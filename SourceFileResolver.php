<?php

declare(strict_types=1);

/**
 * Resolve Magento / PHP function names to absolute source files for Cursor open.
 */
final class SourceFileResolver
{
    /** @var array<string, string>|null */
    private static ?array $classmap = null;

    private static ?string $classmapRoot = null;

    public static function detectProjectRoot(string $profilePath): ?string
    {
        $real = realpath($profilePath) ?: $profilePath;
        if (preg_match('#^(.+)/var/profiler(?:/|$)#', $real, $m)) {
            return $m[1];
        }

        $dir = is_file($real) ? dirname($real) : $real;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/app/etc/env.php') || is_file($dir . '/bin/magento')) {
                return $dir;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Extract FQCN from a cachegrind function name.
     */
    public static function extractClass(string $fnName): ?string
    {
        if (preg_match('#include::(.+)$#', $fnName, $m)) {
            return null; // file path handled separately
        }

        // Class->method or Class::method
        if (preg_match('#^([A-Za-z_\\\\][A-Za-z0-9_\\\\]*)(?:->|::)#', $fnName, $m)) {
            return $m[1];
        }

        return null;
    }

    public static function extractIncludePath(string $fnName): ?string
    {
        if (preg_match('#include::(.+)$#', $fnName, $m)) {
            $path = $m[1];
            return str_starts_with($path, '/') ? $path : null;
        }
        return null;
    }

    public static function resolve(?string $existingFile, string $fnName, ?string $projectRoot): ?string
    {
        if ($existingFile && str_starts_with($existingFile, '/') && is_file($existingFile)) {
            return $existingFile;
        }

        $include = self::extractIncludePath($fnName);
        if ($include && is_file($include)) {
            return $include;
        }

        if ($projectRoot === null || $projectRoot === '') {
            return null;
        }

        $class = self::extractClass($fnName);
        if ($class === null) {
            return null;
        }

        $candidates = [];

        // Prefer generated Interceptor/Proxy if the profile name includes them
        if (str_ends_with($class, '\\Interceptor') || str_contains($fnName, '\\Interceptor->') || str_contains($fnName, '\\Interceptor::')) {
            $base = preg_replace('/\\\\Interceptor$/', '', $class);
            $candidates[] = $projectRoot . '/generated/code/' . str_replace('\\', '/', $base) . '/Interceptor.php';
        }
        if (str_ends_with($class, '\\Proxy') || str_contains($fnName, '\\Proxy->')) {
            $base = preg_replace('/\\\\Proxy$/', '', $class);
            $candidates[] = $projectRoot . '/generated/code/' . str_replace('\\', '/', $base) . '/Proxy.php';
        }

        $baseClass = preg_replace('/\\\\(Interceptor|Proxy)$/', '', $class);

        // Composer classmap (fast + accurate for Magento)
        $map = self::loadClassmap($projectRoot);
        if (isset($map[$baseClass]) && is_file($map[$baseClass])) {
            $candidates[] = $map[$baseClass];
        }
        if (isset($map[$class]) && is_file($map[$class])) {
            $candidates[] = $map[$class];
        }

        // Composer PSR-4 (vendor/magento/framework, module-* etc. — not in classmap)
        $psr4Path = self::resolveViaPsr4($baseClass, $projectRoot);
        if ($psr4Path !== null) {
            $candidates[] = $psr4Path;
        }

        // app/code PSR-4 style
        $rel = str_replace('\\', '/', $baseClass) . '.php';
        $candidates[] = $projectRoot . '/app/code/' . $rel;
        $candidates[] = $projectRoot . '/generated/code/' . str_replace('\\', '/', $baseClass) . '.php';

        foreach ($candidates as $path) {
            if ($path && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function loadPsr4(string $projectRoot): array
    {
        static $cacheRoot = null;
        static $cacheMap = null;
        if ($cacheMap !== null && $cacheRoot === $projectRoot) {
            return $cacheMap;
        }

        $path = $projectRoot . '/vendor/composer/autoload_psr4.php';
        $map = [];
        if (is_file($path)) {
            $loaded = include $path;
            if (is_array($loaded)) {
                $map = $loaded;
            }
        }
        $cacheRoot = $projectRoot;
        $cacheMap = $map;
        return $map;
    }

    private static function resolveViaPsr4(string $class, string $projectRoot): ?string
    {
        $psr4 = self::loadPsr4($projectRoot);
        $bestPrefix = null;
        $bestLen = -1;
        foreach ($psr4 as $prefix => $dirs) {
            if ($prefix === '' || !is_string($prefix)) {
                continue;
            }
            if (str_starts_with($class, $prefix) && strlen($prefix) > $bestLen) {
                $bestPrefix = $prefix;
                $bestLen = strlen($prefix);
            }
        }
        if ($bestPrefix === null) {
            return null;
        }
        $relative = str_replace('\\', '/', substr($class, $bestLen)) . '.php';
        foreach ((array) ($psr4[$bestPrefix] ?? []) as $dir) {
            $full = rtrim((string) $dir, '/') . '/' . $relative;
            if (is_file($full)) {
                return $full;
            }
        }
        return null;
    }

    /**
     * @return array<string, string>
     */
    private static function loadClassmap(string $projectRoot): array
    {
        if (self::$classmap !== null && self::$classmapRoot === $projectRoot) {
            return self::$classmap;
        }

        $cacheFile = sys_get_temp_dir() . '/bottleneck_classmap_' . md5($projectRoot) . '.php';
        $classmapPath = $projectRoot . '/vendor/composer/autoload_classmap.php';
        $mtime = is_file($classmapPath) ? (int) filemtime($classmapPath) : 0;

        if (is_file($cacheFile)) {
            $cached = include $cacheFile;
            if (is_array($cached) && ($cached['mtime'] ?? 0) === $mtime && isset($cached['map'])) {
                self::$classmap = $cached['map'];
                self::$classmapRoot = $projectRoot;
                return self::$classmap;
            }
        }

        $map = [];
        if (is_file($classmapPath)) {
            /** @var array<string, string> $loaded */
            $loaded = include $classmapPath;
            if (is_array($loaded)) {
                $map = $loaded;
            }
        }

        // Compact cache write (may be large; optional)
        @file_put_contents(
            $cacheFile,
            '<?php return ' . var_export(['mtime' => $mtime, 'map' => $map], true) . ';'
        );

        self::$classmap = $map;
        self::$classmapRoot = $projectRoot;
        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function enrichRows(array $rows, ?string $projectRoot): array
    {
        foreach ($rows as &$row) {
            $name = (string) ($row['name'] ?? '');
            $file = isset($row['file']) ? (string) $row['file'] : null;
            $resolved = self::resolve($file, $name, $projectRoot);
            if ($resolved) {
                $row['file'] = $resolved;
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array<string, mixed>|null $detail
     * @return array<string, mixed>|null
     */
    public static function enrichDetail(?array $detail, ?string $projectRoot): ?array
    {
        if ($detail === null) {
            return null;
        }
        $name = (string) ($detail['name'] ?? '');
        $file = isset($detail['file']) ? (string) $detail['file'] : null;
        $resolved = self::resolve($file, $name, $projectRoot);
        if ($resolved) {
            $detail['file'] = $resolved;
            $detail['file_hint'] = $resolved;
        }
        if (!empty($detail['callers']) && is_array($detail['callers'])) {
            $detail['callers'] = self::enrichRows($detail['callers'], $projectRoot);
        }
        if (!empty($detail['callees']) && is_array($detail['callees'])) {
            $detail['callees'] = self::enrichRows($detail['callees'], $projectRoot);
        }
        if (!empty($detail['backtrace']) && is_array($detail['backtrace'])) {
            foreach ($detail['backtrace'] as &$path) {
                if (is_array($path)) {
                    $path = self::enrichRows($path, $projectRoot);
                }
            }
            unset($path);
        }
        return $detail;
    }
}
