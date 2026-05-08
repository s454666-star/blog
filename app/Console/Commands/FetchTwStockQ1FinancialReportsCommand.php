<?php

namespace App\Console\Commands;

use App\Models\TwStockQ1FinancialReport;
use App\Services\TwStockQ1FinancialReportFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

class FetchTwStockQ1FinancialReportsCommand extends Command
{
    protected $signature = 'tw-stock:fetch-q1-financial-reports
        {--year= : 財報年度，預設今年}
        {--quarter=1 : 財報季別，預設 Q1}
        {--min-volume-lots=1000 : 排除低量股票，最新日成交量至少幾張}
        {--sleep-ms=80 : 對公開 API 的單檔節流毫秒數}
        {--skip-non-trading-day : 如果公開報價資料不是今天，視為非交易日並略過}
        {--limit= : 限制候選股票數，測試用}
        {--export-json= : 將入庫後資料匯出成 JSON 檔案路徑}';

    protected $description = '抓取台股 Q1 財報、成交量、股價漲跌幅，計算 Q1 整體財報評分並入庫。';

    public function handle(TwStockQ1FinancialReportFetcher $fetcher): int
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

        if ((bool) $this->option('skip-non-trading-day') && !$fetcher->hasTodayOfficialQuote()) {
            $this->info('略過：今天沒有官方最新報價資料，視為非交易日或市場休市。');

            return self::SUCCESS;
        }

        try {
            $payloads = $fetcher->fetch($year, $quarter, $minVolumeLots, $sleepMs, $limit);
        } catch (Throwable $e) {
            report($e);
            $this->error('抓取失敗：' . $e->getMessage());

            return self::FAILURE;
        }

        $keptIds = [];
        foreach ($payloads as $payload) {
            $model = TwStockQ1FinancialReport::query()->updateOrCreate(
                [
                    'fiscal_year' => $payload['fiscal_year'],
                    'quarter' => $payload['quarter'],
                    'exchange' => $payload['exchange'],
                    'stock_code' => $payload['stock_code'],
                ],
                $payload
            );

            $keptIds[] = $model->id;
            $this->info(sprintf(
                '#%d %s %s saved: Q1營收 %s 億, YoY %s%%, 評分 %s, 股價 %s, 量 %s 張',
                $payload['rank'],
                $payload['stock_code'],
                $payload['stock_name'],
                $this->number($payload['q1_revenue_billion'] ?? null, 2),
                $this->number($payload['q1_revenue_yoy_percent'] ?? null, 2),
                $this->number($payload['q1_revenue_score'] ?? null, 2),
                $this->number($payload['latest_close_price'] ?? null, 2),
                number_format((int) ($payload['volume_lots'] ?? 0)),
            ));
        }

        if ($keptIds !== []) {
            TwStockQ1FinancialReport::query()
                ->where('fiscal_year', $year)
                ->where('quarter', $quarter)
                ->whereNotIn('id', $keptIds)
                ->delete();
        }

        if ($this->option('export-json')) {
            $this->exportJson((string) $this->option('export-json'), $year, $quarter);
        }

        $this->newLine();
        $this->info(sprintf(
            '完成：saved=%d year=%d quarter=%d min_volume_lots=%d',
            count($payloads),
            $year,
            $quarter,
            $minVolumeLots,
        ));

        return self::SUCCESS;
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
