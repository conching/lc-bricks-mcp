<?php
/**
 * Bricks global variable name normalization and repair planning.
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
 * VariableNameNormalizer class.
 *
 * Bricks stores variable names and scale prefixes without the CSS -- marker.
 * This helper has no WordPress dependencies, so repair plans can be tested
 * without changing stored options.
 */
final class VariableNameNormalizer {

	/**
	 * Convert a name or var() reference to the bare Bricks storage format.
	 *
	 * @param string $name Variable name or CSS reference.
	 * @return string Bare variable name, or an empty string.
	 */
	public static function to_storage( string $name ): string {
		// Loop until stable: the 2.1.1 writer stored "var(--x)" input as "--var(--x)".
		do {
			$before = $name;
			$name   = trim( $name );

			while ( str_starts_with( $name, '--' ) ) {
				$name = substr( $name, 2 );
			}

			if ( preg_match( '/^var\s*\((.*)\)$/is', $name, $matches ) ) {
				$name = explode( ',', $matches[1], 2 )[0];
			}
		} while ( $name !== $before );

		return $name;
	}

	/**
	 * Format a variable name for use as a CSS custom property.
	 *
	 * @param string $name Variable name or CSS reference.
	 * @return string CSS property name, or an empty string.
	 */
	public static function to_css_property( string $name ): string {
		$name = self::to_storage( $name );
		return '' === $name ? '' : '--' . $name;
	}

	/**
	 * The custom property Bricks actually emits for a stored name.
	 *
	 * Bricks prepends "--" to whatever is stored, so a legacy "--foo" is emitted
	 * as "----foo" until it is repaired.
	 *
	 * @param string $stored_name Stored variable name.
	 * @return string Emitted CSS property name, or an empty string.
	 */
	public static function emitted_property( string $stored_name ): string {
		return '' === $stored_name ? '' : '--' . $stored_name;
	}

	/**
	 * Convert a scale prefix to the bare Bricks storage format.
	 *
	 * @param string $prefix Scale prefix.
	 * @return string Bare scale prefix.
	 */
	public static function normalize_prefix( string $prefix ): string {
		return self::to_storage( $prefix );
	}

	/**
	 * Check whether a stored name still contains a leading CSS marker.
	 *
	 * @param string $stored_name Stored variable name or scale prefix.
	 * @return bool True when Bricks would emit a doubled marker.
	 */
	public static function is_double_prefixed( string $stored_name ): bool {
		return str_starts_with( $stored_name, '--' );
	}

	/**
	 * Find the variable that already owns a bare name.
	 *
	 * A legacy entry stored as "--name" owns "name" too: Bricks emits both as the
	 * same custom property once repaired, so the name is not free.
	 *
	 * @param array       $variables  Stored global variables.
	 * @param string      $bare_name  Bare name to look up.
	 * @param string|null $exclude_id Variable ID to ignore (the one being renamed).
	 * @return array|null The owning variable, or null when the name is free.
	 */
	public static function name_owner( array $variables, string $bare_name, ?string $exclude_id = null ): ?array {
		if ( '' === $bare_name ) {
			return null;
		}

		foreach ( $variables as $variable ) {
			if ( ! is_array( $variable ) || ! is_string( $variable['name'] ?? null ) ) {
				continue;
			}

			if ( null !== $exclude_id && ( $variable['id'] ?? null ) === $exclude_id ) {
				continue;
			}

			if ( self::to_storage( $variable['name'] ) === $bare_name ) {
				return $variable;
			}
		}

		return null;
	}

	/**
	 * Plan a deterministic repair without modifying the supplied arrays.
	 *
	 * A scale is repaired all-or-nothing: Bricks derives a step name by removing
	 * the stored prefix, so a scale whose prefix or any step cannot be repaired
	 * keeps its prefix and every step unchanged, and each is reported.
	 *
	 * @param array       $variables   Stored global variables.
	 * @param array       $categories  Stored variable categories.
	 * @param string|null $category_id Limit the repair to one category (null = all).
	 * @return array<string, array> Repaired arrays and change/conflict records.
	 */
	public static function plan_repair( array $variables, array $categories, ?string $category_id = null ): array {
		$planned_variables  = $variables;
		$planned_categories = $categories;
		$renamed            = [];
		$prefixes           = [];
		$conflicts          = [];
		$scale_prefixes     = []; // Scale category ID => repaired prefix, for legacy prefixes in scope.
		$scale_ids          = [];
		$blocked            = [];

		foreach ( $categories as $category ) {
			if ( ! is_array( $category ) || ! isset( $category['scale'] ) || ! is_array( $category['scale'] ) ) {
				continue;
			}

			$cat_id               = (string) ( $category['id'] ?? '' );
			$scale_ids[ $cat_id ] = true;
			$prefix               = $category['scale']['prefix'] ?? null;

			if ( ( null !== $category_id && $cat_id !== $category_id ) || ! is_string( $prefix ) || ! self::is_double_prefixed( $prefix ) ) {
				continue;
			}

			$scale_prefixes[ $cat_id ] = self::normalize_prefix( $prefix );

			if ( '' === $scale_prefixes[ $cat_id ] ) {
				$blocked[ $cat_id ] = true;
				$conflicts[]        = [
					'id'     => $cat_id,
					'name'   => $prefix,
					'target' => '',
					'reason' => 'empty_prefix',
				];
			}
		}

		// Pass 1: decide every variable's target. Names already bare are claimed first.
		// Repeat until no new scale gets blocked: a blocked scale keeps its steps, so
		// their targets must not stay claimed against other repairs.
		do {
			$blocked_count  = count( $blocked );
			$pass_conflicts = [];
			$targets        = [];
			$claimed        = [];

			foreach ( $variables as $variable ) {
				if ( is_array( $variable ) && is_string( $variable['name'] ?? null ) && ! self::is_double_prefixed( $variable['name'] ) ) {
					$claimed[ $variable['name'] ] = true;
				}
			}

			foreach ( $variables as $index => $variable ) {
				if ( ! is_array( $variable ) || ! is_string( $variable['name'] ?? null ) || ! self::is_double_prefixed( $variable['name'] ) ) {
					continue;
				}

				$var_category = (string) ( $variable['category'] ?? '' );
				if ( null !== $category_id && $var_category !== $category_id ) {
					continue;
				}

				$target = self::to_storage( $variable['name'] );
				$reason = '' === $target ? 'empty_name' : ( isset( $claimed[ $target ] ) ? 'name_taken' : '' );

				if ( '' !== $reason ) {
					$pass_conflicts[] = [
						'id'     => $variable['id'] ?? null,
						'name'   => $variable['name'],
						'target' => $target,
						'reason' => $reason,
					];

					if ( isset( $scale_ids[ $var_category ] ) ) {
						$blocked[ $var_category ] = true;
					}
					continue;
				}

				$targets[ $index ] = $target;

				// Held-back steps (pass 2) keep their legacy name, so they claim nothing.
				if ( ! isset( $blocked[ $var_category ] ) ) {
					$claimed[ $target ] = true;
				}
			}
		} while ( count( $blocked ) > $blocked_count );

		$conflicts = array_merge( $conflicts, $pass_conflicts );

		// Pass 2: apply renames, holding back every step of a blocked scale.
		foreach ( $targets as $index => $target ) {
			$variable     = $variables[ $index ];
			$var_category = (string) ( $variable['category'] ?? '' );

			if ( isset( $blocked[ $var_category ] ) ) {
				$conflicts[] = [
					'id'     => $variable['id'] ?? null,
					'name'   => $variable['name'],
					'target' => $target,
					'reason' => 'held_back_scale_conflict',
				];
				continue;
			}

			$planned_variables[ $index ]['name'] = $target;
			$renamed[]                           = [
				'id'       => $variable['id'] ?? null,
				'category' => $variable['category'] ?? '',
				'from'     => $variable['name'],
				'to'       => $target,
			];
		}

		// Pass 3: apply prefixes of scales that are not blocked.
		foreach ( $planned_categories as $index => $category ) {
			$cat_id = is_array( $category ) ? (string) ( $category['id'] ?? '' ) : '';

			if ( ! isset( $scale_prefixes[ $cat_id ] ) ) {
				continue;
			}

			if ( isset( $blocked[ $cat_id ] ) ) {
				if ( '' !== $scale_prefixes[ $cat_id ] ) {
					$conflicts[] = [
						'id'     => $cat_id,
						'name'   => $category['scale']['prefix'],
						'target' => $scale_prefixes[ $cat_id ],
						'reason' => 'prefix_blocked_by_step_conflict',
					];
				}
				continue;
			}

			$planned_categories[ $index ]['scale']['prefix'] = $scale_prefixes[ $cat_id ];
			$prefixes[]                                      = [
				'category_id'   => $cat_id,
				'category_name' => $category['name'] ?? '',
				'from'          => $category['scale']['prefix'],
				'to'            => $scale_prefixes[ $cat_id ],
			];
		}

		return [
			'variables'  => $planned_variables,
			'categories' => $planned_categories,
			'renamed'    => $renamed,
			'prefixes'   => $prefixes,
			'conflicts'  => $conflicts,
		];
	}
}
