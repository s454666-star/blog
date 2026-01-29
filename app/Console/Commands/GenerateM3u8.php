<?php

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\File;

    class GenerateM3u8 extends Command
    {
        protected $signature = 'video:generate-m3u8';
        protected $description = 'Generate m3u8 and ts files for a video with GPU acceleration';

        public function handle()
        {
            // 設定檔案路徑與目錄
            $videoFile = 'D:\\video\\內射-FC2-PPV-3619794\\內射-FC2-PPV-3619794.mp4';
            $videoDir = dirname($videoFile);
            $fileName = basename($videoFile, '.mp4'); // 只取名稱去掉副檔名
            $videoNameInDB = "$fileName.mp4"; // 確保格式與資料庫一致
            $m3u8FileName = "$fileName.m3u8"; // 修改 m3u8 檔名
            $m3u8Path = "$videoDir\\$m3u8FileName";

            // 取得影片名稱，從資料庫中查詢 video_master ID
            $videoMasterId = DB::table('video_master')
                ->where('video_name', $videoNameInDB) // 直接比對含.mp4的名稱
                ->value('id');

            if (!$videoMasterId) {
                $this->error("Video master ID not found for: $videoNameInDB");
                return;
            }

            // 確保輸出目錄存在
            $outputDir = "$videoDir\\segments";
            if (!File::exists($outputDir)) {
                File::makeDirectory($outputDir, 0777, true, true);
            }

            // 將 Windows 路徑轉換為 FFmpeg 可理解的 Unix-style 路徑
            $videoFileFFmpeg = str_replace('\\', '/', $videoFile);
            $m3u8PathFFmpeg = str_replace('\\', '/', $m3u8Path);
            $outputDirFFmpeg = str_replace('\\', '/', $outputDir);

            // 選項 1：使用流複製（無重新編碼）
            $ffmpegCommandCopy = "ffmpeg -i \"$videoFileFFmpeg\" -c copy -hls_time 5 -hls_playlist_type vod -hls_segment_filename \"$outputDirFFmpeg/segment-%03d.ts\" -hls_base_url \"segments/\" \"$m3u8PathFFmpeg\"";

            // 選項 2：重新編碼，保持高品質
            $ffmpegCommandReencode = "ffmpeg -i \"$videoFileFFmpeg\" -c:v h264_nvenc -preset p7 -cq 19 -c:a copy -hls_time 5 -hls_playlist_type vod -hls_segment_filename \"$outputDirFFmpeg/segment-%03d.ts\" -hls_base_url \"segments/\" \"$m3u8PathFFmpeg\"";

            // 根據需要選擇使用哪個指令
            // 建議初始使用選項 1，如果有問題再考慮選項 2
            $ffmpegCommand = $ffmpegCommandCopy; // 或者使用 $ffmpegCommandReencode

            $this->info("Executing FFmpeg command: $ffmpegCommand");
            exec($ffmpegCommand, $output, $returnVar);

            if ($returnVar !== 0) {
                $this->error('FFmpeg command failed.');
                return;
            }

            // 產生相對路徑，並確保格式正確
            // 將 'D:\video\' 替換為 '\'，以得到相對於根目錄的路徑
            $relativeM3u8Path = str_replace('D:\\video\\', '\\', $m3u8Path);

            // 更新資料表 video_master 的 m3u8_path 欄位
            try {
                DB::table('video_master')
                    ->where('id', $videoMasterId)
                    ->update([
                        'm3u8_path' => $relativeM3u8Path,
                        'updated_at' => now(),
                    ]);

                $this->info("M3U8 file generated and m3u8_path updated: $relativeM3u8Path");
            } catch (\Exception $e) {
                $this->error("Failed to update database: " . $e->getMessage());
            }
        }
    }
