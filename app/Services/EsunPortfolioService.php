<?php

namespace App\Services;

use App\Models\TwStockCompanyProfile;
use App\Models\TwStockDailyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;

class EsunPortfolioService
{
    public function snapshot(bool $force = false): array
    {
        $now = CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'));
        $market = $this->marketStatus($now);
        $ttl = $this->cacheTtl($market);
        $cacheKey = 'esun:portfolio:inventories:v3';
        $fallbackKey = 'esun:portfolio:inventories:last-success:v3';
        $lastQueryKey = 'esun:portfolio:last-query-at:v3';

        $cached = Cache::get($cacheKey);
        $queryAge = $this->secondsSince(Cache::get($lastQueryKey), $now);
        $minQuerySeconds = $this->minimumQuerySeconds();

        if (is_array($cached) && (!$force || ($queryAge !== null && $queryAge < $minQuerySeconds))) {
            $meta = $force ? [
                'status' => 'throttled',
                'message' => '玉山庫存 API 有查詢頻率限制，先顯示最近一次成功資料。',
            ] : [
                'status' => 'cached',
                'message' => '顯示後端快取資料。',
            ];

            return $this->buildSnapshot($cached, $market, $now, $ttl, $meta);
        }

        try {
            Cache::put($lastQueryKey, $now->toIso8601String(), now()->addDay());
            $raw = $this->queryInventories();
            Cache::put($cacheKey, $raw, now()->addSeconds($ttl));
            Cache::put($fallbackKey, $raw, now()->addHours(8));

            return $this->buildSnapshot($raw, $market, $now, $ttl, [
                'status' => 'live',
                'message' => '玉山 API 查詢成功。',
            ]);
        } catch (RuntimeException $exception) {
            $fallback = Cache::get($fallbackKey);
            if (is_array($fallback)) {
                return $this->buildSnapshot($fallback, $market, $now, $ttl, [
                    'status' => 'stale',
                    'message' => $this->isRateLimited($exception)
                        ? '玉山庫存 API 暫時達到查詢頻率限制，顯示最近一次成功資料。'
                        : '玉山庫存 API 暫時查詢失敗，顯示最近一次成功資料。',
                ]);
            }

            throw $exception;
        }
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

    private function queryInventories(): array
    {
        if (!config('esun.portfolio_enabled')) {
            throw new RuntimeException('E.SUN portfolio query is disabled.');
        }

        $required = [
            'ESUN_API_ENTRY' => config('esun.entry'),
            'ESUN_ACCOUNT' => config('esun.account'),
            'ESUN_API_KEY' => config('esun.api_key'),
            'ESUN_API_SECRET' => config('esun.api_secret'),
            'ESUN_CERT_PATH' => config('esun.cert_path'),
            'ESUN_ACCOUNT_PASSWORD' => config('esun.account_password'),
            'ESUN_CERT_PASSWORD' => config('esun.cert_password'),
        ];

        foreach ($required as $name => $value) {
            if (!is_string($value) || trim($value) === '') {
                throw new RuntimeException($name . ' is not configured.');
            }
        }

        $script = (string) config('esun.query_script');
        if (!is_file($script)) {
            throw new RuntimeException('E.SUN query script is missing.');
        }

        $process = new Process([
            (string) config('esun.python_bin', 'python'),
            $script,
        ], base_path(), $required);
        $process->setTimeout(35);
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Unknown E.SUN query failure.';
            throw new RuntimeException($this->redactSecrets($error));
        }

        $payload = $this->decodeProcessJson($process->getOutput());
        if (!is_array($payload) || !isset($payload['inventories']) || !is_array($payload['inventories'])) {
            throw new RuntimeException('E.SUN query returned an unexpected payload.');
        }

        return [
            'queriedAt' => $payload['queried_at'] ?? now()->toIso8601String(),
            'inventories' => $payload['inventories'],
            'balance' => is_array($payload['balance'] ?? null) ? $payload['balance'] : [],
            'settlements' => is_array($payload['settlements'] ?? null) ? $payload['settlements'] : [],
            'transactions' => is_array($payload['transactions'] ?? null) ? $payload['transactions'] : [],
        ];
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
        $balanceSummary = $this->balanceSummary($raw, $totalCostBasis);
        $yearProfitSummary = $this->yearProfitSummary($raw, $totalUnrealizedPnl);
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
                'todayPnl' => $totalTodayPnl,
                'esunTodayPnl' => $totalTodayPnl,
                'todayPnlRate' => $previousMarketValue > 0 ? $totalTodayPnl / $previousMarketValue * 100 : null,
                'unrealizedPnl' => $totalUnrealizedPnl,
                'unrealizedPnlRate' => $totalCostBasis > 0 ? $totalUnrealizedPnl / $totalCostBasis * 100 : null,
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
        $costBasis = abs($signedCostBasis);
        if ($costBasis <= 0) {
            $costBasis = abs($this->number($this->value($row, 'price_qty_sum', 'priceQtySum')));
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
            'priceAmount' => $this->number($this->value($row, 'price_qty_sum', 'priceQtySum')),
            'marketValue' => $marketValue,
            'esunCurrentPrice' => $currentPrice,
            'esunMarketValue' => $marketValue,
            'costBasis' => $costBasis,
            'signedCostBasis' => $signedCostBasis,
            'unrealizedPnl' => $unrealizedPnl,
            'esunUnrealizedPnl' => $unrealizedPnl,
            'realtimePnlBasePrice' => $currentPrice,
            'unrealizedPnlRate' => $costBasis > 0 ? $unrealizedPnl / $costBasis * 100 : null,
            'fiveDayReturn' => $history['fiveDayReturn'] ?? null,
            'twentyDayReturn' => $history['twentyDayReturn'] ?? null,
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
            ],
        ];
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
            : $availableBalance - $dayTradeOffsetAmount - $pendingSettlementAmount;
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
            ->sum(fn (array $settlement): float => abs($this->number($this->value(
                $settlement,
                'price',
                'amount',
                'settlement_amount',
                'settlementAmount',
            ))));
    }

    private function isFutureSettlement(array $settlement, CarbonImmutable $today): bool
    {
        $rawDate = $this->value($settlement, 'c_date', 'cDate', 'settlement_date', 'settlementDate');
        if ($rawDate === null || trim((string) $rawDate) === '') {
            return true;
        }

        try {
            $dateText = trim((string) $rawDate);
            $date = preg_match('/^\d{8}$/', $dateText) === 1
                ? CarbonImmutable::createFromFormat('Ymd', $dateText, $today->timezone)
                : CarbonImmutable::parse($dateText, $today->timezone);

            if (!$date instanceof CarbonImmutable) {
                return true;
            }

            return $date->startOfDay()->greaterThan($today);
        } catch (\Throwable) {
            return true;
        }
    }

    /**
     * @return array{
     *     realizedYearPnl: float,
     *     dayTradeYearPnl: float,
     *     adjustedRealizedYearPnl: float,
     *     yearTotalPnl: float
     * }
     */
    private function yearProfitSummary(array $raw, float $unrealizedPnl): array
    {
        $transactions = is_array($raw['transactions'] ?? null) ? $raw['transactions'] : [];
        $realizedYearPnl = 0.0;
        $dayTradeYearPnl = 0.0;

        foreach ($transactions as $transaction) {
            if (!is_array($transaction)) {
                continue;
            }

            $make = $this->number($this->value($transaction, 'make', 'profit_loss', 'profitLoss'));
            $realizedYearPnl += $make;

            if (in_array((string) $this->value($transaction, 'trade'), ['A', '9'], true)) {
                $dayTradeYearPnl += $make;
            }
        }

        $adjustedRealizedYearPnl = $realizedYearPnl - $dayTradeYearPnl;

        return [
            'realizedYearPnl' => $realizedYearPnl,
            'dayTradeYearPnl' => $dayTradeYearPnl,
            'adjustedRealizedYearPnl' => $adjustedRealizedYearPnl,
            'yearTotalPnl' => $adjustedRealizedYearPnl + $unrealizedPnl,
        ];
    }

    private function annualizedReturnRate(?float $returnRate, int $elapsedDays): ?float
    {
        if ($returnRate === null || $elapsedDays <= 0) {
            return null;
        }

        $multiplier = 1 + ($returnRate / 100);
        if ($multiplier <= 0) {
            return null;
        }

        return (pow($multiplier, 365 / $elapsedDays) - 1) * 100;
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

        $today = CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'))->toDateString();
        $startOfYear = CarbonImmutable::now((string) config('esun.timezone', 'Asia/Taipei'))->startOfYear()->toDateString();

        return $rows
            ->groupBy('stock_code')
            ->map(function (Collection $prices) use ($today, $startOfYear): array {
                $prices = $prices->values();
                $previous = $prices->first(fn (TwStockDailyPrice $row): bool => $row->trade_date->toDateString() < $today);
                $closeList = $prices->filter(fn (TwStockDailyPrice $row): bool => $row->trade_date->toDateString() < $today)->values();
                $base5 = $closeList->get(4);
                $base20 = $closeList->get(19);
                $yearBase = $prices
                    ->filter(fn (TwStockDailyPrice $row): bool => $row->trade_date->toDateString() < $startOfYear)
                    ->first();
                $previousClose = $previous?->close_price;

                return [
                    'previousClose' => $previousClose === null ? null : (float) $previousClose,
                    'fiveDayReturn' => $this->returnRate($previousClose, $base5?->close_price),
                    'twentyDayReturn' => $this->returnRate($previousClose, $base20?->close_price),
                    'yearToDateReturn' => $this->returnRate($previousClose, $yearBase?->close_price),
                ];
            })
            ->all();
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

    private function isRateLimited(RuntimeException $exception): bool
    {
        return str_contains($exception->getMessage(), 'AGR0003')
            || str_contains($exception->getMessage(), 'Transaction Rate Limit');
    }
}
