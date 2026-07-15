<?php
/**
 * Permalink structure check.
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
 * Checks that pretty permalinks are enabled.
 *
 * The WordPress REST API requires pretty permalinks (non-plain) to function.
 */
class PermalinkStructureCheck implements DiagnosticCheck {

	/**
	 * Get the check ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'permalink_structure';
	}

	/**
	 * Get the check label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Permalink Structure', 'bricks-mcp' );
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
	 * Run the permalink structure check.
	 *
	 * @return array<string, mixed>
	 */
	public function run(): array {
		$permalink_structure = get_option( 'permalink_structure' );

		if ( '' === $permalink_structure ) {
			return array(
				'id'        => $this->id(),
				'label'     => $this->label(),
				'status'    => 'fail',
				'message'   => __( 'Permalinks are set to "Plain" -- the REST API requires pretty permalinks.', 'bricks-mcp' ),
				'fix_steps' => array(
					__( 'Go to Settings > Permalinks and select any structure other than "Plain".', 'bricks-mcp' ),
				),
				'category'  => $this->category(),
			);
		}

		return array(
			'id'        => $this->id(),
			'label'     => $this->label(),
			'status'    => 'pass',
			'message'   => __( 'Pretty permalinks are enabled.', 'bricks-mcp' ),
			'fix_steps' => array(),
			'category'  => $this->category(),
		);
	}
}
