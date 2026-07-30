<?php
/**
 * Atomic rate limiter for MCP endpoints.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\MCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RateLimiter class.
 *
 * Provides per-identifier rate limiting via two code paths:
 *
 * 1. Persistent object cache path (Redis, Memcached):
 *    Uses wp_cache_add + wp_cache_incr. Both operations are atomic on
 *    persistent backends, eliminating the TOCTOU race condition.
 *    Detected via wp_using_ext_object_cache().
 *
 * 2. Transient fallback path (default WP object cache):
 *    Uses get_transient / set_transient with a WINDOW (60 s) expiry.
 *    The in-process WP object cache resets per request, so wp_cache_incr
 *    is non-functional across requests — transients are the correct
 *    mechanism for sites without Redis/Memcached.
 *
 * Both paths return true within the limit or WP_Error 429 when exceeded.
 */
final class RateLimiter {

	/**
	 * Cache group for rate limit counters (persistent cache path only).
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'lc_bricks_mcp';

	/**
	 * Rate limit window in seconds.
	 *
	 * @var int
	 */
	private const WINDOW = 60;

	/**
	 * Check whether the given identifier is within the rate limit.
	 *
	 * Selects between the persistent object cache path and the transient
	 * fallback path based on wp_using_ext_object_cache().
	 *
	 * @param string $identifier The rate limit identifier (e.g. 'user_42' or 'ip_1.2.3.4').
	 * @return true|\WP_Error True if within limit, WP_Error with status 429 if exceeded.
	 */
	public static function check( string $identifier, int $cost = 1 ): true|\WP_Error {
		$settings = get_option( 'lc_bricks_mcp_settings', [] );
		$limit    = (int) ( $settings['rate_limit_rpm'] ?? 120 );
		$cost     = max( 1, $cost );

		if ( wp_using_ext_object_cache() ) {
			$state = self::increment_via_object_cache( $identifier, $cost );
		} else {
			$state = self::increment_via_transient( $identifier, $cost );
		}

		if ( false === $state || $state['count'] > $limit ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- suppress "headers already sent" in test environments.
			@header( 'Retry-After: ' . ( false === $state ? self::WINDOW : $state['retry_after'] ) );

			return new \WP_Error(
				'lc_bricks_mcp_rate_limit',
				__( 'Rate limit exceeded. Try again later.', 'lc-bricks-mcp' ),
				[ 'status' => 429 ]
			);
		}

		return true;
	}

	/**
	 * Increment counter using the persistent object cache (atomic path).
	 *
	 * @param string $identifier Rate limit identifier.
	 * @return int|false New counter value, or false on failure.
	 */
	private static function increment_via_object_cache( string $identifier, int $cost ): array|false {
		$key = 'rl_' . $identifier;

		// Initialize counter only if it does not already exist (atomic on persistent cache).
		wp_cache_add( $key, 0, self::CACHE_GROUP, self::WINDOW );

		// Atomically increment and return the new count.
		$count = wp_cache_incr( $key, $cost, self::CACHE_GROUP );
		return false === $count ? false : [ 'count' => (int) $count, 'retry_after' => self::WINDOW ];
	}

	/**
	 * Increment counter using transients (fallback for sites without persistent cache).
	 *
	 * The transient key uses the global namespace (no CACHE_GROUP prefix) to
	 * ensure persistence across requests.
	 *
	 * @param string $identifier Rate limit identifier.
	 * @return int New counter value.
	 */
	private static function increment_via_transient( string $identifier, int $cost ): array {
		$transient_key = 'lc_bricks_mcp_rl_' . $identifier;
		$current       = get_transient( $transient_key );
		$now           = time();

		if ( ! is_array( $current ) || ! isset( $current['count'], $current['reset_at'] ) || (int) $current['reset_at'] <= $now ) {
			$state = [
				'count'    => $cost,
				'reset_at' => $now + self::WINDOW,
			];
		} else {
			$state          = $current;
			$state['count'] = (int) $state['count'] + $cost;
		}

		$retry_after = max( 1, (int) $state['reset_at'] - $now );
		set_transient( $transient_key, $state, $retry_after );

		return [ 'count' => (int) $state['count'], 'retry_after' => $retry_after ];
	}
}
