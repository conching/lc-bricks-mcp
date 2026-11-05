<?php
/**
 * Unit test: admin pagination leaves URL escaping to paginate_links().
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';

if ( ! function_exists( '__' ) ) {
	function __( string $message, string $domain = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return $message;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . $path;
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	/** @param array<string, int|string> $args */
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
}

if ( ! function_exists( 'paginate_links' ) ) {
	/**
	 * @param array<string, mixed> $args Pagination arguments.
	 * @return array<int, string>
	 */
	function paginate_links( array $args ): array {
		$GLOBALS['__lc_paginate_args'] = $args;
		$href = str_replace( '%#%', '3', (string) $args['base'] );
		return array( '<a href="' . htmlspecialchars( $href, ENT_QUOTES ) . '">3</a>' );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( string $message, string $domain = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		echo htmlspecialchars( $message, ENT_QUOTES );
	}
}

if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( string $html ): string {
		return $html;
	}
}

eval( 'namespace LCBricksMCP\\Feedback; final class Workflow {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

require_once __DIR__ . '/../../includes/Feedback/AdminPage.php';

use LCBricksMCP\Feedback\AdminPage;
use LCBricksMCP\Feedback\Workflow;

$page   = new AdminPage( new Workflow() );
$method = new ReflectionMethod( $page, 'render_pagination' );

ob_start();
$method->invoke(
	$page,
	array(
		'items'       => array(),
		'total'       => 145,
		'page'        => 2,
		'per_page'    => 50,
		'total_pages' => 3,
	)
);
$html = (string) ob_get_clean();
$base = (string) ( $GLOBALS['__lc_paginate_args']['base'] ?? '' );

lc_assert_same(
	'https://example.test/wp-admin/admin.php?page=lc-feedback-queue&feedback_page=%#%',
	$base,
	'pagination receives a raw query separator and page placeholder'
);
lc_assert( ! str_contains( $base, '#038;' ), 'pagination base is not pre-escaped' );
lc_assert( str_contains( $html, 'feedback_page=3' ), 'rendered link targets the requested page' );

lc_test_done( 'FeedbackAdminPaginationTest' );
