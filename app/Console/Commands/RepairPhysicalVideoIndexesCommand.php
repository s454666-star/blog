<?php

namespace App\Console\Commands;

use App\Models\VideoFaceScreenshot;
use App\Models\VideoMaster;
use App\Models\VideoScreenshot;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

class RepairPhysicalVideoIndexesCommand extends Command
{
    protected $signature = 'video:repair-physical-indexes
        {--video-root= : 實體影片根目錄；預設使用 videos disk}
        {--apply : 實際寫入；未指定時只列出差異}';

    protected $description = '修復人臉工具已整理進子目錄、但缺少 DB 索引的影片，並校正 good 子目錄搬移後的路徑。';

    public function handle(VideoFeatureExtractionService $featureService): int
    {
        $root = $this->resolveVideoRoot();
        $physicalVideos = $this->findPhysicalVideos($root);
        $dbVideos = VideoMaster::query()
            ->select(['id', 'video_path'])
            ->get()
            ->keyBy(fn (VideoMaster $video): string => mb_strtolower($this->normalizePath($video->video_path)));

        $relocations = [];
        $missing = [];

        foreach ($physicalVideos as $relativePath => $absolutePath) {
            $pathKey = mb_strtolower($relativePath);
            if ($dbVideos->has($pathKey)) {
                continue;
            }

            $oldPath = $this->pathBeforeGoodMove($relativePath);
            $oldKey = $oldPath !== null ? mb_strtolower($oldPath) : null;
            if ($oldKey !== null && $dbVideos->has($oldKey)) {
                $relocations[] = [
                    'video' => $dbVideos->get($oldKey),
                    'old_path' => $oldPath,
                    'new_path' => $relativePath,
                    'absolute_path' => $absolutePath,
                ];
                continue;
            }

            if ($this->isRootLevelPath($relativePath) || !$this->hasLegacyExtractionOutput(dirname($absolutePath))) {
                continue;
            }

            $missing[] = [
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'video_type' => $this->inferVideoType($relativePath),
            ];
        }

        $this->info(sprintf(
            '掃描完成：physical=%d relocation=%d missing=%d mode=%s',
            count($physicalVideos),
            count($relocations),
            count($missing),
            $this->option('apply') ? 'apply' : 'dry-run'
        ));

        foreach ($relocations as $item) {
            $this->line(sprintf('[relocate id=%d] %s -> %s', $item['video']->id, $item['old_path'], $item['new_path']));
        }
        foreach ($missing as $item) {
            $this->line(sprintf('[missing type=%s] %s', $item['video_type'], $item['relative_path']));
        }

        if (!$this->option('apply')) {
            $this->warn('目前是 dry-run；加上 --apply 才會寫入。');
            return self::SUCCESS;
        }

        $relocated = 0;
        $created = 0;
        $failed = 0;

        foreach ($relocations as $item) {
            try {
                $this->relocateExistingIndex($item);
                $relocated++;
                $this->info(sprintf('[relocated id=%d] %s', $item['video']->id, $item['new_path']));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('[relocate failed] %s：%s', $item['new_path'], $e->getMessage()));
            }
        }

        foreach ($missing as $item) {
            try {
                $result = $this->createMissingIndex($featureService, $item);
                if ($result === null) {
                    $this->line(sprintf('[already indexed] %s', $item['relative_path']));
                    continue;
                }

                $created++;
                $this->info(sprintf(
                    '[created id=%d type=%s screenshots=%d faces=%d features=%d] %s',
                    $result['video_id'],
                    $item['video_type'],
                    $result['screenshots'],
                    $result['faces'],
                    $result['features'],
                    $item['relative_path']
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('[create failed] %s：%s', $item['relative_path'], $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info(sprintf('修復完成：relocated=%d created=%d failed=%d', $relocated, $created, $failed));
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveVideoRoot(): string
    {
        $configured = trim((string) $this->option('video-root'));
        if ($configured === '') {
            $configured = (string) config('filesystems.disks.videos.root');
        }

        $root = realpath($configured);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('影片根目錄不存在：' . $configured);
        }

        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, string>
     */
    private function findPhysicalVideos(string $root): array
    {
        $videos = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || mb_strtolower($file->getExtension()) !== 'mp4') {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = $this->normalizePath(substr($absolutePath, strlen($root) + 1));
            $videos[$relativePath] = $absolutePath;
        }

        ksort($videos, SORT_NATURAL | SORT_FLAG_CASE);
        return $videos;
    }

    private function pathBeforeGoodMove(string $relativePath): ?string
    {
        $normalized = $this->normalizePath($relativePath);
        if (!str_starts_with(mb_strtolower($normalized), 'good/')) {
            return null;
        }

        $oldPath = substr($normalized, strlen('good/'));
        return $oldPath !== '' ? $oldPath : null;
    }

    private function isRootLevelPath(string $relativePath): bool
    {
        return !str_contains($this->normalizePath($relativePath), '/');
    }

    private function hasLegacyExtractionOutput(string $directory): bool
    {
        foreach (File::files($directory) as $file) {
            $filename = $file->getFilename();
            if (preg_match('/_face_\d+_\d+\.(?:jpe?g|png)$/iu', $filename) === 1) {
                return true;
            }
            if (
                preg_match('/_\d+\.(?:jpe?g|png)$/iu', $filename) === 1
                && !str_contains(mb_strtolower($filename), '_feature_')
            ) {
                return true;
            }
        }
        return false;
    }

    private function inferVideoType(string $relativePath): string
    {
        $segments = explode('/', $this->normalizePath($relativePath));
        $folder = mb_strtolower($segments[0] ?? '') === 'good'
            ? ($segments[1] ?? '')
            : ($segments[0] ?? '');
        if (stripos($folder, 'FC2-PPV-') !== false) {
            return '3';
        }
        if (preg_match('/^(?:自拍|裸舞)_\d+$/u', $folder) === 1) {
            return '1';
        }
        return '2';
    }

    /**
     * @param array{video: VideoMaster, old_path: string, new_path: string, absolute_path: string} $item
     */
    private function relocateExistingIndex(array $item): void
    {
        $oldPath = $this->normalizePath($item['old_path']);
        $newPath = $this->normalizePath($item['new_path']);
        $oldDirectory = $this->directoryPath($oldPath);
        $newDirectory = $this->directoryPath($newPath);
        $stat = @stat($item['absolute_path']) ?: [];

        DB::transaction(function () use ($item, $oldPath, $newPath, $oldDirectory, $newDirectory, $stat): void {
            $video = VideoMaster::query()->lockForUpdate()->findOrFail($item['video']->id);
            if ($this->normalizePath($video->video_path) !== $oldPath) {
                throw new RuntimeException('DB 路徑已被其他程序修改，停止覆寫。');
            }

            $video->video_path = $newPath;
            if (is_string($video->m3u8_path) && $video->m3u8_path !== '') {
                $video->m3u8_path = $this->rebasePath($video->m3u8_path, $oldDirectory, $newDirectory);
            }
            $video->save();

            $screenshots = DB::table('video_screenshots')
                ->where('video_master_id', $video->id)
                ->get(['id', 'screenshot_path']);

            foreach ($screenshots as $screenshot) {
                DB::table('video_screenshots')->where('id', $screenshot->id)->update([
                    'screenshot_path' => $this->rebasePath($screenshot->screenshot_path, $oldDirectory, $newDirectory),
                    'updated_at' => now(),
                ]);
            }

            $faces = DB::table('video_face_screenshots as vfs')
                ->join('video_screenshots as vs', 'vs.id', '=', 'vfs.video_screenshot_id')
                ->where('vs.video_master_id', $video->id)
                ->get(['vfs.id', 'vfs.face_image_path']);

            foreach ($faces as $face) {
                DB::table('video_face_screenshots')->where('id', $face->id)->update([
                    'face_image_path' => $this->rebasePath($face->face_image_path, $oldDirectory, $newDirectory),
                    'updated_at' => now(),
                ]);
            }

            $feature = DB::table('video_features')->where('video_master_id', $video->id)->first();
            if ($feature !== null) {
                DB::table('video_features')->where('id', $feature->id)->update([
                    'video_path' => $newPath,
                    'directory_path' => $newDirectory,
                    'file_name' => basename(str_replace('/', DIRECTORY_SEPARATOR, $newPath)),
                    'path_sha1' => sha1(mb_strtolower($newPath)),
                    'file_size_bytes' => $stat['size'] ?? null,
                    'file_created_at' => isset($stat['ctime']) ? date('Y-m-d H:i:s', $stat['ctime']) : null,
                    'file_modified_at' => isset($stat['mtime']) ? date('Y-m-d H:i:s', $stat['mtime']) : null,
                    'updated_at' => now(),
                ]);

                $frames = DB::table('video_feature_frames')
                    ->where('video_feature_id', $feature->id)
                    ->get(['id', 'screenshot_path']);
                foreach ($frames as $frame) {
                    DB::table('video_feature_frames')->where('id', $frame->id)->update([
                        'screenshot_path' => $this->rebasePath($frame->screenshot_path, $oldDirectory, $newDirectory),
                        'updated_at' => now(),
                    ]);
                }
            }
        }, 3);
    }

    /**
     * @param array{relative_path: string, absolute_path: string, video_type: string} $item
     * @return array{video_id: int, screenshots: int, faces: int, features: int}|null
     */
    private function createMissingIndex(VideoFeatureExtractionService $featureService, array $item): ?array
    {
        $payload = $featureService->inspectFile($item['absolute_path']);
        $video = null;

        try {
            $legacy = DB::transaction(function () use ($item, $payload, &$video): ?array {
                $existing = VideoMaster::query()
                    ->where('video_path', $item['relative_path'])
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return null;
                }

                $video = VideoMaster::query()->create([
                    'video_name' => basename($item['absolute_path']),
                    'video_path' => $item['relative_path'],
                    'duration' => round((float) $payload['duration_seconds'], 2),
                    'video_type' => $item['video_type'],
                ]);

                return $this->importLegacyFaceOutput($video, dirname($item['absolute_path']), $item['relative_path']);
            }, 3);

            if ($legacy === null || !$video instanceof VideoMaster) {
                return null;
            }

            $feature = $featureService->persistPayloadForVideo($video, $payload);
            return [
                'video_id' => (int) $video->id,
                'screenshots' => $legacy['screenshots'],
                'faces' => $legacy['faces'],
                'features' => $feature->frames()->count(),
            ];
        } catch (Throwable $e) {
            if ($video instanceof VideoMaster && $video->exists) {
                VideoMaster::query()->whereKey($video->id)->delete();
            }
            throw $e;
        } finally {
            $featureService->cleanupPayload($payload);
        }
    }

    /**
     * @return array{screenshots: int, faces: int}
     */
    private function importLegacyFaceOutput(VideoMaster $video, string $directory, string $relativeVideoPath): array
    {
        $relativeDirectory = $this->directoryPath($relativeVideoPath);
        $frameIds = [];
        $screenshotCount = 0;
        $faceCount = 0;

        foreach (File::files($directory) as $file) {
            $filename = $file->getFilename();
            if (preg_match('/^(.*)_(\d+)\.(jpe?g|png)$/iu', $filename, $match) !== 1) {
                continue;
            }
            if (str_contains(mb_strtolower($filename), '_face_') || str_contains(mb_strtolower($filename), '_feature_')) {
                continue;
            }

            $screenshot = VideoScreenshot::query()->create([
                'video_master_id' => $video->id,
                'screenshot_path' => $this->normalizePath($relativeDirectory . '/' . $filename),
            ]);
            $frameIds[$match[1] . ':' . (int) $match[2]] = (int) $screenshot->id;
            $screenshotCount++;
        }

        foreach (File::files($directory) as $file) {
            $filename = $file->getFilename();
            if (preg_match('/^(.*)_face_(\d+)_(\d+)\.(jpe?g|png)$/iu', $filename, $match) !== 1) {
                continue;
            }

            $frameKey = $match[1] . ':' . (int) $match[2];
            if (!isset($frameIds[$frameKey])) {
                continue;
            }

            VideoFaceScreenshot::query()->create([
                'video_screenshot_id' => $frameIds[$frameKey],
                'face_image_path' => $this->normalizePath($relativeDirectory . '/' . $filename),
                'is_master' => 0,
            ]);
            $faceCount++;
        }

        if ($screenshotCount === 0) {
            throw new RuntimeException('找不到可重建的舊截圖：' . $directory);
        }

        return ['screenshots' => $screenshotCount, 'faces' => $faceCount];
    }

    private function rebasePath(?string $path, string $oldDirectory, string $newDirectory): ?string
    {
        if ($path === null || trim($path) === '') {
            return $path;
        }

        $normalized = $this->normalizePath($path);
        if ($normalized === $oldDirectory) {
            return $newDirectory;
        }

        $prefix = rtrim($oldDirectory, '/') . '/';
        if (!str_starts_with(mb_strtolower($normalized), mb_strtolower($prefix))) {
            return $normalized;
        }

        return rtrim($newDirectory, '/') . '/' . substr($normalized, strlen($prefix));
    }

    private function directoryPath(string $path): string
    {
        $normalized = $this->normalizePath($path);
        $position = strrpos($normalized, '/');
        return $position === false ? '' : substr($normalized, 0, $position);
    }

    private function normalizePath(?string $path): string
    {
        $normalized = preg_replace('#/+#', '/', str_replace('\\', '/', trim((string) $path))) ?? '';
        return trim($normalized, '/');
    }
}
