<?php

namespace Tests\Feature;

use App\Services\TwStockRealtimeQuoteService;
use App\Services\YuantaPortfolioService;
use Mockery;
use Tests\TestCase;

class YuantaPortfolioControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('yuanta.dashboard_token', 'test-token');
    }

    public function test_dashboard_requires_token(): void
    {
        $this->get(route('tw-stock.yuanta-portfolio.index'))
            ->assertForbidden();
    }

    public function test_dashboard_renders_private_realtime_page(): void
    {
        $service = Mockery::mock(YuantaPortfolioService::class);
        $service->shouldReceive('marketStatus')->once()->andReturn([
            'isOpen' => false,
            'label' => '非交易時段',
            'pollSeconds' => null,
        ]);
        $this->app->instance(YuantaPortfolioService::class, $service);

        $this->get(route('tw-stock.yuanta-portfolio.index', ['token' => 'test-token']))
            ->assertOk()
            ->assertSee('元大庫存即時看板')
            ->assertSee('元大每60秒校準')
            ->assertSee('更新元大API')
            ->assertSee('今日損益')
            ->assertSee('近 15 日成本')
            ->assertSee('data-cost-history-wave', false)
            ->assertSee('滑鼠移入可查看加減碼日期')
            ->assertSee('showCostHistoryTooltip', false)
            ->assertSee('Math.abs(delta)', false)
            ->assertDontSee('data-cost-history-tooltip-value', false)
            ->assertDontSee('較前次')
            ->assertSee('融資額度')
            ->assertSee('已用額度')
            ->assertSee('可用額度')
            ->assertSee('維持率')
            ->assertSee('元大已實現')
            ->assertSee('apiUrl', false)
            ->assertSee('quoteUrl', false)
            ->assertSee('intradayUrl', false)
            ->assertSee('historyUrl', false)
            ->assertSee('historyDatesUrl', false)
            ->assertSee('data-history-date', false)
            ->assertSee('當日走勢')
            ->assertSee('data-pnl-wave="unrealizedPnl"', false)
            ->assertSee('stockWaveHtml', false)
            ->assertSee('brokerName', false)
            ->assertSee('A provisional price can carry a previousClose from only one source.', false)
            ->assertSee('if (!quoteCanUpdatePnl(quote)) {', false)
            ->assertSee('formatQuantity', false)
            ->assertSee('todayAddedQuantity', false)
            ->assertSee("numeric <= -40) return 'stop-loss-row-danger'", false)
            ->assertSee("numeric <= -30) return 'stop-loss-row-warning'", false)
            ->assertDontSee('fancy-cursor.css', false)
            ->assertDontSee('data-fancy-cursor=', false)
            ->assertDontSee('更新玉山API')
            ->assertDontSee('玉山庫存即時看板');
    }

    public function test_data_endpoint_returns_service_snapshot(): void
    {
        $payload = [
            'summary' => [
                'stockCount' => 1,
                'marketValue' => 100000,
            ],
            'market' => [
                'isOpen' => true,
                'label' => '台股交易時段',
                'pollSeconds' => 2,
            ],
            'rows' => [
                [
                    'stockNo' => '2303',
                    'stockName' => '聯電',
                    'quantity' => 1000,
                ],
            ],
        ];

        $service = Mockery::mock(YuantaPortfolioService::class);
        $service->shouldReceive('snapshot')->once()->with(false)->andReturn($payload);
        $this->app->instance(YuantaPortfolioService::class, $service);

        $this->getJson(route('tw-stock.yuanta-portfolio.data', ['token' => 'test-token']))
            ->assertOk()
            ->assertJsonPath('summary.stockCount', 1)
            ->assertJsonPath('rows.0.stockNo', '2303');
    }

    public function test_quotes_endpoint_returns_requested_holdings_quotes(): void
    {
        $portfolio = Mockery::mock(YuantaPortfolioService::class);
        $portfolio->shouldReceive('marketStatus')->once()->andReturn([
            'isOpen' => true,
            'label' => '台股交易時段',
            'pollSeconds' => 1,
        ]);
        $this->app->instance(YuantaPortfolioService::class, $portfolio);

        $quotes = Mockery::mock(TwStockRealtimeQuoteService::class);
        $quotes->shouldReceive('quotes')->once()->with(['2303'])->andReturn([
            'servedAt' => '2026-06-26T11:45:00+08:00',
            'cacheSeconds' => 1,
            'source' => [
                'status' => 'live',
                'providers' => ['cnyes'],
                'label' => 'CNYES',
            ],
            'quotes' => [
                '2303' => [
                    'price' => 175.0,
                    'previousClose' => 170.0,
                    'source' => 'cnyes',
                ],
            ],
            'missing' => [],
        ]);
        $this->app->instance(TwStockRealtimeQuoteService::class, $quotes);

        $this->getJson(route('tw-stock.yuanta-portfolio.quotes', [
            'token' => 'test-token',
            'codes' => '2303',
        ]))
            ->assertOk()
            ->assertJsonPath('source.label', 'CNYES')
            ->assertJsonPath('market.pollSeconds', 1)
            ->assertJsonPath('quotes.2303.price', 175);
    }

    public function test_intraday_endpoint_returns_requested_holdings_series(): void
    {
        $quotes = Mockery::mock(TwStockRealtimeQuoteService::class);
        $quotes->shouldReceive('intradayPrices')->once()->with(['2303'])->andReturn([
            'servedAt' => '2026-07-15T10:30:00+08:00',
            'date' => '2026-07-15',
            'cacheSeconds' => 15,
            'source' => ['status' => 'live', 'label' => 'CNYES 分時'],
            'series' => [
                '2303' => [['time' => 1784080860, 'price' => 45.5]],
            ],
            'missing' => [],
        ]);
        $this->app->instance(TwStockRealtimeQuoteService::class, $quotes);

        $this->getJson(route('tw-stock.yuanta-portfolio.intraday', [
            'token' => 'test-token',
            'codes' => '2303',
        ]))
            ->assertOk()
            ->assertJsonPath('source.label', 'CNYES 分時')
            ->assertJsonPath('series.2303.0.price', 45.5);
    }

    public function test_history_dates_endpoint_returns_available_snapshot_dates(): void
    {
        $service = Mockery::mock(YuantaPortfolioService::class);
        $service->shouldReceive('dailySnapshotDates')->once()->andReturn([
            [
                'date' => '2026-07-03',
                'capturedAt' => '2026-07-03T17:55:00+08:00',
                'costBasis' => 130000.0,
                'todayPnl' => 16000.0,
                'unrealizedPnl' => -12000.0,
            ],
        ]);
        $this->app->instance(YuantaPortfolioService::class, $service);

        $this->getJson(route('tw-stock.yuanta-portfolio.history-dates', ['token' => 'test-token']))
            ->assertOk()
            ->assertJsonPath('dates.0.date', '2026-07-03')
            ->assertJsonPath('dates.0.costBasis', 130000)
            ->assertJsonPath('dates.0.todayPnl', 16000);
    }

    public function test_history_endpoint_returns_requested_snapshot(): void
    {
        $service = Mockery::mock(YuantaPortfolioService::class);
        $service->shouldReceive('dailySnapshotPayload')->once()->with('2026-07-03')->andReturn([
            'history' => [
                'date' => '2026-07-03',
            ],
            'source' => [
                'status' => 'historical',
            ],
            'summary' => [
                'todayPnl' => 16000.0,
                'unrealizedPnl' => -12000.0,
            ],
            'rows' => [
                ['stockNo' => '2303'],
            ],
        ]);
        $this->app->instance(YuantaPortfolioService::class, $service);

        $this->getJson(route('tw-stock.yuanta-portfolio.history', [
            'token' => 'test-token',
            'date' => '2026-07-03',
        ]))
            ->assertOk()
            ->assertJsonPath('source.status', 'historical')
            ->assertJsonPath('history.date', '2026-07-03')
            ->assertJsonPath('summary.unrealizedPnl', -12000)
            ->assertJsonPath('rows.0.stockNo', '2303');
    }
}
