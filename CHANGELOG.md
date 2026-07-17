# Changelog

All notable changes to LC Bricks MCP are documented here. Format loosely
follows [Keep a Changelog](https://keepachangelog.com/). This project is a
Library Creative fork of [`cristianuibar/bricks-mcp`](https://github.com/cristianuibar/bricks-mcp)
v1.5.1 and remains licensed under GPL-2.0-or-later, retaining the original
copyright (© 2025 BUFF UP MEDIA S.R.L., author Uibar Ion-Cristian).

## [2.1.1] — 2026-07-14

Fix release from pilot E2E verification: the `stripped` diff now actually
reports what sanitization removed.

### Fixed — stripped-diff baseline (E2E check 4)

- **Strip log anchored at the caller's input.** The `stripped` diff previously
  compared the elements handed to `save_elements()` against the read-back —
  but callers pass already-normalized (sanitized) elements, so the diff was
  structurally empty: sanitization ran in `ElementNormalizer` before the
  baseline was captured. `ElementNormalizer` now records every settings value
  that `sanitize_settings()` alters (element id, dotted key path, before/after
  byte lengths) during `normalize()`, and `save_elements()` consumes that log
  and merges it with the persistence read-back diff. A `<script>` payload
  submitted to any normalizing save path now appears in `stripped`.
- **`removed_tags` on stripped entries.** Both strip-log and read-back diff
  entries now include the names of HTML tags removed by the alteration
  (e.g. `["script"]`), so callers can assert on tags instead of byte lengths.
- **`page:create` now returns `persisted` + `stripped`** for the initial
  elements write (via a persistence out-param on `create_page()`), matching
  element/template save responses. Pages created without elements omit the
  fragment — no save ran, nothing to verify.

### Notes

- Raw flat-format writes (`element:update`, native-format `update_content`)
  remain un-sanitized by design (see source audit §4); their strip log is
  empty because nothing is stripped on those paths.

## [2.1.0] — 2026-07-14

Enhancement layer on top of the 2.0.0 correctness release: read-only
verification tooling, template-first authoring, an orphaned-CSS scan, and P3
polish. No breaking changes — the REST namespace, endpoint, and auth are
unchanged.

### Added — `verify` meta-tool (audit §5b, §5c)

- **`verify:page`** — read-only page/template structure verification that
  replaces the curl+grep loop. **Stored mode** (default) reads the persisted
  flat tree and returns the ordered root sections (`element_id`, `name`,
  `label`, and the `_attributes`/`_cssId` DOM id override when set) plus, when
  an `element_id` is given, its parent chain — deterministic, no HTTP.
  **Rendered mode** (`rendered:true`) issues an internal `wp_safe_remote_get()`
  of the permalink and returns the document-order of `id="brxe-…"` elements, to
  catch template resolution / conditions / dynamic data. The response carries a
  `mode` field (`stored`|`rendered`). Target resolved by `post_id` or `url`
  (via `url_to_postid`). Read-only; `manage_options` (consistent with the other
  Bricks tools).
- **`verify:orphaned_css`** — scans the CSS surfaces reachable from the DB
  (each element's `_cssCustom`, the page-settings `_cssCustom`, and the
  `_cssCustom` of global classes in use on the page) for `#brxe-<id>` selectors
  whose target (i) doesn't exist on the page or (ii) has a DOM id override so
  the rendered id differs. Returns `[{selector, element_id, reason, source}]`.
  Documented limit: Bricks-generated static CSS files on disk and external
  stylesheets are **not** scanned.

### Added — template-first tooling (audit §5a)

- **`template:create_from_elements`** — deep-copies the subtree rooted at a
  source `post_id` + `root_element_id`, regenerating every element id
  (`ElementIdGenerator`) with the copy's root re-parented to `0`, then persists
  it as a new template via `create_template()` → `save_elements()` (so
  header/footer types get the correct meta key, validation, and read-back).
  Response includes `template_id`, the new root element id, copied count, and
  the resulting root order (reusing the `verify_page` internals).
- **`template:insert_reference`** — inserts a Bricks template-reference element
  (`{name:'template', settings:{template:<id>}}`) into a target page at
  `parent_id`/`position` by reusing `add_element` (root placement honored after
  the Stage-A `merge_elements` fix). Response includes the inserted
  `element_id` and the target page's resulting root order.

### Added — internals & tests

- **`PageInspector`** (`includes/MCP/Services/PageInspector.php`) — a
  WordPress-free helper holding the pure tree/CSS logic: `root_sections`,
  `parent_chain`, `attributes_id`, `parse_rendered_order`, `extract_subtree`,
  and `scan_orphaned_css`. Kept pure so it is unit-testable without a WP
  runtime; the WP-touching wrappers live in `BricksService`.
- **Standalone unit tests** (`tests/Unit/*Test.php`, WP-free) for subtree
  deep-copy id-regeneration (no collisions, intact linkage), the orphaned-CSS
  regex/cross-ref logic, and `verify_page`'s tree-ordering (root order, parent
  chains, rendered parsing). Runnable directly (`php tests/Unit/XTest.php`) and
  wired into `bin/build-zip.sh` as a build gate; excluded from the dist zip via
  `.distignore`.

### Fixed (P3 polish)

- **Global-class response key normalized back to `styles`** (audit §2.4
  residual sh3 drift). Bricks core stores class rules under `settings`;
  `global_class:list/get/create/update` now present the field as `styles`
  (matching the schema and the `apply`/`remove` responses) while storage stays
  on `settings`.
- **Header/footer sanitize-filter unhook aligned to the resolved meta key**
  (audit §4 note). `unhook_bricks_meta_filters()`/`rehook_bricks_meta_filters()`
  now take the content key being written; `save_elements()` passes the resolved
  key so a header/footer template unhooks its own
  `sanitize_post_meta__bricks_page_header_2`/`_footer_2` filter, not just the
  content key's. Default unchanged for all other callers.

### Documentation

- `readme.txt` tool list updated (20 → 21 tools; new `verify` tool and the two
  new `template` actions), plus 2.1.0 changelog and upgrade notes.

## [2.0.0] — 2026-07-14

First Library Creative release. Forked from bricks-mcp v1.5.1.3 (upstream
v1.5.1 + 3 private local patches). The fork rename, the three inherited
patches (now native), and a batch of Stage-A correctness fixes.

### Fork identity (rename)

No behavior change — identity only.

| Concern | Old (upstream) | New (fork) |
| --- | --- | --- |
| Plugin name | Bricks MCP | LC Bricks MCP |
| Main file | `bricks-mcp.php` | `lc-bricks-mcp.php` |
| Slug / text domain | `bricks-mcp` | `lc-bricks-mcp` |
| Translation template | `languages/bricks-mcp.pot` | `languages/lc-bricks-mcp.pot` |
| PHP namespace | `BricksMCP\` | `LCBricksMCP\` |
| Autoloader prefix | `BricksMCP\\` | `LCBricksMCP\\` |
| `@package` tag | `BricksMCP` | `LCBricksMCP` |
| Constants | `BRICKS_MCP_*` | `LC_BRICKS_MCP_*` |
| Function prefix | `bricks_mcp_*` | `lc_bricks_mcp_*` |
| Version | 1.5.1.3 | 2.0.0 |
| REST namespace | `bricks-mcp/v1` | `lc-bricks-mcp/v1` (`Server::API_NAMESPACE`) |
| Settings option | `bricks_mcp_settings` | `lc_bricks_mcp_settings` |
| Settings option group | `bricks_mcp_settings_group` | `lc_bricks_mcp_settings_group` |
| Update transient | `bricks_mcp_update_data` | `lc_bricks_mcp_update_data` |
| Settings nonce | `bricks_mcp_settings_nonce` | `lc_bricks_mcp_settings_nonce` |
| AJAX actions | `bricks_mcp_*` (check_update, test_connection, generate_app_password) | `lc_bricks_mcp_*` |
| Tools filter hook | `bricks_mcp_tools` | `lc_bricks_mcp_tools` |
| Object cache group | `bricks_mcp` | `lc_bricks_mcp` |
| Unsplash UTM source | `bricks_mcp` | `lc_bricks_mcp` |
| Uninstall cleanup keys | `bricks_mcp_version`, `bricks_mcp_activated_at`, `bricks_mcp_cleanup`, `_transient_bricks_mcp_*`, `bricks_mcp_*` user meta | `lc_bricks_mcp_*` equivalents |
| Update URI / API URL | `github.com/cristianuibar/bricks-mcp` | `github.com/conching/lc-bricks-mcp` |
| Plugin author header | Uibar Ion-Cristian | Library Creative (original credit retained in docblock/provenance) |

**Deliberately NOT renamed** (would sever the Bricks integration or vendor libs):

- Bricks core options: `bricks_global_classes`, `bricks_theme_styles`,
  `bricks_color_palette`, `bricks_global_variables` (+ `_categories`),
  `bricks_components`, `bricks_global_queries`.
- Bricks core post-meta keys: `_bricks_page_content_2`, `_bricks_page_header_2`,
  `_bricks_page_footer_2`, `_bricks_template_type`, `_bricks_template_settings`,
  `_bricks_editor_mode`.
- Bricks core constants/classes: `BRICKS_VERSION`, `BRICKS_DB_PAGE_*`, `\Bricks\*`.
- Bundled vendor namespace: `Opis\*` (opis/json-schema, opis/string, opis/uri).
- Bricks admin parent menu slug `bricks` (the settings page is a submenu of it).

> Known intentional leftover: `vendor/composer/installed.php` still records the
> Composer root package name `bricks-mcp/bricks-mcp` (auto-generated metadata,
> regenerated by `composer install`; not runtime-significant).

### Inherited private patches (now native)

These three fixes shipped as out-of-tree patches on a private deployment and are
now part of the fork's source:

1. **theme_style `styles` → `settings` arg mapping** — the theme_style dispatcher
   maps the schema's `styles` param to the `settings` key Bricks core reads, and
   `apply`/`remove` responses echo `settings ?? styles`.
2. **save_elements template meta-key resolution** — `save_elements()` resolves
   the write target via `resolve_elements_meta_key()` so header/footer templates
   persist to `_bricks_page_header_2` / `_bricks_page_footer_2` instead of the
   hardcoded content key, with the write verified by read-back.
3. **Global-class `settings` storage key** — global class create/update/batch
   store rules under the `settings` key Bricks core expects (migrating any legacy
   `styles` key on write).

### Fixed (Stage-A correctness)

- **[P1 #1] template:create honors `elements`.** `BricksService::create_template`
  advertised an `elements` param but never read it — creating a template with
  elements reported success and rendered empty. Now normalizes and persists
  elements via `save_elements()` (mirroring `create_page`), so header/footer
  templates get the correct meta key plus validation and read-back; the template
  is deleted on save failure.
- **[P1 #2] element:set_conditions writes the correct meta key.**
  `tool_set_conditions` read elements with header/footer key resolution but wrote
  them back with a hardcoded `update_post_meta($post_id, META_KEY, …)`. For
  header/footer templates the condition never applied and a stray content meta
  was created — while reporting success. Now routes through `save_elements()`
  (resolves the key, validates, read-back verifies).
- **[P2 #4] root element `position` is a sibling index.**
  `ElementNormalizer::merge_elements` treated `position` for root inserts as a
  raw flat-array offset, dropping new elements inside an earlier root's subtree.
  Ported the root-sibling counting logic from `BricksService::move_element()`.
- **[P2 #5] read-back-after-save.** `save_elements()` exposes its existing
  post-write read-back via an optional `&$persistence` out-param. Save-path tools
  (`page:update_content`, `element:add/update/bulk_update/set_conditions`,
  `template:create`) now return `persisted` (bool) and a compact `stripped` diff
  (`element_id`, `key`, `before_len`, `after_len`) of altered HTML-content
  settings; the full read-back tree is returned only with `return_persisted:true`.
- **[P2 #6] typography_scale:create/update contract.** The schema advertised both
  a `settings` container and top-level `prefix`/`steps`/`utility_classes`, but the
  handlers read only the top-level keys. The dispatcher now flattens a nested
  `settings` object into top-level params (mirroring the color_palette flatten),
  so both shapes work.

### Added (tooling / P0 / P1 #3)

- **`bin/check-tool-parity.php`** — dev-time schema↔handler parity tripwire.
  Statically parses the 20 registered tool schemas and traces each tool's handler
  dispatch tree (Router + Services) for `$args` reads, reporting schema props no
  handler reads and Router `$args` reads no schema declares, minus a documented
  allowlist. Heuristic, not a proof. Findings: **2 → 0** across the Stage-A fixes
  (`template:elements` and `typography_scale:settings` cleared).
- **`bin/build-zip.sh`** — produces `dist/lc-bricks-mcp-<version>.zip` (top-level
  `lc-bricks-mcp/` folder) honoring `.distignore`, with a `php -l` gate and the
  parity gate; a parity regression blocks the build.
- **`.distignore`, `.gitignore`.**
- **`dev/wp-env-fixes.php`** — the dev-only wp-env must-use plugin moved out of
  the shipped tree (it enables Application Passwords over plain HTTP on
  `env=local`, a security downgrade). Excluded from the build; documented in
  `dev/README.md`.

### Documentation

- `readme.txt` refreshed: stable tag 2.0.0, current 20-tool list, provenance,
  and 2.0.0 changelog/upgrade notes.

## Prior upstream history

See the `== Changelog ==` section of `readme.txt` for the upstream 1.x history
(1.0.0 – 1.5.0) inherited from bricks-mcp.
