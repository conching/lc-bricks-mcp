<?php
/**
 * Admin settings page.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings class.
 *
 * Handles the plugin settings page in WordPress admin.
 */
final class Settings {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 */
	private const PAGE_SLUG = 'lc-bricks-mcp';

	/**
	 * Settings option name.
	 *
	 * @var string
	 */
	private const OPTION_NAME = 'lc_bricks_mcp_settings';

	/**
	 * Settings option group.
	 *
	 * @var string
	 */
	private const OPTION_GROUP = 'lc_bricks_mcp_settings_group';

	/**
	 * Initialize admin settings.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ], 99 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'wp_ajax_lc_bricks_mcp_run_diagnostics', [ $this, 'ajax_run_diagnostics' ] );
		add_action( 'wp_ajax_lc_bricks_mcp_generate_app_password', [ $this, 'ajax_generate_app_password' ] );
	}

	/**
	 * Add settings page to admin menu.
	 *
	 * @return void
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'bricks',
			__( 'MCP Settings', 'lc-bricks-mcp' ),
			__( 'MCP', 'lc-bricks-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => $this->get_defaults(),
			]
		);

		// General settings section.
		add_settings_section(
			'lc_bricks_mcp_general',
			__( 'General Settings', 'lc-bricks-mcp' ),
			[ $this, 'render_general_section' ],
			self::PAGE_SLUG
		);

		// Enable/disable field.
		add_settings_field(
			'enabled',
			__( 'Enable MCP Server', 'lc-bricks-mcp' ),
			[ $this, 'render_enabled_field' ],
			self::PAGE_SLUG,
			'lc_bricks_mcp_general'
		);

		// Require authentication field.
		add_settings_field(
			'require_auth',
			__( 'Require Authentication', 'lc-bricks-mcp' ),
			[ $this, 'render_require_auth_field' ],
			self::PAGE_SLUG,
			'lc_bricks_mcp_general'
		);

		// Custom base URL field.
		add_settings_field(
			'custom_base_url',
			__( 'Custom Base URL', 'lc-bricks-mcp' ),
			[ $this, 'render_custom_base_url_field' ],
			self::PAGE_SLUG,
			'lc_bricks_mcp_general'
		);

		// Rate limit field.
		add_settings_field(
			'rate_limit_rpm',
			__( 'Rate Limit (requests/minute)', 'lc-bricks-mcp' ),
			[ $this, 'render_rate_limit_rpm_field' ],
			self::PAGE_SLUG,
			'lc_bricks_mcp_general'
		);

		// Dangerous actions field.
		add_settings_field(
			'dangerous_actions',
			__( 'Dangerous Actions', 'lc-bricks-mcp' ),
			[ $this, 'render_dangerous_actions_field' ],
			self::PAGE_SLUG,
			'lc_bricks_mcp_general'
		);
	}

	/**
	 * Get default settings.
	 *
	 * @return array<string, mixed> Default settings.
	 */
	private function get_defaults(): array {
		return [
			'enabled'           => true,
			'require_auth'      => true,
			'custom_base_url'   => '',
			'dangerous_actions' => false,
			'rate_limit_rpm'    => 120,
		];
	}

	/**
	 * Sanitize settings.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed> Sanitized settings.
	 */
	public function sanitize_settings( array $input ): array {
		$sanitized = [];

		$sanitized['enabled']         = ! empty( $input['enabled'] );
		$sanitized['require_auth']    = ! empty( $input['require_auth'] );
		$sanitized['custom_base_url'] = isset( $input['custom_base_url'] )
			? esc_url_raw( trim( $input['custom_base_url'] ) )
			: '';

		$sanitized['dangerous_actions'] = ! empty( $input['dangerous_actions'] );
		$sanitized['rate_limit_rpm']    = max( 10, min( 1000, (int) ( $input['rate_limit_rpm'] ?? 120 ) ) );

		return $sanitized;
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'lc-bricks-mcp' ) );
		}

		// Display activation notice if issues were detected on plugin activation.
		$activation_results = get_transient( 'lc_bricks_mcp_activation_checks' );
		if ( false !== $activation_results && is_array( $activation_results ) ) {
			delete_transient( 'lc_bricks_mcp_activation_checks' );
			$has_issues = false;
			foreach ( $activation_results as $check ) {
				if ( 'fail' === ( $check['status'] ?? '' ) ) {
					$has_issues = true;
					break;
				}
			}
			if ( $has_issues ) {
				echo '<div class="notice notice-warning is-dismissible"><p><strong>' . esc_html__( 'LC Bricks MCP: Some configuration issues were detected during activation.', 'lc-bricks-mcp' ) . '</strong> ';
				echo esc_html__( 'Click "Run Diagnostics" below for details and fix instructions.', 'lc-bricks-mcp' ) . '</p></div>';
			}
		}

		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<?php settings_errors(); ?>

			<div class="lc-bricks-mcp-info">
				<h3><?php esc_html_e( 'MCP Server Endpoints', 'lc-bricks-mcp' ); ?></h3>
				<p>
					<strong><?php esc_html_e( 'MCP Endpoint:', 'lc-bricks-mcp' ); ?></strong>
					<code><?php echo esc_html( rest_url( 'lc-bricks-mcp/v1/mcp' ) ); ?></code>
				</p>
				<p class="description">
					<?php esc_html_e( 'This single endpoint handles all MCP protocol communication via JSON-RPC 2.0.', 'lc-bricks-mcp' ); ?>
				</p>
			</div>

			<?php
			// Diagnostic panel (replaces Test Connection per D-07).
			$this->render_diagnostic_panel();

			// Version card.
			$this->render_version_card();

			// MCP configuration tabs.
			$this->render_mcp_config();
			?>

			<form action="options.php" method="post">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render general section description.
	 *
	 * @return void
	 */
	public function render_general_section(): void {
		echo '<p>' . esc_html__( 'Configure general MCP server settings.', 'lc-bricks-mcp' ) . '</p>';
	}

	/**
	 * Render enabled field.
	 *
	 * @return void
	 */
	public function render_enabled_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="lc-bricks-mcp-enabled">
			<input type="checkbox" id="lc-bricks-mcp-enabled" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>>
			<?php esc_html_e( 'Enable the MCP server endpoints', 'lc-bricks-mcp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When disabled, all MCP endpoints will return a 503 Service Unavailable response.', 'lc-bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render require authentication field.
	 *
	 * @return void
	 */
	public function render_require_auth_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="lc-bricks-mcp-require-auth">
			<input type="checkbox" id="lc-bricks-mcp-require-auth" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[require_auth]" value="1" <?php checked( ! empty( $settings['require_auth'] ) ); ?>>
			<?php esc_html_e( 'Require user authentication for MCP endpoints', 'lc-bricks-mcp' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'When enabled, only authenticated users with manage_options capability can access the MCP server.', 'lc-bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render custom base URL field.
	 *
	 * @return void
	 */
	public function render_custom_base_url_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value    = $settings['custom_base_url'] ?? '';
		?>
		<input type="url" id="lc-bricks-mcp-custom-base-url" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[custom_base_url]"
			value="<?php echo esc_attr( $value ); ?>" class="regular-text"
			placeholder="<?php echo esc_attr( get_site_url() ); ?>">
		<p class="description">
			<?php esc_html_e( 'Override the site URL used in MCP config snippets. Useful for reverse proxies or custom domains. Leave empty to use the default site URL.', 'lc-bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Render dangerous actions field.
	 *
	 * @return void
	 */
	public function render_dangerous_actions_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		?>
		<label for="lc-bricks-mcp-dangerous-actions">
			<input type="checkbox" id="lc-bricks-mcp-dangerous-actions" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[dangerous_actions]" value="1" <?php checked( ! empty( $settings['dangerous_actions'] ) ); ?>>
			<?php esc_html_e( 'Enable dangerous actions mode', 'lc-bricks-mcp' ); ?>
		</label>
		<div class="lc-bricks-mcp-danger-notice">
			<strong><?php esc_html_e( 'Warning: This enables unrestricted write access', 'lc-bricks-mcp' ); ?></strong>
			<p>
				<?php esc_html_e( 'When enabled, AI tools can: write to global Bricks settings, execute custom JavaScript on pages, and modify code execution settings. Only enable this on development sites or when running trusted AI agent teams. API keys and secrets remain masked regardless of this setting.', 'lc-bricks-mcp' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render rate limit RPM field.
	 *
	 * @return void
	 */
	public function render_rate_limit_rpm_field(): void {
		$settings = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value    = (int) ( $settings['rate_limit_rpm'] ?? 120 );
		?>
		<input type="number" id="lc-bricks-mcp-rate-limit-rpm" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[rate_limit_rpm]"
			value="<?php echo esc_attr( (string) $value ); ?>" min="10" max="1000" step="10" class="small-text">
		<span><?php esc_html_e( 'requests per minute per user', 'lc-bricks-mcp' ); ?></span>
		<p class="description">
			<?php esc_html_e( 'Maximum number of MCP requests allowed per authenticated user per minute. Default: 120. Increase to 300 for intensive AI building sessions. Applies only when authentication is required.', 'lc-bricks-mcp' ); ?>
		</p>
		<?php
	}

	/**
	 * Enqueue admin scripts on the settings page.
	 *
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_scripts( string $hook ): void {
		if ( 'bricks_page_lc-bricks-mcp' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lc-bricks-mcp-admin-settings',
			LC_BRICKS_MCP_PLUGIN_URL . 'assets/css/admin-settings.css',
			[],
			LC_BRICKS_MCP_VERSION
		);

		wp_enqueue_script(
			'lc-bricks-mcp-admin-updates',
			LC_BRICKS_MCP_PLUGIN_URL . 'assets/js/admin-updates.js',
			[],
			LC_BRICKS_MCP_VERSION,
			true
		);

		$current_user = wp_get_current_user();

		wp_localize_script(
			'lc-bricks-mcp-admin-updates',
			'bricksMcpUpdates',
			[
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'lc_bricks_mcp_settings_nonce' ),
				'currentVersion'  => LC_BRICKS_MCP_VERSION,
				'siteUrl'         => get_site_url(),
				'restBase'        => rest_url( 'lc-bricks-mcp/v1/' ),
				'mcpUrl'          => rest_url( 'lc-bricks-mcp/v1/mcp' ),
				'currentUsername' => $current_user->user_login,
				'profileUrl'      => admin_url( 'profile.php' ),
				'updateCoreUrl'   => admin_url( 'update-core.php' ),
			]
		);
	}

	/**
	 * Render the version info card.
	 *
	 * Shows current version, update availability, and a "Check Now" button.
	 *
	 * @return void
	 */
	private function render_version_card(): void {
		$current_version = LC_BRICKS_MCP_VERSION;
		$update_checker  = \LCBricksMCP\Plugin::get_instance()->get_update_checker();
		$update_data     = null !== $update_checker ? $update_checker->get_cached_update_data() : [];
		$has_update      = ! empty( $update_data['version'] )
			&& version_compare( $current_version, $update_data['version'], '<' );

		?>
		<div class="lc-bricks-mcp-version-card<?php echo $has_update ? ' lc-bricks-mcp-version-card--has-update' : ''; ?>">
			<h2><?php esc_html_e( 'Version', 'lc-bricks-mcp' ); ?></h2>
			<p id="lc-bricks-mcp-version-text">
				<strong>v<?php echo esc_html( $current_version ); ?></strong>
				<?php if ( $has_update ) : ?>
					&mdash;
					<span class="lc-bricks-mcp-update-available">
						v<?php echo esc_html( $update_data['version'] ); ?>
						<?php esc_html_e( 'available', 'lc-bricks-mcp' ); ?>
					</span>
					<a href="<?php echo esc_url( admin_url( 'update-core.php' ) ); ?>">
						<?php esc_html_e( 'Update', 'lc-bricks-mcp' ); ?>
					</a>
				<?php else : ?>
					&mdash; <span class="lc-bricks-mcp-up-to-date"><?php esc_html_e( 'up to date', 'lc-bricks-mcp' ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<button type="button" id="lc-bricks-mcp-check-update-btn" class="button">
					<?php esc_html_e( 'Check Now', 'lc-bricks-mcp' ); ?>
				</button>
				<span id="lc-bricks-mcp-check-update-spinner" class="spinner"></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render MCP configuration tabs with Claude Code and Gemini snippets.
	 *
	 * Includes copy-to-clipboard and brief instructions.
	 *
	 * @return void
	 */
	private function render_mcp_config(): void {
		$current_user = wp_get_current_user();
		$username     = $current_user->user_login;

		// Build MCP endpoint URL with optional custom base URL override.
		$settings    = get_option( self::OPTION_NAME, $this->get_defaults() );
		$custom_base = $settings['custom_base_url'] ?? '';
		if ( ! empty( $custom_base ) ) {
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/lc-bricks-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'lc-bricks-mcp/v1/mcp' );
		}

		// Build Claude Code config snippet.
		$claude_config = json_encode(
			[
				'mcpServers' => [
					'lc-bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Gemini config snippet.
		$gemini_config = json_encode(
			[
				'mcpServers' => [
					'lc-bricks-mcp' => [
						'httpUrl' => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic YOUR_BASE64_AUTH_STRING',
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		?>
		<div class="lc-bricks-mcp-config-section">
			<h2><?php esc_html_e( 'MCP Configuration', 'lc-bricks-mcp' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Add the following configuration to your AI tool to connect to this MCP server.', 'lc-bricks-mcp' ); ?></p>

			<!-- Generate Setup Command -->
			<div class="lc-bricks-mcp-quick-setup">
				<h3><?php esc_html_e( 'Quick Setup', 'lc-bricks-mcp' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Generate an Application Password and get a ready-to-paste setup command.', 'lc-bricks-mcp' ); ?></p>
				<p class="lc-bricks-mcp-generate-btn-wrap">
					<button type="button" id="lc-bricks-mcp-generate-btn" class="button button-primary">
						<?php esc_html_e( 'Generate Setup Command', 'lc-bricks-mcp' ); ?>
					</button>
					<span id="lc-bricks-mcp-generate-spinner" class="spinner"></span>
				</p>
				<div id="lc-bricks-mcp-generate-error" class="lc-bricks-mcp-generate-error" style="display:none;"></div>

				<div id="lc-bricks-mcp-generated-result" class="lc-bricks-mcp-generated-result" style="display:none;">
					<div class="lc-bricks-mcp-important-notice">
						<strong><?php esc_html_e( 'Important:', 'lc-bricks-mcp' ); ?></strong>
						<?php esc_html_e( 'This password is shown once. Copy your command now -- it cannot be retrieved later.', 'lc-bricks-mcp' ); ?>
					</div>

					<h4><?php esc_html_e( 'Claude Code (one-liner):', 'lc-bricks-mcp' ); ?></h4>
					<div class="lc-bricks-mcp-code-wrap lc-bricks-mcp-code-wrap--breakall">
						<pre><code id="lc-bricks-mcp-generated-command"></code></pre>
						<button type="button" class="button lc-bricks-mcp-copy-btn" data-target="lc-bricks-mcp-generated-command">
							<?php esc_html_e( 'Copy to Clipboard', 'lc-bricks-mcp' ); ?>
						</button>
					</div>

					<hr>

					<h4><?php esc_html_e( 'Claude Code (JSON config):', 'lc-bricks-mcp' ); ?></h4>
					<div class="lc-bricks-mcp-code-wrap">
						<pre><code id="lc-bricks-mcp-generated-claude-config"></code></pre>
						<button type="button" class="button lc-bricks-mcp-copy-btn" data-target="lc-bricks-mcp-generated-claude-config">
							<?php esc_html_e( 'Copy to Clipboard', 'lc-bricks-mcp' ); ?>
						</button>
					</div>

					<h4><?php esc_html_e( 'Gemini (JSON config):', 'lc-bricks-mcp' ); ?></h4>
					<div class="lc-bricks-mcp-code-wrap">
						<pre><code id="lc-bricks-mcp-generated-gemini-config"></code></pre>
						<button type="button" class="button lc-bricks-mcp-copy-btn" data-target="lc-bricks-mcp-generated-gemini-config">
							<?php esc_html_e( 'Copy to Clipboard', 'lc-bricks-mcp' ); ?>
						</button>
					</div>
				</div>
			</div>

			<h3><?php esc_html_e( 'Manual Setup', 'lc-bricks-mcp' ); ?></h3>

			<div class="lc-bricks-mcp-tabs lc-bricks-mcp-tabs-wrap">
				<div role="tablist">
					<button type="button" role="tab" id="lc-bricks-mcp-tab-claude" data-tab="claude" aria-selected="true" aria-controls="lc-bricks-mcp-panel-claude" tabindex="0" class="active">
						<?php esc_html_e( 'Claude Code', 'lc-bricks-mcp' ); ?>
					</button>
					<button type="button" role="tab" id="lc-bricks-mcp-tab-gemini" data-tab="gemini" aria-selected="false" aria-controls="lc-bricks-mcp-panel-gemini" tabindex="-1">
						<?php esc_html_e( 'Gemini', 'lc-bricks-mcp' ); ?>
					</button>
				</div>

				<!-- Claude Code Panel -->
				<div role="tabpanel" id="lc-bricks-mcp-panel-claude" aria-labelledby="lc-bricks-mcp-tab-claude" data-panel="claude">
					<div class="lc-bricks-mcp-code-wrap">
						<pre><code id="lc-bricks-mcp-claude-config"><?php echo esc_html( $claude_config ); ?></code></pre>
						<button type="button" class="button lc-bricks-mcp-copy-btn" data-target="lc-bricks-mcp-claude-config">
							<?php esc_html_e( 'Copy to Clipboard', 'lc-bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description lc-bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your .mcp.json file, or use:', 'lc-bricks-mcp' ); ?>
						<code>claude mcp add lc-bricks-mcp <?php echo esc_html( $mcp_url ); ?> --transport http --header "Authorization: Basic ..."</code>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>. Or use the <strong>Generate Setup Command</strong> button above for a ready-to-paste config.', 'lc-bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
				</div>

				<!-- Gemini Panel -->
				<div role="tabpanel" id="lc-bricks-mcp-panel-gemini" aria-labelledby="lc-bricks-mcp-tab-gemini" data-panel="gemini" style="display:none;">
					<div class="lc-bricks-mcp-code-wrap">
						<pre><code id="lc-bricks-mcp-gemini-config"><?php echo esc_html( $gemini_config ); ?></code></pre>
						<button type="button" class="button lc-bricks-mcp-copy-btn" data-target="lc-bricks-mcp-gemini-config">
							<?php esc_html_e( 'Copy to Clipboard', 'lc-bricks-mcp' ); ?>
						</button>
					</div>
					<p class="description lc-bricks-mcp-tab-description">
						<?php esc_html_e( 'Add this to your ~/.gemini/settings.json file.', 'lc-bricks-mcp' ); ?>
					</p>
					<p class="description">
						<?php
						echo wp_kses(
							__( 'Replace <code>YOUR_BASE64_AUTH_STRING</code> with the Base64-encoded value of <code>username:app_password</code>. Or use the <strong>Generate Setup Command</strong> button above for a ready-to-paste config.', 'lc-bricks-mcp' ),
							[
								'code'   => [],
								'strong' => [],
							]
						);
						?>
					</p>
				</div>
			</div>

			</div>
		<?php
	}

	/**
	 * AJAX handler: Run all diagnostic checks and return structured results.
	 *
	 * @return void
	 */
	public function ajax_run_diagnostics(): void {
		check_ajax_referer( 'lc_bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'lc-bricks-mcp' ) ], 403 );
		}

		$runner = new \LCBricksMCP\Admin\DiagnosticRunner();
		$runner->register_defaults();
		$results = $runner->run_all();

		wp_send_json_success( $results );
	}

	/**
	 * Render the System Status diagnostic panel.
	 *
	 * Replaces the old Test Connection panel per D-07. Provides a Run Diagnostics
	 * button that executes all checks via AJAX and renders a colored checklist.
	 * Also provides a Copy Results button for support ticket use.
	 *
	 * @return void
	 */
	private function render_diagnostic_panel(): void {
		?>
		<style>
			.lc-bricks-mcp-diagnostics { margin: 20px 0; background: #fff; border: 1px solid #c3c4c7; padding: 15px 20px; }
			.lc-bricks-mcp-diagnostics h3 { margin-top: 0; }
			.lc-bricks-mcp-diagnostics-actions { margin: 10px 0; display: flex; align-items: center; gap: 8px; }
			.lc-bricks-mcp-check { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f1; }
			.lc-bricks-mcp-check:last-child { border-bottom: none; }
			.lc-bricks-mcp-check .dashicons { margin-top: 2px; font-size: 20px; width: 20px; height: 20px; }
			.lc-bricks-mcp-check--pass .dashicons { color: #00a32a; }
			.lc-bricks-mcp-check--warn .dashicons { color: #dba617; }
			.lc-bricks-mcp-check--fail .dashicons { color: #d63638; }
			.lc-bricks-mcp-check--skipped .dashicons { color: #787c82; }
			.lc-bricks-mcp-check-content p { margin: 2px 0 0; }
			.lc-bricks-mcp-check-fixes { margin-top: 5px; padding: 8px 12px; background: #f6f7f7; border-radius: 3px; }
			.lc-bricks-mcp-check-fixes ul { margin: 5px 0 0 15px; }
			.lc-bricks-mcp-diagnostics-summary { font-size: 14px; margin: 10px 0; }
			#lc-bricks-mcp-diagnostics-spinner { float: none; margin: 0; }
		</style>

		<div class="lc-bricks-mcp-diagnostics" id="lc-bricks-mcp-diagnostics">
			<h3><?php esc_html_e( 'System Status', 'lc-bricks-mcp' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Run diagnostics to check if your site is properly configured for MCP connections.', 'lc-bricks-mcp' ); ?></p>
			<div class="lc-bricks-mcp-diagnostics-actions">
				<button type="button" class="button button-primary" id="lc-bricks-mcp-run-diagnostics">
					<?php esc_html_e( 'Run Diagnostics', 'lc-bricks-mcp' ); ?>
				</button>
				<button type="button" class="button" id="lc-bricks-mcp-copy-results" style="display:none;">
					<?php esc_html_e( 'Copy Results', 'lc-bricks-mcp' ); ?>
				</button>
				<span class="spinner" id="lc-bricks-mcp-diagnostics-spinner"></span>
			</div>
			<div id="lc-bricks-mcp-diagnostics-results"></div>
		</div>

		<script>
		(function() {
			var iconMap = {
				pass:    'dashicons-yes-alt',
				warn:    'dashicons-warning',
				fail:    'dashicons-dismiss',
				skipped: 'dashicons-minus'
			};

			var diagnosticsData = null;

			function escHtml(s) {
				var d = document.createElement('div');
				d.appendChild(document.createTextNode(s));
				return d.innerHTML;
			}

			document.getElementById('lc-bricks-mcp-run-diagnostics').addEventListener('click', function() {
				var btn      = this;
				var spinner  = document.getElementById('lc-bricks-mcp-diagnostics-spinner');
				var results  = document.getElementById('lc-bricks-mcp-diagnostics-results');
				var copyBtn  = document.getElementById('lc-bricks-mcp-copy-results');

				btn.disabled = true;
				spinner.classList.add('is-active');
				results.innerHTML = '';
				copyBtn.style.display = 'none';

				var data = new FormData();
				data.append('action', 'lc_bricks_mcp_run_diagnostics');
				data.append('nonce', bricksMcpUpdates.nonce);

				fetch(bricksMcpUpdates.ajaxUrl, { method: 'POST', body: data })
					.then(function(r) { return r.json(); })
					.then(function(response) {
						btn.disabled = false;
						spinner.classList.remove('is-active');

						if (!response.success) {
							results.innerHTML = '<p style="color:#d63638;">' + (response.data && response.data.message ? escHtml(response.data.message) : '<?php echo esc_js( __( 'An error occurred.', 'lc-bricks-mcp' ) ); ?>') + '</p>';
							return;
						}

						diagnosticsData = response.data;
						var html = '<p class="lc-bricks-mcp-diagnostics-summary"><strong>' + escHtml(response.data.summary) + '</strong></p>';

						response.data.checks.forEach(function(check) {
							var icon = iconMap[check.status] || 'dashicons-minus';
							var fixHtml = '';
							if (check.fix_steps && check.fix_steps.length > 0) {
								fixHtml = '<div class="lc-bricks-mcp-check-fixes"><strong><?php echo esc_js( __( 'How to fix:', 'lc-bricks-mcp' ) ); ?></strong><ul>';
								check.fix_steps.forEach(function(step) {
									fixHtml += '<li>' + escHtml(step) + '</li>';
								});
								fixHtml += '</ul></div>';
							}
							html += '<div class="lc-bricks-mcp-check lc-bricks-mcp-check--' + check.status + '">';
							html += '<span class="dashicons ' + icon + '"></span>';
							html += '<div class="lc-bricks-mcp-check-content">';
							html += '<strong>' + escHtml(check.label) + '</strong>';
							html += '<p>' + escHtml(check.message) + '</p>';
							html += fixHtml;
							html += '</div></div>';
						});

						results.innerHTML = html;
						copyBtn.style.display = 'inline-block';
					})
					.catch(function(err) {
						btn.disabled = false;
						spinner.classList.remove('is-active');
						results.innerHTML = '<p style="color:#d63638;"><?php echo esc_js( __( 'Request failed. Please try again.', 'lc-bricks-mcp' ) ); ?></p>';
					});
			});

			document.getElementById('lc-bricks-mcp-copy-results').addEventListener('click', function() {
				if (!diagnosticsData) return;
				var copyBtn = this;
				var text = '';
				diagnosticsData.checks.forEach(function(check) {
					text += '[' + check.status.toUpperCase() + '] ' + check.label + ': ' + check.message + '\n';
					if (check.fix_steps && check.fix_steps.length > 0) {
						check.fix_steps.forEach(function(step) {
							text += '  Fix: ' + step + '\n';
						});
					}
				});
				navigator.clipboard.writeText(text).then(function() {
					copyBtn.textContent = '<?php echo esc_js( __( 'Copied!', 'lc-bricks-mcp' ) ); ?>';
					setTimeout(function() {
						copyBtn.textContent = '<?php echo esc_js( __( 'Copy Results', 'lc-bricks-mcp' ) ); ?>';
					}, 2000);
				});
			});
		}());
		</script>
		<?php
	}

	/**
	 * AJAX handler: Generate an Application Password and return setup commands.
	 *
	 * Creates a WordPress Application Password for the current user and returns
	 * a complete claude mcp add command with auth headers, plus JSON configs
	 * for Claude Code and Gemini with real credentials.
	 *
	 * @return void
	 */
	public function ajax_generate_app_password(): void {
		check_ajax_referer( 'lc_bricks_mcp_settings_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized.', 'lc-bricks-mcp' ) ], 403 );
		}

		$current_user = wp_get_current_user();
		$username     = $current_user->user_login;

		// Create Application Password.
		$result = \WP_Application_Passwords::create_new_application_password(
			$current_user->ID,
			[
				'name'   => 'LC Bricks MCP - Claude Code',
				'app_id' => wp_generate_uuid4(),
			]
		);

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		// $result is [ $password, $item ] -- the raw password is only available at creation time.
		$password = $result[0];

		// Build MCP endpoint URL with optional custom base URL override.
		$settings    = get_option( self::OPTION_NAME, $this->get_defaults() );
		$custom_base = $settings['custom_base_url'] ?? '';
		if ( ! empty( $custom_base ) ) {
			$mcp_url = trailingslashit( $custom_base ) . 'wp-json/lc-bricks-mcp/v1/mcp';
		} else {
			$mcp_url = rest_url( 'lc-bricks-mcp/v1/mcp' );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$auth_string = base64_encode( $username . ':' . $password );

		// Build the complete CLI command.
		$claude_command = sprintf(
			'claude mcp add lc-bricks-mcp %s --transport http --header "Authorization: Basic %s"',
			$mcp_url,
			$auth_string
		);

		// Build Claude Code JSON config with real credentials.
		$claude_config = wp_json_encode(
			[
				'mcpServers' => [
					'lc-bricks-mcp' => [
						'type'    => 'http',
						'url'     => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		// Build Gemini JSON config with real credentials.
		$gemini_config = wp_json_encode(
			[
				'mcpServers' => [
					'lc-bricks-mcp' => [
						'httpUrl' => $mcp_url,
						'headers' => [
							'Authorization' => 'Basic ' . $auth_string,
						],
					],
				],
			],
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		wp_send_json_success(
			[
				'password'       => $password,
				'username'       => $username,
				'auth_string'    => $auth_string,
				'claude_command' => $claude_command,
				'claude_config'  => $claude_config,
				'gemini_config'  => $gemini_config,
				'mcp_url'        => $mcp_url,
			]
		);
	}
}
