<?php
/**
 * Unit test: update verification is cross-request and fail-closed.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code, public string $message = '' ) {}
		public function get_error_code(): string {
			return $this->code;
		}
	}
}

function __( string $message, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $message;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function get_transient( string $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $GLOBALS['__update_transient'] ?? false;
}

function download_url( string $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $GLOBALS['__download_file'];
}

function wp_delete_file( string $file ): bool {
	$GLOBALS['__deleted_update_file'] = $file;
	return true;
}

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/Updates/UpdateChecker.php';

use LCBricksMCP\Updates\UpdateChecker;

$package = 'https://example.test/lc-bricks-mcp-9.9.9.zip';
$file    = tempnam( sys_get_temp_dir(), 'lc-update-' );
file_put_contents( $file, 'verified package bytes' );
$hash = hash_file( 'sha256', $file );

$GLOBALS['__download_file']    = $file;
$GLOBALS['__update_transient'] = [
	'version' => '9.9.9',
	'package' => $package,
	'sha256'  => $hash,
];

// A fresh object simulates the later upgrader request. It must reload the
// cached manifest rather than depend on update-discovery object state.
$checker = new UpdateChecker();
$result  = $checker->verify_download( false, $package, null, [ 'plugin' => 'lc-bricks-mcp/lc-bricks-mcp.php' ] );
lc_assert_same( $file, $result, 'fresh updater instance verifies from cached manifest' );

$GLOBALS['__update_transient']['sha256'] = '';
$result = ( new UpdateChecker() )->verify_download( false, $package, null, [ 'plugin' => 'lc-bricks-mcp/lc-bricks-mcp.php' ] );
lc_assert( is_wp_error( $result ), 'missing checksum fails closed' );
lc_assert_same( 'checksum_missing', $result->get_error_code(), 'missing checksum has a stable error code' );

$GLOBALS['__update_transient']['sha256'] = $hash;
$result = ( new UpdateChecker() )->verify_download( false, $package . '?changed=1', null, [ 'plugin' => 'lc-bricks-mcp/lc-bricks-mcp.php' ] );
lc_assert( is_wp_error( $result ), 'package URL mismatch fails closed' );
lc_assert_same( 'update_package_mismatch', $result->get_error_code(), 'URL mismatch has a stable error code' );

$GLOBALS['__update_transient']['sha256'] = str_repeat( '0', 64 );
$result = ( new UpdateChecker() )->verify_download( false, $package, null, [ 'plugin' => 'lc-bricks-mcp/lc-bricks-mcp.php' ] );
lc_assert( is_wp_error( $result ), 'hash mismatch fails closed' );
lc_assert_same( 'checksum_mismatch', $result->get_error_code(), 'hash mismatch has a stable error code' );
lc_assert_same( $file, $GLOBALS['__deleted_update_file'], 'mismatched temporary package is deleted' );

@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
lc_test_done( 'UpdateCheckerTest' );
