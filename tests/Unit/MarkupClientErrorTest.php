<?php
/**
 * Unit test: Markup API failures expose useful diagnostics without secrets.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';

if ( ! defined( 'LC_MARKUP_API_KEY' ) ) {
	define( 'LC_MARKUP_API_KEY', 'test-only-key' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {
		public function __construct(
			private string $code,
			private string $message,
			private mixed $data = null
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $message, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $message;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = [] ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$GLOBALS['__lc_markup_url'] = $url;
		return $GLOBALS['__lc_markup_response'];
	}
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( array $response ): int {
		return (int) ( $response['response']['code'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( array $response ): string {
		return (string) ( $response['body'] ?? '' );
	}
}

if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( array $response, string $header ): string {
		return (string) ( $response['headers'][ strtolower( $header ) ] ?? '' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

require_once __DIR__ . '/../../includes/Feedback/Credentials.php';
require_once __DIR__ . '/../../includes/Feedback/MarkupClient.php';

use LCBricksMCP\Feedback\MarkupClient;

$markup_id = 'd5bf7dbd-c9f0-4684-b934-e7c567b36ac5';
$client    = new MarkupClient();

$GLOBALS['__lc_markup_response'] = array(
	'response' => array( 'code' => 400 ),
	'headers'  => array( 'x-request-id' => 'header-request-id' ),
	'body'     => json_encode(
		array(
			'error'     => array(
				'code'    => 'INVALID_QUERY',
				'message' => 'Unknown query field: order',
			),
			'requestId' => 'body-request-id',
		)
	),
);

$result = $client->list_open_threads( $markup_id );
lc_assert( is_wp_error( $result ), 'HTTP 400 returns WP_Error' );
lc_assert_same( 'markup_api_error', $result->get_error_code(), 'stable plugin error code' );
lc_assert_same(
	'MarkUp.io returned HTTP 400. API detail: INVALID_QUERY: Unknown query field: order. Request ID: body-request-id.',
	$result->get_error_message(),
	'sanitized detail and body request ID are visible'
);
lc_assert_same( 400, $result->get_error_data()['upstream_status'], 'upstream status is retained as structured data' );

$GLOBALS['__lc_markup_response'] = array(
	'response' => array( 'code' => 400 ),
	'headers'  => array(),
	'body'     => '{"message":"Bearer abcdefghijklmnop sk_1234567890abcdef <script>alert(1)</script>"}',
);

$redacted = $client->list_open_threads( $markup_id );
lc_assert( is_wp_error( $redacted ), 'malicious detail still returns WP_Error' );
lc_assert( ! str_contains( $redacted->get_error_message(), 'abcdefghijklmnop' ), 'Bearer token is redacted' );
lc_assert( ! str_contains( $redacted->get_error_message(), 'sk_1234567890abcdef' ), 'API key is redacted' );
lc_assert( ! str_contains( $redacted->get_error_message(), '<script>' ), 'markup is stripped' );

$GLOBALS['__lc_markup_response'] = array(
	'response' => array( 'code' => 400 ),
	'headers'  => array( 'cf-ray' => 'safe-ray-id' ),
	'body'     => 'not-json',
);

$generic = $client->list_open_threads( $markup_id );
lc_assert_same(
	'MarkUp.io returned HTTP 400. No safe API detail was returned. Request ID: safe-ray-id.',
	$generic->get_error_message(),
	'non-JSON errors remain useful without exposing the raw body'
);

$GLOBALS['__lc_markup_response'] = array(
	'response' => array( 'code' => 200 ),
	'headers'  => array(),
	'body'     => json_encode(
		array(
			'data' => array(
				'threads'  => array(
					array( 'id' => 'open-thread', 'resolved' => false ),
					array( 'id' => 'resolved-thread', 'resolved' => true ),
				),
				'cursorId' => '',
			)
		)
	),
);

$open_threads = $client->list_open_threads( $markup_id );
lc_assert( is_array( $open_threads ), 'successful response returns threads' );
lc_assert_same( 1, count( $open_threads ), 'resolved threads are excluded locally' );
lc_assert_same( 'open-thread', $open_threads[0]['id'], 'unresolved thread remains' );
lc_assert( ! str_contains( $GLOBALS['__lc_markup_url'], 'resolved=' ), 'request omits rejected optional resolved filter' );

lc_test_done( 'MarkupClientErrorTest' );
