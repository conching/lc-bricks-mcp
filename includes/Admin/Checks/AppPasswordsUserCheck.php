<?php
/**
 * Application Passwords per-user check.
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
 * Checks whether Application Passwords are available for the current user.
 *
 * Some security plugins restrict Application Passwords by user role or group.
 */
class AppPasswordsUserCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'app_passwords_user';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Application Passwords Available for Current User', 'bricks-mcp' );
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
		return array( 'app_passwords' );
	}

	/**
	 * Run the per-user Application Passwords check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$user = wp_get_current_user();

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => sprintf(
					// translators: %s is the current WordPress username.
					__( 'Application Passwords are not available for user "%s". A plugin may be restricting them by role.', 'bricks-mcp' ),
					esc_html( $user->user_login )
				),
				'fix_steps' => array(
					__( 'Check iThemes Security role restrictions.', 'bricks-mcp' ),
					__( 'Check WP Cerber user group settings.', 'bricks-mcp' ),
					__( 'Check for custom per-user filters on wp_is_application_passwords_available_for_user.', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => sprintf(
				// translators: %s is the current WordPress username.
				__( 'Application Passwords are available for user "%s".', 'bricks-mcp' ),
				esc_html( $user->user_login )
			),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}
}
