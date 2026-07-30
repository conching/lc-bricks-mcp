<?php
/**
 * Unit test: JSON-RPC batch validation and MCP version negotiation.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! defined( 'LC_BRICKS_MCP_VERSION' ) ) {
	define( 'LC_BRICKS_MCP_VERSION', 'test' );
}

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/MCP/StreamableHttpHandler.php';

use LCBricksMCP\MCP\StreamableHttpHandler;

$reflection = new ReflectionClass( StreamableHttpHandler::class );
$handler    = $reflection->newInstanceWithoutConstructor();

$dispatch_batch = $reflection->getMethod( 'dispatch_batch' );
$responses = $dispatch_batch->invoke(
	$handler,
	[
		'not-an-object',
		[ 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping', 'params' => [] ],
		[ 'jsonrpc' => '2.0', 'method' => 'ping', 'params' => [] ],
	]
);

lc_assert_same( 2, count( $responses ), 'mixed batch returns invalid-member and request responses only' );
lc_assert_same( StreamableHttpHandler::INVALID_REQUEST, $responses[0]['error']['code'], 'scalar batch member is Invalid Request' );
lc_assert_same( 1, $responses[1]['id'], 'valid batch request retains its ID' );

$invalid_notification = $dispatch_batch->invoke(
	$handler,
	[ [ 'jsonrpc' => '1.0', 'method' => 'ping' ] ]
);
lc_assert_same( 1, count( $invalid_notification ), 'malformed notification receives an invalid-request response' );

$initialize = $reflection->getMethod( 'handle_initialize' );
$response = $initialize->invoke( $handler, 2, [ 'protocolVersion' => '2099-01-01' ] );
lc_assert_same(
	StreamableHttpHandler::PROTOCOL_VERSION,
	$response['result']['protocolVersion'],
	'unsupported client version negotiates to the latest server-supported version'
);

lc_test_done( 'TransportProtocolTest' );
