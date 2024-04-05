<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SPSS\Sav\Reader;

class sav extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'command:sav';

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

    public function handle()
    {
        $filePath = "C:/Users/Public/Documents/mc/PW/1943BB91000000000000000000000000.sav";


        $reader = Reader::fromString(file_get_contents($filePath))->read();
        dd($reader);
    }
}
