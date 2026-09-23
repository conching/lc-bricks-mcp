<?php
/**
 * Unit tests for native Bricks component tree and property shapes.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

class WP_Error {
	public function __construct( public $code = '', public $message = '', public $data = null ) {}
}

function is_wp_error( $value ) { return $value instanceof WP_Error; }

require_once __DIR__ . '/../bootstrap-simple.php';

spl_autoload_register(
	static function ( string $class ): void {
		if ( str_starts_with( $class, 'LCBricksMCP\\' ) ) {
			$file = __DIR__ . '/../../includes/' . str_replace( '\\', '/', substr( $class, 12 ) ) . '.php';
			if ( is_file( $file ) ) {
				require_once $file;
			}
		}
	}
);

use LCBricksMCP\MCP\Services\BricksService;
use LCBricksMCP\MCP\Services\ComponentNormalizer;

$service = new BricksService();
$input   = [
	[ 'id' => 'chd123', 'name' => 'container', 'parent' => 'pkihro', 'children' => [ 'grd123' ], 'settings' => [ '_cssCustom' => '#brxe-chd123 { color: red; }' ] ],
	[ 'id' => 'pkihro', 'name' => 'section', 'parent' => '0', 'children' => [ 'chd123' ], 'settings' => [ '_cssCustom' => '#brxe-pkihro { display: block; }', '_cssCustomTablet' => '#brxe-pkihro { padding: 1rem; }' ] ],
	[ 'id' => 'grd123', 'name' => 'heading', 'parent' => 'chd123', 'children' => [] ],
];
$normalized = ComponentNormalizer::normalize_definition_elements( $input, 'jjdrkt' );
lc_assert( ! is_wp_error( $normalized ), 'valid tree normalizes' );
$elements = $normalized['elements'];
lc_assert_same( 'pkihro', $normalized['old_root_id'], 'old root ID returned for connections' );
lc_assert_same( [ 'jjdrkt', 'chd123', 'grd123' ], array_column( $elements, 'id' ), 'root moved first without reordering descendants' );
lc_assert_same( 0, $elements[0]['parent'], 'root parent normalized to integer zero' );
lc_assert_same( 'jjdrkt', $elements[1]['parent'], 'direct child parent remapped' );
lc_assert_same( 'chd123', $elements[2]['parent'], 'grandchild parent left unchanged' );
lc_assert_same( [], $elements[2]['settings'], 'missing settings defaults to empty array' );
lc_assert_same( true, $service->validate_element_linkage( $elements ), 'normalized tree passes BricksService linkage validation' );
lc_assert_same( '.brxe-jjdrkt { display: block; }', $elements[0]['settings']['_cssCustom'], 'root custom CSS selector remapped to component class' );
lc_assert_same( '.brxe-jjdrkt { padding: 1rem; }', $elements[0]['settings']['_cssCustomTablet'], 'responsive custom CSS selector remapped' );
lc_assert_same( '.brxe-chd123 { color: red; }', $elements[1]['settings']['_cssCustom'], 'child custom CSS selector becomes class' );

foreach ( [ 0, '0', '', null ] as $root_parent ) {
	$tree = ComponentNormalizer::normalize_definition_elements( [ [ 'id' => 'pkihro', 'name' => 'heading', 'parent' => $root_parent ] ], 'jjdrkt' );
	lc_assert( ! is_wp_error( $tree ) && $tree['elements'][0]['parent'] === 0 && $tree['elements'][0]['children'] === [], 'root parent variant normalizes' );
}
$tree = ComponentNormalizer::normalize_definition_elements( [ [ 'id' => 'pkihro', 'name' => 'heading' ] ], 'jjdrkt' );
lc_assert( ! is_wp_error( $tree ) && $tree['elements'][0]['parent'] === 0, 'missing parent counts as root' );
$tree = ComponentNormalizer::normalize_definition_elements( [ [ 'id' => 'pkihro', 'name' => 'section', 'children' => [ 'pkihro' ] ] ], 'jjdrkt' );
lc_assert_same( [ 'jjdrkt' ], $tree['elements'][0]['children'], 'children references to old root ID are remapped' );

foreach ( [
	[],
	[ [ 'id' => 'pkihro', 'name' => 'heading', 'parent' => 'ghost1' ] ],
	[ [ 'id' => 'pkihro', 'name' => 'heading', 'parent' => 0 ], [ 'id' => 'other1', 'name' => 'text', 'parent' => '' ] ],
	[ [ 'id' => 'pkihro', 'name' => 'heading', 'parent' => 0 ], [ 'id' => 'jjdrkt', 'name' => 'text', 'parent' => 'pkihro' ] ],
] as $bad_tree ) {
	$error = ComponentNormalizer::normalize_definition_elements( $bad_tree, 'jjdrkt' );
	lc_assert( is_wp_error( $error ) && $error->code === 'invalid_component_tree', 'zero/multiple roots or cid collision rejected' );
}

$definitions = ComponentNormalizer::normalize_property_definitions(
	[
		[ 'name' => 'Title', 'description' => 'Heading text', 'type' => 'text', 'connections' => [ 'pkihro' => [ 'text' ] ] ],
		[ 'id' => 'prop02', 'label' => 'Native', 'name' => 'Alias', 'desc' => 'Keep this', 'description' => 'Alias description', 'type' => 'image', 'multiple' => true, 'connections' => [ 'chd123' => [ 'image' ] ] ],
	],
	'pkihro',
	'jjdrkt',
	static fn( array $existing ): string => 'prop01'
);
lc_assert( ! is_wp_error( $definitions ), 'property definitions normalize' );
lc_assert_same( 'prop01', $definitions[0]['id'], 'missing property ID generated' );
lc_assert_same( 'Title', $definitions[0]['label'], 'name input becomes native label' );
lc_assert_same( 'Heading text', $definitions[0]['desc'], 'description input becomes native desc' );
lc_assert( ! isset( $definitions[0]['name'], $definitions[0]['description'] ), 'input aliases removed from stored definition' );
lc_assert_same( [ 'jjdrkt' => [ 'text' ] ], $definitions[0]['connections'], 'root connections re-keyed to cid' );
lc_assert_same( [ 'chd123' => [ 'image' ] ], $definitions[1]['connections'], 'non-root connections unchanged' );
lc_assert_same( 'Native', $definitions[1]['label'], 'native label takes precedence over input alias' );
lc_assert_same( 'Keep this', $definitions[1]['desc'], 'native desc takes precedence over input alias' );
lc_assert_same( true, $definitions[1]['multiple'], 'native optional key passed through' );
$bad_definitions = ComponentNormalizer::normalize_property_definitions( [ 'prop01' => [ 'label' => 'Title' ] ], 'pkihro', 'jjdrkt', static fn() => 'prop02' );
lc_assert( is_wp_error( $bad_definitions ) && $bad_definitions->code === 'invalid_property_definitions', 'property definitions require a list' );

$component = [ 'id' => 'jjdrkt', 'elements' => $elements, 'properties' => $definitions ];
lc_assert_same( 'section', ComponentNormalizer::root_element_name( $component ), 'root name found by id equal to cid' );
lc_assert_same( null, ComponentNormalizer::root_element_name( [ 'id' => 'missing', 'elements' => $elements ] ), 'missing component root has no name' );
lc_assert_same( [ 'prop01' => 'New', 'prop02' => [ 'id' => 1 ] ], ComponentNormalizer::normalize_instance_properties( [ 'prop01' => 'New', 'prop02' => [ 'id' => 1 ] ], $component ), 'instance map stored as-is, including nested value' );
lc_assert_same( [ 'prop01' => 'New' ], ComponentNormalizer::normalize_instance_properties( [ [ 'id' => 'prop01', 'value' => 'New' ] ], $component ), 'id/value list converted to keyed map' );
lc_assert_same( [], ComponentNormalizer::normalize_instance_properties( [], $component ), 'empty properties stay empty' );
$unknown = ComponentNormalizer::normalize_instance_properties( [ 'bogus1' => 'No' ], $component );
lc_assert( is_wp_error( $unknown ) && $unknown->code === 'unknown_property' && str_contains( $unknown->message, 'prop01' ) && str_contains( $unknown->message, 'prop02' ), 'unknown property error lists valid IDs' );
foreach ( [ 'bad', [ 'junk' ], [ [ 'id' => [ 'prop01' ], 'value' => 'x' ] ], [ [ 'id' => 'prop01' ] ], [ [ [ 'id' => 'prop01', 'value' => 'x' ] ] ] ] as $bad_props ) {
	$error = ComponentNormalizer::normalize_instance_properties( $bad_props, $component );
	lc_assert( is_wp_error( $error ) && $error->code === 'invalid_instance_properties', 'scalar and nested junk property inputs rejected' );
}

// Legacy repair leaves a healthy tree untouched and ignores unlisted strays.
$healthy = [ [ 'id' => 'abc123', 'name' => 'section', 'parent' => 0, 'children' => [ 'kid001' ] ], [ 'id' => 'kid001', 'name' => 'text', 'parent' => 'abc123', 'children' => [] ] ];
$r = ComponentNormalizer::repair_legacy_root_links( $healthy, 'abc123' );
lc_assert( 0 === $r['repaired'] && $r['elements'] === $healthy && 'abc123' === $r['old_root_id'], 'repair is a no-op on a healthy tree' );
$stray = [ [ 'id' => 'abc123', 'name' => 'section', 'parent' => 0, 'children' => [] ], [ 'id' => 'kid001', 'name' => 'text', 'parent' => 'gone01', 'children' => [] ] ];
$r = ComponentNormalizer::repair_legacy_root_links( $stray, 'abc123' );
lc_assert( 0 === $r['repaired'] && 'gone01' === $r['elements'][1]['parent'], 'repair leaves a child the root does not list' );

lc_test_done( 'ComponentNormalizerTest' );
