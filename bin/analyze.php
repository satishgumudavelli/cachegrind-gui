#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * CLI twin of the Cachegrind GUI analyzer.
 *
 * Usage:
 *   php bin/analyze.php /path/to/cachegrind.out.123
 *   php bin/analyze.php /path/to/cachegrind.out.123 --top 20 --min 0.05 --self
 *   php bin/analyze.php /path/to/cachegrind.out.123 --modules --plugins
 *   php bin/analyze.php /path/to/cachegrind.out.123 --json
 *   php bin/analyze.php /path/to/cachegrind.out.123 --fail-over 12
 *   php bin/analyze.php /path/to/cachegrind.out.123 --fail-module 'Acme\Checkout:0.5'
 */

$root = dirname(__DIR__);
require_once $root . '/CachegrindAnalyzer.php';
require_once $root . '/SourceFileResolver.php';
require_once $root . '/ProfileMeta.php';

$args = array_slice($argv, 1);
if ($args === [] || in_array($args[0], ['-h', '--help'], true)) {
    fwrite(STDOUT, <<<HELP
Usage: php bin/analyze.php <cachegrind.out.*> [options]

Options:
  --top N              Top functions (default 20)
  --min SEC            Min seconds threshold (default 0.05)
  --self               Sort / report by self-time
  --modules            Print module rollup (always on in text mode)
  --plugins            Print interceptor / plugin tax
  --json               JSON output
  --fail-over SEC      Exit 2 if {main} inclusive time exceeds SEC
  --fail-module M:SEC  Exit 2 if module self-time exceeds SEC
                       (repeatable; module is Vendor\\Module)

CI example:
  php bin/analyze.php \$PROFILE --fail-over 15 --fail-module 'Acme\\Checkout:0.8'

HELP);
    exit($args === [] ? 1 : 0);
}

$path = null;
$top = 20;
$min = 0.05;
$sortBy = 'incl';
$showModules = false;
$showPlugins = false;
$asJson = false;
$failOver = null;
/** @var list<array{module:string,sec:float}> $failModules */
$failModules = [];

for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if ($a === '--top' && isset($args[$i + 1])) {
        $top = max(5, (int) $args[++$i]);
    } elseif ($a === '--min' && isset($args[$i + 1])) {
        $min = (float) $args[++$i];
    } elseif ($a === '--self') {
        $sortBy = 'self';
    } elseif ($a === '--modules') {
        $showModules = true;
    } elseif ($a === '--plugins') {
        $showPlugins = true;
    } elseif ($a === '--json') {
        $asJson = true;
    } elseif ($a === '--fail-over' && isset($args[$i + 1])) {
        $failOver = (float) $args[++$i];
    } elseif ($a === '--fail-module' && isset($args[$i + 1])) {
        $spec = (string) $args[++$i];
        $pos = strrpos($spec, ':');
        if ($pos === false) {
            fwrite(STDERR, "error: --fail-module expects Vendor\\\\Module:SEC, got: {$spec}\n");
            exit(1);
        }
        $mod = substr($spec, 0, $pos);
        $sec = (float) substr($spec, $pos + 1);
        if ($mod === '' || $sec < 0) {
            fwrite(STDERR, "error: invalid --fail-module: {$spec}\n");
            exit(1);
        }
        $failModules[] = ['module' => $mod, 'sec' => $sec];
    } elseif ($a[0] !== '-') {
        $path = $a;
    } else {
        fwrite(STDERR, "error: unknown option: {$a}\n");
        exit(1);
    }
}

if ($path === null || !is_file($path)) {
    fwrite(STDERR, "error: profile not found: " . ($path ?? '(none)') . "\n");
    exit(1);
}
if (!preg_match('/^cachegrind\.out/i', basename($path))) {
    fwrite(STDERR, "error: file name should start with cachegrind.out\n");
    exit(1);
}

$real = realpath($path);
$started = microtime(true);
$analyzer = new CachegrindAnalyzer();
$analyzer->parse($real);
$parseMs = (int) round((microtime(true) - $started) * 1000);
$projectRoot = SourceFileResolver::detectProjectRoot($real);
$rows = SourceFileResolver::enrichRows($analyzer->topFunctions($min, $top, $sortBy), $projectRoot);
$modules = $analyzer->moduleRollup(max(0.005, $min / 2), min(40, $top));
$plugins = $analyzer->pluginTax(max(0.01, $min / 2), min(40, $top));
$meta = ProfileMeta::get($real);
$mainSec = $analyzer->mainSec();

$fmt = static function (float $s): string {
    if ($s >= 1) {
        return sprintf('%.2fs', $s);
    }
    if ($s >= 0.001) {
        return sprintf('%.0fms', $s * 1000);
    }
    return sprintf('%.1fµs', $s * 1e6);
};

/** @var list<string> $failures */
$failures = [];
if ($failOver !== null && $mainSec > $failOver) {
    $failures[] = sprintf('{main} %.3fs exceeds --fail-over %.3fs', $mainSec, $failOver);
}
$moduleSelf = [];
foreach ($modules as $m) {
    $moduleSelf[$m['module']] = $m['self_sec'];
}
foreach ($failModules as $rule) {
    $got = $moduleSelf[$rule['module']] ?? 0.0;
    if ($got > $rule['sec']) {
        $failures[] = sprintf(
            'module %s self %.3fs exceeds --fail-module limit %.3fs',
            $rule['module'],
            $got,
            $rule['sec']
        );
    }
}

if ($asJson) {
    $out = [
        'ok' => $failures === [],
        'path' => $real,
        'parse_ms' => $parseMs,
        'main_sec' => $mainSec,
        'meta' => $meta,
        'top' => $rows,
        'modules' => $modules,
        'plugins' => $plugins,
        'apis' => $analyzer->thirdPartyApis(max(0.01, $min / 2), 40),
        'insights' => $analyzer->insights(),
        'gates' => [
            'fail_over' => $failOver,
            'fail_modules' => $failModules,
            'failures' => $failures,
        ],
    ];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit($failures === [] ? 0 : 2);
}

echo "Profile: {$real}\n";
echo "Parse:   {$parseMs}ms\n";
echo "{main}:  " . $fmt($mainSec) . "\n";
if (!empty($meta['label'])) {
    echo "Label:   {$meta['label']}\n";
}
if (!empty($meta['endpoint'])) {
    echo "Endpoint:" . (!empty($meta['method']) ? " {$meta['method']}" : '') . " {$meta['endpoint']}\n";
}
echo "\nTop functions (" . ($sortBy === 'self' ? 'self' : 'inclusive') . ", min {$min}s):\n";
foreach ($rows as $i => $r) {
    $n = $i + 1;
    $metric = $sortBy === 'self' ? ($r['self_sec'] ?? 0) : $r['sec'];
    $self = $fmt($r['self_sec'] ?? 0);
    echo sprintf(
        "  %2d. %8s  self=%-7s  calls=%-6d  %s\n",
        $n,
        $fmt($metric),
        $self,
        $r['calls'] ?? 0,
        $r['name']
    );
}

if ($showModules || true) {
    echo "\nSuspect modules (self-time):\n";
    foreach (array_slice($modules, 0, 15) as $m) {
        echo sprintf("  %8s  %s  (%d fns)\n", $fmt($m['self_sec']), $m['module'], $m['functions']);
    }
}

if ($showPlugins || $plugins['hotspots'] !== []) {
    echo "\nPlugin / interceptor tax:\n";
    echo sprintf(
        "  total self=%s  (%.1f%% of {main})\n",
        $fmt($plugins['total_self_sec']),
        $plugins['pct_of_main']
    );
    foreach ($plugins['kinds'] as $k) {
        echo sprintf(
            "  %8s  %-12s  (%d fns, %d calls)\n",
            $fmt($k['self_sec']),
            $k['kind'],
            $k['functions'],
            $k['calls']
        );
    }
    if ($showPlugins) {
        echo "  Hotspots:\n";
        foreach (array_slice($plugins['hotspots'], 0, 12) as $h) {
            echo sprintf(
                "    %8s  self=%-7s  [%s] %s\n",
                $fmt($h['sec']),
                $fmt($h['self_sec']),
                $h['kind'],
                $h['name']
            );
        }
    }
}

$apis = $analyzer->thirdPartyApis(max(0.01, $min / 2), 40);
if ($apis['services'] !== []) {
    echo "\nHTTP / external I/O (from profile only):\n";
    foreach ($apis['services'] as $s) {
        echo sprintf(
            "  %8s  %5.1f%%  %s  (%d call sites)\n",
            $fmt($s['sec']),
            $s['pct'],
            $s['service'],
            $s['functions']
        );
    }
    echo "\nCall sites:\n";
    foreach (array_slice($apis['calls'], 0, 15) as $c) {
        echo sprintf(
            "  %8s  calls=%-5d  [%s] %s\n",
            $fmt($c['sec']),
            $c['calls'],
            $c['service'],
            $c['name']
        );
    }
}

echo "\nInsights:\n";
foreach ($analyzer->insights() as $tip) {
    echo "  - {$tip}\n";
}

if ($failures !== []) {
    echo "\nCI gates FAILED:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(2);
}

if ($failOver !== null || $failModules !== []) {
    echo "\nCI gates: OK\n";
}

exit(0);
