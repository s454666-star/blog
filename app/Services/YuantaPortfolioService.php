<?php

namespace App\Services;

use App\Models\TwStockCompanyProfile;
use App\Models\TwStockDailyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

class YuantaPortfolioService
{
    public function snapshot(bool $force = false): array
    {
        $now = CarbonImmutable::now((string) config('yuanta.timezone', 'Asia/Taipei'));
        $market = $this->marketStatus($now);
        $ttl = $this->cacheTtl($market);
        $cacheKey = 'yuanta:portfolio:inventories:v1';
        $fallbackKey = 'yuanta:portfolio:inventories:last-success:v1';
        $lastQueryKey = 'yuanta:portfolio:last-query-at:v1';

        $cached = Cache::get($cacheKey);
        $queryAge = $this->secondsSince(Cache::get($lastQueryKey), $now);
        $minQuerySeconds = $this->minimumQuerySeconds();
        $fallback = fn (): ?array => $this->lastSuccessfulSnapshot($fallbackKey);

        if (is_array($cached) && (!$force || ($queryAge !== null && $queryAge < $minQuerySeconds))) {
            return $this->buildSnapshot($cached, $market, $now, $ttl, [
                'status' => $force ? 'throttled' : 'cached',
                'message' => $force
                    ? '元大庫存 API 有查詢頻率限制，先顯示最近一次成功資料。'
                    : '顯示後端快取資料。',
            ]);
        }

        if ($queryAge !== null && $queryAge < $minQuerySeconds && is_array($raw = $fallback())) {
            return $this->buildSnapshot($raw, $market, $now, $ttl, [
                'status' => $force ? 'throttled' : 'cached',
                'message' => '元大庫存 API 有查詢頻率限制，先顯示最近一次成功資料。',
            ]);
        }

        try {
            Cache::put($lastQueryKey, $now->toIso8601String(), now()->addDay());
            $raw = $this->queryPortfolio($now);
            Cache::put($cacheKey, $raw, now()->addSeconds($ttl));
            $this->storeSuccessfulSnapshot($fallbackKey, $raw);

            return $this->buildSnapshot($raw, $market, $now, $ttl, [
                'status' => 'live',
                'message' => '元大 API 查詢成功。',
            ]);
        } catch (RuntimeException $exception) {
            $raw = $fallback();
            if (is_array($raw)) {
                return $this->buildSnapshot($raw, $market, $now, $ttl, [
                    'status' => 'stale',
                    'message' => '元大庫存 API 暫時查詢失敗，顯示最近一次成功資料。',
                ]);
            }

            throw $exception;
        }
    }

    public function marketStatus(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now((string) config('yuanta.timezone', 'Asia/Taipei'));
        $date = $now->toDateString();
        $start = CarbonImmutable::parse($date . ' ' . config('yuanta.market_open_start', '09:00'), $now->timezone);
        $end = CarbonImmutable::parse($date . ' ' . config('yuanta.market_open_end', '13:35'), $now->timezone);
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
            'pollSeconds' => $isOpen ? max(2, (int) config('yuanta.poll_seconds_open', 2)) : null,
        ];
    }

    private function queryPortfolio(CarbonImmutable $now): array
    {
        if (!config('yuanta.portfolio_enabled')) {
            throw new RuntimeException('Yuanta portfolio query is disabled.');
        }

        $today = $now->startOfDay();
        $yearStart = $today->startOfYear();

        $payload = $this->runYuantaScript((string) config('yuanta.query_script'), [
            ...$this->yuantaProcessEnvironment(),
            'YUANTA_REALIZED_START' => $yearStart->format('Y/m/d'),
            'YUANTA_REALIZED_END' => $today->format('Y/m/d'),
        ]);

        if (!isset($payload['inventories']) || !is_array($payload['inventories'])) {
            throw new RuntimeException('Yuanta query returned an unexpected payload.');
        }

        return [
            'queriedAt' => $payload['queried_at'] ?? now()->toIso8601String(),
            'inventories' => $payload['inventories'],
            'unrealizedDetails' => is_array($payload['unrealized_details'] ?? null) ? $payload['unrealized_details'] : [],
            'balance' => is_array($payload['balance'] ?? null) ? $payload['balance'] : [],
            'settlements' => is_array($payload['settlements'] ?? null) ? $payload['settlements'] : [],
            'transactions' => is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [],
            'todayTransactions' => $this->todayTransactions($payload['transactions'] ?? [], $today),
        ];
    }

    private function buildSnapshot(array $raw, array $market, CarbonImmutable $now, int $ttl, array $source = []): array
    {
        $inventories = collect($raw['inventories'] ?? []);
        $stockCodes = $inventories
            ->map(fn (array $row): string => (string) $this->value($row, 'StkCode', 'stkCode', 'stockNo'))
            ->filter()
            ->unique()
            ->values();
        $history = $this->historicalPrices($stockCodes);
        $exchanges = $this->exchangeMetadata($stockCodes);

        $rows = $inventories
            ->map(function (array $row) use ($history, $exchanges): array {
                $stockNo = (string) $this->value($row, 'StkCode', 'stkCode', 'stockNo');

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
        $balanceSummary = $this->balanceSummary($raw, $totalCostBasis, $now);
        $yearProfitSummary = $this->yearProfitSummary($raw);
        $totalCapital = $balanceSummary['bankBalance'] === null ? null : $totalCostBasis + $balanceSummary['bankBalance'];
        $yearReturnBase = $totalCapital === null ? null : $totalCapital - $yearProfitSummary['yearTotalPnl'];
        $yearTotalPnlRate = $yearReturnBase !== null && $yearReturnBase > 0
            ? $yearProfitSummary['yearTotalPnl'] / $yearReturnBase * 100
            : null;
        $previousMarketValue = array_sum(array_map(
            fn (array $row): float => $row['previousClose'] === null ? 0.0 : $row['previousClose'] * $row['quantity'],
            $rows,
        ));

        $rows = array_map(function (array $row) use ($totalMarketValue): array {
            $row['marketWeight'] = $totalMarketValue > 0 ? $row['marketValue'] / $totalMarketValue * 100 : null;

            return $row;
        }, $rows);

        return [
            'queriedAt' => $raw['queriedAt'] ?? $now->toIso8601String(),
            'servedAt' => $now->toIso8601String(),
            'cacheSeconds' => $ttl,
            'market' => $market,
            'source' => [
                'status' => $source['status'] ?? 'cached',
                'message' => $source['message'] ?? '',
                'ageSeconds' => $this->secondsSince($raw['queriedAt'] ?? null, $now),
                'minQuerySeconds' => $this->minimumQuerySeconds(),
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
                'dayTradeYearPnl' => 0,
                'adjustedRealizedYearPnl' => $yearProfitSummary['realizedYearPnl'],
                'yearTotalPnl' => $yearProfitSummary['yearTotalPnl'],
                'yearReturnBase' => $yearReturnBase,
                'yearTotalPnlRate' => $yearTotalPnlRate,
                'yearElapsedDays' => max(1, (int) $now->dayOfYear),
                'annualizedReturnRate' => null,
            ],
            'rows' => $rows,
        ];
    }

    private function marginSummary(array $rows, array $raw): array
    {
        $usedAmount = array_sum(array_map(
            fn (array $row): float => $row['tradeType'] === '3'
                ? $this->number($row['raw']['loan'] ?? 0)
                : 0.0,
            $rows,
        ));
        $marketValue = array_sum(array_map(
            fn (array $row): float => $row['tradeType'] === '3'
                ? $this->number($row['marketValue'] ?? 0)
                : 0.0,
            $rows,
        ));
        $availableAmount = $this->marginAvailableAmount($raw);

        return [
            'limitAmount' => $availableAmount === null ? null : $usedAmount + $availableAmount,
            'usedAmount' => $usedAmount,
            'availableAmount' => $availableAmount,
            'maintenanceRate' => $usedAmount > 0 ? $marketValue / $usedAmount * 100 : null,
        ];
    }

    private function marginAvailableAmount(array $raw): ?float
    {
        $balances = collect($raw['balance'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values();
        if ($balances->isEmpty()) {
            return null;
        }

        return $this->numberOrNull($this->value(
            $balances->first(),
            'MarginAvailableAmount',
            'marginAvailableAmount',
            'CreditAvailableAmount',
            'creditAvailableAmount',
            'AvailableMargin',
            'availableMargin',
            'AvailableBalance',
            'availableBalance',
        ));
    }

    private function formatInventoryRow(array $row, array $history, array $exchange = []): array
    {
        $stockNo = (string) $this->value($row, 'StkCode', 'stkCode', 'stockNo');
        $quantity = $this->number($this->value($row, 'StockQty', 'stockQty', 'quantity'));
        $marketValue = $this->number($this->value($row, 'MarketAmt', 'marketAmt', 'marketValue'));
        $currentPrice = $this->number($this->value($row, 'MarketPrice', 'marketPrice', 'currentPrice'));
        if ($currentPrice <= 0 && $quantity > 0) {
            $currentPrice = $marketValue / $quantity;
        }

        $unrealizedPnl = $this->number($this->value($row, 'ReturnAmt', 'returnAmt', 'unrealizedPnl'));
        $costBasis = abs($this->number($this->value($row, 'Cost', 'cost', 'costBasis')));
        if ($costBasis <= 0) {
            $costBasis = abs($marketValue - $unrealizedPnl);
        }

        $previousClose = $history['previousClose'] ?? null;
        $todayPnl = $previousClose === null ? null : ($currentPrice - $previousClose) * $quantity;
        $todayPnlRate = $previousClose > 0 ? ($currentPrice - $previousClose) / $previousClose * 100 : null;
        $tradeType = (string) $this->value($row, 'TradeKind', 'tradeKind', 'tradeType');

        return [
            'stockNo' => $stockNo,
            'stockName' => (string) $this->value($row, 'StkName', 'stkName', 'stockName'),
            'quantity' => $quantity,
            'currentPrice' => $currentPrice,
            'previousClose' => $previousClose,
            'dayChange' => $previousClose === null ? null : $currentPrice - $previousClose,
            'dayChangeRate' => $todayPnlRate,
            'todayPnl' => $todayPnl,
            'esunTodayPnl' => $todayPnl,
            'averagePrice' => $this->number($this->value($row, 'Price', 'price')),
            'breakevenPrice' => 0,
            'priceAmount' => $costBasis,
            'marketValue' => $marketValue,
            'esunCurrentPrice' => $currentPrice,
            'esunMarketValue' => $marketValue,
            'costBasis' => $costBasis,
            'signedCostBasis' => -$costBasis,
            'unrealizedPnl' => $unrealizedPnl,
            'esunUnrealizedPnl' => $unrealizedPnl,
            'realtimePnlBasePrice' => $currentPrice,
            'unrealizedPnlRate' => $costBasis > 0 ? $unrealizedPnl / $costBasis * 100 : null,
            'fiveDayReturn' => $history['fiveDayReturn'] ?? null,
            'twentyDayReturn' => $history['twentyDayReturn'] ?? null,
            'sixtyDayReturn' => $history['sixtyDayReturn'] ?? null,
            'yearToDateReturn' => $history['yearToDateReturn'] ?? null,
            'tradeType' => $tradeType,
            'tradeTypeLabel' => $this->tradeTypeLabel($tradeType),
            'exchange' => $exchange['exchange'] ?? null,
            'exchangeLabel' => $exchange['label'] ?? $this->normalizeMarketName((string) $this->value($row, 'MarketName', 'marketName')),
            'exchangeShortLabel' => $exchange['shortLabel'] ?? null,
            'exchangeClass' => $exchange['class'] ?? null,
            'positionType' => '',
            'lotCount' => 1,
            'lots' => [],
            'raw' => [
                'marketNo' => (string) $this->value($row, 'MarketNo', 'marketNo'),
                'tradingQty' => $this->number($this->value($row, 'TradingQty', 'tradingQty')),
                'buyPrice' => $this->number($this->value($row, 'BuyPrice', 'buyPrice')),
                'sellPrice' => $this->number($this->value($row, 'SellPrice', 'sellPrice')),
                'loan' => $this->number($this->value($row, 'Loan', 'loan')),
            ],
        ];
    }

    private function balanceSummary(array $raw, float $totalCostBasis, ?CarbonImmutable $now = null): array
    {
        $balances = collect($raw['balance'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values();
        $availableBalance = $balances->isEmpty()
            ? null
            : $this->numberOrNull($this->value($balances->first(), 'AvailableBalance', 'availableBalance'));
        $pendingSettlementAmount = $this->pendingSettlementAmount($raw, $now);
        $bankBalance = $availableBalance === null ? null : $availableBalance - $pendingSettlementAmount;
        $totalCapital = $bankBalance === null ? null : $totalCostBasis + $bankBalance;

        return [
            'availableBalance' => $availableBalance,
            'dayTradeOffsetAmount' => 0.0,
            'pendingSettlementAmount' => $pendingSettlementAmount,
            'bankBalance' => $bankBalance,
            'investmentLevelRate' => $totalCapital !== null && $totalCapital > 0
                ? $totalCostBasis / $totalCapital * 100
                : null,
        ];
    }

    private function pendingSettlementAmount(array $raw, ?CarbonImmutable $now = null): float
    {
        $today = ($now ?? CarbonImmutable::now((string) config('yuanta.timezone', 'Asia/Taipei')))->startOfDay();

        return collect($raw['settlements'] ?? [])
            ->filter(fn (mixed $settlement): bool => is_array($settlement))
            ->filter(function (array $settlement) use ($today): bool {
                $rawDate = $this->value($settlement, 'SettlementDay', 'settlementDay');
                if ($rawDate === null || trim((string) $rawDate) === '') {
                    return true;
                }

                try {
                    return CarbonImmutable::parse((string) $rawDate, $today->timezone)->startOfDay()->greaterThan($today);
                } catch (\Throwable) {
                    return true;
                }
            })
            ->sum(fn (array $settlement): float => abs($this->number($this->value($settlement, 'SettlementAmt', 'settlementAmt'))));
    }

    private function yearProfitSummary(array $raw): array
    {
        $transactions = collect($raw['transactions'] ?? [])->filter(fn (mixed $row): bool => is_array($row));
        $todayTransactions = collect($raw['todayTransactions'] ?? [])->filter(fn (mixed $row): bool => is_array($row));
        $realizedYearPnl = $transactions->sum(fn (array $row): float => $this->number($this->value($row, 'ProfitLoss', 'profitLoss')));
        $realizedTodayPnl = $todayTransactions->sum(fn (array $row): float => $this->number($this->value($row, 'ProfitLoss', 'profitLoss')));

        return [
            'realizedHistoryPnl' => $realizedYearPnl - $realizedTodayPnl,
            'realizedTodayPnl' => $realizedTodayPnl,
            'realizedYearPnl' => $realizedYearPnl,
            'yearTotalPnl' => $realizedYearPnl,
        ];
    }

    /**
     * @param mixed $transactions
     * @return array<int, array<string, mixed>>
     */
    private function todayTransactions(mixed $transactions, CarbonImmutable $today): array
    {
        if (!is_array($transactions)) {
            return [];
        }

        return collect($transactions)
            ->filter(fn (mixed $row): bool => is_array($row))
            ->filter(function (array $row) use ($today): bool {
                $rawDate = $this->value($row, 'TradeDate', 'tradeDate');
                if ($rawDate === null || trim((string) $rawDate) === '') {
                    return false;
                }

                try {
                    return CarbonImmutable::parse((string) $rawDate, $today->timezone)->isSameDay($today);
                } catch (\Throwable) {
                    return false;
                }
            })
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, string> $stockCodes
     * @return array<string, array<string, float|null>>
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
        $today = CarbonImmutable::now((string) config('yuanta.timezone', 'Asia/Taipei'))->toDateString();
        $startOfYear = CarbonImmutable::now((string) config('yuanta.timezone', 'Asia/Taipei'))->startOfYear()->toDateString();

        return $rows
            ->groupBy('stock_code')
            ->map(fn (Collection $prices): array => $this->historicalPriceSummary($prices->values(), $today, $startOfYear))
            ->all();
    }

    /**
     * @param Collection<int, TwStockDailyPrice> $prices
     * @return array<string, float|null>
     */
    private function historicalPriceSummary(Collection $prices, string $today, string $startOfYear): array
    {
        $previous = $prices->first(fn (TwStockDailyPrice $price): bool => (string) $price->trade_date < $today);
        $latest = $prices->first();
        $latestClose = $latest === null ? null : (float) $latest->close_price;
        $priceAt = function (int $offset) use ($prices): ?float {
            $row = $prices->get($offset);

            return $row === null ? null : (float) $row->close_price;
        };
        $yearStart = $prices
            ->filter(fn (TwStockDailyPrice $price): bool => (string) $price->trade_date >= $startOfYear)
            ->last();

        return [
            'previousClose' => $previous === null ? null : (float) $previous->close_price,
            'fiveDayReturn' => $this->returnRate($latestClose, $priceAt(5)),
            'twentyDayReturn' => $this->returnRate($latestClose, $priceAt(20)),
            'sixtyDayReturn' => $this->returnRate($latestClose, $priceAt(60)),
            'yearToDateReturn' => $this->returnRate($latestClose, $yearStart === null ? null : (float) $yearStart->close_price),
        ];
    }

    private function returnRate(?float $current, ?float $base): ?float
    {
        return $current !== null && $base !== null && $base > 0 ? ($current - $base) / $base * 100 : null;
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

        return TwStockCompanyProfile::query()
            ->whereIn('stock_code', $stockCodes->all())
            ->get(['stock_code', 'exchange'])
            ->mapWithKeys(function (TwStockCompanyProfile $profile): array {
                $meta = $this->exchangeMeta((string) $profile->exchange);

                return $meta === null ? [] : [(string) $profile->stock_code => $meta];
            })
            ->all();
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

    private function normalizeMarketName(string $marketName): ?string
    {
        return trim($marketName) === '' ? null : trim($marketName);
    }

    private function tradeTypeLabel(string $tradeType): string
    {
        return match ($tradeType) {
            '0' => '現股',
            '3' => '融資',
            '4' => '融券',
            '6' => '借券',
            default => $tradeType !== '' ? $tradeType : '--',
        };
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
        return storage_path('app/yuanta/portfolio-last-success-v1.json');
    }

    private function yuantaProcessEnvironment(): array
    {
        $env = [
            'YUANTA_DOTNET_ROOT' => config('yuanta.dotnet_root'),
            'YUANTA_SDK_PATH' => config('yuanta.sdk_path'),
            'YUANTA_API_ENVIRONMENT' => config('yuanta.environment'),
            'YUANTA_ACCOUNT' => config('yuanta.account'),
            'YUANTA_PASSWORD' => config('yuanta.password'),
            'YUANTA_PFX_PATH' => config('yuanta.pfx_path'),
            'YUANTA_PFX_PASSWORD' => config('yuanta.pfx_password'),
        ];

        return array_map(fn (mixed $value): string => (string) $value, $env);
    }

    private function runYuantaScript(string $script, array $env, int $timeout = 60): array
    {
        if (!is_file($script)) {
            throw new RuntimeException('Yuanta query script is missing.');
        }

        $processEnv = array_filter($env, fn (string $value): bool => $value !== '');
        $process = new Process([(string) config('yuanta.python_bin', 'python'), $script], base_path(), $processEnv, null, $timeout);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Unknown Yuanta query failure.';
            throw new RuntimeException($error);
        }

        $payload = json_decode($process->getOutput(), true);
        if (!is_array($payload)) {
            throw new RuntimeException('Yuanta query returned an unexpected payload.');
        }

        return $payload;
    }

    private function value(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function secondsSince(mixed $timestamp, CarbonImmutable $now): ?int
    {
        if ($timestamp === null || trim((string) $timestamp) === '') {
            return null;
        }

        try {
            return max(0, CarbonImmutable::parse((string) $timestamp, $now->timezone)->diffInSeconds($now));
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheTtl(array $market): int
    {
        $configured = (int) config($market['isOpen'] ? 'yuanta.cache_seconds_open' : 'yuanta.cache_seconds_closed', 60);

        return max(1, $configured);
    }

    private function minimumQuerySeconds(): int
    {
        return max(60, (int) config('yuanta.minimum_query_seconds', 60));
    }
}
