<?php
/**
 * Plugin activation handler.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activator class.
 *
 * Handles plugin activation tasks.
 */
final class Activator {

	/**
	 * Run activation tasks.
	 *
	 * This method is called when the plugin is activated.
	 *
	 * @return void
	 */
	public static function activate(): void {
		// Store activation timestamp.
		if ( ! get_option( 'bricks_mcp_activated_at' ) ) {
			update_option( 'bricks_mcp_activated_at', time() );
		}

		// Store plugin version.
		update_option( 'bricks_mcp_version', BRICKS_MCP_VERSION );

		// Set default options if they don't exist.
		self::set_default_options();

		// Run lightweight activation checks and store results for admin notice.
		self::run_activation_checks();

		// Flush rewrite rules for REST API endpoints.
		flush_rewrite_rules();
	}

	/**
	 * Run lightweight activation checks (no HTTP requests).
	 *
	 * Checks 3 conditions via pure PHP function calls and stores results
	 * as a transient for display as an admin notice on the settings page.
	 *
	 * @return void
	 */
	private static function run_activation_checks(): void {
		$results = [];

		// Check 1: REST API not disabled.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$rest_enabled = apply_filters( 'rest_enabled', true ); // deprecated but still used by some plugins.
		if ( ! $rest_enabled ) {
			$results[] = [
				'id'      => 'rest_enabled',
				'label'   => __( 'REST API', 'bricks-mcp' ),
				'status'  => 'fail',
				'message' => __( 'The REST API is disabled. Bricks MCP requires the WordPress REST API.', 'bricks-mcp' ),
			];
		} else {
			$results[] = [
				'id'      => 'rest_enabled',
				'label'   => __( 'REST API', 'bricks-mcp' ),
				'status'  => 'pass',
				'message' => __( 'REST API is enabled.', 'bricks-mcp' ),
			];
		}

		// Check 2: Application Passwords available.
		if ( function_exists( 'wp_is_application_passwords_available' ) ) {
			$app_pw_available = wp_is_application_passwords_available();
			$results[]        = [
				'id'      => 'app_passwords',
				'label'   => __( 'Application Passwords', 'bricks-mcp' ),
				'status'  => $app_pw_available ? 'pass' : 'fail',
				'message' => $app_pw_available
					? __( 'Application Passwords are available.', 'bricks-mcp' )
					: __( 'Application Passwords are disabled. MCP clients require Application Passwords for authentication.', 'bricks-mcp' ),
			];
		}

		// Check 3: Bricks Builder active.
		$bricks_active = class_exists( '\Bricks\Elements' );
		$results[]     = [
			'id'      => 'bricks_active',
			'label'   => __( 'Bricks Builder', 'bricks-mcp' ),
			'status'  => $bricks_active ? 'pass' : 'fail',
			'message' => $bricks_active
				? __( 'Bricks Builder is active.', 'bricks-mcp' )
				: __( 'Bricks Builder is not active. Bricks-specific MCP tools will be unavailable.', 'bricks-mcp' ),
		];

		// Store results as transient for admin notice display only if issues exist.
		$has_issues = false;
		foreach ( $results as $r ) {
			if ( 'fail' === $r['status'] ) {
				$has_issues = true;
				break;
			}
		}

		if ( $has_issues ) {
			set_transient( 'bricks_mcp_activation_checks', $results, 3600 ); // 1 hour TTL.
		}
	}

	/**
	 * Set default plugin options.
	 *
	 * @return void
	 */
	private static function set_default_options(): void {
		$defaults = [
			'enabled'      => true,
			'require_auth' => true,
		];

		$existing = get_option( 'bricks_mcp_settings', [] );

		if ( ! is_array( $existing ) ) {
			$existing = [];
		}

		// Merge defaults with existing settings.
		$settings = array_merge( $defaults, $existing );

		update_option( 'bricks_mcp_settings', $settings );
	}
}
