<?php
/**
 * Unit test: MCP cannot make human approval decisions.
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

require_once __DIR__ . '/../../includes/Feedback/Workflow.php';
require_once __DIR__ . '/../../includes/Feedback/ToolProvider.php';

use LCBricksMCP\Feedback\ToolProvider;
use LCBricksMCP\Feedback\Workflow;

$workflow = ( new ReflectionClass( Workflow::class ) )->newInstanceWithoutConstructor();
$tools    = ( new ToolProvider( $workflow ) )->register_tool( [] );
$actions  = $tools['feedback_workflow']['inputSchema']['properties']['action']['enum'];

lc_assert( in_array( 'prepare_preview', $actions, true ), 'MCP may prepare a private preview' );
lc_assert( in_array( 'mark_ready', $actions, true ), 'MCP may submit a verified preview' );
lc_assert( ! in_array( 'approve', $actions, true ), 'MCP cannot approve' );
lc_assert( ! in_array( 'reject', $actions, true ), 'MCP cannot reject' );
lc_assert( ! in_array( 'rollback', $actions, true ), 'MCP cannot rollback' );
lc_assert( ! in_array( 'retry_resolve', $actions, true ), 'MCP cannot retry a human decision side effect' );

lc_test_done( 'FeedbackToolSafetyTest' );
