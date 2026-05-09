<?php

namespace App\Console\Commands;

use App\Models\TwStockQ1FinancialReport;
use App\Services\TwStockQ1FinancialReportFetcher;
use App\Services\TwStockQ1ValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class FetchTwStockQ1FinancialReportsCommand extends Command
{
    protected $signature = 'tw-stock:fetch-q1-financial-reports
        {--year= : 財報年度，預設今年}
        {--quarter=1 : 財報季別，預設 Q1}
        {--backfill-years= : 含財報年度往回抓幾年，未指定時只抓單一年度}
        {--all-quarters : 抓年度範圍內的 Q1~Q4}
        {--monthly-revenue-months=60 : 每檔股票保留近幾個月營收 JSON}
        {--skip-market-data-refresh : 只用官方即時報價候選資料，不逐檔刷新日 K 漲跌幅}
        {--skip-announcement-fallbacks : 只用 nStock 季財報資料，不查重大訊息公告 fallback}
        {--keep-missing : 不刪除本次未抓到的同年季既有資料}
        {--monthly-revenue-only : 只刷新已存在資料列的月營收 JSON}
        {--market-data-only : 只刷新已存在資料列的最新股價、成交量、1/5/20 日漲幅}
        {--shard-count=1 : 將股票候選切成幾份並行處理}
        {--shard-index=0 : 目前處理第幾份，從 0 開始}
        {--min-volume-lots=1000 : 排除低量股票，最新日成交量至少幾張}
        {--sleep-ms=80 : 對公開 API 的單檔節流毫秒數}
        {--skip-non-trading-day : 如果公開報價資料不是今天，視為非交易日並略過}
        {--limit= : 限制候選股票數，測試用}
        {--export-json= : 將入庫後資料匯出成 JSON 檔案路徑}';

    protected $description = '抓取台股財報、成交量、股價漲跌幅，計算財報評分並入庫。';

    private ?bool $valuationColumnsExist = null;

    public function handle(TwStockQ1FinancialReportFetcher $fetcher, TwStockQ1ValuationService $valuationService): int
    {
        $year = $this->option('year') !== null && $this->option('year') !== ''
            ? (int) $this->option('year')
            : (int) now()->year;
        $quarter = max(1, min(4, (int) $this->option('quarter')));
        $minVolumeLots = max(0, (int) $this->option('min-volume-lots'));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $limit = $this->option('limit') !== null && $this->option('limit') !== ''
            ? max(1, (int) $this->option('limit'))
            : null;
        $monthlyRevenueMonths = max(1, (int) $this->option('monthly-revenue-months'));
        $years = $this->yearsToFetch($year);
        $quarters = (bool) $this->option('all-quarters')
            ? [1, 2, 3, 4]
            : [$quarter];
        $refreshMarketData = !(bool) $this->option('skip-market-data-refresh');
        $useAnnouncementFallbacks = !(bool) $this->option('skip-announcement-fallbacks');
        $shardCount = max(1, (int) $this->option('shard-count'));
        $shardIndex = max(0, min($shardCount - 1, (int) $this->option('shard-index')));
        $deleteMissing = !(bool) $this->option('keep-missing') && $shardCount === 1;

        if ((bool) $this->option('skip-non-trading-day') && !$fetcher->hasExpectedLatestOfficialQuote()) {
            $this->info(sprintf(
                '略過：官方報價資料尚未更新到 %s，視為非交易日或資料未完成。',
                $fetcher->expectedLatestTradingDate(),
            ));

            return self::SUCCESS;
        }

        if ((bool) $this->option('monthly-revenue-only')) {
            $updated = $this->refreshMonthlyRevenueRows($fetcher, $years, $quarters, $monthlyRevenueMonths, $sleepMs, $shardCount, $shardIndex);
            $this->info(sprintf(
                '完成：updated_monthly_revenue_rows=%d years=%s quarters=%s monthly_revenue_months=%d shard=%d/%d',
                $updated,
                implode(',', $years),
                implode(',', $quarters),
                $monthlyRevenueMonths,
                $shardIndex,
                $shardCount,
            ));

            return self::SUCCESS;
        }

        if ((bool) $this->option('market-data-only')) {
            $result = $this->refreshMarketDataRows($fetcher, $years, $quarters, $minVolumeLots, $sleepMs, $shardCount, $shardIndex);
            $reranked = $shardCount === 1
                ? $this->rerankStoredRows($years, $quarters, $minVolumeLots, $valuationService)
                : 0;

            $this->info(sprintf(
                '完成：updated_market_data_rows=%d deleted_rows=%d reranked_rows=%d years=%s quarters=%s min_volume_lots=%d shard=%d/%d',
                $result['updated'],
                $result['deleted'],
                $reranked,
                implode(',', $years),
                implode(',', $quarters),
                $minVolumeLots,
                $shardIndex,
                $shardCount,
            ));

            return self::SUCCESS;
        }

        $totalSaved = 0;
        foreach ($years as $targetYear) {
            foreach ($this->quartersToFetch($targetYear, $quarters) as $targetQuarter) {
                try {
                    $payloads = $fetcher->fetch(
                        $targetYear,
                        $targetQuarter,
                        $minVolumeLots,
                        $sleepMs,
                        $limit,
                        $monthlyRevenueMonths,
                        $refreshMarketData,
                        $useAnnouncementFallbacks,
                        $shardCount,
                        $shardIndex,
                    );
                } catch (Throwable $e) {
                    report($e);
                    $this->error('抓取失敗：' . $e->getMessage());

                    return self::FAILURE;
                }

                $totalSaved += $this->storePayloads($payloads, $targetYear, $targetQuarter, !$refreshMarketData, $deleteMissing);
            }
        }

        if ($this->option('export-json')) {
            $this->exportJson((string) $this->option('export-json'), $year, $quarter);
        }

        $this->newLine();
        $this->info(sprintf(
            '完成：saved=%d years=%s quarters=%s min_volume_lots=%d monthly_revenue_months=%d market_data=%s announcement_fallbacks=%s delete_missing=%s shard=%d/%d',
            $totalSaved,
            implode(',', $years),
            implode(',', $quarters),
            $minVolumeLots,
            $monthlyRevenueMonths,
            $refreshMarketData ? 'refreshed' : 'quote-only',
            $useAnnouncementFallbacks ? 'enabled' : 'disabled',
            $deleteMissing ? 'enabled' : 'disabled',
            $shardIndex,
            $shardCount,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function yearsToFetch(int $year): array
    {
        $backfillYears = $this->option('backfill-years') !== null && $this->option('backfill-years') !== ''
            ? max(1, (int) $this->option('backfill-years'))
            : 1;

        return range($year, $year - $backfillYears + 1);
    }

    /**
     * @param list<int> $quarters
     * @return list<int>
     */
    private function quartersToFetch(int $year, array $quarters): array
    {
        $timezone = (string) config('app.timezone');
        $today = CarbonImmutable::now($timezone);

        return array_values(array_filter($quarters, function (int $quarter) use ($year, $timezone, $today): bool {
            $quarterEnd = CarbonImmutable::create($year, $quarter * 3, 1, 0, 0, 0, $timezone)
                ->endOfMonth();

            return $quarterEnd->lessThanOrEqualTo($today);
        }));
    }

    /**
     * @param list<array<string, mixed>> $payloads
     */
    private function storePayloads(
        array $payloads,
        int $year,
        int $quarter,
        bool $preserveEmptyMarketData = false,
        bool $deleteMissing = true,
    ): int {
        $keptIds = [];
        foreach ($payloads as $payload) {
            $updatePayload = $payload;
            $updatePayload = $this->filterPayloadForCurrentSchema($updatePayload);
            if ($preserveEmptyMarketData) {
                foreach (['price_change_1d_percent', 'price_change_5d_percent', 'price_change_20d_percent'] as $field) {
                    if (($updatePayload[$field] ?? null) === null) {
                        unset($updatePayload[$field]);
                    }
                }
            }

            $unique = [
                'fiscal_year' => $payload['fiscal_year'],
                'quarter' => $payload['quarter'],
                'exchange' => $payload['exchange'],
                'stock_code' => $payload['stock_code'],
            ];
            $lockKey = sprintf(
                'tw-stock-q1-financial-report:%d:%d:%s:%s',
                $payload['fiscal_year'],
                $payload['quarter'],
                $payload['exchange'],
                $payload['stock_code'],
            );
            $model = Cache::lock($lockKey, 120)->block(10, function () use ($unique, $updatePayload): TwStockQ1FinancialReport {
                $model = TwStockQ1FinancialReport::query()->firstOrNew($unique);
                if ($this->monthlyRevenueRowCount($model->recent_monthly_revenues) > $this->monthlyRevenueRowCount($updatePayload['recent_monthly_revenues'] ?? null)) {
                    unset($updatePayload['recent_monthly_revenues']);
                }

                $model->fill($updatePayload);
                $model->save();

                return $model;
            });

            $keptIds[] = $model->id;
            $this->info(sprintf(
                '#%d %s %s saved: Y%d Q%d 營收 %s 億, YoY %s%%, 評分 %s, 股價 %s, 量 %s 張',
                $payload['rank'],
                $payload['stock_code'],
                $payload['stock_name'],
                $payload['fiscal_year'],
                $payload['quarter'],
                $this->number($payload['q1_revenue_billion'] ?? null, 2),
                $this->number($payload['q1_revenue_yoy_percent'] ?? null, 2),
                $this->number($payload['q1_revenue_score'] ?? null, 2),
                $this->number($payload['latest_close_price'] ?? null, 2),
                number_format((int) ($payload['volume_lots'] ?? 0)),
            ));
        }

        if ($deleteMissing && $keptIds !== []) {
            TwStockQ1FinancialReport::query()
                ->where('fiscal_year', $year)
                ->where('quarter', $quarter)
                ->whereNotIn('id', $keptIds)
                ->delete();
        }

        return count($payloads);
    }

    /**
     * @param list<int> $years
     * @param list<int> $quarters
     */
    private function refreshMonthlyRevenueRows(
        TwStockQ1FinancialReportFetcher $fetcher,
        array $years,
        array $quarters,
        int $monthlyRevenueMonths,
        int $sleepMs,
        int $shardCount = 1,
        int $shardIndex = 0,
    ): int {
        $updated = 0;
        $pairs = [];
        foreach ($years as $year) {
            foreach ($this->quartersToFetch($year, $quarters) as $quarter) {
                $pairs[] = [$year, $quarter];
            }
        }

        if ($pairs === []) {
            return 0;
        }

        $this->constrainYearQuarterPairs(
            TwStockQ1FinancialReport::query()->select('stock_code')->distinct(),
            $pairs,
        )
            ->orderBy('stock_code')
            ->chunk(200, function ($rows) use ($fetcher, $monthlyRevenueMonths, $sleepMs, $shardCount, $shardIndex, $pairs, &$updated): void {
                foreach ($rows as $row) {
                    $stockCode = (string) $row->stock_code;
                    if (!$this->stockCodeBelongsToShard($stockCode, $shardCount, $shardIndex)) {
                        continue;
                    }

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }

                    $monthlyRows = $fetcher->fetchRecentMonthlyRevenueRows($stockCode, $monthlyRevenueMonths);
                    if ($monthlyRows === []) {
                        continue;
                    }

                    $monthlyRowsCount = count($monthlyRows);
                    $updated += $this->constrainYearQuarterPairs(
                        TwStockQ1FinancialReport::query()->where('stock_code', $stockCode),
                        $pairs,
                    )
                        ->where(function (Builder $query) use ($monthlyRowsCount): void {
                            $query->whereNull('recent_monthly_revenues')
                                ->orWhereRaw('JSON_LENGTH(recent_monthly_revenues) <= ?', [$monthlyRowsCount]);
                        })
                        ->update([
                            'recent_monthly_revenues' => json_encode($monthlyRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                            'fetched_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            });

        return $updated;
    }

    /**
     * @param list<int> $years
     * @param list<int> $quarters
     * @return array{updated: int, deleted: int}
     */
    private function refreshMarketDataRows(
        TwStockQ1FinancialReportFetcher $fetcher,
        array $years,
        array $quarters,
        int $minVolumeLots,
        int $sleepMs,
        int $shardCount = 1,
        int $shardIndex = 0,
    ): array {
        $updated = 0;
        $deleted = 0;
        $pairs = [];
        foreach ($years as $year) {
            foreach ($this->quartersToFetch($year, $quarters) as $quarter) {
                $pairs[] = [$year, $quarter];
            }
        }

        if ($pairs === []) {
            return ['updated' => 0, 'deleted' => 0];
        }

        $expectedLatestTradingDate = $fetcher->expectedLatestTradingDate();
        $this->constrainYearQuarterPairs(TwStockQ1FinancialReport::query(), $pairs)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($fetcher, $minVolumeLots, $sleepMs, $shardCount, $shardIndex, $expectedLatestTradingDate, &$updated, &$deleted): void {
                foreach ($rows as $row) {
                    $stockCode = (string) $row->stock_code;
                    if (!$this->stockCodeBelongsToShard($stockCode, $shardCount, $shardIndex)) {
                        continue;
                    }

                    if ($sleepMs > 0) {
                        usleep($sleepMs * 1000);
                    }

                    $marketData = $fetcher->fetchMarketData($this->marketDataCandidate($row));
                    $hasRequiredMarketData = ($marketData['latest_price_date'] ?? null) !== null
                        && (string) ($marketData['latest_price_date'] ?? '') === $expectedLatestTradingDate
                        && ($marketData['price_change_1d_percent'] ?? null) !== null
                        && ($marketData['price_change_5d_percent'] ?? null) !== null
                        && ($marketData['price_change_20d_percent'] ?? null) !== null
                        && (int) ($marketData['volume_lots'] ?? 0) >= $minVolumeLots;

                    if (!$hasRequiredMarketData) {
                        $row->delete();
                        $deleted++;

                        continue;
                    }

                    $sourcePayload = is_array($row->source_payload) ? $row->source_payload : [];
                    $sourcePayload['daily_price_source'] = $marketData['daily_price_source'];
                    $sourcePayload['latest_daily_rows'] = array_slice($marketData['latest_daily_rows'], 0, 21);
                    $sourcePayload['official_quote_row'] = $marketData['official_quote_row'] ?? ($sourcePayload['official_quote_row'] ?? null);
                    $sourcePayload['quote_source'] = $marketData['official_quote_source'] ?? ($sourcePayload['quote_source'] ?? null);
                    $profile = $fetcher->fetchCompanyProfile((string) $row->exchange, $stockCode);
                    $industry = $row->industry ?: ($profile['industry'] ?? null);
                    $stockName = $profile['stock_name'] ?? (string) $row->stock_name;

                    $row->forceFill([
                        'stock_name' => $stockName,
                        'industry' => $industry,
                        'latest_close_price' => $marketData['latest_close_price'],
                        'latest_price_date' => $marketData['latest_price_date'],
                        'volume_lots' => $marketData['volume_lots'],
                        'price_change_1d_percent' => $marketData['price_change_1d_percent'],
                        'price_change_5d_percent' => $marketData['price_change_5d_percent'],
                        'price_change_20d_percent' => $marketData['price_change_20d_percent'],
                        'source_payload' => $sourcePayload,
                        'fetched_at' => now(),
                    ])->save();
                    $updated++;
                }
            });

        return ['updated' => $updated, 'deleted' => $deleted];
    }

    private function marketDataCandidate(TwStockQ1FinancialReport $row): array
    {
        $sourcePayload = is_array($row->source_payload) ? $row->source_payload : [];

        return [
            'exchange' => (string) $row->exchange,
            'stock_code' => (string) $row->stock_code,
            'stock_name' => (string) $row->stock_name,
            'latest_close_price' => $row->latest_close_price,
            'latest_price_date' => $row->latest_price_date,
            'volume_lots' => $row->volume_lots,
            'source_payload' => $sourcePayload,
        ];
    }

    /**
     * @param list<int> $years
     * @param list<int> $quarters
     */
    private function rerankStoredRows(array $years, array $quarters, int $minVolumeLots, TwStockQ1ValuationService $valuationService): int
    {
        $updated = 0;
        foreach ($years as $year) {
            foreach ($this->quartersToFetch($year, $quarters) as $quarter) {
                $rows = TwStockQ1FinancialReport::query()
                    ->where('fiscal_year', $year)
                    ->where('quarter', $quarter)
                    ->where('volume_lots', '>=', $minVolumeLots)
                    ->whereNotNull('q1_revenue_score')
                    ->whereNotNull('q1_eps')
                    ->whereNotNull('price_change_1d_percent')
                    ->whereNotNull('price_change_5d_percent')
                    ->whereNotNull('price_change_20d_percent')
                    ->orderByDesc('q1_revenue_score')
                    ->orderByDesc('q1_eps_yoy_percent')
                    ->orderByDesc('q1_operating_margin_percent')
                    ->orderByDesc('q1_gross_margin_percent')
                    ->orderByDesc('q1_net_margin_percent')
                    ->orderBy('stock_code')
                    ->get();

                foreach ($rows as $index => $row) {
                    $valuation = $valuationService->valuationForModel($row);
                    $row->forceFill($this->filterPayloadForCurrentSchema([
                        'rank' => $index + 1,
                        'valuation_group' => $valuation['valuation_group'],
                        'valuation_group_pe' => $valuation['valuation_group_pe'],
                    ]))->save();
                    $updated++;
                }
            }
        }

        return $updated;
    }

    /**
     * @param list<array{0: int, 1: int}> $pairs
     */
    private function constrainYearQuarterPairs(Builder $query, array $pairs): Builder
    {
        return $query->where(function (Builder $query) use ($pairs): void {
            foreach ($pairs as [$year, $quarter]) {
                $query->orWhere(function (Builder $query) use ($year, $quarter): void {
                    $query->where('fiscal_year', $year)
                        ->where('quarter', $quarter);
                });
            }
        });
    }

    private function stockCodeBelongsToShard(string $stockCode, int $shardCount, int $shardIndex): bool
    {
        if ($shardCount <= 1) {
            return true;
        }

        return ((int) sprintf('%u', crc32($stockCode)) % $shardCount) === $shardIndex;
    }

    private function monthlyRevenueRowCount(mixed $monthlyRows): int
    {
        if (is_array($monthlyRows)) {
            return count($monthlyRows);
        }

        if (!is_string($monthlyRows) || trim($monthlyRows) === '') {
            return 0;
        }

        try {
            $decoded = json_decode($monthlyRows, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return 0;
        }

        return is_array($decoded) ? count($decoded) : 0;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function filterPayloadForCurrentSchema(array $payload): array
    {
        if ($this->hasValuationColumns()) {
            return $payload;
        }

        unset($payload['valuation_group'], $payload['valuation_group_pe']);

        return $payload;
    }

    private function hasValuationColumns(): bool
    {
        if ($this->valuationColumnsExist !== null) {
            return $this->valuationColumnsExist;
        }

        try {
            $this->valuationColumnsExist = Schema::hasColumn('tw_stock_q1_financial_reports', 'valuation_group')
                && Schema::hasColumn('tw_stock_q1_financial_reports', 'valuation_group_pe');
        } catch (Throwable) {
            $this->valuationColumnsExist = false;
        }

        return $this->valuationColumnsExist;
    }

    private function exportJson(string $path, int $year, int $quarter): void
    {
        $fullPath = base_path($path);
        File::ensureDirectoryExists(dirname($fullPath));

        $rows = TwStockQ1FinancialReport::query()
            ->where('fiscal_year', $year)
            ->where('quarter', $quarter)
            ->orderByDesc('q1_revenue_score')
            ->orderBy('rank')
            ->get()
            ->toArray();

        File::put($fullPath, json_encode([
            'generated_at' => now()->toDateTimeString(),
            'fiscal_year' => $year,
            'quarter' => $quarter,
            'score_formula' => 'EPS YoY分位數 35% + 毛利率分位數 25% + 營益率分位數 25% + 淨利率分位數 15%',
            'rows' => $rows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        $this->info('JSON exported: ' . $fullPath);
    }

    private function number(mixed $value, int $decimals): string
    {
        return $value === null ? 'N/A' : number_format((float) $value, $decimals);
    }
}
