<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockDailyPricesTest extends TestCase
{
    private string $originalDatabaseDefault;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
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

        Carbon::setTestNow('2026-05-09 10:00:00');
        CarbonImmutable::setTestNow('2026-05-09 10:00:00');

        Schema::dropAllTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tw_stock_annual_financial_comparisons');
        Schema::dropIfExists('tw_stock_q1_financial_reports');
        Schema::dropIfExists('tw_stock_daily_prices');

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_latest_command_stores_daily_prices_and_pages_link_to_detail(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_starts_with($request->url(), 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL') => Http::response([
                [
                    'Date' => '1150508',
                    'Code' => '8261',
                    'Name' => '富鼎',
                    'TradeVolume' => '4695000',
                    'TradeValue' => '591570000',
                    'OpeningPrice' => '122.00',
                    'HighestPrice' => '128.00',
                    'LowestPrice' => '120.00',
                    'ClosingPrice' => '126.00',
                    'Change' => '-2.00',
                    'Transaction' => '3210',
                ],
            ]),
            str_starts_with($request->url(), 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes') => Http::response([
                [
                    'Date' => '1150508',
                    'SecuritiesCompanyCode' => '5289',
                    'CompanyName' => '宜鼎',
                    'Close' => '1600.00',
                    'Change' => '+25.00',
                    'Open' => '1550.00',
                    'High' => '1620.00',
                    'Low' => '1540.00',
                    'TradingShares' => '7080000',
                    'TransactionAmount' => '11328000000',
                    'TransactionNumber' => '4200',
                ],
            ]),
            default => Http::response([], 404),
        });

        $this->artisan('tw-stock:fetch-daily-prices', ['--latest' => true])->assertExitCode(0);

        $this->assertSame(2, DB::table('tw_stock_daily_prices')->count());
        $fude = DB::table('tw_stock_daily_prices')->where('stock_code', '8261')->first();
        $this->assertSame('2026-05-08', substr((string) $fude->trade_date, 0, 10));
        $this->assertEqualsWithDelta(126, (float) $fude->close_price, 0.0001);
        $this->assertEqualsWithDelta(-1.5625, (float) $fude->price_change_percent, 0.0001);

        $this->get(route('tw-stock.daily-prices.index'))
            ->assertOk()
            ->assertSee('台股每日漲幅排行')
            ->assertSee('每日漲幅')
            ->assertSee('K 線')
            ->assertSee('5289')
            ->assertSee('8261');

        $this->get(route('tw-stock.daily-prices.show', ['stockCode' => '8261', 'exchange' => 'TWSE']))
            ->assertOk()
            ->assertSee('8261 富鼎 K 線')
            ->assertSee('日 K 線與成交量')
            ->assertSee('data-overlay-mode="ma"', false)
            ->assertSee('data-overlay-mode="bollinger"', false)
            ->assertSee('布林軌道')
            ->assertSee('DEFAULT_VISIBLE_TRADING_DAYS = 22', false)
            ->assertSee('fixRightEdge: true', false)
            ->assertSee('setVisibleLogicalRange', false)
            ->assertDontSee('chart.timeScale().fitContent()', false)
            ->assertSee('lightweight-charts', false);
    }

    public function test_backfill_command_stores_yahoo_kline_rows(): void
    {
        Http::fake(fn ($request) => str_starts_with($request->url(), 'https://query1.finance.yahoo.com/v8/finance/chart/')
            ? Http::response([
                'chart' => [
                    'result' => [[
                        'timestamp' => [
                            CarbonImmutable::parse('2024-01-02 12:00:00', 'Asia/Taipei')->timestamp,
                            CarbonImmutable::parse('2024-01-03 12:00:00', 'Asia/Taipei')->timestamp,
                        ],
                        'indicators' => [
                            'quote' => [[
                                'open' => [100, 105],
                                'high' => [110, 112],
                                'low' => [95, 103],
                                'close' => [108, 111],
                                'volume' => [1000000, 1500000],
                            ]],
                        ],
                    ]],
                    'error' => null,
                ],
            ])
            : Http::response([], 404));

        $this->artisan('tw-stock:fetch-daily-prices', [
            '--backfill' => true,
            '--from' => '2024-01-01',
            '--to' => '2024-01-03',
            '--exchange' => 'TWSE',
            '--stock-code' => '8261',
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('tw_stock_daily_prices')->count());
        $row = DB::table('tw_stock_daily_prices')->where('trade_date', '2024-01-03')->first();
        $this->assertSame('8261', $row->stock_code);
        $this->assertEqualsWithDelta(111, (float) $row->close_price, 0.0001);
        $this->assertEqualsWithDelta(2.7778, (float) $row->price_change_percent, 0.0001);
        $this->assertSame(1500, (int) $row->volume_lots);
    }

    private function createTables(): void
    {
        Schema::create('tw_stock_daily_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->date('trade_date');
            $table->decimal('open_price', 12, 4)->nullable();
            $table->decimal('high_price', 12, 4)->nullable();
            $table->decimal('low_price', 12, 4)->nullable();
            $table->decimal('close_price', 12, 4);
            $table->decimal('previous_close_price', 12, 4)->nullable();
            $table->decimal('price_change_amount', 12, 4)->nullable();
            $table->decimal('price_change_percent', 10, 4)->nullable();
            $table->unsignedBigInteger('volume_lots')->default(0);
            $table->unsignedBigInteger('volume_shares')->default(0);
            $table->unsignedBigInteger('trade_value')->nullable();
            $table->unsignedInteger('transaction_count')->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['exchange', 'stock_code', 'trade_date']);
        });

        Schema::create('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter');
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
        });

        Schema::create('tw_stock_annual_financial_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
        });
    }
}
