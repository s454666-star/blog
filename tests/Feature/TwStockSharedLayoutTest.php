<?php

namespace Tests\Feature;

use App\Support\TwStockPages;
use Tests\TestCase;

class TwStockSharedLayoutTest extends TestCase
{
    public function test_tw_stock_pages_share_the_same_shell_width_partial(): void
    {
        $partial = file_get_contents(resource_path('views/tw-stock/partials/shared-shell-width.blade.php'));

        $this->assertIsString($partial);
        $this->assertStringContainsString('--tw-stock-shell-max: 1960px', $partial);
        $this->assertStringContainsString('--tw-stock-shell-gutter: 12px', $partial);

        $viewPaths = [
            'views/tw-stock/financial-reports.blade.php',
            'views/tw-stock/annual-comparison.blade.php',
            'views/tw-stock/daily-prices/index.blade.php',
            'views/tw-stock/daily-prices/show.blade.php',
            'views/tw-stock/institutional-flows.blade.php',
            'views/tw-stock/upcoming-dividends.blade.php',
            'views/tw-stock/monthly-revenue-rankings.blade.php',
            'views/tw-stock/active-etf-operations.blade.php',
            'views/tw-stock/taiex-index-kline.blade.php',
            'views/tw-stock/eps-growth-rankings.blade.php',
        ];

        foreach ($viewPaths as $viewPath) {
            $content = file_get_contents(resource_path($viewPath));

            $this->assertIsString($content);
            $this->assertStringContainsString(
                "@include('tw-stock.partials.shared-shell-width')",
                $content,
                "{$viewPath} should use the shared tw-stock shell width.",
            );
            $this->assertStringContainsString(
                "@include('tw-stock.partials.nav'",
                $content,
                "{$viewPath} should use the shared tw-stock page switcher.",
            );
            $this->assertStringContainsString(
                "@include('tw-stock.partials.experience-head')",
                $content,
                "{$viewPath} should load the shared tw-stock theme.",
            );
            $this->assertStringContainsString('class="tw-stock-experience"', $content);
        }

        // The futures chart keeps its own look but must still be reachable from, and link to, the workspace.
        $futures = file_get_contents(resource_path('views/tw-stock/taiex-futures-kline.blade.php'));
        $this->assertIsString($futures);
        $this->assertStringContainsString("@include('tw-stock.partials.shared-shell-width')", $futures);
        $this->assertStringNotContainsString("@include('tw-stock.partials.experience-head')", $futures);
        $this->assertStringContainsString("route('tw-stock.financial-reports.index')", $futures);
    }

    public function test_shared_nav_links_every_workspace_page(): void
    {
        $nav = file_get_contents(resource_path('views/tw-stock/partials/nav.blade.php'));
        $this->assertIsString($nav);
        $this->assertStringContainsString("route('tw-stock.index')", $nav);

        $routes = array_column(TwStockPages::all(), 'route');
        foreach ([
            'tw-stock.financial-reports.index',
            'tw-stock.annual-comparison.index',
            'tw-stock.daily-prices.index',
            'tw-stock.institutional-flows.index',
            'tw-stock.upcoming-dividends.index',
            'tw-stock.monthly-revenues.index',
            'tw-stock.eps-growth-rankings.index',
            'tw-stock.active-etf-operations.index',
            'tw-stock.taiex-index.kline',
            'tw-stock.taiex-futures.kline',
        ] as $route) {
            $this->assertContains($route, $routes);
        }
    }

    public function test_every_page_has_its_own_icon_and_scene(): void
    {
        $icons = [];
        foreach (TwStockPages::all() as $key => $page) {
            $this->assertNotSame('', TwStockPages::iconSvg($key));
            $this->assertStringStartsWith('data:image/svg+xml,', TwStockPages::faviconDataUri($key));
            $icons[] = $page['icon'];
            if ($key !== 'taiex-futures-kline') {
                $this->assertNotEmpty($page['scene'], "{$key} should have an animated background scene.");
            }
        }

        $this->assertSame(count($icons), count(array_unique($icons)), 'Each page should have a distinct icon.');
    }

    public function test_portal_lists_all_workspace_pages(): void
    {
        $response = $this->get(route('tw-stock.index'))->assertOk();

        $response->assertSee('Star 台股資訊站');
        foreach (TwStockPages::all() as $page) {
            $response->assertSee(route($page['route']), false);
        }
        $response->assertSee('tw-stock-experience.css', false);
        $response->assertSee('name="tw-stock-page" content="portal"', false);
    }
}
