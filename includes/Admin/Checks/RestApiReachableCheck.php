<?php
/**
 * REST API reachability check.
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
 * Checks whether the WordPress REST API is reachable via a loopback probe.
 *
 * A 401/403 response is treated as a warning (not failure) because MCP
 * clients authenticate via Application Passwords and bypass the auth gate.
 */
class RestApiReachableCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'rest_api_reachable';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'REST API Reachable', 'bricks-mcp' );
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
		return array( 'permalink_structure' );
	}

	/**
	 * Run the REST API reachability check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$rest_url = rest_url( 'wp/v2' );

		if ( empty( $rest_url ) ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => __( 'Could not determine REST API URL.', 'bricks-mcp' ),
				'fix_steps' => array(
					__( 'Ensure pretty permalinks are enabled under Settings > Permalinks.', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		$response = wp_remote_get(
			$rest_url,
			array(
				'timeout'   => 5,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => sprintf(
					// translators: %s is the WP_Error message.
					__( 'REST API loopback request failed: %s', 'bricks-mcp' ),
					$response->get_error_message()
				),
				'fix_steps' => array(
					__( 'Check that loopback requests are allowed on your server.', 'bricks-mcp' ),
					__( 'If using a firewall or security plugin, whitelist loopback connections from the server to itself.', 'bricks-mcp' ),
					__( 'Check the WordPress Site Health page for loopback request status.', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $http_code || 403 === $http_code ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'warn',
				'message'   => __( 'REST API requires authentication for all requests. This is normal if a security plugin restricts unauthenticated access -- MCP clients authenticate via Application Passwords.', 'bricks-mcp' ),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		if ( 200 === $http_code ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => __( 'WordPress REST API is reachable.', 'bricks-mcp' ),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'warn',
			'message'   => sprintf(
				// translators: %d is the HTTP status code.
				__( 'REST API returned unexpected HTTP status: %d', 'bricks-mcp' ),
				$http_code
			),
			'fix_steps' => array(
				__( 'Check your .htaccess file for rules that may block /wp-json/ requests.', 'bricks-mcp' ),
				__( 'Check your Nginx configuration for REST API blocking rules.', 'bricks-mcp' ),
			),
			'category'  => $this->category(),
		);
	}
}
