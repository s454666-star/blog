<?php

namespace Tests\Feature;

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
        $this->assertCount(4, $recentMonthlyRevenues);
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
        ]);

        $priceResponse = $this->get(route('tw-stock.q1-financial-reports.index', [
            'sort' => 'price',
            'direction' => 'asc',
            'per_page' => 50,
        ]));

        $priceResponse->assertOk();
        $this->assertTableOrder($priceResponse->getContent(), ['8261', '9951', '5289']);
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

    private function fakeResponse(string $url): mixed
    {
        if (str_starts_with($url, 'https://openapi.twse.com.tw/v1/exchangeReport/STOCK_DAY_ALL')) {
            return Http::response([
                [
                    'Date' => '1150508',
                    'Code' => '8261',
                    'Name' => '富鼎',
                    'TradeVolume' => '1500000',
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
        ], array_reverse($this->dailyRows(126, -1.56, 120, 100, 4695)));
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
        for ($index = 0; $index <= 20; $index++) {
            $rows[] = [
                '交易日' => sprintf('202604%02d', 30 - $index),
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
    }
}
