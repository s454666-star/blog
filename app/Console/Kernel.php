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

        if ($this->shouldScheduleTwStockAnnualFinancialComparisons()) {
            $schedule->command('tw-stock:sync-annual-financial-comparison-prices --context-year=2026')
                ->dailyAt('15:25')
                ->name('tw-stock-sync-annual-financial-comparison-prices')
                ->withoutOverlapping(180)
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/tw_stock_annual_financial_comparisons.log'));
        }

        $schedule->command('tw-stock:fetch-daily-turnover-rates --skip-non-trading-day')
            ->dailyAt('15:30')
            ->name('tw-stock-fetch-daily-turnover-rates')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_daily_turnover_rates.log'));

        $schedule->command('tw-stock:fetch-monthly-revenues --skip-outside-window')
            ->dailyAt('18:30')
            ->weekdays()
            ->name('tw-stock-fetch-monthly-revenues-evening')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_monthly_revenues.log'));

        $schedule->command('tw-stock:fetch-daily-turnover-rates --recent-days=10 --skip-non-trading-day --sleep-ms=80')
            ->dailyAt('17:10')
            ->name('tw-stock-fetch-daily-turnover-rates-late')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_daily_turnover_rates.log'));

        $schedule->command('tw-stock:fetch-active-etf-operations --backfill-days=31 --sleep-ms=450')
            ->dailyAt('17:40')
            ->name('tw-stock-fetch-active-etf-operations')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_active_etf_operations.log'));

        $schedule->command('yuanta:portfolio-capture-daily')
            ->dailyAt('17:55')
            ->weekdays()
            ->name('yuanta-portfolio-capture-daily')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/yuanta_portfolio_daily_snapshots.log'));

        $schedule->command('yuanta:portfolio-capture-daily')
            ->dailyAt('21:30')
            ->weekdays()
            ->name('yuanta-portfolio-capture-daily-final')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/yuanta_portfolio_daily_snapshots.log'));

        if (config('line.dashboard_token_rotation_schedule_enabled', false)) {
            $schedule->command('line:rotate-dashboard-tokens esun')
                ->dailyAt('08:00')
                ->name('line-rotate-esun-dashboard-token')
                ->withoutOverlapping(30)
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/dashboard_token_rotation.log'));

            $schedule->command('line:rotate-dashboard-tokens yuanta')
                ->dailyAt('08:05')
                ->name('line-rotate-yuanta-dashboard-token')
                ->withoutOverlapping(30)
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/dashboard_token_rotation.log'));
        }

        $schedule->command('esun:portfolio-capture-daily')
            ->dailyAt('17:56')
            ->weekdays()
            ->name('esun-portfolio-capture-daily')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/esun_portfolio_daily_snapshots.log'));

        $schedule->command('esun:portfolio-capture-daily')
            ->dailyAt('21:31')
            ->weekdays()
            ->name('esun-portfolio-capture-daily-final')
            ->withoutOverlapping(30)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/esun_portfolio_daily_snapshots.log'));

        $schedule->command('tw-stock:fetch-monthly-revenues --skip-outside-window')
            ->dailyAt('22:00')
            ->weekdays()
            ->name('tw-stock-fetch-monthly-revenues-night')
            ->withoutOverlapping(180)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_monthly_revenues.log'));

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

        if ($this->shouldScheduleTwStockAnnualFinancialComparisons()) {
            $schedule->command('tw-stock:sync-annual-financial-comparison-prices --context-year=2026')
                ->dailyAt('16:35')
                ->name('tw-stock-sync-annual-financial-comparison-prices-late')
                ->withoutOverlapping(180)
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/tw_stock_annual_financial_comparisons.log'));
        }

        $schedule->command('tw-stock:fetch-upcoming-dividends --prices-only')
            ->dailyAt('16:50')
            ->name('tw-stock-refresh-upcoming-dividend-prices')
            ->withoutOverlapping(120)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_stock_upcoming_dividends.log'));

        $schedule->command('tw-stock:fetch-taiex-futures-hourly --interval=5 --from=' . now(config('app.timezone'))->subDays(7)->toDateString() . ' --to=' . now(config('app.timezone'))->addDays(3)->toDateString() . ' --bars=12000 --delay-seconds=10')
            ->everyFiveMinutes()
            ->name('tw-stock-fetch-taiex-futures-5-minute')
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_futures_5min_prices.log'));

        $schedule->command('tw-stock:fetch-taiex-futures-hourly --interval=15 --from=' . now(config('app.timezone'))->subDays(7)->toDateString() . ' --to=' . now(config('app.timezone'))->addDays(3)->toDateString() . ' --bars=4800 --delay-seconds=35')
            ->everyFifteenMinutes()
            ->name('tw-stock-fetch-taiex-futures-15-minute')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_futures_15min_prices.log'));

        $schedule->command('tw-stock:notify-taiex-futures-line')
            ->everyFiveMinutes()
            ->name('tw-stock-notify-taiex-futures-line')
            ->withoutOverlapping(10)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_futures_line_alerts.log'))
            ->when(fn (): bool => filter_var(config('line.taiex_futures_notify_enabled', true), FILTER_VALIDATE_BOOL));

        $this->scheduleTaiexFuturesOpeningRetries($schedule);

        $schedule->command('tw-stock:fetch-taiex-futures-hourly --interval=60 --from=' . now(config('app.timezone'))->subDays(7)->toDateString() . ' --to=' . now(config('app.timezone'))->addDays(3)->toDateString())
            ->hourlyAt(10)
            ->name('tw-stock-fetch-taiex-futures-hourly')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_futures_hourly_prices.log'));

        $schedule->command('tw-stock:fetch-taiex-futures-daily --from=' . now(config('app.timezone'))->subDays(45)->toDateString())
            ->dailyAt('14:10')
            ->weekdays()
            ->name('tw-stock-fetch-taiex-futures-daily')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_futures_daily_prices.log'));

        $schedule->command('tw-stock:fetch-taiex-futures-daily --from=' . now(config('app.timezone'))->subDays(45)->toDateString())
            ->dailyAt('16:10')
            ->weekdays()
            ->name('tw-stock-fetch-taiex-futures-daily-late')
            ->withoutOverlapping(60)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/tw_futures_daily_prices.log'));

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

        $schedule->command('video:delete-exact-duplicates', [
            'C:\Users\User\Downloads\Video',
            '--recursive' => '1',
        ])
            ->everyTenMinutes()
            ->name('video-delete-download-exact-duplicates')
            ->withoutOverlapping(15)
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/download_video_exact_duplicates.log'));

        if (config('crawler.85sugarbaby.enabled', true)) {
            $schedule->command('crawler:85sugarbaby-import --headless --source=85sugarbaby_active_flow --limit=20 --age-min=18 --age-max=22 --areas=台北,新北 --timeout=45')
                ->everyThirtySeconds()
                ->name('crawler-85sugarbaby-import-30s')
                ->withoutOverlapping(1)
                ->runInBackground()
                ->appendOutputTo(storage_path('logs/crawler_85sugarbaby_import.log'))
                ->onFailure(function () {
                    \Log::error('scheduler crawler:85sugarbaby-import failed');
                });
        }

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

    private function scheduleTaiexFuturesOpeningRetries(Schedule $schedule): void
    {
        $settings = [
            ['interval' => '5', 'bars' => '12000', 'log' => 'tw_futures_5min_prices.log'],
            ['interval' => '15', 'bars' => '4800', 'log' => 'tw_futures_15min_prices.log'],
        ];

        foreach ([
            ['cron' => '47,52,57 8 * * 1-5', 'name' => 'day-open'],
            ['cron' => '2,7,12 15 * * 1-5', 'name' => 'night-open'],
        ] as $window) {
            foreach ($settings as $setting) {
                $schedule->command('tw-stock:fetch-taiex-futures-hourly --interval=' . $setting['interval'] . ' --from=' . now(config('app.timezone'))->subDays(3)->toDateString() . ' --to=' . now(config('app.timezone'))->addDays(3)->toDateString() . ' --bars=' . $setting['bars'])
                    ->cron($window['cron'])
                    ->name('tw-stock-fetch-taiex-futures-' . $setting['interval'] . '-minute-' . $window['name'] . '-retry')
                    ->withoutOverlapping(10)
                    ->runInBackground()
                    ->appendOutputTo(storage_path('logs/' . $setting['log']));
            }
        }
    }

    private function shouldScheduleTwStockAnnualFinancialComparisons(?string $osFamily = null): bool
    {
        $configured = config('tw_stock.annual_financial_comparisons_schedule_enabled');

        if ($configured !== null) {
            if (is_bool($configured)) {
                return $configured;
            }

            return filter_var($configured, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
        }

        return ($osFamily ?? PHP_OS_FAMILY) === 'Windows';
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
