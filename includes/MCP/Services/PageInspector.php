<?php
/**
 * Pure element-tree inspection helpers.
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
 * PageInspector — stateless, WordPress-free operations over Bricks flat element
 * arrays.
 *
 * Every method here is a pure function of its arguments (no post meta, no
 * options, no HTTP). This is deliberate: it keeps the tree-ordering, subtree
 * deep-copy, and orphaned-CSS logic unit-testable without a WordPress runtime
 * (see tests/Unit/*). Anything that needs WordPress (reading meta, fetching a
 * permalink, persisting) lives in BricksService and calls into these helpers.
 */
final class PageInspector {

	/**
	 * Return the ordered root sections of a page/template.
	 *
	 * Root order in Bricks is the order of the `parent === 0` records in the
	 * flat array — the same order the frontend renders them. For each root
	 * element this returns its id, element type name, optional editor label,
	 * and the DOM id override (from _attributes id / _cssId) when one is set —
	 * exactly the fields an AI caller needs to assert section order.
	 *
	 * @param array<int, array<string, mixed>> $elements Flat Bricks element array.
	 * @return array<int, array{element_id: string, name: string, label: string|null, attributes_id: string|null}>
	 */
	public static function root_sections( array $elements ): array {
		$sections = [];

		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) ) {
				continue;
			}
			if ( '0' !== (string) ( $element['parent'] ?? '' ) ) {
				continue;
			}

			$settings = ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : [];

			$sections[] = [
				'element_id'    => (string) $element['id'],
				'name'          => isset( $element['name'] ) ? (string) $element['name'] : 'unknown',
				'label'         => isset( $element['label'] ) && '' !== $element['label'] ? (string) $element['label'] : null,
				'attributes_id' => self::attributes_id( $settings ),
			];
		}

		return $sections;
	}

	/**
	 * Return the parent chain for a given element, element-first, root-last.
	 *
	 * Index 0 is the element itself; each subsequent entry is its parent, up to
	 * the root. Returns null when the element id is not present. Guards against
	 * cyclic parent references by capping the walk at the element count.
	 *
	 * @param array<int, array<string, mixed>> $elements   Flat Bricks element array.
	 * @param string                           $element_id Element id to trace.
	 * @return array<int, array{element_id: string, name: string, is_root: bool}>|null
	 */
	public static function parent_chain( array $elements, string $element_id ): ?array {
		$by_id = [];
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) ) {
				$by_id[ (string) $element['id'] ] = $element;
			}
		}

		if ( ! isset( $by_id[ $element_id ] ) ) {
			return null;
		}

		$chain   = [];
		$current = $element_id;
		$guard   = count( $by_id ) + 1;

		while ( isset( $by_id[ $current ] ) && $guard-- > 0 ) {
			$node      = $by_id[ $current ];
			$parent    = (string) ( $node['parent'] ?? '0' );
			$is_root   = '0' === $parent;
			$chain[]   = [
				'element_id' => $current,
				'name'       => isset( $node['name'] ) ? (string) $node['name'] : 'unknown',
				'is_root'    => $is_root,
			];

			if ( $is_root || ! isset( $by_id[ $parent ] ) ) {
				break;
			}
			$current = $parent;
		}

		return $chain;
	}

	/**
	 * Extract the DOM id override for an element from its settings.
	 *
	 * Bricks renders each element with id="brxe-<elementId>" by default. Two
	 * settings override that DOM id: a custom-attributes (_attributes) entry
	 * whose name is "id", and the _cssId field. When either is set, the rendered
	 * id differs from brxe-<elementId>, which is what orphans a generated
	 * #brxe-<id> rule. Returns the override value, or null when none is set.
	 *
	 * @param array<string, mixed> $settings Element settings array.
	 * @return string|null The overriding DOM id, or null.
	 */
	public static function attributes_id( array $settings ): ?string {
		// Custom attributes repeater: look for an entry with name === 'id'.
		if ( isset( $settings['_attributes'] ) && is_array( $settings['_attributes'] ) ) {
			foreach ( $settings['_attributes'] as $attr ) {
				if (
					is_array( $attr )
					&& isset( $attr['name'], $attr['value'] )
					&& 'id' === strtolower( trim( (string) $attr['name'] ) )
					&& '' !== trim( (string) $attr['value'] )
				) {
					return (string) $attr['value'];
				}
			}
		}

		// CSS ID field (renders directly as the element's id attribute).
		if ( isset( $settings['_cssId'] ) && is_string( $settings['_cssId'] ) && '' !== trim( $settings['_cssId'] ) ) {
			return trim( $settings['_cssId'] );
		}

		return null;
	}

	/**
	 * Parse rendered HTML into the document-order sequence of Bricks elements.
	 *
	 * Bricks renders each element root as `<tag id="brxe-<elementId>"
	 * class="brxe-<name> ...">`. This extracts, in document order, every tag
	 * carrying an id="brxe-…", returning the element id and its brxe- class.
	 *
	 * LIMIT: this is document order across ALL nesting levels — a regex cannot
	 * reliably isolate "top-level only" without a full DOM parse. It is enough
	 * to assert the relative order of specific section ids in the output; for
	 * authoritative nesting/root order use the stored-tree mode.
	 *
	 * @param string $html Rendered page HTML.
	 * @return array<int, array{element_id: string, class: string|null}>
	 */
	public static function parse_rendered_order( string $html ): array {
		$order = [];

		if ( ! preg_match_all( '/<[a-zA-Z][a-zA-Z0-9]*\b[^>]*\bid=("|\')brxe-([a-z0-9]+)\1[^>]*>/', $html, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $order;
		}

		foreach ( $matches[2] as $i => $id_cap ) {
			$tag   = $matches[0][ $i ][0];
			$class = null;
			if ( preg_match( '/\bclass=("|\')([^"\']*)\1/', $tag, $cm ) && preg_match( '/\bbrxe-([a-z0-9_-]+)/', $cm[2], $bm ) ) {
				$class = 'brxe-' . $bm[1];
			}
			$order[] = [
				'element_id' => (string) $id_cap[0],
				'class'      => $class,
			];
		}

		return $order;
	}

	/**
	 * Deep-copy a subtree rooted at $root_id, regenerating every element id.
	 *
	 * Collects the element identified by $root_id and all of its descendants
	 * (following children links), assigns each a fresh collision-free id via
	 * $generator, remaps parent/children links to the new ids, and sets the new
	 * root's parent to 0. Only structural + settings/label data is copied;
	 * component-instance keys (cid, instanceId, …) are intentionally dropped so
	 * the copy carries no dangling references into its new home.
	 *
	 * Returns null when $root_id is not present in $elements.
	 *
	 * @param array<int, array<string, mixed>> $elements  Source flat element array.
	 * @param string                           $root_id   Id of the subtree root to copy.
	 * @param ElementIdGenerator               $generator Id generator (pure; no WP).
	 * @return array{elements: array<int, array<string, mixed>>, root_id: string, id_map: array<string, string>}|null
	 */
	public static function extract_subtree( array $elements, string $root_id, ElementIdGenerator $generator ): ?array {
		$by_id = [];
		foreach ( $elements as $element ) {
			if ( is_array( $element ) && isset( $element['id'] ) ) {
				$by_id[ (string) $element['id'] ] = $element;
			}
		}

		if ( ! isset( $by_id[ $root_id ] ) ) {
			return null;
		}

		// Collect the subtree in a stable order (root first, then descendants),
		// following children links and guarding against cycles / dangling ids.
		$collected = [];
		$queue     = [ $root_id ];
		while ( $queue ) {
			$id = array_shift( $queue );
			if ( isset( $collected[ $id ] ) || ! isset( $by_id[ $id ] ) ) {
				continue;
			}
			$collected[ $id ] = $by_id[ $id ];

			$children = ( isset( $by_id[ $id ]['children'] ) && is_array( $by_id[ $id ]['children'] ) ) ? $by_id[ $id ]['children'] : [];
			foreach ( $children as $child_id ) {
				$child_id = (string) $child_id;
				if ( isset( $by_id[ $child_id ] ) && ! isset( $collected[ $child_id ] ) ) {
					$queue[] = $child_id;
				}
			}
		}

		// Assign fresh ids, accumulating so generate_unique avoids collisions
		// within the freshly minted subtree.
		$id_map   = [];
		$minted   = [];
		foreach ( array_keys( $collected ) as $old_id ) {
			$new_id            = $generator->generate_unique( $minted );
			$id_map[ $old_id ] = $new_id;
			$minted[]          = [ 'id' => $new_id ];
		}

		// Rebuild each element with remapped links.
		$new_elements = [];
		foreach ( $collected as $old_id => $element ) {
			$new_id       = $id_map[ $old_id ];
			$is_root      = $old_id === $root_id;
			$old_children = ( isset( $element['children'] ) && is_array( $element['children'] ) ) ? $element['children'] : [];

			$new_children = [];
			foreach ( $old_children as $child_id ) {
				$child_id = (string) $child_id;
				if ( isset( $id_map[ $child_id ] ) ) {
					$new_children[] = $id_map[ $child_id ];
				}
			}

			$new_element = [
				'id'       => $new_id,
				'name'     => isset( $element['name'] ) ? $element['name'] : 'div',
				'parent'   => $is_root ? 0 : $id_map[ (string) $element['parent'] ],
				'children' => $new_children,
				'settings' => ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : [],
			];

			if ( isset( $element['label'] ) && '' !== $element['label'] ) {
				$new_element['label'] = $element['label'];
			}

			$new_elements[] = $new_element;
		}

		return [
			'elements' => $new_elements,
			'root_id'  => $id_map[ $root_id ],
			'id_map'   => $id_map,
		];
	}

	/**
	 * Cross-reference #brxe-<id> selectors in CSS against the page's elements.
	 *
	 * For every `#brxe-<id>` selector found across the supplied CSS surfaces,
	 * flags it when the target id (i) does not exist on the page, or (ii) exists
	 * but has a DOM id override (_attributes id / _cssId), so the rendered id
	 * differs and the rule no longer matches. Valid selectors are omitted.
	 *
	 * @param array<int, array<string, mixed>>            $elements    Flat Bricks element array.
	 * @param array<int, array{source: string, css: string}> $css_sources CSS blobs to scan, each tagged with its source.
	 * @return array<int, array{selector: string, element_id: string, reason: string, source: string}>
	 */
	public static function scan_orphaned_css( array $elements, array $css_sources ): array {
		// Build the id set and id → override map.
		$exists    = [];
		$overrides = [];
		foreach ( $elements as $element ) {
			if ( ! is_array( $element ) || ! isset( $element['id'] ) ) {
				continue;
			}
			$eid            = (string) $element['id'];
			$exists[ $eid ] = true;
			$settings       = ( isset( $element['settings'] ) && is_array( $element['settings'] ) ) ? $element['settings'] : [];
			$override       = self::attributes_id( $settings );
			if ( null !== $override ) {
				$overrides[ $eid ] = $override;
			}
		}

		$findings = [];
		$seen     = [];
		foreach ( $css_sources as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['css'] ) || ! is_string( $entry['css'] ) ) {
				continue;
			}
			$source = isset( $entry['source'] ) ? (string) $entry['source'] : 'unknown';

			if ( ! preg_match_all( '/#brxe-([a-z0-9]+)/', $entry['css'], $matches ) ) {
				continue;
			}

			foreach ( $matches[1] as $eid ) {
				$eid = (string) $eid;

				if ( ! isset( $exists[ $eid ] ) ) {
					$reason = 'no_matching_element';
				} elseif ( isset( $overrides[ $eid ] ) ) {
					$reason = 'id_overridden';
				} else {
					continue; // Selector targets a live, un-overridden element.
				}

				$key = $source . '|#brxe-' . $eid . '|' . $reason;
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;

				$findings[] = [
					'selector'   => '#brxe-' . $eid,
					'element_id' => $eid,
					'reason'     => $reason,
					'source'     => $source,
				];
			}
		}

		return $findings;
	}
}
