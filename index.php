<?php

declare(strict_types=1);

/**
 * Cachegrind bottleneck GUI — reads server paths only (browse / paste). No file upload.
 *
 * Open: http://localhost/bottleneck/  (or your vhost that serves /var/www/html)
 */

require_once __DIR__ . '/CachegrindAnalyzer.php';
require_once __DIR__ . '/SourceFileResolver.php';

@ini_set('memory_limit', '1024M');
@ini_set('max_execution_time', '300');
@set_time_limit(300);

/** Allowed directory roots for browsing / reading profiles */
const ALLOWED_ROOTS = [
    '/var/www/html',
    '/tmp',
];

function jsonOut(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function isPathAllowed(string $path): bool
{
    $real = realpath($path);
    if ($real === false) {
        return false;
    }
    foreach (ALLOWED_ROOTS as $root) {
        $rootReal = realpath($root);
        if ($rootReal !== false && str_starts_with($real, rtrim($rootReal, '/') . '/')) {
            return true;
        }
        if ($rootReal !== false && $real === $rootReal) {
            return true;
        }
    }
    return false;
}

function isCachegrindName(string $name): bool
{
    return (bool) preg_match('/^cachegrind\.out/i', $name);
}

$action = $_GET['action'] ?? $_POST['action'] ?? null;

function isBrowsableFile(string $name, bool $isDir): bool
{
    if ($name === '.' || $name === '..') {
        return false;
    }
    // Skip hidden entries
    if (str_starts_with($name, '.')) {
        return false;
    }
    return true;
}

if ($action === 'browse' || $action === 'list_dir') {
    $dir = (string) ($_POST['dir'] ?? $_GET['dir'] ?? '/var/www/html');
    if (!isPathAllowed($dir) || !is_dir($dir)) {
        jsonOut(['ok' => false, 'error' => 'Directory not allowed or missing.'], 400);
    }
    $real = realpath($dir);
    $entries = [];
    $items = @scandir($real) ?: [];
    $skipped = 0;
    $maxEntries = 800;
    foreach ($items as $name) {
        if (!isBrowsableFile($name, false)) {
            continue;
        }
        $full = $real . '/' . $name;
        $isDir = is_dir($full);
        if (count($entries) >= $maxEntries) {
            $skipped++;
            continue;
        }
        $entries[] = [
            'name' => $name,
            'path' => $full,
            'is_dir' => $isDir,
            'size' => $isDir ? null : @filesize($full),
            'mtime' => @filemtime($full) ?: null,
            'is_profile' => !$isDir && isCachegrindName($name),
        ];
    }
    usort($entries, static function ($a, $b) {
        if ($a['is_dir'] !== $b['is_dir']) {
            return $a['is_dir'] ? -1 : 1;
        }
        return strcasecmp($a['name'], $b['name']);
    });

    $parent = dirname($real);
    jsonOut([
        'ok' => true,
        'dir' => $real,
        'parent' => isPathAllowed($parent) ? $parent : null,
        'roots' => array_values(array_filter(array_map('realpath', ALLOWED_ROOTS))),
        'entries' => $entries,
        'truncated' => $skipped,
    ]);
}

if ($action === 'delete_profile') {
    $path = (string) ($_POST['path'] ?? '');
    if ($path === '' || !isPathAllowed($path) || !is_file($path)) {
        jsonOut(['ok' => false, 'error' => 'File not allowed or missing.'], 400);
    }
    if (!isCachegrindName(basename($path))) {
        jsonOut(['ok' => false, 'error' => 'Only cachegrind.out* files can be deleted.'], 400);
    }
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        jsonOut(['ok' => false, 'error' => 'File missing.'], 400);
    }
    // Drop analysis cache for this profile if present
    $cacheFile = cacheKeyForProfile($real);
    if (is_file($cacheFile)) {
        @unlink($cacheFile);
    }
    if (!@unlink($real)) {
        jsonOut(['ok' => false, 'error' => 'Could not delete file (permissions?).'], 500);
    }
    jsonOut([
        'ok' => true,
        'deleted' => $real,
        'dir' => dirname($real),
    ]);
}

function cacheKeyForProfile(string $realPath): string
{
    $mtime = (int) filemtime($realPath);
    $size = (int) filesize($realPath);
    // v2: keep compressed fn=(id) names (do not overwrite with empty)
    return sys_get_temp_dir() . '/bottleneck_' . md5($realPath . '|' . $mtime . '|' . $size . '|v2') . '.json';
}

/**
 * @return array{analyzer: CachegrindAnalyzer, parse_ms: int, cached: bool}
 */
function loadAnalyzer(string $realPath): array
{
    $cacheFile = cacheKeyForProfile($realPath);
    if (is_file($cacheFile) && filesize($cacheFile) > 100) {
        $raw = file_get_contents($cacheFile);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($data) && isset($data['idToName'], $data['incl'])) {
            $analyzer = CachegrindAnalyzer::fromArray($data);
            return ['analyzer' => $analyzer, 'parse_ms' => (int) ($data['parse_ms'] ?? 0), 'cached' => true];
        }
    }

    $started = microtime(true);
    $analyzer = new CachegrindAnalyzer();
    $analyzer->parse($realPath);
    $parseMs = (int) round((microtime(true) - $started) * 1000);
    $payload = $analyzer->toArray();
    $payload['parse_ms'] = $parseMs;
    @file_put_contents($cacheFile, json_encode($payload));

    return ['analyzer' => $analyzer, 'parse_ms' => $parseMs, 'cached' => false];
}

if ($action === 'analyze') {
    $path = (string) ($_POST['path'] ?? $_GET['path'] ?? '');
    $minSec = (float) ($_POST['min_sec'] ?? $_GET['min_sec'] ?? 0.05);
    $top = (int) ($_POST['top'] ?? $_GET['top'] ?? 40);
    $top = max(5, min(1000, $top));
    $fnId = isset($_POST['fn_id']) ? (int) $_POST['fn_id'] : (isset($_GET['fn_id']) ? (int) $_GET['fn_id'] : null);
    $find = trim((string) ($_POST['find'] ?? $_GET['find'] ?? ''));
    $detailOnly = !empty($_POST['detail_only']) || !empty($_GET['detail_only']);

    if ($path === '' || !isPathAllowed($path) || !is_file($path)) {
        jsonOut(['ok' => false, 'error' => 'Profile path not allowed or file missing. Use Browse or paste a server path under /var/www/html.'], 400);
    }
    if (!isCachegrindName(basename($path))) {
        jsonOut(['ok' => false, 'error' => 'File name should start with cachegrind.out'], 400);
    }

    try {
        $real = realpath($path);
        $loaded = loadAnalyzer($real);
        $analyzer = $loaded['analyzer'];

        $payload = [
            'ok' => true,
            'path' => $real,
            'size_bytes' => filesize($real),
            'parse_ms' => $loaded['parse_ms'],
            'cached' => $loaded['cached'],
            'main_sec' => $analyzer->mainSec(),
        ];

        if (!$detailOnly) {
            $payload['insights'] = $analyzer->insights();
            $payload['top'] = $analyzer->topFunctions($minSec, $top);
            $payload['keywords'] = $analyzer->keywordHotspots();
        }

        if ($fnId) {
            $payload['detail'] = $analyzer->functionDetail($fnId);
        } elseif ($find !== '') {
            $matches = $analyzer->findByName($find, 15);
            $payload['find_matches'] = $matches;
            if ($matches !== []) {
                $payload['detail'] = $analyzer->functionDetail($matches[0]['id']);
            }
        } elseif (!$detailOnly && !empty($payload['top'])) {
            $pick = $payload['top'][0];
            foreach ($payload['top'] as $row) {
                if ($row['name'] !== '{main}') {
                    $pick = $row;
                    break;
                }
            }
            $payload['detail'] = $analyzer->functionDetail($pick['id']);
        }

        $projectRoot = SourceFileResolver::detectProjectRoot($real);
        $payload['project_root'] = $projectRoot;
        if (!empty($payload['top'])) {
            $payload['top'] = SourceFileResolver::enrichRows($payload['top'], $projectRoot);
        }
        if (!empty($payload['keywords'])) {
            $payload['keywords'] = SourceFileResolver::enrichRows($payload['keywords'], $projectRoot);
        }
        if (!empty($payload['find_matches'])) {
            $payload['find_matches'] = SourceFileResolver::enrichRows($payload['find_matches'], $projectRoot);
        }
        if (!empty($payload['detail'])) {
            $payload['detail'] = SourceFileResolver::enrichDetail($payload['detail'], $projectRoot);
        }

        jsonOut($payload);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'compare') {
    $beforePath = (string) ($_POST['before'] ?? $_GET['before'] ?? '');
    $afterPath = (string) ($_POST['after'] ?? $_GET['after'] ?? '');
    $minSec = (float) ($_POST['min_sec'] ?? $_GET['min_sec'] ?? 0.05);
    $top = (int) ($_POST['top'] ?? $_GET['top'] ?? 40);

    foreach ([$beforePath, $afterPath] as $p) {
        if ($p === '' || !isPathAllowed($p) || !is_file($p) || !isCachegrindName(basename($p))) {
            jsonOut(['ok' => false, 'error' => 'Both Before and After must be valid cachegrind.out.* paths under allowed roots.'], 400);
        }
    }

    try {
        $beforeReal = realpath($beforePath);
        $afterReal = realpath($afterPath);
        $beforeLoaded = loadAnalyzer($beforeReal);
        $afterLoaded = loadAnalyzer($afterReal);
        $beforeRows = $beforeLoaded['analyzer']->topFunctions(0.0, 5000);
        $afterRows = $afterLoaded['analyzer']->topFunctions(0.0, 5000);

        $beforeMap = [];
        foreach ($beforeRows as $row) {
            $beforeMap[$row['name']] = $row;
        }
        $afterMap = [];
        foreach ($afterRows as $row) {
            $afterMap[$row['name']] = $row;
        }

        $names = array_unique(array_merge(array_keys($beforeMap), array_keys($afterMap)));
        $compare = [];
        foreach ($names as $name) {
            $b = $beforeMap[$name] ?? null;
            $a = $afterMap[$name] ?? null;
            $bSec = $b['sec'] ?? 0.0;
            $aSec = $a['sec'] ?? 0.0;
            if ($bSec < $minSec && $aSec < $minSec) {
                continue;
            }
            $compare[] = [
                'name' => $name,
                'before_sec' => $bSec,
                'after_sec' => $aSec,
                'delta_sec' => $aSec - $bSec,
                'before_calls' => $b['calls'] ?? 0,
                'after_calls' => $a['calls'] ?? 0,
                'delta_calls' => ($a['calls'] ?? 0) - ($b['calls'] ?? 0),
                'file' => $a['file'] ?? $b['file'] ?? null,
                'line' => $a['line'] ?? $b['line'] ?? null,
            ];
        }

        usort($compare, static fn ($x, $y) => abs($y['delta_sec']) <=> abs($x['delta_sec']));
        $compare = array_slice($compare, 0, max(10, $top));

        $projectRoot = SourceFileResolver::detectProjectRoot($afterReal)
            ?: SourceFileResolver::detectProjectRoot($beforeReal);
        foreach ($compare as &$row) {
            $resolved = SourceFileResolver::resolve(
                isset($row['file']) ? (string) $row['file'] : null,
                (string) $row['name'],
                $projectRoot
            );
            if ($resolved) {
                $row['file'] = $resolved;
            }
        }
        unset($row);

        jsonOut([
            'ok' => true,
            'before' => $beforeReal,
            'after' => $afterReal,
            'before_main_sec' => $beforeLoaded['analyzer']->mainSec(),
            'after_main_sec' => $afterLoaded['analyzer']->mainSec(),
            'delta_main_sec' => $afterLoaded['analyzer']->mainSec() - $beforeLoaded['analyzer']->mainSec(),
            'before_parse_ms' => $beforeLoaded['parse_ms'],
            'after_parse_ms' => $afterLoaded['parse_ms'],
            'before_cached' => $beforeLoaded['cached'],
            'after_cached' => $afterLoaded['cached'],
            'rows' => $compare,
        ]);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($action === 'open') {
    $file = (string) ($_POST['file'] ?? $_GET['file'] ?? '');
    $line = (int) ($_POST['line'] ?? $_GET['line'] ?? 0);
    $editor = (string) ($_POST['editor'] ?? $_GET['editor'] ?? 'cursor');
    if (!in_array($editor, ['cursor', 'vscode', 'sublime'], true)) {
        $editor = 'cursor';
    }

    if ($file === '' || !isPathAllowed($file) || !is_file($file)) {
        jsonOut(['ok' => false, 'error' => 'File not allowed or missing.'], 400);
    }

    $real = realpath($file);
    if ($editor === 'sublime') {
        // TextMate-compatible scheme used by most Sublime URL handlers on Linux/macOS.
        $uri = 'subl://open?url=' . rawurlencode('file://' . $real);
        if ($line > 0) {
            $uri .= '&line=' . $line;
        }
    } elseif ($editor === 'cursor') {
        // cursor://open?file=/abs/path&line=N
        $uri = 'cursor://open?file=' . rawurlencode($real);
        if ($line > 0) {
            $uri .= '&line=' . $line;
        }
    } else {
        // vscode://file/abs/path:line
        $uri = 'vscode://file' . $real . ($line > 0 ? (':' . $line) : '');
    }

    jsonOut([
        'ok' => true,
        'opened_via' => 'uri',
        'uri' => $uri,
        'file' => $real,
        'line' => $line > 0 ? $line : null,
        'editor' => $editor,
    ]);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>PHP Bottleneck Analyzer</title>
<style>
  :root {
    --bg: #0f1419;
    --panel: #1a2332;
    --panel2: #243044;
    --border: #2f3f55;
    --text: #e7eef8;
    --muted: #8fa3b8;
    --accent: #3d9cf0;
    --accent2: #5ad4a0;
    --warn: #f0b429;
    --danger: #f07178;
    --mono: "JetBrains Mono", "Fira Code", ui-monospace, monospace;
    --sans: "Source Sans 3", "Segoe UI", system-ui, sans-serif;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    font-family: var(--sans);
    background: radial-gradient(1200px 600px at 10% -10%, #1b3050 0%, transparent 50%),
                radial-gradient(900px 500px at 100% 0%, #143528 0%, transparent 45%),
                var(--bg);
    color: var(--text);
    min-height: 100vh;
  }
  header {
    padding: 1.25rem 1.5rem 0.75rem;
    border-bottom: 1px solid var(--border);
    background: rgba(15,20,25,.85);
    backdrop-filter: blur(8px);
    position: sticky; top: 0; z-index: 10;
  }
  header h1 { margin: 0; font-size: 1.25rem; font-weight: 650; letter-spacing: .02em; }
  header p { margin: .35rem 0 0; color: var(--muted); font-size: .9rem; }
  .wrap { display: flex; flex-direction: column; gap: 1rem; padding: 1rem 1.5rem 2rem; max-width: 1600px; margin: 0 auto; width: 100%; }
  .card-choose { width: 100%; }
  .choose-grid {
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }
  .choose-browser {
    width: 100%;
    min-width: 0;
  }
  .choose-controls .row { margin-bottom: .75rem; }
  .choose-actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .75rem; }
  .choose-actions button { flex: 0 0 auto; }
  .card {
    background: var(--panel);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 1rem;
  }
  .card h2 { margin: 0 0 .75rem; font-size: 1rem; color: var(--accent2); }
  label { display: block; font-size: .8rem; color: var(--muted); margin-bottom: .25rem; }
  input[type=text], input[type=number], select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    border-radius: 6px;
    padding: .55rem .65rem;
    font-family: var(--mono);
    font-size: .8rem;
  }
  select { font-family: var(--sans); cursor: pointer; }
  .delta-neg { color: var(--accent2); font-family: var(--mono); }
  .delta-pos { color: var(--danger); font-family: var(--mono); }
  .delta-zero { color: var(--muted); font-family: var(--mono); }
  .row { display: flex; gap: .5rem; align-items: end; margin-bottom: .75rem; }
  .row > * { flex: 1; }
  button, .btn {
    appearance: none;
    border: 1px solid var(--border);
    background: var(--panel2);
    color: var(--text);
    border-radius: 6px;
    padding: .55rem .85rem;
    cursor: pointer;
    font: inherit;
    font-size: .85rem;
  }
  button.primary { background: var(--accent); border-color: #2a7fd0; color: #061018; font-weight: 600; }
  button:hover { filter: brightness(1.08); }
  button:disabled { opacity: .5; cursor: wait; }
  .browser {
    max-height: 420px;
    min-height: 260px;
    overflow: auto;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--bg);
    font-family: var(--mono);
    font-size: .75rem;
    width: 100%;
  }
  .browser .crumb {
    padding: .65rem .75rem .55rem;
    border-bottom: 1px solid var(--border);
    color: var(--muted);
    position: sticky; top: 0; z-index: 1; background: #121820;
  }
  .browser .crumb-path {
    display: block;
    color: var(--text);
    font-size: .78rem;
    line-height: 1.45;
    overflow-wrap: anywhere;
    word-break: break-word;
    white-space: normal;
    margin-bottom: .45rem;
    padding-bottom: .4rem;
    border-bottom: 1px solid var(--border);
  }
  .browser .crumb-help {
    display: block;
    font-family: var(--sans);
    font-size: .72rem;
    color: var(--muted);
    white-space: normal;
    line-height: 1.4;
  }
  .browser .item {
    display: flex; justify-content: space-between; gap: .5rem;
    padding: .4rem .65rem;
    border-bottom: 1px solid #1c2736;
    cursor: pointer;
    align-items: center;
  }
  .browser .item:hover { background: #1a2838; }
  .browser .item.selected { background: #274060; outline: 1px solid var(--accent); }
  .browser .item.dir { color: var(--accent); }
  .browser .item.file { color: var(--accent2); }
  .browser .item .item-main { display: flex; align-items: center; gap: .35rem; min-width: 0; flex: 1; }
  .browser .item .item-main .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .browser .item .item-actions { display: flex; align-items: center; gap: .35rem; flex-shrink: 0; }
  .meta { color: var(--muted); white-space: nowrap; }
  .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: .75rem; margin-bottom: 1rem; }
  .stat { background: var(--panel2); border-radius: 8px; padding: .75rem; border: 1px solid var(--border); }
  .stat .v { font-size: 1.35rem; font-weight: 700; font-family: var(--mono); }
  .stat .l { font-size: .75rem; color: var(--muted); margin-top: .2rem; }
  .insights { margin: 0 0 1rem; padding: 0; list-style: none; }
  .insights li {
    padding: .55rem .75rem;
    margin-bottom: .4rem;
    background: #243218;
    border-left: 3px solid var(--accent2);
    border-radius: 0 6px 6px 0;
    font-size: .88rem;
  }
  table { width: 100%; border-collapse: collapse; font-size: .8rem; }
  th, td { text-align: left; padding: .45rem .5rem; border-bottom: 1px solid var(--border); vertical-align: top; }
  th.sortable {
    cursor: pointer;
    user-select: none;
    white-space: nowrap;
  }
  th.sortable:hover { color: var(--accent); }
  th.sortable .sort-ind { color: var(--accent); margin-left: .2rem; font-size: .7rem; }
  td.num { font-family: var(--mono); white-space: nowrap; color: var(--warn); }
  tr.clickable { cursor: pointer; }
  tr.clickable:hover td { background: #1e2c40; }
  tr.active td { background: #274060; }
  .name { font-family: var(--mono); word-break: break-all; }
  .tabs { display: flex; gap: .35rem; margin-bottom: .75rem; flex-wrap: wrap; }
  .tabs button.active { background: var(--accent); color: #061018; border-color: #2a7fd0; font-weight: 600; }
  .panel-scroll { max-height: 480px; overflow: auto; }
  .load-more {
    display: flex;
    align-items: center;
    gap: .75rem;
    flex-wrap: wrap;
    padding: .65rem .15rem .25rem;
    border-top: 1px solid var(--border);
    margin-top: .35rem;
  }
  .backtrace {
    font-family: var(--mono);
    font-size: .75rem;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: .75rem;
    margin-bottom: .75rem;
  }
  .backtrace .path { margin-bottom: .85rem; padding-bottom: .85rem; border-bottom: 1px dashed var(--border); }
  .backtrace .path:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
  .backtrace .step { padding: .2rem 0 .2rem 0; }
  .backtrace .arrow { color: var(--muted); margin-right: .35rem; }
  .empty { color: var(--muted); font-size: .9rem; padding: 1rem 0; }
  .err { color: var(--danger); margin: .5rem 0; font-size: .9rem; }
  .hint { font-size: .78rem; color: var(--muted); margin-top: .5rem; line-height: 1.4; }
  .detail-title { font-family: var(--mono); font-size: .85rem; word-break: break-all; margin: 0 0 .75rem; color: var(--accent); }
  .hidden { display: none; }
  a.file-open {
    color: var(--muted);
    text-decoration: none;
    cursor: default;
    border-bottom: none;
  }
  button.icon-open {
    appearance: none;
    border: 1px solid var(--border);
    background: var(--panel2);
    color: var(--accent);
    border-radius: 4px;
    padding: .15rem .4rem;
    margin-left: .35rem;
    cursor: pointer;
    font-size: .75rem;
    line-height: 1.2;
    vertical-align: middle;
  }
  button.pick-btn {
    appearance: none;
    border: 1px solid var(--border);
    background: #1e3348;
    color: var(--text);
    border-radius: 4px;
    padding: .15rem .4rem;
    font-size: .7rem;
    cursor: pointer;
  }
  button.pick-btn:hover { border-color: var(--accent); color: var(--accent); }
  button.pick-btn.danger {
    background: #3a1e24;
    color: #f0a0a8;
  }
  button.pick-btn.danger:hover { border-color: var(--danger); color: var(--danger); }
  .path-field.active-target {
    outline: 1px solid var(--accent);
    border-radius: 8px;
    padding: .35rem .45rem;
    background: rgba(61, 156, 240, 0.08);
  }
  button.open-editor {
    background: #2a4a6a;
    border-color: #3d6a9a;
    margin-left: .35rem;
    font-size: .75rem;
    padding: .3rem .55rem;
  }
  button.open-editor.primary {
    background: var(--accent);
    border-color: #2a7fd0;
    color: #061018;
    font-weight: 600;
  }
  .backtrace .step {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: .35rem;
    padding: .35rem 0;
  }
  .source-stat .l { margin-top: .4rem; }
</style>
</head>
<body>
<header>
  <h1>PHP Bottleneck Analyzer</h1>
  <p>Select a local <code>cachegrind.out.*</code> path on this server (browse only — no upload). Built for Xdebug profiles.</p>
</header>

<div class="wrap">
  <section class="card card-choose">
    <h2>1. Choose profile</h2>
    <div class="choose-grid">
      <div class="choose-controls">
        <div class="row">
          <div class="path-field" data-target="path">
            <label>Server path (Analyze)</label>
            <input type="text" id="path" placeholder="/var/www/html/.../var/profiler/cachegrind.out.xxx" value="/var/www/html/blackwoodcabinet-upgrade/var/profiler">
          </div>
        </div>
        <div class="row">
          <div>
            <label>Min seconds</label>
            <input type="number" id="minSec" value="0.05" step="0.01" min="0">
          </div>
          <div>
            <label>Top N</label>
            <input type="number" id="topN" value="40" min="5" max="1000" step="10">
          </div>
          <div>
            <label>Editor</label>
            <select id="editorApp">
              <option value="cursor" selected>Cursor</option>
              <option value="vscode">VS Code</option>
              <option value="sublime">Sublime Text</option>
            </select>
          </div>
          <div>
            <label>Click fills</label>
            <select id="browseTarget">
              <option value="path" selected>Analyze path</option>
              <option value="pathBefore">Compare before</option>
              <option value="pathAfter">Compare after</option>
            </select>
          </div>
        </div>
        <div class="row">
          <div class="path-field" data-target="pathBefore">
            <label>Compare before</label>
            <input type="text" id="pathBefore" placeholder="Pick a profile → click Before in the file list">
          </div>
          <div class="path-field" data-target="pathAfter">
            <label>Compare after</label>
            <input type="text" id="pathAfter" placeholder="Pick another profile → click After in the file list">
          </div>
        </div>
        <div class="choose-actions">
          <button type="button" class="primary" id="btnAnalyze">Analyze</button>
          <button type="button" id="btnCompare">Compare</button>
        </div>
        <p class="hint">
          Allowed roots: <code>/var/www/html</code>, <code>/tmp</code>.
          In the file list use <b>Before</b> / <b>After</b> on a profile to fill compare paths, then click <b>Compare</b>.
          Or set <b>Click fills</b> and click the filename.
          <b>Open in editor</b> uses the browser protocol handler
          (<code>cursor://open?file=…&amp;line=…</code> / <code>vscode://file/…</code> / <code>subl://</code>).
          Cursor must be installed so the OS handles <code>cursor://</code> links.
          On a new Linux PC run <code>bottleneck/setup/linux/setup-cursor-url-handler.sh</code>.
        </p>
        <div id="sideError" class="err hidden"></div>
      </div>
      <div class="browser" id="browser">
        <div class="crumb">Loading folder listing…</div>
      </div>
    </div>
  </section>

  <main>
    <section class="card" id="summaryCard">
      <h2>2. Summary</h2>
      <div class="empty" id="summaryEmpty">Run Analyze on a cachegrind profile to see totals and tips.</div>
      <div id="summaryBody" class="hidden">
        <div class="stats">
          <div class="stat"><div class="v" id="statMain">—</div><div class="l">Total ({main})</div></div>
          <div class="stat"><div class="v" id="statParse">—</div><div class="l">Parse time</div></div>
          <div class="stat"><div class="v" id="statSize">—</div><div class="l">File size</div></div>
          <div class="stat"><div class="v" id="statHot">—</div><div class="l">Top hotspot</div></div>
        </div>
        <h2 style="margin-top:0">Insights</h2>
        <ul class="insights" id="insights"></ul>
      </div>
    </section>

    <section class="card" style="margin-top:1rem">
      <div class="tabs">
        <button type="button" class="active" data-tab="top">Top functions</button>
        <button type="button" data-tab="kw">Keyword hotspots</button>
        <button type="button" data-tab="detail">Backtrace / callers</button>
        <button type="button" data-tab="compare">Compare</button>
      </div>

      <div id="tab-top" class="panel-scroll">
        <div class="empty">No data yet.</div>
      </div>
      <div id="tab-kw" class="panel-scroll hidden">
        <div class="empty">No data yet.</div>
      </div>
      <div id="tab-detail" class="hidden">
        <div class="row">
          <div>
            <label>Find function (substring)</label>
            <input type="text" id="findFn" placeholder="e.g. canApplyRushFee or getForCustomer">
          </div>
          <div style="flex:0 0 auto">
            <label>&nbsp;</label>
            <button type="button" id="btnFind">Find &amp; show backtrace</button>
          </div>
        </div>
        <div id="detailBody">
          <div class="empty">Click a row in Top / Keywords, or search above.</div>
        </div>
      </div>
      <div id="tab-compare" class="panel-scroll hidden">
        <div class="empty">Set Before + After paths, then click Compare. Negative delta = faster.</div>
      </div>
    </section>
  </main>
</div>

<script src="app.js?v=20260712y"></script>

</body>
</html>
