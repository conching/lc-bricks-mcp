<?php
/**
 * Feedback workflow state machine and preview promotion service.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\Feedback;

use LCBricksMCP\MCP\Services\BricksService;
use LCBricksMCP\MCP\Services\SchemaGenerator;
use LCBricksMCP\MCP\Services\ValidationService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Coordinates ingestion, triage, preview-only writes, approval, and rollback.
 */
final class Workflow {

	private TaskRepository $tasks;
	private MarkupClient $markup;
	private TriageService $triage;
	private BricksService $bricks;
	private SlackClient $slack;

	public function __construct(
		?TaskRepository $tasks = null,
		?MarkupClient $markup = null,
		?TriageService $triage = null,
		?BricksService $bricks = null,
		?SlackClient $slack = null
	) {
		$this->tasks  = $tasks ?? new TaskRepository();
		$this->markup = $markup ?? new MarkupClient();
		$this->triage = $triage ?? new TriageService();
		$this->bricks = $bricks ?? new BricksService();
		$this->slack = $slack ?? new SlackClient();

		$schemas = new SchemaGenerator();
		$this->bricks->set_validation_service( new ValidationService( $schemas ) );
	}

	/**
	 * Poll configured Markups and normalize unresolved threads into the queue.
	 *
	 * @return array<string, int>|\WP_Error
	 */
	public function sync( string $actor = 'system:cron' ): array|\WP_Error {
		$markup_ids = Credentials::markup_ids();
		if ( array() === $markup_ids ) {
			return new \WP_Error( 'markup_ids_missing', __( 'Configure at least one Markup ID before syncing.', 'lc-bricks-mcp' ) );
		}

		$counts = array( 'markups' => count( $markup_ids ), 'seen' => 0, 'created' => 0, 'updated' => 0 );
		foreach ( $markup_ids as $markup_id ) {
			$threads = $this->markup->list_open_threads( $markup_id );
			if ( is_wp_error( $threads ) ) {
				return $threads;
			}

			foreach ( $threads as $thread ) {
				++$counts['seen'];
				$normalized = $this->normalize_thread( $thread );
				if ( is_wp_error( $normalized ) ) {
					continue;
				}
				$result = $this->tasks->upsert( $normalized );
				if ( is_wp_error( $result ) ) {
					return $result;
				}

				$task_id = $result['task_id'];
				if ( $result['created'] ) {
					++$counts['created'];
					$this->tasks->audit( $task_id, 'ingested', $actor, array( 'markup_id' => $markup_id ) );
				} else {
					++$counts['updated'];
					$task = $this->tasks->get( $task_id );
					if ( ! is_wp_error( $task ) && in_array( (string) $task['status'], array( 'resolved', 'rolled_back' ), true ) ) {
						$this->tasks->update( $task_id, array( 'status' => 'inbox', 'last_error' => '' ) );
						$this->tasks->audit( $task_id, 'markup_reopened', $actor );
					}
				}
			}
		}

		return $counts;
	}

	/**
	 * List queue items.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list( string $status = '', int $limit = 50 ): array {
		return $this->tasks->list( $status, $limit );
	}

	/**
	 * Paginate queue items for the administrator dashboard.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function paginate( string $status = '', int $page = 1, int $per_page = 50 ): array {
		return $this->tasks->paginate( $status, $page, $per_page );
	}

	/**
	 * Get one queue item.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get( int $task_id ): array|\WP_Error {
		return $this->tasks->get( $task_id );
	}

	/**
	 * Override automatic triage after agent or human review.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function set_triage( int $task_id, string $category, string $risk, string $note, string $actor ): array|\WP_Error {
		$categories = array( 'copy', 'asset', 'layout', 'style', 'functional', 'unclear' );
		$risks      = array( 'low', 'medium', 'high', 'unknown' );
		if ( ! in_array( $category, $categories, true ) || ! in_array( $risk, $risks, true ) ) {
			return new \WP_Error( 'invalid_triage', __( 'Invalid feedback category or risk.', 'lc-bricks-mcp' ) );
		}

		$task = $this->tasks->get( $task_id );
		if ( is_wp_error( $task ) ) {
			return $task;
		}
		if ( ! in_array( (string) $task['status'], array( 'inbox', 'triaged' ), true ) ) {
			return new \WP_Error( 'invalid_feedback_state', __( 'Only inbox or triaged feedback can be re-triaged.', 'lc-bricks-mcp' ) );
		}

		$this->tasks->update(
			$task_id,
			array(
				'status'      => 'triaged',
				'category'    => $category,
				'risk'        => $risk,
				'confidence'  => 1.0,
				'triage_note' => sanitize_textarea_field( $note ),
			)
		);
		$this->tasks->audit( $task_id, 'triage_updated', $actor, array( 'category' => $category, 'risk' => $risk ) );
		return $this->tasks->get( $task_id );
	}

	/**
	 * Create a private page duplicate. The active source is not modified.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function prepare_preview( int $task_id, int $source_post_id, string $actor ): array|\WP_Error {
		$task = $this->tasks->get( $task_id );
		if ( is_wp_error( $task ) ) {
			return $task;
		}
		if ( ! in_array( (string) $task['status'], array( 'inbox', 'triaged' ), true ) ) {
			return new \WP_Error( 'invalid_feedback_state', __( 'This task cannot create another preview in its current state.', 'lc-bricks-mcp' ) );
		}

		if ( $source_post_id <= 0 ) {
			$source_post_id = $this->source_post_id_from_url( (string) $task['page_url'] );
		}
		$source = get_post( $source_post_id );
		if ( ! $source || 'page' !== $source->post_type || 'publish' !== $source->post_status ) {
			return new \WP_Error( 'invalid_source_page', __( 'Feedback previews are limited to published WordPress pages in this MVP.', 'lc-bricks-mcp' ) );
		}

		$source_elements = $this->bricks->get_elements( $source_post_id );
		if ( array() === $source_elements ) {
			return new \WP_Error( 'source_has_no_bricks_content', __( 'The source page has no Bricks elements to preview.', 'lc-bricks-mcp' ) );
		}

		$preview_id = wp_insert_post(
			array(
				'post_type'      => 'page',
				'post_status'    => 'private',
				'post_title'     => sprintf( 'Feedback Preview #%d — %s', $task_id, $source->post_title ),
				'post_content'   => $source->post_content,
				'post_excerpt'   => $source->post_excerpt,
				'post_author'    => get_current_user_id() ?: (int) $source->post_author,
				'post_parent'    => 0,
				'menu_order'     => 0,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			),
			true
		);
		if ( is_wp_error( $preview_id ) ) {
			return $preview_id;
		}

		foreach ( array( '_wp_page_template', '_thumbnail_id', '_bricks_page_settings' ) as $meta_key ) {
			$value = get_post_meta( $source_post_id, $meta_key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $preview_id, $meta_key, $value );
			}
		}
		update_post_meta( $preview_id, '_lc_feedback_preview_task', $task_id );
		update_post_meta( $preview_id, '_lc_feedback_source_post', $source_post_id );

		$persistence = null;
		$saved       = $this->bricks->save_elements( (int) $preview_id, $source_elements, $persistence );
		if ( is_wp_error( $saved ) ) {
			wp_trash_post( (int) $preview_id );
			return $saved;
		}

		$preview_url = get_permalink( (int) $preview_id );
		$this->tasks->update(
			$task_id,
			array(
				'status'          => 'preview_in_progress',
				'source_post_id'  => $source_post_id,
				'preview_post_id' => (int) $preview_id,
				'preview_url'     => is_string( $preview_url ) ? $preview_url : '',
				'base_hash'       => $this->elements_hash( $source_elements ),
				'last_error'      => '',
			)
		);
		$this->tasks->audit( $task_id, 'preview_created', $actor, array( 'source_post_id' => $source_post_id, 'preview_post_id' => (int) $preview_id ) );
		return $this->tasks->get( $task_id );
	}

	/**
	 * Lock a changed private preview for explicit approval.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function mark_ready( int $task_id, string $summary, string $verification, string $actor ): array|\WP_Error {
		$task = $this->tasks->get( $task_id );
		if ( is_wp_error( $task ) ) {
			return $task;
		}
		if ( ! in_array( (string) $task['status'], array( 'preview_in_progress', 'awaiting_approval' ), true ) ) {
			return new \WP_Error( 'invalid_feedback_state', __( 'Only an in-progress preview can be submitted for approval.', 'lc-bricks-mcp' ) );
		}
		if ( '' === trim( $summary ) || '' === trim( $verification ) ) {
			return new \WP_Error( 'approval_evidence_required', __( 'A change summary and browser verification note are required.', 'lc-bricks-mcp' ) );
		}

		$preview_elements = $this->bricks->get_elements( (int) $task['preview_post_id'] );
		if ( array() === $preview_elements || $this->elements_hash( $preview_elements ) === (string) $task['base_hash'] ) {
			return new \WP_Error( 'preview_unchanged', __( 'The private preview does not contain a Bricks change.', 'lc-bricks-mcp' ) );
		}

		if ( 'awaiting_approval' === (string) $task['status'] ) {
			$this->slack->update_status( $task, '*Superseded.* The private preview changed and a new approval card was issued after re-verification.' );
		}

		$this->tasks->update(
			$task_id,
			array(
				'status'            => 'awaiting_approval',
				'ready_hash'        => $this->elements_hash( $preview_elements ),
				'change_summary'    => sanitize_textarea_field( $summary ),
				'verification_note' => sanitize_textarea_field( $verification ),
				'last_error'        => '',
			)
		);
		$this->tasks->audit( $task_id, 'submitted_for_approval', $actor );

		$task     = $this->tasks->get( $task_id );
		$slack_ts = is_wp_error( $task ) ? '' : $this->slack->post_approval( $task );
		if ( is_wp_error( $slack_ts ) ) {
			$this->tasks->update( $task_id, array( 'last_error' => $slack_ts->get_error_message() ) );
			$this->tasks->audit( $task_id, 'slack_notification_failed', 'system' );
		} elseif ( '' !== $slack_ts ) {
			$this->tasks->update( $task_id, array( 'slack_message_ts' => $slack_ts ) );
			$this->tasks->audit( $task_id, 'slack_notification_sent', 'system' );
		}

		return $this->tasks->get( $task_id );
	}

	/**
	 * Promote an approved preview and resolve the Markup thread after persistence.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function approve( int $task_id, string $actor, string $expected_ready_hash = '' ): array|\WP_Error {
		$task = $this->tasks->get( $task_id );
		if ( is_wp_error( $task ) ) {
			return $task;
		}
		if ( 'awaiting_approval' !== (string) $task['status'] ) {
			return new \WP_Error( 'invalid_feedback_state', __( 'Only feedback awaiting approval can be applied.', 'lc-bricks-mcp' ) );
		}
		if ( '' === $expected_ready_hash || 1 !== preg_match( '/^[a-f0-9]{64}$/', $expected_ready_hash ) || ! hash_equals( (string) $task['ready_hash'], $expected_ready_hash ) ) {
			return new \WP_Error( 'stale_approval', __( 'This approval was issued for an older preview. Reload the dashboard or use the newest Slack card.', 'lc-bricks-mcp' ), array( 'status' => 409 ) );
		}

		$source_id        = (int) $task['source_post_id'];
		$preview_id       = (int) $task['preview_post_id'];
		$source_elements  = $this->bricks->get_elements( $source_id );
		$preview_elements = $this->bricks->get_elements( $preview_id );
		if ( $this->elements_hash( $source_elements ) !== (string) $task['base_hash'] ) {
			return new \WP_Error( 'source_changed', __( 'The active source page changed after the preview was created. Rebase the preview before approval.', 'lc-bricks-mcp' ), array( 'status' => 409 ) );
		}
		if ( array() === $preview_elements ) {
			return new \WP_Error( 'preview_missing', __( 'The private preview no longer contains Bricks elements.', 'lc-bricks-mcp' ) );
		}
		if ( $this->elements_hash( $preview_elements ) !== (string) $task['ready_hash'] ) {
			return new \WP_Error( 'preview_changed', __( 'The private preview changed after it was submitted for review. Verify and submit it again before approval.', 'lc-bricks-mcp' ), array( 'status' => 409 ) );
		}

		$snapshot_id = $this->tasks->create_snapshot( $task_id, $source_id, $source_elements );
		if ( is_wp_error( $snapshot_id ) ) {
			return $snapshot_id;
		}

		$persistence = null;
		$applied     = $this->bricks->save_elements( $source_id, $preview_elements, $persistence );
		if ( is_wp_error( $applied ) ) {
			$restored = $this->bricks->save_elements( $source_id, $source_elements );
			$this->tasks->audit( $task_id, 'promotion_failed', $actor, array( 'automatic_restore' => ! is_wp_error( $restored ) ) );
			return $applied;
		}

		$this->tasks->update(
			$task_id,
			array(
				'status'           => 'applied_unresolved',
				'snapshot_post_id' => $snapshot_id,
				'applied_hash'     => $this->elements_hash( $preview_elements ),
				'last_error'       => '',
			)
		);
		$this->tasks->audit( $task_id, 'approved_and_applied', $actor, array( 'source_post_id' => $source_id, 'snapshot_post_id' => $snapshot_id ) );

		$resolved = $this->markup->resolve_thread( (string) $task['thread_id'] );
		if ( is_wp_error( $resolved ) ) {
			$this->tasks->update( $task_id, array( 'last_error' => $resolved->get_error_message() ) );
			$this->tasks->audit( $task_id, 'markup_resolution_failed', 'system' );
		} else {
			$this->tasks->update( $task_id, array( 'status' => 'resolved', 'last_error' => '' ) );
			$this->tasks->audit( $task_id, 'markup_resolved', 'system' );
		}

		$final = $this->tasks->get( $task_id );
		if ( ! is_wp_error( $final ) ) {
			$this->slack->update_status( $final, sprintf( '*Approved and applied* by %s. Status: `%s`.', $actor, $final['status'] ) );
		}
		return $final;
	}

	/**
	 * Reject a task and move its private preview to Trash (recoverable).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function reject( int $task_id, string $reason, string $actor, string $expected_ready_hash = '' ): array|\WP_Error {
		$task = $this->tasks->get( $task_id );
		if ( is_wp_error( $task ) ) {
			return $task;
		}
		if ( ! in_array( (string) $task['status'], array( 'inbox', 'triaged', 'preview_in_progress', 'awaiting_approval' ), true ) ) {
			return new \WP_Error( 'invalid_feedback_state', __( 'This feedback task cannot be rejected in its current state.', 'lc-bricks-mcp' ) );
		}
		if ( 'awaiting_approval' === (string) $task['status'] && ( '' === $expected_ready_hash || ! hash_equals( (string) $task['ready_hash'], $expected_ready_hash ) ) ) {
			return new \WP_Error( 'stale_rejection', __( 'This rejection was issued for an older preview. Reload the dashboard or use the newest Slack card.', 'lc-bricks-mcp' ), array( 'status' => 409 ) );
		}

		$preview_id = (int) $task['preview_post_id'];
		if ( $preview_id > 0 ) {
			wp_trash_post( $preview_id );
		}
		$this->tasks->update( $task_id, array( 'status' => 'rejected', 'last_error' => '' ) );
		$this->tasks->audit( $task_id, 'rejected', $actor, array( 'reason' => sanitize_textarea_field( $reason ), 'preview_trashed' => $preview_id > 0 ) );

		$final = $this->tasks->get( $task_id );
		if ( ! is_wp_error( $final ) ) {
			$this->slack->update_status( $final, sprintf( '*Rejected* by %s. The active source page was not changed.', $actor ) );
		}
		return $final;
	}

	/**
	 * Retry Markup resolution after a successful local promotion.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function retry_resolve( int $task_id, string $actor ): array|\WP_Error {
		$task = $this->tasks->get( $task_id );
		if ( is_wp_error( $task ) ) {
			return $task;
		}
		if ( 'applied_unresolved' !== (string) $task['status'] ) {
			return new \WP_Error( 'invalid_feedback_state', __( 'Only an applied but unresolved task can retry Markup resolution.', 'lc-bricks-mcp' ) );
		}

		$result = $this->markup->resolve_thread( (string) $task['thread_id'] );
		if ( is_wp_error( $result ) ) {
			$this->tasks->update( $task_id, array( 'last_error' => $result->get_error_message() ) );
			return $result;
		}
		$this->tasks->update( $task_id, array( 'status' => 'resolved', 'last_error' => '' ) );
		$this->tasks->audit( $task_id, 'markup_resolved', $actor );
		return $this->tasks->get( $task_id );
	}

	/**
	 * Restore the exact pre-approval snapshot when the active page has not drifted.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function rollback( int $task_id, string $actor ): array|\WP_Error {
		$task = $this->tasks->get( $task_id );
		if ( is_wp_error( $task ) ) {
			return $task;
		}
		if ( ! in_array( (string) $task['status'], array( 'resolved', 'applied_unresolved' ), true ) ) {
			return new \WP_Error( 'invalid_feedback_state', __( 'Only an applied feedback task can be rolled back.', 'lc-bricks-mcp' ) );
		}

		$source_id       = (int) $task['source_post_id'];
		$current_elements = $this->bricks->get_elements( $source_id );
		if ( $this->elements_hash( $current_elements ) !== (string) $task['applied_hash'] ) {
			return new \WP_Error( 'source_changed', __( 'The source page changed after approval. Automatic rollback is blocked to avoid overwriting later work.', 'lc-bricks-mcp' ), array( 'status' => 409 ) );
		}

		$snapshot = $this->tasks->snapshot_elements( (int) $task['snapshot_post_id'] );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		$restored = $this->bricks->save_elements( $source_id, $snapshot );
		if ( is_wp_error( $restored ) ) {
			return $restored;
		}

		$status   = 'rolled_back';
		$reopened = $this->markup->unresolve_thread( (string) $task['thread_id'] );
		if ( is_wp_error( $reopened ) && 'resolved' === (string) $task['status'] ) {
			$status = 'rolled_back_markup_resolved';
		}
		$this->tasks->update(
			$task_id,
			array(
				'status'     => $status,
				'last_error' => is_wp_error( $reopened ) ? $reopened->get_error_message() : '',
			)
		);
		$this->tasks->audit( $task_id, 'rolled_back', $actor, array( 'source_post_id' => $source_id, 'markup_reopened' => ! is_wp_error( $reopened ) ) );
		return $this->tasks->get( $task_id );
	}

	/**
	 * Convert a Markup thread response into the intentionally small local model.
	 *
	 * @param array<string, mixed> $thread Markup thread response.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function normalize_thread( array $thread ): array|\WP_Error {
		$thread_id = isset( $thread['id'] ) ? (string) $thread['id'] : '';
		$markup_id = isset( $thread['_lc_markup_id'] ) ? (string) $thread['_lc_markup_id'] : '';
		if ( '' === $thread_id || '' === $markup_id ) {
			return new \WP_Error( 'invalid_markup_thread', __( 'Markup returned a thread without required identifiers.', 'lc-bricks-mcp' ) );
		}

		$comment  = '';
		$messages = isset( $thread['messages'] ) && is_array( $thread['messages'] ) ? $thread['messages'] : array();
		if ( isset( $messages[0] ) && is_array( $messages[0] ) ) {
			$message = $messages[0]['message'] ?? '';
			$comment = is_string( $message ) ? $message : wp_json_encode( $message );
		}
		$comment = sanitize_textarea_field( $comment );
		$triage  = $this->triage->triage( $comment );

		$user      = isset( $thread['user'] ) && is_array( $thread['user'] ) ? $thread['user'] : array();
		$page_url  = $this->first_url( $thread, array( 'canonicalUrl', 'originalUrl' ) );
		$markup_url = sprintf( 'https://app.markup.io/markup/%s/#thread/%s', rawurlencode( $markup_id ), rawurlencode( $thread_id ) );

		return array(
			'markup_id'   => $markup_id,
			'thread_id'   => $thread_id,
			'markup_url'  => $markup_url,
			'page_url'    => $page_url,
			'comment'     => $comment,
			'author_name' => sanitize_text_field( (string) ( $user['name'] ?? '' ) ),
			'category'    => $triage['category'],
			'risk'        => $triage['risk'],
			'confidence'  => $triage['confidence'],
			'triage_note' => $triage['reason'],
		);
	}

	/**
	 * Resolve an exact same-site page URL to a post ID.
	 */
	private function source_post_id_from_url( string $url ): int {
		$target_host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$site_host   = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		if ( '' === $target_host || ! hash_equals( $site_host, $target_host ) ) {
			return 0;
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return 0;
		}
		$normalized = home_url( (string) ( $parts['path'] ?? '/' ) );
		return (int) url_to_postid( $normalized );
	}

	/**
	 * Compute a stable optimistic-concurrency token for a Bricks tree.
	 *
	 * @param array<int, array<string, mixed>> $elements Bricks elements.
	 */
	private function elements_hash( array $elements ): string {
		return hash( 'sha256', (string) wp_json_encode( $elements, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Read the first safe HTTPS URL from a response.
	 *
	 * @param array<string, mixed> $source Source array.
	 * @param array<int, string>   $keys Candidate keys.
	 */
	private function first_url( array $source, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = isset( $source[ $key ] ) ? esc_url_raw( (string) $source[ $key ] ) : '';
			if ( str_starts_with( $value, 'https://' ) ) {
				return $value;
			}
		}
		return '';
	}
}
