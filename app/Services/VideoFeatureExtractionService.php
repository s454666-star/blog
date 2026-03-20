<?php

namespace App\Services;

use App\Models\VideoFaceScreenshot;
use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use App\Models\VideoMaster;
use App\Models\VideoScreenshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class VideoFeatureExtractionService
{
    private const HASH_BITS = 64;

    public function buildCapturePlan(float $durationSeconds): array
    {
        $durationSeconds = max(0.0, $durationSeconds);

        if ($durationSeconds < 10.0) {
            $captureSecond = min(3.0, max($durationSeconds - 0.25, 0.0));

            return [[
                'capture_order' => 1,
                'label_second' => 3.0,
                'capture_second' => round($captureSecond, 3),
            ]];
        }

        $plan = [];

        foreach ([10.0, 20.0, 30.0, 40.0] as $index => $targetSecond) {
            if ($durationSeconds + 0.001 < $targetSecond) {
                continue;
            }

            $plan[] = [
                'capture_order' => $index + 1,
                'label_second' => $targetSecond,
                'capture_second' => round(min($targetSecond, max($durationSeconds - 0.25, 0.0)), 3),
            ];
        }

        return $plan;
    }

    public function inspectFile(string $absolutePath): array
    {
        $absolutePath = $this->normalizeAbsolutePath($absolutePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            throw new RuntimeException('影片檔不存在：' . $absolutePath);
        }

        $durationSeconds = $this->probeDurationSeconds($absolutePath);
        if ($durationSeconds <= 0) {
            throw new RuntimeException('ffprobe 無法取得影片時長：' . $absolutePath);
        }

        $capturePlan = $this->buildCapturePlan($durationSeconds);
        if ($capturePlan === []) {
            throw new RuntimeException('無法為影片建立截圖計畫：' . $absolutePath);
        }

        $tmpDir = storage_path('app/video_features/tmp/' . uniqid('feature_', true));
        File::ensureDirectoryExists($tmpDir);

        $frames = [];
        $baseName = (string) pathinfo($absolutePath, PATHINFO_FILENAME);

        foreach ($capturePlan as $framePlan) {
            $captureOrder = (int) $framePlan['capture_order'];
            $labelSecond = (float) $framePlan['label_second'];
            $captureSecond = (float) $framePlan['capture_second'];
            $tmpPath = $tmpDir . DIRECTORY_SEPARATOR . sprintf('frame_%02d.jpg', $captureOrder);

            $this->captureFrame($absolutePath, $captureSecond, $tmpPath);

            $imageInfo = @getimagesize($tmpPath);
            $dhashHex = $this->computeDhashHexFromJpeg($tmpPath);
            $frames[] = [
                'capture_order' => $captureOrder,
                'label_second' => $labelSecond,
                'capture_second' => $captureSecond,
                'temp_path' => $tmpPath,
                'suggested_filename' => $this->buildFeatureScreenshotFilename($baseName, $captureOrder, $labelSecond),
                'dhash_hex' => $dhashHex,
                'dhash_prefix' => substr($dhashHex, 0, 2),
                'frame_sha1' => sha1_file($tmpPath) ?: null,
                'image_width' => is_array($imageInfo) ? (int) ($imageInfo[0] ?? 0) : null,
                'image_height' => is_array($imageInfo) ? (int) ($imageInfo[1] ?? 0) : null,
            ];
        }

        return [
            'absolute_path' => $absolutePath,
            'video_name' => basename($absolutePath),
            'file_name' => basename($absolutePath),
            'file_size_bytes' => ($size = @filesize($absolutePath)) !== false ? (int) $size : null,
            'duration_seconds' => round($durationSeconds, 3),
            'file_created_at' => $this->timestampToCarbon(@filectime($absolutePath)),
            'file_modified_at' => $this->timestampToCarbon(@filemtime($absolutePath)),
            'capture_rule' => $durationSeconds < 10.0 ? 'lt_10s_at_3s' : '10s_x4',
            'feature_version' => 'v1',
            'frames' => $frames,
            'tmp_dir' => $tmpDir,
        ];
    }

    public function extractForVideo(VideoMaster $video, bool $refresh = false): VideoFeature
    {
        $video->loadMissing('feature.frames');

        if (!$refresh && $video->feature !== null) {
            $expectedCount = count($this->buildCapturePlan((float) ($video->duration ?? 0)));
            if ($expectedCount > 0 && $video->feature->frames->count() >= $expectedCount) {
                return $video->feature->fresh('frames');
            }
        }

        $payload = null;

        try {
            $payload = $this->inspectFile($this->resolveAbsoluteVideoPath($video));
            return $this->persistPayloadForVideo($video, $payload);
        } catch (Throwable $e) {
            $this->markVideoError($video, $e->getMessage());
            throw $e;
        } finally {
            if (is_array($payload)) {
                $this->cleanupPayload($payload);
            }
        }
    }

    public function persistPayloadForVideo(VideoMaster $video, array $payload): VideoFeature
    {
        $video->loadMissing('feature.frames');

        $videoPath = $this->normalizeDbRelativePath((string) $video->video_path);
        $directoryPath = $this->extractDirectoryPath($videoPath);
        $feature = $video->feature ?: new VideoFeature(['video_master_id' => $video->id]);

        $oldFramePaths = [];
        $createdFiles = [];

        try {
            DB::transaction(function () use ($video, $payload, $videoPath, $directoryPath, $feature, &$oldFramePaths, &$createdFiles): void {
                $feature = $feature->exists
                    ? $feature->fresh('frames') ?? $feature
                    : $feature;

                if ($feature->exists) {
                    $feature->loadMissing('frames');

                    foreach ($feature->frames as $existingFrame) {
                        $oldFramePaths[] = $this->absolutePathFromDbPath((string) $existingFrame->screenshot_path);

                        if ($existingFrame->video_screenshot_id) {
                            VideoScreenshot::query()
                                ->whereKey($existingFrame->video_screenshot_id)
                                ->delete();
                        }
                    }

                    $feature->frames()->delete();
                }

                $feature->fill([
                    'video_master_id' => $video->id,
                    'master_face_screenshot_id' => $this->findMasterFaceId($video->id),
                    'video_name' => (string) ($video->video_name ?: $payload['video_name']),
                    'video_path' => $videoPath,
                    'directory_path' => $directoryPath,
                    'file_name' => (string) $payload['file_name'],
                    'path_sha1' => sha1(strtolower($videoPath)),
                    'file_size_bytes' => $payload['file_size_bytes'],
                    'duration_seconds' => $payload['duration_seconds'],
                    'file_created_at' => $payload['file_created_at'],
                    'file_modified_at' => $payload['file_modified_at'],
                    'screenshot_count' => count($payload['frames'] ?? []),
                    'feature_version' => (string) ($payload['feature_version'] ?? 'v1'),
                    'capture_rule' => (string) ($payload['capture_rule'] ?? '10s_x4'),
                    'extracted_at' => now(),
                    'last_error' => null,
                ]);
                $feature->save();

                foreach ($payload['frames'] as $frame) {
                    $dbPath = $this->buildFeatureScreenshotDbPath(
                        $directoryPath,
                        (string) $frame['suggested_filename']
                    );
                    $absoluteScreenshotPath = $this->absolutePathFromDbPath($dbPath);

                    File::ensureDirectoryExists(dirname($absoluteScreenshotPath));
                    if (!@copy((string) $frame['temp_path'], $absoluteScreenshotPath)) {
                        throw new RuntimeException('無法儲存截圖：' . $absoluteScreenshotPath);
                    }

                    $createdFiles[] = $absoluteScreenshotPath;

                    $screenshot = VideoScreenshot::query()->create([
                        'video_master_id' => $video->id,
                        'screenshot_path' => $dbPath,
                    ]);

                    VideoFeatureFrame::query()->create([
                        'video_feature_id' => $feature->id,
                        'video_screenshot_id' => $screenshot->id,
                        'capture_order' => (int) $frame['capture_order'],
                        'capture_second' => $frame['capture_second'],
                        'screenshot_path' => $dbPath,
                        'dhash_hex' => (string) $frame['dhash_hex'],
                        'dhash_prefix' => (string) $frame['dhash_prefix'],
                        'frame_sha1' => $frame['frame_sha1'],
                        'image_width' => $frame['image_width'],
                        'image_height' => $frame['image_height'],
                    ]);
                }
            });
        } catch (Throwable $e) {
            foreach ($createdFiles as $createdFile) {
                if (is_string($createdFile) && $createdFile !== '' && File::exists($createdFile)) {
                    File::delete($createdFile);
                }
            }

            throw $e;
        }

        foreach ($oldFramePaths as $oldFramePath) {
            if (is_string($oldFramePath) && $oldFramePath !== '' && File::exists($oldFramePath)) {
                File::delete($oldFramePath);
            }
        }

        $this->syncMasterFaceForVideo($video->id);

        return VideoFeature::query()
            ->with('frames')
            ->where('video_master_id', $video->id)
            ->firstOrFail();
    }

    public function cleanupPayload(array $payload): void
    {
        $tmpDir = (string) ($payload['tmp_dir'] ?? '');
        if ($tmpDir !== '' && File::exists($tmpDir)) {
            File::deleteDirectory($tmpDir);
        }
    }

    public function resolveAbsoluteVideoPath(VideoMaster $video): string
    {
        $relativePath = ltrim(str_replace('\\', '/', (string) $video->video_path), '/');
        $disk = Storage::disk('videos');
        $absolutePath = $disk->path($relativePath);

        $absolutePath = $this->normalizeAbsolutePath($absolutePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            throw new RuntimeException('找不到影片檔：' . $absolutePath);
        }

        return $absolutePath;
    }

    public function markVideoError(VideoMaster $video, string $message): void
    {
        $feature = VideoFeature::query()->firstOrNew([
            'video_master_id' => $video->id,
        ]);

        $videoPath = $this->normalizeDbRelativePath((string) $video->video_path);

        $feature->fill([
            'master_face_screenshot_id' => $this->findMasterFaceId($video->id),
            'video_name' => (string) $video->video_name,
            'video_path' => $videoPath,
            'directory_path' => $this->extractDirectoryPath($videoPath),
            'file_name' => (string) pathinfo($videoPath, PATHINFO_BASENAME),
            'path_sha1' => sha1(strtolower($videoPath)),
            'duration_seconds' => (float) ($video->duration ?? 0),
            'capture_rule' => '10s_x4',
            'feature_version' => 'v1',
            'last_error' => $message,
        ]);
        $feature->save();
    }

    public function syncMasterFaceForVideo(int $videoMasterId): void
    {
        VideoFeature::query()
            ->where('video_master_id', $videoMasterId)
            ->update([
                'master_face_screenshot_id' => $this->findMasterFaceId($videoMasterId),
            ]);
    }

    public function hashSimilarityPercent(string $hexA, string $hexB): int
    {
        $distance = $this->hammingDistanceHex64($hexA, $hexB);
        $sameBits = self::HASH_BITS - $distance;

        return max(0, min(100, (int) floor(($sameBits * 100) / self::HASH_BITS)));
    }

    public function isValidDhash(string $hex): bool
    {
        return (bool) preg_match('/^[0-9a-f]{16}$/', strtolower(trim($hex)));
    }

    private function findMasterFaceId(int $videoMasterId): ?int
    {
        $faceId = VideoFaceScreenshot::query()
            ->join('video_screenshots', 'video_screenshots.id', '=', 'video_face_screenshots.video_screenshot_id')
            ->where('video_screenshots.video_master_id', $videoMasterId)
            ->where('video_face_screenshots.is_master', 1)
            ->value('video_face_screenshots.id');

        return $faceId !== null ? (int) $faceId : null;
    }

    private function captureFrame(string $absolutePath, float $captureSecond, string $outputPath): void
    {
        $lastError = '';

        foreach ([false, true] as $forceCompatibleColorspace) {
            if ($forceCompatibleColorspace && !$this->shouldRetryFrameCaptureWithCompatibleColorspace($lastError)) {
                break;
            }

            if (is_file($outputPath)) {
                @unlink($outputPath);
            }

            $process = new Process(
                $this->buildCaptureFrameCommand($absolutePath, $captureSecond, $outputPath, $forceCompatibleColorspace)
            );

            $process->setTimeout(180);
            $process->run();

            if ($process->isSuccessful() && is_file($outputPath) && (int) @filesize($outputPath) > 0) {
                return;
            }

            $lastError = trim($process->getErrorOutput() ?: $process->getOutput());
        }

        throw new RuntimeException('ffmpeg 擷取截圖失敗：' . $lastError);
    }

    private function buildCaptureFrameCommand(
        string $absolutePath,
        float $captureSecond,
        string $outputPath,
        bool $forceCompatibleColorspace
    ): array {
        $command = [
            (string) env('FFMPEG_BIN', 'ffmpeg'),
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-ss',
            number_format($captureSecond, 3, '.', ''),
            '-i',
            $absolutePath,
        ];

        if ($forceCompatibleColorspace) {
            // Some uploads carry uncommon colorspace metadata that ffmpeg cannot auto-convert to mjpeg.
            $command[] = '-vf';
            $command[] = 'colorspace=all=bt709:iall=bt709,format=yuv420p';
        }

        $command[] = '-frames:v';
        $command[] = '1';
        $command[] = '-q:v';
        $command[] = '3';
        $command[] = $outputPath;

        return $command;
    }

    private function shouldRetryFrameCaptureWithCompatibleColorspace(string $errorOutput): bool
    {
        $errorOutput = strtolower($errorOutput);

        return str_contains($errorOutput, 'impossible to convert between the formats supported by the filter')
            || (str_contains($errorOutput, 'auto_scale') && str_contains($errorOutput, 'function not implemented'));
    }

    private function probeDurationSeconds(string $absolutePath): float
    {
        $process = new Process([
            (string) env('FFPROBE_BIN', 'ffprobe'),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=nokey=1:noprint_wrappers=1',
            $absolutePath,
        ]);

        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('ffprobe 失敗：' . trim($process->getErrorOutput() ?: $process->getOutput()));
        }

        $output = trim($process->getOutput());
        if ($output === '' || !is_numeric($output)) {
            throw new RuntimeException('ffprobe 未回傳有效的 duration');
        }

        return max(0.0, (float) $output);
    }

    private function computeDhashHexFromJpeg(string $jpegPath): string
    {
        if (!extension_loaded('gd')) {
            throw new RuntimeException('需要 GD extension 才能計算 dHash');
        }

        $img = @imagecreatefromjpeg($jpegPath);
        if (!$img) {
            throw new RuntimeException('讀取 JPEG 失敗：' . $jpegPath);
        }

        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled(
            $small,
            $img,
            0,
            0,
            0,
            0,
            9,
            8,
            imagesx($img),
            imagesy($img)
        );

        imagedestroy($img);

        $bytes = array_fill(0, 8, 0);
        $bitIndex = 0;

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = $this->grayAt($small, $x, $y);
                $right = $this->grayAt($small, $x + 1, $y);
                $bit = $left > $right ? 1 : 0;

                $bytePos = intdiv($bitIndex, 8);
                $bitPos = 7 - ($bitIndex % 8);

                if ($bit === 1) {
                    $bytes[$bytePos] |= 1 << $bitPos;
                }

                $bitIndex++;
            }
        }

        imagedestroy($small);

        $hex = '';
        foreach ($bytes as $byte) {
            $hex .= str_pad(dechex($byte & 255), 2, '0', STR_PAD_LEFT);
        }

        return strtolower($hex);
    }

    private function grayAt($img, int $x, int $y): int
    {
        $rgb = imagecolorat($img, $x, $y);

        $r = ($rgb >> 16) & 255;
        $g = ($rgb >> 8) & 255;
        $b = $rgb & 255;

        return (int) floor(($r + $g + $b) / 3);
    }

    private function hammingDistanceHex64(string $hexA, string $hexB): int
    {
        $hexA = strtolower(trim($hexA));
        $hexB = strtolower(trim($hexB));

        if (!$this->isValidDhash($hexA) || !$this->isValidDhash($hexB)) {
            return self::HASH_BITS;
        }

        $lookup = [
            '0' => 0, '1' => 1, '2' => 2, '3' => 3,
            '4' => 4, '5' => 5, '6' => 6, '7' => 7,
            '8' => 8, '9' => 9, 'a' => 10, 'b' => 11,
            'c' => 12, 'd' => 13, 'e' => 14, 'f' => 15,
        ];
        $popCount = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];

        $distance = 0;
        for ($i = 0; $i < 16; $i++) {
            $distance += $popCount[$lookup[$hexA[$i]] ^ $lookup[$hexB[$i]]];
        }

        return $distance;
    }

    private function buildFeatureScreenshotFilename(string $baseName, int $captureOrder, float $labelSecond): string
    {
        $baseName = preg_replace('/[<>:"\/\\\\|?*]+/u', '_', $baseName) ?: 'video';
        $label = rtrim(rtrim(number_format($labelSecond, 3, '.', ''), '0'), '.');
        $label = str_replace('.', '_', $label);

        return sprintf('%s_feature_%02d_%ss.jpg', $baseName, $captureOrder, $label);
    }

    private function buildFeatureScreenshotDbPath(?string $directoryPath, string $filename): string
    {
        $filename = trim(str_replace('/', '\\', $filename), '\\/');
        $directoryPath = trim(str_replace('/', '\\', (string) $directoryPath), '\\/');

        if ($directoryPath === '') {
            return '\\' . $filename;
        }

        return '\\' . $directoryPath . '\\' . $filename;
    }

    private function absolutePathFromDbPath(string $dbPath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $dbPath), '/');
        return $this->normalizeAbsolutePath(Storage::disk('videos')->path($relativePath));
    }

    private function normalizeDbRelativePath(string $path): string
    {
        $path = trim(str_replace('/', '\\', $path), '\\/');
        return '\\' . $path;
    }

    private function extractDirectoryPath(string $dbPath): ?string
    {
        $path = ltrim(str_replace('\\', '/', $dbPath), '/');
        $directory = trim((string) pathinfo($path, PATHINFO_DIRNAME), '/');

        return $directory === '' || $directory === '.' ? null : str_replace('/', '\\', $directory);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $real = @realpath($path);
        if (is_string($real) && $real !== '') {
            return $real;
        }

        return $path;
    }

    private function timestampToCarbon(int|false $timestamp): ?Carbon
    {
        if (!is_int($timestamp) || $timestamp <= 0) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp);
    }
}
