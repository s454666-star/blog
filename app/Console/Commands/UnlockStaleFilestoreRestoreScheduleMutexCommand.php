<?php

namespace App\Console\Commands;

use App\Services\FilestoreRestoreScheduleLockService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class UnlockStaleFilestoreRestoreScheduleMutexCommand extends Command
{
    protected $signature = 'schedule:unlock-stale-filestore-restore
        {--force : 即使偵測到 filestore restore process 還在，也強制解鎖排程 mutex}';

    protected $description = '當 filestore restore scheduler 的背景程序停掉但 mutex 還殘留時，自動解鎖。';

    public function handle(FilestoreRestoreScheduleLockService $lockService): int
    {
        if (! $lockService->isLocked()) {
            $this->line('Filestore restore schedule mutex is not locked.');

            return self::SUCCESS;
        }

        $runningProcesses = $lockService->runningScheduledRestoreProcesses();
        if ($runningProcesses !== [] && ! $this->option('force')) {
            $pidList = implode(', ', array_map(
                static fn (array $process): string => (string) $process['pid'],
                $runningProcesses
            ));

            $this->line('Filestore restore schedule mutex kept because matching process is still running: '.$pidList);

            return self::SUCCESS;
        }

        if (! $lockService->forceRelease()) {
            $this->error('Filestore restore schedule event was not found.');

            return self::FAILURE;
        }

        Log::warning('filestore_restore_schedule_mutex_force_released', [
            'forced' => (bool) $this->option('force'),
            'running_processes' => $runningProcesses,
        ]);

        $this->warn('Released stale filestore restore schedule mutex.');

        return self::SUCCESS;
    }
}
