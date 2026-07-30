<?php
/**
 * Unit test: menu replacement preserves the old tree on creation failure.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code, public string $message = '', public $data = null ) {}
		public function get_error_message(): string {
			return $this->message;
		}
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

function wp_get_nav_menu_object( int $menu_id ): object {
	return (object) [ 'term_id' => $menu_id, 'name' => 'Primary' ];
}

function wp_get_nav_menu_items( int $menu_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return [ (object) [ 'ID' => 10 ] ];
}

function wp_update_nav_menu_item( int $menu_id, int $db_id, array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	++$GLOBALS['__menu_insert_calls'];
	return 1 === $GLOBALS['__menu_insert_calls'] ? 20 : new WP_Error( 'insert_failed', 'simulated failure' );
}

function wp_slash( array $data ): array {
	return $data;
}

function wp_delete_post( int $post_id, bool $force ): object|false { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	$GLOBALS['__menu_deleted_ids'][] = $post_id;
	return (object) [ 'ID' => $post_id ];
}

function get_post( int $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return null;
}

function get_post_type( int $post_id ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return '';
}

function get_term( int $term_id, string $taxonomy = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	return null;
}

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/MCP/Services/MenuService.php';

use LCBricksMCP\MCP\Services\MenuService;

$GLOBALS['__menu_insert_calls'] = 0;
$GLOBALS['__menu_deleted_ids']  = [];

$result = ( new MenuService() )->set_menu_items(
	1,
	[
		[ 'title' => 'First', 'type' => 'custom', 'url' => '/first' ],
		[ 'title' => 'Second', 'type' => 'custom', 'url' => '/second' ],
	]
);

lc_assert( is_wp_error( $result ), 'insertion failure returns an error' );
lc_assert_same( 'menu_replacement_failed', $result->get_error_code(), 'failure has a stable error code' );
lc_assert_same( [ 20 ], $GLOBALS['__menu_deleted_ids'], 'only newly created items are rolled back' );
lc_assert( ! in_array( 10, $GLOBALS['__menu_deleted_ids'], true ), 'old menu item remains untouched' );

lc_test_done( 'MenuReplacementTest' );
