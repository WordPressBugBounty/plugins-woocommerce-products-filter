<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * MCP Streamable HTTP transport and JSON-RPC 2.0 dispatcher.
 *
 * Dual-era, as the 2026-07-28 revision defines it, on one endpoint:
 *
 *  - modern (2026-07-28): stateless. No handshake and no session; every
 *    request carries _meta with the protocol version, server/discover
 *    replaces initialize, results carry resultType, lists carry ttlMs and
 *    cacheScope. Mcp-Session-Id is ignored and never minted.
 *  - legacy (2025-11-25, 2025-06-18): initialize negotiates the version and
 *    the response issues an Mcp-Session-Id, which every later request must
 *    carry (400 without it, 404 for an unknown or expired one).
 *
 * The era is chosen from how the client opens: a request with modern _meta is
 * served statelessly, an initialize request starts a legacy session.
 *
 * Only POST carries messages. Answers are always application/json: the spec
 * lets the server choose between that and an SSE stream, and nothing here
 * streams. GET has no event stream to offer and answers 405, DELETE ends a
 * legacy session, OPTIONS answers CORS preflight. JSON-RPC batches are refused:
 * they were removed in 2025-06-18, and running several searches in one PHP
 * request would share HUSKY's per-request state between them.
 *
 * The transport parts follow BEAR's MCP (ext/mcp in woo-bulk-editor): envelope,
 * protocol negotiation and argument type checks, without keys, connections,
 * uploads or anything that writes.
 */
final class HUSKY_MCP_SERVER {

	const MODERN = '2026-07-28';

	// newest first; the first legacy one is what an unknown legacy client gets
	const LEGACY = array( '2025-11-25', '2025-06-18' );

	const SESSION_PREFIX = 'husky_mcp_s_';
	const SESSION_TTL    = DAY_IN_SECONDS;

	// standard JSON-RPC and MCP error codes
	const E_PARSE            = -32700;
	const E_INVALID_REQUEST  = -32600;
	const E_METHOD_NOT_FOUND = -32601;
	const E_INVALID_PARAMS   = -32602;
	const E_INTERNAL         = -32603;
	const E_HEADER_MISMATCH  = -32020;
	const E_UNSUPPORTED_VER  = -32022;
	const E_RATE_LIMITED     = -32029;

	private $headers  = array(); // request headers, lower case names
	private $method   = 'POST';
	private $era      = '';      // 'modern' | 'legacy'
	private $version  = '';      // negotiated protocol revision
	private $session  = '';      // legacy session id of this request
	private $out_head = array(); // extra response headers

	public static function supported_versions() {
		return array_merge( array( self::MODERN ), self::LEGACY );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// entry points

	/**
	 * The request as PHP received it (pretty address and the early REST path).
	 *
	 * @return array status, headers, body
	 */
	public static function handle_raw_request() {

		$headers = array();
		foreach ( $_SERVER as $k => $v ) {
			if ( 0 === strpos( $k, 'HTTP_' ) && is_string( $v ) ) {
				$headers[ strtolower( str_replace( '_', '-', substr( $k, 5 ) ) ) ] = $v;
			}
		}
		if ( isset( $_SERVER['CONTENT_TYPE'] ) ) {
			$headers['content-type'] = (string) $_SERVER['CONTENT_TYPE']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$body   = (string) file_get_contents( 'php://input' );

		$server = new self();
		return $server->handle( $method, $headers, $body );
	}

	/**
	 * The same request arriving through the WordPress REST server.
	 *
	 * @param WP_REST_Request $request
	 * @return array status, headers, body
	 */
	public static function handle_wp_rest_request( $request ) {

		$headers = array();
		foreach ( $request->get_headers() as $name => $values ) {
			$headers[ strtolower( str_replace( '_', '-', $name ) ) ] = is_array( $values ) ? implode( ', ', $values ) : (string) $values;
		}

		$server = new self();
		return $server->handle( $request->get_method(), $headers, (string) $request->get_body() );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// HTTP layer

	public function handle( $method, $headers, $body ) {

		$this->headers = $headers;
		$this->method  = strtoupper( $method );

		// Whatever the visitor's browser holds, the agent sees what a guest sees
		wp_set_current_user( 0 );

		$origin = $this->header( 'origin' );

		// A browser page on a domain the owner did not allow gets nothing,
		// as the spec asks; with no list every origin is welcome, this is a
		// public read-only server without cookies.
		if ( '' !== $origin && ! $this->origin_allowed( $origin ) ) {
			return $this->reply( 403, $this->rpc_error( null, -32000, 'Origin not allowed.' ) );
		}

		if ( 'OPTIONS' === $this->method ) {
			return $this->reply( 204, null );
		}

		if ( 'GET' === $this->method ) {
			// No server-to-client event stream: 405 is what both eras expect
			$this->out_head['Allow'] = 'POST, DELETE, OPTIONS';
			return $this->reply(
				405,
				array(
					'error'    => 'This is an MCP endpoint (Model Context Protocol). Add this address to an AI assistant as a remote MCP server; it speaks JSON-RPC 2.0 over HTTP POST.',
					'server'   => HUSKY_MCP_TOOLS::server_name(),
					'card'     => HUSKY_MCP::card_url(),
					'versions' => self::supported_versions(),
				)
			);
		}

		if ( 'DELETE' === $this->method ) {
			$sid = $this->header( 'mcp-session-id' );
			if ( '' === $sid ) {
				return $this->reply( 405, null );
			}
			delete_transient( self::session_key( $sid ) );
			return $this->reply( 204, null );
		}

		if ( 'POST' !== $this->method ) {
			$this->out_head['Allow'] = 'POST, DELETE, OPTIONS';
			return $this->reply( 405, null );
		}

		$msg = json_decode( $body, true );

		if ( ! is_array( $msg ) ) {
			return $this->reply( 400, $this->rpc_error( null, self::E_PARSE, 'Parse error: the body must be one JSON-RPC 2.0 message.' ) );
		}

		if ( isset( $msg[0] ) || array() === $msg ) {
			return $this->reply( 400, $this->rpc_error( null, self::E_INVALID_REQUEST, 'JSON-RPC batches are not supported. Send one message per request.' ) );
		}

		return $this->route( $msg );
	}

	private function route( $msg ) {

		$method = isset( $msg['method'] ) && is_string( $msg['method'] ) ? $msg['method'] : '';
		$has_id = array_key_exists( 'id', $msg ) && null !== $msg['id'];
		$id     = $has_id ? $msg['id'] : null;
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( ! isset( $msg['jsonrpc'] ) || '2.0' !== $msg['jsonrpc'] ) {
			return $this->reply( 400, $this->rpc_error( $id, self::E_INVALID_REQUEST, 'Invalid Request: jsonrpc must be "2.0".' ) );
		}

		// A response from the client (we never ask it anything) or a
		// notification: accepted, nothing to answer.
		if ( '' === $method ) {
			if ( isset( $msg['result'] ) || isset( $msg['error'] ) ) {
				return $this->reply( 202, null );
			}
			return $this->reply( 400, $this->rpc_error( $id, self::E_INVALID_REQUEST, 'Invalid Request: method is missing.' ) );
		}

		$meta    = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();
		$modern  = isset( $meta['io.modelcontextprotocol/protocolVersion'] );
		$hdr_ver = $this->header( 'mcp-protocol-version' );

		if ( $modern ) {
			return $this->route_modern( $id, $has_id, $method, $params, $meta, $hdr_ver );
		}

		return $this->route_legacy( $id, $has_id, $method, $params, $hdr_ver );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// 2026-07-28: stateless

	private function route_modern( $id, $has_id, $method, $params, $meta, $hdr_ver ) {

		$this->era = 'modern';
		$asked     = (string) $meta['io.modelcontextprotocol/protocolVersion'];

		if ( self::MODERN !== $asked ) {
			return $this->reply(
				400,
				$this->rpc_error(
					$id,
					self::E_UNSUPPORTED_VER,
					'Unsupported protocol version',
					array(
						'supported' => self::supported_versions(),
						'requested' => $asked,
					)
				)
			);
		}

		$this->version = self::MODERN;

		// Headers must say what the body says
		if ( '' !== $hdr_ver && $hdr_ver !== $asked ) {
			return $this->reply( 400, $this->rpc_error( $id, self::E_HEADER_MISMATCH, 'MCP-Protocol-Version header does not match _meta protocolVersion.' ) );
		}

		$hdr_method = $this->header( 'mcp-method' );
		if ( '' !== $hdr_method && $hdr_method !== $method ) {
			return $this->reply( 400, $this->rpc_error( $id, self::E_HEADER_MISMATCH, 'Mcp-Method header does not match the body.' ) );
		}

		if ( 'tools/call' === $method ) {
			$hdr_name = $this->decode_header_value( $this->header( 'mcp-name' ) );
			$name     = isset( $params['name'] ) && is_scalar( $params['name'] ) ? (string) $params['name'] : '';
			if ( '' !== $hdr_name && $hdr_name !== $name ) {
				return $this->reply( 400, $this->rpc_error( $id, self::E_HEADER_MISMATCH, 'Mcp-Name header does not match params.name.' ) );
			}
		}

		if ( ! $has_id ) {
			return $this->reply( 202, null );
		}

		$limited = $this->rate_limit( $id );
		if ( $limited ) {
			return $limited;
		}

		switch ( $method ) {

			case 'server/discover':
				return $this->ok(
					$id,
					$this->cacheable(
						array(
							'supportedVersions' => self::supported_versions(),
							'capabilities'      => $this->capabilities(),
							'instructions'      => HUSKY_MCP_TOOLS::instructions(),
						)
					)
				);

			case 'tools/list':
				return $this->ok( $id, $this->cacheable( array( 'tools' => HUSKY_MCP_TOOLS::definitions( $this->version ) ) ) );

			case 'tools/call':
				return $this->ok( $id, $this->call_tool( $params ) );

			case 'resources/list':
				return $this->ok( $id, $this->cacheable( array( 'resources' => array() ) ) );

			case 'resources/templates/list':
				return $this->ok( $id, $this->cacheable( array( 'resourceTemplates' => array() ) ) );

			case 'prompts/list':
				return $this->ok( $id, $this->cacheable( array( 'prompts' => array() ) ) );

			case 'initialize':
				// a modern request never initializes: the client mixed the eras
				return $this->reply(
					400,
					$this->rpc_error(
						$id,
						self::E_UNSUPPORTED_VER,
						'initialize belongs to protocol versions up to 2025-11-25. Without _meta it starts a legacy session.',
						array(
							'supported' => self::supported_versions(),
							'requested' => self::MODERN,
						)
					)
				);
		}

		return $this->reply( 404, $this->rpc_error( $id, self::E_METHOD_NOT_FOUND, 'Method not found: ' . $method ) );
	}

	/**
	 * List and discover results are cacheable. Nothing here depends on who
	 * asks, so the cache scope is public; five minutes keeps a changed filter
	 * form from being stale for long.
	 */
	private function cacheable( $result ) {
		$result['ttlMs']      = 300000;
		$result['cacheScope'] = 'public';
		return $result;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// up to 2025-11-25: initialize and sessions

	private function route_legacy( $id, $has_id, $method, $params, $hdr_ver ) {

		$this->era = 'legacy';

		if ( 'initialize' === $method ) {

			$limited = $this->rate_limit( $id );
			if ( $limited ) {
				return $limited;
			}

			$asked         = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
			$this->version = in_array( $asked, self::LEGACY, true ) ? $asked : self::LEGACY[0];
			$this->session = $this->new_session( $this->version );

			$this->out_head['Mcp-Session-Id'] = $this->session;

			if ( ! $has_id ) {
				return $this->reply( 400, $this->rpc_error( null, self::E_INVALID_REQUEST, 'initialize must be a request with an id.' ) );
			}

			$info = HUSKY_MCP_TOOLS::server_info( $this->version );

			return $this->ok(
				$id,
				array(
					'protocolVersion' => $this->version,
					'capabilities'    => $this->capabilities(),
					'serverInfo'      => $info,
					'instructions'    => HUSKY_MCP_TOOLS::instructions(),
				)
			);
		}

		// Everything after initialize belongs to a session
		$sid = $this->header( 'mcp-session-id' );

		if ( '' === $sid ) {
			return $this->reply( 400, $this->rpc_error( $id, self::E_INVALID_REQUEST, 'Missing Mcp-Session-Id header. Call initialize first, or send modern _meta (protocol ' . self::MODERN . ').' ) );
		}

		$session = get_transient( self::session_key( $sid ) );

		if ( ! is_array( $session ) ) {
			// unknown or expired: the client starts again with initialize
			return $this->reply( 404, $this->rpc_error( $id, self::E_INVALID_REQUEST, 'Session not found or expired. Initialize a new session.' ) );
		}

		$this->session = $sid;
		$this->version = isset( $session['v'] ) ? (string) $session['v'] : self::LEGACY[0];

		// An explicit header must name a version we speak; absent is fine
		if ( '' !== $hdr_ver && ! in_array( $hdr_ver, self::supported_versions(), true ) && '2025-03-26' !== $hdr_ver ) {
			return $this->reply( 400, $this->rpc_error( $id, self::E_UNSUPPORTED_VER, 'Unsupported MCP-Protocol-Version: ' . $hdr_ver, array( 'supported' => self::supported_versions() ) ) );
		}

		$this->refresh_session( $sid, $session );

		if ( ! $has_id ) {
			// notifications/initialized, notifications/cancelled, ...
			return $this->reply( 202, null );
		}

		$limited = $this->rate_limit( $id );
		if ( $limited ) {
			return $limited;
		}

		switch ( $method ) {

			case 'ping':
				return $this->ok( $id, new stdClass() );

			case 'tools/list':
				return $this->ok( $id, array( 'tools' => HUSKY_MCP_TOOLS::definitions( $this->version ) ) );

			case 'tools/call':
				return $this->ok( $id, $this->call_tool( $params ) );

			case 'resources/list':
				return $this->ok( $id, array( 'resources' => array() ) );

			case 'resources/templates/list':
				return $this->ok( $id, array( 'resourceTemplates' => array() ) );

			case 'prompts/list':
				return $this->ok( $id, array( 'prompts' => array() ) );

			case 'logging/setLevel':
				return $this->ok( $id, new stdClass() );
		}

		return $this->reply( 200, $this->rpc_error( $id, self::E_METHOD_NOT_FOUND, 'Method not found: ' . $method ) );
	}

	public static function session_key( $sid ) {
		return self::SESSION_PREFIX . md5( (string) $sid );
	}

	/**
	 * A new session: 32 random hex characters, only a hash of it is used as
	 * the storage key. One write per session, not per request.
	 */
	private function new_session( $version ) {
		$sid = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::session_key( $sid ),
			array(
				'v' => $version,
				't' => time(),
			),
			self::SESSION_TTL
		);
		return $sid;
	}

	/**
	 * Keeps a session in use alive without a write on every request: the
	 * expiry is pushed forward only once half of it has passed.
	 */
	private function refresh_session( $sid, $session ) {
		$started = isset( $session['t'] ) ? intval( $session['t'] ) : 0;
		if ( time() - $started > self::SESSION_TTL / 2 ) {
			$session['t'] = time();
			set_transient( self::session_key( $sid ), $session, self::SESSION_TTL );
		}
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// shared

	private function capabilities() {
		return array(
			'tools' => array( 'listChanged' => false ),
		);
	}

	private function call_tool( $params ) {

		$name = isset( $params['name'] ) && is_scalar( $params['name'] ) ? (string) $params['name'] : '';

		if ( isset( $params['arguments'] ) && ! is_array( $params['arguments'] ) ) {
			return HUSKY_MCP_TOOLS::error_result( 'arguments must be an object.', 'bad_argument' );
		}

		$args = isset( $params['arguments'] ) ? $params['arguments'] : array();

		// Unknown tool: a tool error the model can read and correct
		if ( ! HUSKY_MCP_TOOLS::exists( $name ) ) {
			return HUSKY_MCP_TOOLS::error_result(
				'Unknown tool "' . $name . '". Available tools: ' . implode( ', ', HUSKY_MCP_TOOLS::names() ) . '.',
				'unknown_tool'
			);
		}

		$result = HUSKY_MCP_TOOLS::call( $name, $args, $this->version );

		if ( '' !== HUSKY_MCP_TOOLS::$cache ) {
			$this->out_head['X-HUSKY-MCP-Cache'] = HUSKY_MCP_TOOLS::$cache;
		}

		return $result;
	}

	/**
	 * @return array|null a ready 429 response, or null when the call may go on
	 */
	private function rate_limit( $id = null ) {

		require_once __DIR__ . '/rate_limit.php';

		$wait = HUSKY_MCP_RATE_LIMIT::check( self::client_ip() );

		if ( $wait <= 0 ) {
			return null;
		}

		$this->out_head['Retry-After'] = (string) $wait;

		return $this->reply(
			429,
			$this->rpc_error(
				$id,
				self::E_RATE_LIMITED,
				'Too many requests. Wait ' . $wait . ( 1 === $wait ? ' second' : ' seconds' ) . ' before the next call, then continue where you stopped.',
				array( 'retryAfter' => $wait )
			)
		);
	}

	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// Behind a proxy the owner decides which header holds the real address
		$ip = (string) apply_filters( 'woof_mcp_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	private function origin_allowed( $origin ) {

		$list = trim( (string) HUSKY_MCP::get( 'allowed_origins' ) );

		if ( '' === $list ) {
			return true;
		}

		$origin = strtolower( untrailingslashit( $origin ) );

		// the shop itself is always allowed
		$own = strtolower( untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST ) . ( wp_parse_url( home_url(), PHP_URL_PORT ) ? ':' . wp_parse_url( home_url(), PHP_URL_PORT ) : '' ) ) );
		if ( $origin === $own ) {
			return true;
		}

		foreach ( preg_split( '/[\s,]+/', strtolower( $list ) ) as $allowed ) {
			$allowed = untrailingslashit( trim( $allowed ) );
			if ( '' === $allowed ) {
				continue;
			}
			if ( '*' === $allowed || $allowed === $origin ) {
				return true;
			}
			// *.example.com
			if ( 0 === strpos( $allowed, '*.' ) && substr( $origin, -strlen( substr( $allowed, 1 ) ) ) === substr( $allowed, 1 ) ) {
				return true;
			}
		}

		return false;
	}

	private function header( $name ) {
		return isset( $this->headers[ $name ] ) ? trim( (string) $this->headers[ $name ] ) : '';
	}

	/**
	 * Mcp-Name may carry non-ASCII text as =?base64?...?=
	 */
	private function decode_header_value( $value ) {
		if ( preg_match( '/^=\?base64\?(.*)\?=$/', $value, $m ) ) {
			$decoded = base64_decode( $m[1], true );
			return false === $decoded ? $value : $decoded;
		}
		return $value;
	}

	private function ok( $id, $result ) {

		if ( 'modern' === $this->era && is_array( $result ) ) {
			$result['resultType'] = 'complete';
			if ( ! isset( $result['_meta'] ) ) {
				$result['_meta'] = array();
			}
			$result['_meta']['io.modelcontextprotocol/serverInfo'] = HUSKY_MCP_TOOLS::server_info( $this->version );
		}

		return $this->reply(
			200,
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			)
		);
	}

	private function rpc_error( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}

	/**
	 * @param int        $status
	 * @param array|null $payload JSON payload, null for an empty body
	 * @return array status, headers, body
	 */
	private function reply( $status, $payload ) {

		$headers = array_merge( self::cors_headers(), $this->out_head );

		if ( null !== $payload ) {
			$headers['Content-Type'] = 'application/json; charset=utf-8';
		}

		$headers['Cache-Control'] = 'no-store';
		$headers['X-Robots-Tag']  = 'noindex';

		return array(
			'status'  => $status,
			'headers' => $headers,
			'body'    => null === $payload ? '' : wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
		);
	}

	/**
	 * CORS for browser based clients. Public and without cookies, so any
	 * origin may read it (the Origin check above narrows that when the owner
	 * keeps a list). Preflight has to allow the MCP headers, and the session
	 * id has to be exposed or a browser client can never send it back.
	 */
	public static function cors_headers() {

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return array(
			'Access-Control-Allow-Origin'   => '' !== trim( (string) HUSKY_MCP::get( 'allowed_origins' ) ) && '' !== $origin ? $origin : '*',
			'Access-Control-Allow-Methods'  => 'POST, GET, DELETE, OPTIONS',
			'Access-Control-Allow-Headers'  => 'Content-Type, Accept, Authorization, MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method, Mcp-Name, Last-Event-ID',
			'Access-Control-Expose-Headers' => 'Mcp-Session-Id, MCP-Protocol-Version, Retry-After',
			'Access-Control-Max-Age'        => '86400',
			'Vary'                          => 'Origin',
		);
	}
}
