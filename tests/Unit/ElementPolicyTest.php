<?php
/**
 * Unit test: executable Bricks element policy.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/MCP/Services/ElementPolicy.php';

use LCBricksMCP\MCP\Services\ElementPolicy;

$policy = new ElementPolicy();

$code = [
	'id'       => 'abc123',
	'name'     => 'code',
	'parent'   => 0,
	'children' => [],
	'settings' => [ 'code' => '<?php echo 7; ?>' ],
];

$violations = $policy->changed_executable_payloads( [ $code ], [] );
lc_assert_same( 1, count( $violations ), 'new code element is executable content' );
lc_assert_same( 'abc123', $violations[0]['element_id'], 'violation identifies the element' );

lc_assert_same(
	[],
	$policy->changed_executable_payloads( [ $code ], [ $code ] ),
	'unchanged stored code is allowed while the toggle is off'
);

$moved              = $code;
$moved['parent']    = 'parent';
$moved['children']  = [ 'child' ];
lc_assert_same(
	[],
	$policy->changed_executable_payloads( [ $moved ], [ $code ] ),
	'moving an existing code element does not change its executable payload'
);

$changed                    = $code;
$changed['settings']['code'] = '<?php echo 8; ?>';
lc_assert_same(
	1,
	count( $policy->changed_executable_payloads( [ $changed ], [ $code ] ) ),
	'changing existing code requires explicit opt-in'
);

$html = [
	'id'       => 'html01',
	'name'     => 'html',
	'parent'   => 0,
	'children' => [],
	'settings' => [ 'html' => '<p onclick="run()">Click</p>' ],
];
lc_assert_same(
	1,
	count( $policy->changed_executable_payloads( [ $html ], [] ) ),
	'inline event-handler markup is treated as executable'
);

$safe             = $html;
$safe['settings'] = [ 'html' => '<p>Safe</p>' ];
lc_assert_same( [], $policy->changed_executable_payloads( [ $safe ], [] ), 'ordinary HTML is not executable' );

$nested = [
	'id'       => 'nest01',
	'name'     => 'div',
	'parent'   => 0,
	'children' => [],
	'settings' => [ 'interaction' => [ 'customJavascript' => 'alert(1)' ] ],
];
$nested_violations = $policy->changed_executable_payloads( [ $nested ], [] );
lc_assert_same( 1, count( $nested_violations ), 'nested script-capable settings are detected' );
lc_assert_same(
	[ 'settings.interaction.customJavascript' ],
	$nested_violations[0]['paths'],
	'violation reports the nested setting path'
);

lc_test_done( 'ElementPolicyTest' );
