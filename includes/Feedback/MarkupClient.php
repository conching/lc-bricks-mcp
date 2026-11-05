<?php
/**
 * Minimal MarkUp.io Feedback API client.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\Feedback;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Calls only the thread endpoints required by the feedback MVP.
 */
final class MarkupClient {

	private const API_BASE    = 'https://api.markup.io/api/v2';
	private const API_VERSION = '2023-02-22';
	private const PAGE_LIMIT  = 100;
	private const MAX_PAGES   = 10;

	/**
	 * List unresolved threads for a Markup.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function list_open_threads( string $markup_id ): array|\WP_Error {
		if ( 1 !== preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $markup_id ) ) {
			return new \WP_Error( 'invalid_markup_id', __( 'Markup ID must be a UUID.', 'lc-bricks-mcp' ) );
		}

		$threads = array();
		$cursor  = '';

		for ( $page = 0; $page < self::MAX_PAGES; ++$page ) {
			$query = array(
				'markupId' => $markup_id,
				'limit'    => self::PAGE_LIMIT,
				'order'    => 'activity',
			);
			if ( '' !== $cursor ) {
				$query['cursorId'] = $cursor;
			}

			$response = $this->request( 'GET', '/threads?' . http_build_query( $query ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$data       = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : array();
			$page_items = isset( $data['threads'] ) && is_array( $data['threads'] ) ? $data['threads'] : array();
			foreach ( $page_items as $thread ) {
				if ( is_array( $thread ) && ! $this->thread_is_resolved( $thread ) ) {
					$thread['_lc_markup_id'] = $markup_id;
					$threads[]               = $thread;
				}
			}

			$next = isset( $data['cursorId'] ) && is_string( $data['cursorId'] ) ? trim( $data['cursorId'] ) : '';
			if ( '' === $next || $next === $cursor || count( $page_items ) < self::PAGE_LIMIT ) {
				break;
			}
			$cursor = $next;
		}

		return $threads;
	}

	/**
	 * Treat only an explicit true-like API value as resolved.
	 *
	 * Markup currently rejects its documented optional `resolved=false` query
	 * filter, so the MVP filters the returned page locally instead.
	 *
	 * @param array<string, mixed> $thread Thread response.
	 */
	private function thread_is_resolved( array $thread ): bool {
		$value = $thread['resolved'] ?? false;
		return true === $value || 1 === $value || '1' === $value || ( is_string( $value ) && 'true' === strtolower( $value ) );
	}

	/**
	 * Mark a thread resolved.
	 */
	public function resolve_thread( string $thread_id ): true|\WP_Error {
		return $this->thread_mutation( $thread_id, 'resolve' );
	}

	/**
	 * Reopen a thread after a local rollback.
	 */
	public function unresolve_thread( string $thread_id ): true|\WP_Error {
		return $this->thread_mutation( $thread_id, 'unresolve' );
	}

	/**
	 * Perform a resolve/unresolve mutation.
	 */
	private function thread_mutation( string $thread_id, string $action ): true|\WP_Error {
		if ( 1 !== preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $thread_id ) ) {
			return new \WP_Error( 'invalid_markup_thread_id', __( 'Markup thread ID must be a UUID.', 'lc-bricks-mcp' ) );
		}

		$response = $this->request( 'POST', '/threads/' . rawurlencode( $thread_id ) . '/' . $action );
		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Issue a bounded request to the fixed Markup API origin.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, string $path ): array|\WP_Error {
		$api_key = Credentials::markup_api_key();
		if ( '' === $api_key ) {
			return new \WP_Error(
				'markup_not_configured',
				__( 'MarkUp.io is not configured. Add a rotated key through LC_MARKUP_API_KEY or MARKUP_API_KEY.', 'lc-bricks-mcp' )
			);
		}

		$response = wp_remote_request(
			self::API_BASE . $path,
			array(
				'method'      => $method,
				'timeout'     => 12,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'      => 'Bearer ' . $api_key,
					'Markup-API-Version' => self::API_VERSION,
					'Content-Type'       => 'application/json',
					'Accept'             => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'markup_request_failed', __( 'The MarkUp.io request failed.', 'lc-bricks-mcp' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$details = $this->api_error_details( $response );
			$message = sprintf(
				/* translators: %d: Markup API HTTP status */
				__( 'MarkUp.io returned HTTP %d.', 'lc-bricks-mcp' ),
				$status
			);

			if ( '' !== $details['code'] || '' !== $details['message'] ) {
				$detail = '' !== $details['code'] ? $details['code'] : '';
				if ( '' !== $details['message'] ) {
					$detail .= ( '' !== $detail ? ': ' : '' ) . $details['message'];
				}
				$message .= ' ' . sprintf(
					/* translators: %s: sanitized Markup API error detail */
					__( 'API detail: %s.', 'lc-bricks-mcp' ),
					$detail
				);
			} else {
				$message .= ' ' . __( 'No safe API detail was returned.', 'lc-bricks-mcp' );
			}

			if ( '' !== $details['request_id'] ) {
				$message .= ' ' . sprintf(
					/* translators: %s: sanitized upstream request ID */
					__( 'Request ID: %s.', 'lc-bricks-mcp' ),
					$details['request_id']
				);
			}

			return new \WP_Error(
				'markup_api_error',
				$message,
				array(
					'status'              => 502,
					'upstream_status'     => $status,
					'upstream_code'       => $details['code'],
					'upstream_request_id' => $details['request_id'],
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return array();
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'markup_invalid_response', __( 'MarkUp.io returned invalid JSON.', 'lc-bricks-mcp' ) );
		}

		return $decoded;
	}

	/**
	 * Extract a bounded, allowlisted diagnostic from an upstream error.
	 *
	 * The raw response body is never logged or returned. Only common scalar error
	 * fields and a request ID are surfaced, after token redaction and length caps.
	 *
	 * @param array<string, mixed> $response WordPress HTTP response.
	 * @return array{code: string, message: string, request_id: string}
	 */
	private function api_error_details( array $response ): array {
		$code       = '';
		$message    = '';
		$request_id = '';
		$decoded    = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( is_array( $decoded ) ) {
			if ( isset( $decoded['code'] ) && is_scalar( $decoded['code'] ) ) {
				$code = $this->safe_detail( (string) $decoded['code'], 80 );
			}
			if ( isset( $decoded['message'] ) && is_scalar( $decoded['message'] ) ) {
				$message = $this->safe_detail( (string) $decoded['message'], 240 );
			}

			if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) && '' === $message ) {
				$message = $this->safe_detail( $decoded['error'], 240 );
			} elseif ( isset( $decoded['error'] ) && is_array( $decoded['error'] ) ) {
				if ( '' === $code && isset( $decoded['error']['code'] ) && is_scalar( $decoded['error']['code'] ) ) {
					$code = $this->safe_detail( (string) $decoded['error']['code'], 80 );
				}
				if ( '' === $message && isset( $decoded['error']['message'] ) && is_scalar( $decoded['error']['message'] ) ) {
					$message = $this->safe_detail( (string) $decoded['error']['message'], 240 );
				}
			}

			foreach ( array( 'requestId', 'request_id', 'request-id' ) as $request_key ) {
				if ( isset( $decoded[ $request_key ] ) && is_scalar( $decoded[ $request_key ] ) ) {
					$request_id = $this->safe_detail( (string) $decoded[ $request_key ], 100 );
					break;
				}
			}
		}

		if ( '' === $request_id ) {
			foreach ( array( 'x-request-id', 'x-amzn-requestid', 'cf-ray' ) as $header ) {
				$value = wp_remote_retrieve_header( $response, $header );
				if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
					$request_id = $this->safe_detail( (string) $value, 100 );
					break;
				}
			}
		}

		return array(
			'code'       => $code,
			'message'    => $message,
			'request_id' => $request_id,
		);
	}

	/**
	 * Sanitize and bound one diagnostic scalar without exposing credentials.
	 */
	private function safe_detail( string $value, int $limit ): string {
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? '';
		$value = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=\-]{8,}/i', 'Bearer [redacted]', $value ) ?? '';
		$value = preg_replace( '/\b(?:sk|pk)_[A-Za-z0-9_-]{8,}\b/i', '[redacted-key]', $value ) ?? '';
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? '';

		return strlen( $value ) > $limit ? substr( $value, 0, $limit ) . '…' : $value;
	}
}
