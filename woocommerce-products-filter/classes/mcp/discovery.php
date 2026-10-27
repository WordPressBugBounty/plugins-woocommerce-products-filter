<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * How agents and people find the MCP server.
 *
 *  - Server card (SEP-2127, final): <endpoint>/server-card, for the default
 *    and the pretty endpoint alike.
 *  - /.well-known/ai-catalog.json: the domain-level list the final card spec
 *    uses for discovery, pointing to the card.
 *  - llms.txt: a section about the server - through the SEO plugin's filter
 *    when it serves the file, as a snippet for the owner when a physical
 *    file exists (never overwritten), as a virtual file otherwise.
 *  - <link> tags in <head>: rel="ai-catalog" (the emerging convention) and
 *    rel="service-desc" pointing to the card.
 *
 * Everything here exists only while the server is switched on; boot.php
 * does not load this file otherwise.
 */
final class HUSKY_MCP_DISCOVERY {

	const CARD_SCHEMA = 'https://static.modelcontextprotocol.io/schemas/v1/server-card.schema.json';

	public static function init() {

		add_filter( 'do_parse_request', array( __CLASS__, 'maybe_serve_file' ), 2, 3 );

		if ( intval( HUSKY_MCP::get( 'head_link' ) ) ) {
			add_action( 'wp_head', array( __CLASS__, 'head_links' ), 5 );
		}

		if ( intval( HUSKY_MCP::get( 'llms_txt' ) ) ) {
			// Rank Math serves llms.txt itself and has a hook for extra content
			add_filter( 'rank_math/llms_txt/extra_content', array( __CLASS__, 'append_llms_section' ), 20 );
			// the "Website LLMs.txt" plugin builds its content through this filter
			add_filter( 'get_llms_content', array( __CLASS__, 'append_llms_section' ), 999 );
		}
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// server card

	public static function card( $endpoint = null ) {

		$endpoint = null === $endpoint ? HUSKY_MCP::public_url() : $endpoint;
		$host     = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$host     = '' === $host ? 'localhost' : $host;

		// reverse DNS of the shop domain, one slash: com.example-shop/husky-catalog
		$name = implode( '.', array_reverse( explode( '.', $host ) ) ) . '/' . sanitize_title( HUSKY_MCP_TOOLS::server_name() );
		$name = preg_replace( '~[^a-zA-Z0-9./_-]~', '-', $name );

		$card = array(
			'$schema'     => self::CARD_SCHEMA,
			'name'        => $name,
			'version'     => WOOF_VERSION,
			'description' => self::cut( HUSKY_MCP_TOOLS::server_description(), 100 ),
			'title'       => self::cut( HUSKY_MCP_TOOLS::server_title(), 100 ),
			'websiteUrl'  => home_url( '/' ),
			'remotes'     => array(),
		);

		$urls = array( $endpoint );
		if ( HUSKY_MCP::default_url() !== $endpoint ) {
			$urls[] = HUSKY_MCP::default_url();
		}
		foreach ( $urls as $url ) {
			$card['remotes'][] = array(
				'type'                      => 'streamable-http',
				'url'                       => $url,
				'supportedProtocolVersions' => HUSKY_MCP_SERVER::supported_versions(),
			);
		}

		$icon = get_site_icon_url( 512 );
		if ( $icon ) {
			$card['icons'] = array(
				array(
					'src'      => $icon,
					'mimeType' => HUSKY_MCP_TOOLS::mime_of( $icon ),
					'sizes'    => array( '512x512' ),
				),
			);
		}

		return apply_filters( 'woof_mcp_server_card', $card );
	}

	/**
	 * Cuts text to the card's length limit at a word boundary.
	 */
	private static function cut( $text, $max ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max - 1 );
		$sp  = mb_strrpos( $cut, ' ' );
		return rtrim( false !== $sp && $sp > $max * 0.5 ? mb_substr( $cut, 0, $sp ) : $cut, ' ,.;:' ) . '…';
	}

	/**
	 * The card as an HTTP response: public CORS and caching with an ETag, as
	 * the card spec asks.
	 */
	public static function card_response( $method, $if_none_match = '' ) {
		return self::json_response( $method, self::card(), 'application/mcp-server-card+json', $if_none_match );
	}

	private static function json_response( $method, $data, $type, $if_none_match = '' ) {

		$headers = array(
			'Access-Control-Allow-Origin'   => '*',
			'Access-Control-Allow-Methods'  => 'GET, OPTIONS',
			'Access-Control-Allow-Headers'  => 'Content-Type, If-None-Match',
			'Access-Control-Expose-Headers' => 'ETag',
			'X-Robots-Tag'                  => 'noindex',
		);

		if ( 'OPTIONS' === $method ) {
			return array(
				'status'  => 204,
				'headers' => $headers,
				'body'    => '',
			);
		}

		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			$headers['Allow'] = 'GET, OPTIONS';
			return array(
				'status'  => 405,
				'headers' => $headers,
				'body'    => '',
			);
		}

		$body = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		$etag = '"' . md5( $body ) . '"';

		$headers['ETag']          = $etag;
		$headers['Cache-Control'] = 'public, max-age=3600';
		$headers['Content-Type']  = $type . '; charset=utf-8';

		if ( '' !== $if_none_match && false !== strpos( $if_none_match, trim( $etag, '"' ) ) ) {
			return array(
				'status'  => 304,
				'headers' => $headers,
				'body'    => '',
			);
		}

		return array(
			'status'  => 200,
			'headers' => $headers,
			'body'    => 'HEAD' === $method ? '' : $body,
		);
	}

	/**
	 * The AI Catalog (/.well-known/ai-catalog.json) with one entry pointing at
	 * the server card. The entry carries no title or description: the card
	 * has them, and the catalog spec asks not to repeat them.
	 */
	public static function ai_catalog() {
		$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$catalog = array(
			'specVersion' => '1.0',
			'entries'     => array(
				array(
					'identifier' => 'urn:air:' . $host . ':mcp:' . sanitize_title( HUSKY_MCP_TOOLS::server_name() ),
					'type'       => 'application/mcp-server-card+json',
					'url'        => HUSKY_MCP::card_url(),
				),
			),
		);

		$site = trim( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) );
		if ( '' !== $site ) {
			$catalog['host'] = array( 'displayName' => $site );
		}

		return $catalog;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// files served from the site root

	/**
	 * WordPress in a sub directory cannot answer for the domain root, where
	 * .well-known lives.
	 */
	public static function at_domain_root() {
		$path = trim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		return '' === $path;
	}

	public static function maybe_serve_file( $do, $wp = null, $extra = null ) {

		$path   = HUSKY_MCP::request_path();
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$inm    = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : '';

		switch ( $path ) {

			case '.well-known/ai-catalog.json':
				HUSKY_MCP::emit( self::json_response( $method, self::ai_catalog(), 'application/ai-catalog+json', $inm ) );
				break;

			case 'llms.txt':
				if ( self::virtual_llms_allowed() ) {
					self::serve_llms();
				}
				break;
		}

		return $do;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// llms.txt

	public static function llms_file_path() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() ) . 'llms.txt';
	}

	/**
	 * Who provides llms.txt on this site: 'file' (a physical file, ours to
	 * leave alone), 'rank-math' / 'website-llms' (a plugin serving it through
	 * a filter we add to), or 'husky' (nobody: we serve it).
	 */
	public static function llms_provider() {

		if ( file_exists( self::llms_file_path() ) ) {
			return 'file';
		}

		// Rank Math serves llms.txt only while its module is actually loaded
		// (an unfinished setup keeps it off even when ticked)
		if ( class_exists( 'RankMath\LLMS\LLMS_Txt', false ) ) {
			return 'rank-math';
		}

		if ( defined( 'LLMS_VERSION' ) || class_exists( 'LLMS_Core' ) ) {
			return 'website-llms';
		}

		return 'husky';
	}

	private static function virtual_llms_allowed() {
		return intval( HUSKY_MCP::get( 'llms_txt' ) ) && 'husky' === self::llms_provider();
	}

	/**
	 * The section about the MCP server, in llms.txt markdown.
	 */
	public static function llms_section() {

		$url  = HUSKY_MCP::public_url();
		$card = HUSKY_MCP::card_url();

		$lines = array(
			'## AI shopping assistant (MCP)',
			'',
			'This shop has a public, read-only MCP server (Model Context Protocol, Streamable HTTP, no authentication). An AI assistant can use it to search the catalog with the shop\'s own filters and give links to product pages; purchases happen on the site.',
			'',
			'- [MCP endpoint](' . $url . '): add this URL to an AI assistant as a remote MCP server. Tools: husky_get_filter_schema, husky_search_products, husky_get_product.',
			'- [Server card](' . $card . '): name, description and supported protocol versions (application/mcp-server-card+json).',
		);

		return implode( "\n", $lines ) . "\n";
	}

	public static function llms_document() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$desc = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
		$out  = '# ' . $name . "\n\n";
		$out .= '> ' . ( '' !== trim( $desc ) ? $desc : HUSKY_MCP_TOOLS::server_description() ) . "\n\n";
		$out .= '- [Shop](' . ( function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' ) ) . ')' . "\n\n";
		$out .= self::llms_section();
		return apply_filters( 'woof_mcp_llms_txt', $out );
	}

	private static function serve_llms() {
		HUSKY_MCP::emit(
			array(
				'status'  => 200,
				'headers' => array(
					'Content-Type'  => 'text/plain; charset=utf-8',
					'Cache-Control' => 'public, max-age=3600',
					'X-Robots-Tag'  => 'noindex',
				),
				'body'    => self::llms_document(),
			)
		);
	}

	/**
	 * Appends the section to the llms.txt an SEO plugin builds.
	 */
	public static function append_llms_section( $content ) {
		$content = (string) $content;
		if ( false !== strpos( $content, '## AI shopping assistant (MCP)' ) ) {
			return $content;
		}
		return rtrim( $content ) . "\n\n" . self::llms_section();
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// <head>

	public static function head_links() {
		if ( self::at_domain_root() ) {
			printf( '<link rel="ai-catalog" href="%s" type="application/ai-catalog+json" />' . "\n", esc_url( home_url( '/.well-known/ai-catalog.json' ) ) );
		}
		printf( '<link rel="service-desc" href="%s" type="application/mcp-server-card+json" title="%s" />' . "\n", esc_url( HUSKY_MCP::card_url() ), esc_attr( HUSKY_MCP_TOOLS::server_title() . ' (MCP)' ) );
	}
}
