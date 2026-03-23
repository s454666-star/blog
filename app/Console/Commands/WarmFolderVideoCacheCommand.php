<?php

namespace App\Console\Commands;

use App\Services\FolderVideoService;
use Illuminate\Console\Command;

class WarmFolderVideoCacheCommand extends Command
{
    protected $signature = 'folder-video:warm-cache {--force : Re-probe every video even if an index entry already exists}';

    protected $description = 'Scan the folder, write duration metadata into the folder index file, and refresh ordering data.';

    public function handle(FolderVideoService $folderVideoService): int
    {
        $this->info('Scanning folder videos and writing the index file...');

        $count = $folderVideoService->warmCache((bool) $this->option('force'));

        $this->newLine();
        $this->info("Indexed {$count} videos.");
        $this->line('Root: '.$folderVideoService->rootPath());
        $this->line('Index: '.$folderVideoService->indexFilePath());

        return self::SUCCESS;
    }
}
