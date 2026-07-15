<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockTaiexIndexKlineTest extends TestCase
{
    private string $originalDatabaseDefault;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00:00', 'Asia/Taipei'));

        if (! extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this feature test.');
        }

        $this->originalDatabaseDefault = (string) config('database.default');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');
        Schema::dropAllTables();
        $this->createInstitutionalFlowsTable();
        Cache::store('file')->forget('tw-stock:taiex-index:twse-feed:v1');
        Cache::store('file')->forget('tw-stock:taiex-index:yahoo-minute:2026-07-01:2026-07-14:v1');
    }

    protected function tearDown(): void
    {
        Cache::store('file')->forget('tw-stock:taiex-index:twse-feed:v1');
        Cache::store('file')->forget('tw-stock:taiex-index:yahoo-minute:2026-07-01:2026-07-14:v1');
        Schema::dropIfExists('tw_stock_institutional_flows');
        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_kline_page_is_independent_and_linked_from_tw_stock_home(): void
    {
        $this->get(route('tw-stock.taiex-index.kline'))
            ->assertOk()
            ->assertSee('加權指數 K 線分析')
            ->assertSee('1分K線')
            ->assertSee('5分K線')
            ->assertSee('15分K線')
            ->assertSee('日K線')
            ->assertSee('高點趨勢線')
            ->assertSee('低點趨勢線')
            ->assertSee('水平頸線')
            ->assertSee('const refreshEveryMs = 15000;', false)
            ->assertSee('computeTrendLine', false)
            ->assertSee('computeHorizontalNeckline', false)
            ->assertSee('subscribeCrosshairMove', false)
            ->assertSee('LightweightCharts.LineStyle.LargeDashed', false)
            ->assertSee('水平價格虛線')
            ->assertSee('data-hover="close"', false)
            ->assertSee('const dataUrl =', false);

        $this->get(route('tw-stock.index'))
            ->assertOk()
            ->assertSee('加權指數K線')
            ->assertDontSee('id="taiexChart"', false);
    }

    public function test_data_endpoint_aggregates_twse_minute_points_into_five_minute_candles(): void
    {
        Http::fake([
            'https://mis.twse.com.tw/stock/api/getChartOhlcStatis.jsp*' => Http::response($this->twseFeed()),
            'https://query1.finance.yahoo.com/v8/finance/chart/*' => Http::response($this->emptyYahooHistory()),
        ]);

        $response = $this->getJson(route('tw-stock.taiex-index.kline.data', ['interval' => '5m']))
            ->assertOk()
            ->assertJsonPath('symbol', 'TAIEX')
            ->assertJsonPath('interval', '5m')
            ->assertJsonPath('intervalLabel', '5 分 K')
            ->assertJsonPath('refreshSeconds', 15)
            ->assertJsonPath('sourceNote', '7/1 起歷史分 K 使用 Yahoo Finance 的 TAIEX 分鐘 OHLC；當日以 TWSE 分時指數補齊，最新指數每 15 秒更新。')
            ->assertJsonPath('quote.latest', 103)
            ->assertJsonPath('quote.previousClose', 95)
            ->assertJsonCount(2, 'bars');

        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $bars = $response->json('bars');
        $this->assertSame(98.0, (float) $bars[0]['open']);
        $this->assertSame(102.0, (float) $bars[0]['high']);
        $this->assertSame(98.0, (float) $bars[0]['low']);
        $this->assertSame(99.0, (float) $bars[0]['close']);
        $this->assertSame(60, $bars[0]['volume']);
        $this->assertSame(99.0, (float) $bars[1]['open']);
        $this->assertSame(103.0, (float) $bars[1]['high']);
        $this->assertSame(99.0, (float) $bars[1]['low']);
        $this->assertSame(103.0, (float) $bars[1]['close']);
        $this->assertSame(40, $bars[1]['volume']);
    }

    public function test_intraday_endpoint_includes_historical_minute_ohlc_from_july_first(): void
    {
        Http::fake([
            'https://mis.twse.com.tw/stock/api/getChartOhlcStatis.jsp*' => Http::response($this->twseFeed()),
            'https://query1.finance.yahoo.com/v8/finance/chart/*' => Http::response($this->yahooHistory()),
        ]);

        $response = $this->getJson(route('tw-stock.taiex-index.kline.data', ['interval' => '1m']))
            ->assertOk();

        $bars = $response->json('bars');
        $this->assertGreaterThan(4, count($bars));
        $this->assertSame('2026-07-01 09:00', $bars[0]['localTime']);
        $this->assertSame(44000.0, (float) $bars[0]['open']);
        $this->assertSame(44025.0, (float) $bars[0]['high']);
        $this->assertSame(43990.0, (float) $bars[0]['low']);
        $this->assertSame(44020.0, (float) $bars[0]['close']);
    }

    public function test_daily_interval_uses_stored_twse_ohlc_and_appends_live_day(): void
    {
        DB::table('tw_stock_institutional_flows')->insert([
            [
                'trade_date' => '2026-07-13',
                'taiex_open_index' => 90,
                'taiex_high_index' => 96,
                'taiex_low_index' => 89,
                'taiex_close_index' => 94,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'trade_date' => '2026-07-14',
                'taiex_open_index' => 94,
                'taiex_high_index' => 98,
                'taiex_low_index' => 92,
                'taiex_close_index' => 95,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        Http::fake([
            'https://mis.twse.com.tw/stock/api/getChartOhlcStatis.jsp*' => Http::response($this->twseFeed()),
            'https://query1.finance.yahoo.com/v8/finance/chart/*' => Http::response($this->emptyYahooHistory()),
        ]);

        $response = $this->getJson(route('tw-stock.taiex-index.kline.data', ['interval' => '1d']))
            ->assertOk()
            ->assertJsonPath('intervalLabel', '日 K')
            ->assertJsonCount(3, 'bars');

        $latest = $response->json('bars.2');
        $this->assertSame('2026-07-15', $latest['localTime']);
        $this->assertSame(98.0, (float) $latest['open']);
        $this->assertSame(104.0, (float) $latest['high']);
        $this->assertSame(97.0, (float) $latest['low']);
        $this->assertSame(103.0, (float) $latest['close']);
    }

    public function test_data_endpoint_rejects_unknown_interval(): void
    {
        $this->getJson(route('tw-stock.taiex-index.kline.data', ['interval' => '30m']))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'interval 必須是 1m、5m、15m 或 1d。');
    }

    /**
     * @return array<string, mixed>
     */
    private function twseFeed(): array
    {
        $timestamp = static fn (string $time): int => Carbon::parse('2026-07-15 ' . $time, 'Asia/Taipei')->timestamp * 1000;

        return [
            'rtcode' => '0000',
            'rtmessage' => 'OK',
            'frequency' => 1,
            'lastIndex' => '103.00',
            'ohlcArray' => [
                ['c' => '100.00', 's' => '10', 't' => (string) $timestamp('09:01:00'), 'ts' => '090100'],
                ['c' => '102.00', 's' => '20', 't' => (string) $timestamp('09:02:00'), 'ts' => '090200'],
                ['c' => '99.00', 's' => '30', 't' => (string) $timestamp('09:03:00'), 'ts' => '090300'],
                ['c' => '101.00', 's' => '40', 't' => (string) $timestamp('09:05:00'), 'ts' => '090500'],
            ],
            'infoArray' => [[
                'c' => 't00',
                'n' => '發行量加權股價指數',
                'd' => '20260715',
                't' => '09:05:30',
                'z' => '103.00',
                'o' => '98.00',
                'h' => '104.00',
                'l' => '97.00',
                'y' => '95.00',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyYahooHistory(): array
    {
        return [
            'chart' => [
                'result' => [[
                    'timestamp' => [],
                    'indicators' => ['quote' => [[]]],
                ]],
                'error' => null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function yahooHistory(): array
    {
        $timestamp = static fn (string $time): int => Carbon::parse('2026-07-01 ' . $time, 'Asia/Taipei')->timestamp;

        return [
            'chart' => [
                'result' => [[
                    'timestamp' => [
                        $timestamp('09:00:00'),
                        $timestamp('09:01:00'),
                    ],
                    'indicators' => [
                        'quote' => [[
                            'open' => [44000, 44020],
                            'high' => [44025, 44040],
                            'low' => [43990, 44010],
                            'close' => [44020, 44035],
                            'volume' => [100, 120],
                        ]],
                    ],
                ]],
                'error' => null,
            ],
        ];
    }

    private function createInstitutionalFlowsTable(): void
    {
        Schema::create('tw_stock_institutional_flows', function (Blueprint $table): void {
            $table->id();
            $table->date('trade_date')->unique();
            $table->bigInteger('foreign_stock_net_amount')->nullable();
            $table->bigInteger('investment_trust_stock_net_amount')->nullable();
            $table->integer('foreign_txf_open_interest_net_contracts')->nullable();
            $table->integer('investment_trust_txf_open_interest_net_contracts')->nullable();
            $table->decimal('taiex_open_index', 12, 2)->nullable();
            $table->decimal('taiex_high_index', 12, 2)->nullable();
            $table->decimal('taiex_low_index', 12, 2)->nullable();
            $table->decimal('taiex_close_index', 12, 2)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }
}
