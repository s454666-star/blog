<?php

namespace App\Console\Commands;

use App\Services\FolderVideoService;
use Illuminate\Console\Command;

class WarmFolderVideoCacheCommand extends Command
{
    protected $signature = 'folder-video:warm-cache
        {--force : Re-probe every video even if an index entry already exists}
        {--previews : Also build low-resolution preview clips}
        {--thumbnails : Also build static thumbnail images}
        {--preview-limit=0 : Maximum preview clips to build; 0 means no limit}';

    protected $description = 'Scan the folder, write duration metadata into the folder index file, refresh ordering data, and optionally build preview clips.';

    public function handle(FolderVideoService $folderVideoService): int
    {
        $this->info('Scanning folder videos and writing the index file...');

        $count = $folderVideoService->warmCache((bool) $this->option('force'));

        $this->newLine();
        $this->info("Indexed {$count} videos.");
        $this->line('Root: '.$folderVideoService->rootPath());
        $this->line('Index: '.$folderVideoService->indexFilePath());

        if ((bool) $this->option('previews')) {
            $this->newLine();
            $this->info('Building preview clips...');

            $previewCount = $folderVideoService->warmPreviewCache((int) $this->option('preview-limit'));

            $this->info("Preview cache ready for {$previewCount} videos.");
            $this->line('Preview cache: '.$folderVideoService->previewCachePath());
        }

        if ((bool) $this->option('thumbnails')) {
            $this->newLine();
            $this->info('Building thumbnails...');

            $thumbnailCount = $folderVideoService->warmThumbnailCache((int) $this->option('preview-limit'));

            $this->info("Thumbnail cache ready for {$thumbnailCount} videos.");
            $this->line('Thumbnail cache: '.$folderVideoService->thumbnailCachePath());
        }

        return self::SUCCESS;
    }
}
