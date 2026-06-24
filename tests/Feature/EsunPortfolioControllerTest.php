<?php

namespace Tests\Feature;

use App\Services\EsunPortfolioService;
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
            ->assertCookie('esun_portfolio_access')
            ->assertSee('玉山庫存即時看板')
            ->assertSee('apiUrl', false)
            ->assertSee('dashboardToken', false)
            ->assertSee('今日損益')
            ->assertSee('累積損益')
            ->assertSee('股票市值')
            ->assertSee('庫存占比')
            ->assertSee('data-sort-key="unrealizedPnl"', false)
            ->assertSee('開盤每 2 秒刷新畫面')
            ->assertDontSee('庫存批次明細');
    }

    public function test_dashboard_allows_return_visit_with_access_cookie(): void
    {
        $this->withoutMiddleware(\Illuminate\Cookie\Middleware\EncryptCookies::class);

        $service = Mockery::mock(EsunPortfolioService::class);
        $service->shouldReceive('marketStatus')->once()->andReturn([
            'isOpen' => true,
            'label' => '台股交易時段',
            'pollSeconds' => 2,
        ]);
        $this->app->instance(EsunPortfolioService::class, $service);

        $cookieValue = hash_hmac('sha256', 'test-token', (string) config('app.key'));

        $this
            ->withCookie('esun_portfolio_access', $cookieValue)
            ->get(route('tw-stock.esun-portfolio.index'))
            ->assertOk()
            ->assertSee('玉山庫存即時看板');
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
}
