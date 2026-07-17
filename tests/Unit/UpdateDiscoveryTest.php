<?php
/**
 * Unit test: release discovery binds an exact versioned ZIP to its checksum.
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
	class WP_Error {}
}

function __( string $message, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return $message;
}

function is_wp_error( $value ): bool {
	return $value instanceof WP_Error;
}

function get_transient( string $key ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return false;
}

function set_transient( string $key, $value, int $ttl ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	$GLOBALS['__discovery_cached'] = $value;
	return true;
}

function wp_remote_get( string $url, array $args = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( str_ends_with( $url, '.sha256' ) ) {
		return [
			'response' => [ 'code' => 200 ],
			'body'     => str_repeat( 'a', 64 ) . "  lc-bricks-mcp-2.2.0.zip\n",
		];
	}

	return [
		'response' => [ 'code' => 200 ],
		'body'     => json_encode(
			[
				'tag_name' => 'v2.2.0',
				'html_url' => 'https://example.test/releases/2.2.0',
				'assets'   => [
					[
						'name'                 => 'lc-bricks-mcp-2.1.1.zip',
						'browser_download_url' => 'https://example.test/lc-bricks-mcp-2.1.1.zip',
					],
					[
						'name'                 => 'lc-bricks-mcp-2.2.0.zip',
						'browser_download_url' => 'https://example.test/lc-bricks-mcp-2.2.0.zip',
					],
					[
						'name'                 => 'lc-bricks-mcp-2.2.0.zip.sha256',
						'browser_download_url' => 'https://example.test/lc-bricks-mcp-2.2.0.zip.sha256',
					],
				],
			]
		),
	];
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) $response['response']['code'];
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) $response['body'];
}

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/Updates/UpdateChecker.php';

use LCBricksMCP\Updates\UpdateChecker;

$update = ( new UpdateChecker() )->check_update(
	false,
	[
		'Version'   => '2.1.1',
		'UpdateURI' => 'https://github.com/conching/lc-bricks-mcp',
	],
	'lc-bricks-mcp/lc-bricks-mcp.php',
	[]
);

lc_assert( is_array( $update ), 'verified release is advertised' );
lc_assert_same( '2.2.0', $update['version'] ?? '', 'tag version is preserved' );
lc_assert_same(
	'https://example.test/lc-bricks-mcp-2.2.0.zip',
	$update['package'] ?? '',
	'only the ZIP whose filename exactly matches the tag is selected'
);
lc_assert_same( str_repeat( 'a', 64 ), $GLOBALS['__discovery_cached']['sha256'] ?? '', 'adjacent checksum is parsed' );

lc_test_done( 'UpdateDiscoveryTest' );
