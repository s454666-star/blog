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
    /**
     * @param callable(int, int, string): void|null $progress
     * @return array{videos: int, generated: int, unchanged: int}
     */
    public function scan(?string $requestedRoot = null, ?callable $progress = null, ?string $runToken = null): array
    {
        $root = realpath($requestedRoot ?: (string) config('tg_video_review.root'));
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('掃描目錄不存在。');
        }

        $lockPath = storage_path('app/tg-video-review-scan.lock');
        File::ensureDirectoryExists(dirname($lockPath));
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('已有另一個 TG 暫存掃描正在執行。');
        }

        $token = $this->safeToken($runToken ?: bin2hex(random_bytes(16)));
        $this->cleanupAbandonedRuns($token);
        $runDirectory = storage_path('app/tg-video-review-runs/' . $token);
        $stageDirectory = $runDirectory . DIRECTORY_SEPARATOR . 'stage';
        File::ensureDirectoryExists($stageDirectory);
        $journalPath = $runDirectory . DIRECTORY_SEPARATOR . 'journal.json';
        $journal = [
            'token' => $token,
            'root' => $root,
            'status' => 'staging',
            'entries' => [],
            'database_before' => [],
        ];
        $this->writeJournal($journalPath, $journal);

        try {
            $videos = $this->videoFiles($root);
            $this->assertNoImageNameCollisions($videos);
            $total = count($videos);
            $generated = 0;
            $unchanged = 0;

            foreach ($videos as $index => $videoPath) {
                $imagePath = $root . DIRECTORY_SEPARATOR . pathinfo($videoPath, PATHINFO_FILENAME) . '.jpg';
                $pathHash = $this->hashPath($videoPath);
                $fileSize = (int) filesize($videoPath);
                $modifiedAt = (int) filemtime($videoPath);
                $existing = TgVideoReview::query()->where('path_hash', $pathHash)->first();

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
                    if ($progress !== null) {
                        $progress($index + 1, $total, '已存在，略過');
                    }
                    continue;
                }

                $duration = $this->probeDuration($videoPath);
                $stagePath = $stageDirectory . DIRECTORY_SEPARATOR . sprintf('%04d.jpg', $index + 1);
                $this->generateContactSheet($videoPath, $stagePath, $duration);
                $journal['entries'][] = [
                    'path_hash' => $pathHash,
                    'video_path' => $videoPath,
                    'image_path' => $imagePath,
                    'stage_path' => $stagePath,
                    'backup_path' => null,
                    'published' => false,
                    'file_size_bytes' => $fileSize,
                    'file_modified_at' => $modifiedAt,
                    'duration_seconds' => $duration,
                ];
                $this->writeJournal($journalPath, $journal);
                $generated++;
                if ($progress !== null) {
                    $progress($index + 1, $total, '已產生 5×4 接觸表');
                }
            }

            $journal['status'] = 'publishing';
            foreach ($journal['entries'] as $entryIndex => $entry) {
                $existing = TgVideoReview::query()->where('path_hash', $entry['path_hash'])->first();
                $journal['database_before'][$entry['path_hash']] = $existing?->getAttributes();

                if (is_file($entry['image_path'])) {
                    $backupPath = $runDirectory . DIRECTORY_SEPARATOR . 'backup-' . $entryIndex . '.jpg';
                    $journal['entries'][$entryIndex]['backup_path'] = $backupPath;
                    $this->writeJournal($journalPath, $journal);
                    if (!rename($entry['image_path'], $backupPath)) {
                        throw new RuntimeException('無法暫存既有接觸表。');
                    }
                }

                $this->writeJournal($journalPath, $journal);
                if (!rename($entry['stage_path'], $entry['image_path'])) {
                    throw new RuntimeException('無法發布接觸表。');
                }
                $journal['entries'][$entryIndex]['published'] = true;
                $this->writeJournal($journalPath, $journal);
            }

            DB::transaction(function () use ($journal): void {
                foreach ($journal['entries'] as $entry) {
                    TgVideoReview::query()->updateOrCreate(
                        ['path_hash' => $entry['path_hash']],
                        [
                            'video_path' => $entry['video_path'],
                            'image_path' => $entry['image_path'],
                            'file_size_bytes' => $entry['file_size_bytes'],
                            'file_modified_at' => $entry['file_modified_at'],
                            'duration_seconds' => $entry['duration_seconds'],
                            'screenshot_count' => 20,
                        ]
                    );
                }
            });

            $journal['status'] = 'completed';
            $this->writeJournal($journalPath, $journal);
            File::deleteDirectory($runDirectory);

            return ['videos' => $total, 'generated' => $generated, 'unchanged' => $unchanged];
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
        $runDirectory = storage_path('app/tg-video-review-runs/' . $token);
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

        DB::transaction(function () use ($journal): void {
            foreach ((array) ($journal['database_before'] ?? []) as $pathHash => $attributes) {
                if (is_array($attributes)) {
                    TgVideoReview::query()->updateOrCreate(['path_hash' => $pathHash], $attributes);
                } else {
                    TgVideoReview::query()->where('path_hash', $pathHash)->delete();
                }
            }
        });

        File::deleteDirectory($runDirectory);
    }

    private function cleanupAbandonedRuns(string $currentToken): void
    {
        $runsRoot = storage_path('app/tg-video-review-runs');
        if (!is_dir($runsRoot)) {
            return;
        }

        foreach (File::directories($runsRoot) as $directory) {
            $token = basename($directory);
            if ($token !== $currentToken && preg_match('/\A[a-zA-Z0-9_-]{8,80}\z/', $token)) {
                $this->cleanupRun($token);
            }
        }
    }

    /** @return array<int, string> */
    private function videoFiles(string $root): array
    {
        $extensions = array_map('strtolower', (array) config('tg_video_review.extensions', []));
        $files = [];
        foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isFile() && in_array(strtolower($item->getExtension()), $extensions, true)) {
                $files[] = $item->getPathname();
            }
        }
        natcasesort($files);
        return array_values($files);
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

    private function generateContactSheet(string $videoPath, string $outputPath, float $duration): void
    {
        $columns = (int) config('tg_video_review.contact_sheet_columns', 5);
        $rows = (int) config('tg_video_review.contact_sheet_rows', 4);
        $width = (int) config('tg_video_review.cell_width', 480);
        $height = (int) config('tg_video_review.cell_height', 270);
        $frameRate = ($columns * $rows) / $duration;
        $filter = sprintf(
            'fps=%.12F,scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2:black,tile=%dx%d:padding=4:margin=4',
            $frameRate, $width, $height, $width, $height, $columns, $rows
        );

        $process = new Process([
            (string) config('tg_video_review.ffmpeg_bin'), '-hide_banner', '-loglevel', 'error', '-y',
            '-i', $videoPath, '-vf', $filter, '-frames:v', '1', '-q:v', '3', $outputPath,
        ]);
        $process->setTimeout(600);
        $process->mustRun();

        $size = @getimagesize($outputPath);
        if (!is_array($size) || $size[0] < $columns * 100 || $size[1] < $rows * 80) {
            throw new RuntimeException('接觸表驗證失敗。');
        }
    }

    private function writeJournal(string $path, array $journal): void
    {
        $bytes = file_put_contents(
            $path,
            json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            LOCK_EX
        );
        if ($bytes === false) {
            throw new RuntimeException('無法寫入掃描回滾紀錄。');
        }
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
