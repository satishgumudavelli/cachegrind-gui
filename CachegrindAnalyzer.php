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

    public function selfTicks(int $fnId): int
    {
        $incl = $this->incl[$fnId] ?? 0;
        $child = 0;
        foreach ($this->callees[$fnId] ?? [] as $edge) {
            $child += (int) ($edge['ticks'] ?? 0);
        }
        return max(0, $incl - $child);
    }

    /**
     * @return list<array{id:int,name:string,sec:float,self_sec:float,calls:int,file:?string,line:?int,scope:string}>
     */
    public function topFunctions(float $minSec = 0.05, int $limit = 40, string $sortBy = 'incl'): array
    {
        $rows = [];
        foreach ($this->idToName as $id => $name) {
            $sec = self::ticksToSec($this->incl[$id] ?? 0);
            $selfSec = self::ticksToSec($this->selfTicks($id));
            $metric = $sortBy === 'self' ? $selfSec : $sec;
            if ($metric < $minSec) {
                continue;
            }
            $file = $this->resolveFile($id, $name);
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'sec' => $sec,
                'self_sec' => $selfSec,
                'calls' => $this->calls[$id] ?? 0,
                'file' => $file,
                'line' => $this->fnToLine[$id] ?? null,
                'scope' => self::classifyScope($file, $name),
            ];
        }
        usort($rows, static function ($a, $b) use ($sortBy) {
            $av = $sortBy === 'self' ? $a['self_sec'] : $a['sec'];
            $bv = $sortBy === 'self' ? $b['self_sec'] : $b['sec'];
            return $bv <=> $av;
        });
        return array_slice($rows, 0, $limit);
    }

    public static function classifyScope(?string $file, string $name): string
    {
        $hay = ($file ?? '') . ' ' . $name;
        if (str_contains($hay, '/vendor/') || str_starts_with($name, 'Composer\\') || str_starts_with($name, 'php::')) {
            if (str_starts_with($name, 'php::') || preg_match('#^php[:\s]#', $name)) {
                return 'php';
            }
            if (str_contains($hay, '/vendor/magento/') || str_contains($hay, '/lib/internal/Magento/')) {
                return 'framework';
            }
            return 'vendor';
        }
        if (str_contains($hay, '/generated/') || str_contains($hay, '/lib/internal/Magento/')) {
            return 'framework';
        }
        if (str_contains($hay, '/app/code/') || str_contains($hay, '/app/design/')) {
            return 'app';
        }
        if ($file && str_starts_with($file, '/') && !str_contains($file, '/vendor/')) {
            return 'app';
        }
        return 'other';
    }

    /**
     * Roll up inclusive time by Magento Vendor/Module (or top namespace segment).
     *
     * @return list<array{module:string,sec:float,self_sec:float,functions:int,scope:string}>
     */
    public function moduleRollup(float $minSec = 0.01, int $limit = 40): array
    {
        /** @var array<string, array{sec:float,self_sec:float,functions:int,scope:string}> $map */
        $map = [];
        foreach ($this->idToName as $id => $name) {
            $module = self::extractModule($name);
            if ($module === null) {
                continue;
            }
            $sec = self::ticksToSec($this->incl[$id] ?? 0);
            $selfSec = self::ticksToSec($this->selfTicks($id));
            if ($sec < 0.001 && $selfSec < 0.001) {
                continue;
            }
            if (!isset($map[$module])) {
                $file = $this->resolveFile($id, $name);
                $map[$module] = [
                    'sec' => 0.0,
                    'self_sec' => 0.0,
                    'functions' => 0,
                    'scope' => self::classifyScope($file, $name),
                ];
            }
            // Sum self-time (avoids double-counting inclusive stacks across modules)
            $map[$module]['self_sec'] += $selfSec;
            $map[$module]['sec'] = max($map[$module]['sec'], $sec);
            $map[$module]['functions']++;
        }
        $rows = [];
        foreach ($map as $module => $row) {
            if ($row['self_sec'] < $minSec && $row['sec'] < $minSec) {
                continue;
            }
            $rows[] = [
                'module' => $module,
                'sec' => $row['sec'],
                'self_sec' => $row['self_sec'],
                'functions' => $row['functions'],
                'scope' => $row['scope'],
            ];
        }
        usort($rows, static fn ($a, $b) => $b['self_sec'] <=> $a['self_sec']);
        return array_slice($rows, 0, $limit);
    }

    public static function extractModule(string $fnName): ?string
    {
        $name = ltrim($fnName, '\\');
        if (preg_match('#^([A-Za-z_][A-Za-z0-9_]*)\\\\([A-Za-z_][A-Za-z0-9_]*)(?:\\\\|->|::)#', $name, $m)) {
            return $m[1] . '\\' . $m[2];
        }
        return null;
    }

    /**
     * Classify Magento interception / plugin-related frames for "plugin tax" views.
     * Kinds: interceptor | plugin | plugin_list | other (null = not tax-related).
     */
    public static function classifyPluginKind(string $name): ?string
    {
        $n = ltrim($name, '\\');
        if (str_contains($n, '\\Interceptor->') || str_contains($n, '\\Interceptor::')
            || str_ends_with($n, '\\Interceptor') || preg_match('#Interceptor->__#', $n)) {
            return 'interceptor';
        }
        if (str_contains($n, 'PluginList') || str_contains($n, 'Interception\\PluginList')
            || str_contains($n, 'Interception\\Interceptor')
            || (str_contains($n, 'Definition\\Runtime') && str_contains($n, 'Interception'))) {
            return 'plugin_list';
        }
        // Magento plugin classes + before/around/after hooks
        if (preg_match('#\\\\Plugin\\\\#', $n) || preg_match('#\\\\Plugin->#', $n)
            || preg_match('#->(before|around|after)[A-Z]#', $n)
            || preg_match('#::(before|around|after)[A-Z]#', $n)) {
            return 'plugin';
        }
        return null;
    }

    /**
     * Roll up time spent in Magento Interceptors, Plugins, and PluginList plumbing.
     *
     * @return array{
     *   total_self_sec: float,
     *   total_sec: float,
     *   pct_of_main: float,
     *   kinds: list<array{kind:string,self_sec:float,sec:float,functions:int,calls:int}>,
     *   hotspots: list<array{id:int,name:string,kind:string,sec:float,self_sec:float,calls:int,file:?string,line:?int,scope:string,module:?string}>
     * }
     */
    public function pluginTax(float $minSec = 0.01, int $limit = 40): array
    {
        $kindAgg = [
            'interceptor' => ['kind' => 'interceptor', 'self_sec' => 0.0, 'sec' => 0.0, 'functions' => 0, 'calls' => 0],
            'plugin' => ['kind' => 'plugin', 'self_sec' => 0.0, 'sec' => 0.0, 'functions' => 0, 'calls' => 0],
            'plugin_list' => ['kind' => 'plugin_list', 'self_sec' => 0.0, 'sec' => 0.0, 'functions' => 0, 'calls' => 0],
        ];
        $hotspots = [];
        $totalSelf = 0.0;
        $totalInclPeak = 0.0;

        foreach ($this->idToName as $id => $name) {
            $kind = self::classifyPluginKind($name);
            if ($kind === null) {
                continue;
            }
            $sec = self::ticksToSec($this->incl[$id] ?? 0);
            $selfSec = self::ticksToSec($this->selfTicks($id));
            $calls = $this->calls[$id] ?? 0;
            if ($sec < 0.0005 && $selfSec < 0.0005) {
                continue;
            }
            $kindAgg[$kind]['self_sec'] += $selfSec;
            $kindAgg[$kind]['sec'] = max($kindAgg[$kind]['sec'], $sec);
            $kindAgg[$kind]['functions']++;
            $kindAgg[$kind]['calls'] += $calls;
            $totalSelf += $selfSec;
            $totalInclPeak = max($totalInclPeak, $sec);

            if ($selfSec >= $minSec || $sec >= $minSec) {
                $file = $this->resolveFile($id, $name);
                $hotspots[] = [
                    'id' => $id,
                    'name' => $name,
                    'kind' => $kind,
                    'sec' => $sec,
                    'self_sec' => $selfSec,
                    'calls' => $calls,
                    'file' => $file,
                    'line' => $this->fnToLine[$id] ?? null,
                    'scope' => self::classifyScope($file, $name),
                    'module' => self::extractModule($name),
                ];
            }
        }

        usort($hotspots, static fn ($a, $b) => $b['self_sec'] <=> $a['self_sec']);
        $kinds = array_values(array_filter(
            $kindAgg,
            static fn ($row) => $row['functions'] > 0
        ));
        usort($kinds, static fn ($a, $b) => $b['self_sec'] <=> $a['self_sec']);

        $main = $this->mainSec();
        return [
            'total_self_sec' => $totalSelf,
            'total_sec' => $totalInclPeak,
            'pct_of_main' => $main > 0 ? round(($totalSelf / $main) * 100, 1) : 0.0,
            'kinds' => $kinds,
            'hotspots' => array_slice($hotspots, 0, $limit),
        ];
    }

    /**
     * Build a trimmed call tree from {main} for flame / icicle charts.
     *
     * @return array{name:string,value:float,self:float,id:int,file:?string,line:?int,children:list}|null
     */
    public function flameTree(int $maxDepth = 8, int $maxChildren = 12, float $minSec = 0.02): ?array
    {
        $mainId = null;
        foreach ($this->idToName as $id => $name) {
            if ($name === '{main}') {
                $mainId = $id;
                break;
            }
        }
        if ($mainId === null) {
            // Fall back to hottest root-ish function
            $top = $this->topFunctions(0.0, 1);
            if ($top === []) {
                return null;
            }
            $mainId = $top[0]['id'];
        }
        return $this->buildFlameNode($mainId, $maxDepth, $maxChildren, $minSec, []);
    }

    /**
     * @param array<int, true> $seen
     * @return array{name:string,value:float,self:float,id:int,file:?string,line:?int,children:list}
     */
    private function buildFlameNode(int $fnId, int $depthLeft, int $maxChildren, float $minSec, array $seen): array
    {
        $name = $this->idToName[$fnId] ?? ('#' . $fnId);
        $value = self::ticksToSec($this->incl[$fnId] ?? 0);
        $self = self::ticksToSec($this->selfTicks($fnId));
        $file = $this->resolveFile($fnId, $name);
        $node = [
            'name' => $name,
            'value' => $value,
            'self' => $self,
            'id' => $fnId,
            'file' => $file,
            'line' => $this->fnToLine[$fnId] ?? null,
            'scope' => self::classifyScope($file, $name),
            'children' => [],
        ];
        if ($depthLeft <= 0 || isset($seen[$fnId])) {
            return $node;
        }
        $seen[$fnId] = true;
        $edges = $this->callees[$fnId] ?? [];
        uasort($edges, static fn ($a, $b) => $b['ticks'] <=> $a['ticks']);
        $i = 0;
        foreach ($edges as $childId => $edge) {
            if ($i++ >= $maxChildren) {
                break;
            }
            $childSec = self::ticksToSec((int) $edge['ticks']);
            if ($childSec < $minSec) {
                continue;
            }
            $node['children'][] = $this->buildFlameNode($childId, $depthLeft - 1, $maxChildren, $minSec, $seen);
        }
        return $node;
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

    /**
     * Profile-only HTTP / external I/O detection from the selected Cachegrind file.
     * No static URL/domain catalogs and no source-file scraping.
     *
     * Finds HTTP boundary functions present in the profile, then includes those
     * sinks and their direct callers. Service label comes from Vendor\Module in
     * the function name when present, otherwise from the client type in the name.
     *
     * @return array{services: list<array{service:string,sec:float,self_sec:float,calls:int,functions:int,pct:float}>, calls: list<array{id:int,name:string,service:string,sec:float,self_sec:float,calls:int,pct:float,file:?string,line:?int,scope:string,via:string}>}
     */
    public function thirdPartyApis(float $minSec = 0.02, int $limit = 60): array
    {
        $main = $this->mainSec();
        /** @var array<int, true> $sinkIds */
        $sinkIds = [];
        foreach ($this->idToName as $id => $name) {
            if ($name !== '' && self::isHttpBoundaryName($name)) {
                $sinkIds[$id] = true;
            }
        }

        /** @var array<int, array<string, mixed>> $byId */
        $byId = [];
        foreach ($sinkIds as $sinkId => $_) {
            $sinkName = $this->idToName[$sinkId] ?? '';
            $this->addApiCallRow($byId, $sinkId, $sinkName, 'http-boundary', $main, $minSec);

            foreach ($this->callers[$sinkId] ?? [] as $callerId => $edge) {
                $callerName = $this->idToName[(int) $callerId] ?? '';
                if ($callerName === '' || $callerName === '{main}') {
                    continue;
                }
                if (str_contains($callerName, '\\Interceptor->') || str_contains($callerName, '\\Proxy->')) {
                    if (!preg_match('/(restCall|request|send|getTax|collectRates|createTransaction)/i', $callerName)) {
                        continue;
                    }
                }
                $this->addApiCallRow(
                    $byId,
                    (int) $callerId,
                    $callerName,
                    'caller-of:' . self::shortName($sinkName),
                    $main,
                    $minSec
                );
            }
        }

        $calls = array_values($byId);
        usort($calls, static fn ($a, $b) => $b['sec'] <=> $a['sec']);
        $calls = array_slice($calls, 0, $limit);

        /** @var array<string, array{service:string,sec:float,self_sec:float,calls:int,functions:int}> $byService */
        $byService = [];
        foreach ($calls as $row) {
            $svc = (string) $row['service'];
            if (!isset($byService[$svc])) {
                $byService[$svc] = [
                    'service' => $svc,
                    'sec' => 0.0,
                    'self_sec' => 0.0,
                    'calls' => 0,
                    'functions' => 0,
                ];
            }
            $byService[$svc]['sec'] = max($byService[$svc]['sec'], (float) $row['sec']);
            $byService[$svc]['self_sec'] += (float) $row['self_sec'];
            $byService[$svc]['calls'] += (int) $row['calls'];
            $byService[$svc]['functions']++;
        }

        $services = [];
        foreach ($byService as $row) {
            $row['pct'] = $main > 0 ? ($row['sec'] / $main) * 100.0 : 0.0;
            $services[] = $row;
        }
        usort($services, static fn ($a, $b) => $b['sec'] <=> $a['sec']);

        return ['services' => $services, 'calls' => $calls];
    }

    /**
     * @param array<int, array<string, mixed>> $byId
     */
    private function addApiCallRow(array &$byId, int $id, string $name, string $via, float $main, float $minSec): void
    {
        if (isset($byId[$id])) {
            return;
        }
        $sec = self::ticksToSec($this->incl[$id] ?? 0);
        if ($sec < $minSec) {
            return;
        }
        $file = $this->resolveFile($id, $name);
        $byId[$id] = [
            'id' => $id,
            'name' => $name,
            'service' => self::serviceLabelFromProfileName($name),
            'sec' => $sec,
            'self_sec' => self::ticksToSec($this->selfTicks($id)),
            'calls' => $this->calls[$id] ?? 0,
            'pct' => $main > 0 ? ($sec / $main) * 100.0 : 0.0,
            'file' => $file,
            'line' => $this->fnToLine[$id] ?? null,
            'scope' => self::classifyScope($file, $name),
            'via' => $via,
        ];
    }

    /** True when this profile function name looks like an HTTP/network boundary. */
    public static function isHttpBoundaryName(string $fnName): bool
    {
        return (bool) preg_match(
            '/curl_exec|php::curl_|\\\\Curl->|Curl::|GuzzleHttp\\\\|Guzzle\\\\Http|restCall\b|SoapClient|stream_socket_client|fsockopen|Client->request\b|Client->send\b|Client->transfer\b/i',
            $fnName
        );
    }

    /** Derive a service label only from names present in the profile. */
    public static function serviceLabelFromProfileName(string $fnName): string
    {
        $mod = self::extractModule($fnName);
        if ($mod !== null) {
            return $mod;
        }
        if (preg_match('/GuzzleHttp/i', $fnName)) {
            return 'GuzzleHttp';
        }
        if (preg_match('/curl_exec|php::curl_|\\\\Curl->|Curl::/i', $fnName)) {
            return 'curl';
        }
        if (preg_match('/SoapClient/i', $fnName)) {
            return 'SoapClient';
        }
        if (preg_match('/stream_socket_client|fsockopen/i', $fnName)) {
            return 'socket';
        }
        return 'HTTP';
    }

    public static function matchApiService(string $fnName): ?string
    {
        if (self::isHttpBoundaryName($fnName)) {
            return self::serviceLabelFromProfileName($fnName);
        }
        return null;
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
            'self_sec' => self::ticksToSec($this->selfTicks($fnId)),
            'calls' => $this->calls[$fnId] ?? 0,
            'file' => $file,
            'line' => $this->fnToLine[$fnId] ?? null,
            'scope' => self::classifyScope($file, $name),
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

        $tax = $this->pluginTax(0.05, 5);
        if ($main > 0 && $tax['total_self_sec'] > 0.5 && $tax['pct_of_main'] >= 8) {
            $tips[] = sprintf(
                'Plugin/interceptor tax ≈ %.2fs self (%.1f%% of {main}) — review around-plugins and heavy Interceptor paths.',
                $tax['total_self_sec'],
                $tax['pct_of_main']
            );
        }

        if ($tips === []) {
            $tips[] = 'Click any function in the tables to see callers (where it came from) and callees (what it called).';
        }

        $apis = $this->thirdPartyApis(0.1, 20);
        if ($apis['services'] !== []) {
            $parts = [];
            foreach (array_slice($apis['services'], 0, 5) as $s) {
                $parts[] = sprintf('%s ≈ %.2fs (%.0f%%)', $s['service'], $s['sec'], $s['pct']);
            }
            array_unshift(
                $tips,
                'HTTP / network (from this profile): ' . implode('; ', $parts) . ' — see the 3rd-party APIs tab.'
            );
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
