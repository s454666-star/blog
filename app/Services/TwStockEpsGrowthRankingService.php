<?php

namespace App\Services;

use App\Models\TwStockDailyPrice;
use App\Models\TwStockEpsGrowthRanking;
use App\Models\TwStockEpsGrowthRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class TwStockEpsGrowthRankingService
{
    private array $actualSources = [];

    public function __construct(private readonly TwStockEpsGrowthScoringService $scoring)
    {
    }

    /**
     * @return array{run: TwStockEpsGrowthRun, top_rows: list<array<string, mixed>>}
     */
    public function refresh(
        CarbonImmutable $snapshotDate,
        int $lookbackDays,
        int $sleepMs,
        int $minimumEligible,
        bool $requireTopPrices = true,
        bool $dryRun = false,
    ): array {
        $forecastResult = $this->fetchLatestForecasts($snapshotDate, $lookbackDays);
        $forecasts = $forecastResult['forecasts'];
        if ($forecasts === []) {
            throw new RuntimeException('找不到包含 2026、2027、2028 的 FactSet EPS 預估。');
        }

        $actuals = $this->fetchActualEps(array_keys($forecasts), $sleepMs);
        $rows = $this->buildEligibleRows($forecasts, $actuals);
        if (count($rows) < $minimumEligible) {
            throw new RuntimeException(sprintf(
                '完整可比股票不足：eligible=%d minimum=%d，拒絕寫入不完整快照。',
                count($rows),
                $minimumEligible,
            ));
        }

        $eligibleCodes = array_column($rows, 'stock_code');
        $audit = [];
        foreach ($forecasts as $code => $forecast) {
            $audit[] = [
                'stock_code' => (string) $code, 'stock_name' => $forecast['stock_name'],
                'eps_2025' => $actuals[$code] ?? null,
                'actual_source' => $this->actualSources[$code] ?? '未取得完整 2025 年度實績',
                'years' => $forecast['forecast_metadata'],
                'source_review' => $forecast['source_review'] ?? null,
                'rankable' => in_array((string) $code, $eligibleCodes, true),
            ];
        }
        if ($dryRun) {
            return ['run' => null, 'top_rows' => $rows, 'audit' => $audit];
        }
        $priceMap = $this->latestPriceMap(array_column($rows, 'stock_code'), $snapshotDate);
        foreach ($rows as &$row) {
            $price = $priceMap[$row['stock_code']] ?? null;
            $row['price_date'] = $price['price_date'] ?? null;
            $row['close_price'] = $price['close_price'] ?? null;
        }
        unset($row);

        $topRows = array_slice($rows, 0, min(50, count($rows)));
        $missingTopPrices = array_values(array_map(
            fn (array $row): string => $row['stock_code'],
            array_filter($topRows, fn (array $row): bool => $row['close_price'] === null),
        ));
        if ($requireTopPrices && $missingTopPrices !== []) {
            throw new RuntimeException('前 50 名缺少收盤價：' . implode(', ', $missingTopPrices));
        }

        $previousRanks = $this->previousRanks($snapshotDate);
        foreach ($rows as &$row) {
            $previousRank = $previousRanks[$row['stock_code']] ?? null;
            $row['previous_rank'] = $previousRank;
            $row['rank_change'] = $previousRank === null ? null : $previousRank - $row['rank'];
        }
        unset($row);
        $topRows = array_slice($rows, 0, min(50, count($rows)));

        $priceDates = array_values(array_filter(array_column($topRows, 'price_date')));
        sort($priceDates);
        $run = DB::transaction(function () use ($snapshotDate, $forecastResult, $rows, $priceDates, $audit): TwStockEpsGrowthRun {
            $run = TwStockEpsGrowthRun::query()->create([
                'forecast_audit' => $audit,
                'snapshot_date' => $snapshotDate->toDateString(),
                'price_date' => $priceDates === [] ? null : end($priceDates),
                'base_year' => 2025,
                'forecast_year_1' => 2026,
                'forecast_year_2' => 2027,
                'forecast_year_3' => 2028,
                'article_count' => $forecastResult['article_count'],
                'forecast_count' => count($forecastResult['forecasts']),
                'eligible_count' => count($rows),
                'top_count' => min(50, count($rows)),
                'completed_at' => now(),
            ]);

            $now = now();
            foreach (array_chunk($rows, 250) as $chunk) {
                TwStockEpsGrowthRanking::query()->insert(array_map(function (array $row) use ($run, $now): array {
                    return [
                        'run_id' => $run->id,
                        'rank' => $row['rank'],
                        'previous_rank' => $row['previous_rank'],
                        'rank_change' => $row['rank_change'],
                        'stock_code' => $row['stock_code'],
                        'stock_name' => $row['stock_name'],
                        'forecast_metadata' => json_encode($row['forecast_metadata'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                        'eps_2025' => $row['eps_2025'],
                        'eps_2026' => $row['eps_2026'],
                        'eps_2027' => $row['eps_2027'],
                        'eps_2028' => $row['eps_2028'],
                        'growth_2025_2026' => $row['growth_2025_2026'],
                        'growth_2026_2027' => $row['growth_2026_2027'],
                        'growth_2027_2028' => $row['growth_2027_2028'],
                        'growth_sum' => $row['growth_sum'],
                        'weighted_score' => $row['weighted_score'],
                        'is_neutral_estimate' => $row['is_neutral_estimate'],
                        'revenue_2026_thousands' => $row['revenue_2026_thousands'],
                        'revenue_2027_thousands' => $row['revenue_2027_thousands'],
                        'revenue_2028_thousands' => $row['revenue_2028_thousands'],
                        'price_date' => $row['price_date'],
                        'close_price' => $row['close_price'],
                        'analyst_count' => $row['analyst_count'],
                        'forecast_date' => $row['forecast_date'],
                        'news_id' => $row['news_id'],
                        'low_base' => $row['low_base'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }, $chunk));
            }

            return $run;
        });

        return [
            'run' => $run,
            'top_rows' => $topRows,
        ];
    }

    /**
     * @return array{article_count: int, forecasts: array<string, array<string, mixed>>}
     */
    private function fetchLatestForecasts(CarbonImmutable $snapshotDate, int $lookbackDays): array
    {
        $articles = [];
        $cursor = $snapshotDate->subDays(max(35, $lookbackDays))->startOfDay();
        $endExclusive = $snapshotDate->addDay()->startOfDay();
        $url = (string) config('tw_stock.eps_growth_ranking.cnyes_url');

        while ($cursor->lessThan($endExclusive)) {
            $windowEnd = $cursor->addDays(35);
            if ($windowEnd->greaterThan($endExclusive)) {
                $windowEnd = $endExclusive;
            }

            for ($page = 1; $page <= 10; $page++) {
                try {
                    $payload = $this->http()->get($url, [
                        'startAt' => $cursor->timestamp,
                        'endAt' => $windowEnd->timestamp - 1,
                        'limit' => 100,
                        'page' => $page,
                    ])->throw()->json();
                } catch (Throwable $exception) {
                    throw new RuntimeException('鉅亨 FactSet 清單取得失敗：' . $exception->getMessage(), 0, $exception);
                }

                $pageRows = data_get($payload, 'items.data', []);
                if (!is_array($pageRows) || $pageRows === []) {
                    break;
                }

                foreach ($pageRows as $article) {
                    if (!is_array($article) || !isset($article['newsId'])) {
                        continue;
                    }
                    $articles[(string) $article['newsId']] = $article;
                }

                $lastPage = (int) data_get($payload, 'items.last_page', $page);
                if ($page >= $lastPage) {
                    break;
                }
            }

            $cursor = $windowEnd;
        }

        $latestRun = TwStockEpsGrowthRun::query()->whereNotNull('completed_at')->orderByDesc('snapshot_date')->orderByDesc('id')->first();
        $universe = $latestRun?->rankings()->pluck('stock_name', 'stock_code')->all() ?? [];
        $previousReviews = [];
        foreach ($latestRun?->forecast_audit ?? [] as $audited) {
            $previousReviews[$audited['stock_code']] = $audited['source_review'] ?? [];
            $universe[$audited['stock_code']] = $audited['stock_name'];
        }
        $forecasts = app(TwStockEpsForecastSource::class)->latest(array_values($articles), $universe, $snapshotDate, $previousReviews);
        return ['article_count' => count($articles), 'forecasts' => $forecasts];
    }

    /**
     * @param list<string> $stockCodes
     * @return array<string, float>
     */
    private function fetchActualEps(array $stockCodes, int $sleepMs): array
    {
        $actuals = [];
        $this->actualSources = [];
        $url = (string) config('tw_stock.eps_growth_ranking.finmind_url');
        sort($stockCodes);

        foreach ($stockCodes as $index => $stockCode) {
            if ($sleepMs > 0 && $index > 0) {
                usleep($sleepMs * 1000);
            }

            try {
                $payload = $this->http()->get($url, [
                    'dataset' => 'TaiwanStockFinancialStatements',
                    'data_id' => $stockCode,
                    'start_date' => '2025-01-01',
                    'end_date' => '2025-12-31',
                ])->throw()->json();
            } catch (Throwable) {
                continue;
            }

            $epsRows = array_values(array_filter(
                is_array($payload['data'] ?? null) ? $payload['data'] : [],
                fn (mixed $row): bool => is_array($row)
                    && ($row['type'] ?? null) === 'EPS'
                    && str_starts_with((string) ($row['date'] ?? ''), '2025-')
                    && is_numeric($row['value'] ?? null),
            ));
            $quarters = array_unique(array_map(fn ($row) => isset($row['date']) ? CarbonImmutable::parse($row['date'])->format('Y').'-'.CarbonImmutable::parse($row['date'])->quarter : '', $epsRows));
            if (count($epsRows) !== 4 || count($quarters) !== 4) {
                continue;
            }

            $this->actualSources[$stockCode] = 'FinMind TaiwanStockFinancialStatements：2025 Q1–Q4 公告 EPS 加總（元／股）';
            $actuals[$stockCode] = round(array_sum(array_map(
                fn (array $row): float => (float) $row['value'],
                $epsRows,
            )), 4);
        }

        $configuredForecasts = config('tw_stock.eps_growth_ranking.manual_neutral_forecasts', []);
        foreach ($stockCodes as $stockCode) {
            $configuredActual = $configuredForecasts[$stockCode]['eps_2025'] ?? null;
            if (!array_key_exists($stockCode, $actuals) && is_numeric($configuredActual) && (float) $configuredActual > 0) {
                $actuals[$stockCode] = round((float) $configuredActual, 4);
                $this->actualSources[$stockCode] = '既有年度實績參考（FinMind 四季不完整）：'.($configuredForecasts[$stockCode]['source_url'] ?? '未註明');
            }
        }

        return $actuals;
    }

    /**
     * @param array<string, array<string, mixed>> $forecasts
     * @param array<string, float> $actuals
     * @return list<array<string, mixed>>
     */
    private function buildEligibleRows(array $forecasts, array $actuals): array
    {
        $rows = [];
        foreach ($forecasts as $stockCode => $forecast) {
            $eps2025 = $actuals[$stockCode] ?? null;
            if ($eps2025 === null || $eps2025 <= 0) {
                continue;
            }

            if (count(array_filter($forecast['forecast_metadata'] ?? [], fn ($year) => $year['status'] === 'current')) !== 3) {
                continue;
            }
            $eps2026 = (float) $forecast['eps_2026'];
            $eps2027 = (float) $forecast['eps_2027'];
            $eps2028 = (float) $forecast['eps_2028'];
            if (min($eps2026, $eps2027, $eps2028) <= 0) {
                continue;
            }

            $growth1 = (($eps2026 / $eps2025) - 1) * 100;
            $growth2 = (($eps2027 / $eps2026) - 1) * 100;
            $growth3 = (($eps2028 / $eps2027) - 1) * 100;
            $rows[] = [
                ...$forecast,
                'eps_2025' => $eps2025,
                'growth_2025_2026' => round($growth1, 4),
                'growth_2026_2027' => round($growth2, 4),
                'growth_2027_2028' => round($growth3, 4),
                'growth_sum' => round($growth1 + $growth2 + $growth3, 4),
                'low_base' => $eps2025 < 1,
            ];
        }

        return $this->scoring->scoreAndRank($rows);
    }

    /**
     * @param list<string> $stockCodes
     * @return array<string, array{price_date: string, close_price: float}>
     */
    private function latestPriceMap(array $stockCodes, CarbonImmutable $snapshotDate): array
    {
        $prices = [];
        foreach ($stockCodes as $stockCode) {
            $row = TwStockDailyPrice::query()
                ->where('stock_code', $stockCode)
                ->whereDate('trade_date', '<=', $snapshotDate->toDateString())
                ->whereNotNull('close_price')
                ->orderByDesc('trade_date')
                ->orderByDesc('id')
                ->first();
            if ($row === null) {
                continue;
            }

            $prices[$stockCode] = [
                'price_date' => $row->trade_date->toDateString(),
                'close_price' => (float) $row->close_price,
            ];
        }

        return $prices;
    }

    /**
     * @return array<string, int>
     */
    private function previousRanks(CarbonImmutable $snapshotDate): array
    {
        $previousRun = TwStockEpsGrowthRun::query()
            ->whereDate('snapshot_date', '<', $snapshotDate->toDateString())
            ->whereNotNull('completed_at')
            ->orderByDesc('snapshot_date')
            ->orderByDesc('id')
            ->first();
        if ($previousRun === null) {
            return [];
        }

        return $previousRun->rankings()
            ->pluck('rank', 'stock_code')
            ->map(fn (mixed $rank): int => (int) $rank)
            ->all();
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::acceptJson()
            ->withUserAgent('Mozilla/5.0 (compatible; mystar.tw-stock-eps-growth/1.0)')
            ->timeout(35)
            ->retry(3, 500);
    }
}
