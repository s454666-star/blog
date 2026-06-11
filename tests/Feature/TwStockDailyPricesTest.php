<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();

        Carbon::setTestNow('2026-05-09 10:00:00');
        CarbonImmutable::setTestNow('2026-05-09 10:00:00');

        Schema::dropAllTables();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tw_stock_company_profiles');
        Schema::dropIfExists('tw_stock_annual_financial_comparisons');
        Schema::dropIfExists('tw_stock_q1_financial_reports');
        Schema::dropIfExists('tw_stock_daily_turnover_rates');
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
            str_starts_with($request->url(), 'https://www.twse.com.tw/exchangeReport/STOCK_DAY_ALL') => Http::response([
                'stat' => 'OK',
                'date' => '20260508',
                'data' => [
                    [
                        '8261',
                        '富鼎',
                        '4,695,000',
                        '591,570,000',
                        '122.00',
                        '128.00',
                        '120.00',
                        '126.00',
                        '-2.00',
                        '3,210',
                    ],
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
            ->assertSee('DEFAULT_VISIBLE_TRADING_DAYS = 44', false)
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

    public function test_daily_turnover_command_stores_twse_and_tpex_rows(): void
    {
        Http::fake(fn ($request) => match (true) {
            str_starts_with($request->url(), 'https://openapi.twse.com.tw/v1/opendata/t187ap03_L') => Http::response([
                [
                    '公司代號' => '2408',
                    '公司簡稱' => '南亞科',
                    '已發行普通股數或TDR原股發行股數' => '1,000,000',
                ],
            ]),
            str_starts_with($request->url(), 'https://www.twse.com.tw/exchangeReport/MI_INDEX') => Http::response([
                'stat' => 'OK',
                'tables' => [
                    [
                        'title' => '115年05月25日 每日收盤行情(全部(不含權證、牛熊證、可展延牛熊證))',
                        'fields' => [
                            '證券代號',
                            '證券名稱',
                            '成交股數',
                            '成交筆數',
                            '成交金額',
                            '開盤價',
                            '最高價',
                            '最低價',
                            '收盤價',
                            '漲跌(+/-)',
                            '漲跌價差',
                            '本益比',
                        ],
                        'data' => [
                            [
                                '2408',
                                '南亞科',
                                '25,000',
                                '120',
                                '1,000,000',
                                '40.00',
                                '41.00',
                                '39.50',
                                '40.50',
                                '+',
                                '0.50',
                                '20.00',
                            ],
                        ],
                    ],
                ],
            ]),
            str_starts_with($request->url(), 'https://www.tpex.org.tw/web/stock/aftertrading/daily_turnover/trn_result.php') => Http::response([
                'stat' => 'ok',
                'tables' => [
                    [
                        'title' => '上櫃股票個股週轉率排行',
                        'date' => '115/05/25',
                        'fields' => ['排行', '股票代號', '股票名稱', '總成交股數', '發行股數', '週轉率(%)'],
                        'data' => [
                            ['1', '8261', '富鼎', '50,000', '2,000,000', '2.50'],
                        ],
                    ],
                ],
            ]),
            default => Http::response([], 404),
        });

        $this->artisan('tw-stock:fetch-daily-turnover-rates', [
            '--date' => '2026-05-25',
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('tw_stock_daily_turnover_rates')->count());

        $twse = DB::table('tw_stock_daily_turnover_rates')->where('stock_code', '2408')->first();
        $this->assertSame('TWSE', $twse->exchange);
        $this->assertSame('2026-05-25', substr((string) $twse->trade_date, 0, 10));
        $this->assertSame(25000, (int) $twse->trading_shares);
        $this->assertSame(1000000, (int) $twse->issued_shares);
        $this->assertEqualsWithDelta(2.5, (float) $twse->turnover_rate_percent, 0.0001);

        $tpex = DB::table('tw_stock_daily_turnover_rates')->where('stock_code', '8261')->first();
        $this->assertSame('TPEx', $tpex->exchange);
        $this->assertSame(1, (int) $tpex->rank);
        $this->assertEqualsWithDelta(2.5, (float) $tpex->turnover_rate_percent, 0.0001);
    }

    public function test_daily_turnover_command_can_backfill_recent_days(): void
    {
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://openapi.twse.com.tw/v1/opendata/t187ap03_L')) {
                return Http::response([
                    [
                        '公司代號' => '2408',
                        '公司簡稱' => '南亞科',
                        '已發行普通股數或TDR原股發行股數' => '1,000,000',
                    ],
                ]);
            }

            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            if (str_starts_with($request->url(), 'https://www.twse.com.tw/exchangeReport/MI_INDEX')) {
                $date = CarbonImmutable::createFromFormat('Ymd', (string) ($query['date'] ?? '20260509'));
                $titleDate = sprintf('%d年%02d月%02d日', $date->year - 1911, $date->month, $date->day);

                return Http::response([
                    'stat' => 'OK',
                    'tables' => [
                        [
                            'title' => $titleDate . ' 每日收盤行情(全部(不含權證、牛熊證、可展延牛熊證))',
                            'fields' => ['證券代號', '證券名稱', '成交股數'],
                            'data' => [
                                ['2408', '南亞科', '25,000'],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_starts_with($request->url(), 'https://www.tpex.org.tw/web/stock/aftertrading/daily_turnover/trn_result.php')) {
                return Http::response([
                    'stat' => 'ok',
                    'tables' => [
                        [
                            'title' => '上櫃股票個股週轉率排行',
                            'date' => (string) ($query['d'] ?? '115/05/09'),
                            'fields' => ['排行', '股票代號', '股票名稱', '總成交股數', '發行股數', '週轉率(%)'],
                            'data' => [
                                ['1', '8261', '富鼎', '50,000', '2,000,000', '2.50'],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response([], 404);
        });

        $this->artisan('tw-stock:fetch-daily-turnover-rates', [
            '--recent-days' => 3,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $dates = DB::table('tw_stock_daily_turnover_rates')
            ->select('trade_date')
            ->distinct()
            ->orderBy('trade_date')
            ->pluck('trade_date')
            ->map(fn ($date): string => substr((string) $date, 0, 10))
            ->all();

        $this->assertSame(['2026-05-07', '2026-05-08', '2026-05-09'], $dates);
        $this->assertSame(6, DB::table('tw_stock_daily_turnover_rates')->count());
        $this->assertSame(3, DB::table('tw_stock_daily_turnover_rates')->where('exchange', 'TPEx')->count());
    }

    public function test_daily_turnover_command_falls_back_to_daily_prices_when_twse_source_fails(): void
    {
        DB::table('tw_stock_daily_prices')->insert([
            [
                'exchange' => 'TWSE',
                'stock_code' => '2408',
                'stock_name' => '南亞科',
                'trade_date' => '2026-05-25',
                'open_price' => 40,
                'high_price' => 41,
                'low_price' => 39.5,
                'close_price' => 40.5,
                'previous_close_price' => 40,
                'price_change_amount' => 0.5,
                'price_change_percent' => 1.25,
                'volume_lots' => 25,
                'volume_shares' => 25000,
                'trade_value' => 1000000,
                'transaction_count' => 120,
                'source' => 'TWSE STOCK_DAY_ALL',
                'source_payload' => json_encode(['fallback' => false], JSON_THROW_ON_ERROR),
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('tw_stock_company_profiles')->insert([
            [
                'exchange' => 'TWSE',
                'stock_code' => '2408',
                'stock_name' => '南亞科',
                'industry' => '半導體業',
                'industry_code' => '24',
                'valuation_group' => '半導體',
                'valuation_group_pe' => 20,
                'source_date' => '2026-05-25',
                'source_payload' => json_encode([
                    '公司代號' => '2408',
                    '已發行普通股數或TDR原股發行股數' => '1,000,000',
                ], JSON_THROW_ON_ERROR),
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Http::fake(fn ($request) => match (true) {
            str_starts_with($request->url(), 'https://openapi.twse.com.tw/v1/opendata/t187ap03_L') => Http::response([], 451),
            str_starts_with($request->url(), 'https://www.twse.com.tw/exchangeReport/MI_INDEX') => Http::response([], 451),
            str_starts_with($request->url(), 'https://www.tpex.org.tw/web/stock/aftertrading/daily_turnover/trn_result.php') => Http::response([
                'stat' => 'ok',
                'tables' => [
                    [
                        'title' => '上櫃股票個股週轉率排行',
                        'date' => '115/05/25',
                        'fields' => ['排行', '股票代號', '股票名稱', '總成交股數', '發行股數', '週轉率(%)'],
                        'data' => [],
                    ],
                ],
            ]),
            default => Http::response([], 404),
        });

        $this->artisan('tw-stock:fetch-daily-turnover-rates', [
            '--date' => '2026-05-25',
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $twse = DB::table('tw_stock_daily_turnover_rates')->where('stock_code', '2408')->first();
        $this->assertNotNull($twse);
        $this->assertSame('TWSE', $twse->exchange);
        $this->assertSame('2026-05-25', substr((string) $twse->trade_date, 0, 10));
        $this->assertSame(25000, (int) $twse->trading_shares);
        $this->assertSame(1000000, (int) $twse->issued_shares);
        $this->assertEqualsWithDelta(2.5, (float) $twse->turnover_rate_percent, 0.0001);
        $this->assertSame('tw_stock_daily_prices + t187ap03_L', $twse->source);
    }

    public function test_daily_price_index_uses_shared_tw_stock_pagination(): void
    {
        $now = now();
        $rows = [];
        foreach (range(1, 51) as $index) {
            $rows[] = [
                'exchange' => 'TWSE',
                'stock_code' => sprintf('9%03d', $index),
                'stock_name' => '測試股' . $index,
                'trade_date' => '2026-05-08',
                'open_price' => 100,
                'high_price' => 110,
                'low_price' => 90,
                'close_price' => 100 + $index,
                'previous_close_price' => 100,
                'price_change_amount' => $index,
                'price_change_percent' => $index / 10,
                'volume_lots' => 1000 + $index,
                'volume_shares' => (1000 + $index) * 1000,
                'trade_value' => 1000000,
                'transaction_count' => 100,
                'source' => 'test',
                'fetched_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('tw_stock_daily_prices')->insert($rows);

        $this->get(route('tw-stock.daily-prices.index', ['per_page' => 50]))
            ->assertOk()
            ->assertSee('tw-stock-pagination', false)
            ->assertSee('tw-stock-pagination__item active', false)
            ->assertSee('aria-label="第 2 頁"', false)
            ->assertSee('aria-label="下一頁"', false);
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

        Schema::create('tw_stock_company_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->string('industry_code', 8)->nullable();
            $table->string('valuation_group', 32);
            $table->decimal('valuation_group_pe', 8, 4);
            $table->date('source_date')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tw_stock_daily_turnover_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->date('trade_date');
            $table->unsignedInteger('rank')->nullable();
            $table->unsignedBigInteger('trading_shares')->default(0);
            $table->unsignedBigInteger('issued_shares')->nullable();
            $table->decimal('turnover_rate_percent', 10, 4)->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['exchange', 'stock_code', 'trade_date']);
        });
    }
}
