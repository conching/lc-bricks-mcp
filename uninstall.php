<?php
/**
 * Plugin uninstallation handler.
 *
 * This file is executed when the plugin is uninstalled via the WordPress admin.
 * It removes all plugin data from the database.
 *
 * @package LCBricksMCP
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
function lc_bricks_mcp_delete_options(): void {
	$options = [
		'lc_bricks_mcp_settings',
		'lc_bricks_mcp_version',
		'lc_bricks_mcp_activated_at',
		'lc_feedback_settings',
		'lc_feedback_last_sync_at',
		'lc_feedback_last_sync_error',
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
function lc_bricks_mcp_delete_transients(): void {
	global $wpdb;

	// Delete all transients with the plugin prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_lc_bricks_mcp_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_lc_bricks_mcp_' ) . '%'
		)
	);
}

/**
 * Delete all plugin user meta.
 *
 * @return void
 */
function lc_bricks_mcp_delete_user_meta(): void {
	global $wpdb;

	// Delete all user meta with the plugin prefix.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'lc_bricks_mcp_' ) . '%'
		)
	);
}

/**
 * Clear any scheduled cron events.
 *
 * @return void
 */
function lc_bricks_mcp_clear_cron(): void {
	$cron_hooks = [
		'lc_bricks_mcp_cleanup',
		'lc_bricks_mcp_feedback_sync',
		'lc_bricks_mcp_feedback_slack_action',
	];

	foreach ( $cron_hooks as $hook ) {
		$timestamp = wp_next_scheduled( $hook );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, $hook );
		}
	}
}

/**
 * Delete private feedback tasks, snapshots, and preview pages on uninstall.
 */
function lc_bricks_mcp_delete_feedback_posts(): void {
	$task_ids = get_posts(
		[
			'post_type'      => [ 'lc_feedback_task', 'lc_feedback_snap' ],
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		]
	);
	foreach ( $task_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	$preview_ids = get_posts(
		[
			'post_type'      => 'page',
			'post_status'    => [ 'private', 'trash' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => '_lc_feedback_preview_task',
		]
	);
	foreach ( $preview_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}

// Run cleanup.
lc_bricks_mcp_delete_options();
lc_bricks_mcp_delete_transients();
lc_bricks_mcp_delete_user_meta();
lc_bricks_mcp_clear_cron();
lc_bricks_mcp_delete_feedback_posts();

// Flush rewrite rules.
flush_rewrite_rules();
