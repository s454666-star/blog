<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('command:get-bt')->dailyAt('11:30')->onSuccess(function () {
            \Log::info('Command get-bt executed successfully');
        })->onFailure(function () {
            \Log::error('Command get-bt failed');
        });

//        $schedule->command('photos:import')
//            ->dailyAt('03:00')
//            ->appendOutputTo(storage_path('logs/schedule.log'));
//
//        $schedule->command('import:videos')
//            ->dailyAt('04:30')
//            ->appendOutputTo(storage_path('logs/schedule.log'));
//
//        $schedule->command('video:generate-thumbnails')
//            ->everyFiveMinutes()
//            ->appendOutputTo(storage_path('logs/schedule.log'));
//

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
