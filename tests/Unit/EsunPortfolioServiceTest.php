<?php

namespace Tests\Unit;

use App\Services\EsunPortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class EsunPortfolioServiceTest extends TestCase
{
    private bool $createdEsunSnapshotsTable = false;

    protected function tearDown(): void
    {
        if (Schema::hasTable('esun_portfolio_daily_snapshots')) {
            DB::table('esun_portfolio_daily_snapshots')
                ->where('snapshot_date', '2099-07-03')
                ->delete();
        }

        if ($this->createdEsunSnapshotsTable) {
            Schema::dropIfExists('esun_portfolio_daily_snapshots');
        }

        parent::tearDown();
    }

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

    public function test_it_uses_official_mis_previous_close_when_historical_etf_close_is_stale(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'mergeOfficialPreviousClose');

        $summary = $method->invoke($service, [
            'previousClose' => 36.28,
            'previousCloseDate' => '2026-06-30',
            'fiveDayReturn' => 1.0,
            'twentyDayReturn' => 2.0,
            'sixtyDayReturn' => 3.0,
            'yearToDateReturn' => 4.0,
        ], [
            'previousClose' => 38.42,
        ]);

        $this->assertSame(38.42, $summary['previousClose']);
        $this->assertSame('2026-06-30', $summary['previousCloseDate']);
        $this->assertSame(1.0, $summary['fiveDayReturn']);
        $this->assertSame(2.0, $summary['twentyDayReturn']);
        $this->assertSame(3.0, $summary['sixtyDayReturn']);
        $this->assertSame(4.0, $summary['yearToDateReturn']);
    }

    public function test_esun_minimum_query_seconds_uses_sixty_second_floor(): void
    {
        config()->set('esun.minimum_query_seconds', 30);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'minimumQuerySeconds');

        $this->assertSame(60, $method->invoke($service));
    }

    public function test_it_calculates_investment_level_from_esun_signed_settlements(): void
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
        $this->assertEqualsWithDelta(500000 / (500000 + 470000) * 100, $summary['investmentLevelRate'], 0.000001);
    }

    public function test_it_applies_live_style_negative_future_settlements_to_bank_balance(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'balanceSummary');

        $summary = $method->invoke($service, [
            'balance' => [
                'available_balance' => '590361',
                'exchange_balance' => '0',
            ],
            'settlements' => [
                ['c_date' => '20260708', 'date' => '20260706', 'price' => '-20219'],
                ['c_date' => '20260706', 'date' => '20260702', 'price' => '256'],
                ['c_date' => '20260707', 'date' => '20260703', 'price' => '-337206'],
            ],
        ], 1971378.0, CarbonImmutable::parse('2026-07-06 11:20:00', 'Asia/Taipei'));

        $this->assertSame(-357425.0, $summary['pendingSettlementAmount']);
        $this->assertSame(232936.0, $summary['bankBalance']);
    }

    public function test_it_summarizes_margin_usage_from_esun_margin_rows(): void
    {
        config()->set('esun.margin_limit_amount', 2000000);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
            [
                'tradeType' => '3',
                'priceAmount' => 417530.0,
                'cashCostBasis' => 168755.0,
                'marketValue' => 421080.0,
            ],
            [
                'tradeType' => '3',
                'priceAmount' => 345000.0,
                'cashCostBasis' => 139186.0,
                'marketValue' => 334000.0,
            ],
            [
                'tradeType' => '0',
                'priceAmount' => 264000.0,
                'cashCostBasis' => 264142.0,
                'marketValue' => 267000.0,
            ],
        ], [
            'balance' => [
                'available_balance' => 784777,
            ],
        ]);

        $this->assertSame(2000000.0, $summary['limitAmount']);
        $this->assertSame(454589.0, $summary['usedAmount']);
        $this->assertSame(1545411.0, $summary['availableAmount']);
        $this->assertEqualsWithDelta(755080 / 454589 * 100, $summary['maintenanceRate'], 0.000001);
    }

    public function test_it_can_derive_esun_margin_limit_from_reported_available_amount(): void
    {
        config()->set('esun.margin_limit_amount', null);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
            [
                'tradeType' => '3',
                'priceAmount' => 300000.0,
                'cashCostBasis' => 120000.0,
                'marketValue' => 310000.0,
            ],
        ], [
            'balance' => [
                'margin_available_balance' => 500000,
            ],
        ]);

        $this->assertSame(680000.0, $summary['limitAmount']);
        $this->assertSame(180000.0, $summary['usedAmount']);
        $this->assertSame(500000.0, $summary['availableAmount']);
    }

    public function test_it_prefers_esun_reported_margin_limit_for_available_amount(): void
    {
        config()->set('esun.margin_limit_amount', 2000000);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
            [
                'tradeType' => '3',
                'priceAmount' => 2271030.0,
                'cashCostBasis' => 915255.0,
                'marketValue' => 2262970.0,
            ],
        ], [
            'tradeStatus' => [
                'trade_limit' => 4000000,
                'margin_limit' => 1400000,
            ],
        ]);

        $this->assertSame(1400000.0, $summary['limitAmount']);
        $this->assertSame(1355775.0, $summary['usedAmount']);
        $this->assertSame(44225.0, $summary['availableAmount']);
    }

    public function test_it_uses_last_success_snapshot_during_minimum_query_window_after_short_cache_expires(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-26 09:23:00', 'Asia/Taipei'));
        Cache::forget('esun:portfolio:inventories:v5');
        Cache::forget('esun:portfolio:inventories:last-success:v5');
        Cache::forget('esun:portfolio:last-query-at:v5');
        Cache::forget('esun:portfolio:rate-limited-until:v5');

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
            Cache::forget('esun:portfolio:rate-limited-until:v5');
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
                'trade_status' => ['margin_limit' => 1400000],
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
        $this->assertSame(1400000, $payload['tradeStatus']['margin_limit']);
        $this->assertSame([], $payload['transactions']);
        $this->assertCount(1, $payload['todayTransactions']);
        $this->assertSame('2000', $payload['todayTransactions'][0]['make']);
        $this->assertSame('2026-01-01', $payload['todayTransactionsDate']);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/portfolio?today=2026-01-01'));
    }

    public function test_it_keeps_previous_settlements_when_current_settlement_query_is_rate_limited(): void
    {
        config()->set('esun.portfolio_enabled', true);
        config()->set('esun.daemon_url', 'http://127.0.0.1:8765');

        Http::fake([
            'http://127.0.0.1:8765/portfolio*' => Http::response([
                'queried_at' => '2026-07-06T02:50:00+00:00',
                'inventories' => [],
                'balance' => ['available_balance' => '590361'],
                'trade_status' => [],
                'settlements' => [],
                'today_transactions_history' => [],
                'today_transactions' => [],
                'warnings' => [
                    ['label' => 'settlements', 'error' => 'ValueError: AGR0006'],
                ],
            ]),
            'http://127.0.0.1:8765/transactions*' => Http::response([
                'queried_at' => '2026-07-06T02:50:00+00:00',
                'transactions' => [],
            ]),
        ]);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'queryInventories');
        $payload = $method->invoke(
            $service,
            CarbonImmutable::parse('2026-07-06 10:50:00', 'Asia/Taipei'),
            [
                'settlements' => [
                    ['c_date' => '20260708', 'price' => '-329588'],
                ],
            ],
        );

        $this->assertSame([['c_date' => '20260708', 'price' => '-329588']], $payload['settlements']);
        $this->assertSame('settlements', $payload['warnings'][0]['label']);
    }

    public function test_it_keeps_previous_today_realized_profit_when_current_payload_misses_today_transactions(): void
    {
        config()->set('esun.portfolio_enabled', true);
        config()->set('esun.daemon_url', 'http://127.0.0.1:8765');

        Http::fake([
            'http://127.0.0.1:8765/portfolio*' => Http::response([
                'queried_at' => '2026-06-30T02:00:00+00:00',
                'inventories' => [],
                'balance' => ['available_balance' => '100000'],
                'settlements' => [],
                'today_transactions_history' => [],
                'today_transactions' => [],
                'warnings' => [
                    ['label' => 'today_transactions_range', 'error' => 'AGR0003'],
                ],
            ]),
            'http://127.0.0.1:8765/transactions*' => Http::response([
                'queried_at' => '2026-06-30T02:00:00+00:00',
                'transactions' => [],
            ]),
        ]);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'queryInventories');
        $payload = $method->invoke(
            $service,
            CarbonImmutable::parse('2026-06-30 10:00:00', 'Asia/Taipei'),
            [
                'todayTransactions' => [
                    ['t_date' => '20260630', 'stk_no' => '3362', 'trade' => '3', 'qty' => '1000', 'make' => '-13857'],
                    ['t_date' => '20260630', 'stk_no' => '6548', 'trade' => '0', 'qty' => '1000', 'make' => '-5938'],
                    ['t_date' => '20260630', 'stk_no' => '6548', 'trade' => '3', 'qty' => '2000', 'make' => '-11115'],
                    ['t_date' => '20260630', 'stk_no' => '8016', 'trade' => '3', 'qty' => '1000', 'make' => '-11395'],
                ],
            ],
        );

        $sumMethod = new ReflectionMethod($service, 'sumTransactionProfit');

        $this->assertSame(-42305.0, $sumMethod->invoke($service, $payload['todayTransactions']));
    }

    public function test_it_keeps_fresh_inventory_when_year_transactions_query_fails(): void
    {
        config()->set('esun.portfolio_enabled', true);
        config()->set('esun.daemon_url', 'http://127.0.0.1:8765');

        Http::fake([
            'http://127.0.0.1:8765/portfolio*' => Http::response([
                'queried_at' => '2026-07-02T01:35:00+00:00',
                'inventories' => [
                    [
                        'stk_no' => '6271',
                        'stk_na' => '同欣電',
                        'trade' => '0',
                        'cost_qty' => '1000',
                    ],
                ],
                'balance' => ['available_balance' => '600000'],
                'settlements' => [],
                'today_transactions_history' => [],
                'today_transactions' => [],
                'warnings' => [],
            ]),
            'http://127.0.0.1:8765/transactions*' => Http::response([
                'error' => 'ValueError: AW00002: Parameter error',
            ], 503),
        ]);

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'queryInventories');
        $payload = $method->invoke(
            $service,
            CarbonImmutable::parse('2026-07-02 09:35:00', 'Asia/Taipei'),
            [
                'transactions' => [
                    ['t_date' => '20260630', 'stk_no' => '3362', 'trade' => '0', 'qty' => '1000', 'make' => '1000'],
                ],
                'todayTransactions' => [
                    ['t_date' => '20260701', 'stk_no' => '6548', 'trade' => '0', 'qty' => '1000', 'make' => '2000'],
                    ['t_date' => '20260702', 'stk_no' => '8016', 'trade' => '0', 'qty' => '1000', 'make' => '9999'],
                ],
            ],
        );

        $this->assertSame('2026-07-02T01:35:00+00:00', $payload['queriedAt']);
        $this->assertSame('6271', $payload['inventories'][0]['stk_no']);
        $this->assertSame('600000', $payload['balance']['available_balance']);
        $this->assertCount(2, $payload['transactions']);
        $this->assertSame(3000.0, (new ReflectionMethod($service, 'sumTransactionProfit'))->invoke($service, $payload['transactions']));
        $this->assertCount(1, $payload['todayTransactions']);
        $this->assertSame('20260702', $payload['todayTransactions'][0]['t_date']);
        $this->assertSame('year_transactions', $payload['warnings'][0]['label']);
        $this->assertStringContainsString('AW00002', $payload['warnings'][0]['error']);
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

    public function test_it_queries_year_transactions_in_monthly_chunks(): void
    {
        config()->set('esun.daemon_url', 'http://127.0.0.1:8765');

        foreach ([
            ['20990101', '20990131'],
            ['20990201', '20990228'],
            ['20990301', '20990331'],
            ['20990401', '20990430'],
            ['20990501', '20990531'],
            ['20990601', '20990630'],
            ['20990701', '20990705'],
        ] as [$start, $end]) {
            Cache::forget(sprintf('esun:portfolio:year-transactions:%s:%s:v2', $start, $end));
        }

        Http::fake(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $makeByStart = [
                '2099-01-01' => 100.0,
                '2099-02-01' => 200.0,
                '2099-03-01' => 300.0,
                '2099-04-01' => 400.0,
                '2099-05-01' => 500.0,
                '2099-06-01' => 600.0,
                '2099-07-01' => 700.0,
            ];

            return Http::response([
                'transactions' => [
                    [
                        't_date' => str_replace('-', '', (string) $query['start']),
                        'make' => $makeByStart[(string) $query['start']] ?? 0,
                    ],
                ],
            ]);
        });

        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'historicalYearTransactions');
        $transactions = $method->invoke(
            $service,
            CarbonImmutable::parse('2099-07-06 11:20:00', 'Asia/Taipei'),
        );

        $this->assertSame(7, count($transactions));
        $this->assertSame(2800.0, (new ReflectionMethod($service, 'sumTransactionProfit'))->invoke($service, $transactions));

        foreach ([
            ['2099-01-01', '2099-01-31'],
            ['2099-02-01', '2099-02-28'],
            ['2099-03-01', '2099-03-31'],
            ['2099-04-01', '2099-04-30'],
            ['2099-05-01', '2099-05-31'],
            ['2099-06-01', '2099-06-30'],
            ['2099-07-01', '2099-07-05'],
        ] as [$start, $end]) {
            Http::assertSent(fn ($request): bool => str_contains($request->url(), 'start=' . $start)
                && str_contains($request->url(), 'end=' . $end));
        }
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

    public function test_it_subtracts_today_realized_loss_from_year_history_total(): void
    {
        $service = new EsunPortfolioService();
        $method = new ReflectionMethod($service, 'yearProfitSummary');

        $summary = $method->invoke($service, [
            'transactions' => [
                ['t_date' => '20260629', 'stk_no' => '9999', 'trade' => '0', 'qty' => '1000', 'make' => '1198247'],
            ],
            'todayTransactions' => [
                ['t_date' => '20260630', 'stk_no' => '3362', 'trade' => '3', 'qty' => '1000', 'make' => '-42305'],
            ],
        ]);

        $this->assertSame(1198247.0, $summary['realizedHistoryPnl']);
        $this->assertSame(-42305.0, $summary['realizedTodayPnl']);
        $this->assertSame(1155942.0, $summary['realizedYearPnl']);
        $this->assertSame(1155942.0, $summary['yearTotalPnl']);
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

    public function test_it_stores_and_reads_daily_snapshot_payloads(): void
    {
        $this->migrateEsunDailySnapshotsTable();

        $service = new EsunPortfolioService();
        $snapshot = $service->storeDailySnapshot([
            'queriedAt' => '2099-07-03T09:54:50+00:00',
            'servedAt' => '2099-07-03T17:55:02+08:00',
            'cacheSeconds' => 600,
            'market' => [
                'isOpen' => false,
                'label' => '非交易時段',
            ],
            'source' => [
                'status' => 'live',
                'message' => '玉山 API 查詢成功。',
                'ageSeconds' => 12,
            ],
            'summary' => [
                'stockCount' => 2,
                'shareCount' => 2000,
                'marketValue' => 118000,
                'costBasis' => 130000,
                'todayPnl' => 16000,
                'unrealizedPnl' => -12000,
                'bankBalance' => 7506,
                'marginUsedAmount' => 603000,
                'marginAvailableAmount' => 397000,
            ],
            'rows' => [
                [
                    'stockNo' => '2303',
                    'todayPnl' => 16000,
                    'unrealizedPnl' => -12000,
                ],
            ],
        ], CarbonImmutable::parse('2099-07-03', 'Asia/Taipei'));

        $this->assertSame('2099-07-03', $snapshot->snapshot_date->toDateString());
        $this->assertSame(16000.0, $snapshot->today_pnl);
        $this->assertSame(-12000.0, $snapshot->unrealized_pnl);

        $dates = $service->dailySnapshotDates();
        $this->assertSame('2099-07-03', $dates[0]['date']);
        $this->assertSame(16000.0, $dates[0]['todayPnl']);

        $payload = $service->dailySnapshotPayload('2099-07-03');
        $this->assertSame('historical', $payload['source']['status']);
        $this->assertSame('2099-07-03', $payload['history']['date']);
        $this->assertSame('2099-07-03T17:54:50+08:00', $payload['history']['queriedAt']);
        $this->assertFalse($payload['market']['isOpen']);
        $this->assertSame(16000, $payload['summary']['todayPnl']);
        $this->assertSame('2303', $payload['rows'][0]['stockNo']);
    }

    private function migrateEsunDailySnapshotsTable(): void
    {
        if (Schema::hasTable('esun_portfolio_daily_snapshots')) {
            DB::table('esun_portfolio_daily_snapshots')
                ->where('snapshot_date', '2099-07-03')
                ->delete();

            return;
        }

        $migration = require base_path('database/migrations/2026_07_03_112000_create_esun_portfolio_daily_snapshots_table.php');
        $migration->up();
        $this->createdEsunSnapshotsTable = true;
    }
}
