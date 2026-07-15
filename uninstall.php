<?php
/**
 * Plugin uninstallation handler.
 *
 * This file is executed when the plugin is uninstalled via the WordPress admin.
 * It removes all plugin data from the database.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// Prevent direct access and ensure this is an uninstall request.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete all plugin options.
 *
 * @return void
 */
function bricks_mcp_delete_options(): void {
	$options = [
		'bricks_mcp_settings',
		'bricks_mcp_version',
		'bricks_mcp_activated_at',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}
}

/**
 * Delete all plugin transients.
 *
 * @return void
 */
function bricks_mcp_delete_transients(): void {
	global $wpdb;

	// Delete all transients with the plugin prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_bricks_mcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_bricks_mcp_' ) . '%'
		)
	);
}

/**
 * Delete all plugin user meta.
 *
 * @return void
 */
function bricks_mcp_delete_user_meta(): void {
	global $wpdb;

	// Delete all user meta with the plugin prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'bricks_mcp_' ) . '%'
		)
	);
}

/**
 * Clear any scheduled cron events.
 *
 * @return void
 */
function bricks_mcp_clear_cron(): void {
	$cron_hooks = [
		'bricks_mcp_cleanup',
	];

	foreach ( $cron_hooks as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}
}

// Run cleanup.
bricks_mcp_delete_options();
bricks_mcp_delete_transients();
bricks_mcp_delete_user_meta();
bricks_mcp_clear_cron();

// Flush rewrite rules.
flush_rewrite_rules();
