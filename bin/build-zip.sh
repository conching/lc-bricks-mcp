#!/usr/bin/env bash
#
# build-zip.sh — produce a distributable plugin ZIP honoring .distignore.
#
# Output: dist/lc-bricks-mcp-<version>.zip, containing a single top-level
# folder "lc-bricks-mcp/" with the shippable plugin files (dev/, bin/, dist/,
# tests, and VCS/build cruft excluded per .distignore).
#
# Usage: bin/build-zip.sh
#
set -euo pipefail

SLUG="lc-bricks-mcp"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

# Derive the version from the plugin's version constant (single source of truth).
VERSION="$(grep -E "define\(\s*'LC_BRICKS_MCP_VERSION'" "${SLUG}.php" \
	| grep -oE "[0-9]+\.[0-9]+\.[0-9]+" | head -1)"
if [ -z "${VERSION}" ]; then
	echo "ERROR: could not determine version from ${SLUG}.php" >&2
	exit 1
fi

BUILD="${ROOT}/dist/_build"
STAGE="${BUILD}/${SLUG}"
OUT="${ROOT}/dist/${SLUG}-${VERSION}.zip"

echo "==> Building ${SLUG} v${VERSION}"

# --- PHP lint gate (best-effort) ------------------------------------------
# Blocks the build on any syntax error. Skipped (with a warning) when no PHP
# CLI is available, so the build still works in PHP-less environments.
if command -v php >/dev/null 2>&1; then
	echo "==> php -l gate"
	lint_fail=0
	while IFS= read -r f; do
		php -l "$f" >/dev/null 2>&1 || { echo "LINT FAIL: $f"; php -l "$f" || true; lint_fail=1; }
	done < <(find . -name '*.php' -type f -not -path './dist/*' -not -path './.git/*')
	[ "$lint_fail" -eq 0 ] || { echo "ERROR: PHP lint failed — aborting build." >&2; exit 1; }
else
	echo "WARN: no PHP CLI found — skipping php -l gate." >&2
fi

# --- Schema<->handler parity gate -----------------------------------------
# Blocks the build if a tool schema advertises a param no handler reads, or a
# handler reads an undeclared param (outside the documented allowlist). Skipped
# with a warning when no PHP CLI is available.
if command -v php >/dev/null 2>&1; then
	echo "==> schema<->handler parity gate"
	php "${ROOT}/bin/check-tool-parity.php" || {
		echo "ERROR: tool parity check failed — aborting build." >&2
		exit 1
	}
else
	echo "WARN: no PHP CLI found — skipping parity gate." >&2
fi

# --- Assemble staging tree ------------------------------------------------
echo "==> Staging (honoring .distignore)"
rm -rf "${BUILD}"
mkdir -p "${STAGE}"
rsync -a --exclude-from="${ROOT}/.distignore" --exclude='.git' ./ "${STAGE}/"

# --- Zip ------------------------------------------------------------------
echo "==> Zipping"
mkdir -p "${ROOT}/dist"
rm -f "${OUT}"
( cd "${BUILD}" && zip -rqX "${OUT}" "${SLUG}" -x '*.DS_Store' )
rm -rf "${BUILD}"

SIZE="$(du -h "${OUT}" | cut -f1 | tr -d ' ')"
echo "==> Built ${OUT} (${SIZE})"
