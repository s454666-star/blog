<?php

namespace Tests\Unit;

use App\Services\YuantaPortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class YuantaPortfolioServiceTest extends TestCase
{
    private bool $createdYuantaSnapshotsTable = false;

    protected function tearDown(): void
    {
        if (Schema::hasTable('yuanta_portfolio_daily_snapshots')) {
            DB::table('yuanta_portfolio_daily_snapshots')
                ->where('snapshot_date', '2099-07-03')
                ->delete();
        }

        if ($this->createdYuantaSnapshotsTable) {
            Schema::dropIfExists('yuanta_portfolio_daily_snapshots');
        }

        parent::tearDown();
    }

    public function test_it_summarizes_margin_usage_from_yuanta_loan_fields(): void
    {
        config()->set('yuanta.margin_limit_amount', 1000000);

        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
            [
                'tradeType' => '3',
                'marketValue' => 101000,
                'raw' => ['loan' => 60000],
            ],
            [
                'tradeType' => '3',
                'marketValue' => 38430,
                'raw' => ['loan' => 23000],
            ],
            [
                'tradeType' => '3',
                'marketValue' => 305250,
                'raw' => ['loan' => 179000],
            ],
            [
                'tradeType' => '3',
                'marketValue' => 563400,
                'raw' => ['loan' => 334000],
            ],
            [
                'tradeType' => '0',
                'marketValue' => 153750,
                'raw' => ['loan' => 0],
            ],
        ], [
            'balance' => [
                ['AvailableBalance' => 544980],
            ],
        ]);

        $this->assertSame(1000000.0, $summary['limitAmount']);
        $this->assertSame(596000.0, $summary['usedAmount']);
        $this->assertSame(404000.0, $summary['availableAmount']);
        $this->assertEqualsWithDelta(169.14, $summary['maintenanceRate'], 0.01);
    }

    public function test_it_does_not_treat_bank_balance_as_margin_available_amount(): void
    {
        config()->set('yuanta.margin_limit_amount', null);

        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'marginSummary');

        $summary = $method->invoke($service, [
            [
                'tradeType' => '3',
                'marketValue' => 305250,
                'raw' => ['loan' => 179000],
            ],
        ], [
            'balance' => [
                ['AvailableBalance' => 544980],
            ],
        ]);

        $this->assertNull($summary['limitAmount']);
        $this->assertSame(179000.0, $summary['usedAmount']);
        $this->assertNull($summary['availableAmount']);
    }

    public function test_it_applies_future_settlements_with_yuanta_signs(): void
    {
        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'balanceSummary');

        $summary = $method->invoke($service, [
            'balance' => [
                ['AvailableBalance' => 51607],
            ],
            'settlements' => [
                ['SettlementDay' => '2026/07/06', 'SettlementAmt' => -5926],
                ['SettlementDay' => '2026/07/08', 'SettlementAmt' => 48377],
            ],
        ], 1590986.0, CarbonImmutable::parse('2026-07-06 10:45:00', 'Asia/Taipei'));

        $this->assertSame(51607.0, $summary['availableBalance']);
        $this->assertSame(48377.0, $summary['pendingSettlementAmount']);
        $this->assertSame(99984.0, $summary['bankBalance']);
        $this->assertEqualsWithDelta(1590986 / (1590986 + 99984) * 100, $summary['investmentLevelRate'], 0.000001);
    }

    public function test_it_reads_twse_mis_previous_close_for_yuanta_holdings(): void
    {
        Cache::flush();
        Http::fake([
            'https://mis.twse.com.tw/stock/api/getStockInfo.jsp*' => Http::response([
                'msgArray' => [
                    ['c' => '5269', 'y' => '1520'],
                    ['c' => '00685L', 'y' => '306.50'],
                ],
            ]),
        ]);

        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'twseMisPreviousCloses');

        $previousCloses = $method->invoke($service, collect(['5269', '00685L']));

        $this->assertSame(1520.0, $previousCloses['5269']['previousClose']);
        $this->assertSame(306.5, $previousCloses['00685L']['previousClose']);
    }

    public function test_it_uses_official_previous_close_for_yuanta_today_pnl(): void
    {
        $service = new YuantaPortfolioService();
        $mergeMethod = new ReflectionMethod($service, 'mergeOfficialPreviousClose');
        $rowMethod = new ReflectionMethod($service, 'formatInventoryRow');

        $history = $mergeMethod->invoke($service, [
            'previousClose' => 1470.0,
            'fiveDayReturn' => 1.0,
            'twentyDayReturn' => 2.0,
            'sixtyDayReturn' => 3.0,
            'yearToDateReturn' => 4.0,
        ], [
            'previousClose' => 1520.0,
        ]);

        $row = $rowMethod->invoke($service, [
            'StkCode' => '5269',
            'StkName' => '祥碩',
            'StockQty' => 77,
            'MarketPrice' => 1560,
            'MarketAmt' => 120120,
            'ReturnAmt' => 7664,
            'Cost' => 111925,
            'Price' => 1453.57,
            'TradeKind' => '0',
        ], $history);

        $this->assertSame(1520.0, $row['previousClose']);
        $this->assertSame(40.0, $row['dayChange']);
        $this->assertSame(3080.0, $row['todayPnl']);
        $this->assertSame(7664.0, $row['unrealizedPnl']);
    }

    public function test_it_calculates_yuanta_breakeven_prices_with_sale_costs_and_valid_ticks(): void
    {
        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'formatInventoryRow');

        $stock = $method->invoke($service, [
            'StkCode' => '6669',
            'StkName' => '緯穎',
            'StockQty' => 25,
            'MarketPrice' => 5095,
            'MarketAmt' => 127375,
            'ReturnAmt' => 5719,
            'Cost' => 121093,
            'Price' => 4843.72,
            'TaxRate' => 3,
            'StkType1' => 0,
            'TradeKind' => '0',
        ], []);
        $smallEtf = $method->invoke($service, [
            'StkCode' => '00905',
            'StkName' => 'FT臺灣Smart',
            'StockQty' => 1,
            'MarketPrice' => 27.56,
            'MarketAmt' => 28,
            'ReturnAmt' => -10,
            'Cost' => 18,
            'Price' => 18,
            'TaxRate' => 1,
            'StkType1' => 12,
            'TradeKind' => '0',
        ], []);
        $etfAboveFifty = $method->invoke($service, [
            'StkCode' => '00631L',
            'StkName' => '元大台灣50正2',
            'StockQty' => 1,
            'MarketPrice' => 37.04,
            'MarketAmt' => 37,
            'ReturnAmt' => -21,
            'Cost' => 38,
            'Price' => 38,
            'TaxRate' => 1,
            'StkType1' => 12,
            'TradeKind' => '0',
        ], []);

        $this->assertSame(4870.0, $stock['breakevenPrice']);
        $this->assertSame(37.5, $smallEtf['breakevenPrice']);
        $this->assertSame(57.5, $etfAboveFifty['breakevenPrice']);
    }

    public function test_it_fills_missing_yuanta_etf_returns_from_yahoo_history(): void
    {
        Cache::flush();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2099-07-15 18:00:00', 'Asia/Taipei'));

        try {
            $timestamps = [];
            $closes = [];
            $start = CarbonImmutable::parse('2099-05-15 13:30:00', 'Asia/Taipei');
            for ($index = 0; $index <= 60; $index++) {
                $timestamps[] = $start->addDays($index)->utc()->timestamp;
                $closes[] = 100 + $index;
            }

            Http::fake([
                'https://query1.finance.yahoo.com/v8/finance/chart/*' => Http::response([
                    'chart' => [
                        'result' => [[
                            'timestamp' => $timestamps,
                            'indicators' => [
                                'quote' => [[
                                    'close' => $closes,
                                ]],
                            ],
                        ]],
                        'error' => null,
                    ],
                ]),
                'https://mis.twse.com.tw/stock/api/getStockInfo.jsp*' => Http::response([
                    'msgArray' => [
                        ['c' => '00905', 'y' => '160'],
                    ],
                ]),
            ]);

            $service = new YuantaPortfolioService();
            $method = new ReflectionMethod($service, 'historicalPrices');
            $history = $method->invoke($service, collect(['00905']), collect());

            $this->assertSame(160.0, $history['00905']['previousClose']);
            $this->assertEqualsWithDelta((160 - 156) / 156 * 100, $history['00905']['fiveDayReturn'], 0.000001);
            $this->assertEqualsWithDelta((160 - 141) / 141 * 100, $history['00905']['twentyDayReturn'], 0.000001);
            $this->assertEqualsWithDelta((160 - 101) / 101 * 100, $history['00905']['sixtyDayReturn'], 0.000001);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_it_marks_only_the_yuanta_quantity_added_since_the_previous_daily_snapshot(): void
    {
        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'annotateTodayAddedQuantities');

        $rows = $method->invoke($service, [
            ['stockNo' => '2303', 'tradeType' => '0', 'quantity' => 9000],
            ['stockNo' => '2330', 'tradeType' => '0', 'quantity' => 9000],
        ], [
            ['stockNo' => '2303', 'tradeType' => '0', 'quantity' => 7000],
            ['stockNo' => '2330', 'tradeType' => '0', 'quantity' => 9000],
        ]);

        $this->assertSame(2000.0, $rows[0]['todayAddedQuantity']);
        $this->assertNull($rows[1]['todayAddedQuantity']);
    }

    public function test_it_recognizes_emerging_market_from_yuanta_inventory(): void
    {
        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'mergeInventoryExchangeMetadata');

        $metadata = $method->invoke($service, collect([
            [
                'StkCode' => '7861',
                'MarketName' => '興櫃',
            ],
        ]), []);

        $this->assertSame('Emerging', $metadata['7861']['exchange']);
        $this->assertSame('興櫃', $metadata['7861']['label']);
        $this->assertSame('興', $metadata['7861']['shortLabel']);
        $this->assertSame('emerging', $metadata['7861']['class']);
    }

    public function test_it_stores_and_reads_daily_snapshot_payloads(): void
    {
        $this->migrateYuantaDailySnapshotsTable();

        $service = new YuantaPortfolioService();
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
                'message' => '元大 API 查詢成功。',
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
        $this->assertSame(130000.0, $dates[0]['costBasis']);
        $this->assertSame(16000.0, $dates[0]['todayPnl']);

        $payload = $service->dailySnapshotPayload('2099-07-03');
        $this->assertSame('historical', $payload['source']['status']);
        $this->assertSame('2099-07-03', $payload['history']['date']);
        $this->assertSame('2099-07-03T17:54:50+08:00', $payload['history']['queriedAt']);
        $this->assertFalse($payload['market']['isOpen']);
        $this->assertSame(16000, $payload['summary']['todayPnl']);
        $this->assertSame('2303', $payload['rows'][0]['stockNo']);
    }

    private function migrateYuantaDailySnapshotsTable(): void
    {
        if (Schema::hasTable('yuanta_portfolio_daily_snapshots')) {
            DB::table('yuanta_portfolio_daily_snapshots')
                ->where('snapshot_date', '2099-07-03')
                ->delete();

            return;
        }

        $migration = require base_path('database/migrations/2026_07_03_110000_create_yuanta_portfolio_daily_snapshots_table.php');
        $migration->up();
        $this->createdYuantaSnapshotsTable = true;
    }
}
