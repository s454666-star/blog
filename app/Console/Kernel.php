<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Content jobs
        $schedule->command('get-bt')
            ->cron('0 5,17 * * *')
            ->name('blog-get-bt')
            ->withoutOverlapping(720)
            ->runInBackground()
            ->onSuccess(function () {
                \Log::info('Command get-bt executed successfully');
            })
            ->onFailure(function () {
                \Log::error('Command get-bt failed');
            });

        $schedule->command('article:extract-number')
            ->dailyAt('02:00')
            ->name('article-extract-number')
            ->withoutOverlapping(720)
            ->runInBackground();

        // Telegram / filestore
        $schedule->command('filestore:restore-to-bot', [
            '--all' => true,
            '--pending-session-limit' => 500,
            '--base-uri' => 'http://127.0.0.1:8001',
            '--target-bot-username' => config('telegram.backup_restore_bot_username', 'new_files_star_bot'),
            '--worker-env' => base_path('storage/app/telegram-filestore-local-workers/worker.env'),
        ])
            ->dailyAt('01:00')
            ->name('blog-filestore-restore-pending-sessions')
            ->withoutOverlapping(1440)
            ->runInBackground();

        $schedule->command('telegram:ensure-backup-restore-webhook')
            ->dailyAt('04:10')
            ->name('telegram-ensure-backup-restore-webhook')
            ->withoutOverlapping()
            ->runInBackground();

        $schedule->command('filestore:cleanup-stale-sessions')
            ->hourly()
            ->name('filestore-cleanup-stale-sessions')
            ->runInBackground()
            ->withoutOverlapping();

        $schedule->exec($this->hiddenBatchCommand('ensure_tg_scan_group_media.bat'))
            ->hourlyAt(24)
            ->name('blog-ensure-tg-scan-group-media');

        // $schedule->exec($this->hiddenBatchCommand('clear_project_logs.bat'))
        //     ->dailyAt('07:00')
        //     ->name('blog-project-log-cleanup');
    }

    private function hiddenBatchCommand(string $batchFileName): string
    {
        $hiddenRunnerPath = base_path('scripts\\run_hidden_task.vbs');
        $batchPath = base_path('scripts\\' . $batchFileName);

        return sprintf('wscript.exe "%s" "%s"', $hiddenRunnerPath, $batchPath);
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
