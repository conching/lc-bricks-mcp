<?php
/**
 * LC Bricks MCP
 *
 * @package           LCBricksMCP
 * @author            Library Creative
 * @copyright         2025 BUFF UP MEDIA S.R.L. (original work); 2026 Library Creative (fork)
 * @license           GPL-2.0-or-later
 *
 * Forked from cristianuibar/bricks-mcp v1.5.1 (plus 3 private patches, now
 * native in 2.0.0). Original plugin "Bricks MCP" (c) 2025 BUFF UP MEDIA S.R.L.,
 * author Uibar Ion-Cristian. Distributed under GPL-2.0-or-later.
 *
 * @wordpress-plugin
 * Plugin Name:       LC Bricks MCP
 * Plugin URI:        https://github.com/conching/lc-bricks-mcp
 * Description:       AI-powered assistant for Bricks Builder. Control your website with natural language through MCP-compatible AI tools like Claude. Library Creative fork of Bricks MCP with correctness fixes (template elements, header/footer conditions, root placement, read-back verification).
 * Version:           2.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Library Creative
 * Author URI:        https://librarycreative.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lc-bricks-mcp
 * Domain Path:       /languages
 * Update URI:        https://github.com/conching/lc-bricks-mcp
 */

declare(strict_types=1);

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin version.
define( 'LC_BRICKS_MCP_VERSION', '2.1.2' );

// Minimum PHP version.
define( 'LC_BRICKS_MCP_MIN_PHP_VERSION', '8.2' );

// Minimum WordPress version.
define( 'LC_BRICKS_MCP_MIN_WP_VERSION', '6.4' );

// Minimum Bricks Builder version.
define( 'LC_BRICKS_MCP_MIN_BRICKS_VERSION', '1.6' );

// Plugin directory path.
define( 'LC_BRICKS_MCP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory URL.
define( 'LC_BRICKS_MCP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Plugin basename.
define( 'LC_BRICKS_MCP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );


/**
 * Check PHP version requirement.
 *
 * @return bool True if PHP version is sufficient.
 */
function lc_bricks_mcp_check_php_version(): bool {
	return version_compare( PHP_VERSION, LC_BRICKS_MCP_MIN_PHP_VERSION, '>=' );
}

/**
 * Check WordPress version requirement.
 *
 * @return bool True if WordPress version is sufficient.
 */
function lc_bricks_mcp_check_wp_version(): bool {
	global $wp_version;
	return version_compare( $wp_version, LC_BRICKS_MCP_MIN_WP_VERSION, '>=' );
}

/**
 * Display admin notice for PHP version requirement.
 *
 * @return void
 */
function lc_bricks_mcp_php_version_notice(): void {
	$message = sprintf(
		/* translators: 1: Required PHP version, 2: Current PHP version */
		esc_html__( 'LC Bricks MCP requires PHP %1$s or higher. You are running PHP %2$s. Please upgrade PHP to use this plugin.', 'lc-bricks-mcp' ),
		LC_BRICKS_MCP_MIN_PHP_VERSION,
		PHP_VERSION
	);
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Display admin notice for WordPress version requirement.
 *
 * @return void
 */
function lc_bricks_mcp_wp_version_notice(): void {
	global $wp_version;
	$message = sprintf(
		/* translators: 1: Required WordPress version, 2: Current WordPress version */
		esc_html__( 'LC Bricks MCP requires WordPress %1$s or higher. You are running WordPress %2$s. Please upgrade WordPress to use this plugin.', 'lc-bricks-mcp' ),
		LC_BRICKS_MCP_MIN_WP_VERSION,
		$wp_version
	);
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

/**
 * Check Bricks Builder version requirement.
 *
 * @return bool True if Bricks is installed and version is sufficient.
 */
function lc_bricks_mcp_check_bricks_version(): bool {
	if ( ! defined( 'BRICKS_VERSION' ) ) {
		return false;
	}
	if ( ! class_exists( '\Bricks\Elements' ) ) {
		return false;
	}
	return version_compare( BRICKS_VERSION, LC_BRICKS_MCP_MIN_BRICKS_VERSION, '>=' );
}

/**
 * Display admin notice for Bricks Builder requirement.
 *
 * @return void
 */
function lc_bricks_mcp_bricks_version_notice(): void {
	if ( ! defined( 'BRICKS_VERSION' ) ) {
		$message = sprintf(
			/* translators: %s: Required Bricks version */
			esc_html__( 'LC Bricks MCP requires Bricks Builder %s or higher. Bricks Builder is not installed or not activated.', 'lc-bricks-mcp' ),
			LC_BRICKS_MCP_MIN_BRICKS_VERSION
		);
	} else {
		$message = sprintf(
			/* translators: 1: Required Bricks version, 2: Current Bricks version */
			esc_html__( 'LC Bricks MCP requires Bricks Builder %1$s or higher. You are running Bricks %2$s. Please upgrade Bricks Builder to use this plugin.', 'lc-bricks-mcp' ),
			LC_BRICKS_MCP_MIN_BRICKS_VERSION,
			BRICKS_VERSION
		);
	}
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}

// Check requirements before loading the plugin.
if ( ! lc_bricks_mcp_check_php_version() ) {
	add_action( 'admin_notices', 'lc_bricks_mcp_php_version_notice' );
	return;
}

if ( ! lc_bricks_mcp_check_wp_version() ) {
	add_action( 'admin_notices', 'lc_bricks_mcp_wp_version_notice' );
	return;
}

// Load the autoloader.
require_once LC_BRICKS_MCP_PLUGIN_DIR . 'includes/Autoloader.php';

// Initialize autoloader.
LCBricksMCP\Autoloader::register();

// Load Composer autoloader if available (for Opis JSON Schema).
$lc_bricks_mcp_composer_autoload = LC_BRICKS_MCP_PLUGIN_DIR . 'vendor/autoload.php';
if ( file_exists( $lc_bricks_mcp_composer_autoload ) ) {
	require_once $lc_bricks_mcp_composer_autoload;
}

/**
 * Run plugin activation routine.
 *
 * @return void
 */
function lc_bricks_mcp_activate(): void {
	LCBricksMCP\Activator::activate();
}
register_activation_hook( __FILE__, 'lc_bricks_mcp_activate' );

/**
 * Run plugin deactivation routine.
 *
 * @return void
 */
function lc_bricks_mcp_deactivate(): void {
	LCBricksMCP\Deactivator::deactivate();
}
register_deactivation_hook( __FILE__, 'lc_bricks_mcp_deactivate' );

/**
 * Initialize the plugin after theme is loaded (Bricks version available).
 *
 * @return void
 */
function lc_bricks_mcp_init(): void {
	if ( ! lc_bricks_mcp_check_bricks_version() ) {
		add_action( 'admin_notices', 'lc_bricks_mcp_bricks_version_notice' );
		return;
	}
	LCBricksMCP\Plugin::get_instance();
}
add_action( 'after_setup_theme', 'lc_bricks_mcp_init' );
