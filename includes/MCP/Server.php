<?php
/**
 * MCP Server implementation.
 *
 * @package BricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

namespace BricksMCP\MCP;

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server class.
 *
 * Main MCP (Model Context Protocol) server implementation.
 * Registers the single /mcp endpoint using the Streamable HTTP transport.
 */
final class Server {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	public const API_NAMESPACE = 'bricks-mcp/v1';

	/**
	 * Router instance.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Streamable HTTP handler instance.
	 *
	 * @var StreamableHttpHandler
	 */
	private StreamableHttpHandler $handler;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->router  = new Router();
		$this->handler = new StreamableHttpHandler( $this->router );
	}

	/**
	 * Initialize the MCP server.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'rest_request_before_callbacks', [ $this, 'intercept_json_parse_error' ], 10, 3 );
		add_filter( 'rest_post_dispatch', [ $this, 'add_www_authenticate_header' ], 10, 3 );
		add_action( 'parse_request', [ $this, 'handle_well_known_request' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * Registers the single /mcp endpoint supporting POST, GET, and DELETE
	 * per the MCP Streamable HTTP transport specification.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			self::API_NAMESPACE,
			'/mcp',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this->handler, 'handle_post' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => 'GET',
					'callback'            => [ $this->handler, 'handle_get' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this->handler, 'handle_delete' ],
					'permission_callback' => [ $this, 'check_permissions' ],
				],
			]
		);

	}

	/**
	 * Handle /.well-known/oauth-protected-resource requests at the site root.
	 *
	 * MCP clients that receive a 401 follow the MCP 2025 auth spec (RFC 9728)
	 * and look for this endpoint at the site root — NOT under /wp-json/.
	 * Without this handler, WordPress returns a 404 HTML page.
	 *
	 * We do not implement OAuth. This endpoint tells MCP clients that this
	 * server uses WordPress Application Passwords and how to set them up.
	 *
	 * @param \WP $wp The WordPress environment instance.
	 * @return void
	 */
	public function handle_well_known_request( \WP $wp ): void {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		$well_known_paths = [
			'/.well-known/oauth-protected-resource',
			'/.well-known/oauth-authorization-server',
		];

		if ( ! in_array( $path, $well_known_paths, true ) ) {
			return;
		}

		$resource_url = rest_url( self::API_NAMESPACE . '/mcp' );
		$settings_url = admin_url( 'options-general.php?page=bricks-mcp' );

		$auth_hint = sprintf(
			/* translators: 1: settings URL */
			__( 'This server uses WordPress Application Passwords, not OAuth. Generate one at Users > Profile > Application Passwords, then configure your MCP client with Basic auth (base64 of "username:app-password"). Settings: %1$s', 'bricks-mcp' ),
			$settings_url
		);

		// oauth-authorization-server: Return 404 JSON — we don't have an OAuth server.
		// This prevents MCP clients from seeing a WordPress HTML 404 page.
		if ( '/.well-known/oauth-authorization-server' === $path ) {
			status_header( 404 );
			header( 'Content-Type: application/json' );
			header( 'Access-Control-Allow-Origin: *' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_json_encode( [
				'error'                   => 'oauth_not_supported',
				'error_description'       => $auth_hint,
				'bricks_mcp_auth_method'  => 'application_password',
				'bricks_mcp_settings_url' => $settings_url,
			] );
			exit;
		}

		// oauth-protected-resource: RFC 9728 metadata.
		header( 'Content-Type: application/json' );
		header( 'Access-Control-Allow-Origin: *' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( [
			'resource'                 => $resource_url,
			'authorization_servers'    => [],
			'bearer_methods_supported' => [ 'header' ],
			'resource_documentation'   => 'https://aiforbricks.com/docs/authentication',
			'bricks_mcp_auth_method'   => 'application_password',
			'bricks_mcp_auth_hint'     => $auth_hint,
			'bricks_mcp_settings_url'  => $settings_url,
		] );
		exit;
	}

	/**
	 * Intercept WordPress JSON parse errors for the /mcp route.
	 *
	 * WordPress validates the JSON body via has_valid_params() before calling our callback.
	 * When the body is not valid JSON, it returns rest_invalid_json WP_Error.
	 * We intercept this for our /mcp POST route and emit a proper JSON-RPC parse error SSE event.
	 *
	 * @param mixed            $response Current response (WP_Error or null).
	 * @param array            $handler  The matched route handler.
	 * @param \WP_REST_Request $request  The REST request.
	 * @return mixed The response (unchanged), or WP_REST_Response if we handle it.
	 */
	public function intercept_json_parse_error( mixed $response, array $handler, \WP_REST_Request $request ): mixed {
		// Only intercept JSON parse errors on our /mcp POST route.
		if ( ! is_wp_error( $response ) ) {
			return $response;
		}

		if ( 'rest_invalid_json' !== $response->get_error_code() ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! str_starts_with( $route, '/' . self::API_NAMESPACE . '/mcp' ) ) {
			return $response;
		}

		if ( 'POST' !== $request->get_method() ) {
			return $response;
		}

		// Emit SSE parse error and exit — we handle it directly.
		$this->handler->emit_parse_error_and_exit();

		// Unreachable, but satisfies return type.
		return $response;
	}

	/**
	 * Add WWW-Authenticate header to 401 responses from the /mcp route.
	 *
	 * When a MCP client receives a 401, the MCP 2025 auth spec requires the
	 * response to include a WWW-Authenticate header with a resource_metadata
	 * parameter pointing to the OAuth Protected Resource Metadata endpoint
	 * (RFC 9728). Without this header, clients fall back to guessing the
	 * well-known URL — and may still get a WordPress 404 HTML page.
	 *
	 * By returning this header we comply with RFC 9728 §5.1 and give clients
	 * a machine-readable pointer to our JSON metadata document.
	 *
	 * @param \WP_REST_Response $response The REST response.
	 * @param \WP_REST_Server   $server   The REST server.
	 * @param \WP_REST_Request  $request  The REST request.
	 * @return \WP_REST_Response The (potentially modified) response.
	 */
	public function add_www_authenticate_header(
		\WP_REST_Response $response,
		\WP_REST_Server $server, // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		\WP_REST_Request $request
	): \WP_REST_Response {
		// Only act on 401 responses from our /mcp route.
		if ( 401 !== $response->get_status() ) {
			return $response;
		}

		$route = $request->get_route();
		if ( ! str_starts_with( $route, '/' . self::API_NAMESPACE . '/mcp' ) ) {
			return $response;
		}

		$metadata_url = site_url( '/.well-known/oauth-protected-resource' );
		$response->header(
			'WWW-Authenticate',
			'Bearer resource_metadata="' . esc_url_raw( $metadata_url ) . '"'
		);

		return $response;
	}

	/**
	 * Check request permissions.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function check_permissions( \WP_REST_Request $request ): bool|\WP_Error { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$settings = get_option( 'bricks_mcp_settings', [] );

		// Check if plugin is enabled.
		if ( empty( $settings['enabled'] ) ) {
			return new \WP_Error(
				'bricks_mcp_disabled',
				__( 'The Bricks MCP server is currently disabled.', 'bricks-mcp' ),
				[ 'status' => 503 ]
			);
		}

		// Check if authentication is required.
		if ( ! empty( $settings['require_auth'] ) ) {
			if ( ! is_user_logged_in() ) {
				return new \WP_Error(
					'bricks_mcp_unauthorized',
					__( 'Authentication is required to access the MCP server.', 'bricks-mcp' ),
					[ 'status' => 401 ]
				);
			}

			// Check user capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				return new \WP_Error(
					'bricks_mcp_forbidden',
					__( 'You do not have permission to access the MCP server.', 'bricks-mcp' ),
					[ 'status' => 403 ]
				);
			}
		}

		// Rate limit all requests (authenticated by user ID, anonymous by IP).
		$remote_addr = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$identifier  = is_user_logged_in()
			? 'user_' . get_current_user_id()
			: 'ip_' . $remote_addr;
		$rate_check  = RateLimiter::check( $identifier );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		return true;
	}

	/**
	 * Get the router instance.
	 *
	 * @return Router The router instance.
	 */
	public function get_router(): Router {
		return $this->router;
	}

	/**
	 * Get the API namespace.
	 *
	 * @return string The API namespace.
	 */
	public function get_namespace(): string {
		return self::API_NAMESPACE;
	}
}
