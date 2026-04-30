<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockUpcomingDividendsTest extends TestCase
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

        Carbon::setTestNow('2026-04-30 12:00:00');
        CarbonImmutable::setTestNow('2026-04-30 12:00:00');

        Schema::dropAllTables();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tw_stock_upcoming_dividends');

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_fetch_command_stores_upcoming_stock_dividends_and_last_fill_days(): void
    {
        DB::table('tw_stock_upcoming_dividends')->insert([
            [
                'exchange' => 'TWSE',
                'stock_code' => '9999',
                'stock_name' => '區間舊資料',
                'security_type' => 'stock',
                'ex_dividend_date' => '2026-05-10',
                'ex_dividend_type' => '息',
                'cash_dividend' => 1,
                'days_until_ex_dividend' => 10,
                'last_fill_status' => 'no_history',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'exchange' => 'TWSE',
                'stock_code' => '8888',
                'stock_name' => '過期資料',
                'security_type' => 'stock',
                'ex_dividend_date' => '2026-04-29',
                'ex_dividend_type' => '息',
                'cash_dividend' => 1,
                'days_until_ex_dividend' => 0,
                'last_fill_status' => 'no_history',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Http::fake(fn ($request) => $this->fakeDividendResponse($request->url()));

        $this->artisan('tw-stock:fetch-upcoming-dividends', [
            '--as-of' => '2026-04-30',
            '--days' => 30,
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('tw_stock_upcoming_dividends')->count());
        $this->assertDatabaseMissing('tw_stock_upcoming_dividends', ['stock_code' => '00939']);
        $this->assertDatabaseMissing('tw_stock_upcoming_dividends', ['stock_code' => '9999']);
        $this->assertDatabaseMissing('tw_stock_upcoming_dividends', ['stock_code' => '8888']);

        $twse = DB::table('tw_stock_upcoming_dividends')->where('stock_code', '3413')->first();
        $this->assertSame('京鼎', $twse->stock_name);
        $this->assertSame('2026-05-27', substr((string) $twse->ex_dividend_date, 0, 10));
        $this->assertEqualsWithDelta(10.983792, (float) $twse->cash_dividend, 0.000001);
        $this->assertEqualsWithDelta(400.0, (float) $twse->latest_close_price, 0.0001);
        $this->assertEqualsWithDelta(2.7459, (float) $twse->dividend_yield_percent, 0.0001);
        $this->assertSame(27, (int) $twse->days_until_ex_dividend);
        $this->assertSame('2025-07-01', substr((string) $twse->last_ex_dividend_date, 0, 10));
        $this->assertSame('2025-07-02', substr((string) $twse->last_fill_date, 0, 10));
        $this->assertSame(2, (int) $twse->last_fill_days);
        $this->assertSame('filled', $twse->last_fill_status);

        $tpex = DB::table('tw_stock_upcoming_dividends')->where('stock_code', '8074')->first();
        $this->assertSame('鉅橡', $tpex->stock_name);
        $this->assertSame('2026-05-06', substr((string) $tpex->ex_dividend_date, 0, 10));
        $this->assertSame(6, (int) $tpex->days_until_ex_dividend);
        $this->assertNull($tpex->last_fill_days);
        $this->assertSame('unfilled', $tpex->last_fill_status);
    }

    public function test_dashboard_only_shows_not_yet_expired_next_thirty_days(): void
    {
        DB::table('tw_stock_upcoming_dividends')->insert([
            $this->row(['stock_code' => '3413', 'stock_name' => '京鼎', 'ex_dividend_date' => '2026-05-27', 'days_until_ex_dividend' => 27]),
            $this->row(['stock_code' => '8074', 'stock_name' => '鉅橡', 'exchange' => 'TPEx', 'ex_dividend_date' => '2026-05-06', 'days_until_ex_dividend' => 6]),
            $this->row(['stock_code' => '1111', 'stock_name' => '昨天除息', 'ex_dividend_date' => '2026-04-29', 'days_until_ex_dividend' => 0]),
            $this->row(['stock_code' => '2222', 'stock_name' => '太遠除息', 'ex_dividend_date' => '2026-06-01', 'days_until_ex_dividend' => 32]),
        ]);

        $this->get(route('tw-stock.upcoming-dividends.index'))
            ->assertOk()
            ->assertSee('近 30 天除息股票')
            ->assertSee('3413')
            ->assertSee('京鼎')
            ->assertSee('8074')
            ->assertSee('鉅橡')
            ->assertSee('上次填息天數')
            ->assertSee('2026-04-30 ~ 2026-05-30')
            ->assertDontSee('昨天除息')
            ->assertDontSee('太遠除息');
    }

    private function fakeDividendResponse(string $url): mixed
    {
        if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/exchangeReport/TWT48U_ALL')) {
            return Http::response([
                [
                    'Date' => '1150527',
                    'Code' => '3413',
                    'Name' => '京鼎',
                    'Exdividend' => '息',
                    'CashDividend' => '10.983792',
                ],
                [
                    'Date' => '1150508',
                    'Code' => '00939',
                    'Name' => '統一台灣高息動能',
                    'Exdividend' => '息',
                    'CashDividend' => '0.070000',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.tpex.org.tw/openapi/v1/tpex_exright_prepost')) {
            return Http::response([
                [
                    'ExRrightsExDividendDate' => '1150506',
                    'SecuritiesCompanyCode' => '8074',
                    'CompanyName' => '鉅橡',
                    'ExRrightsExDividend' => '除權息',
                    'CashDividend' => '1.10000000',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL')) {
            return Http::response([
                [
                    'Date' => '1150429',
                    'Code' => '3413',
                    'Name' => '京鼎',
                    'ClosingPrice' => '400.00',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes')) {
            return Http::response([
                [
                    'Date' => '1150430',
                    'SecuritiesCompanyCode' => '8074',
                    'CompanyName' => '鉅橡',
                    'Close' => '23.20',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://api.finmindtrade.com/api/v4/data')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            return Http::response([
                'status' => 200,
                'data' => $this->fakeFinMindData((string) ($query['dataset'] ?? ''), (string) ($query['data_id'] ?? '')),
            ]);
        }

        return Http::response([], 404);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fakeFinMindData(string $dataset, string $stockCode): array
    {
        if ($dataset === 'TaiwanStockDividendResult' && $stockCode === '3413') {
            return [
                [
                    'date' => '2025-07-01',
                    'stock_or_cache_dividend' => '息',
                    'stock_and_cache_dividend' => 8,
                    'before_price' => 300,
                ],
            ];
        }

        if ($dataset === 'TaiwanStockPrice' && $stockCode === '3413') {
            return [
                ['date' => '2025-07-01', 'close' => 295],
                ['date' => '2025-07-02', 'close' => 301],
            ];
        }

        if ($dataset === 'TaiwanStockDividendResult' && $stockCode === '8074') {
            return [
                [
                    'date' => '2025-08-01',
                    'stock_or_cache_dividend' => '息',
                    'stock_and_cache_dividend' => 0.8,
                    'before_price' => 30,
                ],
            ];
        }

        if ($dataset === 'TaiwanStockPrice' && $stockCode === '8074') {
            return [
                ['date' => '2025-08-01', 'close' => 22],
                ['date' => '2025-08-04', 'close' => 23],
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'exchange' => 'TWSE',
            'stock_code' => '3413',
            'stock_name' => '京鼎',
            'security_type' => 'stock',
            'ex_dividend_date' => '2026-05-27',
            'ex_dividend_type' => '息',
            'cash_dividend' => 10.983792,
            'latest_close_price' => 400,
            'latest_price_date' => '2026-04-29',
            'dividend_yield_percent' => 2.7459,
            'days_until_ex_dividend' => 27,
            'last_ex_dividend_date' => '2025-07-01',
            'last_ex_dividend_cash_dividend' => 8,
            'last_ex_dividend_before_price' => 300,
            'last_fill_date' => '2025-07-02',
            'last_fill_days' => 2,
            'last_fill_status' => 'filled',
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function createTable(): void
    {
        Schema::create('tw_stock_upcoming_dividends', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('security_type', 24)->default('stock');
            $table->date('ex_dividend_date');
            $table->string('ex_dividend_type', 16);
            $table->decimal('cash_dividend', 12, 6);
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->date('latest_price_date')->nullable();
            $table->decimal('dividend_yield_percent', 8, 4)->nullable();
            $table->unsignedInteger('days_until_ex_dividend');
            $table->date('last_ex_dividend_date')->nullable();
            $table->decimal('last_ex_dividend_cash_dividend', 12, 6)->nullable();
            $table->decimal('last_ex_dividend_before_price', 12, 4)->nullable();
            $table->date('last_fill_date')->nullable();
            $table->unsignedInteger('last_fill_days')->nullable();
            $table->string('last_fill_status', 24)->default('no_history');
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['exchange', 'stock_code', 'ex_dividend_date'], 'uq_tw_stock_upcoming_dividends_event');
            $table->index('ex_dividend_date', 'idx_tw_stock_upcoming_dividends_ex_date');
            $table->index('stock_code', 'idx_tw_stock_upcoming_dividends_code');
        });
    }
}
