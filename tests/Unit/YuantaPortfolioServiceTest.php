<?php

namespace Tests\Unit;

use App\Services\YuantaPortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class YuantaPortfolioServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('yuanta_portfolio_daily_snapshots');

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

    public function test_it_subtracts_future_settlements_from_yuanta_available_balance(): void
    {
        $service = new YuantaPortfolioService();
        $method = new ReflectionMethod($service, 'balanceSummary');

        $summary = $method->invoke($service, [
            'balance' => [
                ['AvailableBalance' => 48971],
            ],
            'settlements' => [
                ['SettlementDay' => '2026/07/02', 'SettlementAmt' => -466131],
                ['SettlementDay' => '2026/07/03', 'SettlementAmt' => -41624],
                ['SettlementDay' => '2026/07/06', 'SettlementAmt' => -6485],
            ],
        ], 1590986.0, CarbonImmutable::parse('2026-07-02 09:45:00', 'Asia/Taipei'));

        $this->assertSame(48971.0, $summary['availableBalance']);
        $this->assertSame(48109.0, $summary['pendingSettlementAmount']);
        $this->assertSame(862.0, $summary['bankBalance']);
        $this->assertEqualsWithDelta(1590986 / (1590986 + 862) * 100, $summary['investmentLevelRate'], 0.000001);
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

    public function test_it_stores_and_reads_daily_snapshot_payloads(): void
    {
        $this->migrateYuantaDailySnapshotsTable();

        $service = new YuantaPortfolioService();
        $snapshot = $service->storeDailySnapshot([
            'queriedAt' => '2026-07-03T17:54:50+08:00',
            'servedAt' => '2026-07-03T17:55:02+08:00',
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
        ], CarbonImmutable::parse('2026-07-03', 'Asia/Taipei'));

        $this->assertSame('2026-07-03', $snapshot->snapshot_date->toDateString());
        $this->assertSame(16000.0, $snapshot->today_pnl);
        $this->assertSame(-12000.0, $snapshot->unrealized_pnl);

        $dates = $service->dailySnapshotDates();
        $this->assertSame('2026-07-03', $dates[0]['date']);
        $this->assertSame(16000.0, $dates[0]['todayPnl']);

        $payload = $service->dailySnapshotPayload('2026-07-03');
        $this->assertSame('historical', $payload['source']['status']);
        $this->assertSame('2026-07-03', $payload['history']['date']);
        $this->assertFalse($payload['market']['isOpen']);
        $this->assertSame(16000, $payload['summary']['todayPnl']);
        $this->assertSame('2303', $payload['rows'][0]['stockNo']);
    }

    private function migrateYuantaDailySnapshotsTable(): void
    {
        Schema::dropIfExists('yuanta_portfolio_daily_snapshots');

        $migration = require base_path('database/migrations/2026_07_03_110000_create_yuanta_portfolio_daily_snapshots_table.php');
        $migration->up();
    }
}
