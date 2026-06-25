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
            ->assertSee('dashboardToken', false)
            ->assertSee('今日損益')
            ->assertSee('累積損益')
            ->assertSee('股票市值')
            ->assertSee('投入總成本')
            ->assertDontSee('總股數')
            ->assertSee('庫存占比')
            ->assertSee('即時價')
            ->assertSee('玉山價')
            ->assertSee('更新玉山API')
            ->assertSee('更新即時報價')
            ->assertSee('button secondary', false)
            ->assertSee('data-sort-key="unrealizedPnl"', false)
            ->assertSee('玉山成本固定基準')
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
}
