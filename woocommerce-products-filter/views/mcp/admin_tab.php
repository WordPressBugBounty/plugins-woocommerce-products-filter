<?php
if ( ! defined( 'ABSPATH' ) ) {
	die( 'No direct access allowed' );
}

// Variables from HUSKY_MCP_ADMIN::render_tab(): $mcp, $defaults, $default_url, $public_url,
// $card_url, $at_root, $llms, $llms_file, $llms_section, $conflicts, $rl_backend, $permalinks

$on       = ! empty( $mcp['enabled'] );
$name_ph  = HUSKY_MCP_TOOLS::server_name();
$server   = sanitize_title( HUSKY_MCP_TOOLS::server_name() );
$json_cc  = wp_json_encode( array( 'mcpServers' => array( $server => array( 'type' => 'http', 'url' => $public_url ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$json_cur = wp_json_encode( array( 'mcpServers' => array( $server => array( 'url' => $public_url ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$json_vsc = wp_json_encode( array( 'servers' => array( $server => array( 'type' => 'http', 'url' => $public_url ) ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
$yes_no   = array(
	0 => esc_html__( 'No', 'woocommerce-products-filter' ),
	1 => esc_html__( 'Yes', 'woocommerce-products-filter' ),
);
?>
<section id="tabs-mcp">

	<div class="woof-section-title">
		<div class="col-title">
			<h4><?php esc_html_e( 'MCP Server', 'woocommerce-products-filter' ); ?></h4>
		</div>
		<div class="col-button">
			<a href="https://products-filter.com/mcp-server" target="_blank" class="button-primary"><span class="icon-info"></span></a>
		</div>
	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Enable MCP server', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<select name="woof_settings[mcp][enabled]" id="woof_mcp_enabled">
					<?php foreach ( $yes_no as $k => $v ) : ?>
						<option value="<?php echo intval( $k ); ?>" <?php selected( (int) $on, $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'A public, read-only MCP server (Model Context Protocol): AI assistants such as Claude, ChatGPT or Cursor can filter your products for their users exactly as your filter form does, and give them links to the product pages. Buying happens only on your site. No keys and no accounts. When this is off, the server, its addresses, the discovery files, the head link, the llms.txt section and the shortcode output do not exist.', 'woocommerce-products-filter' ); ?></p>
			</div>
		</div>

	</div>

	<div class="woof-mcp-on" <?php echo $on ? '' : 'style="display:none;"'; ?>>

		<div class="woof-control-section">

			<h5><?php esc_html_e( 'Your MCP server address', 'woocommerce-products-filter' ); ?></h5>

			<div class="woof-control-container">
				<div class="woof-control">
					<div class="woof-mcp-urlrow">
						<input type="text" readonly class="woof-mcp-url" id="woof_mcp_public_url" value="<?php echo esc_attr( $public_url ); ?>" />
						<button type="button" class="button woof-mcp-copy" data-target="woof_mcp_public_url"><?php esc_html_e( 'Copy', 'woocommerce-products-filter' ); ?></button>
					</div>
					<p class="woof-mcp-small"><?php esc_html_e( 'Default address, always working:', 'woocommerce-products-filter' ); ?></p>
					<div class="woof-mcp-urlrow">
						<input type="text" readonly class="woof-mcp-url" id="woof_mcp_default_url" value="<?php echo esc_attr( $default_url ); ?>" />
						<button type="button" class="button woof-mcp-copy" data-target="woof_mcp_default_url"><?php esc_html_e( 'Copy', 'woocommerce-products-filter' ); ?></button>
					</div>
				</div>
				<div class="woof-description">
					<p class="description"><?php esc_html_e( 'Give this address to an AI assistant as a remote MCP server (transport: Streamable HTTP, authentication: none). The server card, which describes the server to agents and directories:', 'woocommerce-products-filter' ); ?> <a href="<?php echo esc_url( $card_url ); ?>" target="_blank" id="woof_mcp_card_link"><?php echo esc_html( $card_url ); ?></a></p>
					<p class="description"><?php esc_html_e( 'Save the settings after switching the server on: until then the address does not answer.', 'woocommerce-products-filter' ); ?></p>
				</div>
			</div>

		</div>

		<div class="woof-control-section">

			<h5><?php esc_html_e( 'How to connect', 'woocommerce-products-filter' ); ?></h5>

			<div class="woof-mcp-howto">

				<div class="woof-mcp-card">
					<b><?php esc_html_e( 'Claude (claude.ai, Claude Desktop)', 'woocommerce-products-filter' ); ?></b>
					<ol>
						<li><?php esc_html_e( 'Open Customize > Connectors (claude.ai/customize/connectors).', 'woocommerce-products-filter' ); ?></li>
						<li><?php esc_html_e( 'Click "Add custom connector" and paste the address above.', 'woocommerce-products-filter' ); ?></li>
						<li><?php esc_html_e( 'Leave the OAuth fields empty (no sign-in) and click Add.', 'woocommerce-products-filter' ); ?></li>
					</ol>
					<p class="woof-mcp-small"><?php esc_html_e( 'Available on Free, Pro, Max, Team and Enterprise plans (Free: one custom connector). On Team and Enterprise an owner adds it first in Organization settings > Connectors > Add > Custom.', 'woocommerce-products-filter' ); ?></p>
				</div>

				<div class="woof-mcp-card">
					<b><?php esc_html_e( 'ChatGPT', 'woocommerce-products-filter' ); ?></b>
					<ol>
						<li><?php esc_html_e( 'Open chatgpt.com/plugins, press + and choose "Add custom MCP server".', 'woocommerce-products-filter' ); ?></li>
						<li><?php esc_html_e( 'Enter a name, paste the address as Server URL, choose "No authentication".', 'woocommerce-products-filter' ); ?></li>
						<li><?php esc_html_e( 'Confirm, choose "Create as a plugin", then in a chat type @ and pick it.', 'woocommerce-products-filter' ); ?></li>
					</ol>
					<p class="woof-mcp-small"><?php esc_html_e( 'Custom MCP servers are available on ChatGPT web; which plans and workspace roles may add them is decided by OpenAI and the workspace admin.', 'woocommerce-products-filter' ); ?></p>
				</div>

				<div class="woof-mcp-card">
					<b><?php esc_html_e( 'Claude Code', 'woocommerce-products-filter' ); ?></b>
					<pre class="woof-mcp-code" id="woof_mcp_cc_cmd">claude mcp add --transport http <?php echo esc_html( $server ); ?> <?php echo esc_html( $public_url ); ?></pre>
					<button type="button" class="button woof-mcp-copy" data-target="woof_mcp_cc_cmd"><?php esc_html_e( 'Copy', 'woocommerce-products-filter' ); ?></button>
					<p class="woof-mcp-small"><?php esc_html_e( 'Or in .mcp.json of a project:', 'woocommerce-products-filter' ); ?></p>
					<pre class="woof-mcp-code" id="woof_mcp_cc_json"><?php echo esc_html( $json_cc ); ?></pre>
				</div>

				<div class="woof-mcp-card">
					<b><?php esc_html_e( 'Cursor', 'woocommerce-products-filter' ); ?></b>
					<p class="woof-mcp-small"><?php esc_html_e( '.cursor/mcp.json (project) or ~/.cursor/mcp.json (all projects):', 'woocommerce-products-filter' ); ?></p>
					<pre class="woof-mcp-code" id="woof_mcp_cursor_json"><?php echo esc_html( $json_cur ); ?></pre>
					<button type="button" class="button woof-mcp-copy" data-target="woof_mcp_cursor_json"><?php esc_html_e( 'Copy', 'woocommerce-products-filter' ); ?></button>
				</div>

				<div class="woof-mcp-card">
					<b><?php esc_html_e( 'VS Code (GitHub Copilot)', 'woocommerce-products-filter' ); ?></b>
					<p class="woof-mcp-small"><?php esc_html_e( '.vscode/mcp.json, or the command "MCP: Add Server" > HTTP:', 'woocommerce-products-filter' ); ?></p>
					<pre class="woof-mcp-code" id="woof_mcp_vscode_json"><?php echo esc_html( $json_vsc ); ?></pre>
					<button type="button" class="button woof-mcp-copy" data-target="woof_mcp_vscode_json"><?php esc_html_e( 'Copy', 'woocommerce-products-filter' ); ?></button>
				</div>

				<div class="woof-mcp-card">
					<b><?php esc_html_e( 'Any other MCP client', 'woocommerce-products-filter' ); ?></b>
					<p class="woof-mcp-small"><?php esc_html_e( 'Remote server, transport Streamable HTTP, no authentication. Protocol versions 2026-07-28, 2025-11-25 and 2025-06-18. To try it from a terminal:', 'woocommerce-products-filter' ); ?></p>
					<pre class="woof-mcp-code" id="woof_mcp_inspector">npx @modelcontextprotocol/inspector --cli <?php echo esc_html( $public_url ); ?> --transport http --method tools/list</pre>
				</div>

			</div>

		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Your own address (pretty URL)', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<div class="woof-mcp-urlrow">
					<span class="woof-mcp-prefix"><?php echo esc_html( trailingslashit( home_url() ) ); ?></span>
					<input type="text" name="woof_settings[mcp][pretty_path]" id="woof_mcp_pretty_path" value="<?php echo esc_attr( $mcp['pretty_path'] ); ?>" placeholder="ai-shop" />
				</div>
				<p class="woof-mcp-small"><?php esc_html_e( 'Resulting address:', 'woocommerce-products-filter' ); ?> <code id="woof_mcp_pretty_full"><?php echo esc_html( '' !== $mcp['pretty_path'] ? home_url( '/' . $mcp['pretty_path'] ) : '-' ); ?></code></p>
				<p class="woof-mcp-small woof-mcp-conflicts" id="woof_mcp_conflicts" <?php echo empty( $conflicts ) ? 'style="display:none;"' : ''; ?>>
					<?php
					foreach ( $conflicts as $c ) {
						echo '<span class="woof-mcp-warn">' . esc_html( $c ) . '</span><br />';
					}
					?>
				</p>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'Optional. Any address on your domain, for example ai-shop or mcp, instead of the long default one. The default address keeps working; the server card, llms.txt, the head link, the shortcode and the blocks above all switch to this one. It works with any permalink structure, plain included, as long as the web server sends unknown addresses to WordPress (the standard .htaccess or nginx try_files do).', 'woocommerce-products-filter' ); ?></p>
				<?php if ( '' === $permalinks ) : ?>
					<p class="description woof-mcp-warn"><?php esc_html_e( 'Plain permalinks are in use: the default address is index.php?rest_route=/husky/v1/mcp, and the pretty address needs the web server to send it to index.php.', 'woocommerce-products-filter' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Name, title and description shown to agents', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<input type="text" name="woof_settings[mcp][server_name]" value="<?php echo esc_attr( $mcp['server_name'] ); ?>" placeholder="<?php echo esc_attr( $name_ph ); ?>" /><br /><br />
				<input type="text" name="woof_settings[mcp][server_title]" value="<?php echo esc_attr( $mcp['server_title'] ); ?>" placeholder="<?php echo esc_attr( HUSKY_MCP_TOOLS::server_title() ); ?>" /><br /><br />
				<textarea name="woof_settings[mcp][server_description]" rows="3" placeholder="<?php echo esc_attr( HUSKY_MCP_TOOLS::default_description() ); ?>"><?php echo esc_textarea( $mcp['server_description'] ); ?></textarea>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'Name (short, no spaces), title and a one or two sentence description. Empty fields use the text shown in grey. The server card keeps the first 100 characters of title and description.', 'woocommerce-products-filter' ); ?></p>
			</div>
		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Instructions for agents', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<textarea name="woof_settings[mcp][instructions]" id="woof_mcp_instructions" rows="14" data-default="<?php echo esc_attr( HUSKY_MCP_TOOLS::default_instructions() ); ?>"><?php echo esc_textarea( '' !== trim( (string) $mcp['instructions'] ) ? $mcp['instructions'] : HUSKY_MCP_TOOLS::default_instructions() ); ?></textarea><br />
				<button type="button" class="button" id="woof_mcp_reset_instructions"><?php esc_html_e( 'Reset to default', 'woocommerce-products-filter' ); ?></button>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'Every agent receives this text when it connects: how to search, how to present products, that buying happens on the site. Write it in English for best results; agents answer the shopper in his own language anyway.', 'woocommerce-products-filter' ); ?></p>
			</div>
		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Rate limit', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<select name="woof_settings[mcp][rl_enabled]">
					<option value="1" <?php selected( (int) $mcp['rl_enabled'], 1 ); ?>><?php esc_html_e( 'On', 'woocommerce-products-filter' ); ?></option>
					<option value="0" <?php selected( (int) $mcp['rl_enabled'], 0 ); ?>><?php esc_html_e( 'Off', 'woocommerce-products-filter' ); ?></option>
				</select>
				<table class="woof-mcp-rl">
					<tr>
						<td><?php esc_html_e( 'Per IP address: requests per minute', 'woocommerce-products-filter' ); ?></td>
						<td><input type="number" min="0" name="woof_settings[mcp][rl_ip_rpm]" value="<?php echo intval( $mcp['rl_ip_rpm'] ); ?>" /></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Per IP address: burst (calls in a row)', 'woocommerce-products-filter' ); ?></td>
						<td><input type="number" min="0" name="woof_settings[mcp][rl_ip_burst]" value="<?php echo intval( $mcp['rl_ip_burst'] ); ?>" /></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'AI platforms (each listed range): requests per minute', 'woocommerce-products-filter' ); ?></td>
						<td><input type="number" min="0" name="woof_settings[mcp][rl_ai_rpm]" value="<?php echo intval( $mcp['rl_ai_rpm'] ); ?>" /></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'AI platforms (each listed range): burst', 'woocommerce-products-filter' ); ?></td>
						<td><input type="number" min="0" name="woof_settings[mcp][rl_ai_burst]" value="<?php echo intval( $mcp['rl_ai_burst'] ); ?>" /></td>
					</tr>
				</table>
				<p class="woof-mcp-small"><?php esc_html_e( 'AI platform address ranges (one CIDR per line, # starts a comment):', 'woocommerce-products-filter' ); ?></p>
				<textarea name="woof_settings[mcp][rl_ai_ranges]" id="woof_mcp_ai_ranges" rows="8" class="woof-mcp-code" data-default="<?php echo esc_attr( HUSKY_MCP_RATE_LIMIT::default_ai_ranges() ); ?>"><?php echo esc_textarea( '' !== trim( (string) $mcp['rl_ai_ranges'] ) ? $mcp['rl_ai_ranges'] : HUSKY_MCP_RATE_LIMIT::default_ai_ranges() ); ?></textarea><br />
				<button type="button" class="button" id="woof_mcp_reset_ranges"><?php esc_html_e( 'Reset to default', 'woocommerce-products-filter' ); ?></button>
				<p class="woof-mcp-small"><?php esc_html_e( 'Cache identical searches for (seconds, 0 = off):', 'woocommerce-products-filter' ); ?>
					<input type="number" min="0" max="3600" name="woof_settings[mcp][cache_ttl]" value="<?php echo intval( $mcp['cache_ttl'] ); ?>" style="width:90px;" />
				</p>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'An ordinary address gets a strict limit. Shoppers using claude.ai or ChatGPT reach your shop from the assistant\'s own servers, so all of them share a few addresses: the address ranges those platforms publish for their MCP calls get a separate, much higher ceiling, shared by all addresses of one listed range. The default list holds only ranges from the vendors\' own documentation (sources in the comments): Anthropic (Claude) and OpenAI (ChatGPT connectors). OpenAI updates its list from time to time: Reset to default loads the list of this HUSKY version, and you can add ranges a vendor publishes later. Over the limit an agent gets HTTP 429 with the number of seconds to wait. 0 switches a single limit off.', 'woocommerce-products-filter' ); ?></p>
				<p class="description"><?php esc_html_e( 'Repeated identical searches (same filters, page, sort, language and currency) are answered from a cache for the time set; any change to products, terms or the filter settings clears it.', 'woocommerce-products-filter' ); ?></p>
				<p class="description">
					<?php
					if ( 'object-cache' === $rl_backend ) {
						esc_html_e( 'Counters are kept in the persistent object cache.', 'woocommerce-products-filter' );
					} elseif ( 'apcu' === $rl_backend ) {
						esc_html_e( 'Counters are kept in APCu memory.', 'woocommerce-products-filter' );
					} else {
						esc_html_e( 'Counters are kept in the database (transients): one small write per request. A persistent object cache (Redis, Memcached) or APCu removes that.', 'woocommerce-products-filter' );
					}
					?>
				</p>
			</div>
		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Products per page at most', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<input type="number" min="1" max="100" name="woof_settings[mcp][max_per_page]" value="<?php echo intval( $mcp['max_per_page'] ); ?>" />
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'The largest page an agent may ask for (1-100). Without per_page it gets the shop\'s own number of products per page.', 'woocommerce-products-filter' ); ?></p>
			</div>
		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Allowed browser origins', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<textarea name="woof_settings[mcp][allowed_origins]" rows="3" placeholder="https://example-agent.com"><?php echo esc_textarea( $mcp['allowed_origins'] ); ?></textarea>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'Empty: browser-based MCP clients from any website may use the server (it is public and read-only, and uses no cookies). To restrict that, list the allowed origins, one per line, *.example.com allowed. Assistants that call from their servers (claude.ai, ChatGPT) send no origin and are not affected.', 'woocommerce-products-filter' ); ?></p>
			</div>
		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Discovery', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<label><?php esc_html_e( 'llms.txt section', 'woocommerce-products-filter' ); ?></label><br />
				<select name="woof_settings[mcp][llms_txt]">
					<?php foreach ( $yes_no as $k => $v ) : ?>
						<option value="<?php echo intval( $k ); ?>" <?php selected( (int) $mcp['llms_txt'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select><br /><br />
				<label><?php esc_html_e( 'Link in the page head', 'woocommerce-products-filter' ); ?></label><br />
				<select name="woof_settings[mcp][head_link]">
					<?php foreach ( $yes_no as $k => $v ) : ?>
						<option value="<?php echo intval( $k ); ?>" <?php selected( (int) $mcp['head_link'], $k ); ?>><?php echo esc_html( $v ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'How agents find the server by themselves: a server card at <address>/server-card and /.well-known/ai-catalog.json linking to it (MCP Server Cards), a section in llms.txt, and <link rel="ai-catalog"> / <link rel="service-desc"> in the head of every page.', 'woocommerce-products-filter' ); ?></p>
				<?php if ( ! $at_root ) : ?>
					<p class="description woof-mcp-warn"><?php esc_html_e( 'WordPress is installed in a sub directory, so it cannot answer at /.well-known/ on your domain: /.well-known/ai-catalog.json will not be found there. The server card at <address>/server-card, llms.txt in the WordPress folder and the head link still work.', 'woocommerce-products-filter' ); ?></p>
				<?php endif; ?>
				<?php if ( 'file' === $llms ) : ?>
					<p class="description woof-mcp-warn">
						<?php
						/* translators: %s: file path */
						printf( esc_html__( 'Your site has a physical llms.txt file (%s). HUSKY never changes it; to tell agents about the MCP server, paste this section into it:', 'woocommerce-products-filter' ), esc_html( $llms_file ) );
						?>
					</p>
					<textarea readonly rows="8" id="woof_mcp_llms_snippet" class="woof-mcp-code"><?php echo esc_textarea( $llms_section ); ?></textarea>
					<button type="button" class="button woof-mcp-copy" data-target="woof_mcp_llms_snippet"><?php esc_html_e( 'Copy', 'woocommerce-products-filter' ); ?></button>
				<?php elseif ( 'rank-math' === $llms ) : ?>
					<p class="description"><?php esc_html_e( 'llms.txt is generated by Rank Math: the MCP section is added to it.', 'woocommerce-products-filter' ); ?></p>
				<?php elseif ( 'website-llms' === $llms ) : ?>
					<p class="description"><?php esc_html_e( 'llms.txt is generated by the Website LLMs.txt plugin: the MCP section is added to it.', 'woocommerce-products-filter' ); ?></p>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'No llms.txt exists on the site, so HUSKY serves one at /llms.txt while the server is on.', 'woocommerce-products-filter' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

	</div>

	<div class="woof-control-section">

		<h5><?php esc_html_e( 'Box for your shop pages', 'woocommerce-products-filter' ); ?></h5>

		<div class="woof-control-container">
			<div class="woof-control">
				<input type="text" name="woof_settings[mcp][widget_title]" value="<?php echo esc_attr( $mcp['widget_title'] ); ?>" placeholder="<?php echo esc_attr( HUSKY_MCP_WIDGET::default_title() ); ?>" /><br /><br />
				<textarea name="woof_settings[mcp][widget_text]" rows="4" placeholder="<?php echo esc_attr( HUSKY_MCP_WIDGET::default_text() ); ?>"><?php echo esc_textarea( $mcp['widget_text'] ); ?></textarea>
			</div>
			<div class="woof-description">
				<p class="description"><?php esc_html_e( 'Shortcode [woof_mcp_connect] or the block "AI assistant connection (HUSKY MCP)" shows shoppers the address with a copy button and how to add it to their assistant. Title and text here, or per use: [woof_mcp_connect title="..." text="..."]. Translations: the HUSKY taxonomy label translations field above applies to these texts too.', 'woocommerce-products-filter' ); ?></p>
			</div>
		</div>

	</div>

	<style>
		#tabs-mcp .woof-mcp-urlrow { display: flex; gap: 6px; align-items: center; flex-wrap: wrap; }
		#tabs-mcp .woof-mcp-url { flex: 1 1 340px; font-family: Menlo, Consolas, monospace; font-size: 12px; }
		#tabs-mcp .woof-mcp-prefix { font-family: Menlo, Consolas, monospace; font-size: 12px; color: #555; }
		#tabs-mcp #woof_mcp_pretty_path { flex: 1 1 160px; }
		#tabs-mcp .woof-mcp-small { font-size: 12px; color: #555; margin: 6px 0; }
		#tabs-mcp .woof-mcp-howto { display: grid; grid-template-columns: repeat( auto-fill, minmax( 300px, 1fr ) ); gap: 12px; }
		#tabs-mcp .woof-mcp-card { border: 1px solid #dcdcde; border-radius: 4px; padding: 10px 12px; background: #fff; }
		#tabs-mcp .woof-mcp-card ol { margin: 6px 0 6px 18px; }
		#tabs-mcp .woof-mcp-code { background: #f6f7f7; border: 1px solid #e2e4e7; padding: 6px 8px; font-family: Menlo, Consolas, monospace; font-size: 12px; white-space: pre-wrap; word-break: break-all; width: 100%; box-sizing: border-box; }
		#tabs-mcp .woof-mcp-warn { color: #b32d2e; }
		#tabs-mcp .woof-mcp-ok { color: #007017; }
		#tabs-mcp table.woof-mcp-rl td { padding: 3px 8px 3px 0; }
		#tabs-mcp table.woof-mcp-rl input { width: 90px; }
		#tabs-mcp textarea#woof_mcp_instructions { width: 100%; font-size: 12px; }
	</style>

</section>
