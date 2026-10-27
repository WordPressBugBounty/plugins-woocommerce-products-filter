<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

/**
 * "Find products with your AI assistant": the [woof_mcp_connect] shortcode
 * and the HUSKY MCP block, for any page of the shop.
 *
 * Both are registered whether the server is on or off - a shortcode that
 * stops existing would print its own [tag] into the page - and both render
 * nothing while it is off.
 */
final class HUSKY_MCP_WIDGET {

	const SHORTCODE = 'woof_mcp_connect';
	const BLOCK     = 'husky/mcp-connect';

	public static function init() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'shortcode' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
	}

	public static function default_title() {
		return __( 'Find products with your AI assistant', 'woocommerce-products-filter' );
	}

	public static function default_text() {
		return __( 'Ask Claude, ChatGPT or any AI assistant to find products in our shop for you. Add this address to it as a custom connector (remote MCP server), then just say what you are looking for. No account or key is needed; you buy on our site as usual.', 'woocommerce-products-filter' );
	}

	public static function register_block() {

		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		wp_register_script(
			'husky-mcp-block',
			WOOF_LINK . 'js/mcp_block.js',
			array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ),
			WOOF_VERSION,
			true
		);

		register_block_type(
			self::BLOCK,
			array(
				'api_version'     => 3,
				'title'           => __( 'AI assistant connection (HUSKY MCP)', 'woocommerce-products-filter' ),
				'category'        => 'widgets',
				'icon'            => 'search',
				'editor_script'   => 'husky-mcp-block',
				'render_callback' => array( __CLASS__, 'render_block' ),
				'attributes'      => array(
					'title' => array(
						'type'    => 'string',
						'default' => '',
					),
					'text'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);
	}

	public static function render_block( $attributes ) {
		return self::shortcode(
			array(
				'title' => isset( $attributes['title'] ) ? $attributes['title'] : '',
				'text'  => isset( $attributes['text'] ) ? $attributes['text'] : '',
			)
		);
	}

	/**
	 * @param array $atts title, text - override the texts from the settings
	 */
	public static function shortcode( $atts = array() ) {

		if ( ! HUSKY_MCP::enabled() ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'title' => '',
				'text'  => '',
			),
			$atts,
			self::SHORTCODE
		);

		$title = '' !== trim( $atts['title'] ) ? $atts['title'] : trim( (string) HUSKY_MCP::get( 'widget_title' ) );
		$text  = '' !== trim( $atts['text'] ) ? $atts['text'] : trim( (string) HUSKY_MCP::get( 'widget_text' ) );
		$title = '' !== $title ? WOOF_HELPER::wpml_translate( null, $title ) : self::default_title();
		$text  = '' !== $text ? WOOF_HELPER::wpml_translate( null, $text ) : self::default_text();
		$url   = HUSKY_MCP::public_url();
		$id    = 'woof-mcp-' . wp_rand( 1000, 999999 );

		ob_start();
		?>
		<div class="woof_mcp_connect" style="border:1px solid #d7ddf0;border-radius:6px;padding:16px 18px;margin:16px 0;background:#f8f9fd;">
			<div style="font-weight:700;font-size:1.05em;margin-bottom:6px;"><?php echo esc_html( $title ); ?></div>
			<div style="margin-bottom:10px;"><?php echo wp_kses_post( wpautop( $text ) ); ?></div>
			<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
				<input id="<?php echo esc_attr( $id ); ?>" type="text" readonly value="<?php echo esc_attr( $url ); ?>" style="flex:1 1 260px;min-width:0;font-family:Menlo,Consolas,monospace;font-size:13px;padding:6px 8px;" onclick="this.select();" aria-label="<?php esc_attr_e( 'MCP server address', 'woocommerce-products-filter' ); ?>" />
				<button type="button" class="button woof_mcp_copy" data-target="<?php echo esc_attr( $id ); ?>" data-done="<?php esc_attr_e( 'Copied', 'woocommerce-products-filter' ); ?>"><?php esc_html_e( 'Copy', 'woocommerce-products-filter' ); ?></button>
			</div>
			<ol style="margin:10px 0 0 18px;padding:0;font-size:.92em;">
				<li><?php esc_html_e( 'Claude: Customize > Connectors > Add custom connector, paste the address, choose no sign-in.', 'woocommerce-products-filter' ); ?></li>
				<li><?php esc_html_e( 'ChatGPT: Plugins > + > Add custom MCP server, paste the address, choose no authentication.', 'woocommerce-products-filter' ); ?></li>
				<li><?php esc_html_e( 'Other assistants: add it as a remote MCP server (Streamable HTTP).', 'woocommerce-products-filter' ); ?></li>
			</ol>
		</div>
		<script>
			( function () {
				var b = document.querySelector( '.woof_mcp_copy[data-target="<?php echo esc_js( $id ); ?>"]' );
				if ( ! b ) {
					return;
				}
				b.addEventListener( 'click', function () {
					var f = document.getElementById( b.getAttribute( 'data-target' ) ), t = b.textContent;
					f.select();
					( navigator.clipboard ? navigator.clipboard.writeText( f.value ) : Promise.reject() ).catch( function () {
						document.execCommand( 'copy' );
					} ).then( function () {
						b.textContent = b.getAttribute( 'data-done' );
						setTimeout( function () { b.textContent = t; }, 1500 );
					} );
				} );
			} )();
		</script>
		<?php
		return ob_get_clean();
	}
}
