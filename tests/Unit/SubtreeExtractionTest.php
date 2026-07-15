<?php
/**
 * Unit test: PageInspector::extract_subtree — subtree deep-copy with ID
 * regeneration. Verifies no ID collisions, intact parent/children linkage,
 * root re-parenting to 0, settings/label preservation, and scope isolation.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';

use LCBricksMCP\MCP\Services\ElementIdGenerator;
use LCBricksMCP\MCP\Services\PageInspector;

/*
 * Source page (flat array):
 *   aaaaaa  section (root)      children: bbbbbb, cccccc
 *     bbbbbb  container         children: dddddd
 *       dddddd  heading         children: []
 *     cccccc  button            children: []
 *   eeeeee  section (root)      children: ffffff   <- unrelated, must NOT be copied
 *     ffffff  text              children: []
 */
$source = [
	[ 'id' => 'aaaaaa', 'name' => 'section',   'parent' => 0,        'children' => [ 'bbbbbb', 'cccccc' ], 'settings' => [ '_padding' => [ 'top' => '10px' ] ], 'label' => 'Hero' ],
	[ 'id' => 'bbbbbb', 'name' => 'container', 'parent' => 'aaaaaa', 'children' => [ 'dddddd' ],           'settings' => [] ],
	[ 'id' => 'dddddd', 'name' => 'heading',   'parent' => 'bbbbbb', 'children' => [],                     'settings' => [ 'text' => 'Hello' ] ],
	[ 'id' => 'cccccc', 'name' => 'button',    'parent' => 'aaaaaa', 'children' => [],                     'settings' => [ 'text' => 'Click' ] ],
	[ 'id' => 'eeeeee', 'name' => 'section',   'parent' => 0,        'children' => [ 'ffffff' ],           'settings' => [] ],
	[ 'id' => 'ffffff', 'name' => 'text',      'parent' => 'eeeeee', 'children' => [],                     'settings' => [] ],
];

$gen    = new ElementIdGenerator();
$result = PageInspector::extract_subtree( $source, 'aaaaaa', $gen );

lc_assert( null !== $result, 'extract_subtree returns a result for a valid root' );

$elements = $result['elements'];

// --- Scope: exactly the aaaaaa subtree (4 elements), none of eeeeee/ffffff. ---
lc_assert_same( 4, count( $elements ), 'copies exactly the 4 subtree elements' );
lc_assert_same( 4, count( $result['id_map'] ), 'id_map has one entry per copied element' );

// --- Every new ID is a valid 6-char lowercase alnum and unique (no collisions). ---
$new_ids = array_map( static fn ( $e ) => $e['id'], $elements );
foreach ( $new_ids as $nid ) {
	lc_assert( (bool) preg_match( '/^[a-z0-9]{6}$/', $nid ), "new id '{$nid}' is 6-char lowercase alnum" );
}
lc_assert_same( count( $new_ids ), count( array_unique( $new_ids ) ), 'no duplicate IDs among copied elements' );

// --- None of the old IDs survive in the copy. ---
$old_ids = [ 'aaaaaa', 'bbbbbb', 'cccccc', 'dddddd', 'eeeeee', 'ffffff' ];
foreach ( $new_ids as $nid ) {
	lc_assert( ! in_array( $nid, $old_ids, true ), "regenerated id '{$nid}' does not reuse a source id" );
}

// --- Root re-parented to 0 and reported. ---
$by_id    = [];
foreach ( $elements as $e ) {
	$by_id[ $e['id'] ] = $e;
}
$new_root = $result['root_id'];
lc_assert( isset( $by_id[ $new_root ] ), 'reported root_id exists in the copied elements' );
lc_assert_same( 0, $by_id[ $new_root ]['parent'], 'new root parent is 0 (int)' );

$root_children = 0;
foreach ( $elements as $e ) {
	if ( 0 === $e['parent'] ) {
		++$root_children;
	}
}
lc_assert_same( 1, $root_children, 'exactly one root element after extraction' );

// --- Linkage intact: reciprocal parent/children, no dangling references. ---
foreach ( $elements as $e ) {
	// Each child must exist and list this element as parent.
	foreach ( $e['children'] as $cid ) {
		lc_assert( isset( $by_id[ $cid ] ), "child '{$cid}' of '{$e['id']}' exists" );
		if ( isset( $by_id[ $cid ] ) ) {
			lc_assert_same( $e['id'], (string) $by_id[ $cid ]['parent'], "child '{$cid}' lists '{$e['id']}' as parent" );
		}
	}
	// Each non-root parent must exist and list this element as a child.
	if ( 0 !== $e['parent'] ) {
		$pid = (string) $e['parent'];
		lc_assert( isset( $by_id[ $pid ] ), "parent '{$pid}' of '{$e['id']}' exists" );
		if ( isset( $by_id[ $pid ] ) ) {
			lc_assert( in_array( $e['id'], $by_id[ $pid ]['children'], true ), "parent '{$pid}' lists '{$e['id']}' in children" );
		}
	}
}

// --- Settings + label deep-copied by mapping old->new via id_map. ---
$map      = $result['id_map'];
$new_hero = $by_id[ $map['aaaaaa'] ];
lc_assert_same( [ 'top' => '10px' ], $new_hero['settings']['_padding'] ?? null, 'root settings deep-copied' );
lc_assert_same( 'Hero', $new_hero['label'] ?? null, 'root label preserved' );
lc_assert_same( 'Hello', $by_id[ $map['dddddd'] ]['settings']['text'] ?? null, 'nested heading text preserved' );

// --- Structure preserved: new root has two children (mapped bbbbbb, cccccc). ---
lc_assert_same( 2, count( $new_hero['children'] ), 'root keeps its two children' );
lc_assert( in_array( $map['bbbbbb'], $new_hero['children'], true ), 'root children include mapped container' );
lc_assert( in_array( $map['cccccc'], $new_hero['children'], true ), 'root children include mapped button' );

// --- Missing root returns null. ---
lc_assert_same( null, PageInspector::extract_subtree( $source, 'nosuch', $gen ), 'missing root_id yields null' );

// --- Extracting a mid-tree node re-parents it to root and drops its ancestors. ---
$sub = PageInspector::extract_subtree( $source, 'bbbbbb', $gen );
lc_assert( null !== $sub, 'extract from a non-root subtree works' );
lc_assert_same( 2, count( $sub['elements'] ), 'bbbbbb subtree has 2 elements (container + heading)' );
$sub_by_id = [];
foreach ( $sub['elements'] as $e ) {
	$sub_by_id[ $e['id'] ] = $e;
}
lc_assert_same( 0, $sub_by_id[ $sub['root_id'] ]['parent'], 'mid-tree root re-parented to 0' );

lc_test_done( 'SubtreeExtractionTest' );
