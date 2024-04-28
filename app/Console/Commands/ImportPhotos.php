<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Http\Controllers\PhotoImportController;

class ImportPhotos extends Command
{
    protected $signature = 'photos:import';
    protected $description = 'Import photos into the database from albums';

    public function handle()
    {
        // 解析 Controller
        $controller = resolve(PhotoImportController::class);

        // 調用 import 方法
        $result = $controller->import();

        // 輸出結果
        $this->info($result);
    }
}
