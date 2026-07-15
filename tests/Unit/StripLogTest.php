<?php
/**
 * Unit test: ElementNormalizer strip log — sanitization alterations are
 * recorded against the caller's RAW input (pre-sanitization baseline), with
 * element attribution and removed-tag names. Guards the fix for the empty
 * `stripped` diff: the read-back diff alone can never see what the normalizer
 * removed, because its baseline is captured after sanitization.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

// --- Minimal WordPress stubs (normalizer's sanitization dependencies) ------

if ( ! function_exists( 'wp_kses_post' ) ) {
	/**
	 * Emulate wp_kses_post closely enough for the strip-log contract:
	 * disallowed tags (script/iframe) are removed, allowed markup passes.
	 */
	function wp_kses_post( string $value ): string {
		$value = preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', $value ) ?? $value;
		$value = preg_replace( '#<iframe\b[^>]*>.*?</iframe\s*>#is', '', $value ) ?? $value;
		$value = preg_replace( '#</?(script|iframe)\b[^>]*>#i', '', $value ) ?? $value;
		return $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/MCP/Services/ElementNormalizer.php';

use LCBricksMCP\MCP\Services\ElementIdGenerator;
use LCBricksMCP\MCP\Services\ElementNormalizer;

$normalizer = new ElementNormalizer( new ElementIdGenerator() );

// --- removed_tags() static helper -------------------------------------------

lc_assert_same(
	[ 'script' ],
	ElementNormalizer::removed_tags( 'Hi <b>x</b> <script>alert(1)</script>', 'Hi <b>x</b> ' ),
	'removed_tags names the stripped tag and ignores surviving tags'
);
lc_assert_same(
	[],
	ElementNormalizer::removed_tags( 'plain before', 'plain after' ),
	'removed_tags is empty when no tags were involved'
);

// --- strip log: <script> in an HTML content key ------------------------------

$payload = 'Hello <script>alert(1)</script> world';
$flat    = $normalizer->normalize(
	[
		[
			'name'     => 'section',
			'children' => [
				[
					'name'     => 'container',
					'children' => [
						[ 'name' => 'heading', 'settings' => [ 'text' => $payload ] ],
					],
				],
			],
		],
	]
);

$heading = null;
foreach ( $flat as $el ) {
	if ( 'heading' === $el['name'] ) {
		$heading = $el;
	}
}

lc_assert( null !== $heading, 'normalized tree contains the heading' );
lc_assert(
	false === strpos( $heading['settings']['text'], '<script>' ),
	'script tag was sanitized out of the stored value'
);

$log = $normalizer->get_strip_log();
lc_assert_same( 1, count( $log ), 'exactly one strip entry recorded' );
lc_assert_same( $heading['id'], $log[0]['element_id'], 'strip entry attributed to the heading element' );
lc_assert_same( 'text', $log[0]['key'], 'strip entry names the settings key' );
lc_assert_same( strlen( $payload ), $log[0]['before_len'], 'before_len is the RAW caller input length' );
lc_assert( $log[0]['after_len'] < $log[0]['before_len'], 'after_len shrank' );
lc_assert_same( [ 'script' ], $log[0]['removed_tags'], 'removed_tags contains "script"' );

// --- consume clears -----------------------------------------------------------

$consumed = $normalizer->consume_strip_log();
lc_assert_same( 1, count( $consumed ), 'consume returns the pending entries' );
lc_assert_same( [], $normalizer->get_strip_log(), 'consume clears the log' );

// --- clean input produces no entries; new normalize resets old log ------------

$normalizer->normalize(
	[ [ 'name' => 'heading', 'settings' => [ 'text' => 'Perfectly <b>fine</b> text' ] ] ]
);
lc_assert_same( [], $normalizer->get_strip_log(), 'clean input logs nothing' );

$normalizer->normalize(
	[ [ 'name' => 'heading', 'settings' => [ 'text' => 'bad <script>x</script>' ] ] ]
);
lc_assert_same( 1, count( $normalizer->get_strip_log() ), 'dirty input logs again after reset' );
$normalizer->normalize(
	[ [ 'name' => 'heading', 'settings' => [ 'text' => 'clean' ] ] ]
);
lc_assert_same( [], $normalizer->get_strip_log(), 'normalize() resets the previous log' );

lc_test_done( 'StripLogTest' );
