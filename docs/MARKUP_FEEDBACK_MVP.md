# Markup Feedback MVP

This module turns unresolved MarkUp.io threads into a private WordPress queue,
lets an authenticated MCP agent triage and build changes on private page copies,
and reserves promotion/rejection/rollback for an allowlisted Slack user or a
nonce-protected WordPress administrator.

## Security boundary

- Revoke any API key that has appeared in chat, source control, screenshots, or
  logs. Do not reuse it.
- Secrets are read only from PHP constants or process environment variables.
  They are never stored in WordPress options and are never rendered in wp-admin.
- The Markup API key should be scoped to the relevant workspace with only
  `threads:read` and `threads:write`.
- Markup ingestion uses authenticated polling. Markup publishes a webhook
  signing key but does not currently document the signature header or algorithm,
  so this MVP does not accept an unverifiable public Markup webhook.
- MCP can create and edit a private preview and submit it for review. MCP cannot
  approve, reject, retry resolution, or roll back.
- Slack decisions require a current HMAC signature, the configured Slack team,
  and an explicit Slack user-ID allowlist.
- Approval is blocked if the active source page changed after preview creation.
  A rollback snapshot is written before promotion.

## 1. Install and configure the rotated Markup key

Create a replacement key in Markup Developer Settings with `threads:read` and
`threads:write`. On each staging site, make it available as `MARKUP_API_KEY`, or
define `LC_MARKUP_API_KEY` in the host-managed `wp-config.php` outside the Git
deployment:

```php
define( 'LC_MARKUP_API_KEY', getenv( 'MARKUP_API_KEY' ) ?: '' );
```

Do not paste the replacement into WordPress settings. If the host cannot inject
process environment variables, define the rotated value directly in the
server-only `wp-config.php` and restrict file access; do not commit that file.

In **Bricks → Feedback Queue**, add the Markup UUID(s), one per line, and enable
background polling. Non-secret IDs may instead be supplied through
`LC_MARKUP_IDS` / `MARKUP_IDS` as comma- or newline-separated values.

## 2. Optional Slack approvals

Create a single-workspace Slack app with a bot token and `chat:write`. Enable
Interactivity and set its Request URL to the URL shown in **Bricks → Feedback
Queue**. Configure all five values on the server:

```php
define( 'LC_SLACK_BOT_TOKEN', getenv( 'SLACK_BOT_TOKEN' ) ?: '' );
define( 'LC_SLACK_SIGNING_SECRET', getenv( 'SLACK_SIGNING_SECRET' ) ?: '' );
define( 'LC_SLACK_TEAM_ID', getenv( 'SLACK_TEAM_ID' ) ?: '' );
define( 'LC_SLACK_CHANNEL_ID', getenv( 'SLACK_CHANNEL_ID' ) ?: '' );
define( 'LC_SLACK_APPROVER_IDS', getenv( 'SLACK_APPROVER_IDS' ) ?: '' );
```

`SLACK_APPROVER_IDS` is a comma-separated list of Slack member IDs. Slack is
disabled unless the bot token, signing secret, team, channel, and at least one
approver are all present. The WordPress dashboard remains the fallback.

## 3. Agent workflow

1. `feedback_workflow(action: "sync")` or wait for the five-minute poll.
2. `feedback_workflow(action: "list")`, then `get` the selected task.
3. Confirm `page_url` belongs to this exact site. Refine the automatic category
   with `set_triage` when needed.
4. `prepare_preview` creates a private page and returns its `preview_post_id`.
5. Use existing `page` / `element` tools only against that preview ID.
6. Verify the private preview in a logged-in browser at desktop and relevant
   responsive sizes. The active source must remain unchanged.
7. Call `mark_ready` with a concise diff summary and browser verification note.
8. A human approves or rejects in Slack or **Bricks → Feedback Queue**.

Approval copies the verified preview tree to the source page, then resolves the
Markup thread through `POST /api/v2/threads/{id}/resolve`. If resolution fails,
the applied change remains in `applied_unresolved` and the dashboard exposes a
manual retry. It never silently rolls back an explicitly approved site change.

## 4. Rollback

The dashboard exposes rollback for applied tasks. Rollback succeeds only while
the active source tree still matches the exact tree applied by this task. If
later work changed the source, rollback stops with a conflict instead of
overwriting that later work. A successful rollback restores the pre-approval
snapshot and reopens the Markup thread.

## 5. Scope of this MVP

- One WordPress installation per feedback queue. Install/configure independently
  on each staging site.
- Published WordPress pages only; templates, posts, options, forms, and code are
  intentionally outside automatic preview preparation.
- Promotion means private preview → active source page on the same staging site.
  Deployment from staging to a separate production site is intentionally not
  implemented until the production targets and credentials are explicitly
  supplied and a second approval boundary is designed.
- WP-Cron is traffic-driven. For reliable background work, configure the host's
  real cron to call WordPress cron on a five-minute cadence.

## API references

- Markup authentication: https://developer.markup.io/api-specs/authentication/
- Markup thread endpoints: https://developer.markup.io/api-specs/resources/feedback/threads/
- Slack interactivity: https://docs.slack.dev/interactivity/handling-user-interaction/
- Slack request signing: https://docs.slack.dev/authentication/verifying-requests-from-slack/
