<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $restoreTargetBotUsername = config('telegram.backup_restore_bot_username', 'new_files_star_bot');
        $restoreWorkerEnvPath = str_replace('\\', '/', base_path('storage/app/telegram-filestore-local-workers/worker.env'));

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
        $schedule->command('schedule:unlock-stale-filestore-restore')
            ->everyMinute()
            ->name('schedule-unlock-stale-filestore-restore');

        $schedule->command(sprintf(
            'filestore:restore-to-bot --all --pending-session-limit=500 --base-uri=http://127.0.0.1:8001 --target-bot-username=%s --worker-env=%s',
            $restoreTargetBotUsername,
            $restoreWorkerEnvPath
        ))
            ->dailyAt('01:00')
            ->name('blog-filestore-restore-pending-sessions')
            ->withoutOverlapping(1440)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/filestore_restore_scheduler.log'))
            ->onSuccess(function () {
                \Log::info('Scheduled filestore restore completed successfully');
            })
            ->onFailure(function () {
                \Log::error('Scheduled filestore restore failed');
            });

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

        $schedule->command('image:ingest-telegram-downloads')
            ->everyFiveMinutes()
            ->name('image-ingest-telegram-downloads')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/telegram_image_ingest.log'));

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
