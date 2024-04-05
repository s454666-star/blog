<?php

namespace App\Console\Commands;

use App\Http\Controllers\GetDataController;
use Illuminate\Console\Command;

class CrawlerCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:get-data';

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
    public function handle(GetDataController $getDataController)
    {
        $getDataController->fetchData();
    }
}
