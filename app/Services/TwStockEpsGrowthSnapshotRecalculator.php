<?php

namespace App\Services;

use App\Models\TwStockEpsGrowthRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TwStockEpsGrowthSnapshotRecalculator
{
    public function __construct(private readonly TwStockEpsGrowthScoringService $scoring)
    {
    }

    /**
     * @return array{runs: int, rows: int}
     */
    public function recalculate(): array
    {
        $runs = TwStockEpsGrowthRun::query()
            ->whereNotNull('completed_at')
            ->orderBy('snapshot_date')
            ->orderBy('id')
            ->get();

        DB::transaction(function () use ($runs): void {
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

                $rows = $this->scoring->scoreAndRank($rankings->map(fn ($ranking): array => [
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

        return [
            'runs' => $runs->count(),
            'rows' => $runs->sum('eligible_count'),
        ];
    }
}
