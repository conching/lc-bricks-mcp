<?php
/**
 * Unit test: PageInspector root/parent-chain ordering and rendered parsing —
 * the tree-ordering logic behind verify:page. Verifies root sections come back
 * in flat-array order even when subtrees interleave, label/attributes extraction,
 * parent chains, and document-order parsing of rendered HTML.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';

use LCBricksMCP\MCP\Services\PageInspector;

/*
 * Flat array where the first root's subtree is interleaved BEFORE the second
 * root — the exact case where a naive "flat index" reading would misorder:
 *   sec111 section (root, label "Header", _cssId "site-header")
 *     blk11 block (child of sec111)
 *       txt11 text (grandchild)
 *   sec222 footer (root)
 *     blk22 block (child of sec222)
 */
$elements = [
	[ 'id' => 'sec111', 'name' => 'section', 'parent' => 0,        'children' => [ 'blk11' ], 'settings' => [ '_cssId' => 'site-header' ], 'label' => 'Header' ],
	[ 'id' => 'blk11',  'name' => 'block',   'parent' => 'sec111', 'children' => [ 'txt11' ], 'settings' => [] ],
	[ 'id' => 'txt11',  'name' => 'text',    'parent' => 'blk11',  'children' => [],          'settings' => [] ],
	[ 'id' => 'sec222', 'name' => 'footer',  'parent' => 0,        'children' => [ 'blk22' ], 'settings' => [] ],
	[ 'id' => 'blk22',  'name' => 'block',   'parent' => 'sec222', 'children' => [],          'settings' => [] ],
];

// --- Root sections: only roots, in flat-array order. ---
$roots = PageInspector::root_sections( $elements );
lc_assert_same( 2, count( $roots ), 'exactly two root sections' );
lc_assert_same( 'sec111', $roots[0]['element_id'], 'first root is sec111' );
lc_assert_same( 'sec222', $roots[1]['element_id'], 'second root is sec222 (subtree interleave ignored)' );
lc_assert_same( 'section', $roots[0]['name'], 'root 0 name' );
lc_assert_same( 'footer', $roots[1]['name'], 'root 1 name' );
lc_assert_same( 'Header', $roots[0]['label'], 'root 0 label extracted' );
lc_assert_same( null, $roots[1]['label'], 'root 1 has no label' );
lc_assert_same( 'site-header', $roots[0]['attributes_id'], 'root 0 DOM id override extracted' );
lc_assert_same( null, $roots[1]['attributes_id'], 'root 1 has no DOM id override' );

// --- Parent chain: element-first, root-last, is_root flag on the root. ---
$chain = PageInspector::parent_chain( $elements, 'txt11' );
lc_assert( null !== $chain, 'parent_chain returns for a valid element' );
lc_assert_same( 3, count( $chain ), 'txt11 chain has 3 links (txt11 -> blk11 -> sec111)' );
lc_assert_same( 'txt11', $chain[0]['element_id'], 'chain starts at the element itself' );
lc_assert_same( 'blk11', $chain[1]['element_id'], 'chain second is the parent' );
lc_assert_same( 'sec111', $chain[2]['element_id'], 'chain ends at the root' );
lc_assert_same( false, $chain[0]['is_root'], 'leaf is not root' );
lc_assert_same( true, $chain[2]['is_root'], 'top of chain is flagged root' );

// --- Root element's chain is just itself. ---
$root_chain = PageInspector::parent_chain( $elements, 'sec222' );
lc_assert_same( 1, count( $root_chain ), 'root element chain has a single link' );
lc_assert_same( true, $root_chain[0]['is_root'], 'root chain link is_root true' );

// --- Missing element -> null. ---
lc_assert_same( null, PageInspector::parent_chain( $elements, 'nope00' ), 'missing element yields null chain' );

// --- Cyclic parents must not hang (guarded walk). ---
$cyclic = [
	[ 'id' => 'cyc001', 'name' => 'a', 'parent' => 'cyc002', 'children' => [], 'settings' => [] ],
	[ 'id' => 'cyc002', 'name' => 'b', 'parent' => 'cyc001', 'children' => [], 'settings' => [] ],
];
$cyc_chain = PageInspector::parent_chain( $cyclic, 'cyc001' );
lc_assert( null !== $cyc_chain && count( $cyc_chain ) <= 3, 'cyclic parent chain terminates without hanging' );

// --- Empty page -> no roots. ---
lc_assert_same( 0, count( PageInspector::root_sections( [] ) ), 'empty element array has no roots' );

// --- Rendered parse: document order of id="brxe-..." across nesting levels. ---
$html  = '<div class="brxe-root">'
	. '<section id="brxe-sec111" class="brxe-section" data-x="1">'
	. '<div id="brxe-blk11" class="brx-block brxe-block">'
	. '<p id="brxe-txt11" class="brxe-text">Hi</p>'
	. '</div></section>'
	. "<footer id='brxe-sec222' class='brxe-footer'>bye</footer>"
	. '</div>';
$order = PageInspector::parse_rendered_order( $html );
lc_assert_same( 4, count( $order ), 'four brxe elements parsed from rendered HTML' );
lc_assert_same( [ 'sec111', 'blk11', 'txt11', 'sec222' ], array_map( static fn ( $o ) => $o['element_id'], $order ), 'rendered element ids in document order' );
lc_assert_same( 'brxe-section', $order[0]['class'], 'first element brxe class captured' );
lc_assert_same( 'brxe-block', $order[1]['class'], 'block brxe class captured (multi-class)' );
lc_assert_same( 'brxe-footer', $order[3]['class'], 'single-quoted attribute parsed' );

// --- No brxe ids -> empty. ---
lc_assert_same( 0, count( PageInspector::parse_rendered_order( '<div class="plain">no bricks here</div>' ) ), 'no brxe ids yields empty order' );

lc_test_done( 'VerifyPageOrderingTest' );
