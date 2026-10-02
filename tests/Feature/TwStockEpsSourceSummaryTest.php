<?php

namespace Tests\Feature;

use Tests\TestCase;

class TwStockEpsSourceSummaryTest extends TestCase
{
    public function test_summary_displays_each_year_count_and_date_without_generic_placeholder(): void
    {
        $metadata = [
            2026 => ['analyst_count' => 6, 'source_date' => '2026-09-30'],
            2027 => ['analyst_count' => 4, 'source_date' => '2026-09-28'],
            2028 => ['analyst_count' => 2, 'source_date' => '2026-08-17'],
        ];
        $html = view('tw-stock.partials.eps-source-summary', ['metadata' => $metadata, 'column' => 'analysts'])->render();
        foreach ([6, 4, 2] as $count) { $this->assertStringContainsString($count.' 位', $html); }
        $this->assertStringNotContainsString('依年度', $html);
        $html = view('tw-stock.partials.eps-source-summary', ['metadata' => $metadata, 'column' => 'date'])->render();
        foreach ($metadata as $source) { $this->assertStringContainsString($source['source_date'], $html); }
    }

    public function test_news_supplement_does_not_assign_2026_count_to_2027_and_model_has_no_fake_count(): void
    {
        $news = ['source_type' => 'factset', 'news_id' => 6620280, 'analyst_count' => null];
        $metadata = [2026 => $news, 2027 => $news, 2028 => ['source_type' => 'site_neutral', 'estimate_kind' => 'industry_model']];
        $html = view('tw-stock.partials.eps-source-summary', ['metadata' => $metadata, 'column' => 'analysts'])->render();
        $this->assertStringContainsString('15 位', $html);
        $this->assertStringContainsString('2026 調查 15 位', $html);
        $this->assertStringContainsString('不能當作 2027 年人數', $html);
        $this->assertStringContainsString('本站模型', $html);
        $this->assertStringNotContainsString('來源未披露人數', $html);
    }

    public function test_newly_ranked_companies_have_sourced_qualitative_ratings(): void
    {
        foreach (['6643' => '低', '8021' => '中', '5347' => '中'] as $code => $level) {
            $source = config('tw_stock_order_loss_risk.stocks.'.$code);
            $this->assertSame($level, $source['level']);
            $this->assertNotEmpty($source['basis']);
            $this->assertStringStartsWith('https://', $source['source_url']);
        }
    }
}
