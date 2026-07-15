<?php
/**
 * Plugin deactivation handler.
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
 * Deactivator class.
 *
 * Handles plugin deactivation tasks.
 */
final class Deactivator {

	/**
	 * Run deactivation tasks.
	 *
	 * This method is called when the plugin is deactivated.
	 * Note: This should NOT delete plugin data. Use uninstall.php for that.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		// Flush rewrite rules to clean up REST API endpoints.
		flush_rewrite_rules();
	}
}
