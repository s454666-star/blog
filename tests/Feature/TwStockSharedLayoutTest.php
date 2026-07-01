<?php

namespace Tests\Feature;

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
            'views/tw-stock/q1-financial-reports.blade.php',
            'views/tw-stock/annual-comparison.blade.php',
            'views/tw-stock/daily-prices/index.blade.php',
            'views/tw-stock/daily-prices/show.blade.php',
            'views/tw-stock/institutional-flows.blade.php',
            'views/tw-stock/upcoming-dividends.blade.php',
            'views/tw-stock/monthly-revenue-rankings.blade.php',
            'views/tw-stock/active-etf-operations.blade.php',
            'views/tw-stock/taiex-futures-kline.blade.php',
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
                "route('tw-stock.active-etf-operations.index')",
                $content,
                "{$viewPath} should link to the active ETF operations page.",
            );
            $this->assertStringContainsString(
                "route('tw-stock.monthly-revenues.index')",
                $content,
                "{$viewPath} should link to the monthly revenue rankings page.",
            );
        }
    }
}
