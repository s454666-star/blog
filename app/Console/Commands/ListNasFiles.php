<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use FFMpeg\FFMpeg;
use FFMpeg\Coordinate\TimeCode;
use App\Models\FileScreenshot;

class ListNasFiles extends Command
{
    protected $signature = 'nas:latest-mp4-screenshots';
    protected $description = '從 R:\\FC2-2023\\精選 資料夾中擷取所有 .mp4 檔案，建立其名稱的資料夾，並每分鐘從影片中擷取一張截圖，如果資料庫中尚無紀錄。';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $directory = 'R:\FC2-2023\精選';
        $domain = 'https://' . env('DOMAIN', 'mystar.monster');

        // 檢查資料夾是否存在
        if (!File::exists($directory)) {
            $this->error('資料夾不存在：' . $directory);
            return 1;
        }

        // 取得所有 .mp4 檔案
        $files = File::files($directory);

        // 過濾出 .mp4 檔案
        $mp4Files = array_filter($files, function ($file) {
            return strtolower($file->getExtension()) === 'mp4';
        });

        if (empty($mp4Files)) {
            $this->info('資料夾中沒有找到 .mp4 檔案。');
            return 0;
        }

        // 逐一處理每個 .mp4 檔案
        foreach ($mp4Files as $file) {
            $fileName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $filePath = $file->getRealPath();

            // 檢查檔案是否已存在於資料表中
            if (FileScreenshot::where('file_name', $fileName)->exists()) {
                $this->info('檔案已存在於資料庫中：' . $fileName);
                continue; // 如果已存在，跳過這個檔案
            }

            // 依照檔案名稱建立新資料夾
            $newDirectory = $directory . '\\' . $fileName;

            if (!File::exists($newDirectory)) {
                File::makeDirectory($newDirectory);
                $this->info('已建立資料夾：' . $newDirectory);
            } else {
                $this->info('資料夾已存在：' . $newDirectory);
            }

            // 使用 FFMpeg 擷取影片中的每分鐘圖片
            $screenshotPaths = $this->captureScreenshots($filePath, $newDirectory, $domain, $fileName);

            // 轉換本地路徑為 URL 格式
            $urlFilePath = str_replace(
                ['R:\FC2-2023\精選', '\\'],
                [$domain . '/fhd/FC2-2023/%E7%B2%BE%E9%81%B8', '/'],
                $filePath
            );

            // 將檔案資訊寫入資料表
            FileScreenshot::create([
                'file_name' => $fileName,
                'file_path' => $urlFilePath,
                'type' => '2',
                'screenshot_paths' => implode(',', $screenshotPaths),
            ]);

            $this->info('檔案和截圖已儲存到資料庫：' . $fileName);
        }

        return 0;
    }

    private function captureScreenshots($filePath, $outputDirectory, $domain, $fileName)
    {
        $ffmpeg = FFMpeg::create();
        $video = $ffmpeg->open($filePath);

        // 取得影片總長度（以秒為單位）
        $duration = $video->getFFProbe()->format($filePath)->get('duration');

        // 計算影片總分鐘數，並確定擷取的張數
        $totalMinutes = ceil($duration / 60);

        $screenshotPaths = [];

        for ($i = 0; $i < $totalMinutes; $i++) {
            $time = $i * 60; // 每分鐘擷取一次
            $screenshotPath = $outputDirectory . '\\screenshot_' . $i . '.jpg';

            try {
                // 擷取當前時間點的畫面
                $video->frame(TimeCode::fromSeconds($time))
                    ->save($screenshotPath);

                // 將本地圖片路徑轉換成 URL
                $urlScreenshotPath = $domain . '/fhd/FC2-2023/%E7%B2%BE%E9%81%B8/' . $fileName . '/screenshot_' . $i . '.jpg';

                $screenshotPaths[] = $urlScreenshotPath;
                $this->info('截圖已儲存：' . $urlScreenshotPath);

            } catch (\Exception $e) {
                $this->error('擷取截圖失敗，跳過此時間點：' . $time . ' 秒，錯誤訊息：' . $e->getMessage());
                continue; // 跳過這張截圖，繼續處理下一張
            }
        }

        return $screenshotPaths;
    }
}
