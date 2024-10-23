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
        protected $description = 'Import album photos from NAS (limit to 5 records)';

        public function handle()
        {
            // 固定演員名稱
            $actorName = '赤西夜夜';

            // 如果演員不存在，則創建
            $actor = Actor::firstOrCreate(['actor_name' => $actorName], ['secondary_actor_name' => '']);
            $this->info('Actor found or created: ' . $actor->actor_name);

            // 目標資料夾
            $targetDir = '/mnt/nas/b2/套圖專區/赤西夜夜';

            // 檢查資料夾是否存在
            if (!File::exists($targetDir)) {
                $this->error("Directory does not exist: $targetDir");
                return;
            }

            // 掃描資料夾
            $folders = File::directories($targetDir);

            // 只處理前5個資料夾
            $folders = array_slice($folders, 0, 5);

            foreach ($folders as $folder) {
                // 抓取資料夾名稱作為套圖名稱
                $albumName = basename($folder);

                // 掃描資料夾內的檔案
                $files = File::files($folder);
                $indexSort = 1;

                // 只處理前5個檔案
                $files = array_slice($files, 0, 5);

                // 確保有檔案才創建套圖
                if (!empty($files)) {
                    // 抓取第一個檔案作為封面
                    $firstFile = $files[0];
                    $firstFileExtension = strtolower($firstFile->getExtension());

                    // 只選取符合條件的檔案作為封面
                    if (in_array($firstFileExtension, ['jpg', 'jpeg', 'mp4', 'mov'])) {
                        $coverPath = str_replace('/mnt/nas', '', $firstFile->getPathname());

                        // 創建套圖，並設定封面路徑
                        $album = Album::create([
                                                   'name' => $albumName,
                                                   'content' => '',
                                                   'cover_path' => $coverPath, // 設定封面路徑為第一筆檔案
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
                    }
                }
            }

            $this->info('Import completed successfully with a 5-record limit per album and cover set as first file!');
        }
    }
