<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockMonthlyRevenuesTest extends TestCase
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

        $this->createTables();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            parent::tearDown();

            return;
        }

        Carbon::setTestNow();
        Schema::connection('sqlite')->dropIfExists('tw_stock_monthly_revenues');
        Schema::connection('sqlite')->dropIfExists('tw_stock_daily_prices');

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_fetch_command_stores_mops_rows_and_price_changes(): void
    {
        Carbon::setTestNow('2026-07-03 12:00:00');
        $this->seedDailyPrices('TWSE', '1234', '測試上市', [100, 102, 101, 104, 106, 110], 3.7736);
        $this->seedDailyPrices('TPEx', '3228', '金麗科', [50, 51, 53, 54, 57, 60], 5.2632);

        Http::fake([
            'https://mopsov.twse.com.tw/server-java/FileDownLoad' => function ($request) {
                $data = $request->data();
                $filePath = (string) ($data['filePath'] ?? '');

                return Http::response($this->mopsCsv($filePath === '/t21/sii/'
                    ? [[
                        'date' => '115/07/01',
                        'period' => '115/6',
                        'code' => '1234',
                        'name' => '測試上市',
                        'industry' => '半導體業',
                        'revenue' => '100000',
                        'prev' => '70000',
                        'last' => '50000',
                        'mom' => '42.8571',
                        'yoy' => '100.0000',
                        'cum' => '600000',
                        'last_cum' => '300000',
                        'cum_yoy' => '100.0000',
                    ]]
                    : [[
                        'date' => '115/07/01',
                        'period' => '115/6',
                        'code' => '3228',
                        'name' => '金麗科',
                        'industry' => '半導體業',
                        'revenue' => '34669',
                        'prev' => '28741',
                        'last' => '22110',
                        'mom' => '20.6256',
                        'yoy' => '56.8024',
                        'cum' => '171634',
                        'last_cum' => '120000',
                        'cum_yoy' => '43.0283',
                    ]]));
            },
        ]);

        $this->artisan('tw-stock:fetch-monthly-revenues', [
            '--year' => 2026,
            '--month' => 6,
            '--skip-price-refresh' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('tw_stock_monthly_revenues', 2);
        $this->assertDatabaseHas('tw_stock_monthly_revenues', [
            'exchange' => 'TWSE',
            'stock_code' => '1234',
            'stock_name' => '測試上市',
            'revenue_year' => 2026,
            'revenue_month' => 6,
            'monthly_revenue_thousands' => 100000,
            'announced_date' => '2026-07-01',
        ]);

        $twse = DB::table('tw_stock_monthly_revenues')->where('stock_code', '1234')->first();
        $this->assertNotNull($twse);
        $this->assertEquals(142.8571, (float) $twse->mom_yoy_sum_percent);
        $this->assertEquals(3.7736, (float) $twse->one_day_change_percent);
        $this->assertEquals(10.0, (float) $twse->five_day_change_percent);
        $payload = json_decode((string) $twse->source_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('/t21/sii/', $payload['file_path']);
        $this->assertSame('t21sc03_115_6.csv', $payload['file_name']);

        $tpex = DB::table('tw_stock_monthly_revenues')->where('stock_code', '3228')->first();
        $this->assertNotNull($tpex);
        $this->assertSame('TPEx', $tpex->exchange);
        $this->assertEquals(77.428, round((float) $tpex->mom_yoy_sum_percent, 3));
        $this->assertEquals(20.0, (float) $tpex->five_day_change_percent);
    }

    public function test_page_defaults_to_thresholds_sorts_by_sum_and_limits_top_100(): void
    {
        $now = now();
        $rows = [];
        for ($index = 1; $index <= 105; $index++) {
            $rows[] = $this->monthlyRevenueRow([
                'stock_code' => (string) (9000 + $index),
                'stock_name' => '排行' . $index,
                'month_over_month_percent' => 120 - ($index / 10),
                'year_over_year_percent' => 160 - ($index / 10),
                'mom_yoy_sum_percent' => 280 - ($index / 5),
                'monthly_revenue_thousands' => 200000 - $index,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $rows[] = $this->monthlyRevenueRow([
            'stock_code' => '8123',
            'stock_name' => '低於門檻',
            'month_over_month_percent' => 20,
            'year_over_year_percent' => 120,
            'mom_yoy_sum_percent' => 140,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('tw_stock_monthly_revenues')->insert($rows);

        $this->get(route('tw-stock.monthly-revenues.index'))
            ->assertOk()
            ->assertSee('每月營收排行')
            ->assertSee('月增 &gt; 30%', false)
            ->assertSee('最近5日漲跌')
            ->assertSee('exchange-badge--twse', false)
            ->assertSee('即時')
            ->assertSee('共 105 筆符合條件')
            ->assertSee('顯示前 100 筆')
            ->assertSeeInOrder(['9001', '9002', '9003'])
            ->assertDontSee('上市/櫃')
            ->assertDontSee('低於門檻')
            ->assertDontSee('9105');

        $this->get(route('tw-stock.monthly-revenues.index', [
            'period' => '2026-06',
            'mom_gt' => 0,
            'yoy_gt' => 0,
            'sum_gt' => 0,
            'sort' => 'revenue',
            'direction' => 'asc',
        ]))
            ->assertOk()
            ->assertSee('9105')
            ->assertDontSee('9001</span>', false);
    }

    public function test_command_skips_outside_monthly_window(): void
    {
        Carbon::setTestNow('2026-07-20 12:00:00');
        Http::fake(['*' => Http::response('', 500)]);

        $this->artisan('tw-stock:fetch-monthly-revenues', [
            '--skip-outside-window' => true,
        ])
            ->expectsOutput('略過：2026-07-20 不在月營收 1-10 號或假日遞延緩衝視窗。')
            ->assertExitCode(0);

        $this->assertDatabaseCount('tw_stock_monthly_revenues', 0);
    }

    /**
     * @param list<float> $closes oldest to newest
     */
    private function seedDailyPrices(string $exchange, string $stockCode, string $stockName, array $closes, float $latestChangePercent): void
    {
        $dates = ['2026-06-24', '2026-06-25', '2026-06-26', '2026-06-29', '2026-06-30', '2026-07-01'];
        $rows = [];
        foreach ($closes as $index => $close) {
            $rows[] = [
                'exchange' => $exchange,
                'stock_code' => $stockCode,
                'stock_name' => $stockName,
                'trade_date' => $dates[$index],
                'close_price' => $close,
                'price_change_percent' => $index === count($closes) - 1 ? $latestChangePercent : null,
                'volume_lots' => 1000,
                'volume_shares' => 1000000,
                'source' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('tw_stock_daily_prices')->insert($rows);
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function mopsCsv(array $rows): string
    {
        $lines = [
            "\xEF\xBB\xBF出表日期,資料年月,公司代號,公司名稱,產業別,營業收入-當月營收,營業收入-上月營收,營業收入-去年當月營收,營業收入-上月比較增減(%),營業收入-去年同月增減(%),累計營業收入-當月累計營收,累計營業收入-去年累計營收,累計營業收入-前期比較增減(%),備註",
        ];

        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                fn (string $value): string => '"' . str_replace('"', '""', $value) . '"',
                [
                    $row['date'],
                    $row['period'],
                    $row['code'],
                    $row['name'],
                    $row['industry'],
                    $row['revenue'],
                    $row['prev'],
                    $row['last'],
                    $row['mom'],
                    $row['yoy'],
                    $row['cum'],
                    $row['last_cum'],
                    $row['cum_yoy'],
                    '',
                ],
            ));
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function monthlyRevenueRow(array $overrides): array
    {
        return array_merge([
            'revenue_year' => 2026,
            'revenue_month' => 6,
            'announced_date' => '2026-07-01',
            'exchange' => 'TWSE',
            'stock_code' => '9001',
            'stock_name' => '排行',
            'industry' => '半導體業',
            'monthly_revenue_thousands' => 100000,
            'previous_month_revenue_thousands' => 80000,
            'last_year_month_revenue_thousands' => 50000,
            'month_over_month_percent' => 40,
            'year_over_year_percent' => 80,
            'mom_yoy_sum_percent' => 120,
            'cumulative_revenue_thousands' => 400000,
            'last_year_cumulative_revenue_thousands' => 250000,
            'cumulative_yoy_percent' => 60,
            'latest_price_date' => '2026-07-01',
            'latest_close_price' => 100,
            'one_day_change_percent' => 2.5,
            'five_day_change_percent' => 8.5,
            'source' => 'test',
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function createTables(): void
    {
        Schema::connection('sqlite')->create('tw_stock_daily_prices', function (Blueprint $table): void {
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

        Schema::connection('sqlite')->create('tw_stock_monthly_revenues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('revenue_year');
            $table->unsignedTinyInteger('revenue_month');
            $table->date('announced_date')->nullable();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->bigInteger('monthly_revenue_thousands')->nullable();
            $table->bigInteger('previous_month_revenue_thousands')->nullable();
            $table->bigInteger('last_year_month_revenue_thousands')->nullable();
            $table->decimal('month_over_month_percent', 12, 4)->nullable();
            $table->decimal('year_over_year_percent', 12, 4)->nullable();
            $table->decimal('mom_yoy_sum_percent', 12, 4)->nullable();
            $table->bigInteger('cumulative_revenue_thousands')->nullable();
            $table->bigInteger('last_year_cumulative_revenue_thousands')->nullable();
            $table->decimal('cumulative_yoy_percent', 12, 4)->nullable();
            $table->date('latest_price_date')->nullable();
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->decimal('one_day_change_percent', 10, 4)->nullable();
            $table->decimal('five_day_change_percent', 10, 4)->nullable();
            $table->string('source')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
            $table->unique(['exchange', 'stock_code', 'revenue_year', 'revenue_month']);
        });
    }
}
