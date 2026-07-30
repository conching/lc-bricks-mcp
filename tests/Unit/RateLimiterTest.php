<?php
/**
 * Unit test: transient rate-limit window and weighted batch cost.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code, public string $message = '', public $data = null ) {}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

function __( string $message, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $message;
}

function get_option( string $name, $default = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return [ 'rate_limit_rpm' => 2 ];
}

function wp_using_ext_object_cache(): bool {
	return false;
}

function get_transient( string $key ) {
	return $GLOBALS['__rate_transients'][ $key ] ?? false;
}

function set_transient( string $key, $value, int $expiration ): bool {
	$GLOBALS['__rate_transients'][ $key ]    = $value;
	$GLOBALS['__rate_expirations'][ $key ] = $expiration;
	return true;
}

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/MCP/RateLimiter.php';

use LCBricksMCP\MCP\RateLimiter;

$GLOBALS['__rate_transients']  = [];
$GLOBALS['__rate_expirations'] = [];
$key                           = 'lc_bricks_mcp_rl_user_7';

lc_assert_same( true, RateLimiter::check( 'user_7', 2 ), 'batch cost consumes two units' );
$first_reset = $GLOBALS['__rate_transients'][ $key ]['reset_at'];

$blocked = RateLimiter::check( 'user_7', 1 );
lc_assert( $blocked instanceof WP_Error, 'request over the weighted limit is blocked' );
lc_assert_same( 'lc_bricks_mcp_rate_limit', $blocked->get_error_code(), 'blocked request has stable error code' );
lc_assert_same( $first_reset, $GLOBALS['__rate_transients'][ $key ]['reset_at'], 'retry does not extend the rate-limit window' );
lc_assert( $GLOBALS['__rate_expirations'][ $key ] <= 60, 'transient uses remaining fixed-window TTL' );

lc_test_done( 'RateLimiterTest' );
