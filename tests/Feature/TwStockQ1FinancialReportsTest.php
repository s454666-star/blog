<?php

namespace Tests\Feature;

use App\Models\TwStockQ1FinancialReport;
use App\Services\TwStockQ1FinancialReportFetcher;
use App\Services\TwStockQ1ValuationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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
        Cache::flush();

        Carbon::setTestNow('2026-05-08 17:00:00');
        CarbonImmutable::setTestNow('2026-05-08 17:00:00');

        Schema::dropAllTables();
        $this->createTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('tw_stock_annual_financial_comparisons');
        Schema::dropIfExists('tw_stock_company_profiles');
        Schema::dropIfExists('tw_stock_q1_financial_reports');
        Schema::dropIfExists('tw_stock_daily_turnover_rates');
        Schema::dropIfExists('tw_stock_daily_prices');

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
        $this->assertSame('半導體業', $top->industry);
        $this->assertSame('記憶體/儲存', $top->valuation_group);
        $this->assertEqualsWithDelta(38.0, (float) $top->valuation_group_pe, 0.0001);
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
        $this->assertSame('半導體業', $second->industry);
        $this->assertSame('IC設計', $second->valuation_group);
        $this->assertEqualsWithDelta(45.0, (float) $second->valuation_group_pe, 0.0001);
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
            $this->row([
                'exchange' => 'TPEx',
                'stock_code' => '9951',
                'stock_name' => '皇田',
                'industry' => '汽車工業',
                'valuation_group' => '汽車/電動車',
                'valuation_group_pe' => 22.0,
                'q1_revenue_score' => 60,
                'latest_close_price' => 110,
                'rank' => 1,
            ]),
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
            ->assertSee('預期股價')
            ->assertSee('147.83')
            ->assertSee('(+34.39%)')
            ->assertSee('PE 23.5x')
            ->assertSee('汽車/電動車 22.0x')
            ->assertDontSee('市場平均')
            ->assertSee('近1月營收')
            ->assertSee('前段班')
            ->assertSee('name="price_min"', false)
            ->assertSee('value="100"', false)
            ->assertSee('name="price_max"', false)
            ->assertSee('value="120"', false)
            ->assertSee('name="valuation_groups[]"', false)
            ->assertSee('全部族群')
            ->assertSee("document.querySelectorAll('[data-multi-select][open]')", false)
            ->assertSee("multiSelect.removeAttribute('open')", false)
            ->assertSee('name="sort"', false)
            ->assertSee('近3日兩月新高')
            ->assertSee('sort=eps_yoy', false)
            ->assertSee('sort=expected_price', false)
            ->assertSee('data-tooltip="點一下排序：高到低"', false)
            ->assertSee('data-tooltip="點一下排序：低到高"', false)
            ->assertSee('data-auto-submit-form', false)
            ->assertSee('data-sticky-table-wrap', false)
            ->assertSee('q1-sticky-table-head', false)
            ->assertSee('syncStickyHeader', false)
            ->assertDontSee('本業佔比')
            ->assertDontSee('成交量(張)')
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

    public function test_dashboard_shows_exchange_badges_under_stock_name(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row([
                'exchange' => 'TWSE',
                'stock_code' => '2337',
                'stock_name' => '旺宏',
                'created_at' => now()->subMinute(),
                'rank' => 1,
            ]),
            $this->row([
                'exchange' => 'TPEx',
                'stock_code' => '3529',
                'stock_name' => '力旺',
                'rank' => 2,
            ]),
            $this->row([
                'exchange' => 'Emerging',
                'stock_code' => '9999',
                'stock_name' => '興櫃測試',
                'created_at' => now()->subMinutes(30),
                'rank' => 3,
            ]),
        ]);

        $response = $this->get(route('tw-stock.q1-financial-reports.index', ['per_page' => 50]))
            ->assertOk()
            ->assertSee('exchange-badge exchange-badge--twse', false)
            ->assertSee('title="上市"', false)
            ->assertSee('>市<', false)
            ->assertSee('class="realtime-price-link"', false)
            ->assertSee('href="https://tw.stock.yahoo.com/quote/2337.TW"', false)
            ->assertSee('>即時</a>', false)
            ->assertSee('class="latest-update-badge"', false)
            ->assertSee('>新</span>', false)
            ->assertSee('資料最新日期')
            ->assertSee('最新資料筆數')
            ->assertDontSee('Q1 營收最大')
            ->assertDontSee('營收年增最高')
            ->assertSee('exchange-badge exchange-badge--tpex', false)
            ->assertSee('title="上櫃"', false)
            ->assertSee('>櫃<', false)
            ->assertSee('href="https://tw.stock.yahoo.com/quote/3529.TW"', false)
            ->assertSee('exchange-badge exchange-badge--emerging', false)
            ->assertSee('title="興櫃"', false)
            ->assertSee('>興<', false)
            ->assertSee('href="https://tw.stock.yahoo.com/quote/9999.TW"', false);

        $this->assertMatchesRegularExpression('/<div class="label">資料最新日期<\/div>\s*<div class="value">2026-05-08<\/div>/', $response->getContent());
        $this->assertMatchesRegularExpression('/<div class="label">最新資料筆數<\/div>\s*<div class="value">3<\/div>/', $response->getContent());
        $this->assertSame(3, substr_count($response->getContent(), 'class="latest-update-badge"'));

        DB::table('tw_stock_q1_financial_reports')->update([
            'fetched_at' => now()->addDay(),
            'updated_at' => now()->addDay(),
        ]);
        Cache::flush();

        $this->get(route('tw-stock.q1-financial-reports.index', ['per_page' => 50]))
            ->assertOk()
            ->assertDontSee('class="latest-update-badge"', false);
    }

    public function test_dashboard_filters_by_multiple_valuation_groups(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row([
                'stock_code' => '9951',
                'stock_name' => '皇田',
                'valuation_group' => '汽車/電動車',
                'valuation_group_pe' => 22.0,
                'rank' => 1,
            ]),
            $this->row([
                'stock_code' => '8261',
                'stock_name' => '富鼎',
                'valuation_group' => 'IC設計',
                'valuation_group_pe' => 45.0,
                'rank' => 2,
            ]),
            $this->row([
                'stock_code' => '5289',
                'stock_name' => '宜鼎',
                'valuation_group' => '記憶體/儲存',
                'valuation_group_pe' => 38.0,
                'rank' => 3,
            ]),
        ]);

        $response = $this->get(route('tw-stock.q1-financial-reports.index', [
            'valuation_groups' => ['汽車/電動車', '記憶體/儲存'],
            'per_page' => 50,
        ]));

        $response->assertOk()
            ->assertSee('汽車/電動車、記憶體/儲存')
            ->assertSee('value="汽車/電動車" checked', false)
            ->assertSee('value="記憶體/儲存" checked', false)
            ->assertSee('9951')
            ->assertSee('5289')
            ->assertDontSee('8261');
    }

    public function test_dashboard_marks_recent_two_month_high_from_daily_prices(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row([
                'stock_code' => '9951',
                'stock_name' => '皇田',
                'rank' => 2,
            ]),
            $this->row([
                'stock_code' => '8261',
                'stock_name' => '富鼎',
                'rank' => 1,
            ]),
        ]);

        $latestDate = CarbonImmutable::create(2026, 5, 8);
        $dailyRows = [];
        for ($index = 0; $index < 50; $index++) {
            $date = $latestDate->subDays($index)->toDateString();

            $dailyRows[] = $this->dailyPriceRow(
                '9951',
                '皇田',
                $date,
                $index === 1 ? 132.0 : 118.0 - min($index, 20),
            );
            $dailyRows[] = $this->dailyPriceRow(
                '8261',
                '富鼎',
                $date,
                $index === 8 ? 132.0 : 118.0 - min($index, 20),
            );
        }

        DB::table('tw_stock_daily_prices')->insert($dailyRows);

        $response = $this->get(route('tw-stock.q1-financial-reports.index', [
            'sort' => 'recent_two_month_high',
            'direction' => 'desc',
            'per_page' => 50,
        ]));

        $response->assertOk()
            ->assertSee('近3日兩月新高')
            ->assertSee('recent-high-row', false)
            ->assertSee('aria-label="近三日內有兩個月新高"', false)
            ->assertDontSee('本業佔比');
        $this->assertTableOrder($response->getContent(), ['9951', '8261']);

        $filteredResponse = $this->get(route('tw-stock.q1-financial-reports.index', [
            'recent_two_month_high' => 1,
            'per_page' => 50,
        ]));

        $filteredResponse->assertOk()
            ->assertSee('name="recent_two_month_high" value="1" checked', false)
            ->assertSee('9951')
            ->assertDontSee('8261');
        $this->assertTableOrder($filteredResponse->getContent(), ['9951']);
    }

    public function test_dashboard_shows_valuation_group_when_expected_price_is_unavailable(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert($this->row([
            'stock_code' => '3138',
            'stock_name' => '耀登',
            'industry' => '通信網路業',
            'valuation_group' => '通信網路',
            'valuation_group_pe' => 24.0,
            'q1_eps' => -0.02,
            'q1_revenue_score' => 52,
            'latest_close_price' => 155,
            'rank' => 1,
        ]));

        $this->get(route('tw-stock.q1-financial-reports.index', ['q' => '3138']))
            ->assertOk()
            ->assertSee('--')
            ->assertSee('通信網路 24.0x');
    }

    public function test_reasonable_pe_uses_latest_post_q1_monthly_revenue_momentum(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert([
            $this->row([
                'stock_code' => '1111',
                'valuation_group_pe' => 20.0,
                'q1_revenue_score' => 50.0,
                'q1_revenue_billion' => 30.0,
                'recent_monthly_revenues' => json_encode([
                    ['year_month' => '202604', 'revenue_billion' => 15.0],
                    ['year_month' => '202605', 'revenue_billion' => 20.0],
                    ['year_month' => '202603', 'revenue_billion' => 9.0],
                ], JSON_THROW_ON_ERROR),
            ]),
            $this->row([
                'stock_code' => '2222',
                'valuation_group_pe' => 20.0,
                'q1_revenue_score' => 50.0,
                'q1_revenue_billion' => 30.0,
                'recent_monthly_revenues' => json_encode([
                    ['year_month' => '202604', 'revenue_billion' => 5.0],
                ], JSON_THROW_ON_ERROR),
            ]),
            $this->row([
                'stock_code' => '3333',
                'valuation_group_pe' => 20.0,
                'q1_revenue_score' => 50.0,
                'q1_revenue_billion' => 30.0,
                'recent_monthly_revenues' => json_encode([
                    ['year_month' => '202603', 'revenue_billion' => 15.0],
                ], JSON_THROW_ON_ERROR),
            ]),
        ]);

        $higher = TwStockQ1FinancialReport::query()->where('stock_code', '1111')->firstOrFail();
        $lower = TwStockQ1FinancialReport::query()->where('stock_code', '2222')->firstOrFail();
        $notPublished = TwStockQ1FinancialReport::query()->where('stock_code', '3333')->firstOrFail();

        $this->assertEqualsWithDelta(100.0, $higher->latestMonthlyRevenueVsQ1AveragePercent(), 0.0001);
        $this->assertEqualsWithDelta(20.0, $higher->revenueMomentumPeAdjustmentPercent(), 0.0001);
        $this->assertEqualsWithDelta(24.6, $higher->reasonablePeRatio(), 0.0001);

        $this->assertEqualsWithDelta(-50.0, $lower->latestMonthlyRevenueVsQ1AveragePercent(), 0.0001);
        $this->assertEqualsWithDelta(-20.0, $lower->revenueMomentumPeAdjustmentPercent(), 0.0001);
        $this->assertEqualsWithDelta(16.4, $lower->reasonablePeRatio(), 0.0001);

        $this->assertNull($notPublished->latestMonthlyRevenueVsQ1AveragePercent());
        $this->assertNull($notPublished->revenueMomentumPeAdjustmentPercent());
        $this->assertEqualsWithDelta(20.5, $notPublished->reasonablePeRatio(), 0.0001);
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

        $expectedPriceResponse = $this->get(route('tw-stock.q1-financial-reports.index', [
            'sort' => 'expected_price',
            'direction' => 'desc',
            'per_page' => 50,
        ]));

        $expectedPriceResponse->assertOk();
        $this->assertTableOrder($expectedPriceResponse->getContent(), ['8261', '9951', '5289']);
        $expectedPriceResponse->assertSee('sort=expected_price', false)
            ->assertSee('direction=asc', false);

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
                9.17,
                8.28,
                'TPEx',
            ),
            $this->annualComparisonRows(
                '2451',
                '創見',
                [2020 => 100, 2021 => 105, 2022 => 110, 2023 => 115, 2024 => 100, 2025 => 120, 2026 => 22],
                [2020 => 2.0, 2021 => 2.2, 2022 => 2.4, 2023 => 2.6, 2024 => 2.8, 2025 => 3.1, 2026 => 0.8],
                18.5,
                true,
                9.2,
                4.9,
            ),
            $this->annualComparisonRows(
                '3034',
                '聯詠',
                [2020 => 100, 2021 => 106, 2022 => 112, 2023 => 118, 2024 => 100, 2025 => 122, 2026 => 23],
                [2020 => 2.0, 2021 => 2.3, 2022 => 2.6, 2023 => 2.9, 2024 => 3.1, 2025 => 3.4, 2026 => 0.9],
                19.5,
                true,
                4.9,
                9.2,
            ),
        ));

        $this->artisan('tw-stock:refresh-annual-financial-comparisons', [
            '--context-year' => 2026,
            '--start-year' => 2020,
            '--end-year' => 2025,
        ])->assertExitCode(0);

        $this->assertSame(4, DB::table('tw_stock_annual_financial_comparisons')->count());
        $topAnnual = DB::table('tw_stock_annual_financial_comparisons')->where('stock_code', '2408')->first();
        $this->assertEqualsWithDelta(173.61, (float) $topAnnual->revenue_yoy_sum, 0.01);
        $this->assertEqualsWithDelta(178.08, (float) $topAnnual->eps_yoy_sum, 0.01);
        $this->assertSame(1, (int) $topAnnual->revenue_filter_pass);
        $this->assertSame(1, (int) $topAnnual->eps_filter_pass);

        $latestDate = CarbonImmutable::create(2026, 5, 8);
        $dailyRows = [];
        for ($index = 0; $index < 50; $index++) {
            $date = $latestDate->subDays($index)->toDateString();
            $dailyRows[] = $this->dailyPriceRow(
                '2408',
                '南亞科',
                $date,
                $index === 1 ? 132.0 : 118.0 - min($index, 20),
            );
            $dailyRows[] = $this->dailyPriceRow(
                '8261',
                '富鼎',
                $date,
                $index === 8 ? 132.0 : 118.0 - min($index, 20),
                'TPEx',
            );
        }
        DB::table('tw_stock_daily_prices')->insert($dailyRows);
        DB::table('tw_stock_company_profiles')->insert([
            [
                'exchange' => 'TWSE',
                'stock_code' => '2408',
                'stock_name' => '南亞科',
                'industry' => '半導體業',
                'industry_code' => null,
                'valuation_group' => '記憶體/儲存',
                'valuation_group_pe' => 38.0,
                'source_date' => '2026-05-08',
                'source_payload' => null,
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'exchange' => 'TPEx',
                'stock_code' => '8261',
                'stock_name' => '富鼎',
                'industry' => '半導體業',
                'industry_code' => null,
                'valuation_group' => 'IC設計',
                'valuation_group_pe' => 45.0,
                'source_date' => '2026-05-08',
                'source_payload' => null,
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'exchange' => 'TWSE',
                'stock_code' => '2451',
                'stock_name' => '創見',
                'industry' => '半導體業',
                'industry_code' => null,
                'valuation_group' => '記憶體/儲存',
                'valuation_group_pe' => 38.0,
                'source_date' => '2026-05-08',
                'source_payload' => null,
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'exchange' => 'TWSE',
                'stock_code' => '3034',
                'stock_name' => '聯詠',
                'industry' => '半導體業',
                'industry_code' => null,
                'valuation_group' => 'IC設計',
                'valuation_group_pe' => 45.0,
                'source_date' => '2026-05-08',
                'source_payload' => null,
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        DB::table('tw_stock_daily_turnover_rates')->insert($this->weeklyTurnoverRows([
            '2408' => ['exchange' => 'TWSE', 'name' => '南亞科', 'rates' => [2.5, 2.8, 2.1, 2.4, 2.7]],
            '8261' => ['exchange' => 'TPEx', 'name' => '富鼎', 'rates' => [0.7, 0.8, 0.6, 0.9, 0.7]],
            '3034' => ['exchange' => 'TWSE', 'name' => '聯詠', 'rates' => [1.2, 1.4, 1.3, 1.1, 1.2]],
        ]));

        $response = $this->get(route('tw-stock.annual-comparison.index'));

        $response->assertOk()
            ->assertSee('台股年度營收 EPS 比較')
            ->assertSee('營收加權排序')
            ->assertSee('EPS 加權排序')
            ->assertSee('營收 5 年 YoY 加權 &gt;', false)
            ->assertSee('name="revenue_growth_threshold" value="52"', false)
            ->assertSee('EPS 5 年 YoY 加權 &gt;', false)
            ->assertSee('name="eps_growth_threshold" value="34"', false)
            ->assertSee('2026 Q1 EPS YoY &gt;', false)
            ->assertSee('name="current_q1_eps_yoy_threshold" value="5"', false)
            ->assertSee('2025 年營收 YoY &gt;', false)
            ->assertSee('name="end_year_revenue_yoy_threshold" value="15"', false)
            ->assertSee('2026 Q1 營收 YoY &gt;', false)
            ->assertSee('name="current_q1_revenue_yoy_threshold" value="7"', false)
            ->assertSee('淨利率近 8 季或近 2 年平均 &gt;', false)
            ->assertSee('name="net_margin_threshold" value="15"', false)
            ->assertSee('一周平均周轉率 &gt;', false)
            ->assertSee('name="weekly_turnover_threshold" value="5"', false)
            ->assertSee('平均周轉率條件')
            ->assertSee('一周平均周轉')
            ->assertSee('5/25')
            ->assertSee('2.50%')
            ->assertDontSee('一周每日周轉率')
            ->assertSee('近 3 日創兩月新高')
            ->assertSee('近 3 日新高')
            ->assertDontSee('每年 EPS YoY 均為正')
            ->assertDontSee('Q1 EPS YoY WAIT')
            ->assertDontSee('2025 營收 WAIT')
            ->assertDontSee('Q1 營收 YoY WAIT')
            ->assertSee('淨利率 PASS')
            ->assertDontSee('淨利率 WAIT')
            ->assertSee('name="sort" value="eps"', false)
            ->assertSee('value="50"', false)
            ->assertSee('value="100" selected', false)
            ->assertSee('value="200"', false)
            ->assertSee('value="500"', false)
            ->assertSee('data-copy-value="2408"', false)
            ->assertSee('font-size: 14px;', false)
            ->assertSee('tbody tr:nth-child(odd) td', false)
            ->assertSee('tbody tr:nth-child(even) td', false)
            ->assertSee('name="valuation_groups[]"', false)
            ->assertSee('全部族群')
            ->assertSee('族群')
            ->assertSee('記憶體/儲存')
            ->assertSee('38.0x')
            ->assertSee('exchange-badge exchange-badge--twse', false)
            ->assertSee('exchange-badge exchange-badge--tpex', false)
            ->assertSee('>市</span>', false)
            ->assertSee('>櫃</span>', false)
            ->assertDontSee('<span class="badge">TWSE</span>', false)
            ->assertDontSee('<span class="badge">TPEx</span>', false)
            ->assertSee('預期股價')
            ->assertSee('121.60')
            ->assertSee('2020 → 2021')
            ->assertSee('點一下複製');

        $html = $response->getContent();
        $highGrowthPosition = strpos($html, 'data-copy-value="2408"');
        $lowGrowthPosition = strpos($html, 'data-copy-value="8261"');

        $this->assertNotFalse($highGrowthPosition);
        $this->assertNotFalse($lowGrowthPosition);
        $this->assertLessThan($lowGrowthPosition, $highGrowthPosition);

        $groupFiltered = $this->get(route('tw-stock.annual-comparison.index', [
            'per_page' => 50,
            'valuation_groups' => ['IC設計'],
        ]));

        $groupFiltered->assertOk()
            ->assertSee('name="valuation_groups[]" value="IC設計" checked', false)
            ->assertSee('data-copy-value="8261"', false)
            ->assertSee('data-copy-value="3034"', false)
            ->assertDontSee('data-copy-value="2408"', false)
            ->assertDontSee('data-copy-value="2451"', false);

        $filtered = $this->get(route('tw-stock.annual-comparison.index', [
            'sort' => 'eps',
            'per_page' => 50,
            'current_q1_eps_yoy' => 1,
            'end_year_revenue_yoy' => 1,
            'current_q1_revenue_yoy' => 1,
            'current_q1_revenue_yoy_threshold' => 9,
            'recent_two_month_high' => 1,
        ]));

        $filtered->assertOk()
            ->assertSee('name="sort" value="eps"', false)
            ->assertSee('value="50" selected', false)
            ->assertSee('name="current_q1_eps_yoy" value="1" checked', false)
            ->assertSee('name="end_year_revenue_yoy" value="1" checked', false)
            ->assertSee('name="current_q1_revenue_yoy" value="1" checked', false)
            ->assertSee('name="current_q1_revenue_yoy_threshold" value="9"', false)
            ->assertSee('name="recent_two_month_high" value="1" checked', false)
            ->assertSee('data-copy-value="2408"', false)
            ->assertDontSee('data-copy-value="8261"', false)
            ->assertDontSee('data-copy-value="2451"', false)
            ->assertDontSee('data-copy-value="3034"', false);

        $turnoverFiltered = $this->get(route('tw-stock.annual-comparison.index', [
            'weekly_turnover' => 1,
            'weekly_turnover_threshold' => 2,
        ]));

        $turnoverFiltered->assertOk()
            ->assertSee('name="weekly_turnover" value="1" checked', false)
            ->assertSee('name="weekly_turnover_threshold" value="2"', false)
            ->assertSee('data-copy-value="2408"', false)
            ->assertDontSee('data-copy-value="8261"', false)
            ->assertDontSee('data-copy-value="2451"', false)
            ->assertDontSee('data-copy-value="3034"', false);
    }

    public function test_annual_comparison_refresh_uses_latest_daily_price_snapshot(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert($this->annualComparisonRows(
            '2408',
            '南亞科',
            [2020 => 100, 2021 => 125, 2022 => 150, 2023 => 180, 2024 => 225, 2025 => 280, 2026 => 90],
            [2020 => 1.0, 2021 => 1.3, 2022 => 1.6, 2023 => 2.0, 2024 => 2.4, 2025 => 3.0, 2026 => 0.8],
            22.5,
            true,
        ));
        DB::table('tw_stock_daily_prices')->insert([
            $this->dailyPriceRow('2408', '南亞科', '2026-05-28', 320.0),
            array_merge($this->dailyPriceRow('2408', '南亞科', '2026-05-29', 348.0), [
                'volume_lots' => 4567,
            ]),
        ]);

        $this->artisan('tw-stock:refresh-annual-financial-comparisons', [
            '--context-year' => 2026,
            '--start-year' => 2020,
            '--end-year' => 2025,
        ])->assertExitCode(0);

        $row = DB::table('tw_stock_annual_financial_comparisons')->where('stock_code', '2408')->first();

        $this->assertEqualsWithDelta(347.0, (float) $row->latest_close_price, 0.0001);
        $this->assertSame(4567, (int) $row->volume_lots);
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

        $this->assertSame(4, DB::table('tw_stock_q1_financial_reports')->count());

        $tsmc = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '2330')->first();
        $this->assertNotNull($tsmc);
        $this->assertSame('台積電', $tsmc->stock_name);
        $this->assertEqualsWithDelta(22.08, (float) $tsmc->q1_eps, 0.0001);
        $this->assertEqualsWithDelta(11341.0, (float) $tsmc->q1_revenue_billion, 0.0001);
        $this->assertEqualsWithDelta(66.2, (float) $tsmc->q1_gross_margin_percent, 0.0001);
        $this->assertEqualsWithDelta(58.1, (float) $tsmc->q1_operating_margin_percent, 0.0001);
        $this->assertEqualsWithDelta(50.5, (float) $tsmc->q1_net_margin_percent, 0.0001);
        $this->assertStringContainsString('MOPS ajax_t05st01', (string) $tsmc->source_payload);

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

        $lungteh = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '6753')->first();
        $this->assertNotNull($lungteh);
        $this->assertSame('龍德造船', $lungteh->stock_name);
        $this->assertEqualsWithDelta(2.70, (float) $lungteh->q1_eps, 0.0001);
        $this->assertEqualsWithDelta(13.2492, (float) $lungteh->q1_revenue_billion, 0.0001);
        $this->assertStringContainsString('MOPS ajax_t05st01', (string) $lungteh->source_payload);
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

    public function test_company_profile_refresh_stores_all_stock_industries_and_valuation_groups(): void
    {
        Http::fake(fn ($request) => $this->fakeResponse($request->url()));

        $this->artisan('tw-stock:refresh-company-profiles')->assertExitCode(0);

        $this->assertDatabaseHas('tw_stock_company_profiles', [
            'exchange' => 'TWSE',
            'stock_code' => '2330',
            'industry' => '半導體業',
            'valuation_group' => '半導體製造/設備/材料',
        ]);
        $this->assertDatabaseHas('tw_stock_company_profiles', [
            'exchange' => 'TWSE',
            'stock_code' => '1101',
            'industry' => '水泥工業',
            'valuation_group' => '原物料/傳產',
        ]);
        $this->assertDatabaseHas('tw_stock_company_profiles', [
            'exchange' => 'TPEx',
            'stock_code' => '9951',
            'industry' => '汽車工業',
            'valuation_group' => '汽車/電動車',
        ]);
        $this->assertDatabaseHas('tw_stock_company_profiles', [
            'exchange' => 'TPEx',
            'stock_code' => '5289',
            'industry' => '半導體業',
            'valuation_group' => '記憶體/儲存',
        ]);
    }

    public function test_valuation_groups_use_business_specific_overrides_before_official_industry(): void
    {
        $service = app(TwStockQ1ValuationService::class);

        $cases = [
            ['3293', '鈊象', '文化創意業', '遊戲/數位內容', 24.0],
            ['5284', 'jpp-KY', '其他業', '航太/國防', 30.0],
            ['2633', '台灣高鐵', '航運業', '交通運輸', 14.0],
            ['3138', '耀登', '通信網路業', '通信網路', 24.0],
            ['2337', '旺宏', '半導體業', '記憶體/儲存', 38.0],
            ['6197', '佳必琪', '電子零組件業', '高速傳輸/連接器', 32.0],
            ['6214', '精誠', '資訊服務業', '資訊服務/雲端', 20.0],
            ['2360', '致茂', '其他電子業', '電子設備/檢測', 27.0],
            ['3014', '聯陽', '半導體業', 'IC設計', 45.0],
            ['3008', '大立光', '光電業', '光學/鏡頭', 24.0],
            ['1503', '士電', '電機機械', '電線電纜/重電', 18.0],
            ['1605', '華新', '電器電纜', '電線電纜/重電', 18.0],
            ['6505', '台塑化', '油電燃氣業', '石化/油品', 12.0],
            ['9921', '巨大', '運動休閒業', '運動休閒/品牌消費', 16.0],
            ['2317', '鴻海', '其他電子業', '電子代工/EMS', 20.0],
            ['2736', '富野', '觀光餐旅', '食品/觀光/消費', 18.0],
            ['2401', '凌陽', '半導體業', 'IC設計', 45.0],
            ['3406', '玉晶光', '光電業', '光學/鏡頭', 24.0],
            ['1519', '華城', '電機機械', '電線電纜/重電', 18.0],
            ['2618', '長榮航', '航運業', '交通運輸', 14.0],
            ['2645', '長榮航太', '航運業', '航太/國防', 30.0],
            ['5871', '中租-KY', '其他業', '租賃/金融服務', 12.0],
            ['6184', '大豐電', '其他業', '通信網路', 24.0],
            ['9945', '潤泰新', '其他業', '營建資產', 13.0],
        ];

        foreach ($cases as [$stockCode, $stockName, $industry, $expectedGroup, $expectedPe]) {
            $valuation = $service->valuationForValues($stockCode, $stockName, $industry);

            $this->assertSame($expectedGroup, $valuation['valuation_group'], $stockCode);
            $this->assertEqualsWithDelta($expectedPe, $valuation['valuation_group_pe'], 0.0001, $stockCode);
        }
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

        $this->seedDailyPriceRows('8261', '富鼎', 126, -1.56, 120, 100, 4695);
        $this->seedDailyPriceRows('3054', '立萬利', 75.5, 2.30, 70, 62, 900);
        Http::fake(fn () => Http::response([], 404));

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
        $sourcePayload = json_decode((string) $row->source_payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('tw_stock_daily_prices (/tw-stock/daily-price-rankings)', $sourcePayload['daily_price_source']);
    }

    public function test_market_data_only_can_keep_rows_when_latest_market_data_is_not_eligible(): void
    {
        DB::table('tw_stock_q1_financial_reports')->insert($this->row([
            'stock_code' => '3054',
            'stock_name' => '立萬利',
            'latest_close_price' => 75.5,
            'latest_price_date' => '2026-05-07',
            'volume_lots' => 1457,
            'rank' => 1,
        ]));

        $this->seedDailyPriceRows('3054', '立萬利', 75.5, 2.30, 70, 62, 900);
        Http::fake(fn () => Http::response([], 404));

        $this->artisan('tw-stock:fetch-q1-financial-reports', [
            '--year' => 2026,
            '--quarter' => 1,
            '--market-data-only' => true,
            '--keep-missing-market-data' => true,
            '--min-volume-lots' => 1000,
            '--sleep-ms' => 0,
        ])->assertExitCode(0);

        $row = DB::table('tw_stock_q1_financial_reports')->where('stock_code', '3054')->first();

        $this->assertNotNull($row);
        $this->assertSame('2026-05-07', substr((string) $row->latest_price_date, 0, 10));
        $this->assertEqualsWithDelta(75.5, (float) $row->latest_close_price, 0.0001);
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

        if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/opendata/t187ap03_L')) {
            return Http::response([
                [
                    '出表日期' => '1150508',
                    '公司代號' => '1101',
                    '公司簡稱' => '台泥',
                    '產業別' => '01',
                ],
                [
                    '出表日期' => '1150508',
                    '公司代號' => '2330',
                    '公司簡稱' => '台積電',
                    '產業別' => '24',
                ],
                [
                    '出表日期' => '1150508',
                    '公司代號' => '8261',
                    '公司簡稱' => '富鼎',
                    '產業別' => '24',
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

        if (str_starts_with($url, 'https://www.tpex.org.tw/openapi/v1/mopsfin_t187ap03_O')) {
            return Http::response([
                [
                    'Date' => '1150508',
                    'SecuritiesCompanyCode' => '5289',
                    'CompanyAbbreviation' => '宜鼎',
                    'SecuritiesIndustryCode' => '24',
                ],
                [
                    'Date' => '1150508',
                    'SecuritiesCompanyCode' => '9951',
                    'CompanyAbbreviation' => '皇田',
                    'SecuritiesIndustryCode' => '12',
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

    private function seedDailyPriceRows(
        string $stockCode,
        string $stockName,
        float $latestClose,
        float $oneDayChange,
        float $fiveDayClose,
        float $twentyDayClose,
        int $volumeLots,
        string $exchange = 'TWSE',
    ): void {
        $rows = $this->dailyRows($latestClose, $oneDayChange, $fiveDayClose, $twentyDayClose, $volumeLots);
        $payloads = [];
        foreach ($rows as $index => $row) {
            $close = (float) $row['收盤價'];
            $previousClose = isset($rows[$index + 1]) ? (float) $rows[$index + 1]['收盤價'] : null;
            $changeAmount = $previousClose === null ? null : $close - $previousClose;
            $payloads[] = [
                'exchange' => $exchange,
                'stock_code' => $stockCode,
                'stock_name' => $stockName,
                'trade_date' => CarbonImmutable::createFromFormat('Ymd', (string) $row['交易日'])->toDateString(),
                'open_price' => $close,
                'high_price' => $close,
                'low_price' => $close,
                'close_price' => $close,
                'previous_close_price' => $previousClose,
                'price_change_amount' => $changeAmount,
                'price_change_percent' => $index === 0
                    ? $oneDayChange
                    : ($previousClose !== null && $previousClose > 0 ? (($close - $previousClose) / $previousClose) * 100 : null),
                'volume_lots' => $volumeLots,
                'volume_shares' => $volumeLots * 1000,
                'source' => 'test daily prices',
                'source_payload' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
                'fetched_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('tw_stock_daily_prices')->insert($payloads);
    }

    /**
     * @return array<string, mixed>
     */
    private function dailyPriceRow(string $stockCode, string $stockName, string $tradeDate, float $highPrice, string $exchange = 'TWSE'): array
    {
        return [
            'exchange' => $exchange,
            'stock_code' => $stockCode,
            'stock_name' => $stockName,
            'trade_date' => $tradeDate,
            'open_price' => $highPrice - 1,
            'high_price' => $highPrice,
            'low_price' => $highPrice - 2,
            'close_price' => $highPrice - 1,
            'previous_close_price' => $highPrice - 2,
            'price_change_amount' => 1,
            'price_change_percent' => 1,
            'volume_lots' => 1500,
            'volume_shares' => 1500000,
            'source' => 'test daily prices',
            'source_payload' => json_encode(['test' => true], JSON_THROW_ON_ERROR),
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
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
                    'Code' => '2330',
                    'Name' => '台積電',
                    'TradeVolume' => '31730000',
                    'ClosingPrice' => '2290.00',
                ],
                [
                    'Date' => '1150508',
                    'Code' => '2363',
                    'Name' => '矽統',
                    'TradeVolume' => '2000000',
                    'ClosingPrice' => '20.00',
                ],
                [
                    'Date' => '1150508',
                    'Code' => '6753',
                    'Name' => '龍德造船',
                    'TradeVolume' => '3707000',
                    'ClosingPrice' => '145.00',
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
                        '2330' => [[
                            '年季' => '202501',
                            '公告基本每股盈餘(元)' => '13.94',
                            '季營收(億)' => '8392.54',
                        ]],
                        '6753' => [[
                            '年季' => '202501',
                            '公告基本每股盈餘(元)' => '1.00',
                            '季營收(億)' => '8.00',
                        ]],
                        default => [],
                    },
                ],
            ]]);
        }

        if (str_starts_with($url, 'https://mopsov.twse.com.tw/mops/web/ajax_t05st01')) {
            if (($data['step'] ?? null) === '1' && ($data['queryName'] ?? null) === 'all') {
                if (($data['month'] ?? null) === '04' && ($data['b_date'] ?? null) === '16') {
                    return Http::response($this->mopsListHtml(
                        '2330',
                        '台積電',
                        '115/04/16',
                        '15:38:13',
                        '台積公司2026年第一季每股盈餘新台幣22.08元',
                        '20260416',
                        '153813',
                        '3',
                        'sii',
                    ));
                }

                if (($data['month'] ?? null) === '04' && ($data['b_date'] ?? null) === '27') {
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

                if (($data['month'] ?? null) === '05' && ($data['b_date'] ?? null) === '08') {
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
                    ) . $this->mopsListHtml(
                        '6753',
                        '龍德造船',
                        '115/05/08',
                        '17:39:01',
                        '公告本公司董事會通過115年第一季財務報告',
                        '20260508',
                        '173901',
                        '1',
                        'sii',
                    ));
                }

                return Http::response('<html><body><table></table></body></html>');
            }

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

            if (($data['step'] ?? null) === '2' && ($data['co_id'] ?? null) === '2330') {
                return Http::response(<<<'HTML'
<html><body>
台積公司2026年第一季合併營收新台幣1兆1,341億元，稅後純益新台幣5,729億元，每股盈餘新台幣22.08元。
毛利率為66.2%，營業利益率為58.1%，稅後純益率為50.5%。
</body></html>
HTML);
            }

            if (($data['step'] ?? null) === '2' && ($data['co_id'] ?? null) === '6753') {
                return Http::response($this->mopsCurrencyDetailHtml([
                    'revenue' => '1,324,923',
                    'gross_profit' => '438,584',
                    'operating_profit' => '383,922',
                    'net_income' => '317,126',
                    'parent_net_income' => '317,126',
                    'eps' => '2.70',
                    'assets' => '10,821,486',
                    'parent_equity' => '5,014,378',
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

    /**
     * @param array<string, string> $values
     */
    private function mopsCurrencyDetailHtml(array $values): string
    {
        return <<<HTML
<html><body>
<p>3.財務報告或年度自結財務資訊報導期間起訖日期(XXX/XX/XX~XXX/XX/XX):115/01/01~115/03/31</p>
<p>4.1月1日累計至本期止營業收入(仟元):新台幣{$values['revenue']}仟元</p>
<p>5.1月1日累計至本期止營業毛利(毛損) (仟元):新台幣{$values['gross_profit']}仟元</p>
<p>6.1月1日累計至本期止營業利益(損失) (仟元):新台幣{$values['operating_profit']}仟元</p>
<p>8.1月1日累計至本期止本期淨利(淨損) (仟元):新台幣{$values['net_income']}仟元</p>
<p>9.1月1日累計至本期止歸屬於母公司業主淨利(損) (仟元):新台幣{$values['parent_net_income']}仟元</p>
<p>10.1月1日累計至本期止基本每股盈餘(損失) (元):新台幣{$values['eps']}元</p>
<p>11.期末總資產(仟元):新台幣{$values['assets']}仟元</p>
<p>13.期末歸屬於母公司業主之權益(仟元):新台幣{$values['parent_equity']}仟元</p>
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
        float $currentQ1RevenueYoyPercent = 9.17,
        float $currentQ1EpsYoyPercent = 8.28,
        string $exchange = 'TWSE',
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
                'exchange' => $exchange,
                'stock_code' => $stockCode,
                'stock_name' => $stockName,
                'q1_eps' => $eps,
                'q1_revenue_yoy_percent' => $currentQ1RevenueYoyPercent,
                'q1_eps_yoy_percent' => $currentQ1EpsYoyPercent,
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
                        'exchange' => $exchange,
                        'stock_code' => $stockCode,
                        'stock_name' => $stockName,
                        'q1_eps' => 0,
                        'q1_revenue_yoy_percent' => $currentQ1RevenueYoyPercent,
                        'q1_eps_yoy_percent' => $currentQ1EpsYoyPercent,
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
            'industry' => '半導體業',
            'valuation_group' => 'IC設計',
            'valuation_group_pe' => 45.0,
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
            $table->unsignedTinyInteger('quarter')->default(1);
            $table->string('financial_period', 8);
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->string('valuation_group', 32)->nullable();
            $table->decimal('valuation_group_pe', 8, 4)->nullable();
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
            $table->unique(['exchange', 'stock_code']);
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
            $table->decimal('current_q1_eps_yoy_percent', 10, 4)->nullable();
            $table->decimal('current_q1_revenue_yoy_percent', 10, 4)->nullable();
            $table->decimal('end_year_revenue_yoy_percent', 10, 4)->nullable();
            $table->decimal('latest_close_price', 12, 4)->nullable();
            $table->unsignedInteger('volume_lots')->nullable();
            $table->json('comparisons');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * @param array<string, array{exchange: string, name: string, rates: list<float>}> $stocks
     * @return list<array<string, mixed>>
     */
    private function weeklyTurnoverRows(array $stocks): array
    {
        $dates = ['2026-05-25', '2026-05-26', '2026-05-27', '2026-05-28', '2026-05-29'];
        $now = now();
        $rows = [];
        foreach ($stocks as $stockCode => $stock) {
            foreach ($dates as $index => $date) {
                $rate = $stock['rates'][$index];
                $rows[] = [
                    'exchange' => $stock['exchange'],
                    'stock_code' => $stockCode,
                    'stock_name' => $stock['name'],
                    'trade_date' => $date,
                    'rank' => null,
                    'trading_shares' => (int) round($rate * 10000),
                    'issued_shares' => 1000000,
                    'turnover_rate_percent' => $rate,
                    'source' => 'test',
                    'source_payload' => null,
                    'fetched_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }
}
