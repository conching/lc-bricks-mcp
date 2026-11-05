<?php
/**
 * Private feedback task and rollback snapshot persistence.
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
 * Stores normalized feedback and append-only audit entries through WordPress APIs.
 */
final class TaskRepository {

	public const TASK_POST_TYPE     = 'lc_feedback_task';
	public const SNAPSHOT_POST_TYPE = 'lc_feedback_snap';

	/**
	 * Register internal post types. They have no public routes or UI.
	 */
	public function register_post_types(): void {
		$common = array(
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_rest'        => false,
			'exclude_from_search' => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'supports'            => array( 'title' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		);

		register_post_type( self::TASK_POST_TYPE, $common );
		register_post_type( self::SNAPSHOT_POST_TYPE, $common );
	}

	/**
	 * Create or refresh a normalized Markup thread.
	 *
	 * @param array<string, mixed> $thread Normalized thread data.
	 * @return array{task_id: int, created: bool}|\WP_Error
	 */
	public function upsert( array $thread ): array|\WP_Error {
		$thread_id = (string) ( $thread['thread_id'] ?? '' );
		if ( '' === $thread_id ) {
			return new \WP_Error( 'missing_thread_id', __( 'Cannot store feedback without a thread ID.', 'lc-bricks-mcp' ) );
		}

		$task_id = $this->find_by_thread_id( $thread_id );
		$created = false;
		if ( 0 === $task_id ) {
			$title = (string) ( $thread['comment'] ?? __( 'Markup feedback', 'lc-bricks-mcp' ) );
			$title = wp_trim_words( wp_strip_all_tags( $title ), 14, '…' );
			$task_id = wp_insert_post(
				array(
					'post_type'   => self::TASK_POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => '' !== $title ? $title : __( 'Markup feedback', 'lc-bricks-mcp' ),
				),
				true
			);
			if ( is_wp_error( $task_id ) ) {
				return $task_id;
			}
			$created = true;
			update_post_meta( $task_id, '_lc_status', 'inbox' );
		}

		$mapping = array(
			'markup_id'   => '_lc_markup_id',
			'thread_id'   => '_lc_markup_thread_id',
			'markup_url'  => '_lc_markup_url',
			'page_url'    => '_lc_page_url',
			'comment'     => '_lc_comment',
			'author_name' => '_lc_author_name',
		);
		foreach ( $mapping as $source => $meta_key ) {
			if ( array_key_exists( $source, $thread ) ) {
				update_post_meta( $task_id, $meta_key, $thread[ $source ] );
			}
		}
		// Never overwrite an agent/human triage decision during later polls.
		// Deterministic triage is only the initial classification.
		$triage_mapping = array(
			'category'    => '_lc_category',
			'risk'        => '_lc_risk',
			'confidence'  => '_lc_triage_confidence',
			'triage_note' => '_lc_triage_note',
		);
		foreach ( $triage_mapping as $source => $meta_key ) {
			if ( array_key_exists( $source, $thread ) && ( $created || '' === get_post_meta( $task_id, $meta_key, true ) ) ) {
				update_post_meta( $task_id, $meta_key, $thread[ $source ] );
			}
		}

		update_post_meta( $task_id, '_lc_last_synced_at', gmdate( 'c' ) );
		return array( 'task_id' => (int) $task_id, 'created' => $created );
	}

	/**
	 * Find a task by its immutable Markup thread ID.
	 */
	public function find_by_thread_id( string $thread_id ): int {
		$ids = get_posts(
			array(
				'post_type'              => self::TASK_POST_TYPE,
				'post_status'            => 'any',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'meta_key'               => '_lc_markup_thread_id',
				'meta_value'             => $thread_id,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return isset( $ids[0] ) ? (int) $ids[0] : 0;
	}

	/**
	 * Fetch a task in API/dashboard form.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get( int $task_id ): array|\WP_Error {
		$post = get_post( $task_id );
		if ( ! $post || self::TASK_POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'feedback_not_found', __( 'Feedback task was not found.', 'lc-bricks-mcp' ) );
		}

		$integer_keys = array( 'source_post_id', 'preview_post_id', 'snapshot_post_id' );
		$keys = array(
			'status'             => '_lc_status',
			'markup_id'          => '_lc_markup_id',
			'thread_id'          => '_lc_markup_thread_id',
			'markup_url'         => '_lc_markup_url',
			'page_url'           => '_lc_page_url',
			'comment'            => '_lc_comment',
			'author_name'        => '_lc_author_name',
			'category'           => '_lc_category',
			'risk'               => '_lc_risk',
			'confidence'         => '_lc_triage_confidence',
			'triage_note'        => '_lc_triage_note',
			'source_post_id'     => '_lc_source_post_id',
			'preview_post_id'    => '_lc_preview_post_id',
			'preview_url'        => '_lc_preview_url',
			'base_hash'          => '_lc_base_hash',
			'ready_hash'         => '_lc_ready_hash',
			'applied_hash'       => '_lc_applied_hash',
			'snapshot_post_id'   => '_lc_snapshot_post_id',
			'change_summary'     => '_lc_change_summary',
			'verification_note'  => '_lc_verification_note',
			'slack_message_ts'   => '_lc_slack_message_ts',
			'last_error'         => '_lc_last_error',
			'last_synced_at'     => '_lc_last_synced_at',
		);

		$task = array(
			'id'         => $task_id,
			'title'      => get_the_title( $task_id ),
			'created_at' => get_post_time( 'c', true, $post ),
			'updated_at' => get_post_modified_time( 'c', true, $post ),
		);
		foreach ( $keys as $name => $meta_key ) {
			$value         = get_post_meta( $task_id, $meta_key, true );
			$task[ $name ] = in_array( $name, $integer_keys, true ) ? (int) $value : $value;
		}

		$audit         = get_post_meta( $task_id, '_lc_audit_log', true );
		$task['audit'] = is_array( $audit ) ? $audit : array();
		return $task;
	}

	/**
	 * List recent tasks, optionally filtered by workflow status.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list( string $status = '', int $limit = 50 ): array {
		$args = array(
			'post_type'              => self::TASK_POST_TYPE,
			'post_status'            => 'any',
			'posts_per_page'         => max( 1, min( 100, $limit ) ),
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);
		if ( '' !== $status ) {
			$args['meta_key']   = '_lc_status';
			$args['meta_value'] = sanitize_key( $status );
		}

		return $this->hydrate_task_ids( get_posts( $args ) );
	}

	/**
	 * Paginate queue items for the administrator dashboard.
	 *
	 * @return array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function paginate( string $status = '', int $page = 1, int $per_page = 50 ): array {
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$args     = array(
			'post_type'              => self::TASK_POST_TYPE,
			'post_status'            => 'any',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => 'modified',
			'order'                  => 'DESC',
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);
		if ( '' !== $status ) {
			$args['meta_key']   = '_lc_status';
			$args['meta_value'] = sanitize_key( $status );
		}

		$query = new \WP_Query( $args );
		$total = (int) $query->found_posts;

		return array(
			'items'       => $this->hydrate_task_ids( is_array( $query->posts ) ? $query->posts : array() ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Hydrate internal post IDs into dashboard-safe task arrays.
	 *
	 * @param array<int, int|string> $task_ids Internal task post IDs.
	 * @return array<int, array<string, mixed>>
	 */
	private function hydrate_task_ids( array $task_ids ): array {

		$tasks = array();
		foreach ( $task_ids as $task_id ) {
			$task = $this->get( (int) $task_id );
			if ( ! is_wp_error( $task ) ) {
				unset( $task['audit'] );
				$tasks[] = $task;
			}
		}
		return $tasks;
	}

	/**
	 * Update controlled workflow fields.
	 *
	 * @param array<string, mixed> $fields Field name to value.
	 */
	public function update( int $task_id, array $fields ): true|\WP_Error {
		$allowed = array(
			'status'            => '_lc_status',
			'category'          => '_lc_category',
			'risk'              => '_lc_risk',
			'confidence'        => '_lc_triage_confidence',
			'triage_note'       => '_lc_triage_note',
			'source_post_id'    => '_lc_source_post_id',
			'preview_post_id'   => '_lc_preview_post_id',
			'preview_url'       => '_lc_preview_url',
			'base_hash'         => '_lc_base_hash',
			'ready_hash'        => '_lc_ready_hash',
			'applied_hash'      => '_lc_applied_hash',
			'snapshot_post_id'  => '_lc_snapshot_post_id',
			'change_summary'    => '_lc_change_summary',
			'verification_note' => '_lc_verification_note',
			'slack_message_ts'  => '_lc_slack_message_ts',
			'last_error'        => '_lc_last_error',
		);

		if ( is_wp_error( $this->get( $task_id ) ) ) {
			return new \WP_Error( 'feedback_not_found', __( 'Feedback task was not found.', 'lc-bricks-mcp' ) );
		}

		foreach ( $fields as $name => $value ) {
			if ( isset( $allowed[ $name ] ) ) {
				update_post_meta( $task_id, $allowed[ $name ], $value );
			}
		}
		wp_update_post( array( 'ID' => $task_id ) );
		return true;
	}

	/**
	 * Append an audit record. No credentials or raw remote payloads are accepted.
	 *
	 * @param array<string, scalar|null> $details Safe structured details.
	 */
	public function audit( int $task_id, string $event, string $actor, array $details = array() ): void {
		$audit = get_post_meta( $task_id, '_lc_audit_log', true );
		$audit = is_array( $audit ) ? $audit : array();
		$audit[] = array(
			'at'      => gmdate( 'c' ),
			'event'   => sanitize_key( $event ),
			'actor'   => sanitize_text_field( $actor ),
			'details' => array_map(
				static fn ( mixed $value ): string|int|float|bool|null => is_scalar( $value ) || null === $value ? $value : (string) wp_json_encode( $value ),
				$details
			),
		);
		update_post_meta( $task_id, '_lc_audit_log', $audit );
	}

	/**
	 * Create an immutable rollback snapshot of Bricks elements.
	 *
	 * @param array<int, array<string, mixed>> $elements Source Bricks elements.
	 * @return int|\WP_Error Snapshot post ID or error.
	 */
	public function create_snapshot( int $task_id, int $source_post_id, array $elements ): int|\WP_Error {
		$snapshot_id = wp_insert_post(
			array(
				'post_type'   => self::SNAPSHOT_POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Feedback %d rollback snapshot', $task_id ),
			),
			true
		);
		if ( is_wp_error( $snapshot_id ) ) {
			return $snapshot_id;
		}

		update_post_meta( $snapshot_id, '_lc_task_id', $task_id );
		update_post_meta( $snapshot_id, '_lc_source_post_id', $source_post_id );
		update_post_meta( $snapshot_id, '_lc_elements', $elements );
		update_post_meta( $snapshot_id, '_lc_created_at', gmdate( 'c' ) );
		return (int) $snapshot_id;
	}

	/**
	 * Read snapshot elements.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function snapshot_elements( int $snapshot_id ): array|\WP_Error {
		$post = get_post( $snapshot_id );
		if ( ! $post || self::SNAPSHOT_POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'snapshot_not_found', __( 'Rollback snapshot was not found.', 'lc-bricks-mcp' ) );
		}
		$elements = get_post_meta( $snapshot_id, '_lc_elements', true );
		return is_array( $elements ) ? $elements : new \WP_Error( 'snapshot_invalid', __( 'Rollback snapshot is invalid.', 'lc-bricks-mcp' ) );
	}
}
