<?php

namespace App\Services;

use App\Models\EsunPortfolioDailySnapshot;
use App\Models\TwStockCompanyProfile;
use App\Models\TwStockDailyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class EsunPortfolioService
{
    private const TWSE_MIS_URL = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';

    public function snapshot(bool $force = false): array
    {
        $now = CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'));
        $market = $this->marketStatus($now);
        $ttl = $this->cacheTtl($market);
        $cacheKey = 'esun:portfolio:inventories:v5';
        $fallbackKey = 'esun:portfolio:inventories:last-success:v5';
        $lastQueryKey = 'esun:portfolio:last-query-at:v5';
        $rateLimitedUntilKey = 'esun:portfolio:rate-limited-until:v5';

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && !$this->isFreshCachedSnapshot($cached, $now, $ttl)) {
            Cache::forget($cacheKey);
            $cached = null;
        }
        $queryAge = $this->secondsSince(Cache::get($lastQueryKey), $now);
        $minQuerySeconds = $this->minimumQuerySeconds();
        $fallback = fn (): ?array => $this->lastSuccessfulSnapshot($fallbackKey);
        $rateLimitRetryAfter = $this->secondsUntil(Cache::get($rateLimitedUntilKey), $now);

        if ($rateLimitRetryAfter !== null && $rateLimitRetryAfter > 0 && is_array($raw = $fallback())) {
            return $this->buildSnapshot($raw, $market, $now, $ttl, [
                'status' => 'throttled',
                'message' => '玉山庫存 API 暫時達到查詢頻率限制，先顯示最近一次成功資料。',
                'lastQueryAgeSeconds' => $queryAge,
                'retryAfterSeconds' => $rateLimitRetryAfter,
            ]);
        }

        if (is_array($cached) && (!$force || ($queryAge !== null && $queryAge < $minQuerySeconds))) {
            $meta = $force ? [
                'status' => 'throttled',
                'message' => '玉山庫存 API 有查詢頻率限制，先顯示最近一次成功資料。',
            ] : [
                'status' => 'cached',
                'message' => '顯示後端快取資料。',
            ];
            $meta['lastQueryAgeSeconds'] = $queryAge;

            return $this->buildSnapshot($cached, $market, $now, $ttl, $meta);
        }

        if ($queryAge !== null && $queryAge < $minQuerySeconds && is_array($raw = $fallback())) {
            return $this->buildSnapshot($raw, $market, $now, $ttl, [
                'status' => $force ? 'throttled' : 'cached',
                'message' => '玉山庫存 API 有查詢頻率限制，先顯示最近一次成功資料。',
                'lastQueryAgeSeconds' => $queryAge,
            ]);
        }

        try {
            Cache::put($lastQueryKey, $now->toIso8601String(), now()->addDay());
            $raw = $this->queryInventories($now, $fallback());
            Cache::put($cacheKey, $raw, now()->addSeconds($ttl));
            $this->storeSuccessfulSnapshot($fallbackKey, $raw);
            Cache::forget($rateLimitedUntilKey);

            return $this->buildSnapshot($raw, $market, $now, $ttl, [
                'status' => 'live',
                'message' => '玉山 API 查詢成功。',
                'lastQueryAgeSeconds' => 0,
            ]);
        } catch (RuntimeException $exception) {
            if ($this->isRateLimited($exception)) {
                Cache::put($rateLimitedUntilKey, $now->addSeconds(65)->toIso8601String(), now()->addMinutes(2));
            }

            $raw = $fallback();
            if (is_array($raw)) {
                return $this->buildSnapshot($raw, $market, $now, $ttl, [
                    'status' => 'stale',
                    'message' => $this->staleSnapshotMessage($exception),
                    'lastQueryAgeSeconds' => 0,
                    'retryAfterSeconds' => $this->isRateLimited($exception) ? 65 : null,
                ]);
            }

            throw $exception;
        }
    }

    private function isFreshCachedSnapshot(array $raw, CarbonImmutable $now, int $ttl): bool
    {
        $age = $this->secondsSince($raw['queriedAt'] ?? null, $now);

        return $age !== null && $age <= $ttl;
    }

    private function lastSuccessfulSnapshot(string $fallbackKey): ?array
    {
        $cached = Cache::get($fallbackKey);
        if (is_array($cached)) {
            return $cached;
        }

        $path = $this->persistentFallbackPath();
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        Cache::put($fallbackKey, $decoded, now()->addHours(8));

        return $decoded;
    }

    private function storeSuccessfulSnapshot(string $fallbackKey, array $raw): void
    {
        Cache::put($fallbackKey, $raw, now()->addHours(8));

        $path = $this->persistentFallbackPath();
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function persistentFallbackPath(): string
    {
        return storage_path('app/esun/portfolio-last-success-v5.json');
    }

    public function marketStatus(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'));
        $date = $now->toDateString();
        $start = CarbonImmutable::parse($date . ' ' . config('esun.market_open_start', '09:00'), $now->timezone);
        $end = CarbonImmutable::parse($date . ' ' . config('esun.market_open_end', '13:35'), $now->timezone);
        $isWeekday = $now->isWeekday();
        $isOpen = $isWeekday && $now->betweenIncluded($start, $end);

        return [
            'isOpen' => $isOpen,
            'isWeekday' => $isWeekday,
            'status' => $isOpen ? 'open' : 'closed',
            'label' => $isOpen ? '台股交易時段' : '非交易時段',
            'timezone' => (string) $now->timezone,
            'now' => $now->toIso8601String(),
            'openStart' => $start->format('H:i'),
            'openEnd' => $end->format('H:i'),
            'pollSeconds' => $isOpen ? max(2, (int) config('esun.poll_seconds_open', 2)) : null,
        ];
    }

    public function captureDailySnapshot(?CarbonImmutable $snapshotDate = null, bool $force = true): EsunPortfolioDailySnapshot
    {
        return $this->storeDailySnapshot($this->snapshot($force), $snapshotDate);
    }

    public function storeDailySnapshot(array $payload, ?CarbonImmutable $snapshotDate = null): EsunPortfolioDailySnapshot
    {
        $timezone = (string) config('esun.timezone', 'Asia/Taipei');
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $rows = is_array($payload['rows'] ?? null) ? array_values($payload['rows']) : [];
        $queriedAt = $this->parseDateTime($payload['queriedAt'] ?? null, $timezone);
        $capturedAt = $this->parseDateTime($payload['servedAt'] ?? null, $timezone)
            ?? CarbonImmutable::now($timezone);
        $date = $snapshotDate?->startOfDay()
            ?? ($queriedAt ?? $capturedAt)->setTimezone($timezone)->startOfDay();

        return EsunPortfolioDailySnapshot::query()->updateOrCreate(
            ['snapshot_date' => $date->toDateString()],
            [
                'captured_at' => $capturedAt,
                'queried_at' => $queriedAt,
                'source_status' => (string) ($source['status'] ?? ''),
                'source_message' => (string) ($source['message'] ?? ''),
                'source_age_seconds' => $this->integerOrNull($source['ageSeconds'] ?? null),
                'stock_count' => (int) round($this->number($summary['stockCount'] ?? count($rows))),
                'share_count' => $this->number($summary['shareCount'] ?? 0),
                'market_value' => $this->numberOrNull($summary['marketValue'] ?? null),
                'cost_basis' => $this->numberOrNull($summary['costBasis'] ?? null),
                'today_pnl' => $this->numberOrNull($summary['todayPnl'] ?? null),
                'unrealized_pnl' => $this->numberOrNull($summary['unrealizedPnl'] ?? null),
                'bank_balance' => $this->numberOrNull($summary['bankBalance'] ?? null),
                'margin_used_amount' => $this->numberOrNull($summary['marginUsedAmount'] ?? null),
                'margin_available_amount' => $this->numberOrNull($summary['marginAvailableAmount'] ?? null),
                'summary' => $summary,
                'rows' => $rows,
                'payload' => $payload,
            ],
        );
    }

    /**
     * @return array<int, array{date: string, capturedAt: string|null, todayPnl: float|null, unrealizedPnl: float|null}>
     */
    public function dailySnapshotDates(int $limit = 90): array
    {
        return EsunPortfolioDailySnapshot::query()
            ->orderByDesc('snapshot_date')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (EsunPortfolioDailySnapshot $snapshot): array => [
                'date' => $snapshot->snapshot_date?->toDateString() ?? '',
                'capturedAt' => $snapshot->captured_at?->toIso8601String(),
                'todayPnl' => $snapshot->today_pnl,
                'unrealizedPnl' => $snapshot->unrealized_pnl,
            ])
            ->filter(fn (array $row): bool => $row['date'] !== '')
            ->values()
            ->all();
    }

    public function dailySnapshotPayload(string $date): ?array
    {
        $snapshotDate = $this->parseSnapshotDate($date);
        $snapshot = EsunPortfolioDailySnapshot::query()
            ->where('snapshot_date', $snapshotDate->toDateString())
            ->first();
        if (!$snapshot instanceof EsunPortfolioDailySnapshot) {
            return null;
        }

        $timezone = (string) config('esun.timezone', 'Asia/Taipei');
        $payload = is_array($snapshot->payload) ? $snapshot->payload : [];
        $payload['summary'] = is_array($snapshot->summary) ? $snapshot->summary : [];
        $payload['rows'] = is_array($snapshot->rows) ? $snapshot->rows : [];
        $payload['queriedAt'] = $snapshot->queried_at?->toIso8601String() ?? ($payload['queriedAt'] ?? null);
        $payload['servedAt'] = CarbonImmutable::now($timezone)->toIso8601String();
        $payload['cacheSeconds'] = 86400;
        $payload['market'] = [
            'isOpen' => false,
            'isWeekday' => $snapshotDate->isWeekday(),
            'status' => 'historical',
            'label' => '歷史收盤快照',
            'timezone' => $timezone,
            'now' => CarbonImmutable::now($timezone)->toIso8601String(),
            'openStart' => (string) config('esun.market_open_start', '09:00'),
            'openEnd' => (string) config('esun.market_open_end', '13:35'),
            'pollSeconds' => null,
        ];
        $payload['source'] = [
            'status' => 'historical',
            'message' => '玉山收盤快照',
            'ageSeconds' => null,
            'originalStatus' => $snapshot->source_status,
            'capturedAt' => $snapshot->captured_at?->toIso8601String(),
        ];
        $payload['history'] = [
            'date' => $snapshotDate->toDateString(),
            'capturedAt' => $snapshot->captured_at?->toIso8601String(),
            'queriedAt' => $snapshot->queried_at?->toIso8601String(),
        ];

        return $payload;
    }

    private function queryInventories(?CarbonImmutable $now = null, ?array $previousRaw = null): array
    {
        if (!config('esun.portfolio_enabled')) {
            throw new RuntimeException('E.SUN portfolio query is disabled.');
        }

        $now ??= CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'));
        $today = $now->startOfDay();
        $payload = $this->queryPortfolioPayload($today);
        if (!isset($payload['inventories']) || !is_array($payload['inventories'])) {
            throw new RuntimeException('E.SUN query returned an unexpected payload.');
        }

        $todayHistory = array_values(array_filter(
            is_array($payload['today_transactions_history'] ?? null) ? $payload['today_transactions_history'] : [],
            fn (mixed $transaction): bool => is_array($transaction),
        ));
        $todayRangeTransactions = array_values(array_filter(
            is_array($payload['today_transactions'] ?? null) ? $payload['today_transactions'] : [],
            fn (mixed $transaction): bool => is_array($transaction),
        ));
        $todayTransactions = $this->todayRealizedTransactions($todayHistory, $todayRangeTransactions, $today);
        if ($todayTransactions === []) {
            $todayTransactions = $this->previousTodayTransactions($previousRaw, $today);
        }

        $warnings = is_array($payload['warnings'] ?? null) ? $payload['warnings'] : [];
        $settlements = is_array($payload['settlements'] ?? null) ? $payload['settlements'] : [];
        if ($settlements === [] && $this->hasWarning($warnings, 'settlements')) {
            $settlements = $this->previousSettlements($previousRaw);
        }

        try {
            $transactions = $this->historicalYearTransactions($now);
        } catch (\Throwable $exception) {
            $transactions = $this->previousHistoricalYearTransactions($previousRaw, $now);
            $warnings[] = [
                'label' => 'year_transactions',
                'error' => $this->redactSecrets($exception->getMessage()),
            ];
        }

        return [
            'queriedAt' => $payload['queried_at'] ?? now()->toIso8601String(),
            'inventories' => $payload['inventories'],
            'balance' => is_array($payload['balance'] ?? null) ? $payload['balance'] : [],
            'tradeStatus' => is_array($payload['trade_status'] ?? null) ? $payload['trade_status'] : [],
            'settlements' => $settlements,
            'transactions' => $transactions,
            'todayTransactions' => $todayTransactions,
            'todayTransactionsDate' => $today->toDateString(),
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryPortfolioPayload(CarbonImmutable $today): array
    {
        $daemonUrl = $this->daemonUrl();
        if ($daemonUrl !== null) {
            return $this->queryDaemon($daemonUrl . '/portfolio', [
                'today' => $today->toDateString(),
            ], 60);
        }

        return $this->runEsunScript((string) config('esun.query_script'), [
            ...$this->esunProcessEnvironment(),
            'ESUN_TODAY_DATE' => $today->toDateString(),
        ], 60);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function historicalYearTransactions(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $yearStart = $today->startOfYear();
        $historyEnd = $today->subDay();
        $transactions = [];

        $cursor = $yearStart;
        while ($cursor->lessThanOrEqualTo($historyEnd)) {
            $chunkEnd = $cursor->endOfMonth();
            if ($chunkEnd->greaterThan($historyEnd)) {
                $chunkEnd = $historyEnd;
            }

            $history = Cache::remember(
                sprintf(
                    'esun:portfolio:year-transactions:%s:%s:v2',
                    $cursor->format('Ymd'),
                    $chunkEnd->format('Ymd'),
                ),
                now()->addDays(max(1, (int) config('esun.year_transaction_cache_days', 10))),
                fn (): array => $this->queryTransactions($cursor, $chunkEnd),
            );

            if (is_array($history)) {
                $transactions = array_merge($transactions, $history);
            }

            $cursor = $chunkEnd->addDay()->startOfDay();
        }

        return array_values($transactions);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previousHistoricalYearTransactions(?array $previousRaw, CarbonImmutable $now): array
    {
        if (!is_array($previousRaw)) {
            return [];
        }

        $today = $now->startOfDay();
        $yearStart = $today->startOfYear();
        $historyEnd = $today->subDay();
        if ($historyEnd->lessThan($yearStart)) {
            return [];
        }

        $history = $this->filterHistoricalTransactions(
            is_array($previousRaw['transactions'] ?? null) ? $previousRaw['transactions'] : [],
            $yearStart,
            $historyEnd,
            true,
        );
        $previousToday = $this->filterHistoricalTransactions(
            is_array($previousRaw['todayTransactions'] ?? null) ? $previousRaw['todayTransactions'] : [],
            $yearStart,
            $historyEnd,
            false,
        );

        return $this->mergeAdditionalTransactions($history, $previousToday);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previousSettlements(?array $previousRaw): array
    {
        if (!is_array($previousRaw)) {
            return [];
        }

        return array_values(array_filter(
            is_array($previousRaw['settlements'] ?? null) ? $previousRaw['settlements'] : [],
            fn (mixed $settlement): bool => is_array($settlement),
        ));
    }

    /**
     * @param array<int, mixed> $warnings
     */
    private function hasWarning(array $warnings, string $label): bool
    {
        foreach ($warnings as $warning) {
            if (is_array($warning) && ($warning['label'] ?? null) === $label) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, mixed> $transactions
     * @return array<int, array<string, mixed>>
     */
    private function filterHistoricalTransactions(
        array $transactions,
        CarbonImmutable $yearStart,
        CarbonImmutable $historyEnd,
        bool $allowUnknownDate,
    ): array {
        return array_values(array_filter(
            $transactions,
            function (mixed $transaction) use ($yearStart, $historyEnd, $allowUnknownDate): bool {
                if (!is_array($transaction)) {
                    return false;
                }

                $date = $this->transactionDate($transaction, $historyEnd);
                if ($date === null) {
                    return $allowUnknownDate;
                }

                $date = $date->startOfDay();

                return $date->betweenIncluded($yearStart, $historyEnd);
            },
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryTransactions(CarbonImmutable $start, CarbonImmutable $end, ?string $range = null): array
    {
        if ($end->lessThan($start)) {
            return [];
        }

        $payload = $this->queryTransactionsPayload($start, $end, $range);
        if (!isset($payload['transactions']) || !is_array($payload['transactions'])) {
            throw new RuntimeException('E.SUN transactions query returned an unexpected payload.');
        }

        return $payload['transactions'];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryTransactionsPayload(CarbonImmutable $start, CarbonImmutable $end, ?string $range): array
    {
        $daemonUrl = $this->daemonUrl();
        if ($daemonUrl !== null) {
            $query = [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
            ];
            if ($range !== null && trim($range) !== '') {
                $query['range'] = $range;
            }

            return $this->queryDaemon($daemonUrl . '/transactions', $query);
        }

        return $this->runEsunScript((string) config('esun.transaction_script'), [
            ...$this->esunProcessEnvironment(),
            'ESUN_TRANSACTIONS_START' => $start->toDateString(),
            'ESUN_TRANSACTIONS_END' => $end->toDateString(),
            'ESUN_TRANSACTIONS_RANGE' => $range ?? '',
        ]);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function queryDaemon(string $url, array $query, int $timeout = 35): array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(max(1, (int) config('esun.daemon_timeout_seconds', $timeout)))
                ->get($url, $query);
        } catch (\Throwable $exception) {
            throw new RuntimeException(
                'E.SUN daemon query failed: ' . $this->redactSecrets($exception->getMessage()),
                0,
                $exception,
            );
        }

        $payload = $response->json();
        if (!$response->successful()) {
            $error = is_array($payload)
                ? (string) ($payload['error'] ?? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                : $response->body();

            throw new RuntimeException('E.SUN daemon query failed: ' . $this->redactSecrets($error));
        }

        if (!is_array($payload)) {
            throw new RuntimeException('E.SUN daemon query returned an unexpected payload.');
        }

        return $payload;
    }

    private function daemonUrl(): ?string
    {
        $url = rtrim(trim((string) config('esun.daemon_url', '')), '/');

        return $url === '' ? null : $url;
    }

    /**
     * @return array<string, mixed>
     */
    private function esunProcessEnvironment(): array
    {
        $env = [
            'ESUN_API_ENTRY' => config('esun.entry'),
            'ESUN_ACCOUNT' => config('esun.account'),
            'ESUN_API_KEY' => config('esun.api_key'),
            'ESUN_API_SECRET' => config('esun.api_secret'),
            'ESUN_CERT_PATH' => config('esun.cert_path'),
            'ESUN_ACCOUNT_PASSWORD' => config('esun.account_password'),
            'ESUN_CERT_PASSWORD' => config('esun.cert_password'),
        ];

        foreach ($env as $name => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException($name . ' is not configured.');
            }
        }

        return $env;
    }

    /**
     * @param array<string, mixed> $env
     * @return array<string, mixed>
     */
    private function runEsunScript(string $script, array $env, int $timeout = 35): array
    {
        if (!is_file($script)) {
            throw new RuntimeException('E.SUN query script is missing.');
        }

        $process = new Process([
            (string) config('esun.python_bin', 'python'),
            $script,
        ], base_path(), $env);
        $process->setTimeout($timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Unknown E.SUN query failure.';
            throw new RuntimeException($this->redactSecrets($error));
        }

        $payload = $this->decodeProcessJson($process->getOutput());
        if (!is_array($payload)) {
            throw new RuntimeException('E.SUN query returned an unexpected payload.');
        }

        return $payload;
    }

    private function buildSnapshot(array $raw, array $market, CarbonImmutable $now, int $ttl, array $source = []): array
    {
        $inventories = collect($raw['inventories'] ?? []);
        $stockCodes = $inventories
            ->map(fn (array $row): string => (string) $this->value($row, 'stk_no', 'stkNo'))
            ->filter()
            ->unique()
            ->values();
        $history = $this->historicalPrices($stockCodes);
        $exchanges = $this->exchangeMetadata($stockCodes);

        $rows = $inventories
            ->map(function (array $row) use ($history, $exchanges): array {
                $stockNo = (string) $this->value($row, 'stk_no', 'stkNo');

                return $this->formatInventoryRow($row, $history[$stockNo] ?? [], $exchanges[$stockNo] ?? []);
            })
            ->sortBy('stockNo')
            ->values()
            ->all();

        $totalMarketValue = array_sum(array_column($rows, 'marketValue'));
        $totalCostBasis = array_sum(array_column($rows, 'costBasis'));
        $totalUnrealizedPnl = array_sum(array_column($rows, 'unrealizedPnl'));
        $totalTodayPnl = array_sum(array_column($rows, 'todayPnl'));
        $marginSummary = $this->marginSummary($rows, $raw);
        $balanceSummary = $this->balanceSummary($raw, $totalCostBasis);
        $yearProfitSummary = $this->yearProfitSummary($raw);
        $totalCapital = $balanceSummary['bankBalance'] === null ? null : $totalCostBasis + $balanceSummary['bankBalance'];
        $yearReturnBase = $totalCapital === null ? null : $totalCapital - $yearProfitSummary['yearTotalPnl'];
        $yearTotalPnlRate = $yearReturnBase !== null && $yearReturnBase > 0
            ? $yearProfitSummary['yearTotalPnl'] / $yearReturnBase * 100
            : null;
        $yearElapsedDays = max(1, (int) $now->dayOfYear);
        $previousMarketValue = array_sum(array_map(
            fn (array $row): float => $row['previousClose'] === null ? 0.0 : $row['previousClose'] * $row['quantity'],
            $rows,
        ));

        $rows = array_map(function (array $row) use ($totalMarketValue): array {
            $row['marketWeight'] = $totalMarketValue > 0 ? $row['marketValue'] / $totalMarketValue * 100 : null;

            return $row;
        }, $rows);

        $snapshotAgeSeconds = $this->secondsSince($raw['queriedAt'] ?? null, $now);

        return [
            'queriedAt' => $raw['queriedAt'] ?? $now->toIso8601String(),
            'servedAt' => $now->toIso8601String(),
            'cacheSeconds' => $ttl,
            'market' => $market,
            'source' => [
                'status' => $source['status'] ?? 'cached',
                'message' => $source['message'] ?? '',
                'ageSeconds' => $snapshotAgeSeconds,
                'snapshotAgeSeconds' => $snapshotAgeSeconds,
                'lastQueryAgeSeconds' => $source['lastQueryAgeSeconds'] ?? null,
                'retryAfterSeconds' => $source['retryAfterSeconds'] ?? null,
                'minQuerySeconds' => $this->minimumQuerySeconds(),
                'inventoryFresh' => $snapshotAgeSeconds !== null && $snapshotAgeSeconds <= $this->minimumQuerySeconds(),
            ],
            'summary' => [
                'stockCount' => count($rows),
                'lotCount' => array_sum(array_column($rows, 'lotCount')),
                'shareCount' => array_sum(array_column($rows, 'quantity')),
                'marketValue' => $totalMarketValue,
                'costBasis' => $totalCostBasis,
                'availableBalance' => $balanceSummary['availableBalance'],
                'dayTradeOffsetAmount' => $balanceSummary['dayTradeOffsetAmount'],
                'pendingSettlementAmount' => $balanceSummary['pendingSettlementAmount'],
                'bankBalance' => $balanceSummary['bankBalance'],
                'investmentLevelRate' => $balanceSummary['investmentLevelRate'],
                'marginLimitAmount' => $marginSummary['limitAmount'],
                'marginUsedAmount' => $marginSummary['usedAmount'],
                'marginAvailableAmount' => $marginSummary['availableAmount'],
                'marginMaintenanceRate' => $marginSummary['maintenanceRate'],
                'todayPnl' => $totalTodayPnl,
                'esunTodayPnl' => $totalTodayPnl,
                'todayPnlRate' => $previousMarketValue > 0 ? $totalTodayPnl / $previousMarketValue * 100 : null,
                'unrealizedPnl' => $totalUnrealizedPnl,
                'unrealizedPnlRate' => $totalCostBasis > 0 ? $totalUnrealizedPnl / $totalCostBasis * 100 : null,
                'realizedHistoryPnl' => $yearProfitSummary['realizedHistoryPnl'],
                'realizedTodayPnl' => $yearProfitSummary['realizedTodayPnl'],
                'realizedYearPnl' => $yearProfitSummary['realizedYearPnl'],
                'dayTradeYearPnl' => $yearProfitSummary['dayTradeYearPnl'],
                'adjustedRealizedYearPnl' => $yearProfitSummary['adjustedRealizedYearPnl'],
                'yearTotalPnl' => $yearProfitSummary['yearTotalPnl'],
                'yearReturnBase' => $yearReturnBase,
                'yearTotalPnlRate' => $yearTotalPnlRate,
                'yearElapsedDays' => $yearElapsedDays,
                'annualizedReturnRate' => $this->annualizedReturnRate($yearTotalPnlRate, $yearElapsedDays),
            ],
            'rows' => $rows,
        ];
    }

    private function formatInventoryRow(array $row, array $history, array $exchange = []): array
    {
        $stockNo = (string) $this->value($row, 'stk_no', 'stkNo');
        $quantity = $this->number($this->value($row, 'cost_qty', 'costQty', 'qty_b', 'qtyB'));
        $currentPrice = $this->number($this->value($row, 'price_mkt', 'priceMkt', 'price_now', 'priceNow'));
        $marketValue = $this->number($this->value($row, 'value_mkt', 'valueMkt', 'value_now', 'valueNow'));
        $unrealizedPnl = $this->number($this->value($row, 'make_a_sum', 'makeASum'));
        $apiReturnRate = $this->numberOrNull($this->value($row, 'make_a_per', 'makeAPer'));
        $signedCostBasis = $this->number($this->value($row, 'cost_sum', 'costSum'));
        $cashCostBasis = abs($signedCostBasis);
        $priceAmount = abs($this->number($this->value($row, 'price_qty_sum', 'priceQtySum')));
        $costBasis = $cashCostBasis;
        if ($costBasis <= 0) {
            $costBasis = $priceAmount;
        }
        if ($costBasis <= 0 && $quantity > 0) {
            $costBasis = abs($this->number($this->value($row, 'price_evn', 'priceEvn')) * $quantity);
        }

        $previousClose = $history['previousClose'] ?? null;
        $todayPnl = $previousClose === null ? null : ($currentPrice - $previousClose) * $quantity;
        $todayPnlRate = $previousClose > 0 ? ($currentPrice - $previousClose) / $previousClose * 100 : null;

        $lots = collect($this->value($row, 'stk_dats', 'stkDats') ?? [])
            ->map(fn (array $lot): array => $this->formatLot($stockNo, (string) $this->value($row, 'stk_na', 'stkNa'), $lot))
            ->values()
            ->all();

        $tradeType = (string) $this->value($row, 'trade');

        return [
            'stockNo' => $stockNo,
            'stockName' => (string) $this->value($row, 'stk_na', 'stkNa'),
            'quantity' => $quantity,
            'currentPrice' => $currentPrice,
            'previousClose' => $previousClose,
            'dayChange' => $previousClose === null ? null : $currentPrice - $previousClose,
            'dayChangeRate' => $todayPnlRate,
            'todayPnl' => $todayPnl,
            'esunTodayPnl' => $todayPnl,
            'averagePrice' => $this->number($this->value($row, 'price_avg', 'priceAvg')),
            'breakevenPrice' => $this->number($this->value($row, 'price_evn', 'priceEvn')),
            'priceAmount' => $priceAmount,
            'marketValue' => $marketValue,
            'esunCurrentPrice' => $currentPrice,
            'esunMarketValue' => $marketValue,
            'costBasis' => $costBasis,
            'signedCostBasis' => $signedCostBasis,
            'cashCostBasis' => $cashCostBasis,
            'unrealizedPnl' => $unrealizedPnl,
            'esunUnrealizedPnl' => $unrealizedPnl,
            'realtimePnlBasePrice' => $currentPrice,
            'unrealizedPnlRate' => $apiReturnRate ?? ($costBasis > 0 ? $unrealizedPnl / $costBasis * 100 : null),
            'fiveDayReturn' => $history['fiveDayReturn'] ?? null,
            'twentyDayReturn' => $history['twentyDayReturn'] ?? null,
            'sixtyDayReturn' => $history['sixtyDayReturn'] ?? null,
            'yearToDateReturn' => $history['yearToDateReturn'] ?? null,
            'tradeType' => $tradeType,
            'tradeTypeLabel' => $this->tradeTypeLabel($tradeType),
            'exchange' => $exchange['exchange'] ?? null,
            'exchangeLabel' => $exchange['label'] ?? null,
            'exchangeShortLabel' => $exchange['shortLabel'] ?? null,
            'exchangeClass' => $exchange['class'] ?? null,
            'positionType' => (string) $this->value($row, 's_type', 'sType'),
            'lotCount' => count($lots),
            'lots' => $lots,
            'raw' => [
                'qtyBuy' => $this->number($this->value($row, 'qty_b', 'qtyB')),
                'qtySell' => $this->number($this->value($row, 'qty_s', 'qtyS')),
                'apiReturnRate' => $apiReturnRate,
                'marginLoan' => $tradeType === '3' ? max(0.0, $priceAmount - $cashCostBasis) : 0.0,
            ],
        ];
    }

    private function marginSummary(array $rows, array $raw): array
    {
        $usedAmount = array_sum(array_map(
            fn (array $row): float => $row['tradeType'] === '3'
                ? max(0.0, $this->number($row['priceAmount'] ?? 0) - $this->number($row['cashCostBasis'] ?? $row['costBasis'] ?? 0))
                : 0.0,
            $rows,
        ));
        $marketValue = array_sum(array_map(
            fn (array $row): float => $row['tradeType'] === '3'
                ? $this->number($row['marketValue'] ?? 0)
                : 0.0,
            $rows,
        ));
        $configuredLimitAmount = $this->configuredMarginLimitAmount();
        $reportedLimitAmount = $this->reportedMarginLimitAmount($raw);
        $reportedAvailableAmount = $this->reportedMarginAvailableAmount($raw);
        $limitAmount = $reportedLimitAmount ?? $configuredLimitAmount ?? ($reportedAvailableAmount === null ? null : $usedAmount + $reportedAvailableAmount);
        $availableAmount = $limitAmount === null
            ? $reportedAvailableAmount
            : max(0.0, $limitAmount - $usedAmount);

        return [
            'limitAmount' => $limitAmount,
            'usedAmount' => $usedAmount,
            'availableAmount' => $availableAmount,
            'maintenanceRate' => $usedAmount > 0 ? $marketValue / $usedAmount * 100 : null,
        ];
    }

    private function configuredMarginLimitAmount(): ?float
    {
        $amount = $this->numberOrNull(config('esun.margin_limit_amount'));

        return $amount !== null && $amount > 0 ? $amount : null;
    }

    private function reportedMarginLimitAmount(array $raw): ?float
    {
        $tradeStatus = is_array($raw['tradeStatus'] ?? null)
            ? $raw['tradeStatus']
            : (is_array($raw['trade_status'] ?? null) ? $raw['trade_status'] : []);
        $amount = $this->numberOrNull($this->value(
            $tradeStatus,
            'margin_limit',
            'marginLimit',
            'margin_limit_amount',
            'marginLimitAmount',
        ));

        return $amount !== null && $amount > 0 ? $amount : null;
    }

    private function reportedMarginAvailableAmount(array $raw): ?float
    {
        $balance = is_array($raw['balance'] ?? null) ? $raw['balance'] : [];

        return $this->numberOrNull($this->value(
            $balance,
            'margin_available_amount',
            'marginAvailableAmount',
            'margin_available_balance',
            'marginAvailableBalance',
            'credit_available_amount',
            'creditAvailableAmount',
            'available_margin',
            'availableMargin',
        ));
    }

    private function formatLot(string $stockNo, string $stockName, array $lot): array
    {
        $marketValue = $this->number($this->value($lot, 'value_now', 'valueNow', 'value_mkt', 'valueMkt'));
        $unrealizedPnl = $this->number($this->value($lot, 'make_a', 'makeA'));

        return [
            'stockNo' => $stockNo,
            'stockName' => $stockName,
            'date' => (string) $this->value($lot, 't_date', 'tDate'),
            'time' => (string) $this->value($lot, 't_time', 'tTime'),
            'side' => (string) $this->value($lot, 'buy_sell', 'buySell'),
            'quantity' => $this->number($this->value($lot, 'qty')),
            'price' => $this->number($this->value($lot, 'price')),
            'breakevenPrice' => $this->number($this->value($lot, 'price_evn', 'priceEvn')),
            'fee' => $this->number($this->value($lot, 'fee')),
            'tax' => $this->number($this->value($lot, 'tax')),
            'payAmount' => $this->number($this->value($lot, 'pay_n', 'payN')),
            'marketValue' => $marketValue,
            'unrealizedPnl' => $unrealizedPnl,
            'unrealizedPnlRate' => $this->number($this->value($lot, 'make_a_per', 'makeAPer')),
            'costBasis' => $marketValue - $unrealizedPnl,
            'orderNo' => (string) $this->value($lot, 'ord_no', 'ordNo'),
        ];
    }

    private function tradeTypeLabel(string $tradeType): string
    {
        return match ($tradeType) {
            '0' => '現股',
            '3' => '融資',
            '4' => '融券',
            '9' => '當沖',
            'A' => '沖賣',
            default => $tradeType !== '' ? $tradeType : '--',
        };
    }

    /**
     * @param Collection<int, string> $stockCodes
     * @return array<string, array{exchange: string, label: string, shortLabel: string, class: string}>
     */
    private function exchangeMetadata(Collection $stockCodes): array
    {
        if ($stockCodes->isEmpty()) {
            return [];
        }

        $codes = $stockCodes->unique()->values();
        $metadata = TwStockCompanyProfile::query()
            ->whereIn('stock_code', $codes->all())
            ->get(['stock_code', 'exchange'])
            ->mapWithKeys(function (TwStockCompanyProfile $profile): array {
                $meta = $this->exchangeMeta((string) $profile->exchange);

                return $meta === null ? [] : [(string) $profile->stock_code => $meta];
            })
            ->all();

        $missingCodes = $codes->reject(fn (string $code): bool => isset($metadata[$code]))->values();
        if ($missingCodes->isNotEmpty()) {
            TwStockDailyPrice::query()
                ->whereIn('stock_code', $missingCodes->all())
                ->orderBy('stock_code')
                ->orderByDesc('trade_date')
                ->get(['stock_code', 'exchange'])
                ->unique('stock_code')
                ->each(function (TwStockDailyPrice $price) use (&$metadata): void {
                    $meta = $this->exchangeMeta((string) $price->exchange);
                    if ($meta !== null) {
                        $metadata[(string) $price->stock_code] = $meta;
                    }
                });
        }

        foreach ($codes as $code) {
            if (isset($metadata[$code])) {
                continue;
            }

            $inferred = $this->inferExchange($code);
            if ($inferred === null) {
                continue;
            }

            $meta = $this->exchangeMeta($inferred);
            if ($meta !== null) {
                $metadata[$code] = $meta;
            }
        }

        return $metadata;
    }

    /**
     * @return array{exchange: string, label: string, shortLabel: string, class: string}|null
     */
    private function exchangeMeta(?string $exchange): ?array
    {
        return match (strtoupper(trim((string) $exchange))) {
            'TWSE', 'SII', 'TSE', 'TAI', '上市' => [
                'exchange' => 'TWSE',
                'label' => '上市',
                'shortLabel' => '市',
                'class' => 'twse',
            ],
            'TPEX', 'OTC', 'TWO', '上櫃' => [
                'exchange' => 'TPEx',
                'label' => '上櫃',
                'shortLabel' => '櫃',
                'class' => 'tpex',
            ],
            'EMERGING', 'ESB', '興櫃' => [
                'exchange' => 'Emerging',
                'label' => '興櫃',
                'shortLabel' => '興',
                'class' => 'emerging',
            ],
            default => null,
        };
    }

    private function inferExchange(string $stockCode): ?string
    {
        return preg_match('/^00[0-9A-Z]+$/i', $stockCode) === 1 ? 'TWSE' : null;
    }

    /**
     * @return array{
     *     availableBalance: float|null,
     *     dayTradeOffsetAmount: float,
     *     pendingSettlementAmount: float,
     *     bankBalance: float|null,
     *     investmentLevelRate: float|null
     * }
     */
    private function balanceSummary(array $raw, float $totalCostBasis, ?CarbonImmutable $now = null): array
    {
        $balance = is_array($raw['balance'] ?? null) ? $raw['balance'] : [];
        $availableBalance = $this->numberOrNull($this->value($balance, 'available_balance', 'availableBalance'));
        $dayTradeOffsetAmount = abs($this->number($this->value($balance, 'exchange_balance', 'exchangeBalance')));
        $pendingSettlementAmount = $this->pendingSettlementAmount($raw, $now);
        $bankBalance = $availableBalance === null
            ? null
            : $availableBalance - $dayTradeOffsetAmount + $pendingSettlementAmount;
        $totalCapital = $bankBalance === null ? null : $totalCostBasis + $bankBalance;

        return [
            'availableBalance' => $availableBalance,
            'dayTradeOffsetAmount' => $dayTradeOffsetAmount,
            'pendingSettlementAmount' => $pendingSettlementAmount,
            'bankBalance' => $bankBalance,
            'investmentLevelRate' => $totalCapital !== null && $totalCapital > 0
                ? $totalCostBasis / $totalCapital * 100
                : null,
        ];
    }

    private function pendingSettlementAmount(array $raw, ?CarbonImmutable $now = null): float
    {
        $settlements = is_array($raw['settlements'] ?? null) ? $raw['settlements'] : [];
        $today = ($now ?? CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei')))->startOfDay();

        return collect($settlements)
            ->filter(fn (mixed $settlement): bool => is_array($settlement))
            ->filter(fn (array $settlement): bool => $this->isFutureSettlement($settlement, $today))
            ->sum(fn (array $settlement): float => $this->number($this->value(
                $settlement,
                'price',
                'amount',
                'settlement_amount',
                'settlementAmount',
            )));
    }

    private function isFutureSettlement(array $settlement, CarbonImmutable $today): bool
    {
        $rawDate = $this->value($settlement, 'c_date', 'cDate', 'settlement_date', 'settlementDate');
        if ($rawDate === null || trim((string) $rawDate) === '') {
            return true;
        }

        try {
            $date = $this->parseEsunDate($rawDate, $today);
            if ($date === null) {
                return true;
            }

            return $date->startOfDay()->greaterThan($today);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $todayHistory
     * @param array<int, array<string, mixed>> $todayRangeTransactions
     * @return array<int, array<string, mixed>>
     */
    private function todayRealizedTransactions(
        array $todayHistory,
        array $todayRangeTransactions,
        CarbonImmutable $today,
    ): array {
        $history = array_values(array_filter(
            $todayHistory,
            fn (array $transaction): bool => $this->transactionOccursOn($transaction, $today),
        ));

        if ($history !== []) {
            return $history;
        }

        return array_values(array_filter(
            $todayRangeTransactions,
            fn (array $transaction): bool => $this->transactionOccursOn($transaction, $today),
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function previousTodayTransactions(?array $previousRaw, CarbonImmutable $today): array
    {
        if (!is_array($previousRaw)) {
            return [];
        }

        $todayTransactions = array_values(array_filter(
            is_array($previousRaw['todayTransactions'] ?? null) ? $previousRaw['todayTransactions'] : [],
            fn (mixed $transaction): bool => is_array($transaction)
                && $this->transactionOccursOn($transaction, $today),
        ));
        if ($todayTransactions !== []) {
            return $todayTransactions;
        }

        return array_values(array_filter(
            is_array($previousRaw['transactions'] ?? null) ? $previousRaw['transactions'] : [],
            fn (mixed $transaction): bool => is_array($transaction)
                && $this->transactionOccursOn($transaction, $today),
        ));
    }

    private function transactionOccursOn(array $transaction, CarbonImmutable $date): bool
    {
        foreach ([
            ['t_date', 'tDate', 'trade_date', 'tradeDate', 'TradeDate', 'date', 'Date'],
            ['c_date', 'cDate', 'settlement_date', 'settlementDate', 'SettlementDate'],
        ] as $keys) {
            $matches = $this->transactionDateMatches($transaction, $keys, $date);
            if ($matches !== null) {
                return $matches;
            }
        }

        return false;
    }

    private function transactionDate(array $transaction, CarbonImmutable $reference): ?CarbonImmutable
    {
        foreach ([
            ['t_date', 'tDate', 'trade_date', 'tradeDate', 'TradeDate', 'date', 'Date'],
            ['c_date', 'cDate', 'settlement_date', 'settlementDate', 'SettlementDate'],
        ] as $keys) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $transaction) || $transaction[$key] === null) {
                    continue;
                }

                $rawDate = trim((string) $transaction[$key]);
                if ($rawDate === '') {
                    continue;
                }

                try {
                    return $this->parseEsunDate($rawDate, $reference);
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $keys
     */
    private function transactionDateMatches(array $transaction, array $keys, CarbonImmutable $date): ?bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $transaction) || $transaction[$key] === null) {
                continue;
            }

            $rawDate = trim((string) $transaction[$key]);
            if ($rawDate === '') {
                continue;
            }

            try {
                return $this->parseEsunDate($rawDate, $date)?->isSameDay($date) ?? false;
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function parseEsunDate(mixed $rawDate, CarbonImmutable $reference): ?CarbonImmutable
    {
        $dateText = trim((string) $rawDate);
        if ($dateText === '') {
            return null;
        }

        $date = preg_match('/^\d{8}$/', $dateText) === 1
            ? CarbonImmutable::createFromFormat('Ymd', $dateText, $reference->timezone)
            : CarbonImmutable::parse($dateText, $reference->timezone);

        return $date instanceof CarbonImmutable ? $date : null;
    }

    /**
     * @return array{
     *     realizedHistoryPnl: float,
     *     realizedTodayPnl: float,
     *     realizedYearPnl: float,
     *     dayTradeYearPnl: float,
     *     adjustedRealizedYearPnl: float,
     *     yearTotalPnl: float
     * }
     */
    private function yearProfitSummary(array $raw): array
    {
        $historyTransactions = is_array($raw['transactions'] ?? null) ? $raw['transactions'] : [];
        $todayTransactions = is_array($raw['todayTransactions'] ?? null) ? $raw['todayTransactions'] : [];
        $allTransactions = $this->mergeAdditionalTransactions($historyTransactions, $todayTransactions);
        $realizedHistoryPnl = $this->sumTransactionProfit($historyTransactions);
        $realizedTodayPnl = $this->sumTransactionProfit($todayTransactions);
        $realizedYearPnl = $this->sumTransactionProfit($allTransactions);
        $dayTradeYearPnl = 0.0;

        foreach ($allTransactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $make = $this->number($this->value($transaction, 'make', 'profit_loss', 'profitLoss'));

            if (in_array((string) $this->value($transaction, 'trade'), ['A', '9'], true)) {
                $dayTradeYearPnl += $make;
            }
        }

        $adjustedRealizedYearPnl = $realizedYearPnl - $dayTradeYearPnl;

        return [
            'realizedHistoryPnl' => $realizedHistoryPnl,
            'realizedTodayPnl' => $realizedTodayPnl,
            'realizedYearPnl' => $realizedYearPnl,
            'dayTradeYearPnl' => $dayTradeYearPnl,
            'adjustedRealizedYearPnl' => $adjustedRealizedYearPnl,
            'yearTotalPnl' => $realizedYearPnl,
        ];
    }

    /**
     * @param array<int, mixed> $transactions
     * @param array<int, mixed> $additionalTransactions
     * @return array<int, array<string, mixed>>
     */
    private function mergeAdditionalTransactions(array $transactions, array $additionalTransactions): array
    {
        $merged = [];
        $seen = [];

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $seen[$this->transactionIdentity($transaction)] = true;
            $merged[] = $transaction;
        }

        foreach ($additionalTransactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $identity = $this->transactionIdentity($transaction);
            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $merged[] = $transaction;
        }

        return $merged;
    }

    private function transactionIdentity(array $transaction): string
    {
        $fields = [
            $this->firstFilledTransactionValue($transaction, ['t_date', 'tDate', 'trade_date', 'tradeDate', 'TradeDate', 'date', 'Date']),
            $this->firstFilledTransactionValue($transaction, ['c_date', 'cDate', 'settlement_date', 'settlementDate', 'SettlementDate']),
            $this->firstFilledTransactionValue($transaction, ['stk_no', 'stkNo', 'stock_no', 'stockNo', 'StockNo']),
            $this->firstFilledTransactionValue($transaction, ['stk_na', 'stkNa', 'stock_name', 'stockName', 'StockName']),
            $this->firstFilledTransactionValue($transaction, ['trade', 'Trade']),
            $this->firstFilledTransactionValue($transaction, ['qty', 'quantity', 'Quantity', 'cost_qty', 'costQty']),
            $this->firstFilledTransactionValue($transaction, ['price', 'Price', 'price_avg', 'priceAvg']),
            $this->firstFilledTransactionValue($transaction, ['price_qty_sum', 'priceQtySum', 'amount', 'Amount']),
            $this->firstFilledTransactionValue($transaction, ['make', 'profit_loss', 'profitLoss', 'ProfitLoss']),
            $this->firstFilledTransactionValue($transaction, ['ord_no', 'ordNo', 'order_no', 'orderNo', 'OrderNo']),
        ];

        if (array_filter($fields, fn (string $value): bool => $value !== '') === []) {
            return sha1((string) json_encode($transaction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return sha1(implode('|', $fields));
    }

    /**
     * @param array<int, string> $keys
     */
    private function firstFilledTransactionValue(array $transaction, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $transaction) || $transaction[$key] === null) {
                continue;
            }

            $value = trim((string) $transaction[$key]);
            if ($value !== '') {
                return str_replace(',', '', $value);
            }
        }

        return '';
    }

    /**
     * @param array<int, mixed> $transactions
     */
    private function sumTransactionProfit(array $transactions): float
    {
        $total = 0.0;

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $total += $this->number($this->value($transaction, 'make', 'profit_loss', 'profitLoss'));
        }

        return $total;
    }

    private function annualizedReturnRate(?float $returnRate, int $elapsedDays): ?float
    {
        if ($returnRate === null || $elapsedDays <= 0) {
            return null;
        }

        return $returnRate * 365 / $elapsedDays;
    }

    /**
     * @param Collection<int, string> $stockCodes
     * @return array<string, array<string, float|string|null>>
     */
    private function historicalPrices(Collection $stockCodes): array
    {
        if ($stockCodes->isEmpty()) {
            return [];
        }

        $rows = TwStockDailyPrice::query()
            ->whereIn('stock_code', $stockCodes->all())
            ->orderBy('stock_code')
            ->orderByDesc('trade_date')
            ->get(['stock_code', 'trade_date', 'close_price']);

        $now = CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'));
        $today = $now->toDateString();
        $startOfYear = $now->startOfYear()->toDateString();

        $history = $rows
            ->groupBy('stock_code')
            ->map(fn (Collection $prices): array => $this->historicalPriceSummary($prices->values(), $today, $startOfYear))
            ->all();

        foreach ($stockCodes->unique()->values() as $stockCode) {
            $stockCode = (string) $stockCode;
            $fallback = $this->yahooHistoricalPriceSummary($stockCode, $today, $startOfYear);
            if ($fallback === null) {
                continue;
            }

            $history[$stockCode] = $this->mergeHistoricalSummary($history[$stockCode] ?? [], $fallback);
        }

        foreach ($this->twseMisPreviousCloses($stockCodes) as $stockCode => $official) {
            $history[(string) $stockCode] = $this->mergeOfficialPreviousClose(
                $history[(string) $stockCode] ?? [],
                $official,
            );
        }

        return $history;
    }

    /**
     * @param Collection<int, TwStockDailyPrice|array{tradeDate?: string, closePrice?: mixed}> $prices
     * @return array{previousClose: float|null, previousCloseDate: string|null, fiveDayReturn: float|null, twentyDayReturn: float|null, sixtyDayReturn: float|null, yearToDateReturn: float|null}
     */
    private function historicalPriceSummary(Collection $prices, string $today, string $startOfYear): array
    {
        $normalized = $prices
            ->map(function (TwStockDailyPrice|array $row): array {
                if ($row instanceof TwStockDailyPrice) {
                    return [
                        'tradeDate' => $row->trade_date?->toDateString(),
                        'closePrice' => $row->close_price,
                    ];
                }

                return [
                    'tradeDate' => $row['tradeDate'] ?? null,
                    'closePrice' => $row['closePrice'] ?? null,
                ];
            })
            ->filter(fn (array $row): bool => is_string($row['tradeDate']) && $this->numberOrNull($row['closePrice']) !== null)
            ->sortByDesc('tradeDate')
            ->values();

        $closeList = $normalized->filter(fn (array $row): bool => $row['tradeDate'] < $today)->values();
        $previous = $closeList->first();
        $base5 = $closeList->get(4);
        $base20 = $closeList->get(19);
        $base60 = $closeList->get(59);
        $yearBase = $normalized
            ->filter(fn (array $row): bool => $row['tradeDate'] < $startOfYear)
            ->first();
        $previousClose = $previous['closePrice'] ?? null;

        return [
            'previousClose' => $this->numberOrNull($previousClose),
            'previousCloseDate' => $previous['tradeDate'] ?? null,
            'fiveDayReturn' => $this->returnRate($previousClose, $base5['closePrice'] ?? null),
            'twentyDayReturn' => $this->returnRate($previousClose, $base20['closePrice'] ?? null),
            'sixtyDayReturn' => $this->returnRate($previousClose, $base60['closePrice'] ?? null),
            'yearToDateReturn' => $this->returnRate($previousClose, $yearBase['closePrice'] ?? null),
        ];
    }

    /**
     * @return array{previousClose: float|null, previousCloseDate: string|null, fiveDayReturn: float|null, twentyDayReturn: float|null, sixtyDayReturn: float|null, yearToDateReturn: float|null}|null
     */
    private function yahooHistoricalPriceSummary(string $stockCode, string $today, string $startOfYear): ?array
    {
        return Cache::remember(
            'esun:portfolio:yahoo-history:' . $stockCode . ':' . $today . ':v1',
            now()->addDays(10),
            function () use ($stockCode, $today, $startOfYear): ?array {
                foreach (['TW', 'TWO'] as $suffix) {
                    $summary = $this->fetchYahooHistoricalPriceSummary($stockCode, $suffix, $today, $startOfYear);
                    if ($summary !== null && $this->hasCompleteHistoricalReturns($summary)) {
                        return $summary;
                    }
                }

                return null;
            },
        );
    }

    /**
     * @return array{previousClose: float|null, previousCloseDate: string|null, fiveDayReturn: float|null, twentyDayReturn: float|null, sixtyDayReturn: float|null, yearToDateReturn: float|null}|null
     */
    private function fetchYahooHistoricalPriceSummary(string $stockCode, string $suffix, string $today, string $startOfYear): ?array
    {
        try {
            $payload = Http::withHeaders([
                'Accept' => 'application/json,text/plain,*/*',
                'User-Agent' => 'Mozilla/5.0',
            ])
                ->timeout(6)
                ->retry(1, 150)
                ->get('https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($stockCode . '.' . $suffix), [
                    'interval' => '1d',
                    'range' => '1y',
                ])
                ->throw()
                ->json();
        } catch (\Throwable) {
            return null;
        }

        $result = $payload['chart']['result'][0] ?? null;
        $timestamps = is_array($result) ? ($result['timestamp'] ?? null) : null;
        $closes = is_array($result) ? ($result['indicators']['quote'][0]['close'] ?? null) : null;
        if (!is_array($timestamps) || !is_array($closes)) {
            return null;
        }

        $timezone = (string) config('esun.timezone', 'Asia/Taipei');
        $prices = collect($timestamps)
            ->map(function (mixed $timestamp, int $index) use ($closes, $timezone): ?array {
                $close = $this->numberOrNull($closes[$index] ?? null);
                if ($close === null || !is_numeric($timestamp)) {
                    return null;
                }

                return [
                    'tradeDate' => CarbonImmutable::createFromTimestampUTC((int) $timestamp)
                        ->setTimezone($timezone)
                        ->toDateString(),
                    'closePrice' => $close,
                ];
            })
            ->filter()
            ->values();

        if ($prices->isEmpty()) {
            return null;
        }

        return $this->historicalPriceSummary($prices, $today, $startOfYear);
    }

    private function hasCompleteHistoricalReturns(array $summary): bool
    {
        return ($summary['fiveDayReturn'] ?? null) !== null
            && ($summary['twentyDayReturn'] ?? null) !== null
            && ($summary['sixtyDayReturn'] ?? null) !== null;
    }

    private function mergeHistoricalSummary(array $base, array $fallback): array
    {
        if ($this->fallbackHasNewerPreviousClose($base, $fallback)) {
            foreach (['previousClose', 'previousCloseDate', 'fiveDayReturn', 'twentyDayReturn', 'sixtyDayReturn', 'yearToDateReturn'] as $key) {
                if (($fallback[$key] ?? null) !== null) {
                    $base[$key] = $fallback[$key];
                }
            }

            return $base;
        }

        foreach (['previousClose', 'fiveDayReturn', 'twentyDayReturn', 'sixtyDayReturn', 'yearToDateReturn'] as $key) {
            if (($base[$key] ?? null) === null && ($fallback[$key] ?? null) !== null) {
                $base[$key] = $fallback[$key];
            }
        }

        if (($base['previousCloseDate'] ?? null) === null && ($fallback['previousCloseDate'] ?? null) !== null) {
            $base['previousCloseDate'] = $fallback['previousCloseDate'];
        }

        return $base;
    }

    /**
     * @param Collection<int, string> $stockCodes
     * @return array<string, array{previousClose: float|null}>
     */
    private function twseMisPreviousCloses(Collection $stockCodes): array
    {
        $codes = $stockCodes
            ->map(fn (mixed $code): string => strtoupper(preg_replace('/[^0-9A-Z]/i', '', (string) $code) ?? ''))
            ->filter()
            ->unique()
            ->values();
        if ($codes->isEmpty()) {
            return [];
        }

        return Cache::remember(
            'esun:portfolio:twse-mis-prevclose:' . CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'))->toDateString() . ':' . sha1($codes->implode(',')) . ':v1',
            now()->addSeconds(60),
            function () use ($codes): array {
                $channels = $codes
                    ->flatMap(fn (string $code): array => ['tse_' . $code . '.tw', 'otc_' . $code . '.tw'])
                    ->implode('|');

                try {
                    $payload = Http::withHeaders([
                        'Accept' => 'application/json,text/plain,*/*',
                        'Referer' => 'https://mis.twse.com.tw/stock/index.jsp',
                        'User-Agent' => 'Mozilla/5.0',
                    ])
                        ->timeout(4)
                        ->retry(1, 150)
                        ->get(self::TWSE_MIS_URL, [
                            'ex_ch' => $channels,
                            'json' => '1',
                            'delay' => '0',
                            '_' => (string) (int) (microtime(true) * 1000),
                        ])
                        ->throw()
                        ->json();
                } catch (\Throwable) {
                    return [];
                }

                $rows = is_array($payload['msgArray'] ?? null) ? $payload['msgArray'] : [];
                $previousCloses = [];
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $code = strtoupper(preg_replace('/[^0-9A-Z]/i', '', (string) ($row['c'] ?? '')) ?? '');
                    $previousClose = $this->numberOrNull($row['y'] ?? null);
                    if ($code === '' || !$codes->contains($code) || $previousClose === null) {
                        continue;
                    }

                    $previousCloses[$code] = ['previousClose' => $previousClose];
                }

                return $previousCloses;
            },
        );
    }

    private function mergeOfficialPreviousClose(array $base, array $official): array
    {
        $officialPreviousClose = $this->numberOrNull($official['previousClose'] ?? null);
        if ($officialPreviousClose === null) {
            return $base;
        }

        $basePreviousClose = $this->numberOrNull($base['previousClose'] ?? null);
        $tolerance = max(0.01, abs($officialPreviousClose) * 0.0001);
        if ($basePreviousClose !== null && abs($basePreviousClose - $officialPreviousClose) <= $tolerance) {
            return $base;
        }

        $base['previousClose'] = $officialPreviousClose;

        return $base;
    }

    private function fallbackHasNewerPreviousClose(array $base, array $fallback): bool
    {
        if (($fallback['previousClose'] ?? null) === null) {
            return false;
        }

        $baseDate = (string) ($base['previousCloseDate'] ?? '');
        $fallbackDate = (string) ($fallback['previousCloseDate'] ?? '');

        return $fallbackDate !== '' && ($baseDate === '' || $fallbackDate > $baseDate);
    }

    private function returnRate(mixed $current, mixed $base): ?float
    {
        $current = $this->numberOrNull($current);
        $base = $this->numberOrNull($base);
        if ($current === null || $base === null || $base == 0.0) {
            return null;
        }

        return ($current - $base) / $base * 100;
    }

    private function value(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null) {
                return $row[$key];
            }
        }

        return null;
    }

    private function number(mixed $value): float
    {
        return $this->numberOrNull($value) ?? 0.0;
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '', (string) $value);
    }

    private function integerOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = str_replace(',', '', (string) $value);

        return is_numeric($normalized) ? (int) round((float) $normalized) : null;
    }

    private function parseDateTime(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, $timezone)->setTimezone($timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseSnapshotDate(string $date): CarbonImmutable
    {
        $normalized = str_replace('/', '-', trim($date));
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $normalized, (string) config('esun.timezone', 'Asia/Taipei'));
        if (!$parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $normalized) {
            throw new \InvalidArgumentException('日期格式錯誤：' . $date);
        }

        return $parsed->startOfDay();
    }

    private function redactSecrets(string $message): string
    {
        foreach (['api_key', 'api_secret', 'account_password', 'cert_password'] as $configKey) {
            $value = config('esun.' . $configKey);
            if (is_string($value) && $value !== '') {
                $message = str_replace($value, '[redacted]', $message);
            }
        }

        return $message;
    }

    private function decodeProcessJson(string $output): mixed
    {
        $payload = json_decode(trim($output), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $payload;
        }

        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);
        foreach ($lines as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '{')) {
                continue;
            }

            $payload = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $payload;
            }
        }

        return null;
    }

    private function cacheTtl(array $market): int
    {
        $configured = (int) config($market['isOpen'] ? 'esun.cache_seconds_open' : 'esun.cache_seconds_closed', 60);

        return max($this->minimumQuerySeconds(), $configured);
    }

    private function minimumQuerySeconds(): int
    {
        return max(60, (int) config('esun.minimum_query_seconds', 60));
    }

    private function secondsSince(mixed $value, CarbonImmutable $now): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (int) round(CarbonImmutable::parse($value)->diffInSeconds($now, true));
        } catch (\Throwable) {
            return null;
        }
    }

    private function secondsUntil(mixed $value, CarbonImmutable $now): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return max(0, CarbonImmutable::parse($value)->getTimestamp() - $now->getTimestamp());
        } catch (\Throwable) {
            return null;
        }
    }

    private function isRateLimited(RuntimeException $exception): bool
    {
        return str_contains($exception->getMessage(), 'AGR0003')
            || str_contains($exception->getMessage(), 'AGR0004')
            || str_contains($exception->getMessage(), 'Transaction Rate Limit');
    }

    private function staleSnapshotMessage(RuntimeException $exception): string
    {
        if (str_contains($exception->getMessage(), 'AGR0004')) {
            return '玉山庫存 API 今日登入次數已達上限，顯示最近一次成功資料。';
        }

        return $this->isRateLimited($exception)
            ? '玉山庫存 API 暫時達到查詢頻率限制，顯示最近一次成功資料。'
            : '玉山庫存 API 暫時查詢失敗，顯示最近一次成功資料。';
    }
}
