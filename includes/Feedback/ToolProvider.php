<?php
/**
 * Feedback workflow MCP tool provider.
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
 * Adds one bounded action-based tool through the plugin's extension hook.
 */
final class ToolProvider {

	public function __construct( private Workflow $workflow ) {}

	/**
	 * Register before the MCP Router is constructed.
	 */
	public function init(): void {
		add_filter( 'lc_bricks_mcp_tools', array( $this, 'register_tool' ) );
	}

	/**
	 * Add the feedback tool definition.
	 *
	 * @param array<string, array<string, mixed>> $tools Existing tools.
	 * @return array<string, array<string, mixed>>
	 */
	public function register_tool( array $tools ): array {
		$tools['feedback_workflow'] = array(
			'name'        => 'feedback_workflow',
			'description' => __( "Safely process Markup feedback. Actions: sync, list, get, set_triage, prepare_preview, mark_ready. prepare_preview creates a private duplicate and never changes the active page. Use existing page/element tools only on the returned preview_post_id, verify it in a browser, then call mark_ready with evidence. Human decisions are intentionally unavailable through MCP: approval, rejection, resolution retry, and rollback require signed Slack interaction or a nonce-protected WordPress administrator action.", 'lc-bricks-mcp' ),
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'action' => array(
						'type' => 'string',
						'enum' => array( 'sync', 'list', 'get', 'set_triage', 'prepare_preview', 'mark_ready' ),
					),
					'task_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'status' => array( 'type' => 'string' ),
					'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					'category' => array( 'type' => 'string', 'enum' => array( 'copy', 'asset', 'layout', 'style', 'functional', 'unclear' ) ),
					'risk' => array( 'type' => 'string', 'enum' => array( 'low', 'medium', 'high', 'unknown' ) ),
					'note' => array( 'type' => 'string', 'maxLength' => 2000 ),
					'source_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
					'summary' => array( 'type' => 'string', 'maxLength' => 4000 ),
					'verification' => array( 'type' => 'string', 'maxLength' => 4000 ),
				),
				'required'   => array( 'action' ),
			),
			'handler'     => array( $this, 'handle' ),
		);
		return $tools;
	}

	/**
	 * Dispatch a feedback tool action.
	 *
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>|array<int, array<string, mixed>>|\WP_Error
	 */
	public function handle( array $args ): array|\WP_Error {
		$action  = (string) ( $args['action'] ?? '' );
		$task_id = (int) ( $args['task_id'] ?? 0 );
		$actor   = 'wp:' . get_current_user_id();

		if ( ! in_array( $action, array( 'sync', 'list' ), true ) && $task_id <= 0 ) {
			return new \WP_Error( 'task_id_required', __( 'task_id is required for this feedback action.', 'lc-bricks-mcp' ) );
		}

		return match ( $action ) {
			'sync'            => $this->workflow->sync( $actor ),
			'list'            => $this->workflow->list( sanitize_key( (string) ( $args['status'] ?? '' ) ), (int) ( $args['limit'] ?? 50 ) ),
			'get'             => $this->workflow->get( $task_id ),
			'set_triage'      => $this->workflow->set_triage(
				$task_id,
				sanitize_key( (string) ( $args['category'] ?? '' ) ),
				sanitize_key( (string) ( $args['risk'] ?? '' ) ),
				(string) ( $args['note'] ?? '' ),
				$actor
			),
			'prepare_preview' => $this->workflow->prepare_preview( $task_id, (int) ( $args['source_post_id'] ?? 0 ), $actor ),
			'mark_ready'      => $this->workflow->mark_ready( $task_id, (string) ( $args['summary'] ?? '' ), (string) ( $args['verification'] ?? '' ), $actor ),
			default           => new \WP_Error( 'invalid_feedback_action', __( 'Unknown feedback workflow action.', 'lc-bricks-mcp' ) ),
		};
	}
}
