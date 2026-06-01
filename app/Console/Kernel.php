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
        if (config('bt.crawler_enabled', true)) {
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
        }

        $schedule->command('article:extract-number')
            ->dailyAt('02:00')
            ->name('article-extract-number')
            ->withoutOverlapping(720)
            ->runInBackground();

        $schedule->command('tw-stock:fetch-institutional-flows')
            ->dailyAt('16:00')
            ->name('tw-stock-fetch-institutional-flows')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_institutional_flows.log'));

        $schedule->command('tw-stock:fetch-upcoming-dividends')
            ->dailyAt('16:20')
            ->name('tw-stock-fetch-upcoming-dividends')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_upcoming_dividends.log'));

        $schedule->command('tw-stock:fetch-daily-prices --latest')
            ->dailyAt('15:00')
            ->name('tw-stock-fetch-daily-prices')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_daily_prices.log'));

        $schedule->command('tw-stock:fetch-q1-financial-reports --year=2026 --quarter=1 --market-data-only --min-volume-lots=1000 --sleep-ms=80 --skip-non-trading-day --keep-missing-market-data')
            ->dailyAt('15:10')
            ->name('tw-stock-refresh-q1-market-data')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_q1_market_data.log'));

        $schedule->command('tw-stock:refresh-annual-financial-comparisons --context-year=2026 --start-year=2020 --end-year=2025')
            ->dailyAt('15:25')
            ->name('tw-stock-refresh-annual-financial-comparisons')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_annual_financial_comparisons.log'));

        $schedule->command('tw-stock:fetch-daily-turnover-rates --skip-non-trading-day')
            ->dailyAt('15:30')
            ->name('tw-stock-fetch-daily-turnover-rates')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_daily_turnover_rates.log'));

        $schedule->command('tw-stock:fetch-daily-prices --latest')
            ->dailyAt('16:05')
            ->name('tw-stock-fetch-daily-prices-late')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_daily_prices.log'));

        $schedule->command('tw-stock:fetch-q1-financial-reports --year=2026 --quarter=1 --market-data-only --min-volume-lots=1000 --sleep-ms=80 --skip-non-trading-day --keep-missing-market-data')
            ->dailyAt('16:15')
            ->name('tw-stock-refresh-q1-market-data-late')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_q1_market_data.log'));

        $schedule->command('tw-stock:refresh-annual-financial-comparisons --context-year=2026 --start-year=2020 --end-year=2025')
            ->dailyAt('16:35')
            ->name('tw-stock-refresh-annual-financial-comparisons-late')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_annual_financial_comparisons.log'));

        $schedule->command('tw-stock:fetch-upcoming-dividends --prices-only')
            ->dailyAt('16:50')
            ->name('tw-stock-refresh-upcoming-dividend-prices')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_upcoming_dividends.log'));

        $schedule->command('tw-stock:fetch-taiex-futures-hourly')
            ->hourly()
            ->name('tw-stock-fetch-taiex-futures-hourly')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_futures_hourly_prices.log'));

        $schedule->command('tw-stock:refresh-company-profiles')
            ->dailyAt('14:40')
            ->name('tw-stock-refresh-company-profiles')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_company_profiles.log'));

        $schedule->command('tw-stock:fetch-q1-financial-reports --year=2026 --quarter=1 --min-volume-lots=1000 --sleep-ms=80 --skip-non-trading-day')
            ->dailyAt('16:45')
            ->weekdays()
            ->name('tw-stock-fetch-q1-financial-reports')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_q1_financial_reports.log'))
            ->when(fn (): bool => now(config('app.timezone'))->lessThanOrEqualTo(
                \Carbon\CarbonImmutable::parse('2026-05-15 23:59:59', config('app.timezone'))
            ));

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

        $schedule->command('video:delete-exact-duplicates', [
            'C:\Users\User\Downloads\Video',
            '--recursive' => '1',
        ])
            ->everyTenMinutes()
            ->name('video-delete-download-exact-duplicates')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/download_video_exact_duplicates.log'));

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
