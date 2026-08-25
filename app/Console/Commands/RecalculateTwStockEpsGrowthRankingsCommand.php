<?php

namespace App\Console\Commands;

use App\Services\TwStockEpsGrowthSnapshotRecalculator;
use Illuminate\Console\Command;
use Throwable;

class RecalculateTwStockEpsGrowthRankingsCommand extends Command
{
    protected $signature = 'tw-stock:recalculate-eps-growth-rankings';

    protected $description = '以 1.8:2.5:1 的百分位加權分數重算所有既有 EPS 快照名次。';

    public function handle(TwStockEpsGrowthSnapshotRecalculator $recalculator): int
    {
        try {
            $result = $recalculator->recalculate();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('歷史快照重算失敗：' . $exception->getMessage());

            return self::FAILURE;
        }

        if ($result['runs'] === 0) {
            $this->warn('沒有可重算的 EPS 成長快照。');
        } else {
            $this->info(sprintf(
                '歷史快照加權排行完成：runs=%d rows=%d',
                $result['runs'],
                $result['rows'],
            ));
        }

        return self::SUCCESS;
    }
}
