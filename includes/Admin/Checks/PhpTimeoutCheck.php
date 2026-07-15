<?php
/**
 * PHP timeout diagnostic check.
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
 * Checks whether PHP execution time is sufficient for SSE streams and long-running MCP tool calls.
 *
 * Returns pass for unlimited (0) or >= 60 seconds, warn for < 60 seconds.
 */
class PhpTimeoutCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'php_timeout';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'PHP Execution Time', 'bricks-mcp' );
	}

	/**
	 * Get the check category.
	 *
	 * @return string
	 */
	public function category(): string {
		return 'compatibility';
	}

	/**
	 * Get dependencies.
	 *
	 * No dependencies — this check runs independently.
	 *
	 * @return array<string>
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * Get the current max_execution_time ini value.
	 *
	 * Extracted into a protected method so unit tests can override it
	 * without needing to call ini_get() directly.
	 *
	 * @return string
	 */
	protected function get_max_execution_time(): string {
		return (string) ini_get( 'max_execution_time' );
	}

	/**
	 * Run the PHP timeout check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$raw     = $this->get_max_execution_time();
		$seconds = (int) $raw;

		if ( 0 === $seconds ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => __( 'PHP execution time is set to unlimited (0).', 'bricks-mcp' ),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		if ( $seconds >= 60 ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'pass',
				'message'   => sprintf(
					// translators: %d is the number of seconds.
					__( 'PHP execution time is %d seconds.', 'bricks-mcp' ),
					$seconds
				),
				'fix_steps' => array(),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'warn',
			'message'   => sprintf(
				// translators: %d is the number of seconds.
				__( 'PHP execution time is %d seconds (minimum 60 recommended). Long MCP tool calls or SSE streams may be terminated early.', 'bricks-mcp' ),
				$seconds
			),
			'fix_steps' => array(
				__( "Option A \u2013 wp-config.php (applies at runtime): add the line below before \"That's all, stop editing!\": ini_set( 'max_execution_time', 300 );", 'bricks-mcp' ),
				__( 'Option B \u2013 php.ini (applies globally): set max_execution_time = 300 and restart PHP.', 'bricks-mcp' ),
			),
			'category'  => $this->category(),
		);
	}
}
