<?php
/**
 * Unit test: Slack request verification rejects tampering and replays.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap-simple.php';
require_once __DIR__ . '/../../includes/Feedback/SlackVerifier.php';

use LCBricksMCP\Feedback\SlackVerifier;

$verifier  = new SlackVerifier();
$secret    = 'unit-test-signing-secret';
$timestamp = '1720000000';
$body      = 'payload=%7B%22type%22%3A%22block_actions%22%7D';
$signature = 'v0=' . hash_hmac( 'sha256', 'v0:' . $timestamp . ':' . $body, $secret );

lc_assert( $verifier->verify( $body, $timestamp, $signature, $secret, 1720000100 ), 'valid Slack signature passes' );
lc_assert( ! $verifier->verify( $body . 'tampered', $timestamp, $signature, $secret, 1720000100 ), 'tampered body fails' );
lc_assert( ! $verifier->verify( $body, $timestamp, $signature, $secret, 1720000400 ), 'request older than five minutes fails' );
lc_assert( ! $verifier->verify( $body, 'not-a-time', $signature, $secret, 1720000100 ), 'non-numeric timestamp fails' );
lc_assert( ! $verifier->verify( $body, $timestamp, 'v0=bad', $secret, 1720000100 ), 'invalid signature fails' );

lc_test_done( 'SlackVerifierTest' );
