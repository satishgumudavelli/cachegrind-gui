<?php

declare(strict_types=1);

/**
 * Cachegrind bottleneck GUI — reads server paths only (browse / paste). No file upload.
 *
 * Open: http://localhost/bottleneck/  (or your vhost that serves /var/www/html)
 */

require_once __DIR__ . '/CachegrindAnalyzer.php';
require_once __DIR__ . '/SourceFileResolver.php';
require_once __DIR__ . '/ProfileMeta.php';

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

/** True if $path would sit under an allowed root even when the path does not exist yet. */
function isPathUnderAllowedRoots(string $path): bool
{
    $norm = rtrim(str_replace('\\', '/', $path), '/');
    if ($norm === '') {
        return false;
    }
    foreach (ALLOWED_ROOTS as $root) {
        $rootReal = realpath($root);
        if ($rootReal === false) {
            continue;
        }
        $rootNorm = rtrim(str_replace('\\', '/', $rootReal), '/');
        if ($norm === $rootNorm || str_starts_with($norm, $rootNorm . '/')) {
            return true;
        }
    }
    return false;
}

/**
 * Create a missing profiler directory when safe:
 * - path basename is "profiler" (e.g. …/var/profiler or bottleneck/profiler)
 * - path is under ALLOWED_ROOTS
 * - parent directory already exists and is allowed
 */
function ensureProfilerDir(string $dir): bool
{
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    if ($dir === '' || is_dir($dir)) {
        return is_dir($dir);
    }
    if (basename($dir) !== 'profiler' || !isPathUnderAllowedRoots($dir)) {
        return false;
    }
    $parent = dirname($dir);
    if (!is_dir($parent) || !isPathAllowed($parent)) {
        return false;
    }
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    // Best-effort writable for the web / CLI user that runs Xdebug
    @chmod($dir, 0775);
    return true;
}

// Default Bottleneck profile drop folder
ensureProfilerDir(__DIR__ . '/profiler');

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
    // Auto-create missing …/profiler folders under allowed roots (e.g. Magento var/profiler)
    if (!is_dir($dir)) {
        ensureProfilerDir($dir);
    }
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

    $meta = ProfileMeta::loadForDir($real);
    $baseline = $meta['baseline'] ?? null;
    foreach ($entries as &$e) {
        if (!$e['is_profile']) {
            continue;
        }
        $key = $e['name'];
        $row = is_array($meta['profiles'][$key] ?? null) ? $meta['profiles'][$key] : [];
        $e['label'] = isset($row['label']) ? (string) $row['label'] : null;
        $e['notes'] = isset($row['notes']) ? (string) $row['notes'] : null;
        $e['endpoint'] = isset($row['endpoint']) ? (string) $row['endpoint'] : null;
        $e['method'] = isset($row['method']) ? (string) $row['method'] : null;
        $e['is_baseline'] = is_string($baseline) && $baseline === $e['path'];
    }
    unset($e);

    // Group profile names by endpoint for UI
    $byEndpoint = [];
    foreach ($entries as $e) {
        if (empty($e['is_profile']) || empty($e['endpoint'])) {
            continue;
        }
        $ep = (string) $e['endpoint'];
        $byEndpoint[$ep][] = $e['name'];
    }

    $parent = dirname($real);
    jsonOut([
        'ok' => true,
        'dir' => $real,
        'parent' => isPathAllowed($parent) ? $parent : null,
        'roots' => array_values(array_filter(array_map('realpath', ALLOWED_ROOTS))),
        'entries' => $entries,
        'truncated' => $skipped,
        'baseline' => is_string($baseline) && is_file($baseline) ? $baseline : null,
        'endpoints' => $byEndpoint,
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

if ($action === 'meta_get') {
    $path = (string) ($_POST['path'] ?? $_GET['path'] ?? '');
    if ($path === '' || !isPathAllowed($path) || !is_file($path)) {
        jsonOut(['ok' => false, 'error' => 'Profile path not allowed or missing.'], 400);
    }
    $real = realpath($path);
    jsonOut(['ok' => true, 'path' => $real, 'meta' => ProfileMeta::get($real)]);
}

if ($action === 'meta_save') {
    $path = (string) ($_POST['path'] ?? '');
    if ($path === '' || !isPathAllowed($path) || !is_file($path) || !isCachegrindName(basename($path))) {
        jsonOut(['ok' => false, 'error' => 'Profile path not allowed or missing.'], 400);
    }
    $real = realpath($path);
    $ok = ProfileMeta::set($real, [
        'label' => (string) ($_POST['label'] ?? ''),
        'notes' => (string) ($_POST['notes'] ?? ''),
        'endpoint' => (string) ($_POST['endpoint'] ?? ''),
        'method' => (string) ($_POST['method'] ?? ''),
    ]);
    if (!$ok) {
        jsonOut(['ok' => false, 'error' => 'Could not save metadata (write permissions?).'], 500);
    }
    jsonOut(['ok' => true, 'path' => $real, 'meta' => ProfileMeta::get($real)]);
}

if ($action === 'baseline_set') {
    $path = (string) ($_POST['path'] ?? '');
    $clear = !empty($_POST['clear']);
    if ($clear) {
        $dir = (string) ($_POST['dir'] ?? dirname($path));
        if ($dir === '' || !isPathAllowed($dir) || !is_dir($dir)) {
            jsonOut(['ok' => false, 'error' => 'Directory not allowed.'], 400);
        }
        ProfileMeta::clearBaseline($dir);
        jsonOut(['ok' => true, 'baseline' => null, 'dir' => realpath($dir)]);
    }
    if ($path === '' || !isPathAllowed($path) || !is_file($path) || !isCachegrindName(basename($path))) {
        jsonOut(['ok' => false, 'error' => 'Profile path not allowed or missing.'], 400);
    }
    $real = realpath($path);
    if (!ProfileMeta::setBaseline($real)) {
        jsonOut(['ok' => false, 'error' => 'Could not set baseline.'], 500);
    }
    jsonOut(['ok' => true, 'baseline' => $real]);
}

if ($action === 'cleanup') {
    $dir = (string) ($_POST['dir'] ?? '');
    $keepDays = (int) ($_POST['keep_days'] ?? 14);
    $keepDays = max(0, min(3650, $keepDays));
    $keepNewest = (int) ($_POST['keep_newest'] ?? 0);
    $keepNewest = max(0, min(500, $keepNewest));
    $dryRun = !empty($_POST['dry_run']);

    if ($dir === '' || !isPathAllowed($dir) || !is_dir($dir)) {
        jsonOut(['ok' => false, 'error' => 'Directory not allowed or missing.'], 400);
    }
    $real = realpath($dir);
    $profiles = [];
    foreach (@scandir($real) ?: [] as $name) {
        if (!isCachegrindName($name)) {
            continue;
        }
        $full = $real . '/' . $name;
        if (!is_file($full)) {
            continue;
        }
        $profiles[] = [
            'path' => $full,
            'name' => $name,
            'mtime' => (int) @filemtime($full),
            'size' => (int) @filesize($full),
        ];
    }
    usort($profiles, static fn ($a, $b) => $b['mtime'] <=> $a['mtime']);

    $cutoff = $keepDays > 0 ? time() - ($keepDays * 86400) : null;
    $keepPaths = [];
    if ($keepNewest > 0) {
        foreach (array_slice($profiles, 0, $keepNewest) as $p) {
            $keepPaths[$p['path']] = true;
        }
    }
    $baseline = ProfileMeta::getBaseline($real);
    if ($baseline) {
        $keepPaths[$baseline] = true;
    }

    $toDelete = [];
    foreach ($profiles as $idx => $p) {
        if (isset($keepPaths[$p['path']])) {
            continue;
        }
        // keepNewest already folded into $keepPaths
        if ($keepDays > 0) {
            if ($cutoff !== null && $p['mtime'] < $cutoff) {
                $toDelete[] = $p;
            }
            continue;
        }
        if ($keepNewest > 0) {
            $toDelete[] = $p;
        }
    }

    $deleted = [];
    $bytes = 0;
    if (!$dryRun) {
        foreach ($toDelete as $p) {
            $cacheFile = cacheKeyForProfile($p['path']);
            if (is_file($cacheFile)) {
                @unlink($cacheFile);
            }
            if (@unlink($p['path'])) {
                $deleted[] = $p['path'];
                $bytes += $p['size'];
            }
        }
    }

    jsonOut([
        'ok' => true,
        'dir' => $real,
        'dry_run' => $dryRun,
        'candidates' => $toDelete,
        'deleted' => $deleted,
        'freed_bytes' => $bytes,
        'kept_baseline' => $baseline,
        'profile_count' => count($profiles),
    ]);
}

if ($action === 'trend') {
    $dir = (string) ($_POST['dir'] ?? '');
    $limit = (int) ($_POST['limit'] ?? 20);
    $limit = max(3, min(50, $limit));
    if ($dir === '' || !isPathAllowed($dir) || !is_dir($dir)) {
        jsonOut(['ok' => false, 'error' => 'Directory not allowed or missing.'], 400);
    }
    $real = realpath($dir);
    $meta = ProfileMeta::loadForDir($real);
    $points = [];
    foreach (@scandir($real) ?: [] as $name) {
        if (!isCachegrindName($name)) {
            continue;
        }
        $full = $real . '/' . $name;
        if (!is_file($full)) {
            continue;
        }
        try {
            $loaded = loadAnalyzer($full);
            $rowMeta = is_array($meta['profiles'][$name] ?? null) ? $meta['profiles'][$name] : [];
            $points[] = [
                'path' => $full,
                'name' => $name,
                'label' => isset($rowMeta['label']) ? (string) $rowMeta['label'] : null,
                'endpoint' => isset($rowMeta['endpoint']) ? (string) $rowMeta['endpoint'] : null,
                'mtime' => (int) @filemtime($full),
                'main_sec' => $loaded['analyzer']->mainSec(),
                'size_bytes' => (int) @filesize($full),
            ];
        } catch (Throwable $e) {
            // skip unreadable
        }
    }
    usort($points, static fn ($a, $b) => $a['mtime'] <=> $b['mtime']);
    $points = array_slice($points, -$limit);
    jsonOut(['ok' => true, 'dir' => $real, 'points' => $points]);
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
    $sortBy = (string) ($_POST['sort_by'] ?? $_GET['sort_by'] ?? 'incl');
    if ($sortBy !== 'self') {
        $sortBy = 'incl';
    }
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
            'sort_by' => $sortBy,
            'meta' => ProfileMeta::get($real),
            'baseline' => ProfileMeta::getBaseline(dirname($real)),
        ];

        if (!$detailOnly) {
            $payload['insights'] = $analyzer->insights();
            $payload['top'] = $analyzer->topFunctions($minSec, $top, $sortBy);
            $payload['keywords'] = $analyzer->keywordHotspots();
            $payload['modules'] = $analyzer->moduleRollup(max(0.005, $minSec / 2), min(60, $top));
            $payload['plugins'] = $analyzer->pluginTax(max(0.01, $minSec / 2), min(60, $top));
            $payload['flame'] = $analyzer->flameTree(8, 12, max(0.01, $minSec / 2));
            $payload['apis'] = $analyzer->thirdPartyApis(max(0.02, $minSec / 2), min(80, max(40, $top)));
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
        if (!empty($payload['flame'])) {
            $payload['flame'] = selfEnrichFlame($payload['flame'], $projectRoot);
        }
        if (!empty($payload['apis']['calls'])) {
            foreach ($payload['apis']['calls'] as &$apiRow) {
                if (!is_array($apiRow)) {
                    continue;
                }
                $resolved = SourceFileResolver::resolve(
                    isset($apiRow['file']) ? (string) $apiRow['file'] : null,
                    (string) ($apiRow['name'] ?? ''),
                    $projectRoot
                );
                if ($resolved) {
                    $apiRow['file'] = $resolved;
                }
            }
            unset($apiRow);
        }
        if (!empty($payload['plugins']['hotspots'])) {
            $payload['plugins']['hotspots'] = SourceFileResolver::enrichRows(
                $payload['plugins']['hotspots'],
                $projectRoot
            );
        }

        jsonOut($payload);
    } catch (Throwable $e) {
        jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

/**
 * @param array<string, mixed> $node
 * @return array<string, mixed>
 */
function selfEnrichFlame(array $node, ?string $projectRoot): array
{
    if (!empty($node['file']) || !empty($node['name'])) {
        $resolved = SourceFileResolver::resolve(
            isset($node['file']) ? (string) $node['file'] : null,
            (string) ($node['name'] ?? ''),
            $projectRoot
        );
        if ($resolved) {
            $node['file'] = $resolved;
        }
    }
    if (!empty($node['children']) && is_array($node['children'])) {
        $kids = [];
        foreach ($node['children'] as $child) {
            if (is_array($child)) {
                $kids[] = selfEnrichFlame($child, $projectRoot);
            }
        }
        $node['children'] = $kids;
    }
    return $node;
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

/**
 * Minimal Markdown → HTML for README.md (no external dependency).
 */
function markdownToHtml(string $md): string
{
    $md = str_replace(["\r\n", "\r"], "\n", $md);
    $parts = preg_split('/(```[\s\S]*?```)/', $md, -1, PREG_SPLIT_DELIM_CAPTURE);
    $html = '';
    foreach ($parts ?: [] as $part) {
        if ($part === '') {
            continue;
        }
        if (preg_match('/^```(\w*)\n?([\s\S]*?)```$/s', $part, $m)) {
            $code = htmlspecialchars(rtrim($m[2], "\n"), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lang = $m[1] !== '' ? ' data-lang="' . htmlspecialchars($m[1], ENT_QUOTES, 'UTF-8') . '"' : '';
            $html .= '<pre><code' . $lang . '>' . $code . '</code></pre>';
            continue;
        }
        $html .= markdownBlocksToHtml($part);
    }
    return $html;
}

function markdownInline(string $text): string
{
    $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $text = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)\s]+)\)/',
        static function (array $m): string {
            $label = $m[1];
            $href = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^https?://#i', $href)) {
                // Relative paths: keep readable without a broken link
                return '<strong>' . $label . '</strong>';
            }
            return '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                . '" target="_blank" rel="noopener noreferrer">' . $label . '</a>';
        },
        $text
    ) ?? $text;
    $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;
    $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;
    return $text;
}

function markdownBlocksToHtml(string $text): string
{
    $lines = explode("\n", $text);
    $out = '';
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $line = $lines[$i];
        $trim = rtrim($line);

        if ($trim === '') {
            $i++;
            continue;
        }

        if (preg_match('/^---+$/', $trim)) {
            $out .= '<hr>';
            $i++;
            continue;
        }

        if (preg_match('/^(#{1,3})\s+(.+)$/', $trim, $m)) {
            $lvl = strlen($m[1]);
            $out .= '<h' . $lvl . '>' . markdownInline($m[2]) . '</h' . $lvl . '>';
            $i++;
            continue;
        }

        // Table
        if (str_contains($trim, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{3,}/', $lines[$i + 1])) {
            $rows = [];
            while ($i < $n && str_contains($lines[$i], '|') && trim($lines[$i]) !== '') {
                $rows[] = $lines[$i];
                $i++;
            }
            if (count($rows) >= 2) {
                $parseRow = static function (string $row): array {
                    $row = trim($row);
                    $row = trim($row, '|');
                    return array_map('trim', explode('|', $row));
                };
                $head = $parseRow($rows[0]);
                $out .= '<table><thead><tr>';
                foreach ($head as $cell) {
                    $out .= '<th>' . markdownInline($cell) . '</th>';
                }
                $out .= '</tr></thead><tbody>';
                for ($r = 2; $r < count($rows); $r++) {
                    if (preg_match('/^\|?\s*:?-{3,}/', $rows[$r])) {
                        continue;
                    }
                    $cells = $parseRow($rows[$r]);
                    $out .= '<tr>';
                    foreach ($cells as $cell) {
                        $out .= '<td>' . markdownInline($cell) . '</td>';
                    }
                    $out .= '</tr>';
                }
                $out .= '</tbody></table>';
            }
            continue;
        }

        // Unordered list
        if (preg_match('/^\s*[-*]\s+(.+)$/', $trim, $m)) {
            $out .= '<ul>';
            while ($i < $n && preg_match('/^\s*[-*]\s+(.+)$/', rtrim($lines[$i]), $lm)) {
                $out .= '<li>' . markdownInline($lm[1]) . '</li>';
                $i++;
            }
            $out .= '</ul>';
            continue;
        }

        // Ordered list
        if (preg_match('/^\s*\d+\.\s+(.+)$/', $trim, $m)) {
            $out .= '<ol>';
            while ($i < $n && preg_match('/^\s*\d+\.\s+(.+)$/', rtrim($lines[$i]), $lm)) {
                $out .= '<li>' . markdownInline($lm[1]) . '</li>';
                $i++;
            }
            $out .= '</ol>';
            continue;
        }

        // Paragraph (consume consecutive non-blank, non-special lines)
        $buf = [$trim];
        $i++;
        while ($i < $n) {
            $next = rtrim($lines[$i]);
            if ($next === '' || preg_match('/^(#{1,3}\s|---|[-*]\s|\d+\.\s|```)/', $next) || (str_contains($next, '|') && $i + 1 < $n && preg_match('/^\|?\s*:?-{3,}/', $lines[$i + 1]))) {
                break;
            }
            $buf[] = $next;
            $i++;
        }
        $out .= '<p>' . markdownInline(implode(' ', $buf)) . '</p>';
    }
    return $out;
}

if ($action === 'readme' || $action === 'docs') {
    $path = __DIR__ . '/README.md';
    $md = is_file($path) ? (string) file_get_contents($path) : "# README missing\n\nCould not load `README.md`.";
    $body = markdownToHtml($md);
    $self = htmlspecialchars((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php'), ENT_QUOTES, 'UTF-8');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Bottleneck — README</title>';
    echo '<style>
:root{--bg:#0f1419;--panel:#1a2332;--border:#2f3f55;--text:#e7eef8;--muted:#8fa3b8;--accent:#3d9cf0;--mono:"JetBrains Mono","Fira Code",ui-monospace,monospace;--sans:"Source Sans 3","Segoe UI",system-ui,sans-serif}
*{box-sizing:border-box}
body{margin:0;font-family:var(--sans);background:var(--bg);color:var(--text);line-height:1.55}
.bar{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.85rem 1.25rem;border-bottom:1px solid var(--border);background:rgba(15,20,25,.92);position:sticky;top:0;backdrop-filter:blur(8px)}
.bar a{color:var(--accent);text-decoration:none;font-size:.9rem}
.bar a:hover{text-decoration:underline}
.wrap{max-width:860px;margin:0 auto;padding:1.25rem 1.5rem 3rem}
h1{font-size:1.55rem;margin:0 0 .75rem}
h2{font-size:1.15rem;margin:1.75rem 0 .65rem;padding-bottom:.35rem;border-bottom:1px solid var(--border)}
h3{font-size:1rem;margin:1.25rem 0 .5rem;color:#c5d4e4}
p,li{color:#d5e0ec}
a{color:var(--accent)}
code{font-family:var(--mono);font-size:.86em;background:#121820;padding:.1rem .35rem;border-radius:4px;border:1px solid var(--border)}
pre{background:#121820;border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;overflow:auto}
pre code{background:none;border:0;padding:0;font-size:.82rem}
table{border-collapse:collapse;width:100%;margin:.75rem 0 1.25rem;font-size:.9rem}
th,td{border:1px solid var(--border);padding:.45rem .6rem;text-align:left;vertical-align:top}
th{background:var(--panel);color:#c5d4e4}
hr{border:0;border-top:1px solid var(--border);margin:1.5rem 0}
ul,ol{padding-left:1.25rem}
strong{color:#fff}
.muted{color:var(--muted);font-size:.85rem}
</style></head><body>';
    echo '<div class="bar"><strong>Bottleneck docs</strong><span><a href="' . $self . '">← Back to analyzer</a></span></div>';
    echo '<div class="wrap">' . $body . '<p class="muted">Rendered from README.md</p></div>';
    echo '</body></html>';
    exit;
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
  header .header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
  }
  header h1 { margin: 0; font-size: 1.25rem; font-weight: 650; letter-spacing: .02em; }
  header p { margin: .35rem 0 0; color: var(--muted); font-size: .9rem; }
  header .doc-link {
    flex-shrink: 0;
    color: var(--accent);
    text-decoration: none;
    font-size: .88rem;
    padding: .35rem .65rem;
    border: 1px solid var(--border);
    border-radius: 6px;
    background: var(--panel);
  }
  header .doc-link:hover { filter: brightness(1.1); border-color: var(--accent); }
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
  .browser .crumb-nav {
    display: flex;
    gap: .35rem;
    margin-bottom: .45rem;
  }
  .browser .crumb-back,
  .browser .crumb-up {
    font-size: .78rem;
    padding: .25rem .55rem;
  }
  .browser .crumb-back:disabled {
    opacity: .45;
    cursor: not-allowed;
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
  a.api-url {
    font-family: var(--mono);
    font-size: .78rem;
    word-break: break-all;
    overflow-wrap: anywhere;
    color: var(--accent);
  }
  .api-endpoint { margin: 0 0 .45rem; }
  .api-slug {
    font-family: var(--mono);
    font-size: .72rem;
    color: #9fb3c8;
    word-break: break-all;
  }
  .tabs { display: flex; gap: .35rem; margin-bottom: .75rem; flex-wrap: wrap; }
  .tabs button.active { background: var(--accent); color: #061018; border-color: #2a7fd0; font-weight: 600; }
  .panel-scroll { max-height: 480px; overflow: auto; }
  .analyze-progress {
    margin: .65rem 0 0;
    display: none;
  }
  .analyze-progress.active { display: block; }
  .analyze-progress .bar {
    height: 6px;
    border-radius: 999px;
    background: #1a2430;
    overflow: hidden;
  }
  .analyze-progress .bar > span {
    display: block;
    height: 100%;
    width: 40%;
    border-radius: 999px;
    background: linear-gradient(90deg, var(--accent), #7dcea0);
    animation: bn-slide 1.2s ease-in-out infinite;
  }
  .analyze-progress .lbl {
    margin-top: .35rem;
    font-size: .78rem;
    color: var(--muted);
  }
  @keyframes bn-slide {
    0% { transform: translateX(-120%); }
    100% { transform: translateX(320%); }
  }
  .endpoint-groups {
    margin: .35rem 0 .6rem;
    padding: .5rem .65rem;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: #121820;
  }
  .endpoint-groups h3 {
    margin: 0 0 .4rem;
    font-size: .78rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .04em;
  }
  .endpoint-group {
    display: flex;
    flex-wrap: wrap;
    gap: .35rem;
    align-items: center;
    margin: .3rem 0;
    font-size: .82rem;
  }
  .endpoint-group .ep {
    color: #7db3e0;
    font-family: ui-monospace, monospace;
    font-size: .78rem;
  }
  .endpoint-group button {
    font-size: .72rem;
    padding: .15rem .45rem;
  }
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
  .detail-toolbar {
    display: flex;
    align-items: flex-start;
    gap: .65rem;
    margin: 0 0 .75rem;
  }
  .detail-back {
    flex-shrink: 0;
    font-size: .78rem;
    padding: .25rem .55rem;
    margin-top: .1rem;
  }
  .detail-back:disabled {
    opacity: .45;
    cursor: not-allowed;
  }
  .detail-title {
    font-family: var(--mono);
    font-size: .85rem;
    word-break: break-all;
    margin: 0;
    color: var(--accent);
    flex: 1;
    min-width: 0;
  }
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
  .meta-box {
    margin: .5rem 0 .75rem;
    padding: .65rem .75rem;
    border: 1px solid var(--border, #2a3540);
    border-radius: 8px;
    background: rgba(255,255,255,.02);
  }
  .choose-actions { display: flex; flex-wrap: wrap; gap: .4rem; align-items: center; }
  .scope-tag {
    display: inline-block;
    font-size: .65rem;
    padding: .1rem .35rem;
    border-radius: 4px;
    margin-right: .35rem;
    background: #243044;
    color: #9fb3c8;
    text-transform: uppercase;
  }
  .scope-tag.app { background: #1a3a2a; color: #7dcea0; }
  .scope-tag.vendor { background: #3a2a1a; color: #e0b070; }
  .scope-tag.framework { background: #2a1a3a; color: #c09be0; }
  .scope-tag.php { background: #1a2a3a; color: #7db3e0; }
  .flame-wrap { overflow-x: auto; padding: .5rem 0; }
  .flame-wrap svg { display: block; min-width: 100%; }
  .flame-wrap rect { cursor: pointer; stroke: #0f1419; stroke-width: .5; }
  .flame-wrap rect:hover { filter: brightness(1.15); }
  .flame-tip { font-size: .8rem; color: #9fb3c8; margin: .35rem 0 .75rem; }
  .trend-bar {
    display: flex; align-items: flex-end; gap: 4px; height: 140px;
    padding: .5rem 0 1.5rem; border-bottom: 1px solid #2a3540;
  }
  .trend-col { flex: 1; min-width: 18px; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; }
  .trend-col .bar { width: 100%; background: #3d8bfd; border-radius: 3px 3px 0 0; min-height: 2px; }
  .trend-col .lbl { font-size: .6rem; color: #8a9bb0; margin-top: .25rem; writing-mode: vertical-rl; transform: rotate(180deg); max-height: 70px; overflow: hidden; }
  .item .badge {
    font-size: .65rem; padding: .05rem .3rem; border-radius: 3px;
    background: #243044; color: #9fb3c8; margin-left: .35rem;
  }
  .item .badge.baseline { background: #3a2a10; color: #e0b070; }
  .item .badge.endpoint { background: #1a2a3a; color: #7db3e0; }
  button.hidden { display: none !important; }
</style>
</head>
<body>
<header>
  <div class="header-row">
    <div>
      <h1>PHP Bottleneck Analyzer</h1>
      <p>Select a local <code>cachegrind.out.*</code> path on this server (browse only — no upload). Built for Xdebug profiles.</p>
    </div>
    <a class="doc-link" href="?action=readme" target="_blank" rel="noopener noreferrer" title="Open README in a new tab">README</a>
  </div>
</header>

<div class="wrap">
  <section class="card card-choose">
    <h2>1. Choose profile</h2>
    <div class="choose-grid">
      <div class="choose-controls">
        <div class="row">
          <div class="path-field" data-target="path">
            <label>Server path (Analyze)</label>
            <input type="text" id="path" placeholder="/var/www/html/.../var/profiler/cachegrind.out.xxx" value="/var/www/html/bottleneck/profiler">
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
            <label>Time metric</label>
            <select id="sortBy">
              <option value="incl" selected>Inclusive</option>
              <option value="self">Self time</option>
            </select>
          </div>
          <div>
            <label>Scope filter</label>
            <select id="scopeFilter">
              <option value="all">All</option>
              <option value="app" selected>App only</option>
              <option value="hide_vendor">Hide vendor</option>
              <option value="vendor">Vendor only</option>
              <option value="framework">Framework</option>
            </select>
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
        <div class="meta-box" id="metaBox">
          <div class="row">
            <div>
              <label>Label</label>
              <input type="text" id="metaLabel" placeholder="e.g. checkout before session fix">
            </div>
            <div>
              <label>Endpoint</label>
              <input type="text" id="metaEndpoint" placeholder="/rest/V1/... or checkout/totals">
            </div>
            <div>
              <label>Method</label>
              <input type="text" id="metaMethod" placeholder="GET / POST">
            </div>
          </div>
          <div class="row">
            <div style="flex:1">
              <label>Notes</label>
              <input type="text" id="metaNotes" placeholder="Optional notes for this profile">
            </div>
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
          <button type="button" id="btnSaveMeta">Save label</button>
          <button type="button" id="btnBaseline">Set baseline</button>
          <button type="button" id="btnCompareBaseline">vs baseline</button>
          <button type="button" id="btnExport">Export HTML</button>
          <button type="button" id="btnExportJson" title="Download JSON">JSON</button>
          <button type="button" id="btnAiPrompt">Copy AI prompt</button>
          <button type="button" id="btnCleanup">Cleanup…</button>
          <button type="button" id="btnCancelAnalyze" class="hidden">Cancel</button>
        </div>
        <div class="analyze-progress" id="analyzeProgress" aria-live="polite">
          <div class="bar"><span></span></div>
          <div class="lbl" id="analyzeProgressLbl">Parsing profile… large files can take 1–3 minutes. Use Cancel to abort.</div>
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
        <button type="button" data-tab="modules">Modules</button>
        <button type="button" data-tab="plugins">Plugins</button>
        <button type="button" data-tab="apis">3rd-party APIs</button>
        <button type="button" data-tab="flame">Flame</button>
        <button type="button" data-tab="kw">Keyword hotspots</button>
        <button type="button" data-tab="detail">Backtrace / callers</button>
        <button type="button" data-tab="compare">Compare</button>
        <button type="button" data-tab="trend">Trend</button>
      </div>

      <div id="tab-top" class="panel-scroll">
        <div class="empty">No data yet.</div>
      </div>
      <div id="tab-modules" class="panel-scroll hidden">
        <div class="empty">Run Analyze to see Vendor\\Module rollup (self-time).</div>
      </div>
      <div id="tab-plugins" class="panel-scroll hidden">
        <div class="empty">Run Analyze to see Interceptor / Plugin / PluginList tax.</div>
      </div>
      <div id="tab-apis" class="panel-scroll hidden">
        <div class="empty">Run Analyze — HTTP call sites are read from this Cachegrind profile only.</div>
      </div>
      <div id="tab-flame" class="panel-scroll hidden">
        <div class="empty">Run Analyze to see the call-tree flame / icicle chart.</div>
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
      <div id="tab-trend" class="panel-scroll hidden">
        <div class="empty">Open a profiler folder, then click Trend to chart {main} across recent profiles.</div>
      </div>
    </section>
  </main>
</div>

<script src="app.js?v=20260713j"></script>

</body>
</html>
