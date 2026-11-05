# LC Bricks MCP

**Connect AI assistants like Claude to your Bricks Builder site.** Build and edit
WordPress pages through conversation — no clicking through builder panels.

[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)
![Bricks](https://img.shields.io/badge/Bricks-1.6%2B-ffd54f.svg)
![Version](https://img.shields.io/badge/version-2.1.1-success.svg)

LC Bricks MCP implements the [Model Context Protocol](https://modelcontextprotocol.io)
as a WordPress plugin, exposing Bricks Builder's page tree, templates, global
classes, and theme styles as a typed tool surface. Any MCP-compatible client
(Claude Desktop, Claude Code, and others) can then read and modify your site
through natural language.

> *"Create a hero section with a headline and a call-to-action button"* — and it
> happens, in the real page tree, verifiable afterward.

## Why this fork exists

AI-driven page building fails in specific, repeatable ways: writes that report
success but silently drop content, templates created against the wrong meta key,
elements placed at the wrong root position, and header/footer conditions that
don't stick. This fork's focus is **correctness you can verify** — every save is
read back after persistence, and the response reports what sanitization actually
removed rather than claiming a clean write.

Headline additions over upstream:

- **`verify` tool** — read-only page verification returning ordered root sections
  and parent chains straight from the stored tree (deterministic, no HTTP round
  trip), plus `orphaned_css` scanning for `#brxe-<id>` rules that target missing
  or id-overridden elements.
- **Read-back-after-save** on every write path, with a `stripped` diff anchored at
  the caller's raw input so removed markup actually shows up in the response.
- **Template-first tooling** — `create_from_elements` deep-copies a live subtree
  into a template with regenerated IDs; `insert_reference` places template
  references at a chosen parent and position.
- Correctness fixes for header/footer element conditions, root-level placement,
  and global-class response shapes.

## Requirements

| | |
|---|---|
| WordPress | 6.4+ |
| PHP | 8.2+ |
| Bricks Builder | 1.6+ (required for Bricks-specific tools) |

The core WordPress tools work on any site regardless of theme; the Bricks tools
need Bricks active.

## Quick start

1. Install and activate the plugin.
2. **Settings → LC Bricks MCP** — enable the server. Leave authentication
   required (the default).
3. **Users → Profile → Application Passwords** — create one and copy it.
4. Add the endpoint to your MCP client:

   ```
   https://your-site.test/wp-json/lc-bricks-mcp/v1/mcp
   ```

   Authenticate with your WordPress username and the Application Password.
5. Ask your assistant to `get_site_info` with action `diagnose` to confirm the
   connection.

Optional: add an Unsplash API key in settings to enable image search.

## Tool surface

21 action-based tools — each takes an `action` parameter selecting the operation.

| Tool | Covers |
|------|--------|
| `get_site_info` | Connection info and diagnostics |
| `wordpress` | Posts, users, plugins |
| `get_builder_guide` | Built-in Bricks reference for AI context |
| `bricks` | Settings, element schemas, global queries |
| `page` | List, search, create, update content/meta/settings/SEO, duplicate, delete |
| `element` | Add, update, remove, move, bulk update, conditions |
| `template` | CRUD, `create_from_elements`, `insert_reference`, popups, import/export |
| `template_condition` | Display condition types, set, resolve |
| `template_taxonomy` | Template tags and bundles |
| `global_class` | CRUD, apply/remove, batch, CSS import, JSON import/export |
| `theme_style` | Theme style CRUD |
| `typography_scale` | Typography scale variables |
| `color_palette` | Palettes and individual colors |
| `global_variable` | Variables and categories, batch, search |
| `media` | Library, Unsplash search, sideload, featured images |
| `menu` | Nav menu CRUD, items, location assignment |
| `component` | Bricks component CRUD, instantiate, slots, properties |
| `woocommerce` | WooCommerce elements, dynamic tags, scaffolds |
| `font` | Font settings and Adobe Fonts |
| `code` | Page CSS and scripts |
| `verify` | Page structure verification and orphaned-CSS scan |

## Documentation

| Doc | Contents |
|-----|----------|
| [`docs/BUILDER_GUIDE.md`](docs/BUILDER_GUIDE.md) | The builder reference served to AI clients |
| [`docs/BRICKS_DATA_MODEL_DEEP_DIVE.md`](docs/BRICKS_DATA_MODEL_DEEP_DIVE.md) | How Bricks stores its element tree |
| [`docs/LOCALWP_SETUP.md`](docs/LOCALWP_SETUP.md) | Local development setup |
| [`docs/SECURITY.md`](docs/SECURITY.md) | Security model and hardening |
| [`CHANGELOG.md`](CHANGELOG.md) | Full release history |

## Security

Authentication uses WordPress Application Passwords — no third-party auth
service is involved, and WordPress capability checks gate every tool. **Never
disable authentication on a publicly reachable site.** See
[`docs/SECURITY.md`](docs/SECURITY.md) for the full model.

The only optional external service is the Unsplash API, contacted solely when
you have configured a key and an assistant invokes image search.

## Provenance and license

LC Bricks MCP is a Library Creative fork of **Bricks MCP** by Uibar Ion-Cristian /
BUFF UP MEDIA S.R.L. ([`cristianuibar/bricks-mcp`](https://github.com/cristianuibar/bricks-mcp)
v1.5.1), carrying three previously-private patches as native code plus the
correctness work described above.

Licensed **GPL-2.0-or-later**, retaining the original copyright
(© 2025 BUFF UP MEDIA S.R.L.). See [`LICENSE`](LICENSE).

---

Built and maintained by [Library Creative](https://librarycreative.co) against
production client builds. Issues and corrections welcome.
