<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * The "MCP Server" tab of the HUSKY settings.
 *
 * The fields are part of the HUSKY settings form (woof_settings[mcp][...]) and
 * are saved by its Save button. After a save the pretty address is normalised,
 * checked for conflicts and the rewrite rules are flushed.
 */
final class HUSKY_MCP_ADMIN {

	public static function init() {
		add_action( 'woof_settings_saved', array( __CLASS__, 'after_save' ) );
		add_action( 'wp_ajax_woof_mcp_check_path', array( __CLASS__, 'ajax_check_path' ) );
	}

	/**
	 * Called by HUSKY::woof_save_options() with the settings just stored.
	 */
	public static function after_save( $settings ) {

		if ( ! is_array( $settings ) || ! isset( $settings['mcp'] ) || ! is_array( $settings['mcp'] ) ) {
			return;
		}

		$mcp = $settings['mcp'];

		$mcp['enabled']     = empty( $mcp['enabled'] ) ? 0 : 1;
		$mcp['pretty_path'] = HUSKY_MCP::sanitize_path( isset( $mcp['pretty_path'] ) ? $mcp['pretty_path'] : '' );

		foreach ( array( 'rl_enabled', 'llms_txt', 'head_link' ) as $k ) {
			$mcp[ $k ] = empty( $mcp[ $k ] ) ? 0 : 1;
		}
		foreach ( array( 'rl_ip_rpm', 'rl_ip_burst', 'rl_ai_rpm', 'rl_ai_burst', 'cache_ttl' ) as $k ) {
			$mcp[ $k ] = isset( $mcp[ $k ] ) ? max( 0, intval( $mcp[ $k ] ) ) : HUSKY_MCP::defaults()[ $k ];
		}
		$mcp['max_per_page'] = isset( $mcp['max_per_page'] ) ? max( 1, min( 100, intval( $mcp['max_per_page'] ) ) ) : 50;

		// a text equal to the default is stored empty, so it keeps following
		// the default when the plugin improves it
		require_once __DIR__ . '/tools.php';
		if ( isset( $mcp['instructions'] ) && self::same_text( $mcp['instructions'], HUSKY_MCP_TOOLS::default_instructions() ) ) {
			$mcp['instructions'] = '';
		}
		// the same for the AI platform ranges: an updated ai-ranges.txt reaches the shop
		if ( isset( $mcp['rl_ai_ranges'] ) && self::same_text( $mcp['rl_ai_ranges'], HUSKY_MCP_RATE_LIMIT::default_ai_ranges() ) ) {
			$mcp['rl_ai_ranges'] = '';
		}

		$settings['mcp'] = $mcp;
		update_option( 'woof_settings', $settings );

		// the rewrite rule follows the new address (or goes away)
		global $wp_rewrite;
		$all = get_option( 'woof_settings', array() );
		if ( $mcp['enabled'] && '' !== $mcp['pretty_path'] ) {
			add_rewrite_rule( '^' . preg_quote( $mcp['pretty_path'], '' ) . '/?$', 'index.php?' . HUSKY_MCP::QUERY_VAR . '=mcp', 'top' );
			add_rewrite_rule( '^' . preg_quote( $mcp['pretty_path'], '' ) . '/' . HUSKY_MCP::CARD_PATH . '/?$', 'index.php?' . HUSKY_MCP::QUERY_VAR . '=card', 'top' );
		} else {
			// drop a rule registered on this request from the old settings
			$old = HUSKY_MCP::pretty_path();
			if ( '' !== $old && isset( $wp_rewrite->extra_rules_top ) ) {
				unset( $wp_rewrite->extra_rules_top[ '^' . preg_quote( $old, '' ) . '/?$' ], $wp_rewrite->extra_rules_top[ '^' . preg_quote( $old, '' ) . '/' . HUSKY_MCP::CARD_PATH . '/?$' ] );
			}
		}
		flush_rewrite_rules( false );
	}

	private static function same_text( $a, $b ) {
		$norm = function ( $s ) {
			return trim( preg_replace( '/\s+/', ' ', str_replace( "\r", '', (string) $s ) ) );
		};
		return $norm( $a ) === $norm( $b );
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// pretty address conflicts

	/**
	 * What else on the site answers at this path: pages, posts, products,
	 * terms, WordPress' own paths, files and folders. Our address is matched
	 * before WordPress routes the request, so whatever is listed here would
	 * become unreachable at that address.
	 *
	 * @return string[] human readable conflicts, empty when the path is free
	 */
	public static function conflicts( $path ) {

		$path = HUSKY_MCP::sanitize_path( $path );
		$out  = array();

		if ( '' === $path ) {
			return $out;
		}

		$first    = explode( '/', $path )[0];
		$reserved = array( 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', trim( rest_get_url_prefix(), '/' ), 'feed', 'comments', 'xmlrpc.php', 'wp-login.php', 'index.php', '.well-known', 'llms.txt', 'sitemap.xml', 'robots.txt' );
		if ( in_array( $first, $reserved, true ) ) {
			/* translators: %s: path segment */
			$out[] = sprintf( __( '"%s" is reserved by WordPress or the server.', 'woocommerce-products-filter' ), $first );
		}

		if ( file_exists( ABSPATH . $path ) ) {
			$out[] = __( 'A file or folder with this name exists in the site root.', 'woocommerce-products-filter' );
		}

		$types = get_post_types( array( 'public' => true ) );
		$post  = get_page_by_path( $path, OBJECT, array_values( $types ) );
		if ( ! $post ) {
			$id   = url_to_postid( home_url( '/' . $path . '/' ) );
			$post = $id ? get_post( $id ) : null;
		}
		if ( $post && 'trash' !== $post->post_status ) {
			/* translators: 1: post type label, 2: title */
			$out[] = sprintf( __( 'The %1$s "%2$s" has this address.', 'woocommerce-products-filter' ), get_post_type_object( $post->post_type )->labels->singular_name, get_the_title( $post ) );
		}

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$slug = basename( $path );
			$term = get_term_by( 'slug', $slug, $tax->name );
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) && untrailingslashit( $link ) === untrailingslashit( home_url( '/' . $path ) ) ) {
					/* translators: 1: taxonomy label, 2: term name */
					$out[] = sprintf( __( 'The %1$s "%2$s" has this address.', 'woocommerce-products-filter' ), $tax->labels->singular_name, $term->name );
				}
			}
		}

		// post type archives (the shop is one)
		foreach ( get_post_types( array( 'has_archive' => true ), 'objects' ) as $pt ) {
			$link = get_post_type_archive_link( $pt->name );
			if ( $link && untrailingslashit( $link ) === untrailingslashit( home_url( '/' . $path ) ) ) {
				/* translators: %s: post type label */
				$out[] = sprintf( __( 'The %s archive has this address.', 'woocommerce-products-filter' ), $pt->labels->name );
			}
		}

		return array_values( array_unique( $out ) );
	}

	public static function ajax_check_path() {

		check_ajax_referer( 'woof_mcp_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}

		$path = HUSKY_MCP::sanitize_path( isset( $_POST['path'] ) ? wp_unslash( $_POST['path'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		wp_send_json_success(
			array(
				'path'      => $path,
				'url'       => '' === $path ? '' : home_url( '/' . $path ),
				'conflicts' => self::conflicts( $path ),
			)
		);
	}

	// ~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~
	// the tab

	public static function render_nav() {
		?>
		<li>
			<a href="#tabs-mcp">
				<span class="icon-connected-object"></span>
				<span><?php esc_html_e( 'MCP Server', 'woocommerce-products-filter' ); ?></span>
			</a>
		</li>
		<?php
	}

	public static function render_tab() {

		require_once __DIR__ . '/tools.php';
		require_once __DIR__ . '/server.php';
		require_once __DIR__ . '/discovery.php';
		require_once __DIR__ . '/widget.php';
		require_once __DIR__ . '/rate_limit.php';

		wp_enqueue_script( 'woof-mcp-admin', WOOF_LINK . 'js/mcp_admin.js', array( 'jquery' ), WOOF_VERSION, true );
		wp_localize_script(
			'woof-mcp-admin',
			'woof_mcp_admin',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'woof_mcp_admin' ),
				'home'  => trailingslashit( home_url() ),
				'rest'  => HUSKY_MCP::default_url(),
				'copied' => __( 'Copied', 'woocommerce-products-filter' ),
				'free'  => __( 'Free: nothing else on the site uses this address.', 'woocommerce-products-filter' ),
				'used'  => __( 'Conflict: this address already belongs to something else, which will become unreachable there.', 'woocommerce-products-filter' ),
			)
		);

		$data = array(
			'mcp'          => HUSKY_MCP::settings(),
			'defaults'     => HUSKY_MCP::defaults(),
			'default_url'  => HUSKY_MCP::default_url(),
			'public_url'   => HUSKY_MCP::public_url(),
			'card_url'     => HUSKY_MCP::card_url(),
			'at_root'      => HUSKY_MCP_DISCOVERY::at_domain_root(),
			'llms'         => HUSKY_MCP_DISCOVERY::llms_provider(),
			'llms_file'    => HUSKY_MCP_DISCOVERY::llms_file_path(),
			'llms_section' => HUSKY_MCP_DISCOVERY::llms_section(),
			'conflicts'    => self::conflicts( HUSKY_MCP::pretty_path() ),
			'rl_backend'   => HUSKY_MCP_RATE_LIMIT::backend(),
			'permalinks'   => (string) get_option( 'permalink_structure' ),
		);

		woof()->render_html_e( WOOF_PATH . 'views/mcp/admin_tab.php', $data );
	}
}
