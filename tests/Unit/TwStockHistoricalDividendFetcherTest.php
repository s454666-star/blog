<?php

namespace Tests\Unit;

use App\Services\TwStockHistoricalDividendFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwStockHistoricalDividendFetcherTest extends TestCase
{
    public function test_it_merges_official_twse_and_tpex_cash_dividend_events(): void
    {
        Cache::flush();
        Http::fake([
            'www.twse.com.tw/*' => Http::response([
                'stat' => 'OK',
                'data' => [
                    ['115年06月10日', '2330', '台積電', '1000', '995', '5.0', '息'],
                    ['115年07月01日', '2880', '華南金', '40', '38', '2.0', '權息'],
                ],
            ]),
            'openapi.twse.com.tw/*' => Http::response([
                [
                    '公司代號' => '2880',
                    '期別' => '1',
                    '董事會（擬議）股利分派日' => '1150301',
                    '股東配發-盈餘分配之現金股利(元/股)' => '1.5',
                    '股東配發-法定盈餘公積發放之現金(元/股)' => '0.2',
                    '股東配發-資本公積發放之現金(元/股)' => '0.3',
                ],
            ]),
            'www.tpex.org.tw/*' => Http::response([
                'stat' => 'ok',
                'tables' => [[
                    'data' => [
                        ['115/07/02', '5483', '中美晶', '100', '96', '1', '3', '4', '除權息', '', '', '', '', '3.0'],
                    ],
                ]],
            ]),
        ]);

        $events = (new TwStockHistoricalDividendFetcher)->fetch(
            ['2330', '2880', '5483'],
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-08-13'),
        );

        $this->assertCount(3, $events);
        $this->assertSame(['2330', '2880', '5483'], array_column($events, 'stock_code'));
        $this->assertSame([5.0, 2.0, 3.0], array_column($events, 'cash_dividend_per_share'));
        $this->assertSame(
            ['TWSE TWT49U', 'TWSE TWT49U + MOPS t187ap45_L', 'TPEx exDailyQ'],
            array_column($events, 'source'),
        );
    }
}
