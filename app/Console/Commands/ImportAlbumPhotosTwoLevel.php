<?php

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use App\Models\Actor;
    use App\Models\Album;
    use App\Models\AlbumPhoto;
    use Illuminate\Support\Facades\File;

    class ImportAlbumPhotosTwoLevel extends Command
    {
        // Command signature
        protected $signature = 'import:album-photos-two-level';

        // Command description
        protected $description = 'Import album photos from NAS with two-level actors';

        public function handle()
        {
            // 固定主演員名稱
            $mainActorName = '西瓜少女';

            // 如果主演員不存在，則創建
            $mainActor = Actor::firstOrCreate(
                ['actor_name' => $mainActorName],
                ['secondary_actor_name' => '']
            );
            $this->info('Main Actor found or created: ' . $mainActor->actor_name);

            // 目標資料夾
            $targetDir = '/mnt/nas/b2/套圖專區/西瓜少女';

            // 檢查資料夾是否存在
            if (!File::exists($targetDir)) {
                $this->error("Directory does not exist: $targetDir");
                return;
            }

            // 掃描第二層資料夾（次演員）
            $secondaryActorsFolders = File::directories($targetDir);

            foreach ($secondaryActorsFolders as $secondaryFolder) {
                // 抓取次演員名稱
                $secondaryActorName = basename($secondaryFolder);

                // 如果次演員不存在，則創建
                $secondaryActor = Actor::firstOrCreate(
                    [
                        'actor_name' => $mainActorName,
                        'secondary_actor_name' => $secondaryActorName
                    ],
                    []
                );
                $this->info('Secondary Actor found or created: ' . $secondaryActor->secondary_actor_name);

                // 掃描次演員資料夾內的套圖資料夾
                $albumsFolders = File::directories($secondaryFolder);

                foreach ($albumsFolders as $albumFolder) {
                    // 抓取套圖名稱
                    $albumName = basename($albumFolder);

                    // 掃描套圖內的檔案
                    $files = File::files($albumFolder);
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
                                                       'actor_id' => $secondaryActor->id
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
            }

            $this->info('Import completed successfully with all records processed!');
        }
    }
