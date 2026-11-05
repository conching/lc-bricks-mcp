<?php
/**
 * Least-privilege Slack approval notifications.
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
 * Sends and updates approval cards through a configured Slack bot.
 */
final class SlackClient {

	private const API_BASE = 'https://slack.com/api/';

	/**
	 * Post a new interactive approval card.
	 *
	 * @param array<string, mixed> $task Feedback task.
	 * @return string|\WP_Error Slack message timestamp, empty string when disabled, or error.
	 */
	public function post_approval( array $task ): string|\WP_Error {
		if ( ! Credentials::slack_is_configured() ) {
			return '';
		}

		$task_id = (int) ( $task['id'] ?? 0 );
		$summary = (string) ( $task['change_summary'] ?? '' );
		$payload = array(
			'channel' => Credentials::slack_channel_id(),
			'text'    => sprintf( 'Feedback #%d is ready for approval: %s', $task_id, $summary ),
			'blocks'  => $this->approval_blocks( $task ),
		);

		$response = $this->request( 'chat.postMessage', $payload );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return isset( $response['ts'] ) && is_string( $response['ts'] ) ? $response['ts'] : '';
	}

	/**
	 * Replace an approval card with a final status message.
	 *
	 * @param array<string, mixed> $task Feedback task.
	 */
	public function update_status( array $task, string $message ): true|\WP_Error {
		$timestamp = (string) ( $task['slack_message_ts'] ?? '' );
		if ( ! Credentials::slack_is_configured() || '' === $timestamp ) {
			return true;
		}

		$response = $this->request(
			'chat.update',
			array(
				'channel' => Credentials::slack_channel_id(),
				'ts'      => $timestamp,
				'text'    => $message,
				'blocks'  => array(
					array(
						'type' => 'section',
						'text' => array( 'type' => 'mrkdwn', 'text' => $message ),
					),
				),
			)
		);

		return is_wp_error( $response ) ? $response : true;
	}

	/**
	 * Build a bounded Block Kit card.
	 *
	 * @param array<string, mixed> $task Feedback task.
	 * @return array<int, array<string, mixed>>
	 */
	private function approval_blocks( array $task ): array {
		$task_id      = (int) ( $task['id'] ?? 0 );
		$decision_value = $task_id . '|' . (string) ( $task['ready_hash'] ?? '' );
		$comment      = $this->slack_text( (string) ( $task['comment'] ?? '' ), 900 );
		$summary      = $this->slack_text( (string) ( $task['change_summary'] ?? '' ), 900 );
		$verification = $this->slack_text( (string) ( $task['verification_note'] ?? '' ), 500 );
		$fields       = array(
			array( 'type' => 'mrkdwn', 'text' => '*Category*\n' . $this->slack_text( (string) ( $task['category'] ?? 'unclear' ), 80 ) ),
			array( 'type' => 'mrkdwn', 'text' => '*Risk*\n' . $this->slack_text( (string) ( $task['risk'] ?? 'unknown' ), 80 ) ),
		);

		$blocks = array(
			array(
				'type' => 'header',
				'text' => array( 'type' => 'plain_text', 'text' => sprintf( 'Feedback #%d ready for approval', $task_id ) ),
			),
			array(
				'type' => 'section',
				'text' => array( 'type' => 'mrkdwn', 'text' => "*Client feedback*\n{$comment}" ),
			),
			array(
				'type'   => 'section',
				'fields' => $fields,
			),
			array(
				'type' => 'section',
				'text' => array( 'type' => 'mrkdwn', 'text' => "*Proposed change*\n{$summary}\n\n*Verification*\n{$verification}" ),
			),
		);

		$link_elements = array();
		$preview_url   = esc_url_raw( (string) ( $task['preview_url'] ?? '' ) );
		$markup_url    = esc_url_raw( (string) ( $task['markup_url'] ?? '' ) );
		if ( '' !== $preview_url ) {
			$link_elements[] = array( 'type' => 'button', 'text' => array( 'type' => 'plain_text', 'text' => 'Open private preview' ), 'url' => $preview_url );
		}
		if ( '' !== $markup_url ) {
			$link_elements[] = array( 'type' => 'button', 'text' => array( 'type' => 'plain_text', 'text' => 'Open Markup' ), 'url' => $markup_url );
		}
		if ( array() !== $link_elements ) {
			$blocks[] = array( 'type' => 'actions', 'elements' => $link_elements );
		}

		$blocks[] = array(
			'type'     => 'actions',
			'block_id' => 'lc_feedback_decision',
			'elements' => array(
				array(
					'type'      => 'button',
					'text'      => array( 'type' => 'plain_text', 'text' => 'Approve' ),
					'style'     => 'primary',
					'action_id' => 'lc_feedback_approve',
					'value'     => $decision_value,
					'confirm'   => array(
						'title'   => array( 'type' => 'plain_text', 'text' => 'Apply this preview?' ),
						'text'    => array( 'type' => 'mrkdwn', 'text' => 'This promotes the private preview to the active source page and resolves the Markup thread.' ),
						'confirm' => array( 'type' => 'plain_text', 'text' => 'Approve' ),
						'deny'    => array( 'type' => 'plain_text', 'text' => 'Cancel' ),
					),
				),
				array(
					'type'      => 'button',
					'text'      => array( 'type' => 'plain_text', 'text' => 'Reject' ),
					'style'     => 'danger',
					'action_id' => 'lc_feedback_reject',
					'value'     => $decision_value,
				),
			),
		);

		return $blocks;
	}

	/**
	 * Make a fixed-origin Slack Web API request.
	 *
	 * @param array<string, mixed> $payload JSON payload.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function request( string $method, array $payload ): array|\WP_Error {
		$response = wp_remote_post(
			self::API_BASE . $method,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . Credentials::slack_bot_token(),
					'Content-Type'  => 'application/json; charset=utf-8',
				),
				'body'        => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'slack_request_failed', __( 'Slack notification failed.', 'lc-bricks-mcp' ) );
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $decoded ) || empty( $decoded['ok'] ) ) {
			return new \WP_Error( 'slack_api_error', __( 'Slack rejected the notification. Check the bot scopes and channel ID.', 'lc-bricks-mcp' ) );
		}
		return $decoded;
	}

	/**
	 * Remove Slack control characters and enforce a hard length.
	 */
	private function slack_text( string $text, int $length ): string {
		$text = wp_strip_all_tags( $text );
		$text = str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
	}
}
