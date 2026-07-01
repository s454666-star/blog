<?php

namespace App\Console\Commands;

use App\Services\TwActiveEtfOperationFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchTwActiveEtfOperationsCommand extends Command
{
    protected $signature = 'tw-stock:fetch-active-etf-operations
        {--codes=* : 只抓指定 ETF 代碼，可重複}
        {--from= : 起始日期，格式 YYYY-MM-DD 或 YYYY/MM/DD}
        {--to= : 結束日期，格式 YYYY-MM-DD 或 YYYY/MM/DD}
        {--backfill-days=0 : 從今天往前補幾天；未指定 from/to 時生效}
        {--sleep-ms=350 : 每檔 ETF 間的等待毫秒數}
        {--stop-on-error : 任一 ETF 抓取失敗就停止}';

    protected $description = '抓取 TWSE/TPEx 主動式 ETF 清單、行情與 CMoney 主動 ETF 操作日報。';

    public function handle(TwActiveEtfOperationFetcher $fetcher): int
    {
        try {
            [$from, $to] = $this->resolveDateRange();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $stopOnError = (bool) $this->option('stop-on-error');
        $requestedCodes = collect((array) $this->option('codes'))
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->values()
            ->all();

        try {
            $etfs = $fetcher->fetchActiveEtfList();
            $synced = $fetcher->syncActiveEtfs($etfs);
        } catch (Throwable $e) {
            report($e);
            $this->error('主動式 ETF 清單抓取失敗：' . $e->getMessage());

            return self::FAILURE;
        }

        if ($requestedCodes !== []) {
            $allowed = array_flip($requestedCodes);
            $etfs = array_values(array_filter(
                $etfs,
                static fn (array $etf): bool => isset($allowed[(string) $etf['stock_code']]),
            ));
        }

        if ($etfs === []) {
            $this->warn('沒有符合條件的主動式 ETF。');

            return self::SUCCESS;
        }

        $token = null;
        $savedReports = 0;
        $savedItems = 0;
        $failed = 0;

        foreach ($etfs as $etf) {
            $code = (string) $etf['stock_code'];
            $name = (string) $etf['stock_name'];

            try {
                $token ??= $fetcher->fetchCmoneyGuestToken($code);
                $reports = $fetcher->fetchOperationReports($code, $name, $from, $to, $token);

                foreach ($reports as $reportPayload) {
                    $report = $fetcher->storeReport($reportPayload);
                    $savedReports++;
                    $savedItems += (int) $report->changed_row_count;
                }

                $this->line(sprintf(
                    '%s %s saved_reports=%d changed_items=%d',
                    $code,
                    $name,
                    count($reports),
                    array_sum(array_map(static fn (array $report): int => (int) $report['changed_row_count'], $reports)),
                ));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('%s %s failed: %s', $code, $name, $e->getMessage()));

                if ($stopOnError) {
                    break;
                }
            }

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->newLine();
        $this->info(sprintf(
            '完成：synced_etfs=%d processed_etfs=%d saved_reports=%d saved_items=%d failed=%d range=%s~%s',
            $synced,
            count($etfs),
            $savedReports,
            $savedItems,
            $failed,
            $from?->toDateString() ?? 'CMoney returned range',
            $to?->toDateString() ?? 'CMoney returned range',
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{CarbonImmutable|null, CarbonImmutable|null}
     */
    private function resolveDateRange(): array
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $backfillDays = max(0, (int) $this->option('backfill-days'));

        if (($from === null || $from === '') && ($to === null || $to === '') && $backfillDays <= 0) {
            return [null, null];
        }

        $today = CarbonImmutable::today((string) config('app.timezone'));
        $toDate = $to !== null && $to !== ''
            ? $this->parseDate((string) $to)
            : $today;
        $fromDate = $from !== null && $from !== ''
            ? $this->parseDate((string) $from)
            : $toDate->subDays($backfillDays);

        if ($fromDate->gt($toDate)) {
            throw new \InvalidArgumentException('from 不可晚於 to。');
        }

        return [$fromDate, $toDate];
    }

    private function parseDate(string $value): CarbonImmutable
    {
        $normalized = str_replace('/', '-', trim($value));
        $date = CarbonImmutable::createFromFormat('Y-m-d', $normalized, (string) config('app.timezone'));

        if (!$date instanceof CarbonImmutable) {
            throw new \InvalidArgumentException('日期格式錯誤：' . $value);
        }

        return $date->startOfDay();
    }
}
