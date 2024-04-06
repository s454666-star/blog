<?php

namespace App\Console\Commands;

use App\Http\Controllers\GetBtDataController;
use App\Http\Controllers\GetDataController;
use App\Http\Controllers\TelegramController;
use Illuminate\Console\Command;

class CrawlerTgCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:get-tg';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(TelegramController $telegramController)
    {
        $telegramController->fetchData();
    }
}
