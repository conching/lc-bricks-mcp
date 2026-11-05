<?php
/**
 * Handler and Bricks render-lookup regression tests for components.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

define( 'BRICKS_VERSION', '2.4.1' );

$GLOBALS['component_options'] = [];
$GLOBALS['component_pages']   = [];
$GLOBALS['component_writes']  = 0;

function get_option( $key, $default = false ) { return $GLOBALS['component_options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['component_options'][ $key ] = $value; ++$GLOBALS['component_writes']; return true; }
function get_current_user_id() { return 42; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function __( $message, $domain = null ) { return $message; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }

class WP_Error {
	public function __construct( public $code = '', public $message = '', public $data = null ) {}
}

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

use LCBricksMCP\MCP\Router;
use LCBricksMCP\MCP\Services\BricksService;

class RenderHeadingElement {}
class RenderSectionElement {}

class ComponentPageService extends BricksService {
	public function __construct() {}
	public function get_elements( int $post_id ): array { return $GLOBALS['component_pages'][ $post_id ] ?? []; }
	public function save_elements( int $post_id, array $elements, ?array &$persistence = null ): true|\WP_Error {
		$valid = $this->validate_element_linkage( $elements );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$GLOBALS['component_pages'][ $post_id ] = $elements;
		++$GLOBALS['component_writes'];
		return true;
	}
}

$reflection = new ReflectionClass( Router::class );
$router     = $reflection->newInstanceWithoutConstructor();
$service    = new ComponentPageService();
$reflection->getProperty( 'bricks_service' )->setValue( $router, $service );

function component_call( Router $router, string $method, array $args ): array|WP_Error {
	return ( new ReflectionMethod( Router::class, $method ) )->invoke( $router, $args );
}

/**
 * Reproduce Bricks' name-to-class and cid-to-definition lookup.
 */
function bricks_render_lookup( array $instance, array $components, array $registered ): ?array {
	$name       = $instance['name'];
	$class_name = $registered[ $name ]['class'] ?? $name;
	if ( ! class_exists( $class_name ) ) {
		return null;
	}
	$index = array_search( $instance['cid'], array_column( $components, 'id' ), true );
	if ( false === $index ) {
		return null;
	}
	$component = $components[ $index ];
	$root      = null;
	foreach ( $component['elements'] as $element ) {
		if ( $element['id'] === $instance['cid'] ) {
			$root = $element;
			break;
		}
	}
	if ( null === $root ) {
		return null;
	}
	$children = [];
	foreach ( $root['children'] as $child_id ) {
		foreach ( $component['elements'] as $element ) {
			if ( $element['id'] === $child_id && $element['parent'] === $root['id'] ) {
				$children[] = $element;
				break;
			}
		}
	}
	if ( count( $children ) !== count( $root['children'] ) ) {
		return null;
	}
	return [ 'class' => $class_name, 'root' => $root, 'children' => $children ];
}

$heading_create = component_call( $router, 'tool_create_component', [
	'label' => 'One Heading',
	'description' => 'Renderable heading',
	'elements' => [ [ 'id' => 'pkihro', 'name' => 'heading', 'parent' => 0, 'settings' => [ 'text' => 'Hello' ] ] ],
] );
lc_assert( ! is_wp_error( $heading_create ), 'create one-heading component through Router' );
$heading_cid = $heading_create['id'];
$definitions = get_option( 'bricks_components' );
lc_assert_same( [ 0 ], array_keys( $definitions ), 'component option remains zero-indexed list for Bricks lookup' );
lc_assert_same( $heading_cid, $definitions[0]['elements'][0]['id'], 'stored one-heading root ID equals cid' );
lc_assert_same( 'One Heading', $definitions[0]['elements'][0]['label'], 'root carries component label' );
lc_assert_same( 'Renderable heading', $definitions[0]['desc'], 'description input stored as native desc' );
lc_assert_same( 42, $definitions[0]['_user_id'], 'author metadata stored' );
lc_assert_same( '2.4.1', $definitions[0]['_version'], 'Bricks version metadata stored' );
lc_assert( is_int( $definitions[0]['_created'] ) && $definitions[0]['_created'] <= time(), 'creation timestamp stored' );
$listing = component_call( $router, 'tool_list_components', [] );
lc_assert_same( 'Renderable heading', $listing['components'][0]['description'], 'list exposes native desc as description' );
$details = component_call( $router, 'tool_get_component', [ 'component_id' => $heading_cid ] );
lc_assert_same( 'Renderable heading', $details['description'], 'get exposes native desc as description' );

$heading_instance_result = component_call( $router, 'tool_instantiate_component', [ 'component_id' => $heading_cid, 'post_id' => 100 ] );
lc_assert( ! is_wp_error( $heading_instance_result ), 'instantiate one-heading component' );
$heading_instance = $GLOBALS['component_pages'][100][0];
lc_assert_same( 'heading', $heading_instance['name'], 'instance name is registered root element name' );
lc_assert_same( $heading_cid, $heading_instance['cid'], 'instance cid is component ID' );
lc_assert( ! array_key_exists( 'properties', $heading_instance ) && ! array_key_exists( 'slotChildren', $heading_instance ), 'empty optional instance maps omitted' );
$registered = [ 'heading' => [ 'class' => RenderHeadingElement::class ], 'section' => [ 'class' => RenderSectionElement::class ] ];
$rendered   = bricks_render_lookup( $heading_instance, $definitions, $registered );
lc_assert_same( RenderHeadingElement::class, $rendered['class'] ?? null, 'Bricks render lookup resolves new instance to registered element class' );
$legacy_instance         = $heading_instance;
$legacy_instance['name'] = $heading_cid;
lc_assert_same( null, bricks_render_lookup( $legacy_instance, $definitions, $registered ), 'pre-fix name=cid cannot resolve an element class' );

$before = $GLOBALS['component_writes'];
$invalid_create = component_call( $router, 'tool_create_component', [ 'label' => 'Bad', 'elements' => [] ] );
lc_assert( is_wp_error( $invalid_create ) && $invalid_create->code === 'invalid_component_tree' && $GLOBALS['component_writes'] === $before, 'zero-root create rejects without write' );

$second_create = component_call( $router, 'tool_create_component', [
	'label' => 'Card',
	'elements' => [
		[ 'id' => 'chd123', 'name' => 'heading', 'parent' => 'pkihro', 'children' => [], 'settings' => [ 'text' => 'Old' ] ],
		[ 'id' => 'pkihro', 'name' => 'section', 'parent' => 0, 'children' => [ 'chd123' ] ],
	],
	'properties' => [ [ 'id' => 'prop01', 'name' => 'Title', 'type' => 'text', 'connections' => [ 'pkihro' => [ 'text' ] ] ], [ 'id' => '123456', 'label' => 'Numeric ID', 'type' => 'text' ], [ 'name' => 'Generated', 'type' => 'text', 'connections' => [ 'pkihro' => [ 'text' ] ] ] ],
] );
lc_assert( ! is_wp_error( $second_create ), 'create with root second and property definitions' );
$card_cid   = $second_create['id'];
$definitions = get_option( 'bricks_components' );
$card       = $definitions[1];
lc_assert_same( $card_cid, $card['elements'][1]['parent'], 'create remaps child parent from pkihro to cid' );
lc_assert_same( [ $card_cid => [ 'text' ] ], $card['properties'][0]['connections'], 'create re-keys root property connection' );
lc_assert( (bool) preg_match( '/^[a-z0-9]{6}$/', $card['properties'][2]['id'] ), 'handler generates six-character property ID' );
lc_assert_same( [ $card_cid => [ 'text' ] ], $card['properties'][2]['connections'], 'generated property root connection re-keyed' );
$card_instance = [ 'id' => 'inst01', 'name' => 'section', 'cid' => $card_cid ];
lc_assert_same( 'chd123', bricks_render_lookup( $card_instance, $definitions, $registered )['children'][0]['id'] ?? null, 'Bricks root child lookup resolves remapped parent link' );

$created_at = $card['_created'];
$author_id  = $card['_user_id'];
$update = component_call( $router, 'tool_update_component', [
	'component_id' => $card_cid,
	'label' => 'Updated Card',
	'description' => 'Updated description',
	'elements' => [
		[ 'id' => 'chd123', 'name' => 'heading', 'parent' => 'newr01', 'children' => [], 'settings' => [ 'text' => 'New' ] ],
		[ 'id' => 'newr01', 'name' => 'section', 'parent' => '', 'children' => [ 'chd123', 'slot01' ] ],
		[ 'id' => 'slot01', 'name' => 'slot', 'parent' => 'newr01', 'children' => [] ],
	],
	'properties' => [ [ 'id' => 'prop01', 'label' => 'Title', 'type' => 'text', 'connections' => [ 'newr01' => [ 'text' ] ] ], [ 'id' => '123456', 'label' => 'Numeric ID', 'type' => 'text' ] ],
] );
lc_assert( ! is_wp_error( $update ), 'update normalizes new root ID and validates tree' );
$card = get_option( 'bricks_components' )[1];
lc_assert_same( [ $card_cid, 'chd123', 'slot01' ], array_column( $card['elements'], 'id' ), 'update moves new root first' );
lc_assert_same( $card_cid, $card['elements'][1]['parent'], 'update remaps child parent' );
lc_assert_same( [ $card_cid => [ 'text' ] ], $card['properties'][0]['connections'], 'update re-keys property connection' );
lc_assert_same( 'Updated Card', $card['elements'][0]['label'], 'update sets native root label' );
lc_assert_same( 'Updated description', $card['desc'], 'update stores native desc' );
lc_assert_same( $created_at, $card['_created'], 'update preserves creation time' );
lc_assert_same( $author_id, $card['_user_id'], 'update preserves author ID' );

$before = $GLOBALS['component_writes'];
$bad_update = component_call( $router, 'tool_update_component', [
	'component_id' => $card_cid,
	'elements' => [ [ 'id' => 'aabbcc', 'name' => 'heading', 'parent' => 0 ], [ 'id' => 'ddeeff', 'name' => 'text', 'parent' => null ] ],
] );
lc_assert( is_wp_error( $bad_update ) && $bad_update->code === 'invalid_component_tree' && $GLOBALS['component_writes'] === $before, 'multiple-root update rejects without write' );

$before = $GLOBALS['component_writes'];
$unknown = component_call( $router, 'tool_instantiate_component', [ 'component_id' => $card_cid, 'post_id' => 200, 'properties' => [ 'unknown' => 'No' ] ] );
lc_assert( is_wp_error( $unknown ) && $unknown->code === 'unknown_property' && $GLOBALS['component_writes'] === $before, 'unknown instance property rejects before page write' );
$instance_result = component_call( $router, 'tool_instantiate_component', [ 'component_id' => $card_cid, 'post_id' => 200, 'properties' => [ [ 'id' => 'prop01', 'value' => 'New title' ] ] ] );
lc_assert( ! is_wp_error( $instance_result ), 'instantiate accepts id/value property list' );
$instance_id = $instance_result['instance_id'];
lc_assert_same( [ 'prop01' => 'New title' ], $GLOBALS['component_pages'][200][0]['properties'], 'instance property list stored as ID map' );
lc_assert_same( 'section', $GLOBALS['component_pages'][200][0]['name'], 'instance of section-root component uses section name' );
$map_instance = component_call( $router, 'tool_instantiate_component', [ 'component_id' => $card_cid, 'post_id' => 203, 'properties' => [ 'prop01' => 'Map title' ] ] );
lc_assert( ! is_wp_error( $map_instance ) && $GLOBALS['component_pages'][203][0]['properties'] === [ 'prop01' => 'Map title' ], 'instantiate accepts keyed property map unchanged' );
$before = $GLOBALS['component_writes'];
$null_instance = component_call( $router, 'tool_instantiate_component', [ 'component_id' => $card_cid, 'post_id' => 203, 'properties' => null ] );
lc_assert( is_wp_error( $null_instance ) && $null_instance->code === 'invalid_instance_properties' && $GLOBALS['component_writes'] === $before, 'invalid instance properties reject without write' );

$GLOBALS['component_pages'][201] = [ [ 'id' => 'leg001', 'name' => $card_cid, 'cid' => $card_cid, 'parent' => 0, 'children' => [], 'settings' => [], 'properties' => [ [ 'id' => 'prop01', 'value' => 'Old title' ] ] ] ];
$property_update = component_call( $router, 'tool_update_instance_properties', [ 'post_id' => 201, 'instance_id' => 'leg001', 'properties' => [ '123456' => 'Number value' ] ] );
lc_assert( ! is_wp_error( $property_update ), 'update_properties accepts keyed map on legacy instance' );
lc_assert_same( 'section', $GLOBALS['component_pages'][201][0]['name'], 'update_properties repairs legacy name=cid' );
lc_assert_same( [ 'prop01' => 'Old title', 123456 => 'Number value' ], $GLOBALS['component_pages'][201][0]['properties'], 'update_properties normalizes stored list and preserves numeric property ID' );
$property_update_list = component_call( $router, 'tool_update_instance_properties', [ 'post_id' => 201, 'instance_id' => 'leg001', 'properties' => [ [ 'id' => 'prop01', 'value' => 'Updated title' ] ] ] );
lc_assert( ! is_wp_error( $property_update_list ) && $GLOBALS['component_pages'][201][0]['properties'] === [ 'prop01' => 'Updated title', 123456 => 'Number value' ], 'update_properties accepts incoming id/value list and merges by ID' );
$before = $GLOBALS['component_writes'];
$bad_property_update = component_call( $router, 'tool_update_instance_properties', [ 'post_id' => 201, 'instance_id' => 'leg001', 'properties' => [ 'bogus1' => 'No' ] ] );
lc_assert( is_wp_error( $bad_property_update ) && $bad_property_update->code === 'unknown_property' && $GLOBALS['component_writes'] === $before, 'unknown update_properties ID rejects without write' );

$GLOBALS['component_pages'][202] = [ [ 'id' => 'leg002', 'name' => $card_cid, 'cid' => $card_cid, 'parent' => 0, 'children' => [], 'settings' => [], 'properties' => [ [ 'id' => 'prop01', 'value' => 'Slot title' ] ] ] ];
$fill = component_call( $router, 'tool_fill_slot', [ 'post_id' => 202, 'instance_id' => 'leg002', 'slot_id' => 'slot01', 'slot_elements' => [ [ 'id' => 'fill01', 'name' => 'text', 'parent' => 0, 'children' => [], 'settings' => [ 'text' => 'Slot content' ] ] ] ] );
lc_assert( ! is_wp_error( $fill ), 'fill_slot saves a linked slot content element' );
lc_assert_same( 'section', $GLOBALS['component_pages'][202][0]['name'], 'fill_slot repairs legacy name=cid' );
lc_assert_same( [ 'prop01' => 'Slot title' ], $GLOBALS['component_pages'][202][0]['properties'], 'fill_slot normalizes legacy property list' );
// Native shape: slot content has parent = instance and is listed in slotChildren, not children.
lc_assert_same( [], $GLOBALS['component_pages'][202][0]['children'], 'fill_slot leaves instance children empty (native shape)' );
lc_assert_same( [ 'fill01' ], $GLOBALS['component_pages'][202][0]['slotChildren']['slot01'], 'fill_slot writes slotChildren map' );
lc_assert_same( 'leg002', $GLOBALS['component_pages'][202][1]['parent'], 'slot content parent is the instance' );
$orphan_slot_page = $GLOBALS['component_pages'][202];
$orphan_slot_page[0]['slotChildren'] = [ 'slot01' => [] ];
$orphan_check = $service->validate_element_linkage( $orphan_slot_page );
lc_assert( is_wp_error( $orphan_check ) && $orphan_check->code === 'invalid_element_structure', 'linkage rejects instance child listed in neither children nor slotChildren' );
$plain_parent_page = [
	[ 'id' => 'sec001', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => [], 'slotChildren' => [ 'slot01' => [ 'txt001' ] ] ],
	[ 'id' => 'txt001', 'name' => 'text', 'parent' => 'sec001', 'children' => [], 'settings' => [] ],
];
$plain_check = $service->validate_element_linkage( $plain_parent_page );
lc_assert( is_wp_error( $plain_check ), 'slotChildren only satisfies linkage on a component instance (cid set)' );

$GLOBALS['component_options']['bricks_components'][] = [ 'id' => 'bad123', 'label' => 'Broken', 'elements' => [], 'properties' => [] ];
$before = $GLOBALS['component_writes'];
$missing_root = component_call( $router, 'tool_instantiate_component', [ 'component_id' => 'bad123', 'post_id' => 204 ] );
lc_assert( is_wp_error( $missing_root ) && $missing_root->code === 'component_root_missing' && $GLOBALS['component_writes'] === $before, 'missing definition root rejects before page write' );
array_pop( $GLOBALS['component_options']['bricks_components'] );

$legacy_definition = [ 'id' => 'old123', 'label' => 'Legacy', 'description' => 'Old field', 'elements' => [ [ 'id' => 'old123', 'name' => 'heading', 'parent' => 0, 'children' => [] ] ], 'properties' => [] ];
$GLOBALS['component_options']['bricks_components'][] = $legacy_definition;
$listing = component_call( $router, 'tool_list_components', [] );
$details = component_call( $router, 'tool_get_component', [ 'component_id' => 'old123' ] );
lc_assert_same( 'Old field', $listing['components'][2]['description'], 'list reads pre-2.1.3 description' );
lc_assert_same( 'Old field', $details['description'], 'get reads pre-2.1.3 description' );

// Review round 1: legacy description, root label by ID, stale stored properties, slot trees.
$GLOBALS['component_options']['bricks_components'][] = [
	'id' => 'mix123', 'label' => 'Mixed', 'desc' => '', 'description' => 'Legacy text',
	'elements' => [
		[ 'id' => 'kid001', 'name' => 'heading', 'label' => 'Kid', 'parent' => 'mix123', 'children' => [], 'settings' => [] ],
		[ 'id' => 'mix123', 'name' => 'section', 'label' => 'Mixed', 'parent' => 0, 'children' => [ 'kid001' ], 'settings' => [] ],
	],
	'properties' => [],
];
$mixed_update = component_call( $router, 'tool_update_component', [ 'component_id' => 'mix123', 'label' => 'Renamed' ] );
$mixed        = end( $GLOBALS['component_options']['bricks_components'] );
lc_assert( ! is_wp_error( $mixed_update ), 'label-only update on stored component whose root is not first' );
lc_assert_same( 'Legacy text', $mixed['desc'] ?? null, 'label-only update migrates non-empty legacy description into empty desc' );
lc_assert( ! array_key_exists( 'description', $mixed ), 'legacy description key removed after migration' );
lc_assert_same( 'Renamed', $mixed['elements'][1]['label'], 'update labels the root found by ID' );
lc_assert_same( 'Kid', $mixed['elements'][0]['label'], 'update leaves the non-root first element label alone' );
array_pop( $GLOBALS['component_options']['bricks_components'] );

$GLOBALS['component_pages'][205] = [ [ 'id' => 'stl001', 'name' => 'section', 'cid' => $card_cid, 'parent' => 0, 'children' => [], 'settings' => [], 'properties' => [ 'gone01' => 'Removed prop value', 'prop01' => 'Keep' ] ] ];
$stale_update = component_call( $router, 'tool_update_instance_properties', [ 'post_id' => 205, 'instance_id' => 'stl001', 'properties' => [ 'prop01' => 'Changed' ] ] );
lc_assert( ! is_wp_error( $stale_update ), 'update_properties tolerates a stored value for a removed property' );
lc_assert_same( [ 'gone01' => 'Removed prop value', 'prop01' => 'Changed' ], $GLOBALS['component_pages'][205][0]['properties'], 'stale stored value preserved, valid one updated' );
$stale_new = component_call( $router, 'tool_update_instance_properties', [ 'post_id' => 205, 'instance_id' => 'stl001', 'properties' => [ 'gone01' => 'x' ] ] );
lc_assert( is_wp_error( $stale_new ) && $stale_new->code === 'unknown_property', 'incoming value for a removed property is still rejected' );

$GLOBALS['component_pages'][206] = [ [ 'id' => 'nst001', 'name' => 'section', 'cid' => $card_cid, 'parent' => 0, 'children' => [], 'settings' => [], 'properties' => [ 'gone01' => 'stale' ] ] ];
$nested_fill = component_call( $router, 'tool_fill_slot', [ 'post_id' => 206, 'instance_id' => 'nst001', 'slot_id' => 'slot01', 'slot_elements' => [
	[ 'id' => 'wrap01', 'name' => 'div', 'parent' => 0, 'children' => [ 'txt002' ], 'settings' => [] ],
	[ 'id' => 'txt002', 'name' => 'text', 'parent' => 'wrap01', 'children' => [], 'settings' => [] ],
] ] );
lc_assert( ! is_wp_error( $nested_fill ), 'fill_slot saves nested content on an instance with a stale stored property' );
lc_assert_same( [ 'wrap01' ], $GLOBALS['component_pages'][206][0]['slotChildren']['slot01'] ?? null, 'only the top-level filler is listed in slotChildren' );
lc_assert_same( [ 'wrap01', 'txt002' ], is_array( $nested_fill ) ? $nested_fill['element_ids'] : null, 'fill_slot response lists every added element' );
$second_fill = component_call( $router, 'tool_fill_slot', [ 'post_id' => 206, 'instance_id' => 'nst001', 'slot_id' => 'slot01', 'slot_elements' => [ [ 'id' => 'txt003', 'name' => 'text', 'parent' => 0, 'children' => [], 'settings' => [] ] ] ] );
lc_assert( ! is_wp_error( $second_fill ), 'second fill_slot on the same slot saves' );
lc_assert_same( [ 'wrap01', 'txt003' ], $GLOBALS['component_pages'][206][0]['slotChildren']['slot01'] ?? null, 'second fill_slot appends to the slot' );

$nested_listed = $GLOBALS['component_pages'][206];
$nested_listed[0]['slotChildren']['slot01'][] = 'txt002';
lc_assert( is_wp_error( $service->validate_element_linkage( $nested_listed ) ), 'linkage rejects a nested element listed in slotChildren' );
$dangling = $GLOBALS['component_pages'][206];
$dangling[0]['slotChildren']['slot01'][] = 'zzz999';
lc_assert( true === $service->validate_element_linkage( $dangling ), 'linkage tolerates a slotChildren ID that no longer exists' );
$cycle = [
	[ 'id' => 'cyi001', 'name' => 'section', 'cid' => $card_cid, 'parent' => 'cyc002', 'children' => [], 'settings' => [], 'slotChildren' => [ 'slot01' => [ 'cyc002' ] ] ],
	[ 'id' => 'cyc002', 'name' => 'div', 'parent' => 'cyi001', 'children' => [ 'cyi001' ], 'settings' => [] ],
];
$cycle_check = $service->validate_element_linkage( $cycle );
lc_assert( is_wp_error( $cycle_check ) && str_contains( (string) $cycle_check->message, 'Cycle' ), 'cycle through a slotChildren edge is detected' );

// Review round 2: legacy stored trees stay editable (and get repaired); root type is fixed.
$GLOBALS['component_options']['bricks_components'][] = [
	'id' => 'leg555', 'label' => 'Legacy Card', 'description' => '',
	'elements' => [
		[ 'id' => 'leg555', 'name' => 'section', 'parent' => 0, 'children' => [ 'lk0001' ], 'settings' => [] ],
		[ 'id' => 'lk0001', 'name' => 'heading', 'parent' => 'pkold1', 'children' => [], 'settings' => [] ],
	],
	'properties' => [ [ 'id' => 'lp0001', 'label' => 'Title', 'type' => 'text', 'connections' => [ 'pkold1' => [ 'text' ] ] ] ],
];
$legacy_label = component_call( $router, 'tool_update_component', [ 'component_id' => 'leg555', 'label' => 'Legacy Card 2' ] );
$legacy_card  = end( $GLOBALS['component_options']['bricks_components'] );
lc_assert( ! is_wp_error( $legacy_label ), 'label-only update succeeds on a tree stored by 2.1.2' );
lc_assert_same( 1, is_array( $legacy_label ) ? ( $legacy_label['repaired_links'] ?? null ) : null, 'update reports the repaired child link' );
lc_assert_same( 'leg555', $legacy_card['elements'][1]['parent'], 'legacy child parent relinked to the component ID' );
lc_assert_same( [ 'leg555' => [ 'text' ] ], $legacy_card['properties'][0]['connections'], 'connection keyed by the vanished root ID re-keyed to the component ID' );
lc_assert( true === $service->validate_element_linkage( $legacy_card['elements'] ), 'repaired legacy tree passes linkage validation' );
$again = component_call( $router, 'tool_update_component', [ 'component_id' => 'leg555', 'category' => 'x' ] );
lc_assert( ! is_wp_error( $again ) && ! array_key_exists( 'repaired_links', $again ), 'a repaired tree reports no further repairs' );
array_pop( $GLOBALS['component_options']['bricks_components'] );

$before = $GLOBALS['component_writes'];
$type_change = component_call( $router, 'tool_update_component', [
	'component_id' => $card_cid,
	'elements' => [ [ 'id' => 'hdr001', 'name' => 'heading', 'parent' => 0, 'children' => [], 'settings' => [] ] ],
] );
lc_assert( is_wp_error( $type_change ) && $type_change->code === 'root_type_change' && $GLOBALS['component_writes'] === $before, 'root element type change rejected without write' );

lc_test_done( 'ComponentRenderTest' );
