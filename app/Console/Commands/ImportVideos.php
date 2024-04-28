<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\VideoController;

class ImportVideos extends Command
{
    protected $signature   = 'import:videos';
    protected $description = 'Trigger video import via controller directly';

    public function handle()
    {
        $controller = new VideoController();
        $response   = $controller->importVideos();  // Direct method call

        $this->info('Import process completed');
        $this->info('Response: ' . json_encode($response->getData()));
    }
}
