<?php

namespace App\Services;

use App\Models\TgVideoReview;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class TgVideoReviewScanner
{
    private const UNCHANGED_PROGRESS_INTERVAL = 250;

    /**
     * @param callable(int, int, string): void|null $progress
     * @return array{videos: int, generated: int, unchanged: int, disappeared: int, failed: int}
     */
    public function scan(?string $requestedRoot = null, ?callable $progress = null, ?string $runToken = null): array
    {
        $root = realpath($requestedRoot ?: (string) config('tg_video_review.root'));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('掃描目錄不存在。');
        }

        $lockPath = (string) config('tg_video_review.scan_lock_path', storage_path('app/tg-video-review-scan.lock'));
        File::ensureDirectoryExists(dirname($lockPath));
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('已有另一個 TG 暫存掃描正在執行。');
        }

        $token = $this->safeToken($runToken ?: bin2hex(random_bytes(16)));
        $runsRoot = $this->runsRootPath();
        $this->cleanupAbandonedRuns($token, $root, $runsRoot);
        $runDirectory = $runsRoot . DIRECTORY_SEPARATOR . $token;
        $stageDirectory = $runDirectory . DIRECTORY_SEPARATOR . 'stage';
        File::ensureDirectoryExists($stageDirectory);
        $journalPath = $runDirectory . DIRECTORY_SEPARATOR . 'journal.json';
        $journal = [
            'token' => $token,
            'root' => $root,
            'status' => 'processing',
            'entries' => [],
            'database_before' => [],
        ];
        $this->writeJournal($journalPath, $journal);

        try {
            $this->assertMediaToolsAvailable();
            $videos = $this->videoFiles($root);
            $this->assertNoImageNameCollisions($videos);
            $existingByPathHash = TgVideoReview::query()
                ->get()
                ->keyBy(fn (TgVideoReview $review): string => (string) $review->path_hash)
                ->all();
            $total = count($videos);
            $generated = 0;
            $unchanged = 0;
            $disappeared = 0;
            $failed = 0;

            foreach ($videos as $index => $videoPath) {
                $imagePath = $root . DIRECTORY_SEPARATOR . pathinfo($videoPath, PATHINFO_FILENAME) . '.jpg';
                $pathHash = $this->hashPath($videoPath);
                $metadata = $this->readVideoMetadata($videoPath);
                if ($metadata === null) {
                    $disappeared++;
                    if ($progress !== null) {
                        $progress($index + 1, $total, '影片已移動，略過');
                    }
                    continue;
                }
                $fileSize = $metadata['size'];
                $modifiedAt = $metadata['modified_at'];
                $existing = $existingByPathHash[$pathHash] ?? null;

                if (is_file($imagePath)
                    && (!$existing instanceof TgVideoReview
                        || $this->pathKey((string) $existing->image_path) !== $this->pathKey($imagePath))) {
                    throw new RuntimeException('同名 JPG 不是本工具建立的檔案，為避免覆寫已停止掃描。');
                }

                if ($existing instanceof TgVideoReview
                    && (int) $existing->file_size_bytes === $fileSize
                    && (int) $existing->file_modified_at === $modifiedAt
                    && $this->pathKey((string) $existing->image_path) === $this->pathKey($imagePath)
                    && is_file($imagePath)) {
                    $unchanged++;
                    if ($progress !== null && (
                        $unchanged === 1
                        || $unchanged % self::UNCHANGED_PROGRESS_INTERVAL === 0
                        || $index + 1 === $total
                    )) {
                        $progress($index + 1, $total, sprintf('已存在，快速略過（累計 %d）', $unchanged));
                    }
                    continue;
                }

                $stagePath = $stageDirectory . DIRECTORY_SEPARATOR . sprintf('%04d.jpg', $index + 1);
                try {
                    $duration = $this->probeDuration($videoPath);
                    $this->generateContactSheet($videoPath, $stagePath, $duration);
                } catch (Throwable) {
                    if (is_file($stagePath)) {
                        @unlink($stagePath);
                    }
                    if ($this->readVideoMetadata($videoPath) === null) {
                        $disappeared++;
                        if ($progress !== null) {
                            $progress($index + 1, $total, '影片已移動，略過');
                        }
                        continue;
                    }
                    $failed++;
                    if ($progress !== null) {
                        $progress($index + 1, $total, '影片無法讀取，已略過');
                    }
                    continue;
                }
                if ($this->readVideoMetadata($videoPath) === null) {
                    @unlink($stagePath);
                    $disappeared++;
                    if ($progress !== null) {
                        $progress($index + 1, $total, '影片已移動，略過');
                    }
                    continue;
                }
                $entryIndex = count($journal['entries']);
                $journal['entries'][] = [
                    'path_hash' => $pathHash,
                    'video_path' => $videoPath,
                    'image_path' => $imagePath,
                    'stage_path' => $stagePath,
                    'backup_path' => null,
                    'published' => false,
                    'committed' => false,
                    'file_size_bytes' => $fileSize,
                    'file_modified_at' => $modifiedAt,
                    'duration_seconds' => $duration,
                ];
                $journal['database_before'][$pathHash] = $existing?->getAttributes();
                $this->writeJournal($journalPath, $journal);

                if (is_file($imagePath)) {
                    $backupPath = $runDirectory . DIRECTORY_SEPARATOR . 'backup-' . $entryIndex . '.jpg';
                    $journal['entries'][$entryIndex]['backup_path'] = $backupPath;
                    $this->writeJournal($journalPath, $journal);
                    if (!rename($imagePath, $backupPath)) {
                        throw new RuntimeException('無法暫存既有接觸表。');
                    }
                }

                if (!rename($stagePath, $imagePath)) {
                    throw new RuntimeException('無法發布接觸表。');
                }
                $journal['entries'][$entryIndex]['published'] = true;
                $this->writeJournal($journalPath, $journal);

                DB::transaction(function () use (
                    $pathHash,
                    $videoPath,
                    $imagePath,
                    $fileSize,
                    $modifiedAt,
                    $duration
                ): void {
                    TgVideoReview::query()->updateOrCreate(
                        ['path_hash' => $pathHash],
                        [
                            'video_path' => $videoPath,
                            'image_path' => $imagePath,
                            'file_size_bytes' => $fileSize,
                            'file_modified_at' => $modifiedAt,
                            'duration_seconds' => $duration,
                            'screenshot_count' => 20,
                        ]
                    );
                });
                $journal['entries'][$entryIndex]['committed'] = true;
                $this->writeJournal($journalPath, $journal);

                $generated++;
                if ($progress !== null) {
                    $progress($index + 1, $total, '已發布 5×4 接觸表');
                }
            }

            $journal['status'] = 'completed';
            $this->writeJournal($journalPath, $journal);
            File::deleteDirectory($runDirectory);

            return [
                'videos' => $total,
                'generated' => $generated,
                'unchanged' => $unchanged,
                'disappeared' => $disappeared,
                'failed' => $failed,
            ];
        } catch (Throwable $e) {
            $this->cleanupRun($token);
            throw $e;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function cleanupRun(string $runToken): void
    {
        $token = $this->safeToken($runToken);
        $runDirectory = $this->runsRootPath() . DIRECTORY_SEPARATOR . $token;
        $journalPath = $runDirectory . DIRECTORY_SEPARATOR . 'journal.json';
        if (!is_file($journalPath)) {
            File::deleteDirectory($runDirectory);
            return;
        }

        $journal = json_decode((string) file_get_contents($journalPath), true);
        if (!is_array($journal) || ($journal['status'] ?? '') === 'completed') {
            File::deleteDirectory($runDirectory);
            return;
        }

        foreach (array_reverse((array) ($journal['entries'] ?? [])) as $entry) {
            if (($entry['committed'] ?? false) === true) {
                continue;
            }

            $imagePath = (string) ($entry['image_path'] ?? '');
            $backupPath = (string) ($entry['backup_path'] ?? '');
            $stagePath = (string) ($entry['stage_path'] ?? '');
            $wasPossiblyPublished = ($entry['published'] ?? false)
                || ($backupPath !== '' && is_file($backupPath))
                || ($stagePath !== '' && !is_file($stagePath));
            if ($wasPossiblyPublished && is_file($imagePath)) {
                @unlink($imagePath);
            }
            if ($backupPath !== '' && is_file($backupPath) && !file_exists($imagePath)) {
                @rename($backupPath, $imagePath);
            }
        }

        $committedHashes = [];
        foreach ((array) ($journal['entries'] ?? []) as $entry) {
            if (($entry['committed'] ?? false) === true && isset($entry['path_hash'])) {
                $committedHashes[(string) $entry['path_hash']] = true;
            }
        }

        DB::transaction(function () use ($journal, $committedHashes): void {
            foreach ((array) ($journal['database_before'] ?? []) as $pathHash => $attributes) {
                if (isset($committedHashes[(string) $pathHash])) {
                    continue;
                }

                if (is_array($attributes)) {
                    TgVideoReview::query()->updateOrCreate(['path_hash' => $pathHash], $attributes);
                } else {
                    TgVideoReview::query()->where('path_hash', $pathHash)->delete();
                }
            }
        });

        File::deleteDirectory($runDirectory);
    }

    private function cleanupAbandonedRuns(string $currentToken, string $currentRoot, string $runsRoot): void
    {
        if (!is_dir($runsRoot)) {
            return;
        }

        foreach (File::directories($runsRoot) as $directory) {
            $token = basename($directory);
            if ($token === $currentToken || !preg_match('/\A[a-zA-Z0-9_-]{8,80}\z/', $token)) {
                continue;
            }

            $journalPath = $directory . DIRECTORY_SEPARATOR . 'journal.json';
            $journal = is_file($journalPath)
                ? json_decode((string) file_get_contents($journalPath), true)
                : null;
            if (!is_array($journal)
                || $this->pathKey((string) ($journal['root'] ?? '')) !== $this->pathKey($currentRoot)) {
                continue;
            }

            $this->cleanupRun($token);
        }
    }

    /** @return array<int, string> */
    private function videoFiles(string $root): array
    {
        $extensions = array_map('strtolower', (array) config('tg_video_review.extensions', []));
        $files = [];
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isFile() && in_array(strtolower($item->getExtension()), $extensions, true)) {
                $files[] = [
                    'path' => $item->getPathname(),
                    'created_at' => (int) $item->getCTime(),
                ];
            }
        }

        usort($files, function (array $left, array $right): int {
            $timeOrder = $left['created_at'] <=> $right['created_at'];
            return $timeOrder !== 0 ? $timeOrder : strnatcasecmp($left['path'], $right['path']);
        });

        return array_column($files, 'path');
    }

    /** @param array<int, string> $videos */
    private function assertNoImageNameCollisions(array $videos): void
    {
        $names = [];
        foreach ($videos as $video) {
            $key = mb_strtolower(pathinfo($video, PATHINFO_FILENAME));
            if (isset($names[$key])) {
                throw new RuntimeException('有兩支影片會產生相同 JPG 名稱，請先更名後再掃描。');
            }
            $names[$key] = true;
        }
    }

    private function probeDuration(string $videoPath): float
    {
        $process = new Process([
            (string) config('tg_video_review.ffprobe_bin'), '-v', 'error',
            '-show_entries', 'format=duration', '-of', 'default=noprint_wrappers=1:nokey=1', $videoPath,
        ]);
        $process->setTimeout(60);
        $process->mustRun();
        $duration = (float) trim($process->getOutput());
        if (!is_finite($duration) || $duration <= 0) {
            throw new RuntimeException('無法取得有效影片長度。');
        }
        return $duration;
    }

    private function assertMediaToolsAvailable(): void
    {
        foreach (['ffprobe_bin', 'ffmpeg_bin'] as $key) {
            $path = (string) config('tg_video_review.' . $key);
            if ($path === '' || !is_file($path)) {
                throw new RuntimeException('FFmpeg 工具設定無效，已停止掃描。');
            }
        }
    }

    private function generateContactSheet(string $videoPath, string $outputPath, float $duration): void
    {
        $columns = (int) config('tg_video_review.contact_sheet_columns', 5);
        $rows = (int) config('tg_video_review.contact_sheet_rows', 4);
        $height = (int) config('tg_video_review.cell_height', 270);
        $frameCount = $columns * $rows;
        // Ask for one extra sample so videos whose final timestamp falls just
        // short of their container duration still yield a complete tile.
        $frameRate = ($frameCount + 1) / $duration;
        $filter = sprintf(
            'setpts=N/FRAME_RATE/TB,setparams=colorspace=bt709:color_primaries=bt709:color_trc=bt709,fps=%.12F,scale=-2:%d,format=yuvj420p,tile=%dx%d:padding=4:margin=4',
            $frameRate, $height, $columns, $rows
        );

        $process = new Process([
            (string) config('tg_video_review.ffmpeg_bin'), '-hide_banner', '-loglevel', 'error', '-y',
            '-fflags', '+discardcorrupt', '-err_detect', 'ignore_err',
            '-i', $videoPath, '-vf', $filter, '-frames:v', '1', '-q:v', '3', $outputPath,
        ]);
        $process->setTimeout(600);
        $process->mustRun();

        $size = @getimagesize($outputPath);
        if (!is_array($size) || $size[0] < $columns * 100 || $size[1] < $rows * 80) {
            throw new RuntimeException('接觸表驗證失敗。');
        }
    }

    /** @return array{size: int, modified_at: int}|null */
    private function readVideoMetadata(string $videoPath): ?array
    {
        clearstatcache(true, $videoPath);
        $size = @filesize($videoPath);
        $modifiedAt = @filemtime($videoPath);
        if ($size === false || $modifiedAt === false) {
            return null;
        }

        return [
            'size' => (int) $size,
            'modified_at' => (int) $modifiedAt,
        ];
    }

    private function writeJournal(string $path, array $journal): void
    {
        File::ensureDirectoryExists(dirname($path));
        $bytes = file_put_contents(
            $path,
            json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        if ($bytes === false) {
            throw new RuntimeException('無法寫入掃描回滾紀錄。');
        }
    }

    private function runsRootPath(): string
    {
        $path = rtrim((string) config(
            'tg_video_review.runs_root_path',
            storage_path('app/tg-video-review-runs')
        ), '\\/');
        if ($path === '') {
            throw new RuntimeException('掃描暫存目錄設定無效。');
        }

        return $path;
    }

    private function safeToken(string $token): string
    {
        if (!preg_match('/\A[a-zA-Z0-9_-]{8,80}\z/', $token)) {
            throw new RuntimeException('掃描識別碼格式無效。');
        }
        return $token;
    }

    private function hashPath(string $path): string
    {
        return sha1($this->pathKey($path));
    }

    private function pathKey(string $path): string
    {
        return mb_strtolower(str_replace('\\', '/', $path));
    }
}
