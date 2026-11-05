<?php
/**
 * Feedback workflow module composition root.
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
 * Wires persistence, MCP tools, polling, Slack, and the dashboard.
 */
final class Module {

	public const SYNC_HOOK = 'lc_bricks_mcp_feedback_sync';

	private TaskRepository $tasks;
	private Workflow $workflow;

	public function __construct() {
		$this->tasks    = new TaskRepository();
		$this->workflow = new Workflow( $this->tasks );
	}

	public function init(): void {
		add_action( 'init', array( $this->tasks, 'register_post_types' ), 5 );
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );
		add_action( 'init', array( $this, 'reconcile_schedule' ), 20 );
		add_action( self::SYNC_HOOK, array( $this, 'run_sync' ) );

		( new ToolProvider( $this->workflow ) )->init();
		( new SlackController( $this->workflow ) )->init();

		if ( is_admin() ) {
			( new AdminPage( $this->workflow ) )->init();
		}
	}

	/**
	 * Add a fixed five-minute polling interval.
	 *
	 * @param array<string, array<string, int|string>> $schedules Existing schedules.
	 * @return array<string, array<string, int|string>>
	 */
	public function add_cron_schedule( array $schedules ): array {
		$schedules['lc_feedback_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every five minutes (LC feedback)', 'lc-bricks-mcp' ),
		);
		return $schedules;
	}

	/**
	 * Schedule only when explicitly enabled and fully configured.
	 */
	public function reconcile_schedule(): void {
		$settings = get_option( 'lc_feedback_settings', array() );
		$enabled  = is_array( $settings ) && ! empty( $settings['enabled'] );
		$ready    = $enabled && '' !== Credentials::markup_api_key() && array() !== Credentials::markup_ids();
		$next     = wp_next_scheduled( self::SYNC_HOOK );

		if ( $ready && ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'lc_feedback_five_minutes', self::SYNC_HOOK );
		} elseif ( ! $ready && $next ) {
			wp_clear_scheduled_hook( self::SYNC_HOOK );
		}
	}

	/**
	 * Run a background sync without leaking errors to public output.
	 */
	public function run_sync(): void {
		$result = $this->workflow->sync();
		if ( is_wp_error( $result ) ) {
			update_option( 'lc_feedback_last_sync_error', $result->get_error_message(), false );
			return;
		}
		delete_option( 'lc_feedback_last_sync_error' );
		update_option( 'lc_feedback_last_sync_at', gmdate( 'c' ), false );
	}
}
