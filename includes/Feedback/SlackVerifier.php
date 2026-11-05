<?php
/**
 * Pure Slack request signature verification.
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
 * Implements Slack's documented v0 HMAC signing contract and replay window.
 */
final class SlackVerifier {

	private const MAX_AGE_SECONDS = 300;

	public function verify( string $body, string $timestamp, string $signature, string $secret, ?int $now = null ): bool {
		if ( '' === $body || '' === $timestamp || '' === $signature || '' === $secret || ! ctype_digit( $timestamp ) ) {
			return false;
		}

		$now = $now ?? time();
		if ( abs( $now - (int) $timestamp ) > self::MAX_AGE_SECONDS ) {
			return false;
		}

		$expected = 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, $secret );
		return hash_equals( $expected, $signature );
	}
}
