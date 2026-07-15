<?php
/**
 * Application Passwords availability check.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin\Checks;

use BricksMCP\Admin\DiagnosticCheck;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks whether Application Passwords are globally enabled.
 *
 * A plugin or filter may have disabled Application Passwords globally.
 */
class AppPasswordsAvailableCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'app_passwords';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Application Passwords Available', 'bricks-mcp' );
	}

	/**
	 * Get the check category.
	 *
	 * @return string
	 */
	public function category(): string {
		return 'authentication';
	}

	/**
	 * Get dependencies.
	 *
	 * @return array<string>
	 */
	public function dependencies(): array {
		return array( 'https' );
	}

	/**
	 * Run the Application Passwords availability check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		if ( ! wp_is_application_passwords_available() ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => __( 'Application Passwords are disabled on this site. A plugin or filter is blocking them.', 'bricks-mcp' ),
				'fix_steps' => array(
					__( 'Check for "Disable Application Passwords" plugin and deactivate it.', 'bricks-mcp' ),
					__( 'Check iThemes/Solid Security hardening settings for Application Password restrictions.', 'bricks-mcp' ),
					__( 'Check mu-plugins for: add_filter( "wp_is_application_passwords_available", "__return_false" );', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => __( 'Application Passwords are enabled.', 'bricks-mcp' ),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}
}
