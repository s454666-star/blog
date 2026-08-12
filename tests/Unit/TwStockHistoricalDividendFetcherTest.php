<?php

namespace Tests\Unit;

use App\Services\TwStockHistoricalDividendFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwStockHistoricalDividendFetcherTest extends TestCase
{
    public function test_it_keeps_cash_dividend_rows_and_deduplicates_same_event(): void
    {
        Cache::flush();
        Http::fake([
            'api.finmindtrade.com/*' => Http::response([
                'status' => 200,
                'data' => [
                    [
                        'date' => '2026-06-10',
                        'stock_id' => '2330',
                        'stock_and_cache_dividend' => 5.0,
                        'stock_or_cache_dividend' => '息',
                    ],
                    [
                        'date' => '2026-06-10',
                        'stock_id' => '2330',
                        'stock_and_cache_dividend' => 5.0,
                        'stock_or_cache_dividend' => '息',
                    ],
                    [
                        'date' => '2026-07-01',
                        'stock_id' => '2330',
                        'stock_and_cache_dividend' => 1.0,
                        'stock_or_cache_dividend' => '權',
                    ],
                ],
            ]),
        ]);

        $events = (new TwStockHistoricalDividendFetcher)->fetch(
            ['2330'],
            CarbonImmutable::parse('2026-01-01'),
            CarbonImmutable::parse('2026-08-13'),
        );

        $this->assertCount(1, $events);
        $this->assertSame('2026-06-10', $events[0]['ex_dividend_date']);
        $this->assertSame(5.0, $events[0]['cash_dividend_per_share']);
    }
}
