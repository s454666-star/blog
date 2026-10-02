<?php

namespace Tests\Unit;

use App\Services\TwStockEpsForecastSource;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class TwStockEpsForecastSourceTest extends TestCase
{
    public function test_feed_matches_exact_year_and_code_uses_median_and_retains_negative_and_null(): void
    {
        $source = new TwStockEpsForecastSource;
        $rows = [];
        foreach ([2030 => 99, 2028 => null, 2027 => -1.25, 2026 => 0, 2025 => 12] as $year => $value) {
            $rows[] = ['code' => '6488', 'financialYear' => $year, 'feMedian' => $value,
                'feMean' => 900, 'rateDate' => '2026-09-22', 'currency' => 'TWD', 'numEst' => 6];
        }
        $rows[] = ['code' => '1111', 'financialYear' => 2028, 'feMedian' => 88];
        $years = $source->feed('6488', ['statusCode' => 200, 'data' => $rows], 'https://example.test');
        $this->assertSame([2028, 2027, 2026], array_keys($years));
        $this->assertNull($years[2028]['value']);
        $this->assertSame(-1.25, $years[2027]['value']);
        $this->assertSame(0.0, $years[2026]['value']);
        $this->assertSame(6, $years[2028]['analyst_count']);
    }

    public function test_feed_rejects_unexpected_currency(): void
    {
        $this->expectException(\RuntimeException::class);
        (new TwStockEpsForecastSource)->feed('6488', ['data' => [
            ['code' => '6488', 'financialYear' => 2028, 'rateDate' => '2026-09-22', 'currency' => 'USD'],
        ]], 'https://example.test');
    }

    public function test_newest_year_wins_even_when_null_and_news_republication_does_not_reset_rate_date(): void
    {
        $source = new TwStockEpsForecastSource;
        $date = CarbonImmutable::parse('2026-10-02');
        $feed = ['value' => 48.62, 'source_date' => '2026-09-22', 'source_type' => 'factset', 'priority' => 2];
        $news = [...$feed, 'source_date' => '2026-09-30', 'priority' => 1];
        $this->assertSame($feed, $source->merge([2028 => $news], [2028 => $feed], $date)[2028]);
        $this->assertSame($feed, $source->merge([2028 => $feed], [2028 => $news], $date)[2028]);
        $news['value'] = null;
        $this->assertNull($source->merge([2028 => $feed], [2028 => $news], $date)[2028]['value']);
        $news['value'] = 51.1;
        $this->assertSame(51.1, $source->merge([2028 => $feed], [2028 => $news], $date)[2028]['value']);
        $news['source_date'] = '2026-09-22';
        $this->assertSame(48.62, $source->merge([2028 => $news], [2028 => $feed], $date)[2028]['value']);
        $news['source_date'] = '2026-10-03';
        $this->assertSame($feed, $source->merge([2028 => $feed], [2028 => $news], $date)[2028]);
    }

    public function test_news_parses_eps_table_by_year_without_using_revenue_or_previous_value(): void
    {
        $source = new TwStockEpsForecastSource;
        $article = ['title' => '鉅亨速報 - Factset 最新調查：環球晶(6488-TW)EPS預估',
            'newsId' => 1, 'publishAt' => 1790899838,
            'content' => '<table><tr><td>預估值</td><td>2027年</td><td>2028年</td><td>2029年</td></tr><tr><td>中位數</td><td>-1.25</td><td>48.62(65.35)</td><td>999</td></tr></table><table><tr><td>中位數</td><td>900000</td></tr></table>'];
        $years = $source->article($article)['years'];
        $this->assertSame([2027, 2028], array_keys($years));
        $this->assertSame(-1.25, $years[2027]['value']);
        $this->assertSame(48.62, $years[2028]['value']);
        $this->assertSame('article_publication', $years[2028]['date_type']);
        $this->assertNull($source->number('100萬元'));
        $this->assertNull($source->number('—'));
        $this->assertSame(1234.5, $source->number('1,234.50'));
    }
}
