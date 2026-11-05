<?php
/**
 * REST API reachability check.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\Admin\Checks;

use LCBricksMCP\Admin\DiagnosticCheck;

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
		return __( 'REST API Reachable', 'lc-bricks-mcp' );
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
				'message'   => __( 'Could not determine REST API URL.', 'lc-bricks-mcp' ),
				'fix_steps' => array(
					__( 'Ensure pretty permalinks are enabled under Settings > Permalinks.', 'lc-bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		$response = wp_remote_get(
			$rest_url,
			array(
				'timeout' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => sprintf(
					// translators: %s is the WP_Error message.
					__( 'REST API loopback request failed: %s', 'lc-bricks-mcp' ),
					$response->get_error_message()
				),
				'fix_steps' => array(
					__( 'Check that loopback requests are allowed on your server.', 'lc-bricks-mcp' ),
					__( 'If using a firewall or security plugin, whitelist loopback connections from the server to itself.', 'lc-bricks-mcp' ),
					__( 'Check the WordPress Site Health page for loopback request status.', 'lc-bricks-mcp' ),
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
				'message'   => __( 'REST API requires authentication for all requests. This is normal if a security plugin restricts unauthenticated access -- MCP clients authenticate via Application Passwords.', 'lc-bricks-mcp' ),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		if ( 200 === $http_code ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => __( 'WordPress REST API is reachable.', 'lc-bricks-mcp' ),
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
				__( 'REST API returned unexpected HTTP status: %d', 'lc-bricks-mcp' ),
				$http_code
			),
			'fix_steps' => array(
				__( 'Check your .htaccess file for rules that may block /wp-json/ requests.', 'lc-bricks-mcp' ),
				__( 'Check your Nginx configuration for REST API blocking rules.', 'lc-bricks-mcp' ),
			),
			'category'  => $this->category(),
		);
	}
}
