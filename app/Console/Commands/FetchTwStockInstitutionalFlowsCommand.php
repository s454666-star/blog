<?php

namespace App\Console\Commands;

use App\Models\TwStockInstitutionalFlow;
use App\Services\TwStockInstitutionalFlowFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class FetchTwStockInstitutionalFlowsCommand extends Command
{
    protected $signature = 'tw-stock:fetch-institutional-flows
        {--date= : 單一日期，格式 YYYY-MM-DD 或 YYYY/MM/DD}
        {--from= : 起始日期，格式 YYYY-MM-DD 或 YYYY/MM/DD}
        {--to= : 結束日期，格式 YYYY-MM-DD 或 YYYY/MM/DD}
        {--sleep-ms=200 : 每個日期之間的等待毫秒數}
        {--stop-on-error : 任一日期抓取失敗就停止}';

    protected $description = '抓取證交所外資/投信買賣超與期交所外資/投信臺股期貨淨未平倉。';

    public function handle(TwStockInstitutionalFlowFetcher $fetcher): int
    {
        try {
            [$from, $to] = $this->resolveDateRange();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $stopOnError = (bool) $this->option('stop-on-error');
        $saved = 0;
        $skipped = 0;
        $failed = 0;

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            try {
                $payload = $fetcher->fetchDate($date);

                if ($payload === null) {
                    $skipped++;
                    $this->line($date->toDateString() . ' skipped: 非交易日或證交所無資料');
                    $this->sleep($sleepMs);
                    continue;
                }

                TwStockInstitutionalFlow::query()->updateOrCreate(
                    ['trade_date' => $payload['trade_date']],
                    $payload
                );

                $saved++;
                $this->info(sprintf(
                    '%s saved: 外資 %.2f 億, 投信 %.2f 億, 外資OI %s, 投信OI %s',
                    $date->toDateString(),
                    ((int) $payload['foreign_stock_net_amount']) / 100_000_000,
                    ((int) $payload['investment_trust_stock_net_amount']) / 100_000_000,
                    $payload['foreign_txf_open_interest_net_contracts'] !== null
                        ? number_format((int) $payload['foreign_txf_open_interest_net_contracts'])
                        : 'N/A',
                    $payload['investment_trust_txf_open_interest_net_contracts'] !== null
                        ? number_format((int) $payload['investment_trust_txf_open_interest_net_contracts'])
                        : 'N/A',
                ));
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error($date->toDateString() . ' failed: ' . $e->getMessage());

                if ($stopOnError) {
                    break;
                }
            }

            $this->sleep($sleepMs);
        }

        $this->newLine();
        $this->info(sprintf(
            '完成：saved=%d skipped=%d failed=%d range=%s~%s',
            $saved,
            $skipped,
            $failed,
            $from->toDateString(),
            $to->toDateString(),
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveDateRange(): array
    {
        $date = $this->option('date');
        $from = $this->option('from');
        $to = $this->option('to');

        if ($date !== null && $date !== '') {
            $singleDate = $this->parseDate((string) $date);

            return [$singleDate, $singleDate];
        }

        $fromDate = $from !== null && $from !== ''
            ? $this->parseDate((string) $from)
            : CarbonImmutable::today();

        $toDate = $to !== null && $to !== ''
            ? $this->parseDate((string) $to)
            : CarbonImmutable::today();

        if ($fromDate->gt($toDate)) {
            throw new \InvalidArgumentException('from 不可晚於 to。');
        }

        return [$fromDate, $toDate];
    }

    private function parseDate(string $value): CarbonImmutable
    {
        $normalized = str_replace('/', '-', trim($value));

        return CarbonImmutable::createFromFormat('Y-m-d', $normalized)->startOfDay();
    }

    private function sleep(int $sleepMs): void
    {
        if ($sleepMs <= 0) {
            return;
        }

        usleep($sleepMs * 1000);
    }
}
