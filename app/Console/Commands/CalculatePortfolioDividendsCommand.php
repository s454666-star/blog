<?php

namespace App\Console\Commands;

use App\Models\PortfolioDividendIncome;
use App\Services\EsunPortfolioService;
use App\Services\PortfolioDividendCalculator;
use App\Services\TwStockHistoricalDividendFetcher;
use App\Services\YuantaPortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

class CalculatePortfolioDividendsCommand extends Command
{
    protected $signature = 'portfolio:calculate-dividends
        {--broker=all : all、esun 或 yuanta}
        {--from= : 起始除息日 YYYY-MM-DD}
        {--to= : 結束除息日 YYYY-MM-DD}';

    protected $description = '依券商持股證據與公開除息資料，新增尚未計算的股息收益。';

    public function handle(
        EsunPortfolioService $esun,
        YuantaPortfolioService $yuanta,
        TwStockHistoricalDividendFetcher $fetcher,
        PortfolioDividendCalculator $calculator,
    ): int {
        try {
            [$scheduledFrom, $to] = $this->dateRange();
            $hasExplicitRange = trim((string) $this->option('from')) !== ''
                || trim((string) $this->option('to')) !== '';
            foreach ($this->brokers() as $broker) {
                $from = $scheduledFrom;
                if (!$hasExplicitRange && !PortfolioDividendIncome::query()
                    ->where('broker', $broker)
                    ->whereYear('ex_dividend_date', $to->year)
                    ->exists()) {
                    $from = $to->startOfYear();
                    $this->info("{$broker} 尚無今年資料，首次執行自動回補 {$from->toDateString()}~{$to->toDateString()}。");
                }
                $evidence = $broker === 'esun'
                    ? $esun->dividendEvidence($from, $to)
                    : $yuanta->dividendEvidence($from, $to);
                $this->calculateBroker($broker, $evidence, $from, $to, $fetcher, $calculator);
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('股息計算失敗：' . $exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function calculateBroker(
        string $broker,
        array $evidence,
        CarbonImmutable $from,
        CarbonImmutable $to,
        TwStockHistoricalDividendFetcher $fetcher,
        PortfolioDividendCalculator $calculator,
    ): void {
        $inventories = $evidence['inventories'] ?? [];
        $transactions = $evidence['transactions'] ?? [];
        $openLots = $evidence['unrealizedDetails'] ?? [];
        $allRows = array_merge($inventories, $transactions, $openLots);
        $codes = collect($allRows)->filter('is_array')->map(fn (array $row): string => $this->code($row))
            ->filter()->unique()->values()->all();
        $names = collect($allRows)->filter('is_array')->mapWithKeys(function (array $row): array {
            $code = $this->code($row);
            $name = (string) $this->value($row, 'stk_na', 'stkNa', 'StkName', 'stkName', 'stockName');

            return $code !== '' && $name !== '' ? [$code => $name] : [];
        });

        $events = $fetcher->fetch($codes, $from, $to);
        $inserted = 0;
        $existing = 0;
        $amount = 0.0;
        foreach ($events as $event) {
            $stockCode = (string) $event['stock_code'];
            $exDate = (string) $event['ex_dividend_date'];
            $eligibility = $broker === 'esun'
                ? $calculator->esunEligibleQuantity($stockCode, $exDate, $inventories, $transactions)
                : $calculator->yuantaEligibleQuantity($stockCode, $exDate, $openLots, $transactions);
            $income = round($eligibility['quantity'] * (float) $event['cash_dividend_per_share'], 4);
            $row = PortfolioDividendIncome::query()->firstOrCreate(
                ['broker' => $broker, 'stock_code' => $stockCode, 'ex_dividend_date' => $exDate],
                [
                    'stock_name' => $names->get($stockCode),
                    'cash_dividend_per_share' => $event['cash_dividend_per_share'],
                    'eligible_quantity' => $eligibility['quantity'],
                    'dividend_income' => $income,
                    'source' => 'FinMind TaiwanStockDividendResult',
                    'calculation_method' => $eligibility['method'],
                    'source_payload' => $event['source_payload'],
                    'calculated_at' => now(),
                ],
            );
            if ($row->wasRecentlyCreated) {
                $inserted++;
                $amount += $income;
            } else {
                $existing++;
            }
        }
        PortfolioDividendIncome::forgetYearTotal($broker, (int) $from->year);
        if ($from->year !== $to->year) {
            PortfolioDividendIncome::forgetYearTotal($broker, (int) $to->year);
        }

        $this->info(sprintf(
            '%s %s~%s events=%d inserted=%d existing=%d new_income=%s',
            $broker,
            $from->toDateString(),
            $to->toDateString(),
            count($events),
            $inserted,
            $existing,
            number_format($amount, 2, '.', ''),
        ));
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function dateRange(): array
    {
        $timezone = (string) config('app.timezone', 'Asia/Taipei');
        $toOption = trim((string) $this->option('to'));
        $fromOption = trim((string) $this->option('from'));
        if ($fromOption !== '' || $toOption !== '') {
            $to = CarbonImmutable::parse($toOption !== '' ? $toOption : $fromOption, $timezone)->startOfDay();
            $from = CarbonImmutable::parse($fromOption !== '' ? $fromOption : $toOption, $timezone)->startOfDay();
        } else {
            $from = CarbonImmutable::today($timezone)->subDay();
            while ($from->isWeekend()) {
                $from = $from->subDay();
            }
            $to = $from;
        }

        if ($from->greaterThan($to)) {
            throw new \InvalidArgumentException('--from 不可晚於 --to。');
        }

        return [$from, $to];
    }

    /** @return list<string> */
    private function brokers(): array
    {
        $broker = strtolower(trim((string) $this->option('broker')));
        if ($broker === 'all') {
            return ['esun', 'yuanta'];
        }
        if (!in_array($broker, ['esun', 'yuanta'], true)) {
            throw new \InvalidArgumentException('--broker 只能是 all、esun 或 yuanta。');
        }

        return [$broker];
    }

    private function code(array $row): string
    {
        return strtoupper(preg_replace('/[^0-9A-Z]/i', '', (string) $this->value(
            $row, 'stk_no', 'stkNo', 'stockNo', 'StkCode', 'stkCode', 'stock_code',
        )) ?? '');
    }

    private function value(array $row, string ...$keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                return $row[$key];
            }
        }

        return null;
    }
}
