<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * The same three operations in the WordPress Abilities API (core since 6.9),
 * so the MCP Adapter plugin and anything else that reads abilities sees them.
 *
 * One ability per tool, the same input and output schemas, and the execute
 * callback is HUSKY_MCP_TOOLS::run() - the handler the MCP transport uses.
 * Public and read-only: permission_callback is true, meta.public (WP 7.1)
 * and meta.mcp.public (MCP Adapter) are set, show_in_rest for older cores.
 *
 * Note: the MCP Adapter's default server still requires a logged-in user at
 * its transport, so anonymous agents use HUSKY's own endpoint; logged-in
 * agents and the adapter see the same operations here.
 */
final class HUSKY_MCP_ABILITIES {

	const CATEGORY = 'husky-product-filter';

	// tool name => ability name (namespace/name, lower case and dashes)
	const MAP = array(
		'husky_get_filter_schema' => 'husky/get-filter-schema',
		'husky_search_products'   => 'husky/search-products',
		'husky_get_product'       => 'husky/get-product',
	);

	public static function init() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return; // WordPress older than 6.9
		}
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category() {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'HUSKY product filter', 'woocommerce-products-filter' ),
				'description' => __( 'Find products in this shop with the filters of its HUSKY filter form. Read only.', 'woocommerce-products-filter' ),
			)
		);
	}

	public static function register_abilities() {

		foreach ( self::MAP as $tool => $ability ) {
			wp_register_ability(
				$ability,
				array(
					'label'               => HUSKY_MCP_TOOLS::title( $tool ),
					'description'         => HUSKY_MCP_TOOLS::description( $tool ),
					'category'            => self::CATEGORY,
					'input_schema'        => HUSKY_MCP_TOOLS::input_schema( $tool ),
					'output_schema'       => HUSKY_MCP_TOOLS::output_schema( $tool ),
					'execute_callback'    => function ( $input = null ) use ( $tool ) {
						return HUSKY_MCP_TOOLS::run( $tool, is_array( $input ) ? $input : array() );
					},
					'permission_callback' => '__return_true',
					'meta'                => array(
						'public'       => true,
						'show_in_rest' => true,
						'annotations'  => array(
							'readonly'    => true,
							'destructive' => false,
							'idempotent'  => true,
						),
						'mcp'          => array(
							'public'      => true,
							'type'        => 'tool',
							'annotations' => array(
								'openWorldHint' => false,
							),
						),
					),
				)
			);
		}
	}
}
