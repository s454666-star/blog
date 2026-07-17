<?php

namespace Tests\Unit;

use App\Services\TwStockRealtimeQuoteService;
use Carbon\CarbonImmutable;
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
        config()->set('esun.quote_confirmation_required', 1);
        config()->set('esun.quote_confirmation_decimals', 2);
        config()->set('esun.quote_confirmation_tick_tolerance', 1);
    }

    public function test_it_fetches_large_market_quotes_directly_from_official_exchange_channels(): void
    {
        Http::fake([
            'https://mis.twse.com.tw/stock/api/getStockInfo.jsp*' => Http::response([
                'msgArray' => [
                    [
                        'c' => '2330',
                        'n' => '台積電',
                        'ex' => 'tse',
                        'z' => '1100.0000',
                        'y' => '1080.0000',
                        'v' => '12345',
                        'd' => '20260717',
                        't' => '10:00:01',
                    ],
                    [
                        'c' => '5483',
                        'n' => '中美晶',
                        'ex' => 'otc',
                        'z' => '145.5000',
                        'y' => '140.0000',
                        'v' => '6789',
                        'd' => '20260717',
                        't' => '10:00:02',
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->officialMarketQuotes([
            ['code' => '2330', 'exchange' => 'TWSE'],
            ['code' => '5483', 'exchange' => 'TPEx'],
        ]);

        $this->assertSame('live', $payload['source']['status']);
        $this->assertSame(1100.0, $payload['quotes']['2330']['lastPrice']);
        $this->assertSame(12345.0, $payload['quotes']['2330']['volumeLots']);
        $this->assertSame(145.5, $payload['quotes']['5483']['lastPrice']);
        $this->assertSame([], $payload['missing']);
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return ($query['ex_ch'] ?? null) === 'tse_2330.tw|otc_5483.tw';
        });
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

    public function test_it_requires_two_matching_prices_before_confirming_quote(): void
    {
        config()->set('esun.quote_providers', 'cnyes,yahoo_tw,tradingview');
        config()->set('esun.quote_confirmation_required', 2);

        Http::fake([
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
            'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList*' => Http::response([
                [
                    'systexId' => '5285',
                    'symbol' => '5285.TW',
                    'symbolName' => '界霖',
                    'price' => ['raw' => '109.0'],
                    'regularMarketPreviousClose' => ['raw' => '100.0'],
                    'change' => ['raw' => '9.0'],
                    'changePercent' => '9.00%',
                    'regularMarketTime' => '2026-06-24T03:55:45Z',
                ],
            ]),
            'https://scanner.tradingview.com/taiwan/scan' => Http::response([
                'totalCount' => 1,
                'data' => [
                    [
                        's' => 'TWSE:5285',
                        'd' => ['5285', 'Jarllytec', 110.0, 10.0, 10.0, 10000, 'delayed_streaming_900'],
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['5285']);

        $this->assertSame('live', $payload['source']['status']);
        $this->assertSame(110.0, $payload['quotes']['5285']['price']);
        $this->assertSame('confirmed', $payload['quotes']['5285']['source']);
        $this->assertSame(2, $payload['quotes']['5285']['confirmationCount']);
        $this->assertSame([], $payload['missing']);
    }

    public function test_confirmed_price_does_not_reuse_unconfirmed_previous_close(): void
    {
        config()->set('esun.quote_providers', 'tradingview,yahoo_chart');
        config()->set('esun.quote_fallback_providers', '');
        config()->set('esun.quote_confirmation_required', 2);

        Http::fake([
            'https://scanner.tradingview.com/taiwan/scan' => Http::response([
                'totalCount' => 1,
                'data' => [
                    [
                        's' => 'TWSE:00685L',
                        'd' => ['00685L', 'Capital TAIEX Daily Leveraged 2X ETF', 306.0, 6.1210334662736, 17.65, 8814375, 'delayed_streaming_900'],
                    ],
                ],
            ]),
            'https://query1.finance.yahoo.com/v8/finance/chart/00685L.TW*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 306.0,
                                'previousClose' => 306.0,
                                'regularMarketTime' => 1783389383,
                                'shortName' => 'Capital TAIEX Daily Leveraged 2X ETF',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['00685L']);

        $this->assertSame('live', $payload['source']['status']);
        $this->assertSame(306.0, $payload['quotes']['00685L']['price']);
        $this->assertSame('confirmed', $payload['quotes']['00685L']['source']);
        $this->assertNull($payload['quotes']['00685L']['previousClose']);
        $this->assertNull($payload['quotes']['00685L']['dayChange']);
        $this->assertNull($payload['quotes']['00685L']['dayChangeRate']);
        $candidatePreviousCloses = collect($payload['quotes']['00685L']['candidatePrices'])
            ->pluck('previousClose')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([288.35, 306.0], $candidatePreviousCloses);
    }

    public function test_fallback_only_agreement_does_not_override_available_primary_quotes(): void
    {
        config()->set('esun.quote_providers', 'cnyes,yahoo_tw');
        config()->set('esun.quote_fallback_providers', 'tradingview,yahoo_chart');
        config()->set('esun.quote_confirmation_required', 2);

        Http::fake([
            'https://ws.api.cnyes.com/ws/api/v1/quote/quotes/*' => Http::response([
                'statusCode' => 200,
                'data' => [
                    [
                        '200010' => '00631L',
                        '200009' => '元大台灣50正2',
                        '6' => 37.80,
                        '11' => 1.02,
                        '56' => 2.77,
                        '200007' => 1783904400,
                    ],
                ],
            ]),
            'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList*' => Http::response([
                [
                    'systexId' => '00631L',
                    'symbol' => '00631L.TW',
                    'symbolName' => '元大台灣50正2',
                    'price' => ['raw' => '38.00'],
                    'regularMarketPreviousClose' => ['raw' => '36.78'],
                    'change' => ['raw' => '1.22'],
                    'changePercent' => '3.32%',
                    'regularMarketTime' => '2026-07-13T01:00:00Z',
                ],
            ]),
            'https://scanner.tradingview.com/taiwan/scan' => Http::response([
                'totalCount' => 1,
                'data' => [
                    [
                        's' => 'TWSE:00631L',
                        'd' => ['00631L', 'Yuanta/P-shares Taiwan Top 50 ETF', 36.78, -0.97, -0.36, 10000, 'delayed_streaming_900'],
                    ],
                ],
            ]),
            'https://query1.finance.yahoo.com/v8/finance/chart/00631L.TW*' => Http::response([
                'chart' => [
                    'result' => [
                        [
                            'meta' => [
                                'regularMarketPrice' => 36.78,
                                'previousClose' => 37.14,
                                'regularMarketTime' => 1783589401,
                                'shortName' => 'Yuanta/P-shares Taiwan Top 50 ETF',
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['00631L']);

        $this->assertSame('partial', $payload['source']['status']);
        $this->assertSame('provisional', $payload['quotes']['00631L']['source']);
        $this->assertSame(38.0, $payload['quotes']['00631L']['price']);
        $this->assertNotSame(36.78, $payload['quotes']['00631L']['price']);
    }

    public function test_it_confirms_quotes_when_two_sources_are_within_one_tick(): void
    {
        config()->set('esun.quote_providers', 'cnyes,yahoo_tw');
        config()->set('esun.quote_confirmation_required', 2);

        Http::fake([
            'https://ws.api.cnyes.com/ws/api/v1/quote/quotes/*' => Http::response([
                'statusCode' => 200,
                'data' => [
                    [
                        '200010' => '2303',
                        '200009' => '聯電',
                        '6' => 163.0,
                        '11' => -15.5,
                        '56' => -8.68,
                        '22' => 163.0,
                        '25' => 163.5,
                        '800001' => 430187,
                        '200007' => 1782443375,
                    ],
                ],
            ]),
            'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList*' => Http::response([
                [
                    'systexId' => '2303',
                    'symbol' => '2303.TW',
                    'symbolName' => '聯電',
                    'price' => ['raw' => '162.5'],
                    'regularMarketPreviousClose' => ['raw' => '178.5'],
                    'change' => ['raw' => '-16.0'],
                    'changePercent' => '-8.96%',
                    'regularMarketTime' => '2026-06-26T03:09:36Z',
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['2303']);

        $this->assertSame('live', $payload['source']['status']);
        $this->assertStringStartsWith('近似雙來源確認：', $payload['source']['label']);
        $this->assertSame([], $payload['missing']);
        $this->assertSame('nearby-confirmed', $payload['quotes']['2303']['source']);
        $this->assertSame('nearby-confirmed', $payload['quotes']['2303']['priceType']);
        $this->assertSame(2, $payload['quotes']['2303']['confirmationCount']);
        $this->assertSame(0.5, $payload['quotes']['2303']['confirmationRange']);
    }

    public function test_it_uses_provisional_quote_when_no_two_sources_match(): void
    {
        config()->set('esun.quote_providers', 'cnyes,yahoo_tw,tradingview');
        config()->set('esun.quote_confirmation_required', 2);

        Http::fake([
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
            'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList*' => Http::response([
                [
                    'systexId' => '5285',
                    'symbol' => '5285.TW',
                    'symbolName' => '界霖',
                    'price' => ['raw' => '109.0'],
                    'regularMarketPreviousClose' => ['raw' => '100.0'],
                    'change' => ['raw' => '9.0'],
                    'changePercent' => '9.00%',
                    'regularMarketTime' => '2026-06-24T03:55:45Z',
                ],
            ]),
            'https://scanner.tradingview.com/taiwan/scan' => Http::response([
                'totalCount' => 1,
                'data' => [
                    [
                        's' => 'TWSE:5285',
                        'd' => ['5285', 'Jarllytec', 108.0, 8.0, 8.0, 10000, 'delayed_streaming_900'],
                    ],
                ],
            ]),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['5285']);

        $this->assertSame('partial', $payload['source']['status']);
        $this->assertStringStartsWith('多來源暫用：', $payload['source']['label']);
        $this->assertSame([], $payload['missing']);
        $this->assertArrayNotHasKey('5285', $payload['unconfirmed']);
        $this->assertSame(109.0, $payload['quotes']['5285']['price']);
        $this->assertSame('provisional', $payload['quotes']['5285']['source']);
        $this->assertSame('provisional', $payload['quotes']['5285']['priceType']);
        $this->assertSame(3, $payload['quotes']['5285']['confirmationCount']);
        $this->assertCount(3, $payload['quotes']['5285']['candidatePrices']);
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

    public function test_it_validates_sparse_stock_previous_close_with_yahoo_taiwan_page(): void
    {
        config()->set('esun.quote_providers', 'yahoo_tw');
        config()->set('esun.quote_confirmation_required', 2);

        $chart = [
            'meta' => [
                'symbol' => '7861.TWO',
                'shortName' => '貝爾威勒',
                'regularMarketPrice' => 1160,
                'previousClose' => 1123.31,
                'chartPreviousClose' => 1123,
                'regularMarketTime' => 1784088060,
            ],
            'timestamp' => [],
            'indicators' => ['quote' => [[]]],
        ];
        $html = '<script>root.App.main = '
            . json_encode([
                'MarketChartStore' => [
                    'libra' => ['7861.TWO' => $chart],
                    'spark' => [],
                ],
            ], JSON_THROW_ON_ERROR)
            . ';</script>';

        Http::fake([
            'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList*' => Http::response([
                [
                    'systexId' => '7861',
                    'symbol' => '7861.TWO',
                    'symbolName' => '貝爾威勒',
                    'price' => ['raw' => '1160'],
                    'regularMarketPreviousClose' => ['raw' => '1123.31'],
                    'change' => ['raw' => '36.69'],
                    'changePercent' => '3.27%',
                    'regularMarketTime' => '2026-07-15T04:00:12Z',
                ],
            ]),
            'https://tw.stock.yahoo.com/quote/7861.TWO*' => Http::response($html),
        ]);

        $payload = app(TwStockRealtimeQuoteService::class)->quotes(['7861']);
        $quote = $payload['quotes']['7861'];

        $this->assertSame('live', $payload['source']['status']);
        $this->assertSame('confirmed', $quote['priceType']);
        $this->assertSame(1160.0, $quote['price']);
        $this->assertSame(1123.31, $quote['previousClose']);
        $this->assertEqualsWithDelta(36.69, $quote['dayChange'], 0.0001);
        $this->assertEqualsWithDelta(3.2662, $quote['dayChangeRate'], 0.0001);
        $this->assertSame(['Yahoo 台股', 'Yahoo 台股頁面'], $quote['confirmedBy']);
        Http::assertSentCount(2);
    }

    public function test_it_keeps_quotes_after_the_thirtieth_holding(): void
    {
        config()->set('esun.quote_providers', 'yahoo_tw');

        Http::fake([
            'https://tw.stock.yahoo.com/_td-stock/api/resource/StockServices.stockList*' => function ($request) {
                if (!str_contains(urldecode((string) $request->url()), '7861.TW')) {
                    return Http::response([]);
                }

                return Http::response([
                    [
                        'systexId' => '7861',
                        'symbol' => '7861.TW',
                        'symbolName' => '貝爾威勒',
                        'exchange' => 'TAI',
                        'price' => ['raw' => '1235.0'],
                        'regularMarketPreviousClose' => ['raw' => '1186.47'],
                        'change' => ['raw' => '48.53'],
                        'changePercent' => '4.09%',
                        'regularMarketTime' => '2026-06-30T03:09:00Z',
                    ],
                ]);
            },
        ]);

        $codes = collect(range(1, 35))
            ->map(fn (int $index): string => str_pad((string) $index, 4, '0', STR_PAD_LEFT))
            ->push('7861')
            ->all();

        $payload = app(TwStockRealtimeQuoteService::class)->quotes($codes);

        $this->assertArrayHasKey('7861', $payload['quotes']);
        $this->assertSame(1235.0, $payload['quotes']['7861']['price']);
        $this->assertSame(1186.47, $payload['quotes']['7861']['previousClose']);
        $this->assertSame(4.09, $payload['quotes']['7861']['dayChangeRate']);
        Http::assertSentCount(2);
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

    public function test_it_returns_today_intraday_prices_in_ascending_order_and_caches_them(): void
    {
        $now = CarbonImmutable::parse('2026-07-15 10:30:00', 'Asia/Taipei');
        CarbonImmutable::setTestNow($now);

        try {
            Http::fake([
                'https://ws.api.cnyes.com/ws/api/v1/charting/history*' => Http::response([
                    'data' => [
                        's' => 'ok',
                        't' => [
                            $now->setTime(9, 2)->getTimestamp(),
                            $now->setTime(9, 1)->getTimestamp(),
                            $now->subDay()->setTime(13, 30)->getTimestamp(),
                        ],
                        'c' => [101.5, 100.0, 99.0],
                        'o' => [100.8, 99.5, 98.5],
                        'l' => [100.5, 99.0, 98.0],
                        'h' => [102.0, 100.5, 99.5],
                        'v' => [2000, 1500, 1000],
                    ],
                ]),
            ]);

            $service = app(TwStockRealtimeQuoteService::class);
            $payload = $service->intradayPrices(['5483', '5483', '00631L']);

            $this->assertSame('2026-07-15', $payload['date']);
            $this->assertSame('live', $payload['source']['status']);
            $this->assertSame([5483, '00631L'], array_keys($payload['series']));
            $this->assertSame(100.0, $payload['series']['5483'][0]['price']);
            $this->assertSame(99.5, $payload['series']['5483'][0]['open']);
            $this->assertSame(99.0, $payload['series']['5483'][0]['low']);
            $this->assertSame(100.5, $payload['series']['5483'][0]['high']);
            $this->assertSame(1500.0, $payload['series']['5483'][0]['volume']);
            $this->assertSame(101.5, $payload['series']['5483'][1]['price']);
            $this->assertSame([], $payload['missing']);

            $cached = $service->intradayPrices(['5483', '00631L']);
            $this->assertSame(101.5, $cached['series']['5483'][1]['price']);
            Http::assertSentCount(2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_it_removes_previous_day_points_from_today_intraday_cache(): void
    {
        $now = CarbonImmutable::parse('2026-07-16 09:20:00', 'Asia/Taipei');
        CarbonImmutable::setTestNow($now);

        try {
            Cache::put('tw-stock:intraday:v3:2026-07-16:5483', [
                'provider' => 'cnyes',
                'points' => [
                    ['time' => $now->subDay()->setTime(9, 1)->getTimestamp(), 'price' => 98.0],
                    ['time' => $now->setTime(9, 2)->getTimestamp(), 'price' => 100.0, 'open' => 99.8, 'low' => 99.5, 'high' => 100.5, 'volume' => 1200],
                ],
            ], now()->addSeconds(15));
            Http::fake();

            $payload = app(TwStockRealtimeQuoteService::class)->intradayPrices(['5483']);

            $this->assertSame('2026-07-16', $payload['date']);
            $this->assertCount(1, $payload['series']['5483']);
            $this->assertSame($now->setTime(9, 2)->getTimestamp(), $payload['series']['5483'][0]['time']);
            $this->assertSame(99.8, $payload['series']['5483'][0]['open']);
            $this->assertSame(99.5, $payload['series']['5483'][0]['low']);
            $this->assertSame(100.5, $payload['series']['5483'][0]['high']);
            $this->assertSame(1200.0, $payload['series']['5483'][0]['volume']);
            Http::assertNothingSent();
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_it_falls_back_to_yahoo_taiwan_page_when_cnyes_has_no_intraday_history(): void
    {
        $now = CarbonImmutable::parse('2026-07-15 11:34:00', 'Asia/Taipei');
        CarbonImmutable::setTestNow($now);

        try {
            $chart = [
                'meta' => ['symbol' => '7861.TWO', 'regularMarketPrice' => 1150],
                'timestamp' => [
                    $now->setTime(9, 1)->getTimestamp(),
                    $now->setTime(9, 2)->getTimestamp(),
                    $now->setTime(10, 6)->getTimestamp(),
                    $now->subDay()->setTime(13, 30)->getTimestamp(),
                ],
                'indicators' => [
                    'quote' => [[
                        'close' => [1170, 1125, 1150, 999],
                        'low' => [1170, 1120, 1145, 999],
                        'high' => [1170, 1130, 1155, 999],
                    ]],
                ],
            ];
            $html = '<script>root.App.main = '
                . json_encode([
                    'MarketChartStore' => [
                        'libra' => ['7861.TWO' => $chart],
                        'spark' => [],
                    ],
                ], JSON_THROW_ON_ERROR)
                . ';</script>';

            Http::fake([
                'https://ws.api.cnyes.com/ws/api/v1/charting/history*' => Http::response([
                    'data' => ['s' => 'ok', 't' => [], 'c' => []],
                ]),
                'https://tw.stock.yahoo.com/quote/7861.TWO*' => Http::response($html),
            ]);

            $payload = app(TwStockRealtimeQuoteService::class)->intradayPrices(['7861']);

            $this->assertSame('live', $payload['source']['status']);
            $this->assertSame('Yahoo 台股分時', $payload['source']['label']);
            $this->assertSame(['yahoo_tw_page'], $payload['source']['providers']);
            $this->assertCount(3, $payload['series']['7861']);
            $this->assertSame(1170.0, $payload['series']['7861'][0]['price']);
            $this->assertSame(1125.0, $payload['series']['7861'][1]['price']);
            $this->assertSame(1120.0, $payload['series']['7861'][1]['low']);
            $this->assertSame(1150.0, $payload['series']['7861'][2]['price']);
            $this->assertSame([], $payload['missing']);
            Http::assertSentCount(2);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
