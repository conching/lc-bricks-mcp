<?php
/**
 * Unit test: feedback queue pagination returns a bounded page and total.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';

if ( ! class_exists( 'WP_Error' ) ) {
	final class WP_Error {}
}

if ( ! class_exists( 'WP_Query' ) ) {
	final class WP_Query {
		/** @var array<string, mixed> */
		public static array $last_args = array();

		/** @var array<int, int> */
		public array $posts = array( 101, 102 );

		public int $found_posts = 6;

		/** @param array<string, mixed> $args */
		public function __construct( array $args ) {
			self::$last_args = $args;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return $value instanceof WP_Error;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?? '';
	}
}

if ( ! function_exists( 'get_post' ) ) {
	function get_post( int $post_id ): object {
		return (object) array(
			'ID'        => $post_id,
			'post_type' => 'lc_feedback_task',
		);
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $post_id ): string {
		return 'Task ' . $post_id;
	}
}

if ( ! function_exists( 'get_post_time' ) ) {
	function get_post_time( string $format, bool $gmt, mixed $post ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return '2026-07-17T00:00:00+00:00';
	}
}

if ( ! function_exists( 'get_post_modified_time' ) ) {
	function get_post_modified_time( string $format, bool $gmt, mixed $post ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return '2026-07-17T00:01:00+00:00';
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $post_id, string $key, bool $single ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$values = array(
			'_lc_status'    => 'inbox',
			'_lc_markup_id' => 'd5bf7dbd-c9f0-4684-b934-e7c567b36ac5',
			'_lc_comment'   => 'Comment ' . $post_id,
			'_lc_audit_log' => array( array( 'event' => 'created' ) ),
		);

		return $values[ $key ] ?? '';
	}
}

require_once __DIR__ . '/../../includes/Feedback/TaskRepository.php';

use LCBricksMCP\Feedback\TaskRepository;

$repository = new TaskRepository();
$page       = $repository->paginate( 'INBOX!', 2, 2 );

lc_assert_same( 2, count( $page['items'] ), 'two task records are hydrated' );
lc_assert_same( 6, $page['total'], 'found row count is returned' );
lc_assert_same( 2, $page['page'], 'requested page is returned' );
lc_assert_same( 2, $page['per_page'], 'requested page size is returned' );
lc_assert_same( 3, $page['total_pages'], 'total pages are calculated' );
lc_assert_same( 2, WP_Query::$last_args['paged'], 'query uses the requested page' );
lc_assert_same( 2, WP_Query::$last_args['posts_per_page'], 'query is bounded to the requested page size' );
lc_assert_same( false, WP_Query::$last_args['no_found_rows'], 'query calculates the authoritative total' );
lc_assert_same( '_lc_status', WP_Query::$last_args['meta_key'], 'status filter uses the private status meta key' );
lc_assert_same( 'inbox', WP_Query::$last_args['meta_value'], 'status filter is sanitized' );
lc_assert( ! array_key_exists( 'audit', $page['items'][0] ), 'list hydration omits audit detail' );

$clamped = $repository->paginate( '', 0, 500 );
lc_assert_same( 1, $clamped['page'], 'page is clamped to one' );
lc_assert_same( 100, $clamped['per_page'], 'page size is capped at one hundred' );

lc_test_done( 'FeedbackPaginationTest' );
