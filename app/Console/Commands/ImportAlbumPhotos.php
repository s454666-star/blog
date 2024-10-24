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
    protected $description = '從 NAS 匯入相簿照片（處理所有紀錄）';

    public function handle()
    {
        // 固定演員名稱
        $actorName = '少女秩序';

        // 如果演員不存在，則創建
        $actor = Actor::firstOrCreate(
            ['actor_name' => $actorName],
            ['secondary_actor_name' => '']
        );
        $this->info('找到或創建演員: ' . $actor->actor_name);

        // 目標資料夾
        $targetDir = '/mnt/nas/b2/套圖專區/少女秩序';

        // 檢查資料夾是否存在
        if (!File::exists($targetDir)) {
            $this->error("資料夾不存在: $targetDir");
            return;
        }

        // 掃描所有立即子資料夾（相簿）
        $folders = File::directories($targetDir);

        foreach ($folders as $folder) {
            // 抓取資料夾名稱作為相簿名稱
            $albumName = basename($folder);

            // 檢查相簿是否已存在，避免重複匯入
            if (Album::where('name', $albumName)->exists()) {
                $this->info("相簿已存在，跳過: $albumName");
                continue; // 跳到下一個資料夾
            }

            // 遞迴掃描所有檔案（包括子資料夾內的檔案）
            $files = File::allFiles($folder);
            $indexSort = 1;

            // 確保有檔案才繼續處理
            if (empty($files)) {
                $this->error("資料夾內沒有檔案: $albumName");
                continue;
            }

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

            // 如果找到圖片才創建相簿
            if ($coverPath) {
                // 創建相簿，並設定封面路徑
                $album = Album::create([
                    'name' => $albumName,
                    'content' => '',
                    'cover_path' => $coverPath, // 設定封面路徑
                    'actor_id' => $actor->id
                ]);

                $this->info('創建相簿，封面: ' . $album->name);

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

                        $this->info("檔案已新增: $relativePath");
                    }
                }
            } else {
                $this->error("找不到適合的封面圖片: $albumName");
            }
        }

        $this->info('匯入完成，所有紀錄已處理完成！');
    }
}
