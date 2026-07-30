<?php

namespace Tests\Feature;

use Tests\TestCase;

class TwFuturesKlineViewportTest extends TestCase
{
    public function test_periodic_full_refresh_preserves_the_user_visible_range(): void
    {
        $view = file_get_contents(resource_path('views/tw-stock/taiex-futures-kline.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString(
            'applyTimeframe(activeTimeframe, { preserveVisibleRange: true });',
            $view,
        );
        $this->assertStringContainsString(
            'const visibleLogicalRange = preserveVisibleRange',
            $view,
        );
        $this->assertStringContainsString(
            'restoreVisibleLogicalRange(visibleLogicalRange);',
            $view,
        );
    }
}
