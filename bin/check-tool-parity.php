#!/usr/bin/env php
<?php
/**
 * check-tool-parity.php — dev-time schema<->handler parity tripwire.
 *
 * HEURISTIC, NOT A PROOF. It statically parses the MCP tool schemas registered
 * in Router.php and greps each tool's handler dispatch tree (Router.php +
 * includes/MCP/Services/*.php) for `$args['...']` / `$arguments['...']` reads,
 * then reports two mismatch classes that historically produced silent-success
 * bugs (schema advertises param X, handler reads param Y):
 *
 *   CHECK A  — schema properties that NO handler in the tool's dispatch tree
 *              reads ("advertised but dropped", e.g. template:create elements).
 *   CHECK B  — `$args` keys read in Router.php that NO tool schema declares
 *              ("read but undocumented"), excluding auto-detected dispatcher
 *              alias targets (keys assigned via `$args['k'] = ...`).
 *
 * Known-intentional cases live in the $ALLOW_* lists below (documented). Any
 * finding NOT on an allowlist causes a nonzero exit so bin/build-zip.sh can
 * block the build on a regression.
 *
 * Usage: php bin/check-tool-parity.php [--verbose]
 *
 * @package LCBricksMCP
 * @license GPL-2.0-or-later
 */

declare(strict_types=1);

$verbose = in_array('--verbose', $argv, true);

$root        = dirname(__DIR__);
$routerPath  = $root . '/includes/MCP/Router.php';
$serviceGlob = glob($root . '/includes/MCP/Services/*.php') ?: [];

if (!is_file($routerPath)) {
    fwrite(STDERR, "ERROR: Router.php not found at {$routerPath}\n");
    exit(2);
}

$routerSrc = (string) file_get_contents($routerPath);

/*
 * ---------------------------------------------------------------------------
 * Allowlists — known-intentional mismatches (documented). Keep this list tight;
 * every entry is a deliberate design choice, not a bug being suppressed.
 * ---------------------------------------------------------------------------
 */

// CHECK A: "tool:property" pairs where a declared schema prop is intentionally
// not read via a `$args['prop']` subscript in the dispatch tree.
// PRE-EXISTING (present at import) minor gaps or heuristic false-positives —
// NOT regressions introduced by this fork. Left as an acknowledged baseline so
// the tripwire can catch NEW mismatches; revisit if any becomes a real
// silent-drop.
$ALLOW_UNREAD = [
    // --- Consumed via a dynamic field-name loop in Router::tool_update_page_seo
    //     (~:6716  foreach($seo_field_names as $f){ ... $args[$f] ... }); the
    //     tracer only sees literal $args['key'] subscripts, so these read fine.
    'page:description', 'page:robots_noindex', 'page:robots_nofollow',
    'page:canonical', 'page:og_title', 'page:og_description', 'page:og_image',
    'page:twitter_title', 'page:twitter_description', 'page:twitter_image',
    'page:focus_keyword',
    // --- Alternate-shape / benign passthrough params advertised but not read.
    'element:element',              // alt input shape; handler reads name/settings
    'color_palette:position',       // reorder hint not consumed by add_color
    'media:filename',               // optional sideload filename override, unread
    'template:post_type',           // fixed CPT; create_template ignores it
    'template_condition:post_type', // resolve hint, consumed positionally
    'theme_style:active',           // active flag, unread by theme_style handlers
];

// CHECK B: $args keys read in Router.php that are intentionally not schema
// properties. Dispatcher alias *targets* (keys assigned via `$args['k'] = ...`)
// are auto-detected and need not be listed here. These are the dynamic-flatten
// keys copied out of object params (color{}, etc.) which cannot be detected
// statically.
$ALLOW_UNDECLARED = [
    // `wordpress` tool get_posts: optional WP_Query passthrough filters that the
    // handler honors but the schema does not advertise (Router::tool_get_posts).
    's', 'author',
    // `media` tool sideload/attribution: Unsplash API fields read from $args
    // (Router::tool_* media handlers ~:6243/:6297/:6776).
    'raw', 'unsplash_id', 'download_location',
    // `color_palette`: sub-keys flattened out of the `color` object param at
    // runtime (Router ~:4269); the `color` object itself is declared.
    'hex', 'light', 'parent', 'parent_color_id',
];

/*
 * ---------------------------------------------------------------------------
 * Tiny brace/paren matcher.
 * ---------------------------------------------------------------------------
 */
function match_delim(string $s, int $openPos, string $open, string $close): int
{
    $depth = 0;
    $len   = strlen($s);
    $inStr = '';
    for ($i = $openPos; $i < $len; $i++) {
        $c = $s[$i];
        if ($inStr !== '') {
            if ($c === '\\') { $i++; continue; }
            if ($c === $inStr) { $inStr = ''; }
            continue;
        }
        if ($c === "'" || $c === '"') { $inStr = $c; continue; }
        if ($c === $open) { $depth++; }
        elseif ($c === $close) { $depth--; if ($depth === 0) { return $i; } }
    }
    return $len - 1;
}

/**
 * Index every `function name(...) { ... }` body across the given source files.
 * Bodies for duplicated method names are concatenated (heuristic over-approx).
 *
 * @return array<string,string> name => concatenated body
 */
function index_methods(array $sources): array
{
    $map = [];
    foreach ($sources as $src) {
        if (preg_match_all('/function\s+([a-zA-Z_]\w*)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as $i => $nameCap) {
                $name     = $nameCap[0];
                $parenPos = $m[0][$i][1] + strlen($m[0][$i][0]) - 1; // at '('
                $parenEnd = match_delim($src, $parenPos, '(', ')');
                // Find the opening brace after the signature (skip return type).
                $bracePos = strpos($src, '{', $parenEnd);
                if ($bracePos === false) { continue; }
                // Guard against abstract/interface `function f();` (semicolon first).
                $semi = strpos($src, ';', $parenEnd);
                if ($semi !== false && $semi < $bracePos) { continue; }
                $braceEnd = match_delim($src, $bracePos, '{', '}');
                $body     = substr($src, $bracePos, $braceEnd - $bracePos + 1);
                $map[$name] = ($map[$name] ?? '') . "\n" . $body;
            }
        }
    }
    return $map;
}

/** Collect $args['k'] / $arguments['k'] read keys from a code fragment. */
function arg_reads(string $code): array
{
    $keys = [];
    if (preg_match_all('/\$(?:args|arguments)\s*\[\s*([\'"])([^\'"]+)\1\s*\]/', $code, $m)) {
        foreach ($m[2] as $k) { $keys[$k] = true; }
    }
    return $keys;
}

/*
 * ---------------------------------------------------------------------------
 * Parse registered tools out of Router.php.
 * ---------------------------------------------------------------------------
 */
$tools = []; // name => ['props'=>[], 'handler'=>string]
$offset = 0;
while (($pos = strpos($routerSrc, '->register_tool(', $offset)) !== false) {
    $callOpen = strpos($routerSrc, '(', $pos + strlen('->register_tool') - 1);
    $callEnd  = match_delim($routerSrc, $callOpen, '(', ')');
    $call     = substr($routerSrc, $callOpen, $callEnd - $callOpen + 1);
    $offset   = $callEnd + 1;

    // Tool name = first single-quoted string in the call.
    if (!preg_match('/\'([a-z_]+)\'/', $call, $nm)) { continue; }
    $toolName = $nm[1];

    // Handler = last array( $this, 'method' ) in the call.
    if (preg_match_all('/array\s*\(\s*\$this\s*,\s*\'([a-z_]+)\'\s*\)/', $call, $hm)) {
        $handler = end($hm[1]);
    } else {
        $handler = '';
    }

    // Properties: locate 'properties' => array( ... ) and pull depth-1 keys.
    $props = [];
    if (($pp = strpos($call, "'properties'")) !== false) {
        $arrOpen = strpos($call, '(', $pp);
        if ($arrOpen !== false) {
            $arrEnd = match_delim($call, $arrOpen, '(', ')');
            $inner  = substr($call, $arrOpen + 1, $arrEnd - $arrOpen - 1);
            // Walk depth-1 to capture top-level 'key' => keys only.
            $depth = 0; $len = strlen($inner); $inStr = '';
            for ($i = 0; $i < $len; $i++) {
                $c = $inner[$i];
                if ($inStr !== '') {
                    if ($c === '\\') { $i++; continue; }
                    if ($c === $inStr) { $inStr = ''; }
                    continue;
                }
                if ($c === '(') { $depth++; continue; }
                if ($c === ')') { $depth--; continue; }
                if (($c === "'" || $c === '"')) {
                    if ($depth === 0) {
                        // Potential top-level key: 'key' => array(
                        if (preg_match('/([\'"])([A-Za-z_][\w]*)\1\s*=>/A', substr($inner, $i), $km)) {
                            $props[$km[2]] = true;
                            $i += strlen($km[0]) - 1;
                            continue;
                        }
                    }
                    $inStr = $c;
                }
            }
        }
    }
    // 'action' is consumed by the match() dispatcher, not via $args subscript.
    unset($props['action']);
    $tools[$toolName] = ['props' => array_keys($props), 'handler' => $handler];
}

/*
 * ---------------------------------------------------------------------------
 * Build method index (Router + Services) and trace per-tool reads.
 * ---------------------------------------------------------------------------
 */
$methodBodies = index_methods(array_merge([$routerSrc], array_map(
    static fn ($f) => (string) file_get_contents($f),
    $serviceGlob
)));

/** Trace whole-$args forwarding from a handler and union all $args reads. */
function traced_reads(string $handler, array $methodBodies): array
{
    $reads   = [];
    $visited = [];
    $queue   = [$handler];
    while ($queue) {
        $m = array_shift($queue);
        if ($m === '' || isset($visited[$m])) { continue; }
        $visited[$m] = true;
        $body = $methodBodies[$m] ?? '';
        if ($body === '') { continue; }
        $reads += arg_reads($body);
        // Follow calls where the WHOLE $args/$arguments var is forwarded in ANY
        // argument position (e.g. update_page_meta( $post_id, $args )).
        if (preg_match_all('/->\s*([a-zA-Z_]\w*)\s*\(([^()]*)\)/', $body, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $c) {
                $callee = $c[1];
                foreach (explode(',', $c[2]) as $arg) {
                    $arg = trim($arg);
                    if (($arg === '$args' || $arg === '$arguments') && !isset($visited[$callee])) {
                        $queue[] = $callee;
                        break;
                    }
                }
            }
        }
    }
    return $reads;
}

/*
 * ---------------------------------------------------------------------------
 * CHECK A — declared-but-never-read (per tool).
 * ---------------------------------------------------------------------------
 */
$findingsA = [];
foreach ($tools as $name => $info) {
    $reads = traced_reads($info['handler'], $methodBodies);
    foreach ($info['props'] as $prop) {
        if (!isset($reads[$prop]) && !in_array("{$name}:{$prop}", $ALLOW_UNREAD, true)) {
            $findingsA[] = "{$name}:{$prop}";
        }
    }
}

/*
 * ---------------------------------------------------------------------------
 * CHECK B — read-but-never-declared (Router.php scope).
 * ---------------------------------------------------------------------------
 */
$declaredGlobal = [];
foreach ($tools as $info) {
    foreach ($info['props'] as $p) { $declaredGlobal[$p] = true; }
}
$declaredGlobal['action'] = true;

// Auto-detect dispatcher alias targets: keys assigned via `$args['k'] = ...`.
$aliasTargets = [];
if (preg_match_all('/\$args\s*\[\s*([\'"])([^\'"]+)\1\s*\]\s*=(?!=)/', $routerSrc, $am)) {
    foreach ($am[2] as $k) { $aliasTargets[$k] = true; }
}

$routerReads = arg_reads($routerSrc);
$findingsB   = [];
foreach (array_keys($routerReads) as $k) {
    if (isset($declaredGlobal[$k]) || isset($aliasTargets[$k])) { continue; }
    if (in_array($k, $ALLOW_UNDECLARED, true)) { continue; }
    $findingsB[] = $k;
}
sort($findingsA);
sort($findingsB);

/*
 * ---------------------------------------------------------------------------
 * Report.
 * ---------------------------------------------------------------------------
 */
echo "LC Bricks MCP — schema<->handler parity check\n";
echo str_repeat('-', 52) . "\n";
echo 'Tools parsed:            ' . count($tools) . "\n";
echo 'CHECK A findings:        ' . count($findingsA) . "  (schema prop declared, never read)\n";
echo 'CHECK B findings:        ' . count($findingsB) . "  (Router \$args read, never declared)\n";
echo 'TOTAL findings:          ' . (count($findingsA) + count($findingsB)) . "\n";

if ($verbose || $findingsA) {
    echo "\nCHECK A — declared but never read:\n";
    echo $findingsA ? ('  ' . implode("\n  ", $findingsA) . "\n") : "  (none)\n";
}
if ($verbose || $findingsB) {
    echo "\nCHECK B — read but never declared:\n";
    echo $findingsB ? ('  ' . implode("\n  ", $findingsB) . "\n") : "  (none)\n";
}

$total = count($findingsA) + count($findingsB);
if ($total > 0) {
    echo "\nRESULT: FAIL ({$total} non-allowlisted finding(s)). See lists above.\n";
    exit(1);
}
echo "\nRESULT: PASS (no non-allowlisted parity mismatches).\n";
exit(0);
