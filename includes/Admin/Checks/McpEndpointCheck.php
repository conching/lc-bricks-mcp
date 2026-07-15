<?php
/**
 * MCP endpoint reachability check.
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
 * Checks whether the Bricks MCP REST endpoint is registered and responding.
 *
 * A 401 or 405 (Method Not Allowed) response is treated as pass because
 * the MCP endpoint expects POST requests with authentication — the unauthenticated
 * GET simply confirms the endpoint is registered.
 */
class McpEndpointCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'mcp_endpoint';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'MCP Endpoint Registered', 'bricks-mcp' );
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
		return array( 'rest_api_reachable', 'app_passwords' );
	}

	/**
	 * Run the MCP endpoint check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$endpoint_url = rest_url( 'bricks-mcp/v1/mcp' );

		$response = wp_remote_get(
			$endpoint_url,
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
					__( 'MCP endpoint loopback request failed: %s', 'bricks-mcp' ),
					$response->get_error_message()
				),
				'fix_steps' => array(
					__( 'Check that loopback requests are allowed on your server.', 'bricks-mcp' ),
					__( 'Check the WordPress Site Health page for loopback request status.', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		$http_code = wp_remote_retrieve_response_code( $response );

		if ( 404 === $http_code ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => __( 'MCP endpoint not found. The bricks-mcp/v1 namespace may not be registered.', 'bricks-mcp' ),
				'fix_steps' => array(
					__( 'Ensure the Bricks MCP plugin is activated and the "Enable MCP Server" setting is on.', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		// 401 (auth required), 405 (Method Not Allowed — GET on a POST endpoint),
		// and 200 all mean the endpoint exists and is responding.
		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => __( 'MCP endpoint is registered and responding.', 'bricks-mcp' ),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}
}
