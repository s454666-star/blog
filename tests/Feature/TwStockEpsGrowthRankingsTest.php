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
        config()->set('tw_stock.eps_growth_ranking.cnyes_url', 'https://example.test/cnyes');
        config()->set('tw_stock.eps_growth_ranking.finmind_url', 'https://example.test/finmind');

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
        $this->assertEqualsWithDelta(66.9811, (float) $firstPlace->weighted_score, 0.001);
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

        $this->get(route('tw-stock.eps-growth-rankings.index'))
            ->assertOk()
            ->assertSee('EPS 三年成長')
            ->assertSee('歷史週快照')
            ->assertSee('營收成長預估')
            ->assertSee('加權分')
            ->assertSee('66.98')
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

        $this->get(route('tw-stock.eps-growth-rankings.index', ['run' => $oldRunId]))
            ->assertOk()
            ->assertSee('2026/08/11')
            ->assertSee('1111')
            ->assertSee('200.0%');
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
        $this->assertEqualsWithDelta(66.9811, (float) $oldFirst->weighted_score, 0.001);
        $this->assertSame('2222', $newFirst->stock_code);
        $this->assertSame(2, (int) $newFirst->previous_rank);
        $this->assertSame(1, (int) $newFirst->rank_change);
    }

    public function test_refresh_includes_requested_neutral_estimates_and_labels_them(): void
    {
        $this->includeNeutralEstimates = true;
        $this->insertPrices('2026-08-11', 100, 200);
        $this->insertNeutralPrices('2026-08-11');

        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 4,
        ])->assertSuccessful();

        $run = DB::table('tw_stock_eps_growth_runs')->first();
        $this->assertSame(4, (int) $run->forecast_count);
        $this->assertSame(4, (int) $run->eligible_count);

        $fulltech = DB::table('tw_stock_eps_growth_rankings')->where('stock_code', '2455')->first();
        $landmark = DB::table('tw_stock_eps_growth_rankings')->where('stock_code', '3081')->first();
        $this->assertNotNull($fulltech);
        $this->assertNotNull($landmark);
        $this->assertSame(1, (int) $fulltech->is_neutral_estimate);
        $this->assertSame(1, (int) $landmark->is_neutral_estimate);
        $this->assertEqualsWithDelta(8.2626, (float) $fulltech->eps_2028, 0.001);
        $this->assertEqualsWithDelta(15.2433, (float) $landmark->eps_2028, 0.001);

        DB::table('tw_stock_eps_growth_rankings')->where('stock_code', '2455')->update(['rank' => 51]);
        DB::table('tw_stock_eps_growth_rankings')->where('stock_code', '3081')->update(['rank' => 52]);

        $this->get(route('tw-stock.eps-growth-rankings.index'))
            ->assertOk()
            ->assertSee('全新')
            ->assertSee('聯亞')
            ->assertSee('中性估算')
            ->assertSee('全新、聯亞的 2028E 參考估算');
    }

    public function test_backfill_adds_neutral_estimates_without_replacing_snapshots_and_is_idempotent(): void
    {
        $this->insertPrices('2026-08-11', 100, 200);
        $this->insertNeutralPrices('2026-08-11');
        $this->artisan('tw-stock:refresh-eps-growth-rankings', [
            '--date' => '2026-08-11',
            '--lookback-days' => 35,
            '--sleep-ms' => 0,
            '--minimum-eligible' => 2,
        ])->assertSuccessful();
        $runId = (int) DB::table('tw_stock_eps_growth_runs')->value('id');

        $this->includeNeutralEstimates = true;
        $this->artisan('tw-stock:backfill-neutral-eps-growth-estimates', ['--sleep-ms' => 0])
            ->assertSuccessful();
        $this->artisan('tw-stock:backfill-neutral-eps-growth-estimates', ['--sleep-ms' => 0])
            ->assertSuccessful();

        $this->assertSame(1, DB::table('tw_stock_eps_growth_runs')->count());
        $this->assertSame($runId, (int) DB::table('tw_stock_eps_growth_runs')->value('id'));
        $this->assertSame(4, DB::table('tw_stock_eps_growth_rankings')->count());
        $this->assertSame(2, DB::table('tw_stock_eps_growth_rankings')->where('is_neutral_estimate', true)->count());
        $run = DB::table('tw_stock_eps_growth_runs')->first();
        $this->assertSame(4, (int) $run->forecast_count);
        $this->assertSame(4, (int) $run->eligible_count);
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
            'publishAt' => 1786467600 + $this->forecastPhase,
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
            'publishAt' => 1786467600 + $this->forecastPhase,
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

        Schema::connection('sqlite')->create('tw_stock_eps_growth_runs', function (Blueprint $table): void {
            $table->id();
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
