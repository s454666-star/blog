<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use FFMpeg\FFMpeg;
use FFMpeg\Coordinate\TimeCode;
use App\Models\FileScreenshot;

class ListNasFiles extends Command
{
    protected $signature   = 'nas:latest-mp4-screenshots';
    protected $description = 'Retrieve all .mp4 files in Z:\\FC2-2024\\精選 directory, create folders with their names, and capture 60 screenshots from each video if not already in the database.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $directory = 'Z:\\FC2-2023\\精選';
        $domain    = 'https://' . env('DOMAIN', 'mystar.monster');

        // 檢查資料夾是否存在
        if (!File::exists($directory)) {
            $this->error('Directory does not exist: ' . $directory);
            return 1;
        }

        // 取得所有 .mp4 檔案
        $files = File::files($directory);

        // 過濾出 .mp4 檔案
        $mp4Files = array_filter($files, function ($file) {
            return strtolower($file->getExtension()) === 'mp4';
        });

        if (empty($mp4Files)) {
            $this->info('No .mp4 files found in directory.');
            return 0;
        }

        // 逐一處理每個 .mp4 檔案
        foreach ($mp4Files as $file) {
            $fileName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $filePath = $file->getRealPath();

            // 檢查檔案是否已存在於資料表中
            $existingFile = FileScreenshot::where('file_name', $fileName)->first();

            if ($existingFile) {
                $this->info('File already exists in database: ' . $fileName);
                continue; // 如果已存在，跳過這個檔案
            }

            // 依照檔案名稱建立新資料夾
            $newDirectory = $directory . '\\' . $fileName;

            if (!File::exists($newDirectory)) {
                File::makeDirectory($newDirectory);
                $this->info('Directory created: ' . $newDirectory);
            } else {
                $this->info('Directory already exists: ' . $newDirectory);
            }

            // 使用 FFMpeg 擷取影片中的 60 張圖片
            $screenshotPaths = $this->captureScreenshots($filePath, $newDirectory, $domain, $fileName);

            // 轉換本地路徑為 URL 格式
            $urlFilePath = str_replace(
                [ 'Z:\\FC2-2024\\精選', '\\' ],
                [ $domain . '/fhd/FC2-2024/%E7%B2%BE%E9%81%B8', '/' ],
                $filePath
            );

            // 將檔案資訊寫入資料表
            FileScreenshot::create([
                'file_name'        => $fileName,
                'file_path'        => $urlFilePath,
                'type'             => '2',
                'screenshot_paths' => implode(',', $screenshotPaths),
            ]);

            $this->info('File and screenshots saved to database: ' . $fileName);
        }

        return 0;
    }

    private function captureScreenshots($filePath, $outputDirectory, $domain, $fileName)
    {
        $ffmpeg = FFMpeg::create();
        $video  = $ffmpeg->open($filePath);

        // 取得影片總長度
        $duration = $video->getFFProbe()->format($filePath)->get('duration');

        // 計算擷取圖片的時間點間隔
        $interval = $duration / 60;

        $screenshotPaths = [];

        for ($i = 0; $i < 60; $i++) {
            $time           = $i * $interval;
            $screenshotPath = $outputDirectory . '\\screenshot_' . $i . '.jpg';

            // 擷取當前時間點的畫面
            $video->frame(TimeCode::fromSeconds($time))
                ->save($screenshotPath);

            // 將本地圖片路徑轉換成 URL
            $urlScreenshotPath = $domain . '/fhd/FC2-2024/%E7%B2%BE%E9%81%B8/' . $fileName . '/screenshot_' . $i . '.jpg';

            $screenshotPaths[] = $urlScreenshotPath;
            $this->info('Screenshot saved: ' . $urlScreenshotPath);
        }

        return $screenshotPaths;
    }
}
