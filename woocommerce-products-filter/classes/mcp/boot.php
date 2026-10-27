<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * Public read-only MCP server of HUSKY: bootstrap.
 *
 * Lets any AI agent (Claude, ChatGPT, Cursor, any MCP client) filter the shop
 * the way a shopper does with the [woof] form, and hand the user links to the
 * product pages. No keys, no accounts: it is public and read-only.
 *
 * This file only wires things up: settings, addresses and hooks. The protocol
 * lives in server.php, the shop logic in engine.php, the tool catalogue in
 * tools.php, discovery (server card, llms.txt, head link) in discovery.php.
 *
 * WordPress ordering, the same problem BEAR's MCP bootstrap describes:
 * HUSKY::init() runs on 'init' and returns at once for REST requests, so on
 * /wp-json/ no extension is loaded and no query hook exists. REST_REQUEST is
 * defined only later, during parse_request. So the MCP request is recognised
 * here by its address before 'init' (see is_mcp_request()), HUSKY::init() lets
 * such a request through, and the engine brings HUSKY's query machinery up
 * itself (HUSKY_MCP_ENGINE::boot()).
 *
 * Switched off means nothing exists: no REST route, no rewrite rule, no
 * .well-known file, no head link, no llms.txt section, no shortcode output and
 * no abilities.
 */
require_once __DIR__ . '/rate_limit.php';

final class HUSKY_MCP {

	const REST_NS    = 'husky/v1';
	const REST_ROUTE = '/mcp';
	const QUERY_VAR  = 'husky_mcp';
	const CARD_PATH  = 'server-card';

	// MCP request detected for this PHP request: '' none, 'rest', 'pretty'
	private static $request_kind = null;

	public static function init() {

		// The settings screen exists whether the server is on or off
		if ( is_admin() ) {
			require_once __DIR__ . '/admin.php';
			HUSKY_MCP_ADMIN::init();
		}

		// registered either way, renders nothing while the server is off
		require_once __DIR__ . '/widget.php';
		HUSKY_MCP_WIDGET::init();

		if ( ! self::enabled() ) {
			// Switched off some other way than the settings screen (an import,
			// a direct option update): its rewrite rule must not outlive it
			add_action( 'init', array( __CLASS__, 'drop_stale_rewrite' ), 99 );
			return;
		}

		require_once __DIR__ . '/engine.php';
		require_once __DIR__ . '/tools.php';
		require_once __DIR__ . '/server.php';
		require_once __DIR__ . '/discovery.php';

		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
		add_action( 'init', array( __CLASS__, 'register_rewrite' ), 5 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );

		// Our own addresses are served before WordPress parses the request,
		// so they work with any permalink structure, plain included.
		add_filter( 'do_parse_request', array( __CLASS__, 'maybe_serve' ), 1, 3 );
		// The rewrite rule lands here when the early check did not catch it
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve_query_var' ), 1 );

		HUSKY_MCP_DISCOVERY::init();

		// cached schemas and searches go stale with any catalog or filter change
		// WooCommerce saves meta-only changes without save_post (prices from
		// a bulk editor, for example), hence its own CRUD and stock hooks too
		foreach ( array( 'save_post_product', 'save_post_product_variation', 'deleted_post', 'trashed_post', 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation', 'woocommerce_product_set_stock', 'woocommerce_variation_set_stock', 'woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status', 'set_object_terms', 'created_term', 'edited_term', 'delete_term', 'update_option_woof_settings', 'update_option_woocs' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'catalog_changed' ) );
		}
		// ... and product meta written directly (update_post_meta( $id, '_price', ... ))
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'product_meta_changed' ), 10, 3 );
		}

		// WordPress Abilities API (WP 6.9+): the same three operations
		require_once __DIR__ . '/abilities.php';
		HUSKY_MCP_ABILITIES::init();

		// FOX is already up (it starts while its file loads, before HUSKY):
		// from here on an MCP request neither reads nor writes its visitor data
		if ( self::is_mcp_request() ) {
			self::detach_fox();
		}
	}

	/**
	 * FOX (WOOCS) keeps each visitor's currency, geo country and first-visit
	 * flags in its storage: a PHP session, cookies, or transients by IP. An MCP
	 * answer is the same for everybody, so FOX gets a storage that keeps
	 * nothing (WOOCS_STORAGE of an unknown type reads empty and writes
	 * nothing) and its base currency; an explicit currency argument is set by
	 * HUSKY_MCP_ENGINE::enter_context() afterwards.
	 */
	public static function detach_fox() {

		global $WOOCS;

		if ( ! is_object( $WOOCS ) || ! class_exists( 'WOOCS_STORAGE', false ) || ! property_exists( $WOOCS, 'storage' ) ) {
			return;
		}

		if ( ! ( $WOOCS->storage instanceof WOOCS_STORAGE ) || 'none' !== $WOOCS->storage->type ) {
			$WOOCS->storage = new WOOCS_STORAGE( 'none' );
		}

		if ( ! empty( $WOOCS->default_currency ) ) {
			$WOOCS->current_currency = $WOOCS->default_currency;
		}
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// settings

	/**
	 * Defaults of every MCP setting. The texts shown to agents are empty here:
	 * empty means "use the built-in default", so a shop that never edited them
	 * gets improvements of the default text with plugin updates.
	 */
	public static function defaults() {
		return array(
			'enabled'            => 0,
			'pretty_path'        => '',
			'server_name'        => '',
			'server_title'       => '',
			'server_description' => '',
			'instructions'       => '',
			'max_per_page'       => 50,
			'allowed_origins'    => '',
			'rl_enabled'         => 1,
			'rl_ip_rpm'          => 60,
			'rl_ip_burst'        => 20,
			'rl_ai_rpm'          => 1200,
			'rl_ai_burst'        => 300,
			'rl_ai_ranges'       => HUSKY_MCP_RATE_LIMIT::default_ai_ranges(),
			'cache_ttl'          => 90,
			'llms_txt'           => 1,
			'head_link'          => 1,
			'widget_title'       => '',
			'widget_text'        => '',
		);
	}

	public static function settings() {
		static $cache = null;

		if ( null === $cache ) {
			$all   = get_option( 'woof_settings', array() );
			$saved = ( is_array( $all ) && isset( $all['mcp'] ) && is_array( $all['mcp'] ) ) ? $all['mcp'] : array();
			$cache = array_merge( self::defaults(), $saved );
		}

		return $cache;
	}

	public static function get( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : null;
	}

	public static function enabled() {
		return (bool) intval( self::get( 'enabled' ) );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// addresses

	/**
	 * The default endpoint. rest_url() knows the permalink structure: with plain
	 * permalinks this is index.php?rest_route=/husky/v1/mcp, which is the
	 * address that actually works there.
	 */
	public static function default_url() {
		return rest_url( self::REST_NS . self::REST_ROUTE );
	}

	/**
	 * The owner's own address path, normalised: no leading or trailing slash,
	 * lower case segments of letters, digits, dash, underscore and dot.
	 */
	public static function pretty_path() {
		return self::sanitize_path( (string) self::get( 'pretty_path' ) );
	}

	public static function sanitize_path( $path ) {

		$path = trim( wp_unslash( (string) $path ) );

		// The owner may paste the whole address: keep only the part after the site
		if ( preg_match( '~^https?://~i', $path ) ) {
			$home = untrailingslashit( home_url() );
			if ( 0 === stripos( $path, $home ) ) {
				$path = substr( $path, strlen( $home ) );
			} else {
				$path = (string) wp_parse_url( $path, PHP_URL_PATH );
			}
		}

		$path     = strtolower( trim( (string) strtok( $path, '?#' ), '/' ) );
		$segments = array();

		foreach ( explode( '/', $path ) as $seg ) {
			$seg = preg_replace( '~[^a-z0-9._-]~', '', $seg );
			if ( '' !== $seg && '.' !== $seg && '..' !== $seg ) {
				$segments[] = $seg;
			}
		}

		return implode( '/', $segments );
	}

	public static function pretty_url() {
		$path = self::pretty_path();
		return '' === $path ? '' : home_url( '/' . $path );
	}

	/**
	 * The address everything advertises: the pretty one when it is set, the
	 * default one otherwise. The default always keeps working.
	 */
	public static function public_url() {
		$pretty = self::pretty_url();
		return '' !== $pretty ? $pretty : self::default_url();
	}

	/**
	 * Server card address for an MCP endpoint: <endpoint>/server-card, the
	 * location the final Server Card extension reserves (SEP-2127).
	 */
	public static function card_url( $endpoint = null ) {

		$endpoint = null === $endpoint ? self::public_url() : $endpoint;

		if ( false !== strpos( $endpoint, 'rest_route=' ) ) {
			// plain permalinks: the route itself carries the sub path
			return add_query_arg( 'rest_route', '/' . self::REST_NS . self::REST_ROUTE . '/' . self::CARD_PATH, home_url( '/index.php' ) );
		}

		return untrailingslashit( $endpoint ) . '/' . self::CARD_PATH;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// recognising our own requests early

	/**
	 * The path of this request relative to the site home, without query
	 * string, slashes trimmed.
	 */
	public static function request_path() {

		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );

		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return strtolower( trim( rawurldecode( $path ), '/' ) );
	}

	/**
	 * Which of our addresses this request is for: 'rest' for the REST route
	 * (any permalink structure), 'pretty' for the owner's own address, '' for
	 * none. Works before 'init', from the request line alone, because that is
	 * when HUSKY decides whether to bring its machinery up.
	 *
	 * @param bool $with_card whether <endpoint>/server-card counts too
	 */
	public static function request_kind( $with_card = true ) {

		if ( ! self::enabled() ) {
			return '';
		}

		$path   = self::request_path();
		$suffix = $with_card ? '(/' . self::CARD_PATH . ')?' : '';

		// /wp-json/husky/v1/mcp
		$rest = trim( rest_get_url_prefix(), '/' ) . '/' . self::REST_NS . self::REST_ROUTE;
		if ( preg_match( '~^(index\.php/)?' . preg_quote( $rest, '~' ) . $suffix . '/?$~', $path ) ) {
			return 'rest';
		}

		// ?rest_route=/husky/v1/mcp (plain permalinks)
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$route = '/' . trim( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( preg_match( '~^/' . preg_quote( self::REST_NS . self::REST_ROUTE, '~' ) . $suffix . '$~', $route ) ) {
				return 'rest';
			}
		}

		$pretty = self::pretty_path();
		if ( '' !== $pretty && preg_match( '~^' . preg_quote( $pretty, '~' ) . $suffix . '$~', $path ) ) {
			return 'pretty';
		}

		return '';
	}

	/**
	 * Whether this PHP request is an MCP request (endpoint or card). Asked by
	 * HUSKY::init() and HUSKY::is_should_init() so the plugin comes up for it.
	 */
	public static function is_mcp_request() {

		if ( null === self::$request_kind ) {
			self::$request_kind = self::request_kind( true );
		}

		return '' !== self::$request_kind;
	}

	private static function is_card_path() {
		return (bool) preg_match( '~/' . self::CARD_PATH . '/?$~', self::request_path() )
			|| ( isset( $_GET['rest_route'] ) && false !== strpos( sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ), '/' . self::CARD_PATH ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// routing

	public static function register_rest_routes() {

		// Normally the request is answered in maybe_serve() before the REST
		// server starts. The route still exists so it is listed in the REST
		// index and so internal rest_do_request() calls reach the same handler.
		$args = array(
			'methods'             => array( 'GET', 'POST', 'DELETE', 'OPTIONS' ),
			'callback'            => array( __CLASS__, 'rest_callback' ),
			'permission_callback' => '__return_true',
		);

		register_rest_route( self::REST_NS, self::REST_ROUTE, $args );

		$args['methods']  = array( 'GET', 'OPTIONS' );
		$args['callback'] = array( __CLASS__, 'rest_card_callback' );
		register_rest_route( self::REST_NS, self::REST_ROUTE . '/' . self::CARD_PATH, $args );
	}

	public static function rest_callback( $request ) {
		$res = HUSKY_MCP_SERVER::handle_wp_rest_request( $request );
		return self::to_rest_response( $res );
	}

	public static function rest_card_callback( $request ) {
		$res = HUSKY_MCP_DISCOVERY::card_response( $request->get_method(), (string) $request->get_header( 'if_none_match' ) );
		return self::to_rest_response( $res );
	}

	private static function to_rest_response( $res ) {
		$data     = '' === $res['body'] ? null : json_decode( $res['body'], true );
		$response = new WP_REST_Response( $data, $res['status'] );
		foreach ( $res['headers'] as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	public static function register_rewrite() {

		$path = self::pretty_path();

		if ( '' === $path ) {
			return;
		}

		add_rewrite_rule( '^' . preg_quote( $path, '' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=mcp', 'top' );
		add_rewrite_rule( '^' . preg_quote( $path, '' ) . '/' . self::CARD_PATH . '/?$', 'index.php?' . self::QUERY_VAR . '=card', 'top' );
	}

	/**
	 * The MCP search cache is stale: bumped once per request, however many
	 * products a bulk edit touches.
	 */
	public static function catalog_changed() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		HUSKY_MCP_TOOLS::bump_cache();
		// once more when the request ends: a search answered while a long
		// bulk edit was still writing is not kept for the cache lifetime
		add_action( 'shutdown', array( 'HUSKY_MCP_TOOLS', 'bump_cache' ) );
	}

	/**
	 * Direct meta writes of products and variations. The editor's lock and
	 * last-editor keys change when somebody merely opens a product.
	 *
	 * @param int|int[] $meta_id
	 * @param int       $object_id
	 * @param string    $meta_key
	 */
	public static function product_meta_changed( $meta_id, $object_id, $meta_key ) {
		if ( in_array( $meta_key, array( '_edit_lock', '_edit_last' ), true ) ) {
			return;
		}
		if ( in_array( get_post_type( $object_id ), array( 'product', 'product_variation' ), true ) ) {
			self::catalog_changed();
		}
	}

	/**
	 * Off means no rule: flush once when a rule of ours is still stored.
	 */
	public static function drop_stale_rewrite() {
		$rules = get_option( 'rewrite_rules' );
		if ( is_array( $rules ) && preg_grep( '/[?&]' . self::QUERY_VAR . '=/', $rules ) ) {
			flush_rewrite_rules( false );
		}
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * do_parse_request: answer our own addresses before WordPress parses the
	 * request. Matching the request line here, rather than relying on the
	 * rewrite rule alone, keeps the pretty address working with plain
	 * permalinks and right after a permalink change, before rules are flushed.
	 */
	public static function maybe_serve( $do, $wp = null, $extra = null ) {

		$kind = self::request_kind( true );

		if ( '' === $kind ) {
			return $do;
		}

		self::serve( self::is_card_path() ? 'card' : 'mcp' );

		return $do; // not reached
	}

	public static function maybe_serve_query_var( $wp ) {

		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		self::serve( 'card' === $wp->query_vars[ self::QUERY_VAR ] ? 'card' : 'mcp' );
	}

	/**
	 * Answers the request completely and ends it.
	 *
	 * @param string $what 'mcp' or 'card'
	 */
	private static function serve( $what ) {

		if ( ! defined( 'REST_REQUEST' ) ) {
			// Plugins and HUSKY itself treat this as an API request, not a page
			define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';

		if ( 'card' === $what ) {
			$inm = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';
			$res = HUSKY_MCP_DISCOVERY::card_response( $method, $inm );
		} else {
			$res = HUSKY_MCP_SERVER::handle_raw_request();
		}

		self::emit( $res );
	}

	/**
	 * Sends a response built by the server or discovery and ends the request.
	 *
	 * @param array $res status, headers, body
	 */
	public static function emit( $res ) {

		if ( ! headers_sent() ) {
			// An MCP answer starts no visitor session: drop the cookies other
			// plugins set while it was built (FOX storage, PHP or Woo sessions)
			header_remove( 'Set-Cookie' );
			status_header( $res['status'] );
			foreach ( $res['headers'] as $name => $value ) {
				header( $name . ': ' . $value, true );
			}
		}

		if ( '' !== $res['body'] ) {
			echo $res['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON built by wp_json_encode
		}

		exit;
	}
}
