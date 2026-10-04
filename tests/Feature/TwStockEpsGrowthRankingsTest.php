<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockEpsGrowthRankingsTest extends TestCase
{
    private string $originalDatabaseDefault;

    private int $forecastPhase = 1;

    private bool $includeNeutralEstimates = false;

    private array $feedRows = [];

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
        config()->set('tw_stock.eps_growth_ranking.factset_eps_url', 'https://example.test/eps/{code}');
        config()->set('tw_stock.eps_growth_ranking.cnyes_url', 'https://example.test/cnyes');
        config()->set('tw_stock.eps_growth_ranking.finmind_url', 'https://example.test/finmind');
        config()->set('tw_stock.eps_growth_ranking.neutral_estimate_stock_codes', ['2455', '3081']);
        config()->set('tw_stock.eps_growth_ranking.manual_neutral_forecasts', []);
        config()->set('tw_stock_order_loss_risk.stocks', []);
        config()->set('tw_stock_eps_supplemental.stocks', []);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

        Carbon::setTestNow('2026-08-11 17:00:00');
        CarbonImmutable::setTestNow('2026-08-11 17:00:00');

        $this->createTables();
        $this->fakeForecastSources();
    }

    protected function tearDown(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            parent::tearDown();

            return;
        }

        Schema::connection('sqlite')->dropIfExists('tw_stock_eps_growth_rankings');
        Schema::connection('sqlite')->dropIfExists('tw_stock_eps_growth_runs');
        Schema::connection('sqlite')->dropIfExists('tw_stock_q1_financial_reports');
        Schema::connection('sqlite')->dropIfExists('tw_stock_daily_prices');

        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_command_keeps_weekly_snapshots_and_calculates_rank_changes(): void
    {
        $this->insertPrices('2026-08-11', 100, 200);

        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();

        $firstRun = DB::table('tw_stock_eps_growth_runs')->first();
        $this->assertNotNull($firstRun);
        $this->assertSame(2, (int) $firstRun->eligible_count);
        $this->assertSame(2, DB::table('tw_stock_eps_growth_rankings')->count());

        $firstPlace = DB::table('tw_stock_eps_growth_rankings')
            ->where('run_id', $firstRun->id)
            ->where('rank', 1)
            ->first();
        $this->assertSame('1111', $firstPlace->stock_code);
        $this->assertEqualsWithDelta(200, (float) $firstPlace->growth_sum, 0.001);
        $this->assertEqualsWithDelta(100, (float) $firstPlace->weighted_score, 0.001);
        $this->assertNull($firstPlace->rank_change);
        $this->assertEqualsWithDelta(100, (float) $firstPlace->close_price, 0.001);

        $this->forecastPhase = 2;
        $this->insertPrices('2026-08-18', 110, 220);

        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-18',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();

        $this->assertSame(2, DB::table('tw_stock_eps_growth_runs')->count());
        $this->assertSame(4, DB::table('tw_stock_eps_growth_rankings')->count());

        $secondRun = DB::table('tw_stock_eps_growth_runs')->orderByDesc('id')->first();
        $newFirst = DB::table('tw_stock_eps_growth_rankings')
            ->where('run_id', $secondRun->id)
            ->where('rank', 1)
            ->first();
        $newSecond = DB::table('tw_stock_eps_growth_rankings')
            ->where('run_id', $secondRun->id)
            ->where('rank', 2)
            ->first();

        $this->assertSame('2222', $newFirst->stock_code);
        $this->assertSame(2, (int) $newFirst->previous_rank);
        $this->assertSame(1, (int) $newFirst->rank_change);
        $this->assertSame('1111', $newSecond->stock_code);
        $this->assertSame(-1, (int) $newSecond->rank_change);
        $this->assertEqualsWithDelta(220, (float) $newFirst->close_price, 0.001);
    }

    public function test_page_defaults_to_latest_snapshot_and_can_switch_to_an_older_week(): void
    {
        DB::table('tw_stock_company_profiles')->insert([
            [
                'exchange' => 'TWSE',
                'stock_code' => '1111',
                'stock_name' => '甲公司',
                'industry' => '半導體業',
                'valuation_group' => '記憶體/儲存',
                'valuation_group_pe' => 38,
                'source_date' => '2026-08-18',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'exchange' => 'TWSE',
                'stock_code' => '2222',
                'stock_name' => '乙公司',
                'industry' => '電子零組件業',
                'valuation_group' => '電子零組件/PCB',
                'valuation_group_pe' => 2,
                'source_date' => '2026-08-18',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $this->insertPrices('2026-08-11', 100, 200);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();
        $oldRunId = (int) DB::table('tw_stock_eps_growth_runs')->value('id');

        $this->forecastPhase = 2;
        $this->insertPrices('2026-08-18', 110, 220);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-18',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();
        $this->insertMovingAverageHistory('2026-08-18');
        $this->insertPrices('2026-08-19', 120, 210);
        DB::table('tw_stock_q1_financial_reports')->insert([
            ['fiscal_year' => 2026, 'quarter' => 1, 'stock_code' => '1111', 'q1_eps' => 1.25],
            ['fiscal_year' => 2026, 'quarter' => 2, 'stock_code' => '1111', 'q1_eps' => 2.75],
            ['fiscal_year' => 2026, 'quarter' => 1, 'stock_code' => '2222', 'q1_eps' => 3.00],
            ['fiscal_year' => 2026, 'quarter' => 2, 'stock_code' => '2222', 'q1_eps' => 4.00],
        ]);

        $this->get(route('tw-stock.eps-growth-rankings.index'))
            ->assertOk()
            ->assertSee('EPS 三年成長')
            ->assertSee('歷史週快照')
            ->assertDontSee('營收成長預估')
            ->assertDontSee('三段合計')
            ->assertSee('掉單風險')
            ->assertSee('未提供評級')
            ->assertSee('2027預期價格')
            ->assertSee('最新收盤')
            ->assertSee('最新股價日')
            ->assertSee('2026/08/19')
            ->assertSee('預估2026')
            ->assertSee('實際2026（H1＋H1×1.05）')
            ->assertSee('族群平均本益比 × 2027E EPS')
            ->assertSee('目前 Q1+Q2：4.00')
            ->assertSee('目前 Q1+Q2：7.00')
            ->assertSee('456.0')
            ->assertSee('90.00')
            ->assertSee('（+280.0%）')
            ->assertSee('（-57.1%）')
            ->assertDontSee('潛在獲利')
            ->assertDontSee('潛在虧損')
            ->assertSee('（2027預期價格 ÷ 顯示收盤價 − 1）× 100%')
            ->assertSee('加權分')
            ->assertSee('100.00')
            ->assertSee('甲公司')
            ->assertSee('（記憶體/儲存）')
            ->assertSee('電子零組件/PCB')
            ->assertDontSee('冠軍 · #1')
            ->assertDontSee('亞軍 · #2')
            ->assertDontSee('季軍 · #3')
            ->assertSee('2026/08/18')
            ->assertSee('2026/08/11')
            ->assertSee('2222')
            ->assertSee('+1')
            ->assertSee('月線上')
            ->assertSee('季線上')
            ->assertSee('月線下')
            ->assertSee('季線下')
            ->assertSee('↑')
            ->assertSee('↓');

        $this->get(route('tw-stock.eps-growth-rankings.index', ['eps_basis' => 'actual']))
            ->assertOk()
            ->assertSee('2026實際推估')
            ->assertSee('相較預估')
            ->assertSee('（H1 4.00 ＋ H1×1.05）')
            ->assertSee('（H1 7.00 ＋ H1×1.05）')
            ->assertSee('8.20')
            ->assertSee('14.35')
            ->assertSee('+105.0%')
            ->assertSee('+43.5%')
            ->assertSee('+46.3%')
            ->assertSee('+50.0%')
            ->assertSee('實際2026')
            ->assertSee('H1 EPS ＋ H1 EPS × 1.05')
            ->assertSee('重新計算 25→26、26→27、加權分數及排行');

        $this->get(route('tw-stock.eps-growth-rankings.index', ['run' => $oldRunId]))
            ->assertOk()
            ->assertSee('2026/08/11')
            ->assertSee('當期收盤')
            ->assertSee('快照股價最晚日')
            ->assertSee('（+356.0%）')
            ->assertSee('（-82.0%）')
            ->assertSee('1111')
            ->assertSee('+100.0%');
    }

    public function test_fixed_order_loss_risk_survives_refresh_and_is_shared_by_both_modes_and_weeks(): void
    {
        config()->set('tw_stock_order_loss_risk.stocks', [
            '1111' => ['level' => '低', 'basis' => '測試用人工依據', 'source_url' => 'https://example.test/company', 'assessed_at' => '2026-10-01'],
        ]);
        foreach (['2026-08-11', '2026-08-18'] as $date) {
            $this->insertPrices($date, 100, 200);
            $this->artisan('tw-stock:refresh-eps-growth-rankings', [
                '--date' => $date, '--lookback-days' => 35,
                '--sleep-ms' => 0, '--minimum-eligible' => 2,
            ])->assertSuccessful();
        }
        $this->artisan('tw-stock:recalculate-eps-growth-rankings')->assertSuccessful();
        foreach (['1111', '2222'] as $code) {
            foreach ([1, 2] as $quarter) {
                DB::table('tw_stock_q1_financial_reports')->insert([
                    'fiscal_year' => 2026, 'quarter' => $quarter,
                    'stock_code' => $code, 'q1_eps' => 1,
                ]);
            }
        }
        foreach (DB::table('tw_stock_eps_growth_runs')->pluck('id') as $runId) {
            foreach (['forecast', 'actual'] as $basis) {
                $response = $this->get(route('tw-stock.eps-growth-rankings.index', [
                    'run' => $runId, 'eps_basis' => $basis,
                ]))->assertOk()->assertDontSee('三段合計')->assertDontSee('營收成長預估')
                    ->assertSee('不隨週更重評')->assertSee('測試用人工依據')
                    ->assertSee('人工固定分級，僅反映結構性替代風險，不代表近期訂單流失預測')
                    ->assertSee('https://example.test/company')->assertSee('2026-10-01');
                $response->assertSee('class="order-loss-risk" title="測試用人工依據">低', false);
                $this->assertSame(1, substr_count($response->getContent(), 'title="公開資料尚不足以判定替代風險">未提供評級'));
            }
        }

        foreach (['極高', '高', '中', '低', '極低'] as $level) {
            config()->set('tw_stock_order_loss_risk.stocks.1111.level', $level);
            $this->get(route('tw-stock.eps-growth-rankings.index'))->assertOk()
                ->assertSee('class="order-loss-risk" title="測試用人工依據">'.$level, false);
        }
        config()->set('tw_stock_order_loss_risk.stocks.1111.source_url', 'javascript:alert(1)');
        $this->get(route('tw-stock.eps-growth-rankings.index'))->assertOk()->assertDontSee('javascript:');
        foreach ([['level' => '未知', 'basis' => '有依據'], ['level' => '高'], ['level' => '低', 'basis' => '  ']] as $invalid) {
            config()->set('tw_stock_order_loss_risk.stocks.1111', $invalid);
            $response = $this->get(route('tw-stock.eps-growth-rankings.index'))->assertOk();
            $this->assertSame(2, substr_count($response->getContent(), 'title="公開資料尚不足以判定替代風險">未提供評級'));
        }
    }

    public function test_recalculate_command_reorders_existing_snapshots_without_replacing_them(): void
    {
        $this->insertPrices('2026-08-11', 100, 200);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();

        $this->forecastPhase = 2;
        $this->insertPrices('2026-08-18', 110, 220);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-18',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();

        $runIds = DB::table('tw_stock_eps_growth_runs')->orderBy('id')->pluck('id')->all();
        $snapshotDates = DB::table('tw_stock_eps_growth_runs')->orderBy('id')->pluck('snapshot_date')->all();
        DB::table('tw_stock_eps_growth_rankings')
            ->whereIn('run_id', $runIds)
            ->update([
                'rank' => 99,
                'previous_rank' => null,
                'rank_change' => null,
                'weighted_score' => null,
            ]);

        $this->artisan('tw-stock:recalculate-eps-growth-rankings')->assertSuccessful();

        $this->assertSame($runIds, DB::table('tw_stock_eps_growth_runs')->orderBy('id')->pluck('id')->all());
        $this->assertSame($snapshotDates, DB::table('tw_stock_eps_growth_runs')->orderBy('id')->pluck('snapshot_date')->all());
        $this->assertSame(4, DB::table('tw_stock_eps_growth_rankings')->count());

        $oldFirst = DB::table('tw_stock_eps_growth_rankings')
            ->where('run_id', $runIds[0])
            ->where('rank', 1)
            ->first();
        $newFirst = DB::table('tw_stock_eps_growth_rankings')
            ->where('run_id', $runIds[1])
            ->where('rank', 1)
            ->first();

        $this->assertSame('1111', $oldFirst->stock_code);
        $this->assertNull($oldFirst->previous_rank);
        $this->assertEqualsWithDelta(100, (float) $oldFirst->weighted_score, 0.001);
        $this->assertSame('2222', $newFirst->stock_code);
        $this->assertSame(2, (int) $newFirst->previous_rank);
        $this->assertSame(1, (int) $newFirst->rank_change);
    }

    public function test_incomplete_years_remain_visible_without_invented_2028_in_both_modes(): void
    {
        $this->includeNeutralEstimates = true;
        $this->insertPrices('2026-08-11', 100, 200);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11', '--lookback-days' => 35,
            '--sleep-ms' => 0, '--minimum-eligible' => 2,
        ])->assertSuccessful();
        $run = DB::table('tw_stock_eps_growth_runs')->first();
        $audit = collect(json_decode($run->forecast_audit, true))->keyBy('stock_code');
        $this->assertSame(4, (int) $run->forecast_count);
        $this->assertSame(2, (int) $run->eligible_count);
        $this->assertNull($audit['2455']['years'][2028]['value']);
        $this->assertSame('missing', $audit['3081']['years'][2028]['status']);
        $this->assertFalse($audit['2455']['rankable']);
        foreach (['forecast', 'actual'] as $basis) {
            $this->get(route('tw-stock.eps-growth-rankings.index', ['eps_basis' => $basis]))
                ->assertOk()->assertSee('全新')->assertSee('聯亞')
                ->assertSee('未取得此年度估值，不外推')->assertDontSee('8.2626');
        }
    }

    public function test_live_consensus_replaces_manual_forecast_and_labels_small_sample(): void
    {
        config()->set('tw_stock.eps_growth_ranking.manual_neutral_forecasts', [
            '3167' => ['stock_name' => '大量', 'forecast_date' => '2026-08-10',
                'eps_2026' => 19.53, 'eps_2027' => 30.59, 'source_label' => '單一研究',
                'source_url' => 'https://example.test/old'],
        ]);
        $this->feedRows['3167'] = array_map(fn ($year, $value) => [
            'code' => '3167', 'financialYear' => $year, 'feMedian' => $value,
            'feMean' => 999, 'rateDate' => '2026-08-09', 'numEst' => 1, 'currency' => 'TWD',
        ], [2026, 2027, 2028], [24.77, 45.4, 53.54]);
        $this->insertPrices('2026-08-11', 100, 200);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11', '--lookback-days' => 35, '--sleep-ms' => 0,
            '--minimum-eligible' => 3, '--allow-missing-top-prices' => true,
        ])->assertSuccessful();
        $row = DB::table('tw_stock_eps_growth_rankings')->where('stock_code', '3167')->first();
        $this->assertEquals(53.54, $row->eps_2028);
        $this->assertFalse((bool) $row->is_neutral_estimate);
        $this->get(route('tw-stock.eps-growth-rankings.index'))->assertOk()
            ->assertSee('小樣本')->assertSee('2026-08-09')->assertDontSee('https://example.test/old', false);
    }

    public function test_dry_run_never_writes_and_preserves_stale_values_as_stale(): void
    {
        $this->feedRows['1111'] = [['code' => '1111', 'financialYear' => 2028,
            'feMedian' => 18.0, 'rateDate' => '2026-01-01', 'numEst' => 1, 'currency' => 'TWD']];
        $result = app(\App\Services\TwStockEpsGrowthRankingService::class)->refresh(
            CarbonImmutable::parse('2026-08-11'), 35, 0, 1, false, true,
        );
        $audit = collect($result['audit'])->keyBy('stock_code');
        $this->assertSame('stale', $audit['1111']['years'][2028]['status']);
        $this->assertFalse($audit['1111']['rankable']);
        $this->assertSame(0, DB::table('tw_stock_eps_growth_runs')->count());
    }

    public function test_legacy_backfill_refuses_to_rewrite_historical_snapshots(): void
    {
        $this->insertPrices('2026-08-11', 100, 200);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11', '--lookback-days' => 35,
            '--sleep-ms' => 0, '--minimum-eligible' => 2,
        ])->assertSuccessful();
        $before = DB::table('tw_stock_eps_growth_rankings')->get()->toJson();
        $this->includeNeutralEstimates = true;
        $this->artisan('tw-stock:backfill-neutral-eps-growth-estimates', ['--sleep-ms' => 0])->assertFailed();
        $this->assertSame($before, DB::table('tw_stock_eps_growth_rankings')->get()->toJson());
    }

    public function test_supplemental_company_survives_next_week_and_factset_replaces_only_the_projected_year(): void
    {
        // Deliberately synthetic EPS values: verify the weekly integration, not investment assumptions.
        config()->set('tw_stock_eps_supplemental.stocks', ['8021' => [
            'stock_name' => '尖點', 'years' => [2028 => [
                'value' => 6, 'source_date' => '2026-10-02', 'date_type' => 'calculation_date',
                'source_type' => 'site_neutral', 'source_label' => '本站中性情境',
                'source_url' => 'https://example.test/topoint', 'method' => '測試情境：淨利除以稀釋股數',
                'assumptions' => ['測試股數假設'], 'uncertainty' => '不代表機構共識',
            ]],
        ]]);
        $this->feedRows['8021'] = array_map(fn ($year, $value) => [
            'code' => '8021', 'financialYear' => $year, 'feMedian' => $value,
            'rateDate' => '2026-09-25', 'numEst' => 1, 'currency' => 'TWD',
        ], [2026, 2027], [4, 5]);
        DB::table('tw_stock_q1_financial_reports')->insert([
            ['fiscal_year' => 2026, 'quarter' => 1, 'stock_code' => '8021', 'q1_eps' => 1],
            ['fiscal_year' => 2026, 'quarter' => 2, 'stock_code' => '8021', 'q1_eps' => 1],
        ]);
        foreach (['2026-10-02', '2026-10-05'] as $date) {
            $this->insertPrices($date, 100, 200);
            $this->artisan('tw-stock:refresh-eps-growth-rankings', [
                '--date' => $date, '--lookback-days' => 35, '--sleep-ms' => 0,
                '--minimum-eligible' => 3, '--allow-missing-top-prices' => true,
            ])->assertSuccessful();
        }
        $rows = \App\Models\TwStockEpsGrowthRanking::where('stock_code', '8021')->orderBy('run_id')->get();
        $this->assertCount(2, $rows);
        foreach ($rows as $row) {
            $this->assertEquals(4, $row->eps_2026);
            $this->assertEquals(6, $row->eps_2028);
            $this->assertTrue($row->is_neutral_estimate);
            $this->assertSame('2026-10-02', $row->forecast_metadata[2028]['source_date']);
            $this->assertSame('site_neutral', $row->forecast_metadata[2028]['source_type']);
            $this->assertNull($row->forecast_metadata[2028]['analyst_count']);
        }
        DB::table('tw_stock_eps_growth_rankings')->where('stock_code', '8021')->update(['rank' => 51]);
        foreach (['forecast', 'actual'] as $basis) {
            $this->get(route('tw-stock.eps-growth-rankings.index', ['eps_basis' => $basis]))
                ->assertOk()->assertSee('尖點')->assertSee('本站中性推估，非分析師共識')
                ->assertSee('方法、股數口徑與不確定性')->assertSee('測試股數假設')
                ->assertSee('6.00');
        }
        $this->feedRows['8021'][] = ['code' => '8021', 'financialYear' => 2028, 'feMedian' => 7,
            'rateDate' => '2026-10-05', 'numEst' => 2, 'currency' => 'TWD'];
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-10-12', '--lookback-days' => 35, '--sleep-ms' => 0,
            '--minimum-eligible' => 3, '--allow-missing-top-prices' => true,
        ])->assertSuccessful();
        $latest = \App\Models\TwStockEpsGrowthRanking::where('stock_code', '8021')->orderByDesc('run_id')->first();
        $this->assertEquals(7, $latest->eps_2028);
        $this->assertFalse($latest->is_neutral_estimate);
        $this->assertEquals(6, $rows[0]->fresh()->eps_2028);
    }

    public function test_topoint_is_visible_in_both_modes_when_2028_is_missing_and_no_rank_is_invented(): void
    {
        config()->set('tw_stock_eps_supplemental', require base_path('config/tw_stock_eps_supplemental.php'));
        $reference = config('tw_stock_eps_supplemental.stocks.8021');
        unset($reference['years'][2028]);
        config()->set('tw_stock_eps_supplemental.stocks.8021', $reference);
        $this->insertPrices('2026-10-02', 100, 200);
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-10-02', '--lookback-days' => 35, '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();
        $this->assertSame(0, DB::table('tw_stock_eps_growth_rankings')->where('stock_code', '8021')->count());
        foreach (['forecast', 'actual'] as $basis) {
            $this->get(route('tw-stock.eps-growth-rankings.index', ['eps_basis' => $basis]))
                ->assertOk()->assertDontSee('補充中性參考：尖點（8021）');
        }
    }

    public function test_topoint_industry_model_receives_normal_three_year_rank_in_both_modes_and_next_week(): void
    {
        config()->set('tw_stock_eps_supplemental', require base_path('config/tw_stock_eps_supplemental.php'));
        foreach (['1111' => [1, 3], '2222' => [3, 4], '8021' => [1.17, 2.08]] as $code => $eps) {
            foreach ($eps as $quarter => $value) {
                DB::table('tw_stock_q1_financial_reports')->insert(['fiscal_year' => 2026, 'quarter' => $quarter + 1, 'stock_code' => $code, 'q1_eps' => $value]);
            }
        }
        $oldRows = null;
        foreach (['2026-10-02', '2026-10-05'] as $date) {
            $this->insertPrices($date, 100, 200);
            $this->artisan('tw-stock:refresh-eps-growth-rankings', [
                '--date' => $date, '--lookback-days' => 35, '--sleep-ms' => 0,
                '--minimum-eligible' => 3, '--allow-missing-top-prices' => true,
            ])->assertSuccessful();
            $row = \App\Models\TwStockEpsGrowthRanking::where('stock_code', '8021')->orderByDesc('run_id')->first();
            $this->assertNotNull($row);
            $this->assertEquals(22, $row->eps_2028);
            $this->assertEqualsWithDelta((22 / 16.8 - 1) * 100, $row->growth_2027_2028, 0.0001);
            $this->assertSame(1, $row->rank);
            $this->assertEquals(100, $row->weighted_score);
            foreach (['forecast', 'actual'] as $basis) {
                $response = $this->get(route('tw-stock.eps-growth-rankings.index', ['eps_basis' => $basis]));
                $response->assertOk()->assertSee('本站模型估算，非機構或 FactSet 預測')->assertSee('非機率或信賴區間');
                $view = app(\App\Http\Controllers\TwStockEpsGrowthRankingController::class)->index(\Illuminate\Http\Request::create('/', 'GET', ['eps_basis' => $basis]));
                $rows = $view->getData()['rows'];
                $shown = $rows->firstWhere('stock_code', '8021');
                $this->assertNotNull($shown);
                $this->assertSame(1, $shown->rank);
                $this->assertEquals(100, $shown->weighted_score);
                $this->assertEqualsWithDelta($basis === 'actual' ? 6.6625 : 8.8, $shown->eps_2026, 0.0001);
                $expectedGrowth = ((16.8 / $shown->eps_2026) - 1) * 100;
                $this->assertEqualsWithDelta($expectedGrowth, $shown->growth_2026_2027, 0.0001);
            }
            $first = DB::table('tw_stock_eps_growth_rankings')->where('run_id', 1)->orderBy('id')->get()->toJson();
            if ($oldRows !== null) { $this->assertSame($oldRows, $first); }
            $oldRows = $first;
        }
    }

    public function test_incomplete_source_fails_closed_without_creating_a_snapshot(): void
    {
        $this->insertPrices('2026-08-11', 100, 200);

        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 3,
        ])->assertFailed();

        $this->assertSame(0, DB::table('tw_stock_eps_growth_runs')->count());
        $this->assertSame(0, DB::table('tw_stock_eps_growth_rankings')->count());
    }

    private function fakeForecastSources(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'ess.api.cnyes.com')) { return Http::response(['data' => ['items' => []]]); }
            if (str_contains($request->url(), 'www.topoint.tw/tw/finance/')) { return Http::response('<main>Test finance page</main>'); }
            if (str_starts_with($request->url(), 'https://example.test/eps/')) {
                $code = basename(parse_url($request->url(), PHP_URL_PATH));
                return Http::response(['statusCode' => 200, 'data' => $this->feedRows[$code] ?? []]);
            }
            if (str_starts_with($request->url(), 'https://example.test/cnyes')) {
                $secondForecast = $this->forecastPhase === 1
                    ? [12, 18, 27]
                    : [30, 45, 67.5];

                $articles = [
                    $this->forecastArticle(9001 + $this->forecastPhase, '1111', '甲公司', [8, 12, 18]),
                    $this->forecastArticle(9101 + $this->forecastPhase, '2222', '乙公司', $secondForecast),
                ];
                if ($this->includeNeutralEstimates) {
                    $articles[] = $this->neutralForecastArticle(9201, '2455', '全新', [2.95, 5.39, 7.12], [3426000, 4298000, 5115000]);
                    $articles[] = $this->neutralForecastArticle(9301, '3081', '聯亞', [4.16, 8.8, 12.56], [2176500, 3181500, 3924500]);
                }

                return Http::response([
                    'items' => [
                        'data' => $articles,
                        'last_page' => 1,
                    ],
                ]);
            }

            if (str_starts_with($request->url(), 'https://example.test/finmind')) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $annualEps = match ($query['data_id'] ?? '') {
                    '1111' => 4.0,
                    '2222' => 10.0,
                    '2455' => 2.96,
                    '3081' => 4.66,
                    '3167' => 8.13,
                    '8021' => 2.5,
                    '4971' => 1.61,
                    default => 0.0,
                };

                return Http::response([
                    'data' => collect(range(1, 4))->map(fn (int $quarter): array => [
                        'date' => sprintf('2025-%02d-28', $quarter * 3),
                        'type' => 'EPS',
                        'value' => $annualEps / 4,
                    ])->all(),
                ]);
            }

            return Http::response([], 404);
        });
    }

    /**
     * @param array{float|int, float|int, float|int} $eps
     * @return array<string, mixed>
     */
    private function forecastArticle(int $newsId, string $code, string $name, array $eps): array
    {
        $table = static fn (array $values): string => sprintf(
            '<table><tr><td>預估值</td><td>2026年</td><td>2027年</td><td>2028年</td></tr><tr><td>中位數</td><td>%s</td><td>%s</td><td>%s</td></tr></table>',
            $values[0],
            $values[1],
            $values[2],
        );

        return [
            'newsId' => $newsId,
            'publishAt' => 1786438800 + $this->forecastPhase,
            'title' => "鉅亨速報 - Factset 最新調查：{$name}({$code}-TW)EPS預估",
            'content' => '<p>共8位分析師</p>' . $table($eps) . $table([100000, 120000, 150000]),
        ];
    }

    /**
     * @param array{float|int, float|int, float|int} $eps
     * @param array{float|int, float|int, float|int} $revenue
     * @return array<string, mixed>
     */
    private function neutralForecastArticle(
        int $newsId,
        string $code,
        string $name,
        array $eps,
        array $revenue,
    ): array {
        $table = static fn (array $values): string => sprintf(
            '<table><tr><td>預估值</td><td>2025年</td><td>2026年</td><td>2027年</td></tr><tr><td>中位數</td><td>%s</td><td>%s</td><td>%s</td></tr></table>',
            $values[0],
            $values[1],
            $values[2],
        );

        return [
            'newsId' => $newsId,
            'publishAt' => 1786438800 + $this->forecastPhase,
            'title' => "鉅亨速報 - Factset 最新調查：{$name}({$code}-TW)EPS預估",
            'content' => '<p>共9位分析師</p>'.$table($eps).$table($revenue),
        ];
    }

    private function insertPrices(string $date, float $first, float $second): void
    {
        $now = now();
        DB::table('tw_stock_daily_prices')->insert([
            [
                'exchange' => 'TWSE',
                'stock_code' => '1111',
                'stock_name' => '甲公司',
                'trade_date' => $date,
                'close_price' => $first,
                'volume_lots' => 1,
                'volume_shares' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'exchange' => 'TWSE',
                'stock_code' => '2222',
                'stock_name' => '乙公司',
                'trade_date' => $date,
                'close_price' => $second,
                'volume_lots' => 1,
                'volume_shares' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function insertMovingAverageHistory(string $priceDate): void
    {
        $now = now();
        $rows = [];

        foreach (range(1, 60) as $daysAgo) {
            $date = CarbonImmutable::parse($priceDate)->subDays($daysAgo)->toDateString();
            foreach ([['1111', '甲公司', 90], ['2222', '乙公司', 230]] as [$code, $name, $close]) {
                $rows[] = [
                    'exchange' => 'TWSE',
                    'stock_code' => $code,
                    'stock_name' => $name,
                    'trade_date' => $date,
                    'close_price' => $close,
                    'volume_lots' => 1,
                    'volume_shares' => 1000,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::table('tw_stock_daily_prices')->insertOrIgnore($rows);
    }

    private function insertNeutralPrices(string $date): void
    {
        $now = now();
        DB::table('tw_stock_daily_prices')->insert([
            [
                'exchange' => 'TWSE',
                'stock_code' => '2455',
                'stock_name' => '全新',
                'trade_date' => $date,
                'close_price' => 419.5,
                'volume_lots' => 1,
                'volume_shares' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'exchange' => 'TPEx',
                'stock_code' => '3081',
                'stock_name' => '聯亞',
                'trade_date' => $date,
                'close_price' => 1000,
                'volume_lots' => 1,
                'volume_shares' => 1000,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    private function createTables(): void
    {
        Schema::connection('sqlite')->create('tw_stock_daily_prices', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 12);
            $table->string('stock_code', 12);
            $table->string('stock_name');
            $table->date('trade_date');
            $table->decimal('close_price', 12, 4);
            $table->unsignedBigInteger('volume_lots')->default(0);
            $table->unsignedBigInteger('volume_shares')->default(0);
            $table->timestamps();
            $table->unique(['exchange', 'stock_code', 'trade_date']);
        });

        Schema::connection('sqlite')->create('tw_stock_company_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('exchange', 16);
            $table->string('stock_code', 16);
            $table->string('stock_name');
            $table->string('industry')->nullable();
            $table->string('valuation_group')->nullable();
            $table->decimal('valuation_group_pe', 8, 4)->nullable();
            $table->date('source_date')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('tw_stock_q1_financial_reports', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('fiscal_year');
            $table->unsignedTinyInteger('quarter');
            $table->string('stock_code', 12);
            $table->decimal('q1_eps', 10, 4)->nullable();
            $table->unique(['fiscal_year', 'quarter', 'stock_code']);
        });

        Schema::connection('sqlite')->create('tw_stock_eps_growth_runs', function (Blueprint $table): void {
            $table->id();
            $table->json('forecast_audit')->nullable();
            $table->date('snapshot_date');
            $table->date('price_date')->nullable();
            $table->unsignedSmallInteger('base_year');
            $table->unsignedSmallInteger('forecast_year_1');
            $table->unsignedSmallInteger('forecast_year_2');
            $table->unsignedSmallInteger('forecast_year_3');
            $table->unsignedInteger('article_count')->default(0);
            $table->unsignedInteger('forecast_count')->default(0);
            $table->unsignedInteger('eligible_count')->default(0);
            $table->unsignedInteger('top_count')->default(0);
            $table->timestamp('completed_at');
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('tw_stock_eps_growth_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->unsignedSmallInteger('previous_rank')->nullable();
            $table->smallInteger('rank_change')->nullable();
            $table->string('stock_code', 12);
            $table->string('stock_name', 80);
            $table->json('forecast_metadata')->nullable();
            $table->decimal('eps_2025', 16, 4);
            $table->decimal('eps_2026', 16, 4);
            $table->decimal('eps_2027', 16, 4);
            $table->decimal('eps_2028', 16, 4);
            $table->decimal('growth_2025_2026', 14, 4);
            $table->decimal('growth_2026_2027', 14, 4);
            $table->decimal('growth_2027_2028', 14, 4);
            $table->decimal('growth_sum', 14, 4);
            $table->decimal('weighted_score', 7, 4)->nullable();
            $table->boolean('is_neutral_estimate')->default(false);
            $table->bigInteger('revenue_2026_thousands')->nullable();
            $table->bigInteger('revenue_2027_thousands')->nullable();
            $table->bigInteger('revenue_2028_thousands')->nullable();
            $table->date('price_date')->nullable();
            $table->decimal('close_price', 14, 4)->nullable();
            $table->unsignedSmallInteger('analyst_count')->nullable();
            $table->date('forecast_date')->nullable();
            $table->unsignedBigInteger('news_id')->nullable();
            $table->boolean('low_base')->default(false);
            $table->timestamps();
            $table->unique(['run_id', 'stock_code']);
        });
    }
}
