<?php
/**
 * WP Site Health integration.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\Admin;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SiteHealth class.
 *
 * Registers Bricks MCP checks in the WordPress Site Health screen.
 */
class SiteHealth {

	/**
	 * Register hooks for WP Site Health integration.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter( 'site_status_tests', [ $this, 'register_tests' ] );
	}

	/**
	 * Register Bricks MCP direct tests with WP Site Health.
	 *
	 * @param array<string, mixed> $tests Existing tests.
	 * @return array<string, mixed> Modified tests with Bricks MCP checks added.
	 */
	public function register_tests( array $tests ): array {
		$tests['direct']['bricks_mcp_rest_api'] = [
			'label' => __( 'Bricks MCP: REST API reachable', 'bricks-mcp' ),
			'test'  => [ $this, 'test_rest_api' ],
		];
		$tests['direct']['bricks_mcp_app_passwords'] = [
			'label' => __( 'Bricks MCP: Application Passwords', 'bricks-mcp' ),
			'test'  => [ $this, 'test_app_passwords' ],
		];
		$tests['direct']['bricks_mcp_bricks_active'] = [
			'label' => __( 'Bricks MCP: Bricks Builder active', 'bricks-mcp' ),
			'test'  => [ $this, 'test_bricks_active' ],
		];
		return $tests;
	}

	/**
	 * Run the REST API reachable check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_rest_api(): array {
		$check  = new Checks\RestApiReachableCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'security' );
	}

	/**
	 * Run the Application Passwords available check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_app_passwords(): array {
		$check  = new Checks\AppPasswordsAvailableCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'security' );
	}

	/**
	 * Run the Bricks Builder active check.
	 *
	 * @return array<string, mixed> Site Health result array.
	 */
	public function test_bricks_active(): array {
		$check  = new Checks\BricksActiveCheck();
		$result = $check->run();
		return $this->format_site_health_result( $result, 'performance' );
	}

	/**
	 * Convert a DiagnosticCheck result to WP Site Health format.
	 *
	 * @param array<string, mixed> $check_result Result from DiagnosticCheck::run().
	 * @param string               $test_type    Site Health test type ('security' | 'performance').
	 * @return array<string, mixed> Formatted Site Health result.
	 */
	private function format_site_health_result( array $check_result, string $test_type ): array {
		$status_map = [
			'pass'    => 'good',
			'warn'    => 'recommended',
			'fail'    => 'critical',
			'skipped' => 'recommended',
		];

		$description = '<p>' . esc_html( $check_result['message'] ) . '</p>';
		if ( ! empty( $check_result['fix_steps'] ) ) {
			$description .= '<ul>';
			foreach ( $check_result['fix_steps'] as $step ) {
				$description .= '<li>' . esc_html( $step ) . '</li>';
			}
			$description .= '</ul>';
		}

		return [
			'label'       => $check_result['label'],
			'status'      => $status_map[ $check_result['status'] ] ?? 'recommended',
			'badge'       => [
				'label' => __( 'Bricks MCP', 'bricks-mcp' ),
				'color' => 'blue',
			],
			'description' => $description,
			'actions'     => '',
			'test'        => 'bricks_mcp_' . $check_result['id'],
		];
	}
}
