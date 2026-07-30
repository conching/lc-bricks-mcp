<?php
/**
 * Security policy for Bricks element mutations.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\MCP\Services;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Detects newly introduced or changed executable element payloads.
 *
 * Existing executable payloads may remain in place while Dangerous Actions is
 * disabled, which allows safe operations such as moving an existing code
 * element. Creating or changing an executable payload requires explicit opt-in.
 */
final class ElementPolicy {

	/** @var array<int, string> */
	private const EXECUTABLE_ELEMENT_TYPES = [ 'code' ];

	/** @var array<int, string> */
	private const EXECUTABLE_SETTING_KEYS = [
		'code',
		'executeCode',
		'javascript',
		'script',
		'customJs',
		'customJavascript',
		'customScriptsHeader',
		'customScriptsBodyHeader',
		'customScriptsBodyFooter',
	];

	/**
	 * Return executable payloads that differ from the stored version.
	 *
	 * @param array<int, array<string, mixed>> $proposed Proposed element tree.
	 * @param array<int, array<string, mixed>> $existing Stored element tree.
	 * @return array<int, array{element_id: string, element_type: string, paths: array<int, string>}>
	 */
	public function changed_executable_payloads( array $proposed, array $existing ): array {
		$existing_signatures = [];
		foreach ( $existing as $element ) {
			$id = isset( $element['id'] ) ? (string) $element['id'] : '';
			if ( '' !== $id ) {
				$existing_signatures[ $id ] = $this->executable_signature( $element );
			}
		}

		$violations = [];
		foreach ( $proposed as $element ) {
			$id        = isset( $element['id'] ) ? (string) $element['id'] : '';
			$signature = $this->executable_signature( $element );
			if ( [] === $signature ) {
				continue;
			}

			$previous = $existing_signatures[ $id ] ?? [];
			if ( $signature === $previous ) {
				continue;
			}

			$violations[] = [
				'element_id'   => $id,
				'element_type' => isset( $element['name'] ) ? (string) $element['name'] : 'unknown',
				'paths'        => array_keys( $signature ),
			];
		}

		return $violations;
	}

	/**
	 * Build a stable signature of executable content in one element.
	 *
	 * @param array<string, mixed> $element Element data.
	 * @return array<string, mixed>
	 */
	private function executable_signature( array $element ): array {
		$name     = isset( $element['name'] ) ? (string) $element['name'] : '';
		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : [];

		if ( in_array( $name, self::EXECUTABLE_ELEMENT_TYPES, true ) ) {
			return [ 'settings' => $settings ];
		}

		$signature = [];
		$this->collect_executable_values( $settings, 'settings', $signature );
		ksort( $signature );
		return $signature;
	}

	/**
	 * Recursively collect script-capable keys and executable markup.
	 *
	 * @param array<string|int, mixed> $values    Settings to inspect.
	 * @param string                   $prefix    Current dotted path.
	 * @param array<string, mixed>     $signature Collected signature values.
	 * @return void
	 */
	private function collect_executable_values( array $values, string $prefix, array &$signature ): void {
		foreach ( $values as $key => $value ) {
			$key_string = (string) $key;
			$base_key   = explode( ':', $key_string )[0];
			$path       = $prefix . '.' . $key_string;

			if ( in_array( $base_key, self::EXECUTABLE_SETTING_KEYS, true ) ) {
				$signature[ $path ] = $value;
				continue;
			}

			if ( is_array( $value ) ) {
				$this->collect_executable_values( $value, $path, $signature );
				continue;
			}

			if ( is_string( $value ) && $this->contains_executable_markup( $value ) ) {
				$signature[ $path ] = $value;
			}
		}
	}

	/**
	 * Detect browser-executable markup in otherwise ordinary string settings.
	 */
	private function contains_executable_markup( string $value ): bool {
		return 1 === preg_match( '/<\s*script\b|\s+on[a-z]+\s*=|\bjavascript\s*:/i', $value );
	}
}
