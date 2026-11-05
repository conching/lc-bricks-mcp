<?php
/**
 * Verified Slack interactivity endpoint.
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace LCBricksMCP\Feedback;

use LCBricksMCP\MCP\Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authenticates allowlisted Slack approvers and queues decisions for WP-Cron.
 */
final class SlackController {

	public const CRON_HOOK = 'lc_bricks_mcp_feedback_slack_action';

	private SlackVerifier $verifier;

	public function __construct( private Workflow $workflow, ?SlackVerifier $verifier = null ) {
		$this->verifier = $verifier ?? new SlackVerifier();
	}

	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_action' ), 10, 4 );
	}

	public function register_route(): void {
		register_rest_route(
			Server::API_NAMESPACE,
			'/feedback/slack/actions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Verify and queue one button interaction, returning within Slack's deadline.
	 */
	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( ! Credentials::slack_is_configured() ) {
			return new \WP_Error( 'slack_not_configured', __( 'Slack approvals are not configured.', 'lc-bricks-mcp' ), array( 'status' => 503 ) );
		}

		$body      = $request->get_body();
		$timestamp = (string) $request->get_header( 'x-slack-request-timestamp' );
		$signature = (string) $request->get_header( 'x-slack-signature' );
		if ( ! $this->verifier->verify( $body, $timestamp, $signature, Credentials::slack_signing_secret() ) ) {
			return new \WP_Error( 'invalid_slack_signature', __( 'Slack request signature is invalid or expired.', 'lc-bricks-mcp' ), array( 'status' => 403 ) );
		}

		$replay_key = 'lc_bricks_mcp_feedback_slack_' . hash( 'sha256', $signature . $body );
		if ( get_transient( $replay_key ) ) {
			return new \WP_REST_Response( array( 'response_type' => 'ephemeral', 'replace_original' => false, 'text' => 'This decision is already queued.' ), 200 );
		}

		parse_str( $body, $form );
		$payload = isset( $form['payload'] ) && is_string( $form['payload'] ) ? json_decode( $form['payload'], true ) : null;
		if ( ! is_array( $payload ) || 'block_actions' !== (string) ( $payload['type'] ?? '' ) ) {
			return new \WP_Error( 'invalid_slack_payload', __( 'Slack interaction payload is invalid.', 'lc-bricks-mcp' ), array( 'status' => 400 ) );
		}

		$team_id = is_array( $payload['team'] ?? null ) ? (string) ( $payload['team']['id'] ?? '' ) : '';
		$user_id = is_array( $payload['user'] ?? null ) ? (string) ( $payload['user']['id'] ?? '' ) : '';
		if ( ! hash_equals( Credentials::slack_team_id(), $team_id ) || ! in_array( $user_id, Credentials::slack_approver_ids(), true ) ) {
			return new \WP_Error( 'slack_approver_forbidden', __( 'This Slack user is not allowed to approve changes.', 'lc-bricks-mcp' ), array( 'status' => 403 ) );
		}

		$action = isset( $payload['actions'][0] ) && is_array( $payload['actions'][0] ) ? $payload['actions'][0] : array();
		$action_id = (string) ( $action['action_id'] ?? '' );
		$decision  = match ( $action_id ) {
			'lc_feedback_approve' => 'approve',
			'lc_feedback_reject'  => 'reject',
			default               => '',
		};
		$value = (string) ( $action['value'] ?? '' );
		[ $task_id_raw, $approval_hash ] = array_pad( explode( '|', $value, 2 ), 2, '' );
		$task_id = (int) $task_id_raw;
		if ( '' === $decision || $task_id <= 0 || 1 !== preg_match( '/^[a-f0-9]{64}$/', $approval_hash ) ) {
			return new \WP_Error( 'invalid_slack_action', __( 'Slack action is not recognized.', 'lc-bricks-mcp' ), array( 'status' => 400 ) );
		}

		$scheduled = wp_schedule_single_event( time() + 1, self::CRON_HOOK, array( $task_id, $decision, 'slack:' . $user_id, $approval_hash ), true );
		if ( is_wp_error( $scheduled ) || false === $scheduled ) {
			return new \WP_Error( 'slack_action_queue_failed', __( 'The Slack decision could not be queued.', 'lc-bricks-mcp' ), array( 'status' => 500 ) );
		}
		set_transient( $replay_key, 1, 10 * MINUTE_IN_SECONDS );

		return new \WP_REST_Response(
			array(
				'response_type'    => 'in_channel',
				'replace_original' => true,
				'text'             => sprintf( '%s queued by <@%s>. Follow the WordPress Feedback Queue for the final result.', ucfirst( $decision ), $user_id ),
			),
			200
		);
	}

	/**
	 * Run a previously verified decision outside Slack's three-second request window.
	 */
	public function run_action( int $task_id, string $decision, string $actor, string $approval_hash ): void {
		$result = 'approve' === $decision
			? $this->workflow->approve( $task_id, $actor, $approval_hash )
			: $this->workflow->reject( $task_id, 'Rejected from Slack.', $actor, $approval_hash );

		if ( is_wp_error( $result ) ) {
			$task = $this->workflow->get( $task_id );
			if ( ! is_wp_error( $task ) ) {
				( new SlackClient() )->update_status( $task, '*Decision failed safely.* ' . $result->get_error_message() . ' No automatic retry was attempted; use the WordPress Feedback Queue.' );
			}
		}
	}
}
