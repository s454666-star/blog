<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Actor;
use App\Models\Album;
use App\Models\AlbumPhoto;
use Illuminate\Support\Facades\File;

class ImportAlbumPhotos extends Command
{
    // Command signature
    protected $signature = 'import:album-photos';

    // Command description
    protected $description = 'Import album photos from NAS (process all records)';

    public function handle()
    {
        // Fixed actor name
        $actorName = '橙子喵酱';

        // Find or create the actor
        $actor = Actor::firstOrCreate(
            ['actor_name' => $actorName],
            ['secondary_actor_name' => '']
        );
        $this->info('Actor found or created: ' . $actor->actor_name);

        // Target directory
        $targetDir = '/mnt/nas/b2/套圖專區/橙子喵酱';

        // Check if the target directory exists
        if (!File::exists($targetDir)) {
            $this->error("Directory does not exist: $targetDir");
            return;
        }

        // Scan all immediate subdirectories (albums)
        $folders = File::directories($targetDir);

        foreach ($folders as $folder) {
            // Get the album name from the folder name
            $albumName = basename($folder);

            // Check if the album already exists to avoid duplication
            if (Album::where('name', $albumName)->exists()) {
                $this->info("Album already exists and will be skipped: $albumName");
                continue; // Skip to the next folder
            }

            // Recursively scan all files in the folder and its subfolders
            $files = File::allFiles($folder);
            $indexSort = 1;

            // Ensure there are files to process
            if ($files->isEmpty()) {
                $this->error("No files found in folder: $albumName");
                continue;
            }

            $coverPath = null;

            // Find the first jpg or jpeg file to use as the cover
            foreach ($files as $file) {
                $fileExtension = strtolower($file->getExtension());

                // Only select jpg, jpeg images as cover
                if (in_array($fileExtension, ['jpg', 'jpeg'])) {
                    $coverPath = str_replace('/mnt/nas', '', $file->getPathname());
                    break;
                }
            }

            // If a cover image is found, create the album
            if ($coverPath) {
                // Create the album with the cover path
                $album = Album::create([
                    'name' => $albumName,
                    'content' => '',
                    'cover_path' => $coverPath, // Set the cover path
                    'actor_id' => $actor->id
                ]);

                $this->info('Album created with cover: ' . $album->name);

                // Insert file records into album_photos
                foreach ($files as $file) {
                    $extension = strtolower($file->getExtension());

                    // Only process jpg, jpeg, mp4, mov files
                    if (in_array($extension, ['jpg', 'jpeg', 'mp4', 'mov'])) {
                        // Get the relative file path by removing the '/mnt/nas' prefix
                        $relativePath = str_replace('/mnt/nas', '', $file->getPathname());

                        // Insert the file into album_photos
                        AlbumPhoto::create([
                            'album_id' => $album->id,
                            'photo_path' => $relativePath,
                            'index_sort' => $indexSort++
                        ]);

                        $this->info("File added: $relativePath");
                    }
                }
            } else {
                $this->error("No suitable cover image found for album: $albumName");
            }
        }

        $this->info('Import completed successfully with all records processed!');
    }
}
