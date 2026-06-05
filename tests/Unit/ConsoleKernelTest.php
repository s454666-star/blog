<?php

namespace Tests\Unit;

use App\Console\Kernel;
use ReflectionMethod;
use Tests\TestCase;

class ConsoleKernelTest extends TestCase
{
    public function test_annual_comparison_schedule_defaults_to_windows_only(): void
    {
        $this->app['config']->set('tw_stock.annual_financial_comparisons_schedule_enabled', null);

        $method = new ReflectionMethod(Kernel::class, 'shouldScheduleTwStockAnnualFinancialComparisons');
        $kernel = $this->app->make(Kernel::class);

        $this->assertTrue($method->invoke($kernel, 'Windows'));
        $this->assertFalse($method->invoke($kernel, 'Linux'));
    }

    public function test_annual_comparison_schedule_can_be_overridden(): void
    {
        $method = new ReflectionMethod(Kernel::class, 'shouldScheduleTwStockAnnualFinancialComparisons');
        $kernel = $this->app->make(Kernel::class);

        $this->app['config']->set('tw_stock.annual_financial_comparisons_schedule_enabled', true);
        $this->assertTrue($method->invoke($kernel, 'Linux'));

        $this->app['config']->set('tw_stock.annual_financial_comparisons_schedule_enabled', 'false');
        $this->assertFalse($method->invoke($kernel, 'Windows'));
    }
}
