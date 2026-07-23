<?php

namespace Tests\Feature;

use App\Services\EsunPortfolioService;
use App\Services\TwStockRealtimeQuoteService;
use Mockery;
use Tests\TestCase;

class EsunPortfolioControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('esun.dashboard_token', 'test-token');
    }

    public function test_dashboard_requires_token(): void
    {
        $this->get(route('tw-stock.esun-portfolio.index'))
            ->assertForbidden();
    }

    public function test_dashboard_renders_private_realtime_page(): void
    {
        $service = Mockery::mock(EsunPortfolioService::class);
        $service->shouldReceive('marketStatus')->once()->andReturn([
            'isOpen' => false,
            'label' => '非交易時段',
            'pollSeconds' => null,
        ]);
        $this->app->instance(EsunPortfolioService::class, $service);

        $this->get(route('tw-stock.esun-portfolio.index', ['token' => 'test-token']))
            ->assertOk()
            ->assertSee('玉山庫存即時看板')
            ->assertSee('apiUrl', false)
            ->assertSee('quoteUrl', false)
            ->assertSee('intradayUrl', false)
            ->assertSee('historyUrl', false)
            ->assertSee('historyDatesUrl', false)
            ->assertSee('data-history-date', false)
            ->assertSee('dashboardToken', false)
            ->assertSee('今日損益')
            ->assertSee('當日走勢')
            ->assertSee('data-pnl-wave="todayPnl"', false)
            ->assertSee('stockWaveHtml', false)
            ->assertSee('ensureCurrentIntradayDate', false)
            ->assertSee("String(payload?.date || '') !== responseDate", false)
            ->assertSee('ensureIntradaySeries', false)
            ->assertSee('累積損益')
            ->assertSee('股票市值')
            ->assertSee('今年總損益')
            ->assertSee('今年報酬率')
            ->assertSee('近 15 日成本')
            ->assertSee('data-cost-history-wave', false)
            ->assertSee('當日已實現')
            ->assertDontSee('沖銷 --')
            ->assertDontSee('年化報酬率')
            ->assertDontSee('年度報酬率')
            ->assertSee('投入總成本')
            ->assertSee('投資水位')
            ->assertDontSee('總股數')
            ->assertDontSee('庫存檔數')
            ->assertSee('庫存占比')
            ->assertSee('即時價')
            ->assertSee('即時總損益')
            ->assertSee('即時市值')
            ->assertDontSee('玉山價')
            ->assertDontSee('玉山差')
            ->assertSee('近60日漲幅')
            ->assertDontSee('今年以來漲幅')
            ->assertSee('更新玉山API')
            ->assertSee('更新即時報價')
            ->assertSee('button secondary', false)
            ->assertDontSee('class="tabs"', false)
            ->assertDontSee('<span class="tab active">即時損益</span>', false)
            ->assertDontSee('全欄排序')
            ->assertSee('data-sort-key="unrealizedPnl"', false)
            ->assertSee('玉山每60秒校準')
            ->assertSee('const calibrationSeconds = Number(60) || 60;', false)
            ->assertSee('scheduleEsunPolling', false)
            ->assertSee('millisecondsUntilNextEsunRefresh', false)
            ->assertSee('updateInventoryStatus', false)
            ->assertSee('isInventoryFresh', false)
            ->assertSee("['stale', 'throttled']", false)
            ->assertDontSee('整分鐘校準玉山')
            ->assertDontSee('玉山每分鐘校準')
            ->assertSee('quoteCanRepriceRow', false)
            ->assertSee('quoteCanUpdatePnl', false)
            ->assertSee("priceType || quote?.source || '').toLowerCase() !== 'provisional'", false)
            ->assertSee('rowLooksParkedAtPreviousClose', false)
            ->assertSee('quotePreviousCloseMatchesRow', false)
            ->assertSee('canUseCandidateQuote', false)
            ->assertSee('shouldUseQuotePreviousClose', false)
            ->assertSee('暫用報價未計入', false)
            ->assertSee('const previousClose = rowPreviousClose ?? quotePreviousClose;', false)
            ->assertSee('applyCandidateQuoteToRow', false)
            ->assertSee('applyStaleQuoteToRow', false)
            ->assertSee("window.addEventListener('focus'", false)
            ->assertSee("window.addEventListener('pageshow'", false)
            ->assertSee("document.addEventListener('visibilitychange'", false)
            ->assertSee('exchange-badge', false)
            ->assertSee('exchangeBadgeHtml', false)
            ->assertSee('formatInvestmentLevel', false)
            ->assertSee('stopLossRowClass', false)
            ->assertSee('stop-loss-row-warning', false)
            ->assertSee('stop-loss-row-danger', false)
            ->assertSee('formatQuantity', false)
            ->assertSee('todayAddedQuantity', false)
            ->assertSee("numeric <= -40) return 'stop-loss-row-danger'", false)
            ->assertSee("numeric <= -30) return 'stop-loss-row-warning'", false)
            ->assertSee("label: '市'", false)
            ->assertSee("label: '櫃'", false)
            ->assertSee("label: '興'", false)
            ->assertDontSee('fancy-cursor.css', false)
            ->assertDontSee('data-fancy-cursor=', false)
            ->assertDontSee('cursor: copy', false)
            ->assertDontSee('庫存批次明細');
    }

    public function test_dashboard_rejects_return_visit_with_only_access_cookie(): void
    {
        $this->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

        $cookieValue = hash_hmac('sha256', 'test-token', (string) config('app.key'));

        $this
            ->withCookie('esun_portfolio_access', $cookieValue)
            ->get(route('tw-stock.esun-portfolio.index'))
            ->assertForbidden();
    }

    public function test_data_endpoint_returns_service_snapshot(): void
    {
        $payload = [
            'summary' => [
                'stockCount' => 1,
                'lotCount' => 1,
                'shareCount' => 2000,
                'marketValue' => 344000,
                'todayPnl' => 4000,
                'unrealizedPnl' => -2420,
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
                    'quantity' => 2000,
                ],
            ],
        ];

        $service = Mockery::mock(EsunPortfolioService::class);
        $service->shouldReceive('snapshot')->once()->with(false)->andReturn($payload);
        $this->app->instance(EsunPortfolioService::class, $service);

        $this->getJson(route('tw-stock.esun-portfolio.data', ['token' => 'test-token']))
            ->assertOk()
            ->assertJsonPath('summary.stockCount', 1)
            ->assertJsonPath('market.pollSeconds', 2)
            ->assertJsonPath('rows.0.stockNo', '2303');
    }

    public function test_data_endpoint_can_force_esun_refresh(): void
    {
        $service = Mockery::mock(EsunPortfolioService::class);
        $service->shouldReceive('snapshot')->once()->with(true)->andReturn([
            'summary' => [],
            'market' => [],
            'rows' => [],
        ]);
        $this->app->instance(EsunPortfolioService::class, $service);

        $this->getJson(route('tw-stock.esun-portfolio.data', [
            'token' => 'test-token',
            'force' => 1,
        ]))
            ->assertOk()
            ->assertJsonPath('rows', []);
    }

    public function test_quotes_endpoint_returns_requested_holdings_quotes(): void
    {
        $portfolio = Mockery::mock(EsunPortfolioService::class);
        $portfolio->shouldReceive('marketStatus')->once()->andReturn([
            'isOpen' => true,
            'label' => '台股交易時段',
            'pollSeconds' => 1,
        ]);
        $this->app->instance(EsunPortfolioService::class, $portfolio);

        $quotes = Mockery::mock(TwStockRealtimeQuoteService::class);
        $quotes->shouldReceive('quotes')->once()->with(['2303', '5285'])->andReturn([
            'servedAt' => '2026-06-24T11:45:00+08:00',
            'cacheSeconds' => 1,
            'source' => [
                'status' => 'live',
                'providers' => ['twse'],
                'label' => 'TWSE MIS',
            ],
            'quotes' => [
                '2303' => [
                    'price' => 175.0,
                    'previousClose' => 170.0,
                    'source' => 'twse',
                ],
            ],
            'missing' => [],
        ]);
        $this->app->instance(TwStockRealtimeQuoteService::class, $quotes);

        $this->getJson(route('tw-stock.esun-portfolio.quotes', [
            'token' => 'test-token',
            'codes' => '2303,5285',
        ]))
            ->assertOk()
            ->assertJsonPath('cacheSeconds', 1)
            ->assertJsonPath('source.label', 'TWSE MIS')
            ->assertJsonPath('market.pollSeconds', 1)
            ->assertJsonPath('quotes.2303.price', 175);
    }

    public function test_intraday_endpoint_returns_requested_holdings_series(): void
    {
        $quotes = Mockery::mock(TwStockRealtimeQuoteService::class);
        $quotes->shouldReceive('intradayPrices')->once()->with(['2303', '5285'])->andReturn([
            'servedAt' => '2026-07-15T10:30:00+08:00',
            'date' => '2026-07-15',
            'cacheSeconds' => 15,
            'source' => ['status' => 'live', 'label' => 'CNYES 分時'],
            'series' => [
                '2303' => [['time' => 1784080860, 'price' => 45.5]],
            ],
            'missing' => ['5285'],
        ]);
        $this->app->instance(TwStockRealtimeQuoteService::class, $quotes);

        $this->getJson(route('tw-stock.esun-portfolio.intraday', [
            'token' => 'test-token',
            'codes' => '2303,5285',
        ]))
            ->assertOk()
            ->assertJsonPath('source.label', 'CNYES 分時')
            ->assertJsonPath('series.2303.0.price', 45.5)
            ->assertJsonPath('missing.0', '5285');
    }

    public function test_history_dates_endpoint_returns_available_snapshot_dates(): void
    {
        $service = Mockery::mock(EsunPortfolioService::class);
        $service->shouldReceive('dailySnapshotDates')->once()->andReturn([
            [
                'date' => '2026-07-03',
                'capturedAt' => '2026-07-03T17:56:00+08:00',
                'costBasis' => 130000.0,
                'todayPnl' => 16000.0,
                'unrealizedPnl' => -12000.0,
            ],
        ]);
        $this->app->instance(EsunPortfolioService::class, $service);

        $this->getJson(route('tw-stock.esun-portfolio.history-dates', ['token' => 'test-token']))
            ->assertOk()
            ->assertJsonPath('dates.0.date', '2026-07-03')
            ->assertJsonPath('dates.0.costBasis', 130000)
            ->assertJsonPath('dates.0.todayPnl', 16000);
    }

    public function test_history_endpoint_returns_requested_snapshot(): void
    {
        $service = Mockery::mock(EsunPortfolioService::class);
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
        $this->app->instance(EsunPortfolioService::class, $service);

        $this->getJson(route('tw-stock.esun-portfolio.history', [
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
