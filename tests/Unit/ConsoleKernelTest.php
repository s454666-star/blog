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

    public function test_active_etf_operations_schedule_runs_daily_at_1740(): void
    {
        $schedule = new Schedule(config('app.timezone'));
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $kernel = $this->app->make(Kernel::class);

        $method->invoke($kernel, $schedule);

        $activeEtfEvents = collect($schedule->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'tw-stock:fetch-active-etf-operations'))
            ->map(fn ($event): array => [
                'expression' => $event->expression,
                'name' => $event->description,
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'expression' => '40 17 * * *',
                'name' => 'tw-stock-fetch-active-etf-operations',
            ],
        ], $activeEtfEvents);
    }

    public function test_dashboard_token_rotation_schedule_runs_daily_without_user_override(): void
    {
        $this->app['config']->set('line.dashboard_token_rotation_schedule_enabled', true);

        $schedule = new Schedule(config('app.timezone'));
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $kernel = $this->app->make(Kernel::class);

        $method->invoke($kernel, $schedule);

        $events = collect($schedule->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'line:rotate-dashboard-tokens'))
            ->map(fn ($event): array => [
                'expression' => $event->expression,
                'name' => $event->description,
                'user' => $event->user,
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'expression' => '0 8 * * *',
                'name' => 'line-rotate-esun-dashboard-token',
                'user' => null,
            ],
            [
                'expression' => '5 8 * * *',
                'name' => 'line-rotate-yuanta-dashboard-token',
                'user' => null,
            ],
        ], $events);
    }

    public function test_yuanta_daily_snapshot_schedule_runs_weekdays_after_close_and_after_broker_finalization(): void
    {
        $schedule = new Schedule(config('app.timezone'));
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $kernel = $this->app->make(Kernel::class);

        $method->invoke($kernel, $schedule);

        $events = collect($schedule->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'yuanta:portfolio-capture-daily'))
            ->map(fn ($event): array => [
                'expression' => $event->expression,
                'name' => $event->description,
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'expression' => '55 17 * * 1-5',
                'name' => 'yuanta-portfolio-capture-daily',
            ],
            [
                'expression' => '30 21 * * 1-5',
                'name' => 'yuanta-portfolio-capture-daily-final',
            ],
        ], $events);
    }

    public function test_esun_daily_snapshot_schedule_runs_weekdays_after_close_and_after_broker_finalization(): void
    {
        $schedule = new Schedule(config('app.timezone'));
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $kernel = $this->app->make(Kernel::class);

        $method->invoke($kernel, $schedule);

        $events = collect($schedule->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'esun:portfolio-capture-daily'))
            ->map(fn ($event): array => [
                'expression' => $event->expression,
                'name' => $event->description,
            ])
            ->values()
            ->all();

        $this->assertSame([
            [
                'expression' => '56 17 * * 1-5',
                'name' => 'esun-portfolio-capture-daily',
            ],
            [
                'expression' => '31 21 * * 1-5',
                'name' => 'esun-portfolio-capture-daily-final',
            ],
        ], $events);
    }
}
