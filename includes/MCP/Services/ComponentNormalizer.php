<?php
/**
 * Bricks component definition and instance normalization.
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
 * Convert component input to the shapes stored by the Bricks builder.
 *
 * The caller supplies IDs and metadata; this class does not read WordPress state.
 */
final class ComponentNormalizer {

	/**
	 * Put the sole structural root first and replace its ID with the component ID.
	 *
	 * @param array<int, mixed> $elements Flat component element array.
	 * @param string            $cid      Component ID.
	 * @return array{elements: array, old_root_id: string}|\WP_Error Normalized tree or error.
	 */
	public static function normalize_definition_elements( array $elements, string $cid ): array|\WP_Error {
		$elements   = array_values( $elements );
		$root_index = null;

		foreach ( $elements as $index => $element ) {
			if ( ! is_array( $element ) ) {
				return new \WP_Error( 'invalid_component_tree', 'Each component element must be an object.' );
			}

			$parent = $element['parent'] ?? null;
			if ( null === $parent || 0 === $parent || '0' === $parent || '' === $parent ) {
				if ( null !== $root_index ) {
					return new \WP_Error( 'invalid_component_tree', 'A component must have exactly one root element.' );
				}
				$root_index = $index;
			}
		}

		if ( null === $root_index ) {
			return new \WP_Error( 'invalid_component_tree', 'A component must have exactly one root element.' );
		}

		$old_root_id = $elements[ $root_index ]['id'] ?? null;
		if ( ! is_string( $old_root_id ) || '' === $old_root_id ) {
			return new \WP_Error( 'invalid_component_tree', 'The component root must have an element ID.' );
		}

		foreach ( $elements as $index => $element ) {
			if ( $index !== $root_index && ( $element['id'] ?? null ) === $cid ) {
				return new \WP_Error( 'invalid_component_tree', 'A non-root element already uses the component ID.' );
			}
		}

		$root = $elements[ $root_index ];
		unset( $elements[ $root_index ] );
		$elements = array_merge( array( $root ), array_values( $elements ) );

		foreach ( $elements as $index => &$element ) {
			$element_id = $element['id'] ?? null;
			if ( 0 === $index ) {
				$element['id']     = $cid;
				$element['parent'] = 0;
			} elseif ( ( $element['parent'] ?? null ) === $old_root_id ) {
				$element['parent'] = $cid;
			}

			if ( ! array_key_exists( 'children', $element ) ) {
				$element['children'] = array();
			} elseif ( is_array( $element['children'] ) ) {
				foreach ( $element['children'] as &$child_id ) {
					if ( $child_id === $old_root_id ) {
						$child_id = $cid;
					}
				}
				unset( $child_id );
			}

			if ( ! array_key_exists( 'settings', $element ) ) {
				$element['settings'] = array();
			}

			if ( is_array( $element['settings'] ) && is_string( $element_id ) ) {
				foreach ( $element['settings'] as $key => &$value ) {
					if ( is_string( $key ) && str_starts_with( $key, '_cssCustom' ) && is_string( $value ) ) {
						$value = str_replace( '#brxe-' . $element_id, '.brxe-' . ( 0 === $index ? $cid : $element_id ), $value );
					}
				}
				unset( $value );
			}
		}
		unset( $element );

		return array(
			'elements'    => $elements,
			'old_root_id' => $old_root_id,
		);
	}

	/**
	 * Repair a tree stored by lc-bricks-mcp 2.1.2 or earlier, whose create/update set
	 * the root ID to the component ID without remapping the children. A child that the
	 * root lists in `children`, whose `parent` names an element that no longer exists,
	 * is relinked to the component ID. Nothing else changes.
	 *
	 * @param array  $elements Stored component elements.
	 * @param string $cid      Component ID.
	 * @return array{elements: array, repaired: int, old_root_id: string} Repaired tree. old_root_id is the
	 *         single vanished parent ID the repaired children shared, else the component ID.
	 */
	public static function repair_legacy_root_links( array $elements, string $cid ): array {
		$ids       = array();
		$root_kids = array();
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) && is_scalar( $element['id'] ) ) {
				$ids[ (string) $element['id'] ] = true;
				if ( (string) $element['id'] === $cid && is_array( $element['children'] ?? null ) ) {
					$root_kids = $element['children'];
				}
			}
		}

		$repaired     = 0;
		$stale_parent = array();
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) || ( $element['id'] ?? null ) === $cid ) {
				continue;
			}
			$parent = $element['parent'] ?? null;
			if ( is_string( $parent ) && '' !== $parent && '0' !== $parent && ! isset( $ids[ $parent ] ) && in_array( $element['id'] ?? null, $root_kids, true ) ) {
				$stale_parent[ $parent ] = true;
				$element['parent']       = $cid;
				++$repaired;
			}
		}
		unset( $element );

		return array(
			'elements'    => $elements,
			'repaired'    => $repaired,
			'old_root_id' => 1 === count( $stale_parent ) ? (string) array_key_first( $stale_parent ) : $cid,
		);
	}

	/**
	 * Normalize native property definitions and legacy input aliases.
	 *
	 * @param array          $properties  Property definitions list.
	 * @param string         $old_root_id Original root element ID.
	 * @param string         $cid         Component ID.
	 * @param callable       $new_id      Receives existing definitions and returns a new ID.
	 * @return array|\WP_Error Native property definitions or error.
	 */
	public static function normalize_property_definitions( array $properties, string $old_root_id, string $cid, callable $new_id ): array|\WP_Error {
		if ( ! array_is_list( $properties ) ) {
			return new \WP_Error( 'invalid_property_definitions', 'Component property definitions must be a list.' );
		}

		$existing = $properties;
		$seen     = array();
		foreach ( $properties as &$property ) {
			if ( ! is_array( $property ) ) {
				return new \WP_Error( 'invalid_property_definitions', 'Each component property definition must be an object.' );
			}

			if ( ! array_key_exists( 'label', $property ) && array_key_exists( 'name', $property ) ) {
				$property['label'] = $property['name'];
			}
			if ( ! array_key_exists( 'desc', $property ) && array_key_exists( 'description', $property ) ) {
				$property['desc'] = $property['description'];
			}
			unset( $property['name'], $property['description'] );

			if ( ! isset( $property['id'] ) || '' === $property['id'] ) {
				$property['id'] = $new_id( $existing );
				$existing[]     = array( 'id' => $property['id'] );
			}
			if ( ! is_string( $property['id'] ) || '' === $property['id'] || isset( $seen[ $property['id'] ] ) ) {
				return new \WP_Error( 'invalid_property_definitions', 'Component property IDs must be unique non-empty strings.' );
			}
			$seen[ $property['id'] ] = true;

			if ( isset( $property['connections'] ) && ! is_array( $property['connections'] ) ) {
				return new \WP_Error( 'invalid_property_definitions', 'Property connections must be an object keyed by element ID.' );
			}
			if ( $old_root_id !== $cid && isset( $property['connections'] ) && array_key_exists( $old_root_id, $property['connections'] ) ) {
				$old_connections = $property['connections'][ $old_root_id ];
				$cid_connections = $property['connections'][ $cid ] ?? array();
				if ( ! is_array( $old_connections ) || ! is_array( $cid_connections ) ) {
					return new \WP_Error( 'invalid_property_definitions', 'Property connection settings must be arrays.' );
				}
				$property['connections'][ $cid ] = array_merge( $cid_connections, $old_connections );
				unset( $property['connections'][ $old_root_id ] );
			}
		}
		unset( $property );

		return $properties;
	}

	/**
	 * Find the native element name of a component's root.
	 *
	 * @param array $component Stored component definition.
	 * @return string|null Root element name when present.
	 */
	public static function root_element_name( array $component ): ?string {
		$cid = $component['id'] ?? null;
		if ( ! is_string( $cid ) || '' === $cid ) {
			return null;
		}
		$elements = $component['elements'] ?? null;
		if ( ! is_array( $elements ) ) {
			return null;
		}
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && ( $element['id'] ?? null ) === $cid ) {
				$name = $element['name'] ?? null;
				return is_string( $name ) && '' !== $name ? $name : null;
			}
		}
		return null;
	}

	/**
	 * Read a stored description: native `desc` when non-empty, else the pre-2.1.3 `description`.
	 *
	 * @param array $component Stored component definition.
	 * @return string Description.
	 */
	public static function description( array $component ): string {
		$desc = $component['desc'] ?? '';
		if ( is_string( $desc ) && '' !== $desc ) {
			return $desc;
		}
		$legacy = $component['description'] ?? '';
		return is_string( $legacy ) ? $legacy : '';
	}

	/**
	 * Convert an instance's already-stored values to the property-ID map without
	 * validating IDs, so values of since-removed properties survive edits.
	 * Malformed list entries are dropped; a non-array value yields an empty map.
	 *
	 * @param mixed $props Stored instance properties.
	 * @return array Property map.
	 */
	public static function stored_instance_properties( mixed $props ): array {
		if ( ! is_array( $props ) ) {
			return array();
		}
		if ( ! array_is_list( $props ) ) {
			return $props;
		}
		$values = array();
		foreach ( $props as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['id'] ?? null ) && '' !== $entry['id'] && array_key_exists( 'value', $entry ) ) {
				$values[ $entry['id'] ] = $entry['value'];
			}
		}
		return $values;
	}

	/**
	 * Convert instance values to Bricks' property-ID-keyed map.
	 *
	 * @param mixed $props     A keyed map or list of id/value objects.
	 * @param array $component Stored component definition.
	 * @return array|\WP_Error Property map or error.
	 */
	public static function normalize_instance_properties( mixed $props, array $component ): array|\WP_Error {
		if ( ! is_array( $props ) ) {
			return new \WP_Error( 'invalid_instance_properties', 'Instance properties must be a property ID map or a list of id/value objects.' );
		}

		$values = array();
		if ( array_is_list( $props ) ) {
			foreach ( $props as $entry ) {
				if ( ! is_array( $entry ) || ! is_string( $entry['id'] ?? null ) || '' === $entry['id'] || ! array_key_exists( 'value', $entry ) ) {
					return new \WP_Error( 'invalid_instance_properties', 'Each instance property list entry must contain a string id and a value.' );
				}
				$values[ $entry['id'] ] = $entry['value'];
			}
		} else {
			$values = $props;
		}

		$valid_ids   = array();
		$definitions = $component['properties'] ?? array();
		foreach ( is_array( $definitions ) ? $definitions : array() as $property ) {
			if ( is_array( $property ) && is_string( $property['id'] ?? null ) ) {
				$valid_ids[] = $property['id'];
			}
		}

		$unknown_ids = array_diff( array_keys( $values ), $valid_ids );
		if ( ! empty( $unknown_ids ) ) {
			return new \WP_Error(
				'unknown_property',
				'Unknown property ID(s): ' . implode( ', ', $unknown_ids ) . '. Valid property IDs: ' . ( empty( $valid_ids ) ? '(none)' : implode( ', ', $valid_ids ) ) . '.',
				array( 'unknown_ids' => array_values( $unknown_ids ), 'valid_ids' => $valid_ids )
			);
		}

		return $values;
	}
}
