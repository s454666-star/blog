<?php

namespace App\Console\Commands;

use App\Models\TwStockAnnualFinancialComparison;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncTwStockAnnualFinancialComparisonPricesCommand extends Command
{
    protected $signature = 'tw-stock:sync-annual-financial-comparison-prices
        {--context-year=2026 : 顯示頁面的目前年度}
        {--chunk=500 : 每批寫入筆數}';

    protected $description = '同步年度比較頁現有股票的最新收盤價與成交量，避免每日重建整張比較表。';

    public function handle(): int
    {
        $contextYear = max(1, (int) $this->option('context-year'));
        $chunkSize = max(1, (int) $this->option('chunk'));

        $latestDates = DB::table('tw_stock_daily_prices')
            ->select('exchange', 'stock_code')
            ->selectRaw('MAX(trade_date) as max_trade_date')
            ->groupBy('exchange', 'stock_code');

        $rows = DB::table('tw_stock_annual_financial_comparisons as annual')
            ->joinSub($latestDates, 'latest_dates', function ($join): void {
                $join->on('latest_dates.exchange', '=', 'annual.exchange')
                    ->on('latest_dates.stock_code', '=', 'annual.stock_code');
            })
            ->join('tw_stock_daily_prices as prices', function ($join): void {
                $join->on('prices.exchange', '=', 'annual.exchange')
                    ->on('prices.stock_code', '=', 'annual.stock_code')
                    ->on('prices.trade_date', '=', 'latest_dates.max_trade_date');
            })
            ->where('annual.context_year', $contextYear)
            ->get([
                'annual.context_year',
                'annual.exchange',
                'annual.stock_code',
                'prices.close_price as latest_close_price',
                'prices.volume_lots',
            ]);

        if ($rows->isEmpty()) {
            $this->warn(sprintf('沒有可同步的年度比較資料。context_year=%d', $contextYear));

            return self::SUCCESS;
        }

        $now = now();
        $payloads = $rows->map(function (object $row) use ($now): array {
            return [
                'context_year' => (int) $row->context_year,
                'exchange' => (string) $row->exchange,
                'stock_code' => (string) $row->stock_code,
                'latest_close_price' => $row->latest_close_price,
                'volume_lots' => $row->volume_lots === null ? null : (int) $row->volume_lots,
                'generated_at' => $now,
                'updated_at' => $now,
            ];
        })->values();

        $payloads
            ->chunk($chunkSize)
            ->each(function ($chunk): void {
                foreach ($chunk as $payload) {
                    TwStockAnnualFinancialComparison::query()->updateOrInsert(
                        [
                            'context_year' => $payload['context_year'],
                            'exchange' => $payload['exchange'],
                            'stock_code' => $payload['stock_code'],
                        ],
                        [
                            'latest_close_price' => $payload['latest_close_price'],
                            'volume_lots' => $payload['volume_lots'],
                            'generated_at' => $payload['generated_at'],
                            'updated_at' => $payload['updated_at'],
                        ],
                    );
                }
            });

        $this->info(sprintf(
            '完成：synced_rows=%d context_year=%d',
            $payloads->count(),
            $contextYear,
        ));

        return self::SUCCESS;
    }
}
