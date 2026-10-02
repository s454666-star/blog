<?php

namespace Tests\Feature;

use App\Services\TwStockEpsForecastSource;
use App\Services\TwStockSupplementalEpsReviewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwStockSupplementalEpsReviewTest extends TestCase
{
    public function test_industry_model_is_distinct_from_consensus_and_discloses_sensitivity(): void
    {
        // Synthetic display fixture; the production model is maintained separately with researched inputs.
        $html = view('tw-stock.partials.eps-forecast-provenance', ['source' => [
            'source_type' => 'site_neutral', 'estimate_kind' => 'industry_model',
            'source_label' => '本站模型估算', 'source_url' => 'https://example.test/industry',
            'source_date' => '2026-10-02', 'date_type' => 'calculation_date',
            'analyst_count' => null, 'status' => 'current', 'method' => '測試計算方法',
            'sensitivity' => [['label' => '保守', 'eps' => 16, 'assumption' => '測試低成長'],
                ['label' => '樂觀', 'eps' => 24, 'assumption' => '測試高成長']],
        ]])->render();
        $this->assertStringContainsString('本站模型估算，非機構或 FactSet 預測', $html);
        $this->assertStringContainsString('推估日 2026-10-02', $html);
        $this->assertStringContainsString('EPS 16.00 元', $html);
        $this->assertStringContainsString('EPS 24.00 元', $html);
        $this->assertStringContainsString('非機率或信賴區間', $html);
        $this->assertStringNotContainsString('位分析師', $html);
    }

    public function test_new_research_capital_events_and_quarterly_eps_are_queued_without_inventing_estimates(): void
    {
        Http::fake([
            '*news/keyword*' => Http::response(['data' => ['items' => [
                ['title' => '尖點：現金增資收足股款公告', 'publishAt' => CarbonImmutable::parse('2026-10-05')->timestamp, 'newsId' => 42],
                ['title' => '其他公司：EPS 預估', 'publishAt' => CarbonImmutable::parse('2026-10-05')->timestamp, 'newsId' => 43],
            ]]]),
            '*finmind*' => Http::response(['data' => [['type' => 'EPS', 'value' => 2.3, 'date' => '2026-09-30']]]),
            '*topoint.tw*' => Http::response('<main>new financial information</main>'),
        ]);
        $reference = config('tw_stock_eps_supplemental.stocks.8021');
        $result = app(TwStockSupplementalEpsReviewService::class)->review('8021', $reference, CarbonImmutable::parse('2026-10-05'));
        $this->assertSame('needs_review', $result['status']);
        $this->assertCount(2, $result['candidates']);
        $this->assertSame('financial_period', $result['candidates'][1]['date_type']);
        $this->assertArrayNotHasKey('eps_2028', $result);
    }

    public function test_failed_public_sources_are_visible_and_do_not_claim_success(): void
    {
        Http::fake(['*' => Http::response([], 503)]);
        $result = app(TwStockSupplementalEpsReviewService::class)->review('8021', config('tw_stock_eps_supplemental.stocks.8021'), CarbonImmutable::parse('2026-10-05'));
        $this->assertSame('partial', $result['status']);
        $this->assertSame(['failed', 'failed', 'failed'], array_column($result['checks'], 'status'));
    }

    public function test_real_reference_policy_retains_research_and_model_next_week(): void
    {
        config()->set('tw_stock.eps_growth_ranking.manual_neutral_forecasts', []);
        Http::fake([
            '*estimateProfit*' => Http::response(['statusCode' => 200, 'data' => [
                ['code' => '8021', 'financialYear' => 2026, 'feMedian' => 8.54, 'rateDate' => '2026-09-25', 'numEst' => 1, 'currency' => 'TWD'],
                ['code' => '8021', 'financialYear' => 2027, 'feMedian' => 21, 'rateDate' => '2026-08-22', 'numEst' => 1, 'currency' => 'TWD'],
            ]]),
            '*news/keyword*' => Http::response(['data' => ['items' => []]]),
            '*finmind*' => Http::response(['data' => []]),
            '*topoint.tw*' => Http::response('<main>unchanged financial information</main>'),
        ]);
        foreach (['2026-10-02', '2026-10-05'] as $date) {
            $row = app(TwStockEpsForecastSource::class)->latest([], [], CarbonImmutable::parse($date))['8021'];
            $this->assertSame(8.8, $row['eps_2026']);
            $this->assertSame(16.8, $row['eps_2027']);
            $this->assertSame(22.0, $row['eps_2028']);
            $this->assertEqualsWithDelta(22.1466, $row['forecast_metadata'][2028]['unrounded_value'], 0.0001);
            $this->assertSame([17.0, 22.0, 27.0], array_column($row['forecast_metadata'][2028]['sensitivity'], 'eps'));
            $this->assertSame('2026-10-02', $row['forecast_metadata'][2028]['source_date']);
            $this->assertSame('industry_model', $row['forecast_metadata'][2028]['estimate_kind']);
            $this->assertSame('2026-08-18', $row['forecast_metadata'][2026]['source_date']);
            $this->assertSame(8.83, $row['forecast_metadata'][2026]['reported_eps']);
            $this->assertSame(8.54, $row['forecast_metadata'][2026]['alternate_source']['value']);
            $this->assertSame('current', $row['forecast_metadata'][2028]['status']);
            $this->assertSame('unchanged', $row['source_review']['status']);
        }
    }
}
