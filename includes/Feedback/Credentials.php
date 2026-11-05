<?php
/**
 * Environment-backed credentials and feedback configuration.
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
 * Resolves secrets without ever persisting them in WordPress options.
 */
final class Credentials {

	/**
	 * MarkUp.io server-to-server API key.
	 */
	public static function markup_api_key(): string {
		return self::secret( 'LC_MARKUP_API_KEY', 'MARKUP_API_KEY' );
	}

	/**
	 * Slack bot token used only for approval notifications and message updates.
	 */
	public static function slack_bot_token(): string {
		return self::secret( 'LC_SLACK_BOT_TOKEN', 'SLACK_BOT_TOKEN' );
	}

	/**
	 * Slack signing secret used to authenticate interactive button requests.
	 */
	public static function slack_signing_secret(): string {
		return self::secret( 'LC_SLACK_SIGNING_SECRET', 'SLACK_SIGNING_SECRET' );
	}

	/**
	 * Expected Slack workspace/team ID.
	 */
	public static function slack_team_id(): string {
		return self::value( 'LC_SLACK_TEAM_ID', 'SLACK_TEAM_ID' );
	}

	/**
	 * Slack channel ID that receives approval requests.
	 */
	public static function slack_channel_id(): string {
		return self::value( 'LC_SLACK_CHANNEL_ID', 'SLACK_CHANNEL_ID' );
	}

	/**
	 * Slack user IDs allowed to approve or reject changes.
	 *
	 * @return array<int, string>
	 */
	public static function slack_approver_ids(): array {
		$raw = self::value( 'LC_SLACK_APPROVER_IDS', 'SLACK_APPROVER_IDS' );
		return self::csv( $raw );
	}

	/**
	 * Markup IDs to poll. These identifiers are non-secret and may be stored in
	 * the plugin's feedback settings when an environment value is not supplied.
	 *
	 * @return array<int, string>
	 */
	public static function markup_ids(): array {
		$raw = self::constant_or_environment( 'LC_MARKUP_IDS', 'MARKUP_IDS' );
		if ( is_array( $raw ) ) {
			$values = $raw;
		} elseif ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$values = self::csv( $raw );
		} else {
			$settings = get_option( 'lc_feedback_settings', array() );
			$stored   = is_array( $settings ) ? (string) ( $settings['markup_ids'] ?? '' ) : '';
			$values   = self::csv( $stored );
		}

		return array_values(
			array_filter(
				array_unique( array_map( 'strval', $values ) ),
				static fn ( string $id ): bool => 1 === preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $id )
			)
		);
	}

	/**
	 * Whether Slack approval delivery has every required least-privilege value.
	 */
	public static function slack_is_configured(): bool {
		return '' !== self::slack_bot_token()
			&& '' !== self::slack_signing_secret()
			&& '' !== self::slack_team_id()
			&& '' !== self::slack_channel_id()
			&& array() !== self::slack_approver_ids();
	}

	/**
	 * Resolve a secret constant/environment value.
	 */
	private static function secret( string $constant, string $environment ): string {
		$value = self::constant_or_environment( $constant, $environment );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Resolve a non-secret scalar constant/environment value.
	 */
	private static function value( string $constant, string $environment ): string {
		$value = self::constant_or_environment( $constant, $environment );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Read a PHP constant first, then a process environment variable.
	 */
	private static function constant_or_environment( string $constant, string $environment ): mixed {
		if ( defined( $constant ) ) {
			return constant( $constant );
		}

		$value = getenv( $environment );
		return false === $value ? '' : $value;
	}

	/**
	 * Split comma-, whitespace-, or newline-delimited values.
	 *
	 * @return array<int, string>
	 */
	private static function csv( string $value ): array {
		$parts = preg_split( '/[\s,]+/', trim( $value ) ) ?: array();
		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static fn ( string $item ): bool => '' !== $item
			)
		);
	}
}
