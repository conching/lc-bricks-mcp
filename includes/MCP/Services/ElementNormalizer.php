<?php
/**
 * Bricks element normalizer.
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
 * ElementNormalizer class.
 */
class ElementNormalizer {

	/**
	 * HTML content settings keys (sanitized with wp_kses_post).
	 * @var array<int, string>
	 */
	private const HTML_SETTINGS_KEYS = [
		'text',
		'content',
		'html',
		'innerHtml',
		'body',
		'excerpt',
		'description',
		'label',
		'caption',
	];

	/**
	 * CSS code block keys (raw CSS - preserve newlines, braces, combinators).
	 * Use wp_strip_all_tags() only - never sanitize_text_field() or wp_kses_post().
	 * @var array<int, string>
	 */
	private const CSS_CODE_KEYS = [
		'_cssCustom',
		'cssCode',
		'customCss',
		'css',
	];

	/**
	 * Invalid Bricks key corrections. null = drop the key entirely.
	 * @var array<string, string|null>
	 */
	private const KEY_CORRECTIONS = [
		'_maxWidth'  => '_widthMax',
		'_textAlign' => null,
	];

	/**
	 * Element ID generator instance.
	 * @var ElementIdGenerator
	 */
	private ElementIdGenerator $id_generator;

	/**
	 * Log of settings values altered by sanitize_settings() during the most
	 * recent normalize() call. Anchored here because the caller's raw
	 * pre-sanitization values are no longer visible once normalize() returns.
	 *
	 * @var array<int, array{element_id: string, key: string, before_len: int, after_len: int, removed_tags: array<int, string>}>
	 */
	private array $strip_log = [];

	public function __construct( ElementIdGenerator $id_generator ) {
		$this->id_generator = $id_generator;
	}

	/**
	 * Normalize element input to native Bricks flat array format.
	 */
	public function normalize( array $input, array $existing_elements = [] ): array {
		$this->strip_log = [];

		if ( empty( $input ) ) {
			return [];
		}
		if ( $this->is_flat_format( $input ) ) {
			return $input;
		}
		return $this->simplified_to_flat( $input, $existing_elements );
	}

	/**
	 * Sanitization alterations recorded by the most recent normalize() call.
	 *
	 * @return array<int, array{element_id: string, key: string, before_len: int, after_len: int, removed_tags: array<int, string>}>
	 */
	public function get_strip_log(): array {
		return $this->strip_log;
	}

	/**
	 * Return the strip log and clear it.
	 *
	 * Used by the save path so each save reports only the strips from its own
	 * normalize() call — a save that ran without normalization (e.g. raw
	 * element updates) must not inherit a previous call's log.
	 *
	 * @return array<int, array{element_id: string, key: string, before_len: int, after_len: int, removed_tags: array<int, string>}>
	 */
	public function consume_strip_log(): array {
		$log             = $this->strip_log;
		$this->strip_log = [];
		return $log;
	}

	/**
	 * Detect native Bricks flat array format.
	 */
	public function is_flat_format( array $elements ): bool {
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) ) {
				return false;
			}
			if (
				! array_key_exists( 'id', $element ) ||
				! array_key_exists( 'parent', $element ) ||
				! array_key_exists( 'children', $element )
			) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Convert simplified nested format to Bricks native flat array.
	 */
	public function simplified_to_flat( array $tree, array $existing_elements, int|string $parent_id = 0 ): array {
		$flat = [];

		foreach ( $tree as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name     = $node['name'] ?? 'div';
			$settings = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : [];
			$children = isset( $node['children'] ) && is_array( $node['children'] ) ? $node['children'] : [];

			$all_existing = array_merge( $existing_elements, $flat );
			$element_id   = $this->id_generator->generate_unique( $all_existing );

			// Apply key corrections before sanitization.
			$settings = $this->apply_key_corrections( $settings, $name );

			// Sanitize with Bricks-aware strategy.
			$sanitized_settings = $this->sanitize_settings( $settings, $name );

			// Record what sanitization altered, against the caller's raw values.
			$this->log_strips( $settings, $sanitized_settings, $element_id );

			$child_flat   = $this->simplified_to_flat( $children, array_merge( $all_existing, [ [ 'id' => $element_id ] ] ), $element_id );
			$children_ids = array_map(
				static fn( array $el ) => $el['id'],
				array_filter(
					$child_flat,
					static fn( array $el ) => (string) $el['parent'] === (string) $element_id
				)
			);

			$element = [
				'id'       => $element_id,
				'name'     => sanitize_text_field( $name ),
				'parent'   => $parent_id,
				'children' => array_values( $children_ids ),
				'settings' => $sanitized_settings,
			];

			$flat[] = $element;
			foreach ( $child_flat as $child_element ) {
				$flat[] = $child_element;
			}
		}

		return $flat;
	}

	/**
	 * Apply key corrections: rename invalid keys, drop null-mapped keys.
	 */
	public function apply_key_corrections( array $settings, string $element_name = '' ): array {
		$corrected = [];

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) ) {
				$corrected[ $key ] = $value;
				continue;
			}

			$base_key = explode( ':', $key )[0];

			if ( array_key_exists( $base_key, self::KEY_CORRECTIONS ) ) {
				$replacement = self::KEY_CORRECTIONS[ $base_key ];
				if ( null === $replacement ) {
					continue; // Drop invalid key.
				}
				$suffix                              = substr( $key, strlen( $base_key ) );
				$corrected[ $replacement . $suffix ] = $value;
				continue;
			}

			$corrected[ $key ] = $value;
		}

		return $corrected;
	}

	/**
	 * Sanitize element settings with Bricks-aware type detection.
	 *
	 * Strategy:
	 * 1. CSS code keys (_cssCustom, cssCode) -> wp_strip_all_tags() only.
	 * 2. Bricks style keys (underscore prefix: _padding, _background, etc.) -> recurse with sanitize_style_value().
	 * 3. HTML content keys (text, html, label, etc.) -> wp_kses_post().
	 * 4. All other strings -> sanitize_text_field().
	 */
	public function sanitize_settings( array $settings, string $element_name = '' ): array {
		$sanitized = [];

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$base_key    = explode( ':', $key )[0];
			$is_css_key  = $this->is_css_style_key( $base_key );
			$is_css_code = $this->is_css_code_key( $base_key );

			// CSS code blocks: preserve newlines, braces, combinators.
			if ( $is_css_code ) {
				if ( is_string( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_css_string( $value );
				} elseif ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_css_string( implode( "\n", $value ) );
				} else {
					$sanitized[ $key ] = $value;
				}
				continue;
			}

			// Bricks style keys: recurse with CSS-safe sanitization.
			if ( $is_css_key ) {
				if ( is_array( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_style_value( $value );
				} elseif ( is_string( $value ) ) {
					$sanitized[ $key ] = $this->sanitize_css_value( $value );
				} elseif ( ! is_null( $value ) ) {
					$sanitized[ $key ] = $value;
				}
				continue;
			}

			// Non-style arrays (query, link, icon, etc.): recurse.
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = $this->sanitize_settings( $value, $element_name );
				continue;
			}

			// Non-string primitives.
			if ( ! is_string( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}

			// HTML content keys.
			$is_html_key   = in_array( $base_key, self::HTML_SETTINGS_KEYS, true );
			$contains_html = wp_strip_all_tags( $value ) !== $value;

			if ( $is_html_key || $contains_html ) {
				$sanitized[ $key ] = wp_kses_post( $value );
				continue;
			}

			// Default: plain text sanitization.
			$sanitized[ $key ] = sanitize_text_field( $value );
		}

		return $sanitized;
	}

	/**
	 * Sanitize a nested Bricks style value (color, dimension, typography, border objects).
	 * Recurses into nested arrays; applies sanitize_css_value() to leaf strings.
	 */
	private function sanitize_style_value( array $value ): array {
		$sanitized = [];
		foreach ( $value as $k => $v ) {
			if ( ! is_string( $k ) ) {
				$sanitized[ $k ] = $v;
				continue;
			}
			if ( is_array( $v ) ) {
				$sanitized[ $k ] = $this->sanitize_style_value( $v );
			} elseif ( is_string( $v ) ) {
				$sanitized[ $k ] = $this->sanitize_css_value( $v );
			} elseif ( ! is_null( $v ) ) {
				$sanitized[ $k ] = $v;
			}
		}
		return $sanitized;
	}

	/**
	 * Sanitize a CSS code block string.
	 * Preserves newlines, braces, CSS combinators (>), pseudo-selectors, CSS variables.
	 * NEVER use sanitize_text_field() (collapses newlines) or wp_kses_post() (encodes >).
	 */
	private function sanitize_css_string( string $css ): string {
		$s = wp_strip_all_tags( $css );
		$s = (string) preg_replace( '/\bjavascript\s*:/i', '', $s );
		$s = (string) preg_replace( '/\bexpression\s*\(/i', '', $s );
		return $s;
	}

	/**
	 * Sanitize a single CSS value string (units, vars, keywords, colors).
	 */
	private function sanitize_css_value( string $value ): string {
		return wp_strip_all_tags( trim( $value ) );
	}

	/**
	 * Detect Bricks CSS style keys (underscore-prefixed, including composite with :breakpoint/:pseudo).
	 */
	private function is_css_style_key( string $key ): bool {
		return str_starts_with( $key, '_' );
	}

	/**
	 * Detect keys that hold raw CSS code blocks.
	 */
	private function is_css_code_key( string $key ): bool {
		return in_array( $key, self::CSS_CODE_KEYS, true );
	}

	/**
	 * Merge new elements into an existing flat array under a specified parent.
	 */
	public function merge_elements( array $existing, array $new_elements, string $parent_id, ?int $position = null ): array {
		$new_child_ids = array_map(
			static fn( array $el ) => $el['id'],
			array_filter(
				$new_elements,
				static fn( array $el ) => (string) $el['parent'] === $parent_id
			)
		);

		if ( '0' !== $parent_id ) {
			$existing = array_map(
				static function ( array $el ) use ( $parent_id, $new_child_ids, $position ) {
					if ( $el['id'] === $parent_id ) {
						if ( null === $position ) {
							$el['children'] = array_values(
								array_unique( array_merge( $el['children'], $new_child_ids ) )
							);
						} else {
							$children = array_values(
								array_filter(
									$el['children'],
									static fn( string $cid ) => ! in_array( $cid, $new_child_ids, true )
								)
							);
							array_splice( $children, $position, 0, $new_child_ids );
							$el['children'] = array_values( array_unique( $children ) );
						}
					}
					return $el;
				},
				$existing
			);
			return array_merge( $existing, $new_elements );
		}

		if ( null === $position ) {
			return array_merge( $existing, $new_elements );
		}

		// Root-level insert: `position` is a SIBLING index among root elements
		// (parent === 0), NOT a raw offset into the flat array. Count root
		// elements to find the correct flat-array insertion point — mirroring
		// BricksService::move_element()'s root branch. Without this, position:1
		// on a page whose first root element has descendants lands the new
		// element inside that subtree instead of as the second root sibling.
		$root_seen       = 0;
		$insertion_point = count( $existing ); // Default: append after everything.
		foreach ( $existing as $idx => $elem ) {
			if ( '0' === (string) ( $elem['parent'] ?? '' ) ) {
				if ( $root_seen === $position ) {
					$insertion_point = $idx;
					break;
				}
				++$root_seen;
			}
		}

		array_splice( $existing, $insertion_point, 0, $new_elements );
		return $existing;
	}

	/**
	 * Record string settings values that sanitize_settings() altered.
	 *
	 * Recursively walks the pre-sanitization settings against the sanitized
	 * result. Any string leaf whose value changed is appended to the strip
	 * log with the element id, dotted key path, before/after byte lengths,
	 * and the names of HTML tags removed by the change.
	 *
	 * @param array<string, mixed> $before     Settings before sanitization (post key-correction).
	 * @param array<string, mixed> $after      Settings after sanitization.
	 * @param string               $element_id Element the settings belong to.
	 * @param string               $path       Dotted key-path prefix for recursion.
	 * @return void
	 */
	private function log_strips( array $before, array $after, string $element_id, string $path = '' ): void {
		foreach ( $before as $key => $value ) {
			$key_path = '' === $path ? (string) $key : $path . '.' . $key;

			if ( is_array( $value ) ) {
				$after_branch = ( isset( $after[ $key ] ) && is_array( $after[ $key ] ) ) ? $after[ $key ] : [];
				$this->log_strips( $value, $after_branch, $element_id, $key_path );
				continue;
			}

			if ( ! is_string( $value ) ) {
				continue;
			}

			$after_value = ( isset( $after[ $key ] ) && is_string( $after[ $key ] ) ) ? $after[ $key ] : '';

			if ( $value === $after_value ) {
				continue;
			}

			$this->strip_log[] = [
				'element_id'   => $element_id,
				'key'          => $key_path,
				'before_len'   => strlen( $value ),
				'after_len'    => strlen( $after_value ),
				'removed_tags' => self::removed_tags( $value, $after_value ),
			];
		}
	}

	/**
	 * Names of HTML tags present in $before that no longer appear (or appear
	 * fewer times) in $after.
	 *
	 * @param string $before Value before sanitization.
	 * @param string $after  Value after sanitization.
	 * @return array<int, string> Unique lowercase tag names, e.g. ["script"].
	 */
	public static function removed_tags( string $before, string $after ): array {
		$count_tags = static function ( string $html ): array {
			preg_match_all( '/<\s*([a-zA-Z][a-zA-Z0-9-]*)/', $html, $matches );
			return array_count_values( array_map( 'strtolower', $matches[1] ) );
		};

		$before_tags = $count_tags( $before );
		$after_tags  = $count_tags( $after );

		$removed = [];
		foreach ( $before_tags as $tag => $count ) {
			if ( ( $after_tags[ $tag ] ?? 0 ) < $count ) {
				$removed[] = $tag;
			}
		}

		return $removed;
	}
}
