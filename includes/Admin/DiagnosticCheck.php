<?php
/**
 * DiagnosticCheck interface.
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
 * Interface for diagnostic check classes.
 *
 * Each check inspects one aspect of the WordPress environment and returns
 * a structured result describing whether it passed, warned, or failed.
 *
 * Result array shape returned by run():
 * [
 *     'id'        => string,   // Same as id()
 *     'label'     => string,   // Same as label()
 *     'status'    => string,   // 'pass' | 'warn' | 'fail' | 'skipped'
 *     'message'   => string,   // Human-readable description
 *     'fix_steps' => array,    // Array of strings, empty if status = pass
 *     'category'  => string,   // Same as category()
 * ]
 */
interface DiagnosticCheck {

	/**
	 * Get the unique check identifier.
	 *
	 * @return string e.g. 'rest_api_reachable'
	 */
	public function id(): string;

	/**
	 * Get the human-readable check label.
	 *
	 * @return string e.g. 'REST API Reachable'
	 */
	public function label(): string;

	/**
	 * Get the check category.
	 *
	 * @return string One of 'connectivity', 'authentication', 'compatibility'
	 */
	public function category(): string;

	/**
	 * Get the IDs of checks that must pass before this check runs.
	 *
	 * @return array<string> Array of check IDs, e.g. ['https']
	 */
	public function dependencies(): array;

	/**
	 * Execute the check and return a structured result.
	 *
	 * @return array{id: string, label: string, status: string, message: string, fix_steps: array<string>, category: string}
	 */
	public function run(): array;
}
