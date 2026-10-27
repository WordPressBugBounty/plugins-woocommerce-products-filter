<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * The tool catalogue: names, schemas, annotations, the texts shown to agents,
 * and the one entry point that runs a tool. The MCP transport and the
 * WordPress abilities both go through run(), so a tool behaves the same
 * whichever way an agent reaches it.
 */
final class HUSKY_MCP_TOOLS {

	const SCHEMA = 'husky_get_filter_schema';
	const SEARCH = 'husky_search_products';
	const PRODUCT = 'husky_get_product';

	public static function names() {
		return array( self::SCHEMA, self::SEARCH, self::PRODUCT );
	}

	public static function exists( $name ) {
		return in_array( $name, self::names(), true );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// texts shown to agents

	public static function server_name() {
		$name = trim( (string) HUSKY_MCP::get( 'server_name' ) );
		if ( '' === $name ) {
			$name = sanitize_title( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ) . '-catalog';
		}
		return $name;
	}

	public static function server_title() {
		$title = trim( (string) HUSKY_MCP::get( 'server_title' ) );
		if ( '' === $title ) {
			/* translators: %s: shop name */
			$title = sprintf( __( '%s product catalog', 'woocommerce-products-filter' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
		}
		return $title;
	}

	public static function server_description() {
		$d = trim( self::lf( HUSKY_MCP::get( 'server_description' ) ) );
		if ( '' === $d ) {
			$d = self::default_description();
		}
		return $d;
	}

	public static function default_description() {
		/* translators: %s: shop name */
		return sprintf( __( 'Search %s with its own filters and get links to product pages.', 'woocommerce-products-filter' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
	}

	/**
	 * Server info for initialize / _meta. The 2025-06-18 schema knows name,
	 * title and version; description, websiteUrl and icons came in 2025-11-25.
	 */
	public static function server_info( $version = '' ) {

		$info = array(
			'name'    => self::server_name(),
			'title'   => self::server_title(),
			'version' => WOOF_VERSION,
		);

		if ( '2025-06-18' !== $version ) {
			$info['description'] = self::server_description();
			$info['websiteUrl']  = home_url( '/' );
			$icon                = get_site_icon_url( 192 );
			if ( $icon ) {
				$info['icons'] = array(
					array(
						'src'      => $icon,
						'mimeType' => self::mime_of( $icon ),
						'sizes'    => array( '192x192' ),
					),
				);
			}
		}

		return $info;
	}

	public static function mime_of( $url ) {
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$map = array(
			'png'  => 'image/png',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'webp' => 'image/webp',
			'svg'  => 'image/svg+xml',
			'ico'  => 'image/x-icon',
			'gif'  => 'image/gif',
		);
		return isset( $map[ $ext ] ) ? $map[ $ext ] : 'image/png';
	}

	public static function instructions() {
		$text = trim( self::lf( HUSKY_MCP::get( 'instructions' ) ) );
		return '' !== $text ? $text : self::default_instructions();
	}

	// A browser submits the settings textareas with CRLF line ends
	private static function lf( $text ) {
		return str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	}

	/**
	 * What every agent is told at the start. The owner can rewrite it in the
	 * settings and reset it to this.
	 */
	public static function default_instructions() {
		$shop = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return implode(
			"\n",
			array(
				'You help shoppers find products in the online shop "' . $shop . '". You can search and read the catalog; you cannot buy, add to cart or check out. The shopper opens a product page on the site and buys there.',
				'',
				'How to work:',
				'1. Call husky_get_filter_schema first. It lists exactly the filters this shop offers (the same as its filter form), their values with product counts, the price range, sort orders, languages and currencies.',
				'2. Map the shopper\'s words to real filter keys and values from the schema ("red sneakers size 42" -> pa_color: red, product_cat: sneakers, pa_size: 42). Never invent keys or values; if something the shopper wants is not a filter here, use the text filter when there is one, or say it cannot be filtered.',
				'3. Call husky_search_products. Use the counts in "refine" to narrow down further and suggest options; page through with page when there are more results than shown.',
				'4. Call husky_get_product for details, all variations, sizes and stock of one product.',
				'',
				'Show results as product cards: image, name, price (with the old price when on sale), stock status, one line on why it fits the request, and a "View on site" link with the url given. Use only the urls the tools return.',
				'When nothing matches, say so plainly and offer the closest options from "nearest" (another value that has products, or the result without one filter). Do not pretend a near match is an exact one.',
				'Answer in the shopper\'s language. If the shop has that language (see languages in the schema), pass it as lang so names and links come in it; pass currency when the shopper wants prices in another currency the shop supports.',
				'Prices, stock and availability can change; the shop page is final.',
			)
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// definitions

	private static function lang_currency_props() {
		return array(
			'lang'     => array(
				'type'        => 'string',
				'description' => 'Language code from the schema\'s languages (WPML/Polylang shops). Labels, names and links come back in it. Default: the shop\'s default language.',
			),
			'currency' => array(
				'type'        => 'string',
				'description' => 'Currency code from the schema\'s currencies. Prices (and the price filter) are in it and links open the shop in it. Default: the shop\'s base currency.',
			),
		);
	}

	/**
	 * Input schemas: kept to what both JSON Schema validators accept - the
	 * MCP clients' and the WordPress REST one behind the abilities.
	 */
	public static function input_schema( $name ) {

		switch ( $name ) {

			case self::SCHEMA:
				return array(
					'type'                 => 'object',
					'properties'           => self::lang_currency_props(),
					'additionalProperties' => false,
				);

			case self::SEARCH:
				return array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'filters'  => array(
								'type'                 => 'object',
								'description'          => 'Filter key => value, keys and values from husky_get_filter_schema. Taxonomy filters take an array of value slugs (["red","blue"]); price takes {"min":..,"max":..} in the requested currency; flags take true; min_rating takes 1-4; text takes a string. Leave a filter out to not filter by it.',
								'additionalProperties' => true,
							),
							'page'     => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'description' => 'Page of results, from 1.',
							),
							'per_page' => array(
								'type'        => 'integer',
								'minimum'     => 1,
								'maximum'     => HUSKY_MCP_ENGINE::max_per_page(),
								'description' => 'Products per page, at most ' . HUSKY_MCP_ENGINE::max_per_page() . '.',
							),
							'sort'     => array(
								'type'        => 'string',
								'description' => 'Sort order, one of the schema\'s sort values (price, price-desc, popularity, rating, date, menu_order).',
							),
						),
						self::lang_currency_props()
					),
					'additionalProperties' => false,
				);

			case self::PRODUCT:
				return array(
					'type'                 => 'object',
					'properties'           => array_merge(
						array(
							'id'         => array(
								'type'        => 'integer',
								'description' => 'Product id from husky_search_products.',
							),
							'url'        => array(
								'type'        => 'string',
								'description' => 'Or the product page URL.',
							),
							'sku'        => array(
								'type'        => 'string',
								'description' => 'Or the SKU.',
							),
							'attributes' => array(
								'type'                 => 'object',
								'description'          => 'Optional variation choice, e.g. {"pa_color":"red","pa_size":"42"}: the returned url then opens the page with that variation selected.',
								'additionalProperties' => true,
							),
						),
						self::lang_currency_props()
					),
					'additionalProperties' => false,
				);
		}

		return array( 'type' => 'object' );
	}

	public static function output_schema( $name ) {

		$money = array( 'type' => array( 'number', 'null' ) );

		$card = array(
			'type'                 => 'object',
			'properties'           => array(
				'id'                => array( 'type' => 'integer' ),
				'name'              => array( 'type' => 'string' ),
				'type'              => array( 'type' => 'string' ),
				'url'               => array( 'type' => 'string' ),
				'price'             => $money,
				'regular_price'     => $money,
				'sale_price'        => $money,
				'on_sale'           => array( 'type' => 'boolean' ),
				'currency'          => array( 'type' => 'string' ),
				'price_text'        => array( 'type' => 'string' ),
				'stock_status'      => array( 'type' => 'string' ),
				'in_stock'          => array( 'type' => 'boolean' ),
				'image'             => array( 'type' => array( 'string', 'null' ) ),
				'short_description' => array( 'type' => 'string' ),
				'attributes'        => array( 'type' => 'object' ),
				'rating'            => array( 'type' => 'number' ),
				'review_count'      => array( 'type' => 'integer' ),
			),
			'required'             => array( 'id', 'name', 'url' ),
			'additionalProperties' => true,
		);

		switch ( $name ) {

			case self::SCHEMA:
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'shop'       => array( 'type' => 'object' ),
						'filters'    => array(
							'type'  => 'array',
							'items' => array(
								'type'                 => 'object',
								'properties'           => array(
									'key'   => array( 'type' => 'string' ),
									'type'  => array( 'type' => 'string' ),
									'label' => array( 'type' => 'string' ),
								),
								'required'             => array( 'key', 'type', 'label' ),
								'additionalProperties' => true,
							),
						),
						'sort'       => array( 'type' => 'array' ),
						'per_page'   => array( 'type' => 'object' ),
						'languages'  => array( 'type' => 'array' ),
						'currencies' => array( 'type' => 'array' ),
						'total'      => array( 'type' => 'integer' ),
					),
					'required'             => array( 'filters' ),
					'additionalProperties' => true,
				);

			case self::SEARCH:
				return array(
					'type'                 => 'object',
					'properties'           => array(
						'total'    => array( 'type' => 'integer' ),
						'page'     => array( 'type' => 'integer' ),
						'pages'    => array( 'type' => 'integer' ),
						'per_page' => array( 'type' => 'integer' ),
						'filters'  => array( 'type' => 'object' ),
						'refine'   => array( 'type' => 'array' ),
						'products' => array(
							'type'  => 'array',
							'items' => $card,
						),
						'message'  => array( 'type' => 'string' ),
						'nearest'  => array( 'type' => 'array' ),
					),
					'required'             => array( 'total', 'page', 'pages', 'products' ),
					'additionalProperties' => true,
				);

			case self::PRODUCT:
				$detail                             = $card;
				$detail['properties']['description'] = array( 'type' => 'string' );
				$detail['properties']['gallery']     = array( 'type' => 'array' );
				$detail['properties']['variations']  = array( 'type' => 'array' );
				return $detail;
		}

		return array( 'type' => 'object' );
	}

	public static function title( $name ) {
		$titles = array(
			self::SCHEMA  => __( 'Shop filters', 'woocommerce-products-filter' ),
			self::SEARCH  => __( 'Search products', 'woocommerce-products-filter' ),
			self::PRODUCT => __( 'Product details', 'woocommerce-products-filter' ),
		);
		return isset( $titles[ $name ] ) ? $titles[ $name ] : $name;
	}

	public static function description( $name ) {
		switch ( $name ) {
			case self::SCHEMA:
				return 'What this shop can be filtered by - exactly the filters of its own filter form: each filter\'s key, type, label, logic (OR / AND / NOT IN) and values with product counts; the price range; sort orders; per_page limits; languages and currencies. Call it first and use its keys and values in husky_search_products.';
			case self::SEARCH:
				return 'Search the catalog with the shop\'s filters, the same way its filter form does. Returns total, page and pages, the filters applied, "refine" (every filter\'s remaining values with counts, to narrow down), and products: id, name, prices, stock, image, short description, key attributes, rating and a link to the product page. When nothing matches it says so and lists the nearest options.';
			case self::PRODUCT:
				return 'Full details of one product: description, gallery, all attributes, variations with their attributes, prices and stock, rating and the link to its page. Pass attributes to get a link with a variation preselected.';
		}
		return '';
	}

	/**
	 * tools/list entries.
	 */
	public static function definitions( $version = '' ) {

		$out = array();

		foreach ( self::names() as $name ) {
			$def = array(
				'name'        => $name,
				'title'       => self::title( $name ),
				'description' => self::description( $name ),
				'inputSchema' => self::input_schema( $name ),
				'annotations' => array(
					'title'           => self::title( $name ),
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
					'openWorldHint'   => false,
				),
			);
			// structured output arrived in 2025-06-18; every version we speak has it
			$def['outputSchema'] = self::output_schema( $name );
			$out[]               = $def;
		}

		return $out;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// running

	/**
	 * Runs a tool and returns its data or a WP_Error whose message tells the
	 * agent how to recover. The one shared handler of MCP and abilities.
	 *
	 * @return array|WP_Error
	 */
	public static function run( $name, $args ) {

		require_once __DIR__ . '/engine.php';

		$args = is_array( $args ) ? $args : array();

		$problems = self::type_problems( $args, self::input_schema( $name ) );
		if ( ! empty( $problems ) ) {
			return new WP_Error( 'woof_mcp_bad_argument', 'Wrong argument: ' . implode( '; ', $problems ) . '. Call again with the types the tool schema declares.' );
		}

		// A shop in WooCommerce's "Coming soon" mode is not open to the public:
		// guests see no products, and neither does an agent
		if ( 'yes' === get_option( 'woocommerce_coming_soon' ) && apply_filters( 'woof_mcp_respect_coming_soon', true ) ) {
			return new WP_Error( 'woof_mcp_coming_soon', 'This shop is not open to the public yet ("Coming soon" mode), so its products cannot be searched. Tell the user to try again later.' );
		}

		$booted = HUSKY_MCP_ENGINE::boot();
		if ( is_wp_error( $booted ) ) {
			return $booted;
		}

		try {
			$ctx = HUSKY_MCP_ENGINE::enter_context(
				isset( $args['lang'] ) ? $args['lang'] : '',
				isset( $args['currency'] ) ? $args['currency'] : ''
			);
			if ( is_wp_error( $ctx ) ) {
				return $ctx;
			}
			return self::run_in_context( $name, $args );
		} finally {
			HUSKY_MCP_ENGINE::leave_context();
		}
	}

	/**
	 * run() once the language and currency of the call are set.
	 *
	 * @return array|WP_Error
	 */
	private static function run_in_context( $name, $args ) {

		// Identical searches cost nothing for a while: agents repeat them, and
		// many shoppers ask the same thing
		$cache_key   = self::cache_key( $name, $args );
		self::$cache = '';
		if ( '' !== $cache_key ) {
			$cached = HUSKY_MCP_RATE_LIMIT::read( $cache_key, self::CACHE_GROUP );
			if ( is_array( $cached ) ) {
				self::$cache = 'hit';
				return $cached;
			}
			self::$cache = 'miss';
		}

		// Always what a guest sees: through the MCP Adapter or the abilities
		// REST route a logged-in administrator would otherwise get private
		// products in the shop query
		$user_before = get_current_user_id();
		wp_set_current_user( 0 );

		try {
			switch ( $name ) {
				case self::SCHEMA:
					$result = HUSKY_MCP_ENGINE::get_filter_schema( $args );
					break;
				case self::SEARCH:
					$result = HUSKY_MCP_ENGINE::search_products( $args );
					break;
				case self::PRODUCT:
					$result = HUSKY_MCP_ENGINE::get_product( $args );
					break;
				default:
					$result = new WP_Error( 'woof_mcp_unknown_tool', 'Unknown tool ' . $name . '.' );
			}
		} catch ( Throwable $e ) {
			$result = new WP_Error( 'woof_mcp_failed', 'The shop could not answer this request: ' . $e->getMessage() );
		} finally {
			wp_set_current_user( $user_before );
		}

		if ( '' !== $cache_key && is_array( $result ) ) {
			HUSKY_MCP_RATE_LIMIT::write( $cache_key, $result, intval( HUSKY_MCP::get( 'cache_ttl' ) ), self::CACHE_GROUP );
		}

		return $result;
	}

	const CACHE_GROUP = 'husky_mcp_sc';
	const CACHE_GEN   = 'woof_mcp_cache_gen';

	// 'hit', 'miss' or '' (not cached) for the last run(), for the response header
	public static $cache = '';

	/**
	 * The cache key of a filter schema or search: the tool, its arguments in a
	 * canonical order, the resolved language and currency, and the catalog
	 * generation (bumped by any product, term or filter settings change).
	 * '' when this call is not cached.
	 */
	private static function cache_key( $name, $args ) {

		$ttl = intval( HUSKY_MCP::get( 'cache_ttl' ) );

		if ( $ttl <= 0 || ! in_array( $name, array( self::SCHEMA, self::SEARCH ), true ) ) {
			return '';
		}

		unset( $args['lang'], $args['currency'] );
		$args = self::canonical( $args );

		return md5(
			wp_json_encode(
				array(
					$name,
					$args,
					HUSKY_MCP_ENGINE::lang(),
					HUSKY_MCP_ENGINE::currency(),
					(int) get_option( self::CACHE_GEN, 0 ),
					WOOF_VERSION,
					get_locale(),
				)
			)
		);
	}

	/**
	 * Arrays with keys sorted at every level; lists of values sorted too, so
	 * ["red","blue"] and ["blue","red"] are the same search.
	 */
	private static function canonical( $value ) {
		if ( ! is_array( $value ) ) {
			return is_string( $value ) ? trim( $value ) : $value;
		}
		$value = array_map( array( __CLASS__, 'canonical' ), $value );
		if ( array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
			$scalars = array_filter( $value, 'is_scalar' );
			if ( count( $scalars ) === count( $value ) ) {
				sort( $value );
			}
		} else {
			ksort( $value );
		}
		return $value;
	}

	/**
	 * Any change to the catalog or the filter settings: cached answers are
	 * stale from now on.
	 */
	public static function bump_cache() {
		update_option( self::CACHE_GEN, (int) get_option( self::CACHE_GEN, 0 ) + 1, false );
	}

	/**
	 * MCP tools/call result.
	 */
	public static function call( $name, $args, $version = '' ) {

		$result = self::run( $name, $args );

		if ( is_wp_error( $result ) ) {
			return self::error_result( $result->get_error_message(), $result->get_error_code() );
		}

		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode( $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
			'structuredContent' => $result,
			'isError'           => false,
		);
	}

	/**
	 * A tool error the model reads and recovers from - isError, not a
	 * protocol error, as 2025-11-25 asks for input errors.
	 */
	public static function error_result( $message, $code = '' ) {
		$code = (string) $code;
		return array(
			'content'           => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'structuredContent' => array(
				'error'   => '' !== $code ? $code : 'woof_mcp_error',
				'message' => $message,
			),
			'isError'           => true,
		);
	}

	/**
	 * Top level type check against the input schema, before anything runs.
	 * Lossless forms pass: "12" for an integer, a number for a string.
	 */
	public static function type_problems( $args, $schema ) {

		$out   = array();
		$props = isset( $schema['properties'] ) ? $schema['properties'] : array();

		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			foreach ( array_keys( $args ) as $k ) {
				if ( ! isset( $props[ $k ] ) ) {
					$out[] = 'unknown argument "' . $k . '" (accepted: ' . implode( ', ', array_keys( $props ) ) . ')';
				}
			}
		}

		foreach ( $props as $key => $def ) {

			if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] || empty( $def['type'] ) ) {
				continue;
			}

			$v = $args[ $key ];

			switch ( $def['type'] ) {
				case 'string':
					$ok = is_scalar( $v );
					break;
				case 'integer':
					$ok = is_int( $v ) || ( is_numeric( $v ) && floor( (float) $v ) == (float) $v ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
					break;
				case 'object':
					$ok = is_array( $v );
					break;
				default:
					$ok = true;
			}

			if ( ! $ok ) {
				$out[] = $key . ' must be ' . $def['type'];
			}
		}

		return $out;
	}
}
