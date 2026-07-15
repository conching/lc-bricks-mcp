<?php
/**
 * Unit test: PageInspector::scan_orphaned_css + attributes_id — the orphaned-CSS
 * regex + cross-reference logic. Verifies detection of selectors targeting
 * missing elements and id-overridden elements, that live un-overridden selectors
 * are ignored, source attribution, and de-duplication.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';

use LCBricksMCP\MCP\Services\PageInspector;

/*
 * Page elements:
 *   aaaaaa  plain (no override)               -> #brxe-aaaaaa is VALID
 *   bbbbbb  _attributes id override "custom"  -> #brxe-bbbbbb is ORPHANED (id_overridden)
 *   cccccc  _cssId override "my-id"           -> #brxe-cccccc is ORPHANED (id_overridden)
 *   (zzzzzz does not exist)                   -> #brxe-zzzzzz is ORPHANED (no_matching_element)
 */
$elements = [
	[ 'id' => 'aaaaaa', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => [] ],
	[
		'id'       => 'bbbbbb',
		'name'     => 'container',
		'parent'   => 0,
		'children' => [],
		'settings' => [ '_attributes' => [ [ 'id' => 'x1', 'name' => 'data-role', 'value' => 'nav' ], [ 'id' => 'x2', 'name' => 'id', 'value' => 'custom' ] ] ],
	],
	[ 'id' => 'cccccc', 'name' => 'block', 'parent' => 0, 'children' => [], 'settings' => [ '_cssId' => 'my-id' ] ],
];

// --- attributes_id() unit behavior. ---
lc_assert_same( 'custom', PageInspector::attributes_id( $elements[1]['settings'] ), '_attributes name=id override detected' );
lc_assert_same( 'my-id', PageInspector::attributes_id( $elements[2]['settings'] ), '_cssId override detected' );
lc_assert_same( null, PageInspector::attributes_id( $elements[0]['settings'] ), 'no override returns null' );
lc_assert_same( null, PageInspector::attributes_id( [ '_attributes' => [ [ 'name' => 'id', 'value' => '' ] ] ] ), 'empty id value is not an override' );

$css_sources = [
	[ 'source' => 'element:aaaaaa:_cssCustom', 'css' => '#brxe-aaaaaa { color: red; } #brxe-zzzzzz { color: blue; }' ],
	[ 'source' => 'page_settings:_cssCustom', 'css' => '#brxe-bbbbbb { display: flex; }' ],
	[ 'source' => 'global_class:gc0001:_cssCustom', 'css' => '#brxe-cccccc:hover { opacity: .5; }' ],
];

$findings = PageInspector::scan_orphaned_css( $elements, $css_sources );

// --- Exactly three orphans; the valid #brxe-aaaaaa is omitted. ---
lc_assert_same( 3, count( $findings ), 'three orphaned selectors detected (aaaaaa omitted)' );

// Index findings by element_id for assertions.
$by_eid = [];
foreach ( $findings as $f ) {
	$by_eid[ $f['element_id'] ] = $f;
}

lc_assert( isset( $by_eid['zzzzzz'] ), 'missing element zzzzzz reported' );
lc_assert_same( 'no_matching_element', $by_eid['zzzzzz']['reason'] ?? null, 'zzzzzz reason is no_matching_element' );
lc_assert_same( '#brxe-zzzzzz', $by_eid['zzzzzz']['selector'] ?? null, 'zzzzzz selector formatted' );
lc_assert_same( 'element:aaaaaa:_cssCustom', $by_eid['zzzzzz']['source'] ?? null, 'zzzzzz source attributed' );

lc_assert_same( 'id_overridden', $by_eid['bbbbbb']['reason'] ?? null, 'bbbbbb reason is id_overridden (_attributes)' );
lc_assert_same( 'id_overridden', $by_eid['cccccc']['reason'] ?? null, 'cccccc reason is id_overridden (_cssId)' );

lc_assert( ! isset( $by_eid['aaaaaa'] ), 'live, un-overridden #brxe-aaaaaa is NOT flagged' );

// --- De-duplication: same selector twice in one source -> one finding. ---
$dupe = PageInspector::scan_orphaned_css(
	$elements,
	[ [ 'source' => 's', 'css' => '#brxe-zzzzzz{a:1} #brxe-zzzzzz{b:2}' ] ]
);
lc_assert_same( 1, count( $dupe ), 'duplicate selector in one source de-duplicated' );

// --- Same selector across two different sources -> two findings (source-scoped). ---
$two = PageInspector::scan_orphaned_css(
	$elements,
	[
		[ 'source' => 's1', 'css' => '#brxe-zzzzzz{a:1}' ],
		[ 'source' => 's2', 'css' => '#brxe-zzzzzz{b:2}' ],
	]
);
lc_assert_same( 2, count( $two ), 'same selector in two sources yields two source-scoped findings' );

// --- No CSS surfaces -> no findings. ---
lc_assert_same( 0, count( PageInspector::scan_orphaned_css( $elements, [] ) ), 'no sources yields no findings' );

lc_test_done( 'OrphanedCssScanTest' );
