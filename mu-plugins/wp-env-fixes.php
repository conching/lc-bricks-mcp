<?php
/**
 * Plugin Name: wp-env Local Environment Fixes
 * Description: Fixes Docker networking issues that affect wp-env development environments.
 * Version:     1.0.0
 * License:     GPL-2.0-or-later
 *
 * WHY THIS EXISTS
 * ---------------
 * wp-env maps host port 8888 to container port 80 (8888 -> 80).
 * WordPress stores http://localhost:8888 as its site URL for browser access,
 * but Apache inside the container only listens on port 80.
 *
 * When WordPress makes HTTP requests to itself (loopback) — for REST API
 * health checks, wp-cron, and Site Health diagnostics — it targets
 * localhost:8888, which nothing is listening on inside the container.
 *
 * This mu-plugin rewrites those self-referencing requests to use port 80,
 * and enables Application Passwords which require HTTPS by default.
 *
 * SAFE TO DELETE if you're not using wp-env or Docker.
 *
 * @see https://developer.wordpress.org/advanced-administration/server/loopback/
 */

declare( strict_types=1 );

// Only apply fixes in local/development environments.
if ( wp_get_environment_type() !== 'local' ) {
	return;
}

/**
 * Rewrite loopback HTTP requests to use the container's internal port.
 *
 * Intercepts outgoing HTTP requests that target the host-mapped port (8888)
 * and rewrites them to port 80 (what Apache actually listens on inside Docker).
 *
 * Uses a named function so the filter can cleanly remove/re-add itself
 * to avoid infinite recursion when re-issuing the corrected request.
 */
function bricks_mcp_fix_loopback_request( $preempt, $parsed_args, $url ) {
	$url_parts = wp_parse_url( $url );
	$site_port = (int) wp_parse_url( site_url(), PHP_URL_PORT );

	// Only rewrite requests targeting our own host on the mapped port.
	$is_loopback = isset( $url_parts['host'], $url_parts['port'] )
		&& $url_parts['host'] === wp_parse_url( site_url(), PHP_URL_HOST )
		&& (int) $url_parts['port'] === $site_port
		&& $site_port !== 80;

	if ( ! $is_loopback ) {
		return $preempt;
	}

	// Rebuild URL with port 80 (Apache inside the container).
	$fixed_url = sprintf(
		'%s://%s%s%s',
		$url_parts['scheme'],
		$url_parts['host'],
		$url_parts['path'] ?? '/',
		! empty( $url_parts['query'] ) ? '?' . $url_parts['query'] : ''
	);

	// Temporarily unhook to avoid recursion, then re-issue the request.
	remove_filter( 'pre_http_request', __FUNCTION__ );
	$result = wp_remote_request( $fixed_url, $parsed_args );
	add_filter( 'pre_http_request', __FUNCTION__, 10, 3 );

	return $result;
}
add_filter( 'pre_http_request', 'bricks_mcp_fix_loopback_request', 10, 3 );

/**
 * Enable Application Passwords without HTTPS.
 *
 * WordPress requires HTTPS for Application Passwords by default.
 * Local development environments don't have SSL, so we allow it over HTTP.
 *
 * @see https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
 */
add_filter( 'wp_is_application_passwords_available', '__return_true' );
