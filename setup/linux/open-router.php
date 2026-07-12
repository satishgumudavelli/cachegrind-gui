#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Localhost-only open helper for Magento/debug pages.
 *
 * Start:  bash setup/linux/open-http-server.sh
 * Open:   http://127.0.0.1:63343/open?file=/abs/path.php&line=42
 */

$allowedRoots = [
    '/var/www/html',
    '/tmp',
];

function allowed(string $path, array $roots): bool
{
    $real = realpath($path);
    if ($real === false || !is_file($real)) {
        return false;
    }
    foreach ($roots as $root) {
        $rr = realpath($root);
        if ($rr && (str_starts_with($real, rtrim($rr, '/') . '/') || $real === $rr)) {
            return true;
        }
    }
    return false;
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($uri === '/' || $uri === '/health') {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'service' => 'bottleneck-open', 'port' => 63343]);
    exit;
}

if ($uri === '/open') {
    $file = (string) ($_GET['file'] ?? '');
    $line = (int) ($_GET['line'] ?? 0);
    $editor = (string) ($_GET['editor'] ?? 'cursor');
    if ($file === '' || !allowed($file, $allowedRoots)) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'File not allowed or missing']);
        exit;
    }
    $real = realpath($file);
    $target = $line > 0 ? "{$real}:{$line}" : $real;

    $bin = 'cursor';
    if ($editor === 'code' || $editor === 'vscode') {
        $bin = 'code';
    } elseif ($editor === 'subl' || $editor === 'sublime') {
        $bin = 'subl';
    }

    $cmd = escapeshellcmd($bin) . ' -g ' . escapeshellarg($target) . ' > /dev/null 2>&1 &';
    if ($bin === 'subl') {
        $cmd = escapeshellcmd($bin) . ' ' . escapeshellarg($target) . ' > /dev/null 2>&1 &';
    }
    exec($cmd);

    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'opened' => $target, 'editor' => $bin]);
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['ok' => false, 'error' => 'Not found. Use /open?file=/abs/path&line=N']);
