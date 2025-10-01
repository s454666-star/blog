<?php

    namespace App\Console\Commands;

    use App\Models\VideoMaster;
    use App\Models\VideoTs;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;
    use Symfony\Component\Process\Process;
    use Symfony\Component\Process\Exception\ProcessFailedException;

    class ConvertToM3u8Command extends Command
    {
        /**
         * 使用方式：
         * 單筆：php artisan video:to-m3u8 --id=3736
         * 批次（僅處理 m3u8_path 為 NULL）：php artisan video:to-m3u8 --limit=10
         * 預演（不執行 ffmpeg/不寫 DB）：php artisan video:to-m3u8 --id=3736 --dry-run
         */
        protected $signature = 'video:to-m3u8
        {--id= : 指定要轉換的 VideoMaster.id}
        {--limit= : 批次處理筆數（僅處理 m3u8_path 為 NULL）}
        {--dry-run : 僅顯示預計動作，不實際執行 ffmpeg 與資料寫入}';

        protected $description = '將影片不重新編碼切為 HLS (m3u8)（1 秒一片段），輸出固定為 video.m3u8 / video_%05d.ts，寫入 videos_ts 並更新 VideoMaster.m3u8_path。';

        protected const DS = DIRECTORY_SEPARATOR;

        public function handle(): int
        {
            $id = $this->option('id');
            $limit = $this->option('limit');
            $dryRun = (bool)$this->option('dry-run');

            // 根路徑可用 .env 覆蓋，否則使用預設
            $srcRoot = rtrim(env('VIDEO_SOURCE_ROOT', 'F:\\video'), '\\/');
            $dstRoot = rtrim(env('M3U8_TARGET_ROOT', 'Z:\\m3u8'), '\\/');

            // 檢查 ffmpeg 是否可用
            if (!$this->checkFfmpegAvailable()) {
                $this->error('[ERROR] ffmpeg 不可用，請確認已安裝並加入 PATH。');
                return 1;
            }

            // 準備目標清單
            $targets = collect();
            if ($id !== null) {
                $video = VideoMaster::query()
                    ->where('id', $id)
                    ->where('video_type', 1)
                    ->first();

                if (!$video) {
                    $this->error('找不到符合條件的 VideoMaster id=' . $id);
                    return 1;
                }

                $targets = collect([$video]);
            } elseif ($limit !== null) {
                $targets = VideoMaster::query()
                    ->where('video_type', 1)
                    ->whereNull('m3u8_path')
                    ->orderBy('id', 'desc')
                    ->limit((int)$limit)
                    ->get();
            } else {
                $this->warn('未指定 --id 或 --limit，無事可做。');
                return 0;
            }


            if ($targets->isEmpty()) {
                $this->info('沒有可處理的資料。');
                return 0;
            }

            $this->info('來源根目錄：' . $srcRoot);
            $this->info('輸出根目錄：' . $dstRoot);
            if ($dryRun) {
                $this->warn('[DRY RUN] 僅顯示流程，不會執行 ffmpeg 或寫入資料庫。');
            }

            foreach ($targets as $video) {
                try {
                    $this->processOne($video, $srcRoot, $dstRoot, $dryRun);
                } catch (\Throwable $e) {
                    $this->error(sprintf('[ID:%d] 轉換失敗：%s', $video->id, $e->getMessage()));
                }
            }

            $this->info('處理完成。');
            return 0;
        }

        /**
         * 處理單筆 VideoMaster
         */
        protected function processOne(VideoMaster $video, string $srcRoot, string $dstRoot, bool $dryRun): void
        {
            // 來源完整路徑（優先用 video_path 再退回 video_name）
            $sourcePath = $this->resolveSourcePath($video, $srcRoot);

            // 取原資料夾名稱（\自拍_425\自拍.mp4 -> 自拍_425）
            $folderName = $this->extractFolderNameFromVideoPath($video->video_path, $video->video_name);

            // 目的地：固定檔名 video.m3u8、分段檔名 video_%05d.ts
            $destDir = $dstRoot . self::DS . $folderName;
            $segmentPattern = $destDir . self::DS . 'video_%05d.ts';
            $m3u8File = $destDir . self::DS . 'video.m3u8';

            // 回寫給 VideoMaster 的公開路徑
            $m3u8PublicPath = '/m3u8/' . $folderName . '/video.m3u8';

            $this->line('');
            $this->info(sprintf('[ID:%d] %s', $video->id, $video->video_name));
            $this->line('來源檔案：' . $sourcePath);
            $this->line('輸出資料夾：' . $destDir);
            $this->line('輸出 m3u8：' . $m3u8File);

            if (!is_file($sourcePath)) {
                throw new \RuntimeException('來源檔案不存在：' . $sourcePath);
            }

            if (!$dryRun) {
                if (!is_dir($destDir) && !@mkdir($destDir, 0777, true)) {
                    throw new \RuntimeException('無法建立輸出資料夾：' . $destDir);
                }
            }

            // ffmpeg：不重編碼（-c copy），1 秒切段
            $ffmpegCmd = [
                'ffmpeg',
                '-hide_banner',
                '-loglevel', 'info',
                '-y',
                '-i', $sourcePath,
                '-c', 'copy',
                '-map', '0',
                '-f', 'hls',
                '-hls_time', '1',
                '-hls_list_size', '0',
                '-hls_playlist_type', 'vod',
                '-hls_segment_filename', $segmentPattern,
                $m3u8File,
            ];

            $this->line('FFmpeg 命令：' . $this->prettyCommand($ffmpegCmd));

            if (!$dryRun) {
                $process = new Process($ffmpegCmd);
                $process->setTimeout(0); // 長檔案允許無限時限
                $process->run(function ($type, $buffer) {
                    echo $buffer; // 轉印 ffmpeg 輸出
                });

                if (!$process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }

                if (!is_file($m3u8File)) {
                    throw new \RuntimeException('ffmpeg 執行後未找到 m3u8 檔：' . $m3u8File);
                }
            }

            // 解析 m3u8 寫入 videos_ts
            if (!$dryRun) {
                $this->saveM3u8ToVideoTs($video, $m3u8File, $folderName);
            }

            // 更新 VideoMaster 的 m3u8_path
            if (!$dryRun) {
                $video->m3u8_path = $m3u8PublicPath;
                $video->save();
                $this->info('已更新 VideoMaster.m3u8_path = ' . $m3u8PublicPath);
            }

            $this->info(sprintf('[ID:%d] 完成。', $video->id));
        }

        /**
         * 檢查 ffmpeg 可用性
         */
        protected function checkFfmpegAvailable(): bool
        {
            try {
                $process = new Process(['ffmpeg', '-version']);
                $process->setTimeout(10);
                $process->run();
                return $process->isSuccessful();
            } catch (\Throwable $e) {
                return false;
            }
        }

        /**
         * 解析來源完整路徑
         */
        protected function resolveSourcePath(VideoMaster $video, string $srcRoot): string
        {
            $rel = null;

            if (!empty($video->video_path)) {
                $rel = ltrim(str_replace(['/', '\\'], self::DS, $video->video_path), self::DS);
            } else {
                $rel = ltrim(str_replace(['/', '\\'], self::DS, $video->video_name), self::DS);
            }

            $full = rtrim($srcRoot, self::DS) . self::DS . $rel;
            return $full;
        }

        /**
         * 從 video_path 推導原資料夾名稱；若 video_path 不可用，則用「檔名去副檔名」退而求其次。
         * 例如：\自拍_425\自拍.mp4 -> 自拍_425
         */
        protected function extractFolderNameFromVideoPath(?string $videoPath, string $videoName): string
        {
            if (!empty($videoPath)) {
                $norm = trim(str_replace(['/', '\\'], self::DS, $videoPath));
                $dir = dirname($norm);
                $folder = basename($dir);
                if ($folder && $folder !== '.' && $folder !== self::DS) {
                    return $folder;
                }
            }
            $base = pathinfo($videoName, PATHINFO_FILENAME);
            return $base ?: 'video_' . date('Ymd_His');
        }

        /**
         * 解析 m3u8 的 #EXTINF 與檔名，寫入 videos_ts。
         * ts 路徑格式：/m3u8/<folder>/video_00001.ts
         */
        protected function saveM3u8ToVideoTs(VideoMaster $video, string $m3u8File, string $folderName): void
        {
            $content = file_get_contents($m3u8File);
            if ($content === false) {
                throw new \RuntimeException('無法讀取 m3u8 檔：' . $m3u8File);
            }

            $lines = preg_split('/\r\n|\r|\n/', $content);
            $rows = [];
            $pendingDuration = null;

            $webBase = '/m3u8/' . $folderName . '/';

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '' || str_starts_with($line, '#EXTM3U') || str_starts_with($line, '#EXT-X')) {
                    continue;
                }

                if (str_starts_with($line, '#EXTINF:')) {
                    // 例：#EXTINF:1.000000,
                    $durationStr = trim(substr($line, strlen('#EXTINF:')));
                    $durationStr = rtrim($durationStr, ',');
                    $pendingDuration = (float)$durationStr;
                    continue;
                }

                // 若上一行是 #EXTINF，下一行是 ts 檔名
                if ($pendingDuration !== null && !str_starts_with($line, '#')) {
                    $tsName = $line; // e.g. video_00001.ts
                    $rows[] = [
                        'video_name' => $video->video_name,   // 原影片檔名
                        'path'       => $webBase . $tsName,   // 相對 Web 路徑
                        'video_time' => $pendingDuration,     // 片段長度（秒）
                        'tags'       => '',                   // 預設空
                        'rating'     => null,                 // 預設 null
                    ];
                    $pendingDuration = null;
                }
            }

            if (empty($rows)) {
                $this->warn('m3u8 未解析出任何 ts 片段，可能是異常或空檔。');
                return;
            }

            DB::transaction(function () use ($rows) {
                // 若要先刪除相同影片舊紀錄，可在此加入刪除邏輯
                VideoTs::insert($rows);
            });

            $this->info('已寫入 videos_ts 筆數：' . count($rows));
        }

        /**
         * 只為了輸出好讀的命令行
         */
        protected function prettyCommand(array $cmd): string
        {
            return implode(' ', array_map(function ($part) {
                if (preg_match('/\s|["]/', $part)) {
                    $part = '"' . $part . '"';
                }
                return $part;
            }, $cmd));
        }
    }
