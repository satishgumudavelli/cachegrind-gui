<?php

declare(strict_types=1);

/**
 * Streams Xdebug cachegrind.out files and builds inclusive timings + call graph.
 */
final class CachegrindAnalyzer
{
    public const TICKS_PER_SEC = 1e8;

    /** @var array<int, string> */
    private array $idToName = [];

    /** @var array<int, int> inclusive ticks */
    private array $incl = [];

    /** @var array<int, int> call counts into this function */
    private array $calls = [];

    /** @var array<int, array<int, array{calls:int, ticks:int}>> calleeId => [callerId => ...] */
    private array $callers = [];

    /** @var array<int, array<int, array{calls:int, ticks:int}>> callerId => [calleeId => ...] */
    private array $callees = [];

    /** @var array<int, string> cachegrind file-id => absolute path */
    private array $fileIdToPath = [];

    /** @var array<int, string> function-id => source file path */
    private array $fnToFile = [];

    /** @var array<int, int> function-id => representative source line */
    private array $fnToLine = [];

    /** @var int|null */
    private ?int $currentFileId = null;

    /** @var list<string> */
    public const DEFAULT_KEYWORDS = [
        '{main}',
        'ParamOverriderCartId',
        'getForCustomer',
        'QuoteRepository',
        'LoadHandler->load',
        'TotalsInformationManagement',
        'TotalsCollector->collect',
        'canApplyRushFee',
        'getProductStockStatusCode',
        'beforeCalculate',
        'collectRates',
        'AvaTax',
        'getTax',
        'Freightview',
        'collectShippingRates',
    ];

    public function parse(string $path): void
    {
        $this->idToName = [];
        $this->incl = [];
        $this->calls = [];
        $this->callers = [];
        $this->callees = [];
        $this->fileIdToPath = [];
        $this->fnToFile = [];
        $this->fnToLine = [];
        $this->currentFileId = null;

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException('Cannot open profile: ' . $path);
        }

        $currentFn = null;
        $pendingCallee = null;
        $pendingCallCount = 0;

        while (($raw = fgets($fh)) !== false) {
            $line = trim($raw);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            if (preg_match('/^fl=\((\d+)\)\s*(.*)$/', $line, $m)) {
                $this->currentFileId = (int) $m[1];
                $filePath = trim($m[2]);
                if ($filePath !== '') {
                    $this->fileIdToPath[$this->currentFileId] = $filePath;
                }
                continue;
            }

            if (str_starts_with($line, 'fl=') && !str_starts_with($line, 'fl=(')) {
                $filePath = trim(substr($line, 3));
                // Synthetic id for path-only fl=
                $this->currentFileId = -1 * (crc32($filePath) & 0x7fffffff);
                if ($filePath !== '') {
                    $this->fileIdToPath[$this->currentFileId] = $filePath;
                }
                continue;
            }

            if (preg_match('/^fn=\((\d+)\)\s*(.*)$/', $line, $m)) {
                $currentFn = (int) $m[1];
                // Compressed format: first occurrence has the name; later lines are just fn=(id).
                // Never overwrite a known name with an empty string.
                $fnName = $m[2];
                if ($fnName !== '') {
                    $this->idToName[$currentFn] = $fnName;
                } elseif (!isset($this->idToName[$currentFn])) {
                    $this->idToName[$currentFn] = '';
                }
                if ($this->currentFileId !== null && isset($this->fileIdToPath[$this->currentFileId])) {
                    $this->fnToFile[$currentFn] = $this->fileIdToPath[$this->currentFileId];
                }
                $pendingCallee = null;
                continue;
            }

            // Named fn without numeric id (rare)
            if (str_starts_with($line, 'fn=') && !str_starts_with($line, 'fn=(')) {
                $currentFn = null;
                $pendingCallee = null;
                continue;
            }

            if (preg_match('/^cfn=\((\d+)\)/', $line, $m)) {
                $pendingCallee = (int) $m[1];
                if (preg_match('/^cfn=\((\d+)\)\s*(.*)$/', $line, $m2)) {
                    $cfnName = $m2[2];
                    if ($cfnName !== '') {
                        $this->idToName[$pendingCallee] = $cfnName;
                    } elseif (!isset($this->idToName[$pendingCallee])) {
                        $this->idToName[$pendingCallee] = '';
                    }
                }
                continue;
            }

            if (str_starts_with($line, 'calls=') && $pendingCallee !== null) {
                if (preg_match('/^calls=(\d+)/', $line, $m)) {
                    $pendingCallCount = (int) $m[1];
                }
                continue;
            }

            if ($currentFn !== null && preg_match('/^(\d+)\s+(\d+)/', $line, $m)) {
                $srcLine = (int) $m[1];
                $ticks = (int) $m[2];
                if (!isset($this->fnToLine[$currentFn]) && $srcLine > 0) {
                    $this->fnToLine[$currentFn] = $srcLine;
                }
                // Inclusive time for current fn includes child call costs (same as CLI analyzer).
                $this->incl[$currentFn] = ($this->incl[$currentFn] ?? 0) + $ticks;

                if ($pendingCallee !== null) {
                    $this->recordEdge($currentFn, $pendingCallee, $pendingCallCount, $ticks);
                    $this->calls[$pendingCallee] = ($this->calls[$pendingCallee] ?? 0) + $pendingCallCount;
                    $pendingCallee = null;
                    $pendingCallCount = 0;
                }
            }
        }

        fclose($fh);
    }

    private function recordEdge(int $callerId, int $calleeId, int $callCount, int $ticks): void
    {
        if (!isset($this->callees[$callerId][$calleeId])) {
            $this->callees[$callerId][$calleeId] = ['calls' => 0, 'ticks' => 0];
        }
        $this->callees[$callerId][$calleeId]['calls'] += max(1, $callCount);
        $this->callees[$callerId][$calleeId]['ticks'] += $ticks;

        if (!isset($this->callers[$calleeId][$callerId])) {
            $this->callers[$calleeId][$callerId] = ['calls' => 0, 'ticks' => 0];
        }
        $this->callers[$calleeId][$callerId]['calls'] += max(1, $callCount);
        $this->callers[$calleeId][$callerId]['ticks'] += $ticks;
    }

    public static function ticksToSec(int $ticks): float
    {
        return $ticks / self::TICKS_PER_SEC;
    }

    /**
     * @return list<array{id:int,name:string,sec:float,calls:int}>
     */
    public function topFunctions(float $minSec = 0.05, int $limit = 40): array
    {
        $rows = [];
        foreach ($this->idToName as $id => $name) {
            $sec = self::ticksToSec($this->incl[$id] ?? 0);
            if ($sec < $minSec) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'sec' => $sec,
                'calls' => $this->calls[$id] ?? 0,
                'file' => $this->resolveFile($id, $name),
                'line' => $this->fnToLine[$id] ?? null,
            ];
        }
        usort($rows, static fn ($a, $b) => $b['sec'] <=> $a['sec']);
        return array_slice($rows, 0, $limit);
    }

    /**
     * @param list<string> $keywords
     * @return list<array{id:int,name:string,sec:float,calls:int,keyword:string}>
     */
    public function keywordHotspots(array $keywords = null, float $minSec = 0.001): array
    {
        $keywords = $keywords ?? self::DEFAULT_KEYWORDS;
        $rows = [];
        $seen = [];

        foreach ($keywords as $kw) {
            foreach ($this->idToName as $id => $name) {
                if (!str_contains($name, $kw)) {
                    continue;
                }
                if (isset($seen[$name])) {
                    continue;
                }
                $sec = self::ticksToSec($this->incl[$id] ?? 0);
                if ($sec < $minSec && $kw !== '{main}') {
                    continue;
                }
                $seen[$name] = true;
                $rows[] = [
                    'id' => $id,
                    'name' => $name,
                    'sec' => $sec,
                    'calls' => $this->calls[$id] ?? 0,
                    'keyword' => $kw,
                    'file' => $this->resolveFile($id, $name),
                    'line' => $this->fnToLine[$id] ?? null,
                ];
            }
        }

        usort($rows, static fn ($a, $b) => $b['sec'] <=> $a['sec']);
        return $rows;
    }

    public function mainSec(): float
    {
        foreach ($this->idToName as $id => $name) {
            if ($name === '{main}') {
                return self::ticksToSec($this->incl[$id] ?? 0);
            }
        }
        return 0.0;
    }

    /**
     * @return array{id:int,name:string,sec:float,calls:int,file_hint:?string,callers:list,callees:list}|null
     */
    public function functionDetail(int $fnId, int $edgeLimit = 25): ?array
    {
        if (!isset($this->idToName[$fnId])) {
            return null;
        }

        $name = $this->idToName[$fnId];
        $file = $this->resolveFile($fnId, $name);
        return [
            'id' => $fnId,
            'name' => $name,
            'sec' => self::ticksToSec($this->incl[$fnId] ?? 0),
            'calls' => $this->calls[$fnId] ?? 0,
            'file' => $file,
            'line' => $this->fnToLine[$fnId] ?? null,
            'file_hint' => $file ?? $this->guessFileHint($name),
            'callers' => $this->formatEdges($this->callers[$fnId] ?? [], $edgeLimit),
            'callees' => $this->formatEdges($this->callees[$fnId] ?? [], $edgeLimit),
            'backtrace' => $this->buildBacktracePaths($fnId, 5, 4),
        ];
    }

    /**
     * Find function ids by substring.
     *
     * @return list<array{id:int,name:string,sec:float,calls:int}>
     */
    public function findByName(string $needle, int $limit = 20): array
    {
        $needle = trim($needle);
        if ($needle === '') {
            return [];
        }
        $rows = [];
        foreach ($this->idToName as $id => $name) {
            if (!str_contains($name, $needle)) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'sec' => self::ticksToSec($this->incl[$id] ?? 0),
                'calls' => $this->calls[$id] ?? 0,
                'file' => $this->resolveFile($id, $name),
                'line' => $this->fnToLine[$id] ?? null,
            ];
        }
        usort($rows, static fn ($a, $b) => $b['sec'] <=> $a['sec']);
        return array_slice($rows, 0, $limit);
    }

    /**
     * Walk up callers a few levels (approximate backtrace roots).
     *
     * @return list<list<array{id:int,name:string,sec:float}>>
     */
    private function buildBacktracePaths(int $fnId, int $maxPaths, int $maxDepth): array
    {
        $paths = [];
        $this->walkCallers($fnId, [], $paths, $maxPaths, $maxDepth);
        return $paths;
    }

    /**
     * @param list<array{id:int,name:string,sec:float}> $path
     * @param list<list<array{id:int,name:string,sec:float}>> $out
     */
    private function walkCallers(int $fnId, array $path, array &$out, int $maxPaths, int $depthLeft): void
    {
        if (count($out) >= $maxPaths) {
            return;
        }

        $node = [
            'id' => $fnId,
            'name' => $this->idToName[$fnId] ?? ('#' . $fnId),
            'sec' => self::ticksToSec($this->incl[$fnId] ?? 0),
            'file' => $this->resolveFile($fnId, $this->idToName[$fnId] ?? ''),
            'line' => $this->fnToLine[$fnId] ?? null,
        ];
        $nextPath = array_merge([$node], $path);

        $parents = $this->callers[$fnId] ?? [];
        if ($parents === [] || $depthLeft <= 0) {
            $out[] = $nextPath;
            return;
        }

        uasort($parents, static fn ($a, $b) => $b['ticks'] <=> $a['ticks']);
        $i = 0;
        foreach ($parents as $callerId => $edge) {
            if ($i++ >= 2) {
                break; // branch factor
            }
            // avoid cycles
            foreach ($nextPath as $p) {
                if ($p['id'] === $callerId) {
                    $out[] = $nextPath;
                    continue 2;
                }
            }
            $this->walkCallers($callerId, $nextPath, $out, $maxPaths, $depthLeft - 1);
        }
    }

    /**
     * @param array<int, array{calls:int, ticks:int}> $edges
     * @return list<array{id:int,name:string,sec:float,calls:int}>
     */
    private function formatEdges(array $edges, int $limit): array
    {
        $rows = [];
        foreach ($edges as $id => $edge) {
            $rows[] = [
                'id' => $id,
                'name' => $this->idToName[$id] ?? ('#' . $id),
                'sec' => self::ticksToSec($edge['ticks']),
                'calls' => $edge['calls'],
                'file' => $this->resolveFile($id, $this->idToName[$id] ?? ''),
                'line' => $this->fnToLine[$id] ?? null,
            ];
        }
        usort($rows, static fn ($a, $b) => $b['sec'] <=> $a['sec']);
        return array_slice($rows, 0, $limit);
    }

    private function resolveFile(int $fnId, string $name): ?string
    {
        if (isset($this->fnToFile[$fnId]) && $this->fnToFile[$fnId] !== '') {
            return $this->fnToFile[$fnId];
        }
        if (preg_match('#include::(.+)$#', $name, $m)) {
            return $m[1];
        }
        // php::foo:{/absolute/path.php:12} or similar
        if (preg_match('#\{(/[^}\s]+)\}#', $name, $m)) {
            $path = preg_replace('/:\d+$/', '', $m[1]);
            return $path !== '' ? $path : null;
        }
        return null;
    }

    private function guessFileHint(string $name): ?string
    {
        if (preg_match('#include::(.+)$#', $name, $m)) {
            return $m[1];
        }
        if (preg_match('#^((?:\\\\)?[A-Za-z0-9_\\\\]+)->#', $name, $m)) {
            return str_replace('\\', '/', $m[1]) . '.php';
        }
        return null;
    }

    /**
     * Short tips derived from the profile.
     *
     * @return list<string>
     */
    public function insights(): array
    {
        $tips = [];
        $main = $this->mainSec();
        $top = $this->topFunctions(0.01, 5);
        $kw = $this->keywordHotspots();

        if ($main > 0) {
            $tips[] = sprintf('Whole request ({main}) ≈ %.2fs in this profile (Xdebug profiling inflates wall time).', $main);
        }

        foreach ($kw as $row) {
            if (str_contains($row['name'], 'getForCustomer') && $row['sec'] > 0.5) {
                $tips[] = sprintf(
                    'Heavy quote load: %s ≈ %.2fs — consider lightweight cart-ID lookup if REST only needs entity_id.',
                    $this->shortName($row['name']),
                    $row['sec']
                );
            }
            if (str_contains($row['name'], 'canApplyRushFee') && $row['sec'] > 0.3) {
                $tips[] = sprintf(
                    'Rush fee eligibility: %s ≈ %.2fs / %d calls — cache per request or avoid full stock status in loops.',
                    $this->shortName($row['name']),
                    $row['sec'],
                    $row['calls']
                );
            }
            if (str_contains($row['name'], 'AvaTax') && $row['sec'] > 0.5) {
                $tips[] = sprintf('Tax API cost: %s ≈ %.2fs — often network-bound; cache where safe.', $this->shortName($row['name']), $row['sec']);
            }
            if (str_contains($row['name'], 'beforeCalculate') && $row['sec'] > 1.0) {
                $tips[] = sprintf(
                    'Totals beforeCalculate plugin: %s ≈ %.2fs — check for extra quote loads.',
                    $this->shortName($row['name']),
                    $row['sec']
                );
            }
            if (str_contains($row['name'], 'collectRates') && $row['sec'] > 0.5) {
                $tips[] = sprintf('Shipping rates: %s ≈ %.2fs — external carrier APIs are common here.', $this->shortName($row['name']), $row['sec']);
            }
        }

        if ($top !== []) {
            $hot = $top[0];
            if ($hot['name'] !== '{main}' && $hot['calls'] > 100) {
                $tips[] = sprintf(
                    'High call volume: %s ran %d times (%.2fs) — look for N+1 / loops.',
                    $this->shortName($hot['name']),
                    $hot['calls'],
                    $hot['sec']
                );
            }
        }

        if ($tips === []) {
            $tips[] = 'Click any function in the tables to see callers (where it came from) and callees (what it called).';
        }

        return array_values(array_unique($tips));
    }

    private function shortName(string $name): string
    {
        return strlen($name) > 80 ? substr($name, 0, 77) . '...' : $name;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'idToName' => $this->idToName,
            'incl' => $this->incl,
            'calls' => $this->calls,
            'callers' => $this->callers,
            'callees' => $this->callees,
            'fnToFile' => $this->fnToFile,
            'fnToLine' => $this->fnToLine,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $self = new self();
        $self->idToName = $data['idToName'] ?? [];
        $self->incl = $data['incl'] ?? [];
        $self->calls = $data['calls'] ?? [];
        $self->callers = $data['callers'] ?? [];
        $self->callees = $data['callees'] ?? [];
        $self->fnToFile = $data['fnToFile'] ?? [];
        $self->fnToLine = $data['fnToLine'] ?? [];
        // JSON decodes keys as strings — normalize to int
        $self->idToName = self::intKeyMap($self->idToName);
        $self->incl = self::intKeyMap($self->incl);
        $self->calls = self::intKeyMap($self->calls);
        $self->callers = self::intKeyNested($self->callers);
        $self->callees = self::intKeyNested($self->callees);
        $self->fnToFile = self::intKeyMap($self->fnToFile);
        $self->fnToLine = self::intKeyMap($self->fnToLine);
        return $self;
    }

    /** @param array<array-key, mixed> $map @return array<int, mixed> */
    private static function intKeyMap(array $map): array
    {
        $out = [];
        foreach ($map as $k => $v) {
            $out[(int) $k] = $v;
        }
        return $out;
    }

    /** @param array<array-key, mixed> $map @return array<int, array<int, mixed>> */
    private static function intKeyNested(array $map): array
    {
        $out = [];
        foreach ($map as $k => $inner) {
            $out[(int) $k] = is_array($inner) ? self::intKeyMap($inner) : [];
        }
        return $out;
    }
}
