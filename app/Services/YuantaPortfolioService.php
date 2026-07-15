<?php

namespace App\Services;

use App\Models\TwStockCompanyProfile;
use App\Models\TwStockDailyPrice;
use App\Models\YuantaPortfolioDailySnapshot;
use App\Services\Concerns\AnnotatesPortfolioTodayAddedQuantity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class YuantaPortfolioService
{
    use AnnotatesPortfolioTodayAddedQuantity;

    private const TWSE_MIS_URL = 'https://mis.twse.com.tw/stock/api/getStockInfo.jsp';

    private const BROKERAGE_FEE_RATE = 0.001425;

    private const MINIMUM_BROKERAGE_FEE = 20.0;

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

    public function captureDailySnapshot(?CarbonImmutable $snapshotDate = null, bool $force = true): YuantaPortfolioDailySnapshot
    {
        return $this->storeDailySnapshot($this->snapshot($force), $snapshotDate);
    }

    public function storeDailySnapshot(array $payload, ?CarbonImmutable $snapshotDate = null): YuantaPortfolioDailySnapshot
    {
        $timezone = (string) config('yuanta.timezone', 'Asia/Taipei');
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $rows = is_array($payload['rows'] ?? null) ? array_values($payload['rows']) : [];
        $queriedAt = $this->parseDateTime($payload['queriedAt'] ?? null, $timezone);
        $capturedAt = $this->parseDateTime($payload['servedAt'] ?? null, $timezone)
            ?? CarbonImmutable::now($timezone);
        $date = $snapshotDate?->startOfDay()
            ?? ($queriedAt ?? $capturedAt)->setTimezone($timezone)->startOfDay();

        return YuantaPortfolioDailySnapshot::query()->updateOrCreate(
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
        return YuantaPortfolioDailySnapshot::query()
            ->orderByDesc('snapshot_date')
            ->limit(max(1, $limit))
            ->get()
            ->map(fn (YuantaPortfolioDailySnapshot $snapshot): array => [
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
        $snapshot = YuantaPortfolioDailySnapshot::query()
            ->where('snapshot_date', $snapshotDate->toDateString())
            ->first();
        if (!$snapshot instanceof YuantaPortfolioDailySnapshot) {
            return null;
        }

        $timezone = (string) config('yuanta.timezone', 'Asia/Taipei');
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
            'openStart' => (string) config('yuanta.market_open_start', '09:00'),
            'openEnd' => (string) config('yuanta.market_open_end', '13:35'),
            'pollSeconds' => null,
        ];
        $payload['source'] = [
            'status' => 'historical',
            'message' => '元大收盤快照',
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
        $exchanges = $this->mergeInventoryExchangeMetadata(
            $inventories,
            $this->exchangeMetadata($stockCodes),
        );
        $emergingCodes = collect($exchanges)
            ->filter(fn (array $exchange): bool => ($exchange['class'] ?? null) === 'emerging')
            ->keys()
            ->values();
        $history = $this->historicalPrices($stockCodes, $emergingCodes);

        $rows = $inventories
            ->map(function (array $row) use ($history, $exchanges): array {
                $stockNo = (string) $this->value($row, 'StkCode', 'stkCode', 'stockNo');

                return $this->formatInventoryRow($row, $history[$stockNo] ?? [], $exchanges[$stockNo] ?? []);
            })
            ->sortBy('stockNo')
            ->values()
            ->all();
        $rows = $this->annotateTodayAddedQuantities(
            $rows,
            $this->previousDailySnapshotRows(YuantaPortfolioDailySnapshot::class, $now),
        );

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
        $configuredLimitAmount = $this->configuredMarginLimitAmount();
        $reportedAvailableAmount = $this->reportedMarginAvailableAmount($raw);
        $limitAmount = $configuredLimitAmount ?? ($reportedAvailableAmount === null ? null : $usedAmount + $reportedAvailableAmount);
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
        $amount = $this->numberOrNull(config('yuanta.margin_limit_amount'));

        return $amount !== null && $amount > 0 ? $amount : null;
    }

    private function reportedMarginAvailableAmount(array $raw): ?float
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
        $averagePrice = $this->number($this->value($row, 'Price', 'price'));
        $taxRatePermille = max(0.0, $this->number($this->value($row, 'TaxRate', 'taxRate')));
        $securityType = (string) $this->value($row, 'StkType1', 'stkType1');

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
            'averagePrice' => $averagePrice,
            'breakevenPrice' => $this->breakevenPrice(
                $costBasis,
                $quantity,
                $taxRatePermille,
                $securityType === '12',
            ),
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
                'taxRatePermille' => $taxRatePermille,
                'securityType' => $securityType,
            ],
        ];
    }

    private function breakevenPrice(float $costBasis, float $quantity, float $taxRatePermille, bool $isEtf): ?float
    {
        if ($costBasis <= 0 || $quantity <= 0) {
            return null;
        }

        $taxRate = $taxRatePermille / 1000;
        $percentageFeeDenominator = 1 - $taxRate - self::BROKERAGE_FEE_RATE;
        $minimumFeeDenominator = 1 - $taxRate;
        if ($percentageFeeDenominator <= 0 || $minimumFeeDenominator <= 0) {
            return null;
        }

        $estimatedGross = max(
            $costBasis / $percentageFeeDenominator,
            ($costBasis + self::MINIMUM_BROKERAGE_FEE) / $minimumFeeDenominator,
        );
        $candidate = $this->ceilToTradablePrice($estimatedGross / $quantity, $isEtf);

        for ($attempt = 0; $attempt < 1000 && $this->netSaleProceeds($candidate, $quantity, $taxRatePermille) < $costBasis; $attempt++) {
            $candidate = $this->nextTradablePrice($candidate, $isEtf);
        }

        for ($attempt = 0; $attempt < 1000; $attempt++) {
            $previous = $this->previousTradablePrice($candidate, $isEtf);
            if ($previous <= 0 || $this->netSaleProceeds($previous, $quantity, $taxRatePermille) < $costBasis) {
                break;
            }
            $candidate = $previous;
        }

        return round($candidate, 4);
    }

    private function netSaleProceeds(float $price, float $quantity, float $taxRatePermille): float
    {
        $gross = round($price * $quantity, 0, PHP_ROUND_HALF_UP);
        $brokerageFee = max(self::MINIMUM_BROKERAGE_FEE, floor($gross * self::BROKERAGE_FEE_RATE));
        $transactionTax = floor($gross * max(0.0, $taxRatePermille) / 1000);

        return $gross - $brokerageFee - $transactionTax;
    }

    private function ceilToTradablePrice(float $price, bool $isEtf): float
    {
        $tick = $this->priceTick($price, $isEtf);

        return round(ceil(($price - 0.000000001) / $tick) * $tick, 4);
    }

    private function nextTradablePrice(float $price, bool $isEtf): float
    {
        return round($price + $this->priceTick($price + 0.000001, $isEtf), 4);
    }

    private function previousTradablePrice(float $price, bool $isEtf): float
    {
        return round(max(0.0, $price - $this->priceTick(max(0.0, $price - 0.000001), $isEtf)), 4);
    }

    private function priceTick(float $price, bool $isEtf): float
    {
        if ($isEtf) {
            return $price < 50 ? 0.01 : 0.05;
        }

        return match (true) {
            $price < 10 => 0.01,
            $price < 50 => 0.05,
            $price < 100 => 0.1,
            $price < 500 => 0.5,
            $price < 1000 => 1.0,
            default => 5.0,
        };
    }

    private function balanceSummary(array $raw, float $totalCostBasis, ?CarbonImmutable $now = null): array
    {
        $balances = collect($raw['balance'] ?? [])->filter(fn (mixed $row): bool => is_array($row))->values();
        $availableBalance = $balances->isEmpty()
            ? null
            : $this->numberOrNull($this->value($balances->first(), 'AvailableBalance', 'availableBalance'));
        $pendingSettlementAmount = $this->pendingSettlementAmount($raw, $now);
        $bankBalance = $availableBalance === null ? null : $availableBalance + $pendingSettlementAmount;
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
            ->sum(fn (array $settlement): float => $this->number($this->value($settlement, 'SettlementAmt', 'settlementAmt')));
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
    private function historicalPrices(Collection $stockCodes, ?Collection $emergingCodes = null): array
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

        $history = $rows
            ->groupBy('stock_code')
            ->map(fn (Collection $prices): array => $this->historicalPriceSummary($prices->values(), $today, $startOfYear))
            ->all();

        $emergingLookup = ($emergingCodes ?? collect())
            ->map(fn (mixed $stockCode): string => (string) $stockCode)
            ->flip();
        foreach ($stockCodes->unique()->values() as $stockCode) {
            $stockCode = (string) $stockCode;
            if ($emergingLookup->has($stockCode) || $this->hasCompleteHistoricalReturns($history[$stockCode] ?? [])) {
                continue;
            }

            $fallback = $this->yahooHistoricalPriceSummary($stockCode, $today, $startOfYear);
            if ($fallback === null) {
                continue;
            }

            $history[$stockCode] = $this->mergeHistoricalSummary($history[$stockCode] ?? [], $fallback);
        }

        foreach (($emergingCodes ?? collect()) as $stockCode) {
            $stockCode = (string) $stockCode;
            $fallback = app(TwStockEmergingHistoryService::class)->summary(
                $stockCode,
                $today,
                (string) config('yuanta.timezone', 'Asia/Taipei'),
            );
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
            'yuanta:portfolio:yahoo-history:' . $stockCode . ':' . $today . ':v1',
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

        $timezone = (string) config('yuanta.timezone', 'Asia/Taipei');
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
            'yuanta:portfolio:twse-mis-prevclose:' . CarbonImmutable::now((string) config('yuanta.timezone', 'Asia/Taipei'))->toDateString() . ':' . sha1($codes->implode(',')) . ':v1',
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
     * @param Collection<int, array<string, mixed>> $inventories
     * @param array<string, array{exchange: string, label: string, shortLabel: string, class: string}> $metadata
     * @return array<string, array{exchange: string, label: string, shortLabel: string, class: string}>
     */
    private function mergeInventoryExchangeMetadata(Collection $inventories, array $metadata): array
    {
        foreach ($inventories as $row) {
            $stockCode = (string) $this->value($row, 'StkCode', 'stkCode', 'stockNo');
            if ($stockCode === '' || isset($metadata[$stockCode])) {
                continue;
            }

            $meta = $this->exchangeMeta((string) $this->value($row, 'MarketName', 'marketName'));
            if ($meta !== null) {
                $metadata[$stockCode] = $meta;
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

    private function integerOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
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
        $parsed = CarbonImmutable::createFromFormat('Y-m-d', $normalized, (string) config('yuanta.timezone', 'Asia/Taipei'));
        if (!$parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $normalized) {
            throw new \InvalidArgumentException('日期格式錯誤：' . $date);
        }

        return $parsed->startOfDay();
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
