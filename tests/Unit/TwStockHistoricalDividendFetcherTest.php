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

        $this->assertCount(2, $events);
        $this->assertSame(['2330', '5483'], array_column($events, 'stock_code'));
        $this->assertSame([5.0, 3.0], array_column($events, 'cash_dividend_per_share'));
        $this->assertSame(['TWSE TWT49U', 'TPEx exDailyQ'], array_column($events, 'source'));
    }
}
