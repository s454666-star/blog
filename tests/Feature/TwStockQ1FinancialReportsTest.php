<?php

namespace Tests\Feature;

use App\Services\TwStockQ1FinancialReportFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockQ1FinancialReportsTest extends TestCase
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

        Carbon::setTestNow('2026-05-08 17:00:00');
        CarbonImmutable::setTestNow('2026-05-08 17:00:00');

        Schema::dropAllTables();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tw_stock_annual_financial_comparisons');
        Schema::dropIfExists('tw_stock_q1_financial_reports');

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_fetch_command_stores_only_announced_high_volume_q1_reports_and_ranks_by_weighted_financial_score(): void
    {
        Http::fake(fn ($request) => $this->fakeResponse($request->url()));

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--quarter' => 1,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertSame(3, DB::table('tw_stock_q1_financial_reports')->count());
        $this->assertDatabaseMissing('tw_stock_q1_financial_reports', ['stock_code' => '2330']);
        $this->assertDatabaseMissing('tw_stock_q1_financial_reports', ['stock_code' => '1111']);

        $top = DB::table('tw_stock_q1_financial_reports')->where('rank', 1)->first();
        $this->assertSame('5289', $top->stock_code);
        $this->assertEqualsWithDelta(100.0, (float) $top->q1_revenue_score, 0.0001);
        $this->assertEqualsWithDelta(131.8261, (float) $top->q1_revenue_billion, 0.0001);
        $this->assertEqualsWithDelta(403.39, (float) $top->q1_revenue_yoy_percent, 0.01);
        $this->assertEqualsWithDelta(57.49, (float) $top->q1_eps, 0.0001);
        $this->assertEqualsWithDelta(59.10, (float) $top->q1_gross_margin_percent, 0.01);
        $this->assertEqualsWithDelta(51.96, (float) $top->q1_operating_margin_percent, 0.01);
        $this->assertEqualsWithDelta(41.55, (float) $top->q1_net_margin_percent, 0.01);
        $recentMonthlyRevenues = json_decode((string) $top->recent_monthly_revenues, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(6, $recentMonthlyRevenues);
        $this->assertSame('202604', $recentMonthlyRevenues[0]['year_month']);
        $this->assertEqualsWithDelta(66.71, (float) $recentMonthlyRevenues[0]['revenue_billion'], 0.0001);
        $this->assertEqualsWithDelta(583.1, (float) $recentMonthlyRevenues[0]['revenue_yoy_percent'], 0.0001);
        $this->assertEqualsWithDelta(17.6, (float) $recentMonthlyRevenues[0]['revenue_mom_percent'], 0.0001);

        $second = DB::table('tw_stock_q1_financial_reports')->where('rank', 2)->first();
        $this->assertSame('8261', $second->stock_code);
        $this->assertEqualsWithDelta(50.0, (float) $second->q1_revenue_score, 0.0001);
        $this->assertEqualsWithDelta(7.82, (float) $second->q1_revenue_billion, 0.0001);
        $this->assertEqualsWithDelta(9.17, (float) $second->q1_revenue_yoy_percent, 0.0001);
        $this->assertEqualsWithDelta(-1.56, (float) $second->price_change_1d_percent, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $second->price_change_5d_percent, 0.0001);
        $this->assertEqualsWithDelta(26.0, (float) $second->price_change_20d_percent, 0.0001);
        $this->assertSame(4695, (int) $second->volume_lots);
    }

    public function test_fetch_command_preserves_longer_monthly_revenue_history(): void
    {
        Http::fake(fn ($request) => $this->fakeResponse($request->url()));

        $longMonthlyRows = [];
        $month = CarbonImmutable::create(2026, 4, 1);
        for ($index = 0; $index < 10; $index++) {
            $longMonthlyRows[] = [
                'year_month' => $month->subMonths($index)->format('Ym'),
                'revenue_billion' => 10 + $index,
            ];
        }

        DB::table('tw_stock_q1_financial_reports')->insert($this->row([
            'exchange' => 'TPEx',
            'stock_code' => '5289',
            'stock_name' => '宜鼎',
            'q1_revenue_score' => 10,
            'recent_monthly_revenues' => json_encode($longMonthlyRows, JSON_THROW_ON_ERROR),
            'rank' => 3,
        ]));

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--quarter' => 1,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $row = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '5289')->where('exchange', 'TPEx')->first();
        $monthlyRows = json_decode((string) $row->recent_monthly_revenues, true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(10, $monthlyRows);
        $this->assertSame('202604', $monthlyRows[0]['year_month']);
        $this->assertEqualsWithDelta(100.0, (float) $row->q1_revenue_score, 0.0001);
    }

    public function test_dashboard_supports_search_and_per_page_options(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row(['stock_code' => '9951', 'stock_name' => '皇田', 'q1_revenue_score' => 60, 'latest_close_price' => 110, 'rank' => 1]),
            $this->row(['stock_code' => '8261', 'stock_name' => '富鼎', 'q1_revenue_score' => 40, 'latest_close_price' => 80, 'rank' => 2]),
        ]);

        $this->get(route('tw-stock.q1-financial-reports.index', [
            'q' => '9951',
            'per_page' => 50,
            'price_min' => 100,
            'price_max' => 120,
        ]))
            ->assertOk()
            ->assertSee('2026 Q1 財報評分排名')
            ->assertSee('Q1整體財報評分')
            ->assertSee('近1月營收')
            ->assertSee('前段班')
            ->assertSee('name="price_min"', false)
            ->assertSee('value="100"', false)
            ->assertSee('name="price_max"', false)
            ->assertSee('value="120"', false)
            ->assertSee('name="sort"', false)
            ->assertSee('sort=eps_yoy', false)
            ->assertSee('data-tooltip="點一下排序：高到低"', false)
            ->assertSee('data-tooltip="點一下排序：低到高"', false)
            ->assertSee('data-auto-submit-form', false)
            ->assertDontSee('套用')
            ->assertDontSee('重設')
            ->assertSee('data-copy-value="9951"', false)
            ->assertSee('點一下複製代碼')
            ->assertSee('點一下複製名稱')
            ->assertSee('9951')
            ->assertSee('皇田')
            ->assertSee('value="50"', false)
            ->assertSee('value="250"', false)
            ->assertSee('value="500"', false)
            ->assertDontSee('富鼎');
    }

    public function test_dashboard_sorts_all_matching_rows_before_paginating(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row([
                'stock_code' => '9951',
                'stock_name' => '皇田',
                'q1_revenue_score' => 60,
                'latest_close_price' => 110,
                'rank' => 1,
                'recent_monthly_revenues' => json_encode([['revenue_yoy_percent' => 12, 'revenue_mom_percent' => 1]], JSON_THROW_ON_ERROR),
            ]),
            $this->row([
                'stock_code' => '8261',
                'stock_name' => '富鼎',
                'q1_revenue_score' => 40,
                'latest_close_price' => 80,
                'rank' => 2,
                'recent_monthly_revenues' => json_encode([['revenue_yoy_percent' => 25, 'revenue_mom_percent' => 1]], JSON_THROW_ON_ERROR),
            ]),
            $this->row([
                'stock_code' => '5289',
                'stock_name' => '宜鼎',
                'q1_revenue_score' => 100,
                'latest_close_price' => 1600,
                'rank' => 3,
                'recent_monthly_revenues' => json_encode([['revenue_yoy_percent' => -5, 'revenue_mom_percent' => 1]], JSON_THROW_ON_ERROR),
            ]),
            $this->row([
                'stock_code' => '1111',
                'stock_name' => '低量備用',
                'q1_revenue_score' => 1000,
                'latest_close_price' => 10,
                'volume_lots' => 99,
                'rank' => 4,
            ]),
        ]);

        $priceResponse = $this->get(route('tw-stock.q1-financial-reports.index', [
            'sort' => 'price',
            'direction' => 'asc',
            'per_page' => 50,
        ]));

        $priceResponse->assertOk();
        $this->assertTableOrder($priceResponse->getContent(), ['8261', '9951', '5289']);
        $priceResponse->assertDontSee('低量備用');
        $priceResponse->assertSee('sort=price', false)
            ->assertSee('direction=desc', false);

        $monthlyResponse = $this->get(route('tw-stock.q1-financial-reports.index', [
            'sort' => 'month_1',
            'direction' => 'desc',
            'per_page' => 50,
        ]));

        $monthlyResponse->assertOk();
        $this->assertTableOrder($monthlyResponse->getContent(), ['8261', '9951', '5289']);
    }

    public function test_annual_comparison_supports_sort_filters_and_per_page_options(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert(array_merge(
            $this->annualComparisonRows(
                '2408',
                '南亞科',
                [2020 => 100, 2021 => 125, 2022 => 150, 2023 => 180, 2024 => 225, 2025 => 280, 2026 => 90],
                [2020 => 1.0, 2021 => 1.3, 2022 => 1.6, 2023 => 2.0, 2024 => 2.4, 2025 => 3.0, 2026 => 0.8],
                22.5,
                true,
            ),
            $this->annualComparisonRows(
                '8261',
                '富鼎',
                [2020 => 100, 2021 => 96, 2022 => 92, 2023 => 88, 2024 => 84, 2025 => 80, 2026 => 18],
                [2020 => 3.0, 2021 => 2.5, 2022 => 2.0, 2023 => 1.5, 2024 => 1.0, 2025 => 0.7, 2026 => 0.2],
                9.5,
                false,
            ),
        ));

        $this->artisan('tw-stock:refresh-annual-financial-comparisons', [
            '--context-year' => 2026,
            '--start-year' => 2020,
            '--end-year' => 2025,
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('tw_stock_annual_financial_comparisons')->count());

        $response = $this->get(route('tw-stock.annual-comparison.index'));

        $response->assertOk()
            ->assertSee('台股年度營收 EPS 比較')
            ->assertSee('營收加總排序')
            ->assertSee('EPS 加總排序')
            ->assertSee('營收 5 年 YoY 合計 &gt; 30%', false)
            ->assertSee('EPS 5 年 YoY 合計 &gt; 20%', false)
            ->assertSee('每年 EPS YoY 均為正')
            ->assertSee('淨利率近 8 季或近 2 年平均 &gt; 15%', false)
            ->assertSee('name="sort" value="eps"', false)
            ->assertSee('value="50"', false)
            ->assertSee('value="100" selected', false)
            ->assertSee('value="200"', false)
            ->assertSee('value="500"', false)
            ->assertSee('data-copy-value="2408"', false)
            ->assertSee('2020 → 2021')
            ->assertSee('點一下複製');

        $html = $response->getContent();
        $highGrowthPosition = strpos($html, 'data-copy-value="2408"');
        $lowGrowthPosition = strpos($html, 'data-copy-value="8261"');

        $this->assertNotFalse($highGrowthPosition);
        $this->assertNotFalse($lowGrowthPosition);
        $this->assertLessThan($lowGrowthPosition, $highGrowthPosition);

        $filtered = $this->get(route('tw-stock.annual-comparison.index', [
            'sort' => 'eps',
            'per_page' => 50,
            'eps_growth' => 1,
            'eps_yoy_positive' => 1,
            'net_margin' => 1,
        ]));

        $filtered->assertOk()
            ->assertSee('name="sort" value="eps"', false)
            ->assertSee('value="50" selected', false)
            ->assertSee('name="eps_growth" value="1" checked', false)
            ->assertSee('name="eps_yoy_positive" value="1" checked', false)
            ->assertSee('name="net_margin" value="1" checked', false)
            ->assertSee('data-copy-value="2408"', false)
            ->assertDontSee('data-copy-value="8261"', false);
    }

    public function test_monthly_revenue_fetcher_fills_short_nstock_history_from_mops_csv(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_starts_with($url, 'https://www.nstock.tw/api/v2/monthly-revenue/data')) {
                return Http::response(['data' => [
                    [
                        '股票代號' => '5289',
                        '月營收' => $this->monthlyRevenueRowsWithSixtyMonths(),
                    ],
                ]]);
            }

            if (str_starts_with($url, 'https://mopsov.twse.com.tw/server-java/FileDownLoad')) {
                return Http::response($this->mopsMonthlyRevenueCsv(), 200);
            }

            return Http::response([], 404);
        });

        $rows = app(TwStockQ1FinancialReportFetcher::class)->fetchRecentMonthlyRevenueRows('5289', 61);

        $this->assertCount(61, $rows);
        $this->assertSame('202604', $rows[0]['year_month']);
        $this->assertSame('202104', $rows[60]['year_month']);
        $this->assertEqualsWithDelta(12.34, (float) $rows[60]['revenue_billion'], 0.0001);
        $this->assertEqualsWithDelta(25.5, (float) $rows[60]['revenue_yoy_percent'], 0.0001);
    }

    public function test_fetch_command_uses_mops_announcements_when_eps_api_has_not_synced(): void
    {
        Http::fake(fn ($request) => $this->fakeMopsFallbackResponse($request));

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--quarter' => 1,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertSame(2, DB::table('tw_stock_q1_financial_reports')->count());

        $kinik = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '1785')->first();
        $this->assertNotNull($kinik);
        $this->assertSame('光洋科', $kinik->stock_name);
        $this->assertEqualsWithDelta(2.54, (float) $kinik->q1_eps, 0.0001);
        $this->assertEqualsWithDelta(145.5789, (float) $kinik->q1_revenue_billion, 0.0001);
        $this->assertEqualsWithDelta(18.2288, (float) $kinik->q1_gross_margin_percent, 0.0001);
        $this->assertStringContainsString('MOPS ajax_t05st01', (string) $kinik->source_payload);

        $sis = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '2363')->first();
        $this->assertNotNull($sis);
        $this->assertSame('矽統', $sis->stock_name);
        $this->assertEqualsWithDelta(0.17, (float) $sis->q1_eps, 0.0001);
        $this->assertEqualsWithDelta(10.5002, (float) $sis->q1_revenue_billion, 0.0001);
        $this->assertStringContainsString('MOPS ajax_t05st01', (string) $sis->source_payload);
    }

    public function test_fetch_command_can_backfill_years_and_all_quarters_into_existing_table_rows(): void
    {
        Http::fake(fn ($request) => $this->fakeBackfillResponse($request));

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--backfill-years' => 2,
            '--all-quarters' => true,
            '--monthly-revenue-months' => 5,
            '--skip-market-data-refresh' => true,
            '--skip-announcement-fallbacks' => true,
            '--keep-missing' => true,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertSame(5, DB::table('tw_stock_q1_financial_reports')->count());
        $this->assertDatabaseMissing('tw_stock_q1_financial_reports', [
            'stock_code' => '8261',
            'fiscal_year' => 2026,
            'quarter' => 2,
        ]);

        $q4 = DB::table('tw_stock_q1_financial_reports')
            ->where('stock_code', '8261')
            ->where('fiscal_year', 2025)
            ->where('quarter', 4)
            ->first();

        $this->assertNotNull($q4);
        $this->assertSame('202504', $q4->financial_period);
        $this->assertEqualsWithDelta(4.44, (float) $q4->q1_eps, 0.0001);
        $this->assertEqualsWithDelta(44.40, (float) $q4->q1_gross_margin_percent, 0.0001);
        $this->assertEqualsWithDelta(24.40, (float) $q4->q1_operating_margin_percent, 0.0001);
        $this->assertEqualsWithDelta(14.40, (float) $q4->q1_net_margin_percent, 0.0001);

        $monthlyRevenues = json_decode((string) $q4->recent_monthly_revenues, true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(5, $monthlyRevenues);
        $this->assertSame('202604', $monthlyRevenues[0]['year_month']);
        $this->assertSame('202512', $monthlyRevenues[4]['year_month']);
    }

    public function test_skip_non_trading_day_accepts_previous_friday_quote_on_weekend(): void
    {
        Carbon::setTestNow('2026-05-09 10:00:00');
        CarbonImmutable::setTestNow('2026-05-09 10:00:00');
        Http::fake(fn ($request) => $this->fakeResponse($request->url()));

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--quarter' => 1,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
            '--skip-non-trading-day' => true,
        ])->assertExitCode(0);

        $this->assertSame(3, DB::table('tw_stock_q1_financial_reports')->count());
    }

    public function test_market_data_only_refreshes_latest_price_changes_and_prunes_low_volume_rows(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row([
                'stock_code' => '8261',
                'stock_name' => '富鼎',
                'latest_close_price' => 120,
                'latest_price_date' => '2026-05-07',
                'volume_lots' => 1500,
                'price_change_1d_percent' => 0,
                'price_change_5d_percent' => 0,
                'price_change_20d_percent' => 0,
                'rank' => 2,
            ]),
            $this->row([
                'stock_code' => '3054',
                'stock_name' => '立萬利',
                'latest_close_price' => 75.5,
                'latest_price_date' => '2026-05-07',
                'volume_lots' => 1457,
                'rank' => 1,
            ]),
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_starts_with($url, 'https://www.twse.com.tw/rwd/zh/afterTrading/STOCK_DAY')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
                $stockCode = (string) ($query['stockNo'] ?? '');

                return Http::response([
                    'stat' => 'OK',
                    'data' => $stockCode === '3054'
                        ? $this->twseMonthlyRowsForMarketData(75.5, 2.30, 70, 62, 900)
                        : $this->twseMonthlyRows('8261'),
                ]);
            }

            return Http::response([], 404);
        });

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--quarter' => 1,
            '--market-data-only' => true,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('tw_stock_q1_financial_reports', ['stock_code' => '3054']);

        $row = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '8261')->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-05-08', substr((string) $row->latest_price_date, 0, 10));
        $this->assertEqualsWithDelta(126, (float) $row->latest_close_price, 0.0001);
        $this->assertEqualsWithDelta(-1.56, (float) $row->price_change_1d_percent, 0.0001);
        $this->assertEqualsWithDelta(5.0, (float) $row->price_change_5d_percent, 0.0001);
        $this->assertEqualsWithDelta(26.0, (float) $row->price_change_20d_percent, 0.0001);
        $this->assertSame(4695, (int) $row->volume_lots);
        $this->assertSame(1, (int) $row->rank);
    }

    public function test_market_data_only_uses_latest_official_quote_when_daily_history_is_stale(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row([
                'stock_code' => '8261',
                'stock_name' => '富鼎',
                'latest_close_price' => 120,
                'latest_price_date' => '2026-05-07',
                'volume_lots' => 1500,
                'price_change_1d_percent' => 0,
                'price_change_5d_percent' => 0,
                'price_change_20d_percent' => 0,
                'rank' => 2,
            ]),
            $this->row([
                'stock_code' => '3054',
                'stock_name' => '立萬利',
                'latest_close_price' => 75.5,
                'latest_price_date' => '2026-05-07',
                'volume_lots' => 1457,
                'rank' => 1,
            ]),
        ]);

        $stale8261Rows = array_values(array_filter(
            $this->twseMonthlyRowsForMarketData(126, -1.56, 120, 100, 1500),
            fn (array $row): bool => $row[0] !== $this->rocDate('20260508'),
        ));
        $stale3054Rows = array_values(array_filter(
            $this->twseMonthlyRowsForMarketData(75.5, 2.30, 70, 62, 1457),
            fn (array $row): bool => $row[0] !== $this->rocDate('20260508'),
        ));

        Http::fake(function ($request) use ($stale8261Rows, $stale3054Rows) {
            $url = $request->url();

            if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL')) {
                return Http::response([
                    [
                        'Date' => '1150508',
                        'Code' => '8261',
                        'Name' => '富鼎',
                        'TradeVolume' => '1600000',
                        'ClosingPrice' => '130.00',
                    ],
                    [
                        'Date' => '1150508',
                        'Code' => '3054',
                        'Name' => '立萬利',
                        'TradeVolume' => '672000',
                        'ClosingPrice' => '70.40',
                    ],
                ]);
            }

            if (str_starts_with($url, 'https://www.twse.com.tw/rwd/zh/afterTrading/STOCK_DAY')) {
                parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

                return Http::response([
                    'stat' => 'OK',
                    'data' => (string) ($query['stockNo'] ?? '') === '3054' ? $stale3054Rows : $stale8261Rows,
                ]);
            }

            return Http::response([], 404);
        });

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--quarter' => 1,
            '--market-data-only' => true,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $this->assertDatabaseMissing('tw_stock_q1_financial_reports', ['stock_code' => '3054']);

        $row = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '8261')->first();
        $previousClose = 126 / (1 + (-1.56 / 100));
        $this->assertNotNull($row);
        $this->assertSame('2026-05-08', substr((string) $row->latest_price_date, 0, 10));
        $this->assertEqualsWithDelta(130, (float) $row->latest_close_price, 0.0001);
        $this->assertEqualsWithDelta(1600, (int) $row->volume_lots, 0.0001);
        $this->assertEqualsWithDelta(((130 - $previousClose) / $previousClose) * 100, (float) $row->price_change_1d_percent, 0.0001);
        $this->assertNotNull($row->price_change_5d_percent);
        $this->assertNotNull($row->price_change_20d_percent);
    }

    private function fakeResponse(string $url): mixed
    {
        if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL')) {
            return Http::response([
                [
                    'Date' => '1150508',
                    'Code' => '8261',
                    'Name' => '富鼎',
                    'TradeVolume' => '4695000',
                    'ClosingPrice' => '126.00',
                ],
                [
                    'Date' => '1150508',
                    'Code' => '2330',
                    'Name' => '台積電',
                    'TradeVolume' => '2000000',
                    'ClosingPrice' => '900.00',
                ],
                [
                    'Date' => '1150508',
                    'Code' => '1111',
                    'Name' => '低量股',
                    'TradeVolume' => '500000',
                    'ClosingPrice' => '10.00',
                ],
                [
                    'Date' => '1150508',
                    'Code' => '00939',
                    'Name' => 'ETF',
                    'TradeVolume' => '9000000',
                    'ClosingPrice' => '14.00',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes')) {
            return Http::response([
                [
                    'Date' => '1150508',
                    'SecuritiesCompanyCode' => '9951',
                    'CompanyName' => '皇田',
                    'TradingShares' => '1200000',
                    'Close' => '80.00',
                ],
                [
                    'Date' => '1150508',
                    'SecuritiesCompanyCode' => '5289',
                    'CompanyName' => '宜鼎',
                    'TradingShares' => '6796000',
                    'Close' => '1600.00',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/eps/data')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            return Http::response(['data' => [
                [
                    '股票代號' => (string) ($query['stock_id'] ?? ''),
                    '季度EPS' => $this->epsRows((string) ($query['stock_id'] ?? '')),
                ],
            ]]);
        }

        if (str_starts_with($url, 'https://api.cnyes.com/media/api/v1/newslist/')) {
            if (str_contains($url, 'TWS:5289:STOCK')) {
                return Http::response([
                    'items' => [
                        'data' => [
                            [
                                'title' => '宜鼎:本公司董事會通過115年第1季合併財務報告',
                                'newsId' => 6450934,
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response(['items' => ['data' => []]]);
        }

        if (str_starts_with($url, 'https://news.cnyes.com/news/id/6450934')) {
            return Http::response($this->cnyesFinancialNewsHtml());
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/monthly-revenue/data')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            return Http::response(['data' => [
                [
                    '股票代號' => (string) ($query['stock_id'] ?? ''),
                    '月營收' => $this->monthlyRevenueRows((string) ($query['stock_id'] ?? '')),
                ],
            ]]);
        }

        if (str_starts_with($url, 'https://www.twse.com.tw/rwd/zh/afterTrading/STOCK_DAY')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            return Http::response([
                'stat' => 'OK',
                'data' => $this->twseMonthlyRows((string) ($query['stockNo'] ?? '')),
            ]);
        }

        if (str_starts_with($url, 'https://www.tpex.org.tw/www/zh-tw/afterTrading/tradingStock')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);

            return Http::response([
                'stat' => 'ok',
                'tables' => [
                    ['data' => $this->tpexMonthlyRows((string) ($query['code'] ?? ''))],
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/daily-stock-data/data')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $stockCode = (string) ($query['stock_id'] ?? '');

            return Http::response(['data' => [
                [
                    '股票代號' => $stockCode,
                    '日K' => $stockCode === '9951'
                        ? $this->dailyRows(80, 78, 70, 100, 1200)
                        : $this->dailyRows(126, -1.56, 120, 100, 4695),
                ],
            ]]);
        }

        return Http::response([], 404);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function epsRows(string $stockCode): array
    {
        if ($stockCode === '8261') {
            return [
                [
                    '年季' => '202601',
                    '公告基本每股盈餘(元)' => '1.57',
                    '公告基本每股盈餘年成長2(%)' => '8.28',
                    '季營收(億)' => '7.82',
                    '單季年成長(％)' => '9.17',
                    '單季毛利率(％)' => '38.07',
                    '單季營業利益率(％)' => '25.66',
                    '單季稅後淨利率(％)' => '23.90',
                    '單季稅後淨利(億)' => '1.87',
                    '稅後權益報酬率(%)' => '3.10',
                    '稅後資產報酬率(%)' => '2.76',
                    '本業佔比' => '85.23',
                ],
            ];
        }

        if ($stockCode === '9951') {
            return [
                [
                    '年季' => '202601',
                    '公告基本每股盈餘(元)' => '2.00',
                    '公告基本每股盈餘年成長2(%)' => '3.00',
                    '季營收(億)' => '20.00',
                    '單季年成長(％)' => '-5.00',
                    '單季毛利率(％)' => '30.00',
                    '單季營業利益率(％)' => '18.00',
                    '單季稅後淨利率(％)' => '10.00',
                    '單季稅後淨利(億)' => '2.00',
                    '稅後權益報酬率(%)' => '4.00',
                    '稅後資產報酬率(%)' => '2.50',
                    '本業佔比' => '90.00',
                ],
            ];
        }

        if ($stockCode === '2330') {
            return [
                [
                    '年季' => '202504',
                    '公告基本每股盈餘(元)' => '15.00',
                    '季營收(億)' => '8000.00',
                ],
            ];
        }

        if ($stockCode === '5289') {
            return [
                [
                    '年季' => '202501',
                    '公告基本每股盈餘(元)' => '3.68',
                    '季營收(億)' => '26.18742',
                ],
            ];
        }

        return [];
    }

    /**
     * @return list<array<int, string>>
     */
    private function twseMonthlyRows(string $stockCode): array
    {
        if ($stockCode !== '8261') {
            return [];
        }

        return $this->twseMonthlyRowsForMarketData(126, -1.56, 120, 100, 4695);
    }

    /**
     * @return list<array<int, string>>
     */
    private function twseMonthlyRowsForMarketData(float $latestClose, float $oneDayChange, float $fiveDayClose, float $twentyDayClose, int $volumeLots): array
    {
        return array_map(fn (array $row): array => [
            $this->rocDate((string) $row['交易日']),
            number_format(((int) $row['成交量']) * 1000),
            '0',
            '',
            '',
            '',
            (string) $row['收盤價'],
            '',
            '',
        ], array_reverse($this->dailyRows($latestClose, $oneDayChange, $fiveDayClose, $twentyDayClose, $volumeLots)));
    }

    /**
     * @return list<array<int, string>>
     */
    private function tpexMonthlyRows(string $stockCode): array
    {
        if ($stockCode === '5289') {
            return array_map(fn (array $row): array => [
                $this->rocDate((string) $row['交易日']),
                number_format((int) $row['成交量']),
                '0',
                '',
                '',
                '',
                (string) $row['收盤價'],
                '',
                '',
            ], array_reverse($this->dailyRows(1600, 1.59, 1420, 1000, 7080)));
        }

        if ($stockCode !== '9951') {
            return [];
        }

        return array_map(fn (array $row): array => [
            $this->rocDate((string) $row['交易日']),
            number_format((int) $row['成交量']),
            '0',
            '',
            '',
            '',
            (string) $row['收盤價'],
            '',
            '',
        ], array_reverse($this->dailyRows(80, 2.5, 70, 100, 1200)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dailyRows(float $latestClose, float $oneDayChange, float $fiveDayClose, float $twentyDayClose, int $volumeLots): array
    {
        $rows = [];
        $latestDate = CarbonImmutable::createFromFormat('Ymd', '20260508');
        for ($index = 0; $index <= 20; $index++) {
            $rows[] = [
                '交易日' => $latestDate->subDays($index)->format('Ymd'),
                '收盤價' => (string) ($latestClose - $index),
                '漲幅(%)' => $index === 0 ? (string) $oneDayChange : '0',
                '成交量' => (string) $volumeLots,
            ];
        }

        $rows[0]['收盤價'] = (string) $latestClose;
        $rows[1]['收盤價'] = (string) ($latestClose / (1 + ($oneDayChange / 100)));
        $rows[5]['收盤價'] = (string) $fiveDayClose;
        $rows[20]['收盤價'] = (string) $twentyDayClose;

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function monthlyRevenueRows(string $stockCode): array
    {
        if ($stockCode === '5289') {
            return [
                [
                    '年月' => '202604',
                    '單月營收年成長(%)' => '583.1',
                    '累計營收成長(%)' => '452.2',
                    '單月營收月變動(%)' => '17.6',
                    '單月營收(億)' => '66.71',
                    '累計營收(億)' => '198.53',
                ],
                [
                    '年月' => '202603',
                    '單月營收年成長(%)' => '484.8',
                    '累計營收成長(%)' => '403.4',
                    '單月營收月變動(%)' => '35.75',
                    '單月營收(億)' => '56.72',
                    '累計營收(億)' => '131.83',
                ],
                [
                    '年月' => '202602',
                    '單月營收年成長(%)' => '360.3',
                    '累計營收成長(%)' => '343.2',
                    '單月營收月變動(%)' => '21.4',
                    '單月營收(億)' => '41.79',
                    '累計營收(億)' => '75.10',
                ],
                [
                    '年月' => '202601',
                    '單月營收年成長(%)' => '322.1',
                    '累計營收成長(%)' => '322.1',
                    '單月營收月變動(%)' => '9.9',
                    '單月營收(億)' => '33.31',
                    '累計營收(億)' => '33.31',
                ],
                [
                    '年月' => '202512',
                    '單月營收年成長(%)' => '288.8',
                    '累計營收成長(%)' => '288.8',
                    '單月營收月變動(%)' => '8.8',
                    '單月營收(億)' => '30.12',
                    '累計營收(億)' => '399.99',
                ],
                [
                    '年月' => '202511',
                    '單月營收年成長(%)' => '277.7',
                    '累計營收成長(%)' => '277.7',
                    '單月營收月變動(%)' => '7.7',
                    '單月營收(億)' => '29.11',
                    '累計營收(億)' => '369.87',
                ],
            ];
        }

        return [
            [
                '年月' => '202604',
                '單月營收年成長(%)' => '10',
                '累計營收成長(%)' => '8',
                '單月營收月變動(%)' => '5',
                '單月營收(億)' => '8',
                '累計營收(億)' => '28',
            ],
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function monthlyRevenueRowsWithSixtyMonths(): array
    {
        $rows = [];
        $month = CarbonImmutable::create(2026, 4, 1);
        for ($index = 0; $index < 60; $index++) {
            $rows[] = [
                '年月' => $month->subMonths($index)->format('Ym'),
                '單月營收年成長(%)' => '10',
                '累計營收成長(%)' => '8',
                '單月營收月變動(%)' => '5',
                '單月營收(億)' => '8',
                '累計營收(億)' => '28',
            ];
        }

        return $rows;
    }

    private function mopsMonthlyRevenueCsv(): string
    {
        return "\xEF\xBB\xBF" . implode("\n", [
            '出表日期,資料年月,公司代號,公司名稱,產業別,營業收入-當月營收,營業收入-上月營收,營業收入-去年當月營收,營業收入-上月比較增減(%),營業收入-去年同月增減(%),累計營業收入-當月累計營收,累計營業收入-去年累計營收,累計營業收入-前期比較增減(%),備註',
            '"115/05/07","114/10","5289","宜鼎","電子零組件業","1234000","1000000","983267","23.4","25.5","9876000","8000000","23.45","-"',
        ]);
    }

    private function fakeMopsFallbackResponse(mixed $request): mixed
    {
        $url = $request->url();
        $data = method_exists($request, 'data') ? $request->data() : [];

        if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL')) {
            return Http::response([
                [
                    'Date' => '1150508',
                    'Code' => '2363',
                    'Name' => '矽統',
                    'TradeVolume' => '2000000',
                    'ClosingPrice' => '20.00',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes')) {
            return Http::response([
                [
                    'Date' => '1150508',
                    'SecuritiesCompanyCode' => '1785',
                    'CompanyName' => '光洋科',
                    'TradingShares' => '3000000',
                    'Close' => '74.00',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/eps/data')) {
            parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $query);
            $stockCode = (string) ($query['stock_id'] ?? '');

            return Http::response(['data' => [
                [
                    '股票代號' => $stockCode,
                    '季度EPS' => match ($stockCode) {
                        '1785' => [[
                            '年季' => '202501',
                            '公告基本每股盈餘(元)' => '0.60',
                            '季營收(億)' => '8.24',
                        ]],
                        '2363' => [[
                            '年季' => '202501',
                            '公告基本每股盈餘(元)' => '0.05',
                            '季營收(億)' => '8.00',
                        ]],
                        default => [],
                    },
                ],
            ]]);
        }

        if (str_starts_with($url, 'https://mopsov.twse.com.tw/mops/web/ajax_t05st01')) {
            if (($data['step'] ?? null) === '1' && ($data['co_id'] ?? null) === '1785' && ($data['month'] ?? null) === '05') {
                return Http::response($this->mopsListHtml(
                    '1785',
                    '光洋科',
                    '115/05/08',
                    '17:55:57',
                    '本公司民國115年度第一季合併財務報告業經董事會決議',
                    '20260508',
                    '175557',
                    '2',
                    'otc',
                ));
            }

            if (($data['step'] ?? null) === '1' && ($data['co_id'] ?? null) === '2363' && ($data['month'] ?? null) === '04') {
                return Http::response($this->mopsListHtml(
                    '2363',
                    '矽統',
                    '115/04/27',
                    '16:23:39',
                    '公告本公司董事會決議通過民國115年第一季合併財務報告',
                    '20260427',
                    '162339',
                    '1',
                    'sii',
                ));
            }

            if (($data['step'] ?? null) === '2' && ($data['co_id'] ?? null) === '1785') {
                return Http::response($this->mopsDetailHtml([
                    'revenue' => '14,557,891',
                    'gross_profit' => '2,653,726',
                    'operating_profit' => '2,020,785',
                    'net_income' => '1,591,892',
                    'parent_net_income' => '1,514,364',
                    'eps' => '2.54',
                    'assets' => '39,179,443',
                    'parent_equity' => '16,634,762',
                ]));
            }

            if (($data['step'] ?? null) === '2' && ($data['co_id'] ?? null) === '2363') {
                return Http::response($this->mopsDetailHtml([
                    'revenue' => '1,050,018',
                    'gross_profit' => '302,351',
                    'operating_profit' => '75,564',
                    'net_income' => '87,746',
                    'parent_net_income' => '87,795',
                    'eps' => '0.17',
                    'assets' => '22,546,270',
                    'parent_equity' => '20,360,333',
                ]));
            }

            return Http::response('<html><body><table></table></body></html>');
        }

        if (str_starts_with($url, 'https://api.cnyes.com/media/api/v1/newslist/')) {
            return Http::response(['items' => ['data' => []]]);
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/monthly-revenue/data')) {
            return Http::response(['data' => [
                [
                    '股票代號' => '0000',
                    '月營收' => $this->monthlyRevenueRows('0000'),
                ],
            ]]);
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/daily-stock-data/data')) {
            return Http::response(['data' => [
                [
                    '股票代號' => '0000',
                    '日K' => $this->dailyRows(74, 2, 70, 60, 3000),
                ],
            ]]);
        }

        return Http::response(['stat' => 'ok', 'data' => [], 'tables' => [['data' => []]]]);
    }

    private function fakeBackfillResponse(mixed $request): mixed
    {
        $url = $request->url();

        if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL')) {
            return Http::response([
                [
                    'Date' => '1150508',
                    'Code' => '8261',
                    'Name' => '富鼎',
                    'TradeVolume' => '1500000',
                    'ClosingPrice' => '126.00',
                ],
            ]);
        }

        if (str_starts_with($url, 'https://www.tpex.org.tw/openapi/v1/tpex_mainboard_quotes')) {
            return Http::response([]);
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/eps/data')) {
            return Http::response(['data' => [
                [
                    '股票代號' => '8261',
                    '季度EPS' => [
                        $this->quarterRow('202601', 1.61, 16.1, 36.1, 26.1, 21.1),
                        $this->quarterRow('202504', 4.44, 44.4, 44.4, 24.4, 14.4),
                        $this->quarterRow('202503', 3.33, 33.3, 43.3, 23.3, 13.3),
                        $this->quarterRow('202502', 2.22, 22.2, 42.2, 22.2, 12.2),
                        $this->quarterRow('202501', 1.11, 11.1, 41.1, 21.1, 11.1),
                    ],
                ],
            ]]);
        }

        if (str_starts_with($url, 'https://www.nstock.tw/api/v2/monthly-revenue/data')) {
            return Http::response(['data' => [
                [
                    '股票代號' => '8261',
                    '月營收' => $this->monthlyRevenueRows('5289'),
                ],
            ]]);
        }

        if (str_starts_with($url, 'https://www.twse.com.tw/rwd/zh/afterTrading/STOCK_DAY')) {
            return Http::response([
                'stat' => 'OK',
                'data' => $this->twseMonthlyRows('8261'),
            ]);
        }

        if (str_starts_with($url, 'https://mopsov.twse.com.tw/mops/web/ajax_t05st01')) {
            return Http::response('<html><body><table></table></body></html>');
        }

        if (str_starts_with($url, 'https://api.cnyes.com/media/api/v1/newslist/')) {
            return Http::response(['items' => ['data' => []]]);
        }

        return Http::response(['stat' => 'ok', 'data' => [], 'tables' => [['data' => []]]]);
    }

    private function quarterRow(
        string $period,
        float $eps,
        float $revenueBillion,
        float $grossMargin,
        float $operatingMargin,
        float $netMargin,
    ): array {
        return [
            '年季' => $period,
            '公告基本每股盈餘(元)' => (string) $eps,
            '公告基本每股盈餘年成長2(%)' => (string) ($eps * 10),
            '季營收(億)' => (string) $revenueBillion,
            '單季年成長(％)' => (string) ($revenueBillion * 2),
            '單季毛利率(％)' => (string) $grossMargin,
            '單季營業利益率(％)' => (string) $operatingMargin,
            '單季稅後淨利率(％)' => (string) $netMargin,
            '單季稅後淨利(億)' => (string) ($revenueBillion * ($netMargin / 100)),
            '稅後權益報酬率(%)' => '4.00',
            '稅後資產報酬率(%)' => '2.50',
            '本業佔比' => '90.00',
        ];
    }

    private function mopsListHtml(
        string $stockCode,
        string $stockName,
        string $rocDate,
        string $spokenTime,
        string $title,
        string $spokeDate,
        string $spokeTime,
        string $seqNo,
        string $typek,
    ): string {
        return <<<HTML
<html><body>
<table class='hasBorder'>
<tr class='odd'>
<td>&nbsp;{$stockCode}</td><td>&nbsp;{$stockName}</td><td>&nbsp;{$rocDate}</td><td>&nbsp;{$spokenTime}</td>
<td><pre><font size='3'>&nbsp;{$title}</font></pre></td>
<td><input type='button' onclick="document.t05st01_fm.action='ajax_t05st01';document.t05st01_fm.seq_no.value='{$seqNo}';document.t05st01_fm.spoke_time.value='{$spokeTime}';document.t05st01_fm.spoke_date.value='{$spokeDate}';document.t05st01_fm.co_id.value='{$stockCode}';document.t05st01_fm.TYPEK.value='{$typek}';openWindow(this.form ,'');"></td>
</tr>
</table>
</body></html>
HTML;
    }

    /**
     * @param array<string, string> $values
     */
    private function mopsDetailHtml(array $values): string
    {
        return <<<HTML
<html><body>
<p>3.財務報告或年度自結財務資訊報導期間起訖日期(XXX/XX/XX~XXX/XX/XX):115/01/01~115/03/31</p>
<p>4.1月1日累計至本期止營業收入(仟元):{$values['revenue']}</p>
<p>5.1月1日累計至本期止營業毛利(毛損) (仟元):{$values['gross_profit']}</p>
<p>6.1月1日累計至本期止營業利益(損失) (仟元):{$values['operating_profit']}</p>
<p>8.1月1日累計至本期止本期淨利(淨損) (仟元):{$values['net_income']}</p>
<p>9.1月1日累計至本期止歸屬於母公司業主淨利(損) (仟元):{$values['parent_net_income']}</p>
<p>10.1月1日累計至本期止基本每股盈餘(損失) (元):{$values['eps']}</p>
<p>11.期末總資產(仟元):{$values['assets']}</p>
<p>13.期末歸屬於母公司業主之權益(仟元):{$values['parent_equity']}</p>
</body></html>
HTML;
    }

    private function cnyesFinancialNewsHtml(): string
    {
        return <<<'HTML'
<!doctype html>
<html lang="zh-Hant">
<body>
<article>
<p>公司代號：5289</p>
<p>公司名稱：宜鼎</p>
<p>3.財務報告或年度自結財務資訊報導期間起訖日期(XXX/XX/XX~XXX/XX/XX):115/01/01~115/03/31</p>
<p>4.1月1日累計至本期止營業收入(仟元):13,182,608</p>
<p>5.1月1日累計至本期止營業毛利(毛損) (仟元):7,791,357</p>
<p>6.1月1日累計至本期止營業利益(損失) (仟元):6,850,200</p>
<p>7.1月1日累計至本期止稅前淨利(淨損) (仟元):6,917,027</p>
<p>8.1月1日累計至本期止本期淨利(淨損) (仟元):5,477,529</p>
<p>9.1月1日累計至本期止歸屬於母公司業主淨利(損) (仟元):5,466,357</p>
<p>10.1月1日累計至本期止基本每股盈餘(損失) (元):57.49</p>
<p>11.期末總資產(仟元):24,630,697</p>
<p>13.期末歸屬於母公司業主之權益(仟元):14,484,612</p>
</article>
</body>
</html>
HTML;
    }

    /**
     * @param list<string> $stockCodes
     */
    private function assertTableOrder(string $html, array $stockCodes): void
    {
        preg_match('/<tbody>(.*?)<\\/tbody>/s', $html, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        $lastPosition = -1;
        foreach ($stockCodes as $stockCode) {
            $position = strpos($matches[1], $stockCode);
            $this->assertNotFalse($position, 'Missing stock code in table: ' . $stockCode);
            $this->assertGreaterThan($lastPosition, $position, 'Unexpected table order for stock code: ' . $stockCode);
            $lastPosition = $position;
        }
    }

    /**
     * @param array<int, float|int> $annualRevenueBillion
     * @param array<int, float|int> $annualEps
     * @return list<array<string, mixed>>
     */
    private function annualComparisonRows(
        string $stockCode,
        string $stockName,
        array $annualRevenueBillion,
        array $annualEps,
        float $netMargin,
        bool $fullRecentMargins,
    ): array {
        $monthlyRevenues = [];
        foreach ($annualRevenueBillion as $year => $revenueBillion) {
            $monthlyRevenues[] = [
                'year_month' => sprintf('%04d%s', $year, (int) $year === 2026 ? '04' : '12'),
                'revenue_billion' => $revenueBillion,
            ];
        }

        usort(
            $monthlyRevenues,
            fn (array $left, array $right): int => strcmp((string) $right['year_month'], (string) $left['year_month'])
        );

        $monthlyJson = json_encode($monthlyRevenues, JSON_THROW_ON_ERROR);
        $rows = [];

        foreach ($annualEps as $year => $eps) {
            $rows[] = $this->row([
                'fiscal_year' => $year,
                'quarter' => 1,
                'financial_period' => sprintf('%04d01', $year),
                'exchange' => 'TWSE',
                'stock_code' => $stockCode,
                'stock_name' => $stockName,
                'q1_eps' => $eps,
                'q1_net_margin_percent' => $netMargin,
                'q1_gross_margin_percent' => $netMargin + 12,
                'q1_operating_margin_percent' => $netMargin + 5,
                'recent_monthly_revenues' => $monthlyJson,
            ]);
        }

        if ($fullRecentMargins) {
            foreach ([2024, 2025] as $year) {
                foreach ([2, 3, 4] as $quarter) {
                    $rows[] = $this->row([
                        'fiscal_year' => $year,
                        'quarter' => $quarter,
                        'financial_period' => sprintf('%04d%02d', $year, $quarter),
                        'exchange' => 'TWSE',
                        'stock_code' => $stockCode,
                        'stock_name' => $stockName,
                        'q1_eps' => 0,
                        'q1_net_margin_percent' => $netMargin,
                        'q1_gross_margin_percent' => $netMargin + 12,
                        'q1_operating_margin_percent' => $netMargin + 5,
                        'recent_monthly_revenues' => $monthlyJson,
                    ]);
                }
            }
        }

        return $rows;
    }

    private function rocDate(string $ymd): string
    {
        $year = (int) substr($ymd, 0, 4) - 1911;

        return sprintf('%03d/%02d/%02d', $year, (int) substr($ymd, 4, 2), (int) substr($ymd, 6, 2));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'fiscal_year' => 2026,
            'quarter' => 1,
            'financial_period' => '202601',
            'exchange' => 'TWSE',
            'stock_code' => '8261',
            'stock_name' => '富鼎',
            'q1_revenue_billion' => 7.82,
            'q1_revenue_yoy_percent' => 9.17,
            'q1_revenue_score' => 40,
            'q1_eps' => 1.57,
            'q1_eps_yoy_percent' => 8.28,
            'q1_gross_margin_percent' => 38.07,
            'q1_operating_margin_percent' => 25.66,
            'q1_net_margin_percent' => 23.90,
            'q1_net_income_billion' => 1.87,
            'roe_percent' => 3.10,
            'roa_percent' => 2.76,
            'operating_profit_mix_percent' => 85.23,
            'recent_monthly_revenues' => null,
            'latest_close_price' => 126,
            'latest_price_date' => '2026-05-08',
            'volume_lots' => 4695,
            'price_change_1d_percent' => -1.56,
            'price_change_5d_percent' => 5,
            'price_change_20d_percent' => 26,
            'rank' => 2,
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    private function createTable(): void
    {
        Schema::create('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter')->default(1);
            $table->string('financial_period', 8);
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->decimal('q1_revenue_billion', 14, 4)->nullable();
            $table->decimal('q1_revenue_yoy_percent', 10, 4)->nullable();
            $table->decimal('q1_revenue_score', 8, 4)->nullable();
            $table->decimal('q1_eps', 10, 4)->nullable();
            $table->decimal('q1_eps_yoy_percent', 10, 4)->nullable();
            $table->decimal('q1_gross_margin_percent', 10, 4)->nullable();
            $table->decimal('q1_operating_margin_percent', 10, 4)->nullable();
            $table->decimal('q1_net_margin_percent', 10, 4)->nullable();
            $table->decimal('q1_net_income_billion', 14, 4)->nullable();
            $table->decimal('roe_percent', 10, 4)->nullable();
            $table->decimal('roa_percent', 10, 4)->nullable();
            $table->decimal('operating_profit_mix_percent', 10, 4)->nullable();
            $table->json('recent_monthly_revenues')->nullable();
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->date('latest_price_date')->nullable();
            $table->unsignedInteger('volume_lots')->nullable();
            $table->decimal('price_change_1d_percent', 10, 4)->nullable();
            $table->decimal('price_change_5d_percent', 10, 4)->nullable();
            $table->decimal('price_change_20d_percent', 10, 4)->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->json('source_payload')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tw_stock_annual_financial_comparisons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('context_year');
            $table->unsignedSmallInteger('comparison_start_year');
            $table->unsignedSmallInteger('comparison_end_year');
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->decimal('revenue_yoy_sum', 20, 4)->nullable();
            $table->decimal('eps_yoy_sum', 20, 4)->nullable();
            $table->decimal('recent_net_margin_average', 12, 4)->nullable();
            $table->decimal('last_two_year_net_margin_average', 12, 4)->nullable();
            $table->boolean('revenue_filter_pass')->default(false);
            $table->boolean('eps_filter_pass')->default(false);
            $table->boolean('eps_yoy_all_positive')->default(false);
            $table->boolean('net_margin_filter_pass')->default(false);
            $table->decimal('current_revenue_billion', 20, 4)->nullable();
            $table->unsignedTinyInteger('current_revenue_months')->default(0);
            $table->decimal('current_eps', 12, 4)->nullable();
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->unsignedInteger('volume_lots')->nullable();
            $table->json('comparisons');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }
}
