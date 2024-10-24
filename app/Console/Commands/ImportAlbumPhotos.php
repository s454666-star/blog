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
            // 固定演員名稱
            $actorName = 'JVID';

            // 如果演員不存在，則創建
            $actor = Actor::firstOrCreate(['actor_name' => $actorName], ['secondary_actor_name' => '']);
            $this->info('Actor found or created: ' . $actor->actor_name);

            // 目標資料夾
            $targetDir = '/mnt/nas/b2/套圖專區/JVID';

            // 檢查資料夾是否存在
            if (!File::exists($targetDir)) {
                $this->error("Directory does not exist: $targetDir");
                return;
            }

            // 掃描所有資料夾
            $folders = File::directories($targetDir);

            foreach ($folders as $folder) {
                // 抓取資料夾名稱作為套圖名稱
                $albumName = basename($folder);

                // 掃描資料夾內的檔案
                $files = File::files($folder);
                $indexSort = 1;

                // 確保有檔案才創建套圖
                if (!empty($files)) {
                    $coverPath = null;

                    // 找到第一個符合條件的圖片作為封面
                    foreach ($files as $file) {
                        $fileExtension = strtolower($file->getExtension());

                        // 只選取 jpg, jpeg 圖片作為封面
                        if (in_array($fileExtension, ['jpg', 'jpeg'])) {
                            $coverPath = str_replace('/mnt/nas', '', $file->getPathname());
                            break;
                        }
                    }

                    // 如果找到圖片才創建專輯
                    if ($coverPath) {
                        // 創建套圖，並設定封面路徑
                        $album = Album::create([
                                                   'name' => $albumName,
                                                   'content' => '',
                                                   'cover_path' => $coverPath, // 設定封面路徑
                                                   'actor_id' => $actor->id
                                               ]);

                        $this->info('Album created with cover: ' . $album->name);

                        // 插入檔案資料
                        foreach ($files as $file) {
                            $extension = strtolower($file->getExtension());

                            // 僅抓取 jpg, jpeg, mp4, mov 檔案
                            if (in_array($extension, ['jpg', 'jpeg', 'mp4', 'mov'])) {
                                // 檔案路徑
                                $relativePath = str_replace('/mnt/nas', '', $file->getPathname());

                                // 將檔案插入 album_photos
                                AlbumPhoto::create([
                                                       'album_id' => $album->id,
                                                       'photo_path' => $relativePath,
                                                       'index_sort' => $indexSort++
                                                   ]);

                                $this->info("File added: $relativePath");
                            }
                        }
                    } else {
                        $this->error("No image found for album: $albumName");
                    }
                }
            }

            $this->info('Import completed successfully with all records processed!');
        }
    }
