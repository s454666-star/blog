<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class BackfillNeutralTwStockEpsGrowthEstimatesCommand extends Command
{
    protected $signature = 'tw-stock:backfill-neutral-eps-growth-estimates {--sleep-ms=500}';

    protected $description = '已停用：保留歷史 EPS 快照，不再補入外推估值。';

    public function handle(): int
    {
        $this->error('已停用中性估算回填；請建立新快照，歷史資料保持不變。');

        return self::FAILURE;
    }
}
