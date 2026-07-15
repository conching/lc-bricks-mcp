<?php
/**
 * HTTPS check.
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
 * Checks whether the site is served over HTTPS.
 *
 * Application Passwords require HTTPS by default in WordPress.
 */
class HttpsCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'https';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'HTTPS / SSL', 'bricks-mcp' );
	}

	/**
	 * Get the check category.
	 *
	 * @return string
	 */
	public function category(): string {
		return 'connectivity';
	}

	/**
	 * Get dependencies.
	 *
	 * @return array<string>
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * Run the HTTPS check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$is_https = is_ssl()
			|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

		if ( $is_https ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => __( 'Site is served over HTTPS.', 'bricks-mcp' ),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		// No HTTPS, but App Passwords are still available (e.g. via filter in local dev).
		if ( wp_is_application_passwords_available() ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => __( 'No HTTPS detected, but Application Passwords are available (enabled via filter).', 'bricks-mcp' ),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'fail',
			'message'   => __( 'Your site is not served over HTTPS. Application Passwords require HTTPS by default.', 'bricks-mcp' ),
			'fix_steps' => array(
				__( 'Enable HTTPS/SSL on your site via your hosting provider.', 'bricks-mcp' ),
				__( 'If behind a reverse proxy, ensure X-Forwarded-Proto header is set to https.', 'bricks-mcp' ),
				__( 'Or force-enable App Passwords: add_filter( "wp_is_application_passwords_available", "__return_true" );', 'bricks-mcp' ),
			),
			'category'  => $this->category(),
		);
	}
}
