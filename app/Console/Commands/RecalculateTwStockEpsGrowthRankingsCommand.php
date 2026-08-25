<?php

namespace App\Console\Commands;

use App\Models\TwStockEpsGrowthRun;
use App\Services\TwStockEpsGrowthScoringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RecalculateTwStockEpsGrowthRankingsCommand extends Command
{
    protected $signature = 'tw-stock:recalculate-eps-growth-rankings';

    protected $description = '以 2.5:2.5:1 的百分位加權分數重算所有既有 EPS 快照名次。';

    public function handle(TwStockEpsGrowthScoringService $scoring): int
    {
        $runs = TwStockEpsGrowthRun::query()
            ->whereNotNull('completed_at')
            ->orderBy('snapshot_date')
            ->orderBy('id')
            ->get();

        if ($runs->isEmpty()) {
            $this->warn('沒有可重算的 EPS 成長快照。');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($runs, $scoring): void {
                $previousRanks = [];

                foreach ($runs as $run) {
                    $rankings = $run->rankings()->orderBy('id')->get();
                    if ($rankings->count() !== $run->eligible_count) {
                        throw new RuntimeException(sprintf(
                            'run=%d 列數不符：rows=%d eligible=%d，拒絕重算。',
                            $run->id,
                            $rankings->count(),
                            $run->eligible_count,
                        ));
                    }

                    $rows = $scoring->scoreAndRank($rankings->map(fn ($ranking): array => [
                        'id' => $ranking->id,
                        'stock_code' => $ranking->stock_code,
                        'growth_2025_2026' => $ranking->growth_2025_2026,
                        'growth_2026_2027' => $ranking->growth_2026_2027,
                        'growth_2027_2028' => $ranking->growth_2027_2028,
                    ])->all());

                    $currentRanks = [];
                    foreach ($rows as $row) {
                        $previousRank = $previousRanks[$row['stock_code']] ?? null;
                        $rankChange = $previousRank === null ? null : $previousRank - $row['rank'];

                        DB::table('tw_stock_eps_growth_rankings')
                            ->where('id', $row['id'])
                            ->where('run_id', $run->id)
                            ->update([
                                'rank' => $row['rank'],
                                'previous_rank' => $previousRank,
                                'rank_change' => $rankChange,
                                'weighted_score' => $row['weighted_score'],
                                'updated_at' => now(),
                            ]);
                        $currentRanks[$row['stock_code']] = $row['rank'];
                    }

                    $previousRanks = $currentRanks;
                }
            });
        } catch (Throwable $exception) {
            report($exception);
            $this->error('歷史快照重算失敗：' . $exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '歷史快照加權排行完成：runs=%d rows=%d',
            $runs->count(),
            $runs->sum('eligible_count'),
        ));

        return self::SUCCESS;
    }
}
