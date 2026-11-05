<?php
/**
 * Minimal WordPress feedback approval dashboard.
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
 * Provides the fallback approval UI when Slack is unavailable or undesirable.
 */
final class AdminPage {

	private const PAGE_SLUG = 'lc-feedback-queue';
	private const ACTION    = 'lc_feedback_admin_action';
	private const PAGE_SIZE = 50;

	public function __construct( private Workflow $workflow ) {}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 100 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_action' ) );
	}

	public function add_menu(): void {
		add_submenu_page(
			'bricks',
			__( 'Feedback Queue', 'lc-bricks-mcp' ),
			__( 'Feedback Queue', 'lc-bricks-mcp' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render settings, credential readiness, and recent tasks.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view the feedback queue.', 'lc-bricks-mcp' ) );
		}

		$settings     = get_option( 'lc_feedback_settings', array() );
		$settings     = is_array( $settings ) ? $settings : array();
		$current_page = isset( $_GET['feedback_page'] ) ? max( 1, absint( wp_unslash( $_GET['feedback_page'] ) ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$pagination   = $this->workflow->paginate( '', $current_page, self::PAGE_SIZE );
		if ( 0 < $pagination['total_pages'] && $current_page > $pagination['total_pages'] ) {
			$current_page = $pagination['total_pages'];
			$pagination   = $this->workflow->paginate( '', $current_page, self::PAGE_SIZE );
		}
		$tasks  = $pagination['items'];
		$notice = isset( $_GET['lc_feedback_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['lc_feedback_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type   = isset( $_GET['lc_feedback_type'] ) && 'error' === $_GET['lc_feedback_type'] ? 'error' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Markup Feedback Queue', 'lc-bricks-mcp' ); ?></h1>
			<p><?php esc_html_e( 'Feedback is polled into this site, triaged locally, and changed only on a private preview until an explicit approval promotes it.', 'lc-bricks-mcp' ); ?></p>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connection readiness', 'lc-bricks-mcp' ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th><?php esc_html_e( 'Rotated Markup API key', 'lc-bricks-mcp' ); ?></th><td><?php echo '' !== Credentials::markup_api_key() ? esc_html__( 'Configured in the environment', 'lc-bricks-mcp' ) : esc_html__( 'Missing', 'lc-bricks-mcp' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Configured Markups', 'lc-bricks-mcp' ); ?></th><td><?php echo esc_html( (string) count( Credentials::markup_ids() ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Slack approvals', 'lc-bricks-mcp' ); ?></th><td><?php echo Credentials::slack_is_configured() ? esc_html__( 'Configured with workspace and user allowlists', 'lc-bricks-mcp' ) : esc_html__( 'Optional; dashboard approvals remain available', 'lc-bricks-mcp' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Slack Request URL', 'lc-bricks-mcp' ); ?></th><td><code><?php echo esc_html( rest_url( Server::API_NAMESPACE . '/feedback/slack/actions' ) ); ?></code></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Polling settings', 'lc-bricks-mcp' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="workflow_action" value="save_settings">
				<?php wp_nonce_field( self::ACTION, '_lc_feedback_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lc-feedback-enabled"><?php esc_html_e( 'Background polling', 'lc-bricks-mcp' ); ?></label></th>
						<td><label><input id="lc-feedback-enabled" type="checkbox" name="enabled" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?>> <?php esc_html_e( 'Poll every five minutes when credentials and Markup IDs are configured', 'lc-bricks-mcp' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="lc-feedback-markup-ids"><?php esc_html_e( 'Markup IDs', 'lc-bricks-mcp' ); ?></label></th>
						<td>
							<textarea id="lc-feedback-markup-ids" name="markup_ids" rows="4" cols="60" class="large-text code"><?php echo esc_textarea( (string) ( $settings['markup_ids'] ?? '' ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One UUID per line. LC_MARKUP_IDS or MARKUP_IDS overrides this non-secret setting.', 'lc-bricks-mcp' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Feedback Settings', 'lc-bricks-mcp' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<input type="hidden" name="workflow_action" value="sync">
				<?php wp_nonce_field( self::ACTION, '_lc_feedback_nonce' ); ?>
				<?php submit_button( __( 'Sync Now', 'lc-bricks-mcp' ), 'secondary', 'submit', false ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent feedback', 'lc-bricks-mcp' ); ?></h2>
			<?php $this->render_queue_summary( $pagination ); ?>
			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Task', 'lc-bricks-mcp' ); ?></th><th><?php esc_html_e( 'Feedback', 'lc-bricks-mcp' ); ?></th><th><?php esc_html_e( 'Triage', 'lc-bricks-mcp' ); ?></th><th><?php esc_html_e( 'Preview', 'lc-bricks-mcp' ); ?></th><th><?php esc_html_e( 'Actions', 'lc-bricks-mcp' ); ?></th></tr></thead>
				<tbody>
				<?php if ( array() === $tasks ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No feedback has been ingested yet.', 'lc-bricks-mcp' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $tasks as $task ) : ?>
						<?php $this->render_task_row( $task ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
			<?php $this->render_pagination( $pagination ); ?>
		</div>
		<?php
	}

	/**
	 * Show the visible range and authoritative total.
	 *
	 * @param array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} $pagination Page data.
	 */
	private function render_queue_summary( array $pagination ): void {
		$start = 0 === $pagination['total'] ? 0 : ( ( $pagination['page'] - 1 ) * $pagination['per_page'] ) + 1;
		$end   = min( $pagination['total'], $pagination['page'] * $pagination['per_page'] );
		?>
		<p class="description">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: first visible item, 2: last visible item, 3: total feedback items */
					__( 'Showing %1$d–%2$d of %3$d feedback items.', 'lc-bricks-mcp' ),
					$start,
					$end,
					$pagination['total']
				)
			);
			?>
		</p>
		<?php
	}

	/**
	 * Render numbered queue navigation while preserving the admin page slug.
	 *
	 * @param array{items: array<int, array<string, mixed>>, total: int, page: int, per_page: int, total_pages: int} $pagination Page data.
	 */
	private function render_pagination( array $pagination ): void {
		if ( $pagination['total_pages'] <= 1 ) {
			return;
		}

		$base_url = add_query_arg(
			array(
				'page'          => self::PAGE_SLUG,
				'feedback_page' => 999999999,
			),
			admin_url( 'admin.php' )
		);
		$links    = paginate_links(
			array(
				'base'      => str_replace( '999999999', '%#%', $base_url ),
				'format'    => '',
				'current'   => $pagination['page'],
				'total'     => $pagination['total_pages'],
				'type'      => 'array',
				'prev_text' => __( '‹ Previous', 'lc-bricks-mcp' ),
				'next_text' => __( 'Next ›', 'lc-bricks-mcp' ),
			)
		);
		if ( ! is_array( $links ) || array() === $links ) {
			return;
		}
		?>
		<nav class="tablenav" aria-label="<?php esc_attr_e( 'Feedback pagination', 'lc-bricks-mcp' ); ?>">
			<div class="tablenav-pages"><?php echo wp_kses_post( implode( ' ', $links ) ); ?></div>
		</nav>
		<?php
	}

	/**
	 * Process nonce-protected dashboard decisions.
	 */
	public function handle_action(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage feedback.', 'lc-bricks-mcp' ) );
		}
		check_admin_referer( self::ACTION, '_lc_feedback_nonce' );

		$action  = isset( $_POST['workflow_action'] ) ? sanitize_key( wp_unslash( $_POST['workflow_action'] ) ) : '';
		$task_id = isset( $_POST['task_id'] ) ? absint( $_POST['task_id'] ) : 0;
		$actor   = 'wp:' . get_current_user_id();

		if ( 'save_settings' === $action ) {
			$ids = isset( $_POST['markup_ids'] ) ? sanitize_textarea_field( wp_unslash( $_POST['markup_ids'] ) ) : '';
			update_option(
				'lc_feedback_settings',
				array(
					'enabled'    => ! empty( $_POST['enabled'] ),
					'markup_ids' => $this->sanitize_markup_ids( $ids ),
				),
				false
			);
			$this->redirect( __( 'Feedback settings saved.', 'lc-bricks-mcp' ) );
		}

		$result = match ( $action ) {
			'sync'          => $this->workflow->sync( $actor ),
			'approve'       => $this->workflow->approve(
				$task_id,
				$actor,
				isset( $_POST['approval_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['approval_hash'] ) ) : ''
			),
			'reject'        => $this->workflow->reject(
				$task_id,
				'Rejected from the WordPress dashboard.',
				$actor,
				isset( $_POST['approval_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['approval_hash'] ) ) : ''
			),
			'retry_resolve' => $this->workflow->retry_resolve( $task_id, $actor ),
			'rollback'      => $this->workflow->rollback( $task_id, $actor ),
			default         => new \WP_Error( 'invalid_feedback_action', __( 'Unknown feedback action.', 'lc-bricks-mcp' ) ),
		};

		if ( is_wp_error( $result ) ) {
			$this->redirect( $result->get_error_message(), 'error' );
		}
		$this->redirect( __( 'Feedback action completed.', 'lc-bricks-mcp' ) );
	}

	/**
	 * Render one queue row.
	 *
	 * @param array<string, mixed> $task Task data.
	 */
	private function render_task_row( array $task ): void {
		$status      = (string) ( $task['status'] ?? '' );
		$task_id     = (int) ( $task['id'] ?? 0 );
		$preview_url = esc_url( (string) ( $task['preview_url'] ?? '' ) );
		$markup_url  = esc_url( (string) ( $task['markup_url'] ?? '' ) );
		?>
		<tr>
			<td><strong>#<?php echo esc_html( (string) $task_id ); ?></strong><br><code><?php echo esc_html( $status ); ?></code><?php if ( ! empty( $task['last_error'] ) ) : ?><p style="color:#b32d2e"><?php echo esc_html( (string) $task['last_error'] ); ?></p><?php endif; ?></td>
			<td><p><?php echo esc_html( wp_trim_words( (string) ( $task['comment'] ?? '' ), 35, '…' ) ); ?></p><?php if ( '' !== $markup_url ) : ?><a href="<?php echo esc_url( $markup_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in Markup', 'lc-bricks-mcp' ); ?></a><?php endif; ?></td>
			<td><?php echo esc_html( (string) ( $task['category'] ?? 'unclear' ) ); ?> / <?php echo esc_html( (string) ( $task['risk'] ?? 'unknown' ) ); ?></td>
			<td><?php if ( '' !== $preview_url ) : ?><a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open private preview', 'lc-bricks-mcp' ); ?></a><?php else : ?>—<?php endif; ?><?php if ( ! empty( $task['change_summary'] ) ) : ?><p><?php echo esc_html( (string) $task['change_summary'] ); ?></p><?php endif; ?></td>
			<td>
				<?php if ( 'awaiting_approval' === $status ) : ?>
					<?php $this->action_button( $task_id, 'approve', __( 'Approve', 'lc-bricks-mcp' ), 'primary', (string) ( $task['ready_hash'] ?? '' ) ); ?>
					<?php $this->action_button( $task_id, 'reject', __( 'Reject', 'lc-bricks-mcp' ), 'secondary', (string) ( $task['ready_hash'] ?? '' ) ); ?>
				<?php elseif ( 'applied_unresolved' === $status ) : ?>
					<?php $this->action_button( $task_id, 'retry_resolve', __( 'Retry Markup Resolution', 'lc-bricks-mcp' ), 'secondary' ); ?>
					<?php $this->action_button( $task_id, 'rollback', __( 'Rollback', 'lc-bricks-mcp' ), 'secondary' ); ?>
				<?php elseif ( 'resolved' === $status ) : ?>
					<?php $this->action_button( $task_id, 'rollback', __( 'Rollback', 'lc-bricks-mcp' ), 'secondary' ); ?>
				<?php else : ?>
					<span class="description"><?php esc_html_e( 'Use the feedback_workflow MCP tool to triage and prepare a preview.', 'lc-bricks-mcp' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function action_button( int $task_id, string $workflow_action, string $label, string $class, string $approval_hash = '' ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin:0 4px 4px 0">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="workflow_action" value="<?php echo esc_attr( $workflow_action ); ?>">
			<input type="hidden" name="task_id" value="<?php echo esc_attr( (string) $task_id ); ?>">
			<?php if ( '' !== $approval_hash ) : ?><input type="hidden" name="approval_hash" value="<?php echo esc_attr( $approval_hash ); ?>"><?php endif; ?>
			<?php wp_nonce_field( self::ACTION, '_lc_feedback_nonce' ); ?>
			<button type="submit" class="button button-<?php echo esc_attr( $class ); ?>"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}

	private function sanitize_markup_ids( string $input ): string {
		$parts = preg_split( '/[\s,]+/', trim( $input ) ) ?: array();
		$ids   = array_filter(
			array_unique( array_map( 'trim', $parts ) ),
			static fn ( string $id ): bool => 1 === preg_match( '/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i', $id )
		);
		return implode( "\n", $ids );
	}

	private function redirect( string $message, string $type = 'success' ): never {
		$url = add_query_arg(
			array(
				'page'               => self::PAGE_SLUG,
				'lc_feedback_notice' => $message,
				'lc_feedback_type'   => $type,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
