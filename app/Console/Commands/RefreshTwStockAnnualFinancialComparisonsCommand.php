<?php

namespace App\Console\Commands;

use App\Models\TwStockAnnualFinancialComparison;
use App\Services\TwStockAnnualFinancialComparisonBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RefreshTwStockAnnualFinancialComparisonsCommand extends Command
{
    protected $signature = 'tw-stock:refresh-annual-financial-comparisons
        {--context-year=2026 : 顯示頁面的目前年度}
        {--start-year=2020 : 年度比較起始年度}
        {--end-year=2025 : 年度比較結束年度}
        {--chunk=500 : 每批寫入筆數}';

    protected $description = '將台股年度營收、EPS、三率比較預先彙總成前端查詢用資料。';

    public function handle(TwStockAnnualFinancialComparisonBuilder $builder): int
    {
        $contextYear = max(1, (int) $this->option('context-year'));
        $startYear = max(1, (int) $this->option('start-year'));
        $endYear = max($startYear + 1, (int) $this->option('end-year'));
        $chunkSize = max(1, (int) $this->option('chunk'));

        $rows = $builder->build($contextYear, $startYear, $endYear);
        if ($rows->isEmpty()) {
            $this->warn('沒有可彙總的股票資料。');

            return self::SUCCESS;
        }

        $now = now();
        $payloads = $rows
            ->map(function (array $row) use ($now): array {
                $row['comparisons'] = json_encode($row['comparisons'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $row['created_at'] = $now;
                $row['updated_at'] = $now;

                return $row;
            })
            ->values();

        DB::transaction(function () use ($contextYear, $payloads, $chunkSize): void {
            TwStockAnnualFinancialComparison::query()
                ->where('context_year', $contextYear)
                ->delete();

            $payloads
                ->chunk($chunkSize)
                ->each(fn ($chunk): bool => TwStockAnnualFinancialComparison::query()->insert($chunk->all()));
        });

        $this->info(sprintf(
            '完成：annual_financial_comparison_rows=%d context_year=%d start_year=%d end_year=%d',
            $payloads->count(),
            $contextYear,
            $startYear,
            $endYear,
        ));

        return self::SUCCESS;
    }
}
