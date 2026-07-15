<?php
/**
 * Internationalization handler.
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
 * I18n class.
 *
 * Handles internationalization for the plugin.
 * Since WordPress 6.8+, plugins with proper Text Domain and Domain Path headers
 * are automatically loaded. This class provides backward compatibility.
 */
final class I18n {

	/**
	 * Plugin text domain.
	 *
	 * @var string
	 */
	private const TEXT_DOMAIN = 'bricks-mcp';

	/**
	 * Initialize internationalization.
	 *
	 * @return void
	 */
	public function init(): void {
		// For backward compatibility with WordPress < 6.8.
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Load the plugin text domain.
	 *
	 * This is for backward compatibility with WordPress versions before 6.8.
	 * WordPress 6.8+ automatically loads translations from the plugin's
	 * languages directory if the Text Domain and Domain Path headers are set.
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			self::TEXT_DOMAIN,
			false,
			dirname( BRICKS_MCP_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Get the text domain.
	 *
	 * @return string The text domain.
	 */
	public static function get_text_domain(): string {
		return self::TEXT_DOMAIN;
	}
}
