<?php

namespace Tests\Unit;

use App\Services\EsunPortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

class EsunPortfolioServiceTest extends TestCase
{
    public function test_it_uses_cash_cost_for_margin_invested_cost(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-43057',
            'make_a_per' => '8.05',
            'make_a_sum' => '3466',
            'price_avg' => '106.00',
            'price_evn' => '106.44',
            'price_mkt' => '110.00',
            'price_now' => '108.50',
            'price_qty_sum' => '106000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '界霖',
            'stk_no' => '5285',
            's_type' => 'H',
            'trade' => '3',
            'value_mkt' => '110000',
            'value_now' => '108500',
        ], ['previousClose' => null]);

        $this->assertSame(110.0, $row['currentPrice']);
        $this->assertSame(110000.0, $row['marketValue']);
        $this->assertSame(110.0, $row['esunCurrentPrice']);
        $this->assertSame(110000.0, $row['esunMarketValue']);
        $this->assertSame(43057.0, $row['costBasis']);
        $this->assertSame(-43057.0, $row['signedCostBasis']);
        $this->assertSame(43057.0, $row['cashCostBasis']);
        $this->assertSame(106000.0, $row['priceAmount']);
        $this->assertSame(3466.0, $row['unrealizedPnl']);
        $this->assertSame(3466.0, $row['esunUnrealizedPnl']);
        $this->assertSame(110.0, $row['realtimePnlBasePrice']);
        $this->assertEqualsWithDelta(8.05, $row['unrealizedPnlRate'], 0.01);
        $this->assertSame('3', $row['tradeType']);
        $this->assertSame('融資', $row['tradeTypeLabel']);
    }

    public function test_it_sets_esun_today_pnl_from_esun_price_and_previous_close(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-100000',
            'make_a_per' => '10.00',
            'make_a_sum' => '10000',
            'price_avg' => '100.00',
            'price_evn' => '100.00',
            'price_mkt' => '110.00',
            'price_qty_sum' => '100000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '測試股',
            'stk_no' => '9999',
            's_type' => 'H',
            'trade' => '0',
            'value_mkt' => '110000',
        ], ['previousClose' => 100.0]);

        $this->assertSame(10000.0, $row['todayPnl']);
        $this->assertSame(10000.0, $row['esunTodayPnl']);
        $this->assertSame(10.0, $row['dayChange']);
        $this->assertEqualsWithDelta(10.0, $row['dayChangeRate'], 0.0001);
    }

    public function test_it_maps_cash_inventory_from_trade_type_even_when_position_type_is_h(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-264142',
            'make_a_per' => '-1.39',
            'make_a_sum' => '-3674',
            'price_avg' => '264.00',
            'price_evn' => '264.69',
            'price_mkt' => '261.00',
            'price_qty_sum' => '264000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '同欣電',
            'stk_no' => '6271',
            's_type' => 'H',
            'trade' => '0',
            'value_mkt' => '261000',
        ], ['previousClose' => null]);

        $this->assertSame('H', $row['positionType']);
        $this->assertSame('0', $row['tradeType']);
        $this->assertSame('現股', $row['tradeTypeLabel']);
    }

    public function test_it_includes_exchange_badge_metadata(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $row = $method->invoke($service, [
            'cost_qty' => '1000',
            'cost_sum' => '-200000',
            'make_a_per' => '0',
            'make_a_sum' => '0',
            'price_avg' => '200.00',
            'price_evn' => '200.00',
            'price_mkt' => '200.00',
            'price_qty_sum' => '200000',
            'qty_b' => '1000',
            'qty_s' => '0',
            'stk_dats' => [],
            'stk_na' => '測試櫃',
            'stk_no' => '9999',
            's_type' => 'H',
            'trade' => '3',
            'value_mkt' => '200000',
        ], ['previousClose' => null], [
            'exchange' => 'TPEx',
            'label' => '上櫃',
            'shortLabel' => '櫃',
            'class' => 'tpex',
        ]);

        $this->assertSame('TPEx', $row['exchange']);
        $this->assertSame('上櫃', $row['exchangeLabel']);
        $this->assertSame('櫃', $row['exchangeShortLabel']);
        $this->assertSame('tpex', $row['exchangeClass']);
    }

    public function test_it_calculates_sixty_day_return_from_historical_prices(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'historicalPriceSummary');

        $prices = collect(range(0, 64))
            ->map(fn (int $index): array => [
                'tradeDate' => CarbonImmutable::parse('2026-06-24', 'Asia/Taipei')->subDays($index)->toDateString(),
                'closePrice' => match ($index) {
                    0 => 200.0,
                    4 => 180.0,
                    19 => 125.0,
                    59 => 80.0,
                    default => 100.0,
                },
            ])
            ->push([
                'tradeDate' => '2025-12-31',
                'closePrice' => 50.0,
            ]);

        $summary = $method->invoke($service, $prices, '2026-06-25', '2026-01-01');

        $this->assertSame(200.0, $summary['previousClose']);
        $this->assertEqualsWithDelta((200 - 180) / 180 * 100, $summary['fiveDayReturn'], 0.000001);
        $this->assertEqualsWithDelta((200 - 125) / 125 * 100, $summary['twentyDayReturn'], 0.000001);
        $this->assertEqualsWithDelta(150.0, $summary['sixtyDayReturn'], 0.000001);
        $this->assertEqualsWithDelta(300.0, $summary['yearToDateReturn'], 0.000001);
    }

    public function test_it_uses_newer_yahoo_previous_close_when_database_daily_price_is_stale(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'mergeHistoricalSummary');

        $summary = $method->invoke($service, [
            'previousClose' => 364.0,
            'previousCloseDate' => '2026-06-26',
            'fiveDayReturn' => -1.0,
            'twentyDayReturn' => -2.0,
            'sixtyDayReturn' => -3.0,
            'yearToDateReturn' => -4.0,
        ], [
            'previousClose' => 344.5,
            'previousCloseDate' => '2026-06-29',
            'fiveDayReturn' => 1.0,
            'twentyDayReturn' => 2.0,
            'sixtyDayReturn' => 3.0,
            'yearToDateReturn' => 4.0,
        ]);

        $this->assertSame(344.5, $summary['previousClose']);
        $this->assertSame('2026-06-29', $summary['previousCloseDate']);
        $this->assertSame(1.0, $summary['fiveDayReturn']);
        $this->assertSame(2.0, $summary['twentyDayReturn']);
        $this->assertSame(3.0, $summary['sixtyDayReturn']);
        $this->assertSame(4.0, $summary['yearToDateReturn']);
    }

    public function test_esun_minimum_query_seconds_can_be_lowered_to_thirty_seconds(): void
    {
        config()->set('esun.minimum_query_seconds', 30);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'minimumQuerySeconds');

        $this->assertSame(30, $method->invoke($service));
    }

    public function test_it_calculates_investment_level_from_bank_balance(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'balanceSummary');

        $summary = $method->invoke($service, [
            'balance' => [
                'available_balance' => '500000',
                'exchange_balance' => '10000',
            ],
            'settlements' => [
                ['c_date' => '20260624', 'price' => '-90000'],
                ['c_date' => '20260625', 'price' => '70000'],
                ['c_date' => '20260626', 'price' => '-50000'],
                ['c_date' => '20260629', 'price' => '30000'],
            ],
        ], 500000.0, CarbonImmutable::parse('2026-06-25 10:00:00', 'Asia/Taipei'));

        $this->assertSame(500000.0, $summary['availableBalance']);
        $this->assertSame(10000.0, $summary['dayTradeOffsetAmount']);
        $this->assertSame(-20000.0, $summary['pendingSettlementAmount']);
        $this->assertSame(470000.0, $summary['bankBalance']);
        $this->assertEqualsWithDelta(51.546391752, $summary['investmentLevelRate'], 0.000001);
    }

    public function test_it_uses_last_success_snapshot_during_minimum_query_window_after_short_cache_expires(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 09:23:00', 'Asia/Taipei'));
        Cache::forget('esun:portfolio:inventories:v5');
        Cache::forget('esun:portfolio:inventories:last-success:v5');
        Cache::forget('esun:portfolio:last-query-at:v5');

        try {
            config()->set('esun.portfolio_enabled', false);
            config()->set('esun.minimum_query_seconds', 60);

            Cache::put('esun:portfolio:last-query-at:v5', '2026-06-26T09:22:40+08:00', now()->addHour());
            Cache::put('esun:portfolio:inventories:last-success:v5', [
                'queriedAt' => '2026-06-26T09:22:30+08:00',
                'inventories' => [],
                'balance' => ['available_balance' => '1000000'],
                'settlements' => [],
                'transactions' => [],
                'todayTransactions' => [],
            ], now()->addHour());

            $snapshot = (new EsunPortfolioService())->snapshot(false);

            $this->assertSame('cached', $snapshot['source']['status']);
            $this->assertSame(30, $snapshot['source']['ageSeconds']);
            $this->assertSame(0, $snapshot['summary']['stockCount']);
            $this->assertSame(1000000.0, $snapshot['summary']['bankBalance']);
        } finally {
            CarbonImmutable::setTestNow();
            Cache::forget('esun:portfolio:inventories:v5');
            Cache::forget('esun:portfolio:inventories:last-success:v5');
            Cache::forget('esun:portfolio:last-query-at:v5');
        }
    }

    public function test_it_can_read_inventory_payload_from_persistent_daemon(): void
    {
        config()->set('esun.portfolio_enabled', true);
        config()->set('esun.daemon_url', 'http://127.0.0.1:8765');

        Http::fake([
            'http://127.0.0.1:8765/portfolio*' => Http::response([
                'queried_at' => '2026-01-01T02:00:00+00:00',
                'inventories' => [
                    [
                        'stk_no' => '00631L',
                        'stk_na' => '元大台灣50正2',
                        'trade' => '0',
                        'cost_qty' => '8000',
                    ],
                ],
                'balance' => ['available_balance' => '100000'],
                'settlements' => [],
                'today_transactions_history' => [
                    ['make' => '2000', 'trade' => '0', 'c_date' => '20260101'],
                ],
                'today_transactions' => [
                    ['make' => '3000', 'trade' => '0', 'c_date' => '20260101'],
                    ['make' => '9999', 'trade' => '0', 'c_date' => '20260102'],
                ],
                'warnings' => [],
                'daemon' => ['login_count' => 1],
            ]),
        ]);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'queryInventories');
        $payload = $method->invoke($service, CarbonImmutable::parse('2026-01-01 10:00:00', 'Asia/Taipei'));

        $this->assertSame('2026-01-01T02:00:00+00:00', $payload['queriedAt']);
        $this->assertSame('00631L', $payload['inventories'][0]['stk_no']);
        $this->assertSame('8000', $payload['inventories'][0]['cost_qty']);
        $this->assertSame('100000', $payload['balance']['available_balance']);
        $this->assertSame('2000', $payload['transactions'][0]['make']);
        $this->assertCount(1, $payload['todayTransactions']);
        $this->assertSame('2000', $payload['todayTransactions'][0]['make']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/portfolio?today=2026-01-01'));
    }

    public function test_it_can_read_transactions_from_persistent_daemon(): void
    {
        config()->set('esun.daemon_url', 'http://127.0.0.1:8765');

        Http::fake([
            'http://127.0.0.1:8765/transactions*' => Http::response([
                'queried_at' => '2026-06-26T02:00:00+00:00',
                'start' => '2026-01-01',
                'end' => '2026-06-25',
                'transactions' => [
                    ['stk_no' => '6271', 'make' => '1200'],
                ],
                'daemon' => ['login_count' => 1],
            ]),
        ]);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'queryTransactions');
        $transactions = $method->invoke(
            $service,
            CarbonImmutable::parse('2026-01-01', 'Asia/Taipei'),
            CarbonImmutable::parse('2026-06-25', 'Asia/Taipei'),
        );

        $this->assertSame([['stk_no' => '6271', 'make' => '1200']], $transactions);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/transactions?start=2026-01-01')
            && str_contains($request->url(), 'end=2026-06-25'));
    }

    public function test_it_calculates_year_profit_from_esun_realized_profit(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'yearProfitSummary');

        $summary = $method->invoke($service, [
            'transactions' => [
                ['make' => '100000', 'trade' => '3'],
                ['make' => '-15000', 'trade' => 'A'],
                ['make' => '5000', 'trade' => '9'],
                ['make' => '-12000', 'trade' => '0'],
                ['make' => '0', 'trade' => '3'],
            ],
        ]);

        $this->assertSame(78000.0, $summary['realizedYearPnl']);
        $this->assertSame(78000.0, $summary['realizedHistoryPnl']);
        $this->assertSame(0.0, $summary['realizedTodayPnl']);
        $this->assertSame(-10000.0, $summary['dayTradeYearPnl']);
        $this->assertSame(88000.0, $summary['adjustedRealizedYearPnl']);
        $this->assertSame(78000.0, $summary['yearTotalPnl']);
    }

    public function test_it_adds_today_realized_profit_to_year_total_profit(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'yearProfitSummary');

        $summary = $method->invoke($service, [
            'transactions' => [
                ['make' => '1208882', 'trade' => '0'],
            ],
            'todayTransactions' => [
                ['make' => '5000', 'trade' => '0'],
                ['make' => '-2000', 'trade' => '0'],
            ],
        ]);

        $this->assertSame(1208882.0, $summary['realizedHistoryPnl']);
        $this->assertSame(3000.0, $summary['realizedTodayPnl']);
        $this->assertSame(1211882.0, $summary['realizedYearPnl']);
        $this->assertSame(1211882.0, $summary['yearTotalPnl']);
    }

    public function test_it_reports_today_history_realized_profit_without_double_counting_year_total(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'yearProfitSummary');
        $todayRows = [
            ['t_date' => '20260630', 'stk_no' => '3362', 'trade' => '3', 'qty' => '1000', 'price' => '179.00', 'make' => '-13857'],
            ['t_date' => '20260630', 'stk_no' => '6548', 'trade' => '0', 'qty' => '1000', 'price' => '82.40', 'make' => '-5938'],
            ['t_date' => '20260630', 'stk_no' => '6548', 'trade' => '3', 'qty' => '2000', 'price' => '82.50', 'make' => '-11115'],
            ['t_date' => '20260630', 'stk_no' => '8016', 'trade' => '3', 'qty' => '1000', 'price' => '315.50', 'make' => '-11395'],
        ];

        $summary = $method->invoke($service, [
            'transactions' => [
                ['t_date' => '20260601', 'stk_no' => '9999', 'trade' => '0', 'qty' => '1000', 'price' => '1240.55', 'make' => '1240552'],
                ...$todayRows,
            ],
            'todayTransactions' => $todayRows,
        ]);

        $this->assertSame(1198247.0, $summary['realizedHistoryPnl']);
        $this->assertSame(-42305.0, $summary['realizedTodayPnl']);
        $this->assertSame(1198247.0, $summary['realizedYearPnl']);
        $this->assertSame(1198247.0, $summary['yearTotalPnl']);
    }

    public function test_it_counts_today_realized_profit_by_trade_date_with_settlement_fallback(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'transactionOccursOn');
        $today = CarbonImmutable::parse('2026-06-25 10:00:00', 'Asia/Taipei');

        $this->assertTrue($method->invoke($service, ['t_date' => '20260625'], $today));
        $this->assertFalse($method->invoke($service, ['t_date' => '20260629'], $today));
        $this->assertTrue($method->invoke($service, ['c_date' => '20260625'], $today));
    }

    public function test_it_calculates_year_return_rate_from_current_capital_minus_profit(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'buildSnapshot');

        $snapshot = $method->invoke($service, [
            'queriedAt' => '2026-06-25T10:00:00+08:00',
            'inventories' => [],
            'balance' => ['available_balance' => '1000000'],
            'settlements' => [],
            'transactions' => [
                ['make' => '200000', 'trade' => '0'],
            ],
            'todayTransactions' => [
                ['make' => '10000', 'trade' => '0'],
            ],
        ], [
            'isOpen' => false,
            'label' => '非交易時段',
        ], CarbonImmutable::parse('2026-06-25 10:00:00', 'Asia/Taipei'), 60);

        $this->assertSame(10000.0, $snapshot['summary']['realizedTodayPnl']);
        $this->assertSame(210000.0, $snapshot['summary']['yearTotalPnl']);
        $this->assertSame(790000.0, $snapshot['summary']['yearReturnBase']);
        $this->assertEqualsWithDelta(210000 / 790000 * 100, $snapshot['summary']['yearTotalPnlRate'], 0.000001);
        $this->assertSame(176, $snapshot['summary']['yearElapsedDays']);
        $this->assertEqualsWithDelta((210000 / 790000 * 100) * 365 / 176, $snapshot['summary']['annualizedReturnRate'], 0.000001);
    }
}
