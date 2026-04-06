<?php

namespace Tests\Feature;

use App\Services\FilestoreRestoreScheduleLockService;
use Mockery;
use Tests\TestCase;

class UnlockStaleFilestoreRestoreScheduleMutexCommandTest extends TestCase
{
    public function test_it_reports_when_mutex_is_not_locked(): void
    {
        $service = Mockery::mock(FilestoreRestoreScheduleLockService::class);
        $service->shouldReceive('isLocked')->once()->andReturn(false);
        $service->shouldNotReceive('runningScheduledRestoreProcesses');
        $service->shouldNotReceive('forceRelease');

        $this->instance(FilestoreRestoreScheduleLockService::class, $service);

        $this->artisan('schedule:unlock-stale-filestore-restore')
            ->expectsOutput('Filestore restore schedule mutex is not locked.')
            ->assertExitCode(0);
    }

    public function test_it_keeps_mutex_when_matching_process_is_still_running(): void
    {
        $service = Mockery::mock(FilestoreRestoreScheduleLockService::class);
        $service->shouldReceive('isLocked')->once()->andReturn(true);
        $service->shouldReceive('runningScheduledRestoreProcesses')->once()->andReturn([
            ['pid' => 43210, 'command_line' => 'php artisan filestore:restore-to-bot --all --pending-session-limit=500'],
        ]);
        $service->shouldNotReceive('forceRelease');

        $this->instance(FilestoreRestoreScheduleLockService::class, $service);

        $this->artisan('schedule:unlock-stale-filestore-restore')
            ->expectsOutput('Filestore restore schedule mutex kept because matching process is still running: 43210')
            ->assertExitCode(0);
    }

    public function test_it_releases_stale_mutex_when_process_is_not_running(): void
    {
        $service = Mockery::mock(FilestoreRestoreScheduleLockService::class);
        $service->shouldReceive('isLocked')->once()->andReturn(true);
        $service->shouldReceive('runningScheduledRestoreProcesses')->once()->andReturn([]);
        $service->shouldReceive('forceRelease')->once()->andReturn(true);

        $this->instance(FilestoreRestoreScheduleLockService::class, $service);

        $this->artisan('schedule:unlock-stale-filestore-restore')
            ->expectsOutput('Released stale filestore restore schedule mutex.')
            ->assertExitCode(0);
    }
}
