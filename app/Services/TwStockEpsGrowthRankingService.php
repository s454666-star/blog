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
        $run = DB::transaction(function () use ($snapshotDate, $forecastResult, $rows, $priceDates): TwStockEpsGrowthRun {
            $run = TwStockEpsGrowthRun::query()->create([
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
     * @return list<array<string, mixed>>
     */
    public function neutralEstimateRows(
        CarbonImmutable $snapshotDate,
        int $lookbackDays,
        int $sleepMs,
    ): array {
        $forecastResult = $this->fetchLatestForecasts($snapshotDate, $lookbackDays);
        $forecasts = array_filter(
            $forecastResult['forecasts'],
            fn (array $forecast): bool => (bool) ($forecast['is_neutral_estimate'] ?? false),
        );
        if ($forecasts === []) {
            return [];
        }

        $actuals = $this->fetchActualEps(array_keys($forecasts), $sleepMs);
        $rows = $this->buildEligibleRows($forecasts, $actuals);
        $priceMap = $this->latestPriceMap(array_column($rows, 'stock_code'), $snapshotDate);
        foreach ($rows as &$row) {
            $price = $priceMap[$row['stock_code']] ?? null;
            $row['price_date'] = $price['price_date'] ?? null;
            $row['close_price'] = $price['close_price'] ?? null;
        }
        unset($row);

        return $rows;
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

        $latest = [];
        foreach ($articles as $article) {
            $parsed = $this->parseForecastArticle($article);
            if ($parsed === null) {
                continue;
            }

            $code = $parsed['stock_code'];
            if (!isset($latest[$code]) || $parsed['publish_at'] > $latest[$code]['publish_at']) {
                $latest[$code] = $parsed;
            }
        }

        return [
            'article_count' => count($articles),
            'forecasts' => $latest,
        ];
    }

    /**
     * @param array<string, mixed> $article
     * @return array<string, mixed>|null
     */
    private function parseForecastArticle(array $article): ?array
    {
        $title = (string) ($article['title'] ?? '');
        if (preg_match('/\((\d{4})-TW\).*EPS預估/u', $title, $codeMatch) !== 1) {
            return null;
        }

        $html = html_entity_decode((string) ($article['content'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!str_contains($html, '<table')) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        preg_match_all('/<table\b[^>]*>.*?<\/table>/si', $html, $tableMatches);
        $tables = $tableMatches[0] ?? [];
        if (count($tables) < 2) {
            return null;
        }

        $headerRows = $this->tableRows($tables[0]);
        $header = $headerRows[0] ?? [];
        $isCompleteForecast = count($header) >= 4
            && preg_match('/^2026年/u', $header[1]) === 1
            && preg_match('/^2027年/u', $header[2]) === 1
            && preg_match('/^2028年/u', $header[3]) === 1;
        $isNeutralEstimate = in_array(
            $codeMatch[1],
            config('tw_stock.eps_growth_ranking.neutral_estimate_stock_codes', []),
            true,
        )
            && count($header) >= 4
            && preg_match('/^2025年/u', $header[1]) === 1
            && preg_match('/^2026年/u', $header[2]) === 1
            && preg_match('/^2027年/u', $header[3]) === 1;
        if (!$isCompleteForecast && !$isNeutralEstimate) {
            return null;
        }

        $eps = $this->medianValues($tables[0]);
        if ($eps === null || min($eps) <= 0) {
            return null;
        }
        $revenue = $this->medianValues($tables[1]);

        $eps2026 = $isNeutralEstimate ? $eps[1] : $eps[0];
        $eps2027 = $isNeutralEstimate ? $eps[2] : $eps[1];
        $eps2028 = $isNeutralEstimate
            ? round($eps2027 * (1 + $this->neutral2028Growth($eps2026, $eps2027)), 4)
            : $eps[2];
        $revenue2026 = $revenue === null ? null : ($isNeutralEstimate ? $revenue[1] : $revenue[0]);
        $revenue2027 = $revenue === null ? null : ($isNeutralEstimate ? $revenue[2] : $revenue[1]);
        $revenue2028 = $revenue === null
            ? null
            : ($isNeutralEstimate
                ? $revenue2027 * (1 + $this->neutral2028Growth($revenue2026, $revenue2027))
                : $revenue[2]);

        $analystCount = null;
        if (preg_match('/共(\d+)位分析師/u', strip_tags($html), $analystMatch) === 1) {
            $analystCount = (int) $analystMatch[1];
        }

        $stockName = $codeMatch[1];
        if (preg_match('/調查：(.+?)\(/u', $title, $nameMatch) === 1) {
            $stockName = trim($nameMatch[1]);
        }

        $publishAt = (int) ($article['publishAt'] ?? 0);

        return [
            'stock_code' => $codeMatch[1],
            'stock_name' => $stockName,
            'publish_at' => $publishAt,
            'forecast_date' => $publishAt > 0
                ? CarbonImmutable::createFromTimestampUTC($publishAt)->setTimezone('Asia/Taipei')->toDateString()
                : null,
            'news_id' => isset($article['newsId']) ? (int) $article['newsId'] : null,
            'analyst_count' => $analystCount,
            'eps_2026' => $eps2026,
            'eps_2027' => $eps2027,
            'eps_2028' => $eps2028,
            'revenue_2026_thousands' => $revenue2026 === null ? null : (int) round($revenue2026),
            'revenue_2027_thousands' => $revenue2027 === null ? null : (int) round($revenue2027),
            'revenue_2028_thousands' => $revenue2028 === null ? null : (int) round($revenue2028),
            'is_neutral_estimate' => $isNeutralEstimate,
        ];
    }

    private function neutral2028Growth(float $earlierValue, float $laterValue): float
    {
        if ($earlierValue <= 0 || $laterValue <= 0) {
            return 0.0;
        }

        $growth = (($laterValue / $earlierValue) - 1)
            * (float) config('tw_stock.eps_growth_ranking.neutral_2028_growth_retention', 0.5);
        $minimum = (float) config('tw_stock.eps_growth_ranking.neutral_2028_growth_min', 0.0);
        $maximum = (float) config('tw_stock.eps_growth_ranking.neutral_2028_growth_max', 0.3);

        return min($maximum, max($minimum, $growth));
    }

    /**
     * @return list<list<string>>
     */
    private function tableRows(string $table): array
    {
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/si', $table, $rowMatches);
        $rows = [];
        foreach ($rowMatches[1] ?? [] as $rowHtml) {
            preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/si', $rowHtml, $cellMatches);
            $rows[] = array_map(function (string $cell): string {
                $text = html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return trim((string) preg_replace('/\s+/u', ' ', $text));
            }, $cellMatches[1] ?? []);
        }

        return $rows;
    }

    /**
     * @return array{float, float, float}|null
     */
    private function medianValues(string $table): ?array
    {
        foreach ($this->tableRows($table) as $cells) {
            if (count($cells) < 4 || $cells[0] !== '中位數') {
                continue;
            }

            $values = [
                $this->number($cells[1]),
                $this->number($cells[2]),
                $this->number($cells[3]),
            ];
            if (in_array(null, $values, true)) {
                return null;
            }

            return [$values[0], $values[1], $values[2]];
        }

        return null;
    }

    private function number(mixed $value): ?float
    {
        $text = str_replace(',', '', trim((string) $value));
        if (preg_match('/^-?\d+(?:\.\d+)?/', $text, $matches) !== 1) {
            return null;
        }

        return (float) $matches[0];
    }

    /**
     * @param list<string> $stockCodes
     * @return array<string, float>
     */
    private function fetchActualEps(array $stockCodes, int $sleepMs): array
    {
        $actuals = [];
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
                    && is_numeric($row['value'] ?? null),
            ));
            if (count($epsRows) !== 4) {
                continue;
            }

            $actuals[$stockCode] = round(array_sum(array_map(
                fn (array $row): float => (float) $row['value'],
                $epsRows,
            )), 4);
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
