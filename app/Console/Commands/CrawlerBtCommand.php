<?php

namespace App\Console\Commands;

use App\Http\Controllers\GetBtDataController;
use App\Http\Controllers\GetDataController;
use Illuminate\Console\Command;

class CrawlerBtCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'get-bt';

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
    public function handle(GetBtDataController $getBtDataController): int
    {
        \Log::info('Command get-bt started');

        try {
            $getBtDataController->fetchData();
            \Log::info('Command get-bt executed successfully');
            return self::SUCCESS;
        } catch (\Exception $e) {
            \Log::error('Command get-bt failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
