<?php
/**
 * Unit test: component and list behavior across the hardening and component fixes.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $value ): string {
		$value = preg_replace( '#<(script|iframe)\b[^>]*>.*?</\1>#is', '', $value );
		return preg_replace( '/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $value );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/MCP/Services/ElementIdGenerator.php';
require_once __DIR__ . '/../../includes/MCP/Services/ElementNormalizer.php';
require_once __DIR__ . '/../../includes/MCP/Services/ElementPolicy.php';

use LCBricksMCP\MCP\Services\ElementIdGenerator;
use LCBricksMCP\MCP\Services\ElementNormalizer;
use LCBricksMCP\MCP\Services\ElementPolicy;

$normalizer = new ElementNormalizer( new ElementIdGenerator() );
$policy     = new ElementPolicy();

// Lists survive native sanitation.
$images = [ [ 'id' => 12, 'url' => '/a.jpg' ], [ 'id' => 13, 'url' => '/b.jpg' ] ];
$out    = $normalizer->sanitize_flat_elements(
	[ [ 'id' => 'gal001', 'name' => 'image-gallery', 'parent' => 0, 'children' => [], 'settings' => [ 'images' => $images ] ] ]
);
lc_assert_same( $images, $out[0]['settings']['images'], 'gallery images are kept' );

$out = $normalizer->sanitize_flat_elements(
	[ [ 'id' => 'loop01', 'name' => 'div', 'parent' => 0, 'children' => [], 'settings' => [ 'query' => [ 'post_type' => [ 'post', 'page' ] ] ] ] ]
);
lc_assert_same( [ 'post', 'page' ], $out[0]['settings']['query']['post_type'], 'array query values are kept' );

$out = $normalizer->sanitize_flat_elements(
	[ [ 'id' => 'rep001', 'name' => 'accordion', 'parent' => 0, 'children' => [], 'settings' => [ 'items' => [ [ 'title' => '<script>x</script>Hi' ] ] ] ] ]
);
lc_assert_same( 'Hi', $out[0]['settings']['items'][0]['title'], 'list rows are still sanitized' );

$matrix = $normalizer->sanitize_settings( [ 'rows' => [ [ 'a', '<b>b</b><script>x</script>' ] ] ] );
lc_assert_same( [ [ 'a', '<b>b</b>' ] ], $matrix['rows'], 'nested lists are kept and sanitized' );

// Instance property overrides are sanitized and gated.
$instance = [ 'id' => 'inst01', 'name' => 'heading', 'cid' => 'comp01', 'parent' => 0, 'children' => [], 'settings' => [], 'properties' => [ 'prop01' => '<img src=x onerror="alert(1)">' ] ];
$out      = $normalizer->sanitize_flat_elements( [ $instance ] );
lc_assert_same( '<img src=x>', $out[0]['properties']['prop01'], 'property override HTML is sanitized' );
$strips = $normalizer->consume_strip_log();
lc_assert_same( 'properties.prop01', $strips[0]['key'] ?? '', 'property override strip is reported' );

$violations = $policy->changed_executable_payloads( [ $instance ], [] );
lc_assert_same( [ 'properties.prop01' ], $violations[0]['paths'] ?? [], 'executable property override is a violation' );
lc_assert_same( [], $policy->changed_executable_payloads( [ $instance ], [ $instance ] ), 'unchanged stored override is allowed' );

$stored = [ 'id' => 'inst02', 'name' => 'heading', 'cid' => 'comp01', 'parent' => 0, 'children' => [], 'settings' => [ 'a' => 'b' ], 'properties' => [ 'prop01' => 'Plain' ] ];
$same   = $normalizer->sanitize_flat_elements( [ $stored ], [ $stored ] );
lc_assert_same( $stored, $same[0], 'unchanged instance is preserved' );

$legacy_payload = '<img src=x onerror="alert(1)">';
$legacy         = [ 'id' => 'inst04', 'name' => 'heading', 'cid' => 'comp01', 'parent' => 0, 'children' => [], 'settings' => [], 'properties' => [ [ 'id' => 'prop01', 'value' => $legacy_payload ] ] ];
$converted      = $legacy;
$converted['properties'] = [ 'prop01' => $legacy_payload, 'prop02' => 'Edited' ];
lc_assert_same( [], $policy->changed_executable_payloads( [ $converted ], [ $legacy ] ), 'legacy override list conversion is not a new payload' );

// Definition policy: a nested code child with executeCode is caught.
$definition = [
	[ 'id' => 'comp01', 'name' => 'section', 'parent' => 0, 'children' => [ 'code01' ], 'settings' => [] ],
	[ 'id' => 'code01', 'name' => 'code', 'parent' => 'comp01', 'children' => [], 'settings' => [ 'code' => '<?php echo 1;', 'executeCode' => true ] ],
];
lc_assert_same( 'code01', $policy->changed_executable_payloads( $definition, [] )[0]['element_id'] ?? '', 'executable definition child is a violation' );

// Import ID regeneration remaps slot content but not slot IDs.
$tree = $normalizer->regenerate_flat_ids(
	[
		[ 'id' => 'inst03', 'name' => 'section', 'cid' => 'comp02', 'parent' => 0, 'children' => [], 'settings' => [], 'slotChildren' => [ 'slot99' => [ 'kid001' ] ] ],
		[ 'id' => 'kid001', 'name' => 'heading', 'parent' => 'inst03', 'children' => [], 'settings' => [] ],
	]
);
lc_assert_same( [ $tree[1]['id'] ], $tree[0]['slotChildren']['slot99'] ?? [], 'slot content IDs follow regeneration' );
lc_assert_same( $tree[0]['id'], $tree[1]['parent'], 'slot root parent follows regeneration' );
lc_assert_same( 'comp02', $tree[0]['cid'], 'component ID is unchanged' );

lc_test_done( 'MergedPipelineTest' );
