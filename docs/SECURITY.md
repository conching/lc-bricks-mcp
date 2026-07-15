# Security

This document describes the security model of the Bricks MCP plugin — how it authenticates requests, how it limits traffic, what operations require explicit opt-in, and what the plugin explicitly does not do.

## Security Model

Bricks MCP exposes WordPress data through MCP (Model Context Protocol) endpoints. All communication uses HTTP/REST only — there is no stdio transport, no WebSocket transport, and no persistent background process. Every request goes through WordPress's built-in REST API infrastructure, which means WordPress authentication, nonces, and capability checks apply normally.

## Authentication

WordPress Application Passwords are the authentication mechanism. The `require_auth` setting is enabled by default and gates all MCP endpoints.

When `require_auth` is enabled:

- The user must be authenticated (logged in via Application Password over HTTP Basic Auth)
- The user must have the `manage_options` capability (WordPress Administrator role)
- Authentication is checked in `Server::check_permissions()` before any tool is executed

Application Passwords are the standard WordPress mechanism for REST API authentication from external tools such as Claude Code and Gemini CLI. They can be generated from any user's profile page under **Users > Profile > Application Passwords**.

Unauthenticated access is possible only if the site administrator explicitly disables the `require_auth` setting in **Bricks > MCP > Require Authentication**. This is not recommended for production sites.

## Rate Limiting

The plugin enforces a per-user request rate limit to prevent runaway AI agent loops from overloading the server.

- Default: 120 requests per minute per authenticated user
- Configurable from **Bricks > MCP > Rate Limit** (range: 10–1000 RPM)
- Per-user tracking uses WordPress transients keyed by user ID (`bricks_mcp_rl_{user_id}`)
- When the limit is exceeded, the server returns HTTP `429` Too Many Requests with a `Retry-After` header indicating when the window resets
- Applies to both REST API routes and the Streamable HTTP (SSE) endpoint
- Rate limiting is only active when authentication is required — without a user identity, there is no user to track
- Window: 60-second sliding window, auto-resets via transient TTL

For intensive AI building sessions (for example, "build me a landing page" workflows that fire 30–50 tool calls), consider increasing the limit to 300 RPM in the settings.

## Dangerous Actions Toggle

Some operations — specifically writing JavaScript to page scripts — are gated behind a separate **Dangerous Actions** toggle in addition to normal authentication.

- Off by default, with a prominent red warning in the admin settings
- When enabled, AI tools can write JavaScript to page header and body script fields, and modify code execution settings
- When disabled (default), these operations return an error regardless of user capability
- CSS writes are not dangerous-actions-gated — CSS cannot execute code
- API keys and secrets stored in Bricks settings are always masked as `****configured****` regardless of this setting

Recommendation: only enable on development sites or when working with a trusted AI agent team.

## What This Plugin Does NOT Do

- No `eval()`, `assert()`, or dynamic PHP code execution
- No raw SQL queries — all database access goes through WordPress APIs (`get_post_meta`, `update_post_meta`, `get_option`, `update_option`, `WP_Query`)
- No file system writes outside the WordPress media library (`download_url` + `media_handle_sideload`)
- No shell commands (`exec`, `shell_exec`, `system`, `passthru`)
- No `unserialize()` on user-controlled data
- No direct file includes with user-supplied paths
- No unauthenticated write operations — all mutations require the `manage_options` capability
- No stdio or WebSocket transport — HTTP REST only, through WordPress's built-in REST infrastructure
- No cross-site data access — the plugin operates within the single WordPress installation it is installed on
- No browser-facing CORS headers — CLI tools connect server-side; no `Access-Control-Allow-Origin` headers are emitted

## Reporting Vulnerabilities

To report a security vulnerability, contact: **cristi@buffup.media**

Please use responsible disclosure — share details privately before publishing. The maintainer will acknowledge the report within 48 hours and provide a timeline for a fix.
