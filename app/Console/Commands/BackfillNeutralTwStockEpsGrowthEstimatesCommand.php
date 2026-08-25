<?php

namespace App\Console\Commands;

use App\Models\TwStockEpsGrowthRanking;
use App\Models\TwStockEpsGrowthRun;
use App\Services\TwStockEpsGrowthRankingService;
use App\Services\TwStockEpsGrowthSnapshotRecalculator;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class BackfillNeutralTwStockEpsGrowthEstimatesCommand extends Command
{
    protected $signature = 'tw-stock:backfill-neutral-eps-growth-estimates
        {--sleep-ms=500 : FinMind 每檔查詢間隔毫秒}';

    protected $description = '將指定股票的中性 2028E 情境補入既有 EPS 快照並原地重算排行。';

    public function handle(
        TwStockEpsGrowthRankingService $rankingService,
        TwStockEpsGrowthSnapshotRecalculator $recalculator,
    ): int {
        $runs = TwStockEpsGrowthRun::query()
            ->whereNotNull('completed_at')
            ->orderBy('snapshot_date')
            ->orderBy('id')
            ->get();
        if ($runs->isEmpty()) {
            $this->warn('沒有可補入的 EPS 成長快照。');

            return self::SUCCESS;
        }

        $expectedCodes = config('tw_stock.eps_growth_ranking.neutral_estimate_stock_codes', []);
        sort($expectedCodes);
        $lookbackDays = (int) config('tw_stock.eps_growth_ranking.lookback_days', 400);
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $prepared = [];

        try {
            foreach ($runs as $run) {
                $rows = $rankingService->neutralEstimateRows(
                    CarbonImmutable::parse($run->snapshot_date->toDateString(), 'Asia/Taipei'),
                    $lookbackDays,
                    $sleepMs,
                );
                $actualCodes = array_column($rows, 'stock_code');
                sort($actualCodes);
                if ($actualCodes !== $expectedCodes) {
                    throw new RuntimeException(sprintf(
                        'run=%d 中性估算不完整：expected=%s actual=%s，拒絕寫入。',
                        $run->id,
                        implode(',', $expectedCodes),
                        implode(',', $actualCodes),
                    ));
                }

                $missingPrices = array_column(array_filter(
                    $rows,
                    fn (array $row): bool => $row['close_price'] === null,
                ), 'stock_code');
                if ($missingPrices !== []) {
                    throw new RuntimeException(sprintf(
                        'run=%d 中性估算缺少收盤價：%s，拒絕寫入。',
                        $run->id,
                        implode(',', $missingPrices),
                    ));
                }

                $prepared[$run->id] = $rows;
            }

            DB::transaction(function () use ($runs, $prepared, $recalculator): void {
                foreach ($runs as $run) {
                    $existingNeutralCount = $run->rankings()->where('is_neutral_estimate', true)->count();
                    foreach ($prepared[$run->id] as $index => $row) {
                        TwStockEpsGrowthRanking::query()->updateOrCreate(
                            [
                                'run_id' => $run->id,
                                'stock_code' => $row['stock_code'],
                            ],
                            [
                                'rank' => $run->eligible_count + $index + 1,
                                'previous_rank' => null,
                                'rank_change' => null,
                                'stock_name' => $row['stock_name'],
                                'eps_2025' => $row['eps_2025'],
                                'eps_2026' => $row['eps_2026'],
                                'eps_2027' => $row['eps_2027'],
                                'eps_2028' => $row['eps_2028'],
                                'growth_2025_2026' => $row['growth_2025_2026'],
                                'growth_2026_2027' => $row['growth_2026_2027'],
                                'growth_2027_2028' => $row['growth_2027_2028'],
                                'growth_sum' => $row['growth_sum'],
                                'weighted_score' => 0,
                                'is_neutral_estimate' => true,
                                'revenue_2026_thousands' => $row['revenue_2026_thousands'],
                                'revenue_2027_thousands' => $row['revenue_2027_thousands'],
                                'revenue_2028_thousands' => $row['revenue_2028_thousands'],
                                'price_date' => $row['price_date'],
                                'close_price' => $row['close_price'],
                                'analyst_count' => $row['analyst_count'],
                                'forecast_date' => $row['forecast_date'],
                                'news_id' => $row['news_id'],
                                'low_base' => $row['low_base'],
                            ],
                        );
                    }

                    $newNeutralCount = $run->rankings()->where('is_neutral_estimate', true)->count();
                    $eligibleCount = $run->rankings()->count();
                    $run->update([
                        'forecast_count' => $run->forecast_count + max(0, $newNeutralCount - $existingNeutralCount),
                        'eligible_count' => $eligibleCount,
                        'top_count' => min(50, $eligibleCount),
                    ]);
                }

                $recalculator->recalculate();
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('中性估算補入失敗：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '中性估算補入完成：runs=%d stocks=%s rows=%d',
            $runs->count(),
            implode(',', $expectedCodes),
            TwStockEpsGrowthRanking::query()->count(),
        ));

        return self::SUCCESS;
    }
}
