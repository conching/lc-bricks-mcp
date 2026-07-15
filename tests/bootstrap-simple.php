<?php
/**
 * Standalone test bootstrap — no WordPress required.
 *
 * The classes under test (PageInspector, ElementIdGenerator) are pure: they call
 * no WordPress functions. This bootstrap only defines ABSPATH (so the files'
 * direct-access guards pass), loads those classes, and provides a tiny assertion
 * harness. Each tests/Unit/*Test.php is runnable directly:  php tests/Unit/XTest.php
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once __DIR__ . '/../includes/MCP/Services/ElementIdGenerator.php';
require_once __DIR__ . '/../includes/MCP/Services/PageInspector.php';

$GLOBALS['__lc_pass']     = 0;
$GLOBALS['__lc_fail']     = 0;
$GLOBALS['__lc_failures'] = [];

/**
 * Assert a boolean condition.
 */
function lc_assert( bool $cond, string $msg ): void {
	if ( $cond ) {
		++$GLOBALS['__lc_pass'];
		return;
	}
	++$GLOBALS['__lc_fail'];
	$GLOBALS['__lc_failures'][] = $msg;
	fwrite( STDERR, "  FAIL: {$msg}\n" );
}

/**
 * Assert strict equality.
 *
 * @param mixed $expected Expected value.
 * @param mixed $actual   Actual value.
 */
function lc_assert_same( $expected, $actual, string $msg ): void {
	$ok = $expected === $actual;
	if ( ! $ok ) {
		$msg .= ' (expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')';
	}
	lc_assert( $ok, $msg );
}

/**
 * Print the summary for a test file and exit with an appropriate status code.
 */
function lc_test_done( string $name ): void {
	$pass = (int) $GLOBALS['__lc_pass'];
	$fail = (int) $GLOBALS['__lc_fail'];
	echo sprintf( "%s: %d passed, %d failed\n", $name, $pass, $fail );
	exit( $fail > 0 ? 1 : 0 );
}
