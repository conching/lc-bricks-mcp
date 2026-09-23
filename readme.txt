=== LC Bricks MCP ===
Contributors: conching, cristianuibar, optiwebopz
Tags: ai, bricks builder, mcp, artificial intelligence, page builder
Requires at least: 6.4
Tested up to: 6.8
Stable tag: 2.1.3
Requires PHP: 8.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect AI assistants like Claude to your Bricks Builder site. Build and edit pages using natural language — no clicking required.

== Description ==

LC Bricks MCP turns your WordPress site into an AI-controlled page builder. It implements the Model Context Protocol (MCP) — an open standard for connecting AI assistants to external tools — so that any MCP-compatible client (Claude Desktop, Claude Code, and others) can read and modify your Bricks Builder pages through plain conversation.

Tell your AI assistant "create a hero section with a headline and a call-to-action button" and it happens. No template hunting. No clicking through panels.

= Provenance =

LC Bricks MCP is a Library Creative fork of **Bricks MCP** by Uibar Ion-Cristian / BUFF UP MEDIA S.R.L. (cristianuibar/bricks-mcp v1.5.1). It carries three previously-local private patches as native code and adds correctness fixes for template creation, header/footer element conditions, root-level element placement, and post-save read-back verification. Distributed under GPL-2.0-or-later, retaining the original copyright and license. Upstream: https://github.com/cristianuibar/bricks-mcp

= How It Works =

The plugin registers a REST API endpoint on your WordPress site that speaks the MCP protocol. You add the endpoint URL to your AI client's MCP configuration, authenticate with a WordPress Application Password, and your AI can start working with your site immediately.

= Available Tools =

The tool surface is consolidated into 21 action-based tools. Each tool takes an `action` parameter that selects the operation.

* **get_site_info** — Site info and connection diagnostics (info, diagnose)
* **wordpress** — Query WordPress data (get_posts, get_post, get_users, get_plugins)
* **get_builder_guide** — Fetch the built-in builder reference guide for AI context
* **bricks** — Bricks settings, schemas, and global queries (enable, get_element_schemas, get_form_schema, set_global_query, and more)
* **page** — Manage pages and Bricks content (list, search, get, create, update_content, update_meta, delete, duplicate, get/update_settings, get/update_seo)
* **element** — Manage individual elements (add, update, remove, get/set_conditions, move, bulk_update)
* **template** — Manage templates (list, get, create, create_from_elements, insert_reference, update, delete, duplicate, popup settings, export, import, import_url)
* **template_condition** — Manage template display conditions (get_types, set, resolve)
* **template_taxonomy** — Manage template tags and bundles (list/create/delete tag/bundle)
* **global_class** — Manage global CSS classes (list, create, update, delete, apply, remove, batch, import_css, categories, export, import_json)
* **theme_style** — Manage theme styles (list, get, create, update, delete)
* **typography_scale** — Manage typography scale variables (list, create, update, delete)
* **color_palette** — Manage color palettes (list, create, update, delete, add/update/delete_color)
* **global_variable** — Manage global variables and categories (list, create, update, delete, batch, search)
* **media** — Media library and Unsplash (search_unsplash, sideload, list, set/remove_featured, get_image_settings)
* **menu** — Manage WordPress nav menus (list, get, create, update, delete, set_items, assign, unassign, list_locations)
* **component** — Manage Bricks components (list, get, create, update, delete, instantiate, update_properties, fill_slot)
* **woocommerce** — WooCommerce elements and scaffolds (status, get_elements, get_dynamic_tags, scaffold_template, scaffold_store)
* **font** — Font settings (get_status, get_adobe_fonts, update_settings)
* **code** — Page CSS/scripts (get/set_page_css, get/set_page_scripts)
* **verify** — Read-only page verification (page: ordered root sections + parent chains, stored or rendered; orphaned_css: find `#brxe-<id>` rules with no matching or id-overridden element)

All tools are free to use. The plugin is open source and hosted on [GitHub](https://github.com/conching/lc-bricks-mcp).

= Authentication =

All requests are authenticated using WordPress Application Passwords, the built-in authentication system available since WordPress 5.6. No third-party authentication service is involved.

= Requirements =

* WordPress 6.4 or later
* PHP 8.2 or later
* Bricks Builder theme 1.6 or later (required for Bricks-specific tools)

= Getting Started =

1. Install and activate the plugin.
2. Go to **Settings > LC Bricks MCP** and enable the plugin.
3. Create a WordPress Application Password under **Users > Profile**.
4. Add the MCP server URL to your AI client configuration.
5. Start building pages with natural language.

Full setup documentation is available in the [GitHub repository](https://github.com/conching/lc-bricks-mcp).

== External Services ==

This plugin optionally connects to the Unsplash API to search for images.

**Service:** Unsplash (api.unsplash.com)
**When used:** Only when the `search_media` tool is called by an AI assistant, and only if you have configured an Unsplash API key in the plugin settings.
**What is sent:** Your search query string and your Unsplash API key.
**Unsplash Terms of Service:** https://unsplash.com/terms
**Unsplash Privacy Policy:** https://unsplash.com/privacy
**Unsplash API Guidelines:** https://unsplash.com/documentation

No data is sent to Unsplash unless you explicitly configure an API key and an AI assistant invokes the image search tool.

No other external services are contacted by this plugin.

== Installation ==

1. Upload the `lc-bricks-mcp` folder to the `/wp-content/plugins/` directory, or install the plugin via the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Navigate to **Settings > LC Bricks MCP** to configure the plugin.
4. Enable the MCP server and optionally require authentication (strongly recommended for production sites).
5. Go to **Users > Your Profile** and scroll to **Application Passwords**. Create a new Application Password and copy it — you will need it for your AI client.
6. Add your site's MCP endpoint URL and credentials to your AI client (see the [GitHub repository](https://github.com/conching/lc-bricks-mcp) for client-specific setup guides).
7. (Optional) Enter an Unsplash API key in the settings to enable image search.

== Frequently Asked Questions ==

= What is MCP (Model Context Protocol)? =

MCP is an open protocol created by Anthropic that gives AI assistants a standard way to connect to external tools and data sources. It works like a universal adapter: the AI client connects to an MCP server, discovers what tools are available, and calls them by name. This plugin implements that server for WordPress and Bricks Builder.

= Does this plugin work without Bricks Builder? =

Yes, partially. The core WordPress tools (get_site_info, and the wordpress tool's get_posts/get_post/get_users/get_plugins actions) work on any WordPress site regardless of the active theme. The Bricks-specific tools (page, element, template, and the rest) require Bricks Builder to be installed and active.

= Which AI tools and clients are supported? =

Any MCP-compatible client can connect to this plugin. Verified clients include Claude Desktop and Claude Code. Because MCP is an open protocol, support for other clients is expected to grow over time.

= Is it safe to expose a REST API endpoint for AI access? =

Yes, when configured correctly. The plugin enforces WordPress Application Password authentication by default. Only users with the appropriate WordPress capabilities can use the tools. For extra security, you can restrict access by IP or role. Never disable authentication on a publicly accessible site.

== Screenshots ==

1. The LC Bricks MCP settings page under Settings > LC Bricks MCP.
2. Example Claude Desktop configuration connecting to the MCP server endpoint.
3. An AI assistant creating a Bricks Builder hero section from a plain-text prompt.

== Changelog ==

= 2.1.3 =
* Fix: component create/update remap the structural root ID, child links, property connections, and custom CSS selectors, then validate the stored tree.
* Fix: component instances use the root element name and property values keyed by property ID; instance edits repair legacy names and property lists when touched.
* Fix: the component `properties` input accepts a `{propertyId: value}` object (it was rejected as non-array), and `component:fill_slot` saves slot content in Bricks' native `slotChildren` shape (top-level content only; repeat fills append).
* New: `component:update` repairs child links left broken by 2.1.2 and earlier (any update, e.g. a label edit, reports `repaired_links`) and rejects a root element type change (`root_type_change`).
* Changed: component definitions use Bricks' native `desc`, `label`, and metadata fields. Definition input still accepts `name` and `description` property aliases.

= 2.1.2 =
* Fix: global variable names and typography scale prefixes now use Bricks' bare storage format, preventing doubled `--` CSS properties and empty `var(--name)` references. Inputs accept names with or without `--`.
* New `global_variable:repair_names` action previews or repairs existing doubled names and scale prefixes; variable responses include `css_var`, and list warns when legacy names remain.
* Fix: variable and scale writers refuse names that collide with an existing variable after normalization (`name_taken`).

= 2.1.1 =
* Fix: the `stripped` diff in save responses is now anchored at the caller's raw input — sanitization strips recorded inside the normalizer are merged into the persistence read-back diff, so removed content (e.g. a `<script>` tag) actually appears in the response instead of an empty diff.
* Fix: stripped entries now include `removed_tags` (names of HTML tags removed), alongside before/after byte lengths.
* Fix: `page:create` responses now include `persisted` and `stripped` for the initial elements write, matching element/template saves.

= 2.1.0 =
* New tool `verify` — read-only page verification. `verify:page` returns the ordered root sections (element_id, name, label, DOM id override) and, given an element_id, its parent chain, straight from the stored tree (deterministic, no HTTP); pass `rendered: true` to verify against the rendered permalink instead. Response includes a `mode` field. Replaces the curl+grep verification loop.
* New action `verify:orphaned_css` — scans element/page/global-class custom CSS for `#brxe-<id>` selectors that target a missing element or one whose DOM id is overridden (via _attributes id / _cssId). Static Bricks CSS files on disk and external stylesheets are not scanned.
* New action `template:create_from_elements` — deep-copies a subtree of an existing page (regenerating element IDs) into a new template, routed through save_elements for correct meta-key + read-back. Response includes the new template_id and resulting root order.
* New action `template:insert_reference` — inserts a Bricks template-reference element into a target page at a given parent/position; response includes the inserted element_id and the page's resulting root order.
* Fix: global-class list/get/create/update responses now expose the CSS rules under `styles` (matching the schema and apply/remove) instead of the internal `settings` key.
* Fix: header/footer templates now unhook their own per-key sanitize filter on save, aligned to the resolved meta key.

= 2.0.0 =
* Fork: Library Creative fork of cristianuibar/bricks-mcp v1.5.1 (+ 3 private patches, now native). Renamed to LC Bricks MCP — slug/text domain `lc-bricks-mcp`, namespace `LCBricksMCP\`, REST namespace `lc-bricks-mcp/v1`. Update checker repointed to github.com/conching/lc-bricks-mcp (SHA-256-verified self-update retained). Original copyright and GPL-2.0-or-later license retained.
* Fix: template:create now honors its documented `elements` param — previously it reported success and created an empty template. Routed through save_elements so header/footer templates write the correct meta key.
* Fix: element:set_conditions now writes the resolved meta key instead of a hardcoded content key — header/footer template conditions now persist correctly and get validation + read-back.
* Fix: root-level element `position` (element:add, component:instantiate) is now a sibling index among root elements, not a raw flat-array offset — inserts land in the intended spot even when earlier roots have children.
* Fix: typography_scale:create/update now accept prefix/steps/utility_classes nested under a `settings` object as well as at top level.
* New: save-path tools (page:update_content, element:add/update/bulk_update/set_conditions, template:create) return `persisted` (bool) and a compact `stripped` diff of any altered HTML-content settings; pass `return_persisted: true` for the full read-back tree.
* Dev: schema↔handler parity check (bin/check-tool-parity.php) wired into the build; build-zip.sh produces dist/lc-bricks-mcp-<version>.zip honoring .distignore. Dev-only wp-env mu-plugin excluded from the distributable.

= 1.5.0 =
* Security: Fix SSRF in template import by enforcing wp_safe_remote_get (blocks internal network requests).
* Security: Fix SSRF in media sideload by validating URL scheme and blocking internal IPs.
* Security: Fix CSS injection by requiring dangerous_actions toggle for custom CSS writes.
* Security: Fix DOM-based XSS in diagnostic display via escHtml() helper.
* Security: Fix WP_Query parameter injection in get_posts tool — strict allowlist for query args.
* Security: Fix WP_User_Query parameter injection in get_users tool — strict allowlist for query args.
* Security: Fix schema validation failing open when Opis library is unavailable.
* Security: Hide user email and login from get_users by default, add opt-in include_pii argument.
* Security: Add MAX_BODY_SIZE constant (1 MB) and body size check before JSON decode.
* Security: Add batch size limit (max 20) for JSON-RPC requests.
* Security: Add SHA-256 checksum verification for auto-updates via upgrader_pre_download hook.
* Fix: Critical sanitization overhaul — _cssCustom multi-line CSS now preserved correctly, CSS child combinator (>) no longer encoded to &gt;, all style keys use CSS-safe sanitization.
* Fix: Background and text color in CSS import now store correct Bricks color object format.
* Fix: Border width/radius handled as CSS unit strings, not integers.
* Fix: Breakpoint map corrected to Bricks 2.x keys.
* Fix: Schema cache moved from transients to non-autoloaded wp_options (survives cache flushes).
* Fix: WooCommerce scaffold wrong method call, missing normalization, 18 incorrect element names.
* Fix: set_conditions private method access error in BricksService.
* Fix: Type mismatch in component:instantiate root-level placement.
* Fix: Type error in get_posts thumbnail cache priming.
* New: MCP auth discovery endpoint and WWW-Authenticate header for 401 responses.
* New: OAuth well-known endpoints return JSON 404 instead of HTML error pages.
* New: Rate limiter transient fallback for sites without persistent object cache.
* New: Rate limiting now covers unauthenticated requests.
* New: trigger_css_regeneration() for programmatic saves with External Files CSS mode.
* New: 20+ additional CSS properties mapped in import_classes_from_css.
* New: SchemaGenerator color, border, and typography schemas corrected for Bricks internal formats.
* New: SHA-256 checksum file generated alongside release ZIP.
* New: Unit test suite with stubs-based bootstrap (66 tests, 157 assertions).
* Removed: Dead Deactivator class and 17 unused $write_actions arrays.
* Compatibility: Verified against Bricks Builder 2.3.1 on PHP 8.2+.
* Props: @optiwebopz for the CSS sanitization contribution (PR #14).

= 1.4.0 =
* New: Connection diagnostics system — 9 automated checks detect what's blocking MCP API endpoints or App Passwords.
* New: Diagnostic panel on MCP Settings page replaces Test Connection button with richer output and fix instructions.
* New: WP Site Health integration — 3 LC Bricks MCP checks appear in Tools > Site Health.
* New: Plugin activation checks — lightweight PHP-only checks run on activate and surface issues as admin notices.
* New: MCP `get_site_info(action: 'diagnose')` returns structured JSON diagnostics for AI agents.
* New: Hosting provider detection (WP Engine, Kinsta, Flywheel, Cloudways, GoDaddy, SiteGround, Pantheon) with provider-specific fix instructions.
* New: Security plugin compatibility detection (Wordfence, iThemes, Sucuri, AIOS, WP Cerber, Perfmatters, Shield).
* New: Dependency-ordered check execution — checks skip automatically when prerequisites fail.
* New: Connection Troubleshooting section in Builder Guide for AI-assisted troubleshooting.

= 1.3.0 =
* New: Add MCP instructions field to initialize response to guide AI clients on available tools.
* New: Surface Bricks 2.3 builder settings (builderHtmlCssConverter, builderGlobalClassesImport) in get_settings.
* New: Add is_infobox flag to template list, get, and get_popup_settings responses.
* New: Enhance get_popup_schema with infobox_behavior block.
* Compatibility: Accept 'light' as canonical color param with 'hex' as alias in color_palette tool.
* Compatibility: Update Builder Guide with Bricks 2.3 CSS gotchas (_gap, _display, _widthMax corrections).
* Compatibility: Document Bricks 2.3 video element objectFit control in Builder Guide.
* Compatibility: Update wc_thankyou scaffold with Bricks 2.3 button styling controls.
* Compatibility: Document builderHtmlCssConverter and builderGlobalClassesImport in Builder Guide Key Gotchas.

= 1.2.1 =
* Compatibility: Document Bricks 2.3 toggle-mode element in Builder Guide, data model, and SchemaGenerator.
* Compatibility: Document Bricks 2.3 filter improvements in get_filter_schema.
* Compatibility: Document Bricks 2.3 Image Gallery load more and infinite scroll settings.
* Compatibility: Add loadMoreGallery interaction action for Bricks 2.3 Image Gallery.
* Compatibility: Document Bricks 2.3 perspective, scale3d, and parallax style properties.

= 1.2.0 =
* Security: Remove CORS wildcard headers and enforce per-user rate limiting.
* Security: Add `current_user_can()` authorization checks to tool execution and WordPress tool.
* Security: Validate tool arguments against inputSchema before handler dispatch.
* Security: Strip JS page settings keys on template import when dangerous actions disabled.
* New: RateLimiter class with atomic `wp_cache_incr` pattern replaces duplicated transient logic.
* New: ValidationService `validate_arguments()` for tool input schema validation.
* New: Rate limit RPM settings field on admin page.
* New: SECURITY.md documentation.
* Performance: Fix N+1 queries in post listing tools via cache priming.
* Accessibility: Add ARIA roles, id attributes, and aria-selected/tabindex to settings page.
* Accessibility: Add arrow-key tab navigation to admin updates UI.
* UI: Add settings save feedback via `settings_errors()`.
* UI: Extract inline styles from Settings.php into admin-settings.css.

= 1.1.5 =
* Fix: Plugins page now automatically detects new releases when local update cache expires, instead of requiring manual Check Now.

= 1.1.4 =
* Fix: Removed duplicate update notification on plugins page. WordPress core update notice now handles this alone.

= 1.1.3 =
* Fix: Manual setup configs showed base64-encoded placeholder that looked like real credentials. Now shows readable YOUR_BASE64_AUTH_STRING placeholder.
* Improved: Help text now explains Base64 encoding and points to Generate Setup Command button.

= 1.1.2 =
* Fix: Generated `claude mcp add` command had arguments in wrong order, causing "missing required argument name" error.

= 1.1.1 =
* Fix: Settings link in plugins list pointed to old URL after menu move, causing "not allowed to access this page" error.

= 1.1.0 =
* Move MCP settings page from WP Settings into Bricks admin menu as MCP submenu.
* Add quick setup UI with one-click app password generation and auto-fill.
* Add generate setup command JS handler for streamlined onboarding.

= 1.0.0 =
* Initial release.
* MCP server with REST API transport.
* Tools: get_site_info, get_posts, get_post, get_users, get_plugins, get_bricks_page, get_builder_guide, search_media, create_bricks_page, update_bricks_page, delete_bricks_element.
* WordPress Application Password authentication.
* Admin settings page with enable/disable toggle, auth requirement, and rate limiting.
* Unsplash API integration for image search.

== Upgrade Notice ==

= 2.1.3 =
Fixes component definitions and instances written in a shape Bricks could not render. Newly created or updated components use Bricks' native tree and property format. Components stored by earlier versions are repaired by any `component:update`; existing instances stored with `name` equal to the component ID are repaired when edited with `component:update_properties` or `component:fill_slot`.

= 2.1.2 =
Fixes doubled `--` CSS custom properties from MCP-written global variables and typography scales. Use `global_variable:repair_names` with `dry_run: true` to preview existing entries, then `dry_run: false` to repair them.

= 2.1.1 =
Fixes the empty `stripped` diff in save responses: sanitization strips are now recorded against the caller's raw input and include removed tag names; `page:create` now returns the persisted/stripped fragment. No breaking changes; endpoint and auth unchanged.

= 2.1.0 =
Adds the `verify` tool (assert page/section order without curl+grep, stored or rendered) and an orphaned-CSS scan, plus template-first tooling (create a template from a page subtree; insert a template reference). Also normalizes the global-class response key to `styles`. No breaking changes; the MCP endpoint and auth are unchanged.

= 2.0.0 =
Library Creative fork (LC Bricks MCP). New slug, namespace, and REST endpoint — MCP clients must be reconfigured with the new lc-bricks-mcp/v1 endpoint and a fresh Application Password. Fixes silent-success bugs in template creation, header/footer conditions, and root element placement; adds post-save read-back verification.

= 1.5.0 =
Major security hardening release: fixes SSRF, XSS, parameter injection, and CSS injection vulnerabilities. CSS sanitization overhaul for visual builder compatibility. MCP auth discovery endpoints. Recommended for all users.

= 1.3.0 =
MCP initialize instructions, infobox template support, Bricks 2.3 builder settings and CSS gotcha corrections, color param aliases.

= 1.2.1 =
Bricks 2.3 compatibility: toggle-mode element, filter improvements, Image Gallery load more/infinite scroll, perspective and parallax properties.

= 1.2.0 =
Security hardening, input validation, authorization checks, rate limiting overhaul, N+1 query fix, and accessibility improvements.

= 1.1.5 =
Plugins page now auto-detects new releases without manual Check Now.

= 1.1.4 =
Removes duplicate update notification on plugins page.

= 1.1.3 =
Manual setup configs now show readable placeholders instead of confusing base64-encoded strings.

= 1.1.2 =
Fixes generated setup command argument order.

= 1.1.1 =
Fixes settings link from plugins list after menu relocation.

= 1.1.0 =
Settings page moved to Bricks admin menu. New quick setup UI for easier onboarding.

= 1.0.0 =
Initial release.
