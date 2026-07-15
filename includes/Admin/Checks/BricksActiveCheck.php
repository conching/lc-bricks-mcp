<?php
/**
 * Bricks Builder active check.
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
 * Checks whether Bricks Builder theme is installed and active.
 *
 * Bricks MCP requires Bricks Builder to function for layout tools.
 */
class BricksActiveCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'bricks_active';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Bricks Builder Active', 'bricks-mcp' );
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
	 * @return array<string>
	 */
	public function dependencies(): array {
		return array();
	}

	/**
	 * Run the Bricks Builder active check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		if ( ! class_exists( '\Bricks\Elements' ) ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => __( 'Bricks Builder is not active. Bricks MCP requires Bricks Builder theme to be installed and active.', 'bricks-mcp' ),
				'fix_steps' => array(
					__( 'Install and activate the Bricks Builder theme from bricksbuilder.io.', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => __( 'Bricks Builder is active.', 'bricks-mcp' ),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}
}
