<?php

declare(strict_types=1);

/**
 * Sidecar metadata for profiles: labels, notes, endpoint, baseline.
 * Stored as .bottleneck-meta.json next to the profile directory.
 */
final class ProfileMeta
{
    private const FILENAME = '.bottleneck-meta.json';

    public static function metaPathForDir(string $dir): string
    {
        return rtrim($dir, '/') . '/' . self::FILENAME;
    }

    /**
     * @return array{profiles: array<string, array<string, mixed>>, baseline: ?string}
     */
    public static function loadForDir(string $dir): array
    {
        $path = self::metaPathForDir($dir);
        if (!is_file($path)) {
            return ['profiles' => [], 'baseline' => null];
        }
        $raw = file_get_contents($path);
        $data = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return ['profiles' => [], 'baseline' => null];
        }
        return [
            'profiles' => is_array($data['profiles'] ?? null) ? $data['profiles'] : [],
            'baseline' => isset($data['baseline']) && is_string($data['baseline']) ? $data['baseline'] : null,
        ];
    }

    /**
     * @param array{profiles?: array<string, array<string, mixed>>, baseline?: ?string} $data
     */
    public static function saveForDir(string $dir, array $data): bool
    {
        $payload = [
            'profiles' => $data['profiles'] ?? [],
            'baseline' => $data['baseline'] ?? null,
        ];
        $path = self::metaPathForDir($dir);
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        return $json !== false && @file_put_contents($path, $json) !== false;
    }

    /**
     * @return array{label:?string,notes:?string,endpoint:?string,method:?string}|null
     */
    public static function get(string $profilePath): ?array
    {
        $real = realpath($profilePath);
        if ($real === false) {
            return null;
        }
        $meta = self::loadForDir(dirname($real));
        $key = basename($real);
        $row = $meta['profiles'][$key] ?? null;
        if (!is_array($row)) {
            return [
                'label' => null,
                'notes' => null,
                'endpoint' => null,
                'method' => null,
                'is_baseline' => ($meta['baseline'] === $real),
            ];
        }
        return [
            'label' => isset($row['label']) ? (string) $row['label'] : null,
            'notes' => isset($row['notes']) ? (string) $row['notes'] : null,
            'endpoint' => isset($row['endpoint']) ? (string) $row['endpoint'] : null,
            'method' => isset($row['method']) ? (string) $row['method'] : null,
            'is_baseline' => ($meta['baseline'] === $real),
        ];
    }

    public static function set(string $profilePath, array $fields): bool
    {
        $real = realpath($profilePath);
        if ($real === false) {
            return false;
        }
        $dir = dirname($real);
        $meta = self::loadForDir($dir);
        $key = basename($real);
        $existing = is_array($meta['profiles'][$key] ?? null) ? $meta['profiles'][$key] : [];
        foreach (['label', 'notes', 'endpoint', 'method'] as $k) {
            if (array_key_exists($k, $fields)) {
                $val = $fields[$k];
                if ($val === null || $val === '') {
                    unset($existing[$k]);
                } else {
                    $existing[$k] = (string) $val;
                }
            }
        }
        if ($existing === []) {
            unset($meta['profiles'][$key]);
        } else {
            $meta['profiles'][$key] = $existing;
        }
        return self::saveForDir($dir, $meta);
    }

    public static function setBaseline(?string $profilePath): bool
    {
        if ($profilePath === null || $profilePath === '') {
            // clear baseline in common dirs — caller should pass dir via empty + dir
            return false;
        }
        $real = realpath($profilePath);
        if ($real === false) {
            return false;
        }
        $dir = dirname($real);
        $meta = self::loadForDir($dir);
        $meta['baseline'] = $real;
        return self::saveForDir($dir, $meta);
    }

    public static function clearBaseline(string $dir): bool
    {
        $real = realpath($dir);
        if ($real === false) {
            return false;
        }
        $meta = self::loadForDir($real);
        $meta['baseline'] = null;
        return self::saveForDir($real, $meta);
    }

    public static function getBaseline(string $dir): ?string
    {
        $real = realpath($dir);
        if ($real === false) {
            return null;
        }
        $meta = self::loadForDir($real);
        $b = $meta['baseline'] ?? null;
        return is_string($b) && is_file($b) ? $b : null;
    }
}
