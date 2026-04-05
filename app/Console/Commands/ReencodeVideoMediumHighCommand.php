<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use FilesystemIterator;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

class ReencodeVideoMediumHighCommand extends Command
{
    protected $signature = 'video:reencode-medium-high
        {folder : 影片所在資料夾}
        {video? : 單一影片檔名或絕對路徑；留空則處理資料夾內全部影片}
        {--overwrite : 若輸出檔已存在則覆蓋}
        {--dry-run : 只顯示預計執行命令，不實際轉檔}';

    protected $description = '使用 ffmpeg 將影片重新編碼為 H.264/AAC 中高碼率 mp4，輸出檔名固定加上 _mhq.mp4。';

    private const VIDEO_EXTENSIONS = ['mp4', 'mov', 'mkv', 'avi', 'wmv', 'm4v', 'mpeg', 'mpg'];
    private const OUTPUT_SUFFIX = '_mhq.mp4';

    public function handle(): int
    {
        $folder = $this->normalizePath((string) $this->argument('folder'));
        $video = trim((string) ($this->argument('video') ?? ''));
        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');

        if ($folder === '' || !is_dir($folder)) {
            $this->error('資料夾不存在：' . $this->argument('folder'));
            return self::FAILURE;
        }

        $ffmpegBin = $this->resolveBinary((string) env('FFMPEG_BIN', 'ffmpeg'), 'ffmpeg');
        $ffprobeBin = $this->resolveBinary((string) env('FFPROBE_BIN', $this->inferFfprobeBinary($ffmpegBin)), 'ffprobe');

        if (!$this->binaryAvailable($ffmpegBin)) {
            $this->error('ffmpeg 不可用：' . $ffmpegBin);
            return self::FAILURE;
        }

        if (!$this->binaryAvailable($ffprobeBin)) {
            $this->error('ffprobe 不可用：' . $ffprobeBin);
            return self::FAILURE;
        }

        try {
            $targets = $this->resolveTargets($folder, $video);
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($targets === []) {
            $this->warn('找不到可處理的影片。');
            return self::SUCCESS;
        }

        $this->info('資料夾：' . $folder);
        $this->line('目標數量：' . count($targets));
        if ($dryRun) {
            $this->warn('[DRY RUN] 只顯示命令，不會真的轉檔。');
        }

        $completed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($targets as $sourcePath) {
            $this->line('');
            $this->line('來源：' . $sourcePath);

            try {
                $outputPath = $this->buildOutputPath($sourcePath);

                if (!$overwrite && is_file($outputPath)) {
                    $skipped++;
                    $this->warn('輸出已存在，跳過：' . $outputPath);
                    continue;
                }

                $probe = $this->probeVideoStream($ffprobeBin, $sourcePath);
                $profile = $this->resolveBitrateProfile($probe['width'], $probe['height']);

                $this->line(sprintf(
                    '解析度：%dx%d；視訊碼率：%dk；音訊碼率：%dk',
                    $probe['width'],
                    $probe['height'],
                    $profile['video_kbps'],
                    $profile['audio_kbps']
                ));
                $this->line('輸出：' . $outputPath);

                $command = $this->buildFfmpegCommand($ffmpegBin, $sourcePath, $outputPath, $profile);
                $this->line('FFmpeg：' . $this->prettyCommand($command));

                if ($dryRun) {
                    $completed++;
                    continue;
                }

                $process = new Process($command);
                $process->setTimeout(null);
                $process->setIdleTimeout(null);
                $process->run(function (string $type, string $buffer): void {
                    $this->output->write($buffer);
                });

                if (!$process->isSuccessful()) {
                    throw new ProcessFailedException($process);
                }

                if (!is_file($outputPath) || filesize($outputPath) === 0) {
                    throw new \RuntimeException('ffmpeg 完成後找不到輸出檔或檔案大小為 0。');
                }

                $completed++;
                $this->info('完成：' . $outputPath);
            } catch (Throwable $e) {
                $failed++;
                $this->error('失敗：' . $e->getMessage());
            }
        }

        $this->line('');
        $this->info(sprintf('完成=%d 跳過=%d 失敗=%d', $completed, $skipped, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveTargets(string $folder, string $video): array
    {
        if ($video !== '') {
            $target = $this->resolveTargetPath($folder, $video);
            if (!is_file($target)) {
                throw new \InvalidArgumentException('找不到影片：' . $video);
            }

            if (!$this->isVideoFile($target)) {
                throw new \InvalidArgumentException('不是支援的影片格式：' . $target);
            }

            return [$target];
        }

        $targets = [];
        $iterator = new FilesystemIterator($folder, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (!$this->isVideoFile($path) || $this->isGeneratedOutput($path)) {
                continue;
            }

            $targets[] = $path;
        }

        sort($targets, SORT_NATURAL | SORT_FLAG_CASE);

        return $targets;
    }

    private function resolveTargetPath(string $folder, string $video): string
    {
        $trimmed = trim($video, " \t\n\r\0\x0B\"");
        if ($trimmed === '') {
            return '';
        }

        if ($this->isAbsolutePath($trimmed)) {
            return $this->normalizePath($trimmed);
        }

        return $this->normalizePath($folder . DIRECTORY_SEPARATOR . $trimmed);
    }

    private function buildOutputPath(string $sourcePath): string
    {
        $directory = dirname($sourcePath);
        $name = pathinfo($sourcePath, PATHINFO_FILENAME);

        return $directory . DIRECTORY_SEPARATOR . $name . self::OUTPUT_SUFFIX;
    }

    private function probeVideoStream(string $ffprobeBin, string $sourcePath): array
    {
        $process = new Process([
            $ffprobeBin,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream=width,height,bit_rate',
            '-of',
            'json',
            $sourcePath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $decoded = json_decode($process->getOutput(), true);
        $stream = is_array($decoded) ? ($decoded['streams'][0] ?? null) : null;

        $width = max(0, (int) ($stream['width'] ?? 0));
        $height = max(0, (int) ($stream['height'] ?? 0));

        if ($width === 0 || $height === 0) {
            throw new \RuntimeException('ffprobe 無法判斷影片解析度。');
        }

        return [
            'width' => $width,
            'height' => $height,
            'bit_rate' => max(0, (int) ($stream['bit_rate'] ?? 0)),
        ];
    }

    private function resolveBitrateProfile(int $width, int $height): array
    {
        if ($width >= 3840 || $height >= 2160) {
            return ['video_kbps' => 14000, 'maxrate_kbps' => 18000, 'bufsize_kbps' => 28000, 'audio_kbps' => 192];
        }

        if ($width >= 2560 || $height >= 1440) {
            return ['video_kbps' => 9000, 'maxrate_kbps' => 12000, 'bufsize_kbps' => 18000, 'audio_kbps' => 192];
        }

        if ($width >= 1920 || $height >= 1080) {
            return ['video_kbps' => 5500, 'maxrate_kbps' => 7500, 'bufsize_kbps' => 11000, 'audio_kbps' => 192];
        }

        if ($width >= 1280 || $height >= 720) {
            return ['video_kbps' => 3200, 'maxrate_kbps' => 4500, 'bufsize_kbps' => 6500, 'audio_kbps' => 160];
        }

        return ['video_kbps' => 2200, 'maxrate_kbps' => 3200, 'bufsize_kbps' => 4500, 'audio_kbps' => 128];
    }

    private function buildFfmpegCommand(string $ffmpegBin, string $sourcePath, string $outputPath, array $profile): array
    {
        return [
            $ffmpegBin,
            '-hide_banner',
            '-loglevel',
            'info',
            '-y',
            '-i',
            $sourcePath,
            '-map',
            '0:v:0',
            '-map',
            '0:a?',
            '-c:v',
            'libx264',
            '-preset',
            'medium',
            '-pix_fmt',
            'yuv420p',
            '-vf',
            'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            '-b:v',
            $profile['video_kbps'] . 'k',
            '-maxrate',
            $profile['maxrate_kbps'] . 'k',
            '-bufsize',
            $profile['bufsize_kbps'] . 'k',
            '-c:a',
            'aac',
            '-b:a',
            $profile['audio_kbps'] . 'k',
            '-movflags',
            '+faststart',
            $outputPath,
        ];
    }

    private function isVideoFile(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    private function isGeneratedOutput(string $path): bool
    {
        return str_ends_with(strtolower((string) pathinfo($path, PATHINFO_BASENAME)), strtolower(self::OUTPUT_SUFFIX));
    }

    private function binaryAvailable(string $binary): bool
    {
        try {
            $process = new Process([$binary, '-version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    private function inferFfprobeBinary(string $ffmpegBin): string
    {
        if (preg_match('/ffmpeg\.exe$/i', $ffmpegBin) === 1) {
            return preg_replace('/ffmpeg(?:\.exe)?$/i', 'ffprobe.exe', $ffmpegBin) ?? 'ffprobe';
        }

        if (preg_match('/ffmpeg$/i', $ffmpegBin) === 1) {
            return preg_replace('/ffmpeg$/i', 'ffprobe', $ffmpegBin) ?? 'ffprobe';
        }

        return 'ffprobe';
    }

    private function resolveBinary(string $binary, string $fallback): string
    {
        $binary = trim($binary, " \t\n\r\0\x0B\"");

        return $binary === '' ? $fallback : $binary;
    }

    private function normalizePath(string $path): string
    {
        $trimmed = trim($path, " \t\n\r\0\x0B\"");
        if ($trimmed === '') {
            return '';
        }

        $resolved = realpath($trimmed);
        if ($resolved !== false) {
            return $resolved;
        }

        if ($this->isAbsolutePath($trimmed)) {
            return $trimmed;
        }

        return getcwd() . DIRECTORY_SEPARATOR . $trimmed;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || str_starts_with($path, '\\\\')
            || str_starts_with($path, '/');
    }

    private function prettyCommand(array $command): string
    {
        return implode(' ', array_map(static function (string $part): string {
            return preg_match('/\s/', $part) === 1 ? '"' . $part . '"' : $part;
        }, $command));
    }
}
