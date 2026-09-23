<?php
/**
 * Unit test: Bricks variable names use bare storage names and repair safely.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/MCP/Services/VariableNameNormalizer.php';

use LCBricksMCP\MCP\Services\VariableNameNormalizer;

$storage_cases = [
	'brand-probe'                  => 'brand-probe',
	'--brand-probe'                => 'brand-probe',
	'----brand-probe'              => 'brand-probe',
	'var(--brand-probe)'           => 'brand-probe',
	'var( --brand-probe , 4px )'   => 'brand-probe',
	'  --x  '                   => 'x',
	'--'                       => '',
	''                         => '',
	'   '                      => '',
	'var(--a)'                 => 'a',
	'var(a)'                   => 'a',
	'---x'                     => '-x',
	'a--b'                     => 'a--b',
	'var()'                    => '',
	'var(--)'                  => '',
	'----'                     => '',
	'--café'                  => 'café',
];

foreach ( $storage_cases as $input => $expected ) {
	lc_assert_same( $expected, VariableNameNormalizer::to_storage( $input ), 'to_storage handles ' . var_export( $input, true ) );
}

$long_name = str_repeat( 'x', 10000 );
lc_assert_same( $long_name, VariableNameNormalizer::to_storage( '--' . $long_name ), 'to_storage handles a long name' );

lc_assert_same( '--brand-probe', VariableNameNormalizer::to_css_property( 'var(----brand-probe)' ), 'to_css_property adds one marker' );
lc_assert_same( '', VariableNameNormalizer::to_css_property( '--' ), 'to_css_property leaves an empty name empty' );
lc_assert_same( 'brand-text-', VariableNameNormalizer::normalize_prefix( '--brand-text-' ), 'normalize_prefix removes the marker and keeps the trailing dash' );
lc_assert_same( 'brand-text-', VariableNameNormalizer::normalize_prefix( 'brand-text-' ), 'normalize_prefix accepts a bare prefix' );
lc_assert_same( 'brand-text-', VariableNameNormalizer::normalize_prefix( 'var(--brand-text-)' ), 'normalize_prefix accepts a var() reference' );
lc_assert( VariableNameNormalizer::is_double_prefixed( '--x' ), 'legacy name has a leading marker' );
lc_assert( VariableNameNormalizer::is_double_prefixed( '----x' ), 'repeated legacy marker is detected' );
lc_assert( ! VariableNameNormalizer::is_double_prefixed( 'x' ), 'bare name is not legacy' );
lc_assert( ! VariableNameNormalizer::is_double_prefixed( '-x' ), 'single dash is not legacy' );

$variables = [
	[ 'id' => 'plain', 'name' => '--brand-probe', 'value' => '7px', 'category' => '', 'extra' => [ 'keep' => true ] ],
	[ 'id' => 'scale', 'name' => '----brand-text-h1', 'value' => '2rem', 'category' => 'scale-cat' ],
	[ 'id' => 'bare', 'name' => 'already-bare', 'value' => '1rem', 'category' => '' ],
	'junk',
];
$categories = [
	[ 'id' => 'scale-cat', 'name' => 'Type', 'scale' => [ 'prefix' => '--brand-text-', 'other' => 'keep' ], 'utilityClasses' => [ 'text-*' ] ],
	[ 'id' => 'bare-scale', 'name' => 'Other Type', 'scale' => [ 'prefix' => 'text-' ] ],
	[ 'id' => 'plain-cat', 'name' => 'Plain', 'extra' => 42 ],
	false,
];
$original_variables  = $variables;
$original_categories = $categories;

$plan = VariableNameNormalizer::plan_repair( $variables, $categories );
lc_assert_same( $original_variables, $variables, 'planner does not mutate variable input' );
lc_assert_same( $original_categories, $categories, 'planner does not mutate category input' );
lc_assert_same( [ 'plain', 'scale', 'bare', 3 ], array_map( static fn( $entry ) => is_array( $entry ) ? $entry['id'] : 3, $plan['variables'] ), 'variable order is unchanged' );
lc_assert_same( array_keys( $variables[0] ), array_keys( $plan['variables'][0] ), 'variable key order is unchanged' );
lc_assert_same( [ 'keep' => true ], $plan['variables'][0]['extra'], 'variable extra keys survive' );
lc_assert_same( 'junk', $plan['variables'][3], 'non-array variable survives' );
lc_assert_same( false, $plan['categories'][3], 'non-array category survives' );
lc_assert_same( array_keys( $categories[0] ), array_keys( $plan['categories'][0] ), 'category key order is unchanged' );
lc_assert_same( [ 'prefix', 'other' ], array_keys( $plan['categories'][0]['scale'] ), 'scale key order is unchanged' );
lc_assert_same( 'keep', $plan['categories'][0]['scale']['other'], 'scale extra key survives' );
lc_assert_same( 'brand-probe', $plan['variables'][0]['name'], 'plain variable is repaired' );
lc_assert_same( 'brand-text-h1', $plan['variables'][1]['name'], 'scale variable is repaired' );
lc_assert_same( 'brand-text-', $plan['categories'][0]['scale']['prefix'], 'scale prefix is repaired' );
lc_assert_same( $variables[2], $plan['variables'][2], 'already-bare variable is untouched' );
lc_assert_same( $categories[1], $plan['categories'][1], 'already-bare scale prefix is untouched' );
lc_assert_same( $categories[2], $plan['categories'][2], 'non-scale category is untouched' );
lc_assert_same(
	[
		[ 'id' => 'plain', 'category' => '', 'from' => '--brand-probe', 'to' => 'brand-probe' ],
		[ 'id' => 'scale', 'category' => 'scale-cat', 'from' => '----brand-text-h1', 'to' => 'brand-text-h1' ],
	],
	$plan['renamed'],
	'planner reports only repaired variables'
);
lc_assert_same(
	[ [ 'category_id' => 'scale-cat', 'category_name' => 'Type', 'from' => '--brand-text-', 'to' => 'brand-text-' ] ],
	$plan['prefixes'],
	'planner reports repaired scale prefix'
);
lc_assert_same( [], $plan['conflicts'], 'ordinary repair has no conflicts' );

foreach ( $plan['variables'] as $variable ) {
	if ( is_array( $variable ) ) {
		// Bricks emits each custom property by prepending -- to its stored name.
		$emitted_property = '--' . $variable['name'];
		lc_assert( ! str_starts_with( $emitted_property, '----' ), 'repaired Bricks emission has no doubled marker' );
	}
}

$again = VariableNameNormalizer::plan_repair( $plan['variables'], $plan['categories'] );
lc_assert_same( [], $again['renamed'], 'repair is idempotent for variables' );
lc_assert_same( [], $again['prefixes'], 'repair is idempotent for prefixes' );
lc_assert_same( $plan['variables'], $again['variables'], 'idempotent plan keeps repaired variables' );
lc_assert_same( $plan['categories'], $again['categories'], 'idempotent plan keeps repaired categories' );

$taken = VariableNameNormalizer::plan_repair(
	[
		[ 'id' => 'legacy', 'name' => '--x', 'category' => '' ],
		[ 'id' => 'native', 'name' => 'x', 'category' => '' ],
	],
	[]
);
lc_assert_same( '--x', $taken['variables'][0]['name'], 'existing bare target keeps legacy name unchanged' );
lc_assert_same( 'x', $taken['variables'][1]['name'], 'existing bare target is preserved' );
lc_assert_same( [], $taken['renamed'], 'conflicting repair is not reported as a rename' );
lc_assert_same( [ [ 'id' => 'legacy', 'name' => '--x', 'target' => 'x', 'reason' => 'name_taken' ] ], $taken['conflicts'], 'existing bare target is reported as a conflict' );

$duplicate = VariableNameNormalizer::plan_repair(
	[
		[ 'id' => 'first', 'name' => '--x' ],
		[ 'id' => 'second', 'name' => '----x' ],
	],
	[]
);
lc_assert_same( 'x', $duplicate['variables'][0]['name'], 'first duplicate target is repaired' );
lc_assert_same( '----x', $duplicate['variables'][1]['name'], 'second duplicate target remains unchanged' );
lc_assert_same( [ [ 'id' => 'second', 'name' => '----x', 'target' => 'x', 'reason' => 'name_taken' ] ], $duplicate['conflicts'], 'second duplicate target is reported as a conflict' );
lc_assert_same( 1, count( $duplicate['renamed'] ), 'only first duplicate target is reported as renamed' );
$duplicate_again = VariableNameNormalizer::plan_repair( $duplicate['variables'], $duplicate['categories'] );
lc_assert_same( [], $duplicate_again['renamed'], 'conflicting plan reports no further renames on the next pass' );
lc_assert_same( $duplicate['variables'], $duplicate_again['variables'], 'conflicting plan keeps its repaired state on the next pass' );

// An entry whose name is only the marker has no valid bare name; report it, never store ''.
$empty = VariableNameNormalizer::plan_repair( [ [ 'id' => 'dash', 'name' => '--', 'value' => '1px' ] ], [] );
lc_assert_same( '--', $empty['variables'][0]['name'], 'marker-only name is left unchanged' );
lc_assert_same( [ [ 'id' => 'dash', 'name' => '--', 'target' => '', 'reason' => 'empty_name' ] ], $empty['conflicts'], 'marker-only name is reported as empty_name' );
lc_assert_same( [], $empty['renamed'], 'marker-only name is not reported as renamed' );

// name_owner: bare and legacy entries both own a bare name; the excluded id is ignored.
$owned = [
	[ 'id' => 'native', 'name' => 'brand' ],
	[ 'id' => 'legacy', 'name' => '--accent' ],
	'junk',
];
lc_assert_same( 'native', VariableNameNormalizer::name_owner( $owned, 'brand' )['id'] ?? null, 'name_owner finds a native bare name' );
lc_assert_same( 'legacy', VariableNameNormalizer::name_owner( $owned, 'accent' )['id'] ?? null, 'name_owner treats a legacy --name as owning the bare name' );
lc_assert_same( null, VariableNameNormalizer::name_owner( $owned, 'brand', 'native' ), 'name_owner ignores the excluded id' );
lc_assert_same( null, VariableNameNormalizer::name_owner( $owned, 'free' ), 'name_owner returns null for a free name' );
lc_assert_same( null, VariableNameNormalizer::name_owner( $owned, '' ), 'name_owner never matches an empty name' );

// A scale prefix is held back when one of its steps cannot be repaired.
$blocked = VariableNameNormalizer::plan_repair(
	[
		[ 'id' => 'owner', 'name' => 'text-h1', 'category' => '' ],
		[ 'id' => 'step1', 'name' => '--text-h1', 'category' => 'scale' ],
		[ 'id' => 'step2', 'name' => '--text-h2', 'category' => 'scale' ],
	],
	[ [ 'id' => 'scale', 'name' => 'Text', 'scale' => [ 'prefix' => '--text-' ] ] ]
);
lc_assert_same( '--text-', $blocked['categories'][0]['scale']['prefix'], 'blocked scale keeps its legacy prefix' );
lc_assert_same( [], $blocked['prefixes'], 'blocked scale reports no prefix repair' );
lc_assert( in_array( 'prefix_blocked_by_step_conflict', array_column( $blocked['conflicts'], 'reason' ), true ), 'blocked prefix is reported as a conflict' );
lc_assert_same( '--text-h1', $blocked['variables'][1]['name'], 'conflicting step stays unchanged' );

// Category scope: only that category's entries are planned.
$scoped = VariableNameNormalizer::plan_repair(
	[
		[ 'id' => 'a', 'name' => '--a', 'category' => 'one' ],
		[ 'id' => 'b', 'name' => '--b', 'category' => 'two' ],
	],
	[
		[ 'id' => 'one', 'scale' => [ 'prefix' => '--one-' ] ],
		[ 'id' => 'two', 'scale' => [ 'prefix' => '--two-' ] ],
	],
	'one'
);
lc_assert_same( [ 'a', '--b' ], array_column( $scoped['variables'], 'name' ), 'scoped repair renames only the chosen category' );
lc_assert_same( [ 'one-', '--two-' ], [ $scoped['categories'][0]['scale']['prefix'], $scoped['categories'][1]['scale']['prefix'] ], 'scoped repair fixes only the chosen prefix' );

// Review round 2: legacy "--var(--x)" (2.1.1 stored var() input this way) unwraps fully.
lc_assert_same( 'brand', VariableNameNormalizer::to_storage( '--var(--brand)' ), 'legacy --var(--brand) repairs to brand' );
lc_assert_same( 'brand', VariableNameNormalizer::to_storage( '--var(--brand, red)' ), 'legacy --var(--brand, red) repairs to brand' );

// A blocked scale is repaired all-or-nothing: no step is renamed under the old prefix.
$all_or_nothing = VariableNameNormalizer::plan_repair(
	[
		[ 'id' => 'owner', 'name' => 'text-h1', 'category' => '' ],
		[ 'id' => 'step1', 'name' => '--text-h1', 'category' => 'scale' ],
		[ 'id' => 'step2', 'name' => '--text-h2', 'category' => 'scale' ],
		[ 'id' => 'plain', 'name' => '--plain', 'category' => '' ],
	],
	[ [ 'id' => 'scale', 'scale' => [ 'prefix' => '--text-' ] ] ]
);
lc_assert_same( [ 'text-h1', '--text-h1', '--text-h2', 'plain' ], array_column( $all_or_nothing['variables'], 'name' ), 'blocked scale keeps every step; unrelated plain variable is still repaired' );
lc_assert_same( [ 'name_taken', 'held_back_scale_conflict', 'prefix_blocked_by_step_conflict' ], array_column( $all_or_nothing['conflicts'], 'reason' ), 'blocked scale reports the conflict, the held-back step and the prefix' );

// A legacy prefix of only "--" is reported, never stored as an empty prefix.
$empty_prefix = VariableNameNormalizer::plan_repair(
	[ [ 'id' => 's', 'name' => '--h1', 'category' => 'scale' ] ],
	[ [ 'id' => 'scale', 'scale' => [ 'prefix' => '--' ] ] ]
);
lc_assert_same( '--', $empty_prefix['categories'][0]['scale']['prefix'], 'marker-only prefix is left unchanged' );
lc_assert_same( '--h1', $empty_prefix['variables'][0]['name'], 'steps of an empty-prefix scale are held back' );
lc_assert_same( [ 'empty_prefix', 'held_back_scale_conflict' ], array_column( $empty_prefix['conflicts'], 'reason' ), 'empty prefix and held-back step are reported' );

// Review round 3: a blocked scale releases its step targets, independent of option order.
$released = VariableNameNormalizer::plan_repair(
	[
		[ 'id' => 'plain', 'name' => '--text-y', 'category' => '' ],
		[ 'id' => 'owner', 'name' => 'text-h1', 'category' => '' ],
		[ 'id' => 'step1', 'name' => '--text-h1', 'category' => 'scale' ],
		[ 'id' => 'step2', 'name' => '--text-y', 'category' => 'scale' ],
	],
	[ [ 'id' => 'scale', 'scale' => [ 'prefix' => '--text-' ] ] ]
);
lc_assert_same( 'text-y', $released['variables'][0]['name'], 'plain --text-y is repaired even though a blocked scale holds a --text-y step' );
lc_assert_same( [ '--text-h1', '--text-y' ], [ $released['variables'][2]['name'], $released['variables'][3]['name'] ], 'blocked scale steps stay unchanged' );

// emitted_property reports what Bricks outputs today, legacy doubling included.
lc_assert_same( '--foo', VariableNameNormalizer::emitted_property( 'foo' ), 'emitted_property of a bare name' );
lc_assert_same( '----foo', VariableNameNormalizer::emitted_property( '--foo' ), 'emitted_property of a legacy name shows the doubled property' );
lc_assert_same( '', VariableNameNormalizer::emitted_property( '' ), 'emitted_property of an empty name' );

lc_test_done( 'VariableNameNormalizerTest' );
