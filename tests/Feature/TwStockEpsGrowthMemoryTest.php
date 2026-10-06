<?php

namespace Tests\Feature;

use App\Http\Controllers\TwStockEpsGrowthRankingController;
use App\Services\TwStockEpsGrowthRankingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TwStockEpsGrowthMemoryTest extends TestCase
{
    public function test_history_queries_exclude_large_audits_and_load_only_the_selected_audit(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('tw_stock_eps_growth_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('snapshot_date');
            $table->date('price_date')->nullable();
            foreach (['base_year', 'forecast_year_1', 'forecast_year_2', 'forecast_year_3',
                'article_count', 'forecast_count', 'eligible_count', 'top_count'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->timestamp('completed_at');
            $table->json('forecast_audit');
        });
        Schema::create('tw_stock_eps_growth_rankings', function (Blueprint $table): void {
            $table->id();
            $table->integer('run_id');
            $table->integer('rank');
        });
        Schema::create('tw_stock_order_loss_risks', function (Blueprint $table): void {
            $table->string('stock_code');
        });

        $oldAudit = [['stock_code' => '1111', 'stock_name' => 'Old stock', 'source_excerpt' => str_repeat('a', 1024 * 1024)]];
        $newAudit = [['stock_code' => '2222', 'stock_name' => 'Latest stock', 'source_excerpt' => str_repeat('b', 1024 * 1024)]];
        foreach (['2026-09-28' => $oldAudit, '2026-10-05' => $newAudit] as $date => $audit) {
            DB::table('tw_stock_eps_growth_runs')->insert([
                'snapshot_date' => $date,
                'completed_at' => $date . ' 12:00:00',
                'forecast_audit' => json_encode($audit),
            ]);
        }

        DB::enableQueryLog();
        $controller = app(TwStockEpsGrowthRankingController::class);
        $data = $controller->index(Request::create('/tw-stock/eps-growth-rankings'))->getData();
        $this->assertSame($newAudit, $data['run']->forecast_audit);
        $this->assertSame('2026-09-28', $data['previousRun']->snapshot_date->toDateString());
        $this->assertNull($data['availableRuns']->last()->forecast_audit);
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'tw_stock_eps_growth_runs')
                && str_contains($query['query'], 'order by')) {
                $this->assertStringNotContainsString('forecast_audit', $query['query']);
                $this->assertStringNotContainsString('select *', $query['query']);
            }
        }
        $olderData = $controller->index(Request::create('/tw-stock/eps-growth-rankings', 'GET', ['run' => 1]))->getData();
        $this->assertSame($oldAudit, $olderData['run']->forecast_audit);
        $this->assertFalse($olderData['usesLatestPrices']);

        config()->set('tw_stock.eps_growth_ranking.manual_neutral_forecasts', []);
        config()->set('tw_stock_eps_supplemental.stocks', []);
        Http::fake([
            '*newslist*' => Http::response(['items' => ['data' => []]]),
            '*estimateProfit*' => Http::response(['statusCode' => 200, 'data' => []]),
        ]);
        DB::flushQueryLog();
        $service = app(TwStockEpsGrowthRankingService::class);
        $forecasts = (new \ReflectionMethod($service, 'fetchLatestForecasts'))
            ->invoke($service, CarbonImmutable::parse('2026-10-06'), 35);
        $this->assertArrayHasKey('2222', $forecasts['forecasts']);
        $this->assertArrayNotHasKey('1111', $forecasts['forecasts']);
        (new \ReflectionMethod($service, 'previousRanks'))
            ->invoke($service, CarbonImmutable::parse('2026-10-06'));
        foreach (DB::getQueryLog() as $query) {
            if (str_contains($query['query'], 'tw_stock_eps_growth_runs')
                && str_contains($query['query'], 'order by')) {
                $this->assertStringNotContainsString('forecast_audit', $query['query']);
                $this->assertStringNotContainsString('select *', $query['query']);
            }
        }
        DB::disableQueryLog();
        DB::disconnect('sqlite');
    }
}
