<?php

namespace Tests\Unit;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
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

    public function test_monthly_revenue_schedule_runs_at_evening_and_night(): void
    {
        $schedule = new Schedule(config('app.timezone'));
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $kernel = $this->app->make(Kernel::class);

        $method->invoke($kernel, $schedule);

        $monthlyRevenueEvents = collect($schedule->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'tw-stock:fetch-monthly-revenues'))
            ->map(fn ($event): array => [
                'expression' => $event->expression,
                'name' => $event->description,
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'expression' => '30 18 * * 1-5',
                'name' => 'tw-stock-fetch-monthly-revenues-evening',
            ],
            [
                'expression' => '0 22 * * 1-5',
                'name' => 'tw-stock-fetch-monthly-revenues-night',
            ],
        ], $monthlyRevenueEvents);
    }
}
