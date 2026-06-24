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
        config()->set('esun.quote_providers', 'twse');
        config()->set('esun.quote_fallback_providers', '');
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

    public function test_it_ignores_zero_twse_bid_and_uses_next_provider(): void
    {
        config()->set('esun.quote_providers', 'twse,cnyes');

        Http::fake([
            'https://mis.twse.com.tw/stock/api/getStockInfo.jsp*' => Http::response([
                'msgArray' => [
                    [
                        'c' => '5285',
                        'n' => '界霖',
                        'z' => '-',
                        'y' => '100.0000',
                        'a' => '-',
                        'b' => '0_',
                        'd' => '20260624',
                        't' => '12:01:12',
                    ],
                ],
            ]),
            'https://ws.api.cnyes.com/ws/api/v1/quote/quotes/*' => Http::response([
                'statusCode' => 200,
                'data' => [
                    [
                        '200010' => '5285',
                        '200009' => '界霖',
                        '6' => 110.0,
                        '11' => 10.0,
                        '56' => 10.0,
                        '200007' => 1782273344,
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['5285']);

        $this->assertSame('CNYES', $payload['source']['label']);
        $this->assertSame(110.0, $payload['quotes']['5285']['price']);
        $this->assertSame('cnyes', $payload['quotes']['5285']['source']);
    }

    public function test_it_falls_back_to_yahoo_when_twse_has_no_quote(): void
    {
        config()->set('esun.quote_fallback_providers', 'yahoo_chart');

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

        $this->assertSame('Yahoo Finance chart', $payload['source']['label']);
        $this->assertSame(192.0, $payload['quotes']['3362']['price']);
        $this->assertSame('yahoo_chart', $payload['quotes']['3362']['source']);
    }

    public function test_it_parses_cnyes_batch_quotes(): void
    {
        config()->set('esun.quote_providers', 'cnyes');

        Http::fake([
            'https://ws.api.cnyes.com/ws/api/v1/quote/quotes/*' => Http::response([
                'statusCode' => 200,
                'data' => [
                    [
                        '200010' => '2303',
                        '200009' => '聯電',
                        '6' => 174.5,
                        '11' => 4.5,
                        '56' => 2.65,
                        '22' => 174.0,
                        '25' => 174.5,
                        '12' => 185.5,
                        '13' => 165.5,
                        '800001' => 430187,
                        '200007' => 1782273344,
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['2303']);

        $this->assertSame('CNYES', $payload['source']['label']);
        $this->assertSame(174.5, $payload['quotes']['2303']['price']);
        $this->assertSame(170.0, $payload['quotes']['2303']['previousClose']);
        $this->assertSame('cnyes', $payload['quotes']['2303']['source']);
    }

    public function test_it_parses_yahoo_tw_batch_quotes(): void
    {
        config()->set('esun.quote_providers', 'yahoo_tw');

        Http::fake([
            'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList*' => Http::response([
                [
                    'systexId' => '5285',
                    'symbol' => '5285.TW',
                    'symbolName' => '界霖',
                    'exchange' => 'TAI',
                    'price' => ['raw' => '109.0'],
                    'regularMarketPreviousClose' => ['raw' => '100.0'],
                    'change' => ['raw' => '9.0'],
                    'changePercent' => '9.00%',
                    'bid' => ['raw' => '108.5'],
                    'ask' => ['raw' => '109.0'],
                    'regularMarketTime' => '2026-06-24T03:55:45Z',
                    'volume' => '11467000',
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['5285']);

        $this->assertSame('Yahoo 台股', $payload['source']['label']);
        $this->assertSame(109.0, $payload['quotes']['5285']['price']);
        $this->assertSame(9.0, $payload['quotes']['5285']['dayChangeRate']);
        $this->assertSame(11467.0, $payload['quotes']['5285']['volumeLots']);
    }

    public function test_it_parses_tradingview_batch_quotes(): void
    {
        config()->set('esun.quote_providers', 'tradingview');

        Http::fake([
            'https://scanner.tradingview.com/taiwan/scan' => Http::response([
                'totalCount' => 1,
                'data' => [
                    [
                        's' => 'TWSE:2303',
                        'd' => ['2303', 'United Microelectronics Corp.', 174.5, 2.6470588235, 4.5, 426294043, 'delayed_streaming_900'],
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['2303']);

        $this->assertSame('TradingView', $payload['source']['label']);
        $this->assertSame(174.5, $payload['quotes']['2303']['price']);
        $this->assertSame(170.0, $payload['quotes']['2303']['previousClose']);
        $this->assertSame('tradingview', $payload['quotes']['2303']['source']);
    }
}
