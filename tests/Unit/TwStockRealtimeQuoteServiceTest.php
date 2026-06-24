<?php

namespace Tests\Unit;

use App\Services\TwStockRealtimeQuoteService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TwStockRealtimeQuoteServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('esun.quote_providers', 'twse,yahoo');
        config()->set('esun.quote_cache_seconds', 1);
    }

    public function test_it_parses_twse_mis_quotes_with_mid_price_fallback(): void
    {
        Http::fake([
            'https://mis.twse.com.tw/stock/api/getStockInfo.jsp*' => Http::response([
                'msgArray' => [
                    [
                        'c' => '2303',
                        'n' => 'UMC',
                        'ex' => 'tse',
                        'z' => '-',
                        'y' => '170.0000',
                        'a' => '174.5000_175.0000_',
                        'b' => '174.0000_173.5000_',
                        'd' => '20260624',
                        't' => '11:45:01',
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['2303']);

        $this->assertSame('live', $payload['source']['status']);
        $this->assertSame('TWSE MIS', $payload['source']['label']);
        $this->assertSame(174.25, $payload['quotes']['2303']['price']);
        $this->assertSame('mid', $payload['quotes']['2303']['priceType']);
        $this->assertEqualsWithDelta(2.5, $payload['quotes']['2303']['dayChangeRate'], 0.0001);
    }

    public function test_it_falls_back_to_yahoo_when_twse_has_no_quote(): void
    {
        Http::fake([
            'https://mis.twse.com.tw/stock/api/getStockInfo.jsp*' => Http::response([
                'msgArray' => [],
            ]),
            'https://query1.finance.yahoo.com/v8/finance/chart/3362.TW?*' => Http::response([
                'chart' => ['result' => [['meta' => []]]],
            ]),
            'https://query1.finance.yahoo.com/v8/finance/chart/3362.TWO?*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 192.0,
                                'previousClose' => 186.0,
                                'regularMarketTime' => 1782272700,
                                'shortName' => 'Advanced Optoelectronic',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['3362']);

        $this->assertSame('Yahoo Finance', $payload['source']['label']);
        $this->assertSame(192.0, $payload['quotes']['3362']['price']);
        $this->assertSame('yahoo', $payload['quotes']['3362']['source']);
    }
}
