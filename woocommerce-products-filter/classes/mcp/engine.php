<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * The shop side of the MCP server: what can be filtered, the search itself and
 * the product details. The MCP tools and the WordPress abilities both call
 * these three methods and nothing else, so the two can never disagree.
 *
 * THE CORE RULE: the agent can filter by exactly what the [woof] form offers,
 * and the result is built by the very code the shop front end runs.
 *
 *  - The filter list is read from the same settings the form is drawn from:
 *    items_order, tax / tax_type, comparison_logic, excluded_terms, the
 *    'show' flag of every by_* item and meta field, by_price types.
 *  - A search is turned into the request the form would send - swoof=1,
 *    pa_color=red,blue, min_price, stock=instock, ... - and that request is
 *    run as the shop page's main query: WordPress parses the shop URL, then
 *    WP_Query runs with WooCommerce's product query and every HUSKY hook on
 *    it (parse_query, woocommerce_product_query, url_request's tax query,
 *    the extensions' assemble_query_params and posts_where filters). That is
 *    what a shopper gets on /shop/?swoof=1&... in the default (non AJAX)
 *    mode. With "Try to ajaxify the shop" on, the visible results come from
 *    [woof_products] instead, and so do ours (see front_query()).
 *  - The counts next to the values are HUSKY::dynamic_count(), the recount
 *    the front end shows.
 *
 * On top of what the shop page does, MCP results never contain private,
 * password protected or catalog-hidden products (the task requires published
 * and visible products only).
 */
final class HUSKY_MCP_ENGINE {

	const MAX_TEXT = 200;

	private static $booted   = false;
	private static $context  = array(
		'lang'     => '',
		'currency' => '',
	);
	private static $filters  = null;  // definitions of the enabled filters
	private static $original = array(); // state saved by enter_context()

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// bringing HUSKY up

	/**
	 * Makes sure HUSKY's query machinery exists in this request.
	 *
	 * HUSKY::init() runs for MCP requests (it recognises them by address), so
	 * extensions and hooks are normally in place already. What is never right
	 * for an agent is the visitor storage: it is a transient keyed by IP, and
	 * calls from Anthropic's or OpenAI's servers would share one slot - with
	 * each other and with whatever a visitor on that IP left there (the
	 * "really current term" of a category page, which narrows every count).
	 * The agent always searches the whole shop, so it gets a storage that
	 * remembers nothing.
	 *
	 * @return true|WP_Error
	 */
	public static function boot() {

		if ( self::$booted ) {
			return true;
		}

		global $WOOF;

		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error( 'woof_mcp_no_woocommerce', 'WooCommerce is not active on this shop.' );
		}

		if ( ! isset( $WOOF ) || ! is_object( $WOOF ) ) {
			return new WP_Error( 'woof_mcp_no_husky', 'The product filter is not running on this request (the woof_disable_filter filter or the "init only on" setting may switch it off).' );
		}

		// REST requests skip WooCommerce's front end files
		if ( function_exists( 'WC' ) && ! function_exists( 'wc_get_loop_prop' ) ) {
			WC()->frontend_includes();
		}

		if ( empty( WOOF_EXT::$includes['html_type_objects'] ) && empty( WOOF_EXT::$includes['taxonomy_type_objects'] ) ) {
			// HUSKY::init() did not run for this request (internal REST call)
			$WOOF->init_extensions();
		}

		$WOOF->storage = new WOOF_STORAGE( 'none' );

		if ( ! has_filter( 'woof_main_query_tax_relations', array( $WOOF, 'change_query_tax_relations' ) ) ) {
			add_filter( 'woof_main_query_tax_relations', array( $WOOF, 'change_query_tax_relations' ), 5, 1 );
		}
		if ( ! has_action( 'parse_query', array( $WOOF, 'parse_query' ) ) ) {
			add_action( 'parse_query', array( $WOOF, 'parse_query' ), 9999 );
		}
		if ( ! has_filter( 'woocommerce_product_query', array( $WOOF, 'woocommerce_product_query' ) ) ) {
			add_filter( 'woocommerce_product_query', array( $WOOF, 'woocommerce_product_query' ), 9999 );
			add_action( 'woocommerce_product_query', array( $WOOF, 'woocommerce_parse_query' ) );
		}

		// The recount never counts what the agent may not see
		add_filter( 'woof_dynamic_count_attr', array( __CLASS__, 'visible_only_args' ), 99999 );
		// ... and only products of the requested language
		add_filter( 'woof_dynamic_count_attr', array( __CLASS__, 'count_args_in_language' ), 99999 );

		self::$booted = true;
		return true;
	}

	/**
	 * Query args addition: no password protected products. Private ones are
	 * already out, the request runs as a guest.
	 */
	public static function visible_only_args( $args ) {
		if ( is_array( $args ) ) {
			$args['has_password'] = false;
		}
		return $args;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// language and currency

	/**
	 * Languages of the shop: WPML or Polylang. Empty when neither is active.
	 *
	 * @return array[] code, name, default
	 */
	public static function languages() {

		$out = array();

		if ( class_exists( 'SitePress' ) ) {
			$default = apply_filters( 'wpml_default_language', null );
			$langs   = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
			foreach ( (array) $langs as $code => $l ) {
				$out[] = array(
					'code'    => (string) $code,
					'name'    => isset( $l['native_name'] ) ? $l['native_name'] : ( isset( $l['translated_name'] ) ? $l['translated_name'] : $code ),
					'default' => $code === $default,
				);
			}
		} elseif ( function_exists( 'pll_languages_list' ) ) {
			$default = pll_default_language();
			$codes   = pll_languages_list( array( 'fields' => 'slug' ) );
			$names   = pll_languages_list( array( 'fields' => 'name' ) );
			foreach ( (array) $codes as $i => $code ) {
				$out[] = array(
					'code'    => (string) $code,
					'name'    => isset( $names[ $i ] ) ? $names[ $i ] : $code,
					'default' => $code === $default,
				);
			}
		}

		return $out;
	}

	public static function default_language() {
		foreach ( self::languages() as $l ) {
			if ( $l['default'] ) {
				return $l['code'];
			}
		}
		return '';
	}

	/**
	 * Currencies of FOX (WOOCS). Empty when FOX is not active.
	 *
	 * @return array[] code, name, symbol, default
	 */
	public static function currencies() {

		global $WOOCS;

		if ( ! is_object( $WOOCS ) || ! method_exists( $WOOCS, 'get_currencies' ) ) {
			return array();
		}

		$out = array();
		foreach ( (array) $WOOCS->get_currencies() as $code => $c ) {
			if ( method_exists( $WOOCS, 'is_currency_private' ) && $WOOCS->is_currency_private( $code ) ) {
				continue;
			}
			$out[] = array(
				'code'    => (string) $code,
				'name'    => isset( $c['description'] ) && '' !== $c['description'] ? (string) $c['description'] : (string) $code,
				'symbol'  => isset( $c['symbol'] ) ? html_entity_decode( (string) $c['symbol'], ENT_QUOTES, 'UTF-8' ) : '',
				'default' => $code === $WOOCS->default_currency,
			);
		}

		return $out;
	}

	public static function base_currency() {
		global $WOOCS;
		if ( is_object( $WOOCS ) && ! empty( $WOOCS->default_currency ) ) {
			return (string) $WOOCS->default_currency;
		}
		return get_option( 'woocommerce_currency' );
	}

	/**
	 * Switches the request into a language and a currency.
	 *
	 * @return true|WP_Error a tool error listing the valid codes
	 */
	public static function enter_context( $lang, $currency ) {

		$lang     = strtolower( trim( (string) $lang ) );
		$currency = strtoupper( trim( (string) $currency ) );

		// language
		$langs = self::languages();
		if ( '' !== $lang ) {
			if ( empty( $langs ) ) {
				return new WP_Error( 'woof_mcp_bad_lang', 'This shop has one language only; leave lang out.' );
			}
			$codes = wp_list_pluck( $langs, 'code' );
			if ( ! in_array( $lang, $codes, true ) ) {
				return new WP_Error( 'woof_mcp_bad_lang', 'Unknown language "' . $lang . '". Available: ' . implode( ', ', $codes ) . '.' );
			}
		} elseif ( ! empty( $langs ) ) {
			$lang = self::default_language();
		}

		if ( '' !== $lang ) {
			self::switch_language( $lang );
		}

		// currency
		$currencies = self::currencies();
		if ( '' !== $currency ) {
			$codes = wp_list_pluck( $currencies, 'code' );
			if ( empty( $currencies ) ) {
				if ( strtoupper( get_option( 'woocommerce_currency' ) ) !== $currency ) {
					return new WP_Error( 'woof_mcp_bad_currency', 'This shop sells in ' . get_option( 'woocommerce_currency' ) . ' only.' );
				}
			} elseif ( ! in_array( $currency, $codes, true ) ) {
				return new WP_Error( 'woof_mcp_bad_currency', 'Unknown currency "' . $currency . '". Available: ' . implode( ', ', $codes ) . '.' );
			}
		}

		if ( '' === $currency ) {
			$currency = self::base_currency();
		}

		global $WOOCS;
		if ( is_object( $WOOCS ) ) {
			if ( ! array_key_exists( 'woocs', self::$original ) ) {
				self::$original['woocs']         = $WOOCS->current_currency;
				self::$original['woocs_storage'] = $WOOCS->storage;
			}
			// FOX's visitor storage (currency, geo country) must not reach the answer
			HUSKY_MCP::detach_fox();
			// Set for this request only: set_currency() would store it for the
			// visitor, and agents arriving from one IP would share it
			$WOOCS->current_currency = $currency;
		}

		self::$context = array(
			'lang'     => $lang,
			'currency' => $currency,
		);
		self::$filters = null;

		self::$lang_clause = self::language_clause( $lang );
		if ( null !== self::$lang_clause ) {
			add_action( 'pre_get_posts', array( __CLASS__, 'query_in_language' ), PHP_INT_MAX );
		}

		return true;
	}

	/**
	 * Gives FOX back its currency and visitor storage after a tool run, for
	 * abilities executed inside an ordinary page.
	 */
	public static function leave_context() {

		global $WOOCS;
		if ( is_object( $WOOCS ) && array_key_exists( 'woocs', self::$original ) ) {
			$WOOCS->current_currency = self::$original['woocs'];
			$WOOCS->storage          = self::$original['woocs_storage'];
			unset( self::$original['woocs'], self::$original['woocs_storage'] );
		}

		remove_action( 'pre_get_posts', array( __CLASS__, 'query_in_language' ), PHP_INT_MAX );
		self::$lang_clause = null;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// products of the requested language only

	// tax query clause of the requested language, null when not needed
	private static $lang_clause = null;

	/**
	 * Polylang without Polylang for WooCommerce does not filter the queries
	 * of its default language, nor HUSKY's count queries, so the storefront
	 * lists every language. The MCP answers with products of the requested
	 * language only: each product query of a tool run - results, counts,
	 * helper queries - gets the language as a tax query clause. WPML filters
	 * every query by the current language itself and needs nothing here.
	 *
	 * @return array|null
	 */
	private static function language_clause( $lang ) {

		if ( '' === $lang || class_exists( 'SitePress' ) || ! function_exists( 'PLL' ) || ! isset( PLL()->model ) ) {
			return null;
		}

		$language = PLL()->model->get_language( $lang );
		if ( ! $language ) {
			return null;
		}

		$tt_id = method_exists( $language, 'get_tax_prop' ) ? (int) $language->get_tax_prop( 'language', 'term_taxonomy_id' ) : (int) $language->term_taxonomy_id;
		if ( $tt_id <= 0 ) {
			return null;
		}

		return array(
			'taxonomy'         => 'language',
			'field'            => 'term_taxonomy_id',
			'terms'            => array( $tt_id ),
			'include_children' => false,
		);
	}

	/**
	 * Adds the language clause to a tax query, once.
	 */
	private static function tax_query_in_language( $tax_query ) {

		$tax_query = is_array( $tax_query ) ? $tax_query : array();

		if ( self::has_language_clause( $tax_query ) ) {
			return $tax_query;
		}

		$clauses = array_filter(
			$tax_query,
			function ( $k ) {
				return 'relation' !== $k;
			},
			ARRAY_FILTER_USE_KEY
		);

		if ( empty( $clauses ) ) {
			return array( self::$lang_clause );
		}

		// the shop's own conditions keep their relation (OR filters) inside
		return array(
			'relation' => 'AND',
			$tax_query,
			self::$lang_clause,
		);
	}

	private static function has_language_clause( $tax_query ) {
		foreach ( $tax_query as $k => $clause ) {
			if ( 'relation' === $k || ! is_array( $clause ) ) {
				continue;
			}
			if ( ( isset( $clause['taxonomy'] ) && 'language' === $clause['taxonomy'] ) || self::has_language_clause( $clause ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_product_query( $post_type ) {
		$types = array_values( array_filter( (array) $post_type ) );
		return array( 'product' ) === $types;
	}

	/**
	 * pre_get_posts during a tool run: product queries in the requested language.
	 */
	public static function query_in_language( $q ) {

		if ( null === self::$lang_clause || ! self::is_product_query( $q->get( 'post_type' ) ) ) {
			return;
		}

		// Polylang's own language query var would be added to the same tax query
		$q->set( 'lang', '' );
		$q->set( 'tax_query', self::tax_query_in_language( $q->get( 'tax_query' ) ) );
	}

	/**
	 * woof_dynamic_count_attr: the same for HUSKY's counts. Set on the
	 * arguments, so its count cache (keyed by them) never mixes the MCP's
	 * one-language counts with the storefront's.
	 */
	public static function count_args_in_language( $args ) {

		if ( null === self::$lang_clause || ! is_array( $args ) || ! self::is_product_query( isset( $args['post_type'] ) ? $args['post_type'] : 'product' ) ) {
			return $args;
		}

		$args['tax_query'] = self::tax_query_in_language( isset( $args['tax_query'] ) ? $args['tax_query'] : array() );
		return $args;
	}

	private static function switch_language( $lang ) {

		if ( class_exists( 'SitePress' ) ) {
			do_action( 'wpml_switch_language', $lang );
			global $sitepress;
			if ( is_object( $sitepress ) && method_exists( $sitepress, 'get_locale' ) ) {
				switch_to_locale( $sitepress->get_locale( $lang ) );
			}
			return;
		}

		if ( function_exists( 'PLL' ) && function_exists( 'pll_languages_list' ) ) {
			$pll = PLL();
			if ( isset( $pll->model ) ) {
				$language = $pll->model->get_language( $lang );
				if ( $language ) {
					$pll->curlang = $language;
					// labels read get_locale(), and Polylang filters queries by curlang
					switch_to_locale( $language->locale );
					if ( function_exists( 'load_plugin_textdomain' ) ) {
						unload_textdomain( 'woocommerce' );
						WC()->load_plugin_textdomain();
					}
				}
			}
		}
	}

	public static function lang() {
		return self::$context['lang'];
	}

	public static function currency() {
		return self::$context['currency'];
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the filters the form offers

	/**
	 * Definitions of every filter the [woof] form shows, in form order, keyed
	 * by the key the agent uses. Nothing the owner did not enable is here.
	 *
	 * @return array[]
	 */
	public static function filters() {

		if ( null !== self::$filters ) {
			return self::$filters;
		}

		$woof     = woof();
		$settings = $woof->settings;
		$taxes    = $woof->get_taxonomies();
		$order    = array();

		if ( ! empty( $settings['items_order'] ) ) {
			$order = explode( ',', $settings['items_order'] );
		} else {
			$order = array_merge( $woof->items_keys, array_keys( $taxes ) );
		}
		foreach ( array_merge( $woof->items_keys, array_keys( $taxes ) ) as $key ) {
			if ( ! in_array( $key, $order, true ) ) {
				$order[] = $key;
			}
		}
		$order = apply_filters( 'woof_custom_filter_items_order', $order, '' );

		$meta_fields = ( isset( $settings['meta_filter'] ) && is_array( $settings['meta_filter'] ) ) ? $settings['meta_filter'] : array();
		$acf_fields  = class_exists( 'WOOF_ACF_FILTER' ) && isset( WOOF_ACF_FILTER::$acf_fields ) && is_array( WOOF_ACF_FILTER::$acf_fields ) ? WOOF_ACF_FILTER::$acf_fields : array();

		$out = array();

		foreach ( $order as $key ) {

			$key = trim( (string) $key );

			if ( in_array( $key, $woof->items_keys, true ) ) {

				$def = null;

				if ( 'by_price' === $key ) {
					$def = self::def_price();
				} elseif ( isset( $meta_fields[ $key ] ) ) {
					$def = self::def_meta( $key, $meta_fields[ $key ] );
				} elseif ( isset( $acf_fields[ $key ] ) ) {
					$def = self::def_acf( $key, $acf_fields[ $key ] );
				} else {
					$def = self::def_item( $key );
				}

				if ( $def ) {
					$out[ $def['key'] ] = $def;
				}
				continue;
			}

			if ( isset( $settings['tax'][ $key ] ) && isset( $taxes[ $key ] ) ) {
				$def = self::def_taxonomy( $key, $taxes[ $key ] );
				if ( $def ) {
					$out[ $def['key'] ] = $def;
				}
			}
		}

		self::$filters = apply_filters( 'woof_mcp_filters', $out );

		return self::$filters;
	}

	private static function shown( $key ) {
		$s = woof()->settings;
		return isset( $s[ $key ] ) && is_array( $s[ $key ] ) && ! empty( $s[ $key ]['show'] );
	}

	private static function def_taxonomy( $tax, $tax_obj ) {

		$settings = woof()->settings;
		$view     = isset( $settings['tax_type'][ $tax ] ) ? (string) $settings['tax_type'][ $tax ] : 'radio';
		$logic    = isset( $settings['comparison_logic'][ $tax ] ) ? (string) $settings['comparison_logic'][ $tax ] : 'OR';

		if ( ! in_array( $logic, array( 'OR', 'AND', 'NOT IN' ), true ) ) {
			$logic = 'OR';
		}

		// One value or several, as the view lets a shopper pick
		$single = in_array( $view, array( 'radio', 'select', 'select_hierarchy' ), true );
		if ( 'color' === $view && ! empty( $settings['as_radio_color'][ $tax ] ) ) {
			$single = true;
		}
		if ( 'image' === $view && ! empty( $settings['as_radio'][ $tax ] ) ) {
			$single = true;
		}
		if ( 'select_radio_check' === $view && empty( $settings['select_radio_check_type'][ $tax ] ) ) {
			$single = true;
		}
		if ( 'slider' === $view && isset( $settings['slider_type'][ $tax ] ) && 'single' === $settings['slider_type'][ $tax ] ) {
			$single = true;
		}

		// slider and select_hierarchy always send the plain key, the others
		// rev_<tax> for NOT IN (HUSKY::check_slug())
		$request_key = in_array( $view, array( 'slider', 'select_hierarchy' ), true ) ? $tax : woof()->check_slug( $tax );

		$label = WOOF_HELPER::wpml_translate( $tax_obj );
		if ( 'select_hierarchy' === $view && false !== strpos( (string) $label, '^' ) ) {
			$parts = explode( '^', $label );
			$label = end( $parts );
		}

		return array(
			'key'         => $tax,
			'kind'        => 'taxonomy',
			'taxonomy'    => $tax,
			'view'        => $view,
			'label'       => wp_strip_all_tags( html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' ) ),
			'logic'       => $logic,
			'multiple'    => ! $single,
			'request_key' => $request_key,
			'hierarchy'   => is_taxonomy_hierarchical( $tax ) && ! in_array( $view, array( 'color', 'image', 'slider' ), true ),
			'top_only'    => in_array( $view, array( 'color', 'image', 'slider' ), true ),
		);
	}

	private static function def_price() {

		$s    = woof()->settings;
		$show = isset( $s['by_price']['show'] ) ? intval( $s['by_price']['show'] ) : 0;

		if ( $show <= 0 ) {
			return null;
		}

		$title = ! empty( $s['by_price']['title_text'] ) ? WOOF_HELPER::wpml_translate( null, $s['by_price']['title_text'] ) : esc_html__( 'Price', 'woocommerce-products-filter' );

		return array(
			'key'    => 'price',
			'kind'   => 'price',
			'item'   => 'by_price',
			'show'   => $show, // 1 WC slider, 2 drop-down ranges, 3 ion slider, 4 text inputs, 5 radio ranges
			'label'  => wp_strip_all_tags( (string) $title ),
			'ranges' => in_array( $show, array( 2, 5 ), true ) && ! empty( $s['by_price']['ranges'] ) ? (string) $s['by_price']['ranges'] : '',
		);
	}

	/**
	 * The by_* items. The request keys are the ones the extensions read
	 * (see ext/<name>/index.php), not their names.
	 */
	private static function def_item( $key ) {

		if ( ! self::shown( $key ) ) {
			return null;
		}

		$s = woof()->settings;

		switch ( $key ) {

			case 'by_text':
			case 'by_text_2':
				$placeholder = isset( $s[ $key ]['placeholder'] ) && 'none' !== $s[ $key ]['placeholder'] ? (string) $s[ $key ]['placeholder'] : '';
				return array(
					'key'         => 'text',
					'kind'        => 'text',
					'item'        => $key,
					'label'       => '' !== $placeholder ? WOOF_HELPER::wpml_translate( null, $placeholder ) : esc_html__( 'Search by text', 'woocommerce-products-filter' ),
					'request_key' => 'woof_text',
					'behavior'    => isset( $s[ $key ]['behavior'] ) ? (string) $s[ $key ]['behavior'] : 'title',
				);

			case 'by_sku':
				return array(
					'key'         => 'sku',
					'kind'        => 'list',
					'item'        => $key,
					'label'       => esc_html__( 'SKU', 'woocommerce-products-filter' ),
					'request_key' => 'woof_sku',
					'exact'       => ! isset( $s['by_sku']['logic'] ) || 'LIKE' !== $s['by_sku']['logic'],
				);

			case 'by_instock':
				return array(
					'key'         => 'in_stock',
					'kind'        => 'flag',
					'item'        => $key,
					'label'       => apply_filters( 'woof_ext_custom_title_by_instock', esc_html__( 'In stock', 'woocommerce-products-filter' ) ),
					'request_key' => 'stock',
					'on_value'    => 'instock',
				);

			case 'by_onsales':
				return array(
					'key'         => 'on_sale',
					'kind'        => 'flag',
					'item'        => $key,
					'label'       => apply_filters( 'woof_ext_custom_title_by_onsales', esc_html__( 'On sale', 'woocommerce-products-filter' ) ),
					'request_key' => 'onsales',
					'on_value'    => 'salesonly',
				);

			case 'by_featured':
				return array(
					'key'         => 'featured',
					'kind'        => 'flag',
					'item'        => $key,
					'label'       => apply_filters( 'woof_ext_custom_title_by_featured', esc_html__( 'Featured products', 'woocommerce-products-filter' ) ),
					'request_key' => 'product_visibility',
					'on_value'    => 'featured',
				);

			case 'by_backorder':
				return array(
					'key'         => 'exclude_backorder',
					'kind'        => 'flag',
					'item'        => $key,
					'label'       => apply_filters( 'woof_ext_custom_title_by_backorder', esc_html__( 'Exclude: On backorder', 'woocommerce-products-filter' ) ),
					'request_key' => 'backorder',
					'on_value'    => 'onbackorder',
				);

			case 'by_rating':
				return array(
					'key'         => 'min_rating',
					'kind'        => 'rating',
					'item'        => $key,
					'label'       => esc_html__( 'By rating', 'woocommerce-products-filter' ),
					'request_key' => 'min_rating',
				);

			case 'by_author':
				$args = array( 'fields' => array( 'ID', 'display_name' ) );
				if ( ! empty( $s['by_author']['role'] ) ) {
					$args['role'] = $s['by_author']['role'];
				}
				$authors = array();
				foreach ( get_users( $args ) as $u ) {
					$authors[] = array(
						'value' => (string) $u->ID,
						'label' => $u->display_name,
					);
				}
				return array(
					'key'         => 'author',
					'kind'        => 'choice',
					'item'        => $key,
					'label'       => isset( $s['by_author']['placeholder'] ) && '' !== $s['by_author']['placeholder'] ? WOOF_HELPER::wpml_translate( null, $s['by_author']['placeholder'] ) : esc_html__( 'By author', 'woocommerce-products-filter' ),
					'request_key' => 'woof_author',
					'multiple'    => isset( $s['by_author']['view'] ) && 'checkbox' === $s['by_author']['view'],
					'choices'     => $authors,
				);

			case 'stock_quantity':
				$range = isset( $s['stock_quantity']['range'] ) ? explode( '^', (string) $s['stock_quantity']['range'] ) : array( 0, 100 );
				return array(
					'key'         => 'stock_quantity',
					'kind'        => 'range',
					'item'        => $key,
					'label'       => WOOF_HELPER::wpml_translate( null, apply_filters( 'woof_ext_custom_title_stock_quantity', esc_html__( 'Stock Quantity', 'woocommerce-products-filter' ) ) ),
					'request_key' => 'stock_quantity',
					'min'         => isset( $range[0] ) ? floatval( $range[0] ) : 0,
					'max'         => isset( $range[1] ) ? floatval( $range[1] ) : 100,
				);
		}

		return null;
	}

	private static function def_meta( $meta_key, $field ) {

		if ( ! self::shown( $meta_key ) || empty( $field['search_view'] ) ) {
			return null;
		}

		$s     = woof()->settings;
		$view  = (string) $field['search_view'];
		$label = WOOF_HELPER::wpml_translate( null, isset( $field['title'] ) ? (string) $field['title'] : $meta_key );
		$def   = array(
			'key'         => self::meta_public_key( $meta_key ),
			'kind'        => '',
			'item'        => $meta_key,
			'meta_key'    => $meta_key,
			'view'        => $view,
			'label'       => wp_strip_all_tags( (string) $label ),
			'request_key' => $view . '_' . $meta_key,
		);

		switch ( $view ) {

			case 'checkbox':
				$def['kind'] = 'flag';
				$def['on_value'] = '1';
				return $def;

			case 'select':
			case 'mselect':
				$sep     = apply_filters( 'woof_meta_options_separator', ',' );
				$raw     = isset( $field['options'] ) ? (string) $field['options'] : '';
				$choices = array();
				foreach ( '' === $raw ? array() : explode( $sep, $raw ) as $i => $option ) {
					if ( '' === $option ) {
						continue;
					}
					$title = $option;
					$value = $option;
					$parts = explode( '^', $option, 2 );
					if ( count( $parts ) > 1 ) {
						$title = $parts[0];
						$value = $parts[1];
					}
					// the form sends the 1-based position of the option
					$choices[] = array(
						'value'    => (string) $value,
						'label'    => WOOF_HELPER::wpml_translate( null, $title ),
						'position' => $i + 1,
					);
				}
				$def['kind']     = 'choice';
				$def['multiple'] = 'mselect' === $view;
				$def['choices']  = $choices;
				$def['logic']    = 'mselect' === $view && isset( $s[ $meta_key ]['search_logic'] ) && 'AND' === $s[ $meta_key ]['search_logic'] ? 'AND' : 'OR';
				return $def;

			case 'slider':
				$range       = isset( $s[ $meta_key ]['range'] ) ? explode( '^', (string) $s[ $meta_key ]['range'] ) : array( 1, 100 );
				$def['kind'] = 'range';
				$def['min']  = isset( $range[0] ) ? floatval( $range[0] ) : 1;
				$def['max']  = isset( $range[1] ) ? floatval( $range[1] ) : 100;
				return $def;

			case 'textinput':
				$def['kind']  = 'text';
				$def['exact'] = isset( $s[ $meta_key ]['text_conditional'] ) && '=' === $s[ $meta_key ]['text_conditional'];
				return $def;

			case 'datepicker':
				$def['kind'] = 'date_range';
				return $def;
		}

		return null;
	}

	private static function def_acf( $name, $field ) {

		if ( ! self::shown( $name ) ) {
			return null;
		}

		$type = isset( $field['meta_type'] ) ? (string) $field['meta_type'] : '';
		$def  = array(
			'key'         => self::meta_public_key( $name ),
			'item'        => $name,
			'meta_key'    => $name,
			'view'        => 'acf_' . $type,
			'label'       => wp_strip_all_tags( html_entity_decode( WOOF_HELPER::wpml_translate( null, isset( $field['title'] ) ? (string) $field['title'] : $name ), ENT_QUOTES, 'UTF-8' ) ),
			'request_key' => $name,
		);

		if ( 'true_false' === $type ) {
			$def['kind']     = 'flag';
			$def['on_value'] = '1';
			return $def;
		}

		$choices = array();
		foreach ( (array) ( isset( $field['meta_options'] ) ? $field['meta_options'] : array() ) as $value => $label ) {
			$choices[] = array(
				'value' => (string) $value,
				'label' => WOOF_HELPER::wpml_translate( null, (string) $label ),
			);
		}
		$def['kind']     = 'choice';
		$def['multiple'] = false;
		$def['choices']  = $choices;
		return $def;
	}

	/**
	 * Meta field keys keep their name unless it collides with one of ours.
	 */
	private static function meta_public_key( $meta_key ) {
		$reserved = array( 'price', 'text', 'sku', 'in_stock', 'on_sale', 'featured', 'exclude_backorder', 'min_rating', 'author', 'stock_quantity' );
		$key      = in_array( $meta_key, $reserved, true ) || taxonomy_exists( $meta_key ) ? 'meta_' . $meta_key : $meta_key;
		return preg_replace( '/[^A-Za-z0-9_.-]/', '_', $key );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// terms of a taxonomy filter, as the form lists them

	/**
	 * The values a taxonomy filter offers: HUSKY's own term tree, without the
	 * terms the owner excluded, with the form's ordering.
	 *
	 * @return array[] nested: slug, name, term_id, count, children
	 */
	public static function term_tree( $def ) {

		$tax      = $def['taxonomy'];
		$settings = woof()->settings;

		$hide_empty = (bool) get_option( 'woof_hide_dynamic_empty_pos', 0 );
		$terms      = WOOF_HELPER::get_terms( $tax, $hide_empty );
		$terms      = apply_filters( 'woof_sort_terms_before_out', $terms, $def['view'] );

		$excluded = array();
		if ( ! empty( $settings['excluded_terms'][ $tax ] ) ) {
			$excluded = array_map( 'intval', explode( ',', (string) $settings['excluded_terms'][ $tax ] ) );
		}
		$reverse = ! empty( $settings['excluded_terms_reverse'][ $tax ] );

		return self::walk_terms( $terms, $excluded, $reverse, $def['top_only'] );
	}

	private static function walk_terms( $terms, $excluded, $reverse, $top_only ) {

		$out = array();

		foreach ( (array) $terms as $t ) {

			if ( ! is_array( $t ) || ! isset( $t['slug'] ) ) {
				continue;
			}

			// views: in_array(term_id, hidden) == !reverse -> skip
			if ( ! empty( $excluded ) || $reverse ) {
				if ( in_array( intval( $t['term_id'] ), $excluded, true ) !== $reverse ) {
					continue;
				}
			}

			$node = array(
				'value'   => (string) $t['slug'],
				'label'   => html_entity_decode( (string) $t['name'], ENT_QUOTES, 'UTF-8' ),
				'term_id' => intval( $t['term_id'] ),
				'count'   => intval( $t['count'] ),
			);

			if ( ! $top_only && ! empty( $t['childs'] ) ) {
				$children = self::walk_terms( $t['childs'], $excluded, $reverse, false );
				if ( ! empty( $children ) ) {
					$node['children'] = $children;
				}
			}

			$out[] = $node;
		}

		return $out;
	}

	private static function flatten_terms( $tree, &$out = array(), $parent = '' ) {
		foreach ( $tree as $node ) {
			$copy = $node;
			unset( $copy['children'] );
			$copy['parent'] = $parent;
			$out[ $node['value'] ] = $copy;
			if ( ! empty( $node['children'] ) ) {
				self::flatten_terms( $node['children'], $out, $node['value'] );
			}
		}
		return $out;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// tool 1: what can be filtered

	public static function get_filter_schema( $args ) {

		$req = self::empty_request();

		return self::in_front_request(
			$req,
			function () {

				$filters = array();

				foreach ( self::filters() as $def ) {
					$filters[] = self::describe_filter( $def, true );
				}

				return array(
					'shop'       => array(
						'name'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
						'url'      => home_url( '/' ),
						'language' => self::lang(),
						'currency' => self::currency(),
					),
					'filters'    => $filters,
					'sort'       => self::sort_options(),
					'per_page'   => array(
						'default' => self::default_per_page(),
						'max'     => self::max_per_page(),
					),
					'languages'  => self::languages(),
					'currencies' => self::currencies(),
					'total'      => self::count_request( self::empty_request() ),
				);
			}
		);
	}

	/**
	 * One filter as the agent sees it. With $counts the values carry the
	 * number of products each one would give under the current request.
	 */
	private static function describe_filter( $def, $counts ) {

		$out = array(
			'key'   => $def['key'],
			'type'  => $def['kind'],
			'label' => $def['label'],
		);

		switch ( $def['kind'] ) {

			case 'taxonomy':
				$out['taxonomy'] = $def['taxonomy'];
				$out['view']     = $def['view'];
				$out['multiple'] = $def['multiple'];
				$out['logic']    = $def['logic'];
				$out['logic_note'] = self::logic_note( $def );
				$out['values']   = self::values_with_counts( $def, $counts );
				break;

			case 'price':
				$range              = self::price_range();
				$out['currency']    = self::currency();
				$out['min']         = $range['min'];
				$out['max']         = $range['max'];
				if ( '' !== $def['ranges'] ) {
					$out['ranges']     = self::price_ranges( $def );
					$out['range_note'] = 'This shop offers fixed price ranges: pass min and max of one of them.';
				}
				break;

			case 'flag':
				$out['value_type'] = 'boolean';
				if ( $counts && ( 'on_sale' === $def['key'] || 'checkbox' === ( isset( $def['view'] ) ? $def['view'] : '' ) ) ) {
					$out['count'] = self::count_flag( $def );
				}
				break;

			case 'rating':
				$out['values'] = array(
					array(
						'value' => 4,
						'label' => '4 stars and more',
					),
					array(
						'value' => 3,
						'label' => '3 stars',
					),
					array(
						'value' => 2,
						'label' => '2 stars',
					),
					array(
						'value' => 1,
						'label' => '1 star',
					),
				);
				$out['note']   = 'HUSKY rounds the average rating: 3 matches ratings from 2.5 to 3.49, 4 matches 3.5 and above.';
				break;

			case 'choice':
				$out['multiple'] = ! empty( $def['multiple'] );
				$out['values']   = array();
				foreach ( $def['choices'] as $c ) {
					$v = array(
						'value' => $c['value'],
						'label' => $c['label'],
					);
					if ( $counts && isset( $def['meta_key'] ) && 'author' !== $def['key'] ) {
						$v['count'] = self::count_meta_choice( $def, $c );
					}
					$out['values'][] = $v;
				}
				if ( isset( $def['logic'] ) ) {
					$out['logic'] = $def['logic'];
				}
				break;

			case 'range':
				$out['min'] = $def['min'];
				$out['max'] = $def['max'];
				break;

			case 'text':
				$out['value_type'] = 'string';
				if ( isset( $def['behavior'] ) ) {
					$out['searches_in'] = $def['behavior'];
				}
				break;

			case 'list':
				$out['value_type'] = 'array of strings';
				break;

			case 'date_range':
				$out['value_type'] = 'object {from, to} with dates YYYY-MM-DD';
				break;
		}

		return $out;
	}

	private static function logic_note( $def ) {
		if ( 'AND' === $def['logic'] ) {
			return 'Products must have ALL the values given.';
		}
		if ( 'NOT IN' === $def['logic'] ) {
			return 'Values given are EXCLUDED: products with any of them are left out.';
		}
		return $def['multiple'] ? 'Products with ANY of the values given.' : 'One value.';
	}

	/**
	 * The value tree of a taxonomy filter; with $counts every value not yet
	 * chosen carries HUSKY's dynamic recount for the current request (the
	 * number the front end shows next to it). Values that would give nothing
	 * are left out, as the form does with "hide empty terms".
	 */
	private static function values_with_counts( $def, $counts, $drop_empty = false ) {

		$tree     = self::term_tree( $def );
		$selected = self::selected_values( $def['key'] );
		$type     = $def['multiple'] ? 'multi' : 'single';

		$walk = function ( $nodes ) use ( &$walk, $def, $counts, $selected, $type, $drop_empty ) {
			$out = array();
			foreach ( $nodes as $n ) {
				$item = array(
					'value' => $n['value'],
					'label' => $n['label'],
				);
				if ( in_array( $n['value'], $selected, true ) ) {
					$item['selected'] = true;
				} elseif ( $counts ) {
					$item['count'] = intval(
						woof()->dynamic_count(
							array(
								'taxonomy' => $def['taxonomy'],
								'slug'     => $n['value'],
								'term_id'  => $n['term_id'],
							),
							$type,
							''
						)
					);
				}
				if ( ! empty( $n['children'] ) ) {
					$children = $walk( $n['children'] );
					if ( ! empty( $children ) ) {
						$item['children'] = $children;
					}
				}
				if ( $drop_empty && isset( $item['count'] ) && 0 === $item['count'] && empty( $item['children'] ) ) {
					continue;
				}
				$out[] = $item;
			}
			return $out;
		};

		return $walk( $tree );
	}

	private static $selected = array(); // taxonomy key => chosen slugs of the current search
	private static $applied  = array(); // every filter key the current search uses

	private static function selected_values( $key ) {
		return isset( self::$selected[ $key ] ) ? self::$selected[ $key ] : array();
	}

	private static function count_flag( $def ) {
		if ( 'on_sale' === $def['key'] ) {
			return intval( woof()->dynamic_count( array(), 'multi', '', array(), 'onsale' ) );
		}
		// meta checkbox: the count its view shows (ext/meta_filter/html_types/checkbox)
		if ( isset( $def['view'] ) && 'checkbox' === $def['view'] && isset( $def['meta_key'] ) ) {
			$s      = woof()->settings;
			$exists = ! empty( $s[ $def['meta_key'] ]['search_option'] );
			$value  = isset( $s[ $def['meta_key'] ]['search_value'] ) && '' !== $s[ $def['meta_key'] ]['search_value'] ? $s[ $def['meta_key'] ]['search_value'] : 1;
			return intval(
				woof()->dynamic_count(
					array(),
					$exists ? 'checkbox_ex' : 'checkbox',
					'',
					array(
						'key'   => $def['meta_key'],
						'value' => $value,
					)
				)
			);
		}
		return null;
	}

	private static function count_meta_choice( $def, $choice ) {
		$type = 'acf_select' === $def['view'] || 'acf_radio' === $def['view'] ? 'select' : ( 'mselect' === $def['view'] ? 'mselect' : 'select' );
		$meta = array(
			'key'   => $def['meta_key'],
			'value' => 0 === strpos( $def['view'], 'acf_' ) ? $choice['label'] : $choice['value'],
		);
		return intval( woof()->dynamic_count( array(), $type, '', $meta ) );
	}

	/**
	 * Current min and max price of what the request matches, in the requested
	 * currency - HUSKY's own price range (WOOF_HELPER::get_min_price()).
	 */
	private static function price_range() {
		$min = floatval( WOOF_HELPER::get_min_price() );
		$max = floatval( WOOF_HELPER::get_max_price() );
		return array(
			'min' => self::to_currency( $min, true ),
			'max' => self::to_currency( $max, true ),
		);
	}

	private static function price_ranges( $def ) {
		$out = array();
		$max = floatval( WOOF_HELPER::get_max_price() );
		foreach ( explode( ',', $def['ranges'] ) as $r ) {
			$p = explode( '-', trim( $r ) );
			if ( count( $p ) < 2 ) {
				continue;
			}
			$from = floatval( $p[0] );
			$to   = 'i' === trim( $p[1] ) ? $max : floatval( $p[1] );
			if ( $from >= $to ) {
				continue;
			}
			$out[] = array(
				'min'  => self::to_currency( $from, true ),
				'max'  => 'i' === trim( $p[1] ) ? null : self::to_currency( $to, true ),
				'open' => 'i' === trim( $p[1] ),
			);
		}
		return $out;
	}

	private static function sort_options() {

		$options = apply_filters(
			'woocommerce_catalog_orderby',
			array(
				'menu_order' => __( 'Default sorting', 'woocommerce' ),
				'popularity' => __( 'Sort by popularity', 'woocommerce' ),
				'rating'     => __( 'Sort by average rating', 'woocommerce' ),
				'date'       => __( 'Sort by latest', 'woocommerce' ),
				'price'      => __( 'Sort by price: low to high', 'woocommerce' ),
				'price-desc' => __( 'Sort by price: high to low', 'woocommerce' ),
			)
		);

		if ( 'no' === get_option( 'woocommerce_enable_review_rating' ) ) {
			unset( $options['rating'] );
		}

		$out = array();
		foreach ( $options as $value => $label ) {
			$out[] = array(
				'value'   => (string) $value,
				'label'   => wp_strip_all_tags( (string) $label ),
				'default' => (string) $value === (string) get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ),
			);
		}
		return $out;
	}

	public static function default_per_page() {
		$pp = intval( woof()->get_option( 'per_page', 0 ) );
		if ( $pp <= 0 ) {
			$pp = intval( apply_filters( 'loop_shop_per_page', wc_get_default_products_per_row() * wc_get_default_product_rows_per_page() ) );
		}
		return max( 1, min( $pp, self::max_per_page() ) );
	}

	public static function max_per_page() {
		return max( 1, min( 100, intval( HUSKY_MCP::get( 'max_per_page' ) ) ) );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// turning the agent's filters into the request the form sends

	private static function empty_request() {
		return array();
	}

	/**
	 * Validates the agent's filters against the form and builds the HUSKY
	 * request from them.
	 *
	 * @param array $filters key => value as the agent sent them
	 * @return array|WP_Error request (GET params) and the normalised filters
	 */
	public static function build_request( $filters ) {

		$defs    = self::filters();
		$request = array();
		$applied = array();
		$chosen  = array(); // taxonomy key => slugs
		$errors  = array();

		foreach ( (array) $filters as $key => $value ) {

			$key = (string) $key;

			if ( null === $value || '' === $value || array() === $value || false === $value ) {
				continue; // empty means "not filtering by this"
			}

			if ( ! isset( $defs[ $key ] ) ) {
				// a taxonomy given by its request key or its label
				$found = self::find_def( $key );
				if ( ! $found ) {
					return new WP_Error(
						'woof_mcp_unknown_filter',
						'Unknown filter "' . $key . '". This shop can be filtered by: ' . implode( ', ', array_keys( $defs ) ) . '. Call husky_get_filter_schema for their values.'
					);
				}
				$key = $found;
			}

			$def = $defs[ $key ];
			$res = self::request_part( $def, $value );

			if ( is_wp_error( $res ) ) {
				$errors[] = $res->get_error_message();
				continue;
			}

			foreach ( $res['request'] as $rk => $rv ) {
				$request[ $rk ] = $rv;
			}
			$applied[ $key ] = $res['applied'];
			if ( isset( $res['slugs'] ) ) {
				$chosen[ $key ] = $res['slugs'];
			}
		}

		if ( ! empty( $errors ) ) {
			return new WP_Error( 'woof_mcp_bad_value', implode( ' ', $errors ) );
		}

		if ( ! empty( $request ) ) {
			$request = array( woof()->get_swoof_search_slug() => '1' ) + $request;
		}

		return array(
			'request' => $request,
			'applied' => $applied,
			'chosen'  => $chosen,
		);
	}

	private static function find_def( $key ) {
		$lower = strtolower( $key );
		foreach ( self::filters() as $k => $def ) {
			if ( strtolower( $k ) === $lower || ( isset( $def['request_key'] ) && $def['request_key'] === $key ) ) {
				return $k;
			}
			if ( strtolower( $def['label'] ) === $lower ) {
				return $k;
			}
			// pa_color reached as "color"
			if ( 'taxonomy' === $def['kind'] && 'pa_' . $lower === $k ) {
				return $k;
			}
		}
		return '';
	}

	/**
	 * One filter's part of the request.
	 *
	 * @return array|WP_Error request, applied (normalised value), slugs
	 */
	private static function request_part( $def, $value ) {

		$label = '"' . $def['key'] . '"';

		switch ( $def['kind'] ) {

			case 'taxonomy':
				$values = is_array( $value ) ? $value : explode( ',', (string) $value );
				$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' ) );
				$flat   = self::flatten_terms( self::term_tree( $def ) );
				$slugs  = array();
				$bad    = array();
				foreach ( $values as $v ) {
					$slug = self::match_term( $v, $flat, $def['taxonomy'] );
					if ( '' === $slug ) {
						$bad[] = $v;
					} else {
						$slugs[] = $slug;
					}
				}
				if ( ! empty( $bad ) ) {
					return new WP_Error(
						'woof_mcp_bad_value',
						'Unknown value(s) ' . implode( ', ', array_map( 'wp_json_encode', $bad ) ) . ' for filter ' . $label . '. Valid values: ' . self::list_values( $flat ) . '.'
					);
				}
				$slugs = array_values( array_unique( $slugs ) );
				if ( ! $def['multiple'] && count( $slugs ) > 1 ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' takes one value only (the form offers a single choice here), got ' . count( $slugs ) . '.' );
				}
				return array(
					'request' => array( $def['request_key'] => implode( ',', $slugs ) ),
					'applied' => $slugs,
					'slugs'   => $slugs,
				);

			case 'price':
				if ( ! is_array( $value ) ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' takes an object {"min": number, "max": number} in the requested currency.' );
				}
				$min = isset( $value['min'] ) && '' !== $value['min'] && null !== $value['min'] ? floatval( $value['min'] ) : null;
				$max = isset( $value['max'] ) && '' !== $value['max'] && null !== $value['max'] ? floatval( $value['max'] ) : null;
				if ( null === $min && null === $max ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' needs min, max or both.' );
				}
				if ( null !== $min && null !== $max && $min > $max ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ': min is above max.' );
				}
				// The form submits prices in the shop's base currency: the
				// front end divides by the FOX rate before sending (js/front.js)
				$base_min = null === $min ? 0 : self::from_currency( $min );
				$base_max = null === $max ? self::price_ceiling() : self::from_currency( $max );
				if ( '' !== $def['ranges'] ) {
					$ok = false;
					foreach ( self::price_ranges( $def ) as $r ) {
						if ( abs( $r['min'] - ( null === $min ? 0 : $min ) ) < 0.01 && ( $r['open'] ? null === $max || $max >= self::to_currency( self::price_ceiling(), true ) - 0.01 : null !== $max && abs( $r['max'] - $max ) < 0.01 ) ) {
							$ok = true;
							break;
						}
					}
					if ( ! $ok ) {
						return new WP_Error( 'woof_mcp_bad_value', 'This shop filters price by fixed ranges only: ' . wp_json_encode( self::price_ranges( $def ) ) . ' (in ' . self::currency() . '). Pass the min and max of one of them.' );
					}
				}
				return array(
					'request' => array(
						'min_price' => self::num( $base_min ),
						'max_price' => self::num( $base_max ),
					),
					'applied' => array(
						'min'      => $min,
						'max'      => $max,
						'currency' => self::currency(),
					),
				);

			case 'flag':
				$on = is_bool( $value ) ? $value : in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
				if ( ! $on ) {
					return array(
						'request' => array(),
						'applied' => false,
					);
				}
				return array(
					'request' => array( $def['request_key'] => $def['on_value'] ),
					'applied' => true,
				);

			case 'rating':
				$r = intval( $value );
				if ( $r < 1 || $r > 4 ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' takes 1, 2, 3 or 4 (4 means four stars and more).' );
				}
				return array(
					'request' => array( 'min_rating' => (string) $r ),
					'applied' => $r,
				);

			case 'text':
				$text = trim( wp_strip_all_tags( is_scalar( $value ) ? (string) $value : '' ) );
				if ( '' === $text ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' takes text.' );
				}
				$text = mb_substr( $text, 0, self::MAX_TEXT );
				return array(
					'request' => array( $def['request_key'] => $text ),
					'applied' => $text,
				);

			case 'list':
				$values = is_array( $value ) ? $value : explode( ',', (string) $value );
				$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' ) );
				return array(
					'request' => array( $def['request_key'] => implode( ',', $values ) ),
					'applied' => $values,
				);

			case 'choice':
				$values = is_array( $value ) ? $value : array( $value );
				$values = array_values( array_filter( array_map( 'trim', array_map( 'strval', $values ) ), 'strlen' ) );
				if ( empty( $def['multiple'] ) && count( $values ) > 1 ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' takes one value only.' );
				}
				$send = array();
				$bad  = array();
				foreach ( $values as $v ) {
					$hit = null;
					foreach ( $def['choices'] as $c ) {
						if ( strtolower( $c['value'] ) === strtolower( $v ) || strtolower( wp_strip_all_tags( $c['label'] ) ) === strtolower( $v ) ) {
							$hit = $c;
							break;
						}
					}
					if ( ! $hit ) {
						$bad[] = $v;
						continue;
					}
					// meta select/mselect send the option position, the rest the value
					$send[] = isset( $hit['position'] ) ? (string) $hit['position'] : $hit['value'];
				}
				if ( ! empty( $bad ) ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Unknown value(s) ' . implode( ', ', array_map( 'wp_json_encode', $bad ) ) . ' for filter ' . $label . '. Valid values: ' . implode( ', ', wp_list_pluck( $def['choices'], 'value' ) ) . '.' );
				}
				return array(
					'request' => array( $def['request_key'] => implode( ',', $send ) ),
					'applied' => $values,
				);

			case 'range':
				if ( ! is_array( $value ) ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' takes an object {"min": number, "max": number}.' );
				}
				$min = isset( $value['min'] ) && is_numeric( $value['min'] ) ? floatval( $value['min'] ) : $def['min'];
				$max = isset( $value['max'] ) && is_numeric( $value['max'] ) ? floatval( $value['max'] ) : $def['max'];
				if ( $min > $max ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ': min is above max.' );
				}
				return array(
					'request' => array( $def['request_key'] => self::num( $min ) . '^' . self::num( $max ) ),
					'applied' => array(
						'min' => $min,
						'max' => $max,
					),
				);

			case 'date_range':
				if ( ! is_array( $value ) ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' takes an object {"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}.' );
				}
				$from = ! empty( $value['from'] ) ? strtotime( $value['from'] . ' 00:00:01' ) : false;
				$to   = ! empty( $value['to'] ) ? strtotime( $value['to'] . ' 23:59:59' ) : false;
				if ( ! $from && ! $to ) {
					return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' needs from, to or both as YYYY-MM-DD.' );
				}
				return array(
					'request' => array( $def['request_key'] => ( $from ? $from : 'i' ) . '-' . ( $to ? $to : 'i' ) ),
					'applied' => array(
						'from' => $from ? gmdate( 'Y-m-d', $from ) : null,
						'to'   => $to ? gmdate( 'Y-m-d', $to ) : null,
					),
				);
		}

		return new WP_Error( 'woof_mcp_bad_value', 'Filter ' . $label . ' cannot be used.' );
	}

	/**
	 * A value to a term slug: the slug itself, else the name in any case.
	 * A slug of the same term in another language is translated, so an agent
	 * switching languages mid-conversation is not stuck.
	 */
	private static function match_term( $value, $flat, $taxonomy ) {

		$v = strtolower( trim( $value ) );

		if ( isset( $flat[ $value ] ) ) {
			return $value;
		}
		foreach ( $flat as $slug => $node ) {
			if ( strtolower( (string) $slug ) === $v || strtolower( $node['label'] ) === $v || sanitize_title( $node['label'] ) === sanitize_title( $value ) ) {
				return (string) $slug;
			}
		}

		// another language's slug of a term that has a translation here
		$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			$tr = 0;
			if ( class_exists( 'SitePress' ) ) {
				$tr = intval( apply_filters( 'wpml_object_id', $term->term_id, $taxonomy, false, self::lang() ) );
			} elseif ( function_exists( 'pll_get_term' ) ) {
				$tr = intval( pll_get_term( $term->term_id, self::lang() ) );
			}
			if ( $tr ) {
				foreach ( $flat as $slug => $node ) {
					if ( $node['term_id'] === $tr ) {
						return (string) $slug;
					}
				}
			}
		}

		return '';
	}

	private static function list_values( $flat ) {
		$items = array();
		foreach ( $flat as $slug => $node ) {
			$items[] = $slug . ( strtolower( $node['label'] ) !== strtolower( $slug ) ? ' (' . $node['label'] . ')' : '' );
		}
		if ( count( $items ) > 120 ) {
			$items   = array_slice( $items, 0, 120 );
			$items[] = '... (see husky_get_filter_schema)';
		}
		return implode( ', ', $items );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// tool 2: search

	public static function search_products( $args ) {

		$filters  = isset( $args['filters'] ) && is_array( $args['filters'] ) ? $args['filters'] : array();
		$page     = isset( $args['page'] ) ? max( 1, intval( $args['page'] ) ) : 1;
		$per_page = isset( $args['per_page'] ) ? intval( $args['per_page'] ) : self::default_per_page();
		$per_page = max( 1, min( self::max_per_page(), $per_page ) );
		$sort     = isset( $args['sort'] ) ? (string) $args['sort'] : '';

		if ( '' !== $sort ) {
			$valid = wp_list_pluck( self::sort_options(), 'value' );
			if ( ! in_array( $sort, $valid, true ) ) {
				return new WP_Error( 'woof_mcp_bad_sort', 'Unknown sort "' . $sort . '". Available: ' . implode( ', ', $valid ) . '.' );
			}
		}

		$built = self::build_request( $filters );

		if ( is_wp_error( $built ) ) {
			return $built;
		}

		self::$selected = $built['chosen'];
		self::$applied  = $built['applied'];

		$request = $built['request'];
		if ( '' !== $sort ) {
			$request['orderby'] = $sort;
		}

		return self::in_front_request(
			$request,
			function () use ( $request, $built, $page, $per_page, $sort ) {

				$res = self::front_query( $request, $page, $per_page );

				$products = array();
				foreach ( $res['ids'] as $id ) {
					$card = self::product_card( $id, $built['chosen'] );
					if ( $card ) {
						$products[] = $card;
					}
				}

				$out = array(
					'total'    => $res['total'],
					'page'     => $page,
					'pages'    => $res['pages'],
					'per_page' => $per_page,
					'filters'  => (object) $built['applied'],
					'sort'     => '' !== $sort ? $sort : (string) get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ),
					'language' => self::lang(),
					'currency' => self::currency(),
				);

				// What else can narrow this down: every filter's values with the
				// count each would give now (HUSKY's dynamic recount)
				$out['refine'] = self::refine();

				$out['products'] = $products;

				if ( 0 === $res['total'] ) {
					$out['message'] = 'Nothing found for these filters.';
					$out['nearest'] = self::nearest( $request, $built );
				} elseif ( $page > $res['pages'] ) {
					$out['message'] = 'There are only ' . $res['pages'] . ' pages for these filters.';
				}

				return $out;
			}
		);
	}

	/**
	 * Every filter with what it can still do under the current request.
	 */
	private static function refine() {

		$out = array();

		foreach ( self::filters() as $def ) {

			if ( 'taxonomy' === $def['kind'] ) {
				$values = self::values_with_counts( $def, true, true );
				if ( empty( $values ) ) {
					continue;
				}
				$out[] = array(
					'key'      => $def['key'],
					'label'    => $def['label'],
					'multiple' => $def['multiple'],
					'logic'    => $def['logic'],
					'values'   => $values,
				);
			} elseif ( 'price' === $def['kind'] ) {
				$range = self::price_range();
				$out[] = array(
					'key'      => 'price',
					'label'    => $def['label'],
					'min'      => $range['min'],
					'max'      => $range['max'],
					'currency' => self::currency(),
				);
			} elseif ( 'flag' === $def['kind'] && 'on_sale' === $def['key'] && ! isset( self::$applied['on_sale'] ) ) {
				// the form shows this count only while the flag is not chosen
				$out[] = array(
					'key'   => 'on_sale',
					'label' => $def['label'],
					'count' => self::count_flag( $def ),
				);
			}
		}

		return $out;
	}

	/**
	 * When nothing matches: how many products each filter would give if it
	 * were dropped, and for taxonomy filters the alternatives that do have
	 * products (from the recount: another color instead of red, ...).
	 */
	private static function nearest( $request, $built ) {

		$out = array();

		foreach ( $built['applied'] as $key => $applied ) {

			$def     = self::filters()[ $key ];
			$without = $request;
			$part    = self::request_part( $def, $applied );

			if ( ! is_wp_error( $part ) ) {
				foreach ( array_keys( $part['request'] ) as $rk ) {
					unset( $without[ $rk ] );
				}
			}

			$hint = array(
				'filter'          => $key,
				'without_it'      => self::count_request( $without ),
			);

			if ( 'taxonomy' === $def['kind'] ) {
				$flat    = self::flatten_terms( self::values_with_counts( $def, true, true ) );
				$related = self::related_terms( $flat, isset( $built['chosen'][ $key ] ) ? $built['chosen'][ $key ] : array() );
				$alts    = array();
				foreach ( $flat as $slug => $node ) {
					if ( ! empty( $node['count'] ) ) {
						$alts[] = array(
							'value' => $slug,
							'label' => $node['label'],
							'count' => $node['count'],
							'near'  => isset( $related[ $slug ] ),
						);
					}
				}
				// what is close in the tree first (the parent, the neighbours, the
				// children of what was asked for), then the rest by size
				usort(
					$alts,
					function ( $a, $b ) {
						if ( $a['near'] !== $b['near'] ) {
							return $a['near'] ? -1 : 1;
						}
						return $b['count'] - $a['count'];
					}
				);
				foreach ( $alts as &$alt ) {
					if ( ! $alt['near'] ) {
						unset( $alt['near'] );
					}
				}
				unset( $alt );
				$hint['instead'] = array_slice( $alts, 0, 10 );
			} elseif ( 'price' === $def['kind'] ) {
				// what the other filters give in price: "the cheapest one is ..."
				$saved = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$_GET  = $without;
				$range = self::price_range();
				$_GET  = $saved;
				if ( $hint['without_it'] > 0 ) {
					$hint['price_range_without_it'] = array(
						'min'      => $range['min'],
						'max'      => $range['max'],
						'currency' => self::currency(),
					);
				}
			}

			$out[] = $hint;
		}

		return $out;
	}

	/**
	 * Slugs close to the chosen ones in a term tree: their ancestors, siblings
	 * and children.
	 *
	 * @param array $flat   slug => node with 'parent' slug (flatten_terms())
	 * @param array $chosen chosen slugs
	 */
	private static function related_terms( $flat, $chosen ) {
		$out = array();
		foreach ( $chosen as $slug ) {
			if ( ! isset( $flat[ $slug ] ) ) {
				continue;
			}
			$parent = $flat[ $slug ]['parent'];
			// ancestors
			$p = $parent;
			while ( '' !== $p && isset( $flat[ $p ] ) ) {
				$out[ $p ] = true;
				$p         = $flat[ $p ]['parent'];
			}
			foreach ( $flat as $s => $node ) {
				// siblings and children
				if ( ( '' !== $parent && $node['parent'] === $parent ) || $node['parent'] === $slug ) {
					$out[ $s ] = true;
				}
			}
			unset( $out[ $slug ] );
		}
		return $out;
	}

	/**
	 * How many products a request gives, by HUSKY's recount.
	 */
	private static function count_request( $request ) {

		$saved = $_GET; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$_GET  = empty( $request ) ? array() : $request;
		$n     = intval( woof()->dynamic_count( array(), 'none', '' ) );
		$_GET  = $saved;

		return $n;
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// running the shop's own query

	/**
	 * Runs $fn with $request as the visitor's GET parameters and puts
	 * everything back afterwards.
	 */
	private static function in_front_request( $request, $fn ) {

		$saved_get     = $_GET;     // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$saved_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$_GET     = $request;
		$_REQUEST = $request;

		try {
			$result = $fn();
		} finally {
			$_GET     = $saved_get;
			$_REQUEST = $saved_request;
		}

		return $result;
	}

	/**
	 * The shop page's own query for $request: one page of product ids, the
	 * total and the number of pages.
	 *
	 * Default mode: what WordPress does for a visit to the shop URL with this
	 * query string. The shop URL is parsed by WP::parse_request(), then the
	 * main WP_Query runs, so WooCommerce's product query and HUSKY's hooks
	 * shape it exactly as on the front end. With "Try to ajaxify the shop" on,
	 * the front end shows [woof_products] instead, so that query is run.
	 *
	 * @return array ids, total, pages
	 */
	public static function front_query( $request, $page, $per_page ) {

		if ( get_option( 'woof_try_ajax', 0 ) ) {
			return self::shortcode_query( $request, $page, $per_page );
		}

		global $wp, $wp_query, $wp_the_query;

		$saved = array(
			'uri'          => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '', // phpcs:ignore
			'wp_vars'      => $wp->query_vars,
			'wp_string'    => $wp->query_string,
			'wp_request'   => $wp->request,
			'wp_rule'      => $wp->matched_rule,
			'wp_query'     => $wp->matched_query,
			'wp_query_obj' => $wp_query,
			'the_query'    => $wp_the_query,
		);

		$url  = add_query_arg( array_map( 'rawurlencode', $request ), self::shop_url() );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$qs   = (string) wp_parse_url( $url, PHP_URL_QUERY );

		$_SERVER['REQUEST_URI'] = $path . ( '' !== $qs ? '?' . $qs : '' );

		// HUSKY's REST guards stand aside for this query only
		HUSKY::$mcp_front_query = true;

		$per_page_cb = function () use ( $per_page ) {
			return $per_page;
		};
		add_filter( 'loop_shop_per_page', $per_page_cb, PHP_INT_MAX );

		$visible_cb = function ( $q ) {
			if ( $q->is_main_query() ) {
				// on top of the shop page: no password protected products
				$q->set( 'has_password', false );
			}
		};
		add_action( 'pre_get_posts', $visible_cb, PHP_INT_MAX );

		$q = null;

		try {
			$wp->parse_request();

			$vars          = self::shop_page_to_archive( $wp->query_vars );
			$vars['paged'] = $page;

			$q            = new WP_Query();
			$wp_the_query = $q;
			$wp_query     = $q;
			$q->query( $vars );

			$ids = array_map( 'intval', wp_list_pluck( $q->posts, 'ID' ) );

			$out = array(
				'ids'   => $ids,
				'total' => intval( $q->found_posts ),
				'pages' => intval( $q->max_num_pages ),
			);
		} finally {
			remove_filter( 'loop_shop_per_page', $per_page_cb, PHP_INT_MAX );
			remove_action( 'pre_get_posts', $visible_cb, PHP_INT_MAX );
			HUSKY::$mcp_front_query = false;

			$_SERVER['REQUEST_URI'] = $saved['uri'];
			$wp->query_vars         = $saved['wp_vars'];
			$wp->query_string       = $saved['wp_string'];
			$wp->request            = $saved['wp_request'];
			$wp->matched_rule       = $saved['wp_rule'];
			$wp->matched_query      = $saved['wp_query'];
			$wp_query               = $saved['wp_query_obj'];
			$wp_the_query           = $saved['the_query'];
			wp_reset_postdata();
			if ( function_exists( 'wc_reset_loop' ) ) {
				wc_reset_loop();
			}
		}

		return $out;
	}

	/**
	 * The shop page as the products archive, the way WC_Query::pre_get_posts()
	 * turns it into one. Done here from the parsed address because inside an
	 * API request a multilingual plugin may filter the page itself by
	 * language (Polylang without its WooCommerce add-on finds no "shop" page
	 * in German) before WooCommerce gets to recognise it.
	 */
	private static function shop_page_to_archive( $vars ) {

		$shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;

		if ( $shop_id <= 0 ) {
			return $vars;
		}

		$is_shop = false;
		if ( ! empty( $vars['page_id'] ) && intval( $vars['page_id'] ) === $shop_id ) {
			$is_shop = true;
		} elseif ( ! empty( $vars['pagename'] ) && trim( $vars['pagename'], '/' ) === get_page_uri( $shop_id ) ) {
			$is_shop = true;
		}

		if ( ! $is_shop ) {
			return $vars;
		}

		unset( $vars['pagename'], $vars['page_id'], $vars['page'] );
		$vars['post_type'] = 'product';

		return $vars;
	}

	/**
	 * The [woof_products] query, for shops in AJAX mode.
	 */
	private static function shortcode_query( $request, $page, $per_page ) {

		HUSKY::$mcp_front_query = true;

		try {
			$args = woof()->woof_products(
				array(
					'get_args_only' => true,
					'per_page'      => $per_page,
					'page'          => $page,
				)
			);
			WOOF_REQUEST::del( 'woof_products_doing' );

			$args['paged']        = $page;
			$args['has_password'] = false;
			$args['fields']       = 'ids';

			// ajax mode leaves catalog-hidden products in when HUSKY does not
			// listen to catalog visibility; the agent never sees those
			if ( ! woof()->get_option( 'listen_catalog_visibility' ) ) {
				$args = array( 'tax_query' => woof()->product_visibility_not_in( isset( $args['tax_query'] ) ? $args['tax_query'] : array(), array( 'exclude-from-catalog' ) ) ) + $args;
			}

			$q = new WP_Query( $args );

			$out = array(
				'ids'   => array_map( 'intval', $q->posts ),
				'total' => intval( $q->found_posts ),
				'pages' => intval( $q->max_num_pages ),
			);
		} finally {
			HUSKY::$mcp_front_query = false;
			wp_reset_postdata();
		}

		return $out;
	}

	/**
	 * The shop page in the current language.
	 */
	public static function shop_url() {

		$shop_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'shop' ) : 0;

		// Polylang free knows nothing about the shop page: the products archive
		// is the shop in every language, with the language in the address
		// (a translated copy of the shop page would be a plain page there)
		if ( function_exists( 'PLL' ) && ! class_exists( 'SitePress' ) && '' !== self::lang() && isset( PLL()->links_model ) ) {
			$url      = $shop_id > 0 ? get_permalink( $shop_id ) : get_post_type_archive_link( 'product' );
			$language = PLL()->model->get_language( self::lang() );
			if ( $url && $language ) {
				return PLL()->links_model->switch_language_in_link( $url, $language );
			}
		}

		if ( $shop_id > 0 ) {
			if ( class_exists( 'SitePress' ) ) {
				$shop_id = intval( apply_filters( 'wpml_object_id', $shop_id, 'page', true, self::lang() ) );
			}
			$url = get_permalink( $shop_id );
			if ( $url ) {
				return $url;
			}
		}

		return add_query_arg( 'post_type', 'product', home_url( '/' ) );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// tool 3: one product

	public static function get_product( $args ) {

		$id = 0;

		if ( ! empty( $args['id'] ) ) {
			$id = intval( $args['id'] );
		} elseif ( ! empty( $args['url'] ) ) {
			$id = url_to_postid( (string) $args['url'] );
		} elseif ( ! empty( $args['sku'] ) ) {
			$id = wc_get_product_id_by_sku( (string) $args['sku'] );
		}

		if ( $id <= 0 ) {
			return new WP_Error( 'woof_mcp_no_product', 'Give the product id (from husky_search_products), its page URL or its SKU.' );
		}

		$product = wc_get_product( $id );

		// a variation id leads to its product
		if ( $product && $product->is_type( 'variation' ) ) {
			$product = wc_get_product( $product->get_parent_id() );
		}

		// The same language as the request: WPML and Polylang keep a product
		// per language
		if ( $product && '' !== self::lang() ) {
			$tr = 0;
			if ( class_exists( 'SitePress' ) ) {
				$tr = intval( apply_filters( 'wpml_object_id', $product->get_id(), 'product', false, self::lang() ) );
			} elseif ( function_exists( 'pll_get_post' ) ) {
				$tr = intval( pll_get_post( $product->get_id(), self::lang() ) );
			}
			if ( $tr && $tr !== $product->get_id() ) {
				$product = wc_get_product( $tr );
			}
		}

		if ( ! $product || ! self::is_public( $product ) ) {
			return new WP_Error( 'woof_mcp_no_product', 'No product with id ' . $id . ' is available in this shop.' );
		}

		$selected = isset( $args['attributes'] ) && is_array( $args['attributes'] ) ? $args['attributes'] : array();

		return self::in_front_request(
			array(),
			function () use ( $product, $selected ) {
				return self::product_details( $product, $selected );
			}
		);
	}

	/**
	 * Published, not password protected, visible in the catalog, and not
	 * out of stock when the shop hides those.
	 */
	public static function is_public( $product ) {

		$post = get_post( $product->get_id() );

		if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password ) {
			return false;
		}

		if ( 'hidden' === $product->get_catalog_visibility() ) {
			return false;
		}

		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $product->is_in_stock() ) {
			return false;
		}

		return (bool) apply_filters( 'woof_mcp_product_is_public', true, $product );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// product data

	/**
	 * A product as a search result card.
	 *
	 * @param int   $id
	 * @param array $chosen taxonomy key => chosen slugs, to preselect a variation
	 */
	public static function product_card( $id, $chosen = array() ) {

		$product = wc_get_product( $id );

		if ( ! $product ) {
			return null;
		}

		$variation = self::matching_variation( $product, $chosen );
		$priced    = $variation ? $variation : $product;

		$card = array(
			'id'                => $product->get_id(),
			'name'              => self::text( $product->get_name() ),
			'type'              => $product->get_type(),
			'url'               => self::product_url( $product, $variation ),
		);

		$card += self::prices( $priced );

		$card['stock_status']      = $priced->get_stock_status();
		$card['in_stock']          = $priced->is_in_stock();
		$card['image']             = self::image_url( $priced->get_image_id() ? $priced->get_image_id() : $product->get_image_id() );
		$card['short_description'] = self::excerpt( $product, 300 );
		$card['attributes']        = self::attributes( $product, true );
		$card['rating']            = round( floatval( $product->get_average_rating() ), 2 );
		$card['review_count']      = intval( $product->get_review_count() );

		$brands = self::brands( $product );
		if ( ! empty( $brands ) ) {
			$card['brand'] = implode( ', ', $brands );
		}

		if ( $variation ) {
			$card['variation'] = array(
				'id'         => $variation->get_id(),
				'attributes' => self::variation_attributes( $variation ),
			);
		}

		return apply_filters( 'woof_mcp_product_card', $card, $product );
	}

	public static function product_details( $product, $selected = array() ) {

		$out = array(
			'id'                => $product->get_id(),
			'name'              => self::text( $product->get_name() ),
			'type'              => $product->get_type(),
			'url'               => self::product_url( $product, null ),
			'sku'               => (string) $product->get_sku(),
		);

		$out += self::prices( $product );

		$out['stock_status']      = $product->get_stock_status();
		$out['in_stock']          = $product->is_in_stock();
		$out['stock_quantity']    = $product->managing_stock() ? $product->get_stock_quantity() : null;
		$out['short_description'] = self::excerpt( $product, 600 );
		$out['description']       = self::plain( $product->get_description(), 4000 );
		$out['image']             = self::image_url( $product->get_image_id() );
		$out['gallery']           = array_values( array_filter( array_map( array( __CLASS__, 'image_url' ), $product->get_gallery_image_ids() ) ) );
		$out['categories']        = self::term_names( $product->get_id(), 'product_cat' );
		$out['tags']              = self::term_names( $product->get_id(), 'product_tag' );
		$out['brands']            = self::brands( $product );
		$out['attributes']        = self::attributes( $product, false );
		$out['rating']            = round( floatval( $product->get_average_rating() ), 2 );
		$out['review_count']      = intval( $product->get_review_count() );

		$weight = $product->get_weight();
		if ( '' !== (string) $weight ) {
			$out['weight'] = $weight . ' ' . get_option( 'woocommerce_weight_unit' );
		}

		if ( $product->is_type( 'variable' ) ) {
			$out['variations'] = array();
			foreach ( $product->get_children() as $vid ) {
				$v = wc_get_product( $vid );
				if ( ! $v || 'publish' !== get_post_status( $vid ) ) {
					continue;
				}
				if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) && ! $v->is_in_stock() ) {
					continue;
				}
				$row = array(
					'id'         => $v->get_id(),
					'attributes' => self::variation_attributes( $v ),
					'sku'        => (string) $v->get_sku(),
				);
				$row += self::prices( $v );
				$row['stock_status'] = $v->get_stock_status();
				$row['in_stock']     = $v->is_in_stock();
				$row['url']          = self::product_url( $product, $v );
				$img = $v->get_image_id();
				if ( $img && $img !== $product->get_image_id() ) {
					$row['image'] = self::image_url( $img );
				}
				$out['variations'][] = $row;
			}
		}

		// the page link with a variation preselected, when the agent asked for one
		if ( ! empty( $selected ) && $product->is_type( 'variable' ) ) {
			$chosen = array();
			foreach ( $selected as $k => $v ) {
				$k            = 0 === strpos( (string) $k, 'pa_' ) || taxonomy_exists( (string) $k ) ? (string) $k : 'pa_' . sanitize_title( (string) $k );
				$chosen[ $k ] = array( sanitize_title( is_array( $v ) ? reset( $v ) : (string) $v ) );
			}
			$variation = self::matching_variation( $product, $chosen );
			if ( $variation ) {
				$out['url']                = self::product_url( $product, $variation );
				$out['selected_variation'] = $variation->get_id();
			}
		}

		return apply_filters( 'woof_mcp_product_details', $out, $product );
	}

	/**
	 * When the search fixed exactly one value of a variation attribute, the
	 * variation of this product with those values - so the link opens with it
	 * selected and the card shows its price and stock.
	 */
	private static function matching_variation( $product, $chosen ) {

		if ( ! $product->is_type( 'variable' ) || empty( $chosen ) ) {
			return null;
		}

		$wanted = array();
		foreach ( $product->get_variation_attributes() as $attr => $options ) {
			$key = sanitize_title( $attr );
			if ( isset( $chosen[ $key ] ) && 1 === count( $chosen[ $key ] ) ) {
				$wanted[ 'attribute_' . $key ] = $chosen[ $key ][0];
			}
		}

		if ( empty( $wanted ) ) {
			return null;
		}

		$best = null;
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v || 'publish' !== get_post_status( $vid ) ) {
				continue;
			}
			$attrs = $v->get_attributes();
			$ok    = true;
			foreach ( $wanted as $k => $slug ) {
				$a = substr( $k, 10 );
				// an empty value means "any" on the variation
				if ( isset( $attrs[ $a ] ) && '' !== $attrs[ $a ] && $attrs[ $a ] !== $slug ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				if ( $v->is_in_stock() ) {
					return $v;
				}
				if ( ! $best ) {
					$best = $v;
				}
			}
		}

		return $best;
	}

	/**
	 * The product page in the requested language, with the currency of the
	 * request (FOX reads ?currency=) and the chosen variation preselected.
	 * Never a cart or checkout link.
	 */
	public static function product_url( $product, $variation = null ) {

		$url = get_permalink( $product->get_id() );

		if ( class_exists( 'SitePress' ) && '' !== self::lang() ) {
			$url = apply_filters( 'wpml_permalink', $url, self::lang() );
		}

		$args = array();

		if ( $variation ) {
			foreach ( $variation->get_attributes() as $attr => $value ) {
				if ( '' !== (string) $value ) {
					$args[ 'attribute_' . sanitize_title( $attr ) ] = $value;
				}
			}
		}

		global $WOOCS;
		if ( is_object( $WOOCS ) && '' !== self::currency() && self::currency() !== self::base_currency() ) {
			$args['currency'] = self::currency();
		}

		return empty( $args ) ? $url : add_query_arg( array_map( 'rawurlencode', $args ), $url );
	}

	/**
	 * Prices as the front end shows them, in the requested currency: the
	 * text from get_price_html() and the numbers from FOX's own conversion.
	 */
	private static function prices( $product ) {

		$price   = $product->get_price();
		$regular = $product->get_regular_price();
		$sale    = $product->get_sale_price();

		if ( $product->is_type( 'variable' ) ) {
			$min_reg = $product->get_variation_regular_price( 'min' );
			$max_reg = $product->get_variation_regular_price( 'max' );
			$regular = $min_reg;
			$sale    = $product->is_on_sale() ? $product->get_variation_sale_price( 'min' ) : '';
			$price   = $product->get_variation_price( 'min' );
			$out     = array(
				'price'         => self::money( $price ),
				'price_max'     => self::money( $product->get_variation_price( 'max' ) ),
				'regular_price' => self::money( $regular ),
				'regular_price_max' => self::money( $max_reg ),
			);
		} else {
			$out = array(
				'price'         => self::money( $price ),
				'regular_price' => self::money( $regular ),
			);
		}

		$out['sale_price'] = $product->is_on_sale() && '' !== (string) $sale ? self::money( $sale ) : null;
		$out['on_sale']    = $product->is_on_sale();
		$out['currency']   = self::currency();
		$out['price_text'] = self::price_text( $product->get_price_html() );

		return $out;
	}

	/**
	 * The price as text. WooCommerce writes a sale price or a price range twice:
	 * for the eye (aria-hidden) and for screen readers ("Original price was:
	 * 89.99$. Current price is: 71.99$.", "Price range: 99$ through 143$").
	 * Flattened together that reads "89.99$ Original price was: 89.99$.71.99$...";
	 * the screen reader text alone is the plain-language version, translated
	 * like the shop. Without it the visible text is used.
	 */
	public static function price_text( $html ) {
		if ( preg_match_all( '~<span[^>]*class="[^"]*\bscreen-reader-text\b[^"]*"[^>]*>(.*?)</span>~s', (string) $html, $m ) && ! empty( $m[1] ) ) {
			return self::plain( implode( ' ', $m[1] ), 200 );
		}
		return self::plain( $html, 200 );
	}

	/**
	 * A product getter's price as a number in the requested currency. In FOX's
	 * multiple-currency mode the getters already return converted prices; in
	 * the other mode prices are converted only for display, through
	 * woocs_exchange_value().
	 */
	private static function money( $value ) {
		if ( '' === (string) $value || null === $value ) {
			return null;
		}
		global $WOOCS;
		if ( is_object( $WOOCS ) && empty( $WOOCS->is_multiple_allowed ) && self::currency() !== self::base_currency() ) {
			return self::to_currency( floatval( $value ), false );
		}
		return round( floatval( $value ), wc_get_price_decimals() );
	}

	/**
	 * Base currency to the requested one, by FOX's own woocs_exchange_value().
	 */
	private static function to_currency( $value, $round ) {
		global $WOOCS;
		if ( ! is_object( $WOOCS ) || self::currency() === self::base_currency() || '' === self::currency() ) {
			return $round ? round( floatval( $value ), wc_get_price_decimals() ) : floatval( $value );
		}
		$converted = (string) $WOOCS->woocs_exchange_value( floatval( $value ) );
		$sep       = isset( $WOOCS->decimal_sep ) ? (string) $WOOCS->decimal_sep : '.';
		if ( '.' !== $sep && '' !== $sep ) {
			$converted = str_replace( $sep, '.', $converted );
		}
		return floatval( $converted );
	}

	/**
	 * The requested currency to the base one, as the front end does before it
	 * submits the price filter (value / rate).
	 */
	private static function from_currency( $value ) {
		global $WOOCS;
		if ( ! is_object( $WOOCS ) || self::currency() === self::base_currency() || '' === self::currency() ) {
			return floatval( $value );
		}
		$currencies = $WOOCS->get_currencies();
		$rate       = isset( $currencies[ self::currency() ]['rate'] ) ? floatval( $currencies[ self::currency() ]['rate'] ) : 1;
		return $rate > 0 ? floatval( $value ) / $rate : floatval( $value );
	}

	private static function price_ceiling() {
		return max( 1, ceil( floatval( WOOF_HELPER::get_max_price() ) ) );
	}

	private static function num( $n ) {
		$n = round( floatval( $n ), 4 );
		return (string) ( floor( $n ) == $n ? intval( $n ) : $n ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}

	private static function variation_attributes( $variation ) {
		$out = array();
		foreach ( $variation->get_attributes() as $attr => $value ) {
			$label = wc_attribute_label( $attr );
			$name  = $value;
			if ( taxonomy_exists( $attr ) && '' !== (string) $value ) {
				$term = get_term_by( 'slug', $value, $attr );
				if ( $term ) {
					$name = $term->name;
				}
			}
			$out[ $label ] = '' === (string) $value ? 'any' : html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
		}
		return $out;
	}

	/**
	 * Visible attributes: label => values. For cards only the ones a shopper
	 * can filter by and the variation axes, which is what an agent needs to
	 * say why a product fits.
	 */
	private static function attributes( $product, $key_only ) {

		$out     = array();
		$filters = $key_only ? self::filters() : array();

		foreach ( $product->get_attributes() as $name => $attr ) {

			if ( ! $attr->get_visible() && ! $attr->get_variation() ) {
				continue;
			}

			if ( $key_only && ! $attr->get_variation() && $attr->is_taxonomy() && ! isset( $filters[ $name ] ) ) {
				continue;
			}

			if ( $attr->is_taxonomy() ) {
				$values = array();
				foreach ( $attr->get_terms() as $t ) {
					$values[] = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				}
			} else {
				$values = $attr->get_options();
			}

			$out[ wc_attribute_label( $name, $product ) ] = array_values( $values );
		}

		return $out;
	}

	private static function brands( $product ) {
		return taxonomy_exists( 'product_brand' ) ? self::term_names( $product->get_id(), 'product_brand' ) : array();
	}

	private static function term_names( $id, $tax ) {
		$terms = get_the_terms( $id, $tax );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return array_values(
			array_map(
				function ( $t ) {
					return html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				},
				$terms
			)
		);
	}

	public static function image_url( $id ) {
		if ( ! $id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'woocommerce_single' );
		return $src ? $src[0] : null;
	}

	private static function excerpt( $product, $len ) {
		$text = $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			$text = $product->get_description();
		}
		return self::plain( $text, $len );
	}

	private static function text( $s ) {
		return html_entity_decode( wp_strip_all_tags( (string) $s ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Plain text, shortcodes and tags out, whitespace collapsed, cut at a
	 * word boundary.
	 */
	public static function plain( $html, $len ) {
		$text = strip_shortcodes( (string) $html );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) );
		if ( mb_strlen( $text ) > $len ) {
			$cut  = mb_substr( $text, 0, $len );
			$sp   = mb_strrpos( $cut, ' ' );
			$text = rtrim( false !== $sp && $sp > $len * 0.6 ? mb_substr( $cut, 0, $sp ) : $cut, ' ,.;:' ) . '…';
		}
		return $text;
	}
}
