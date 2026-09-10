<?php

namespace Tests\Feature;

use App\Console\Kernel;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionMethod;
use Tests\TestCase;

class TwStockEpsGrowthScheduleTest extends TestCase
{
    public function test_eps_growth_rankings_refresh_is_scheduled_every_monday_morning(): void
    {
        $schedule = new Schedule(config('app.timezone'));
        $method = new ReflectionMethod(Kernel::class, 'schedule');
        $method->setAccessible(true);
        $method->invoke(app(Kernel::class), $schedule);

        $event = collect($schedule->events())->first(
            fn ($event): bool => str_contains($event->command, 'tw-stock:refresh-eps-growth-rankings'),
        );

        $this->assertNotNull($event);
        $this->assertSame('0 9 * * 1', $event->expression);
        $this->assertSame('tw-stock-refresh-eps-growth-rankings-weekly', $event->description);
    }
}
