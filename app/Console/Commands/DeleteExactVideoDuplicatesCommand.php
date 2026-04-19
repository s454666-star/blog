<?php

namespace App\Console\Commands;

use App\Services\MediaDurationProbeService;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class DeleteExactVideoDuplicatesCommand extends Command
{
    protected $signature = 'video:delete-exact-duplicates
        {path : 必填資料夾路徑}
        {--recursive=1 : 1=掃子資料夾，0=只掃一層}
        {--dry-run : 只顯示結果不刪檔}';

    protected $description = '只看檔案內容找出完全相同的影片；同內容保留最早那份，其餘直接刪除，不做截圖比對。';

    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'm4v', 'mpeg', 'mpg'];

    public function handle(MediaDurationProbeService $durationProbeService): int
    {
        $rootPath = $this->normalizeAbsolutePath((string) $this->argument('path'));
        if ($rootPath === '' || !is_dir($rootPath)) {
            $this->error('path 不是有效資料夾：' . $this->argument('path'));
            return self::FAILURE;
        }

        $recursive = (int) $this->option('recursive') === 1;
        $dryRun = (bool) $this->option('dry-run');
        $files = $this->collectVideoFiles($rootPath, $recursive);

        $this->info('Root: ' . $rootPath);
        $this->line(sprintf(
            'recursive=%d dry-run=%d files=%d hash=sha256',
            $recursive ? 1 : 0,
            $dryRun ? 1 : 0,
            count($files)
        ));

        if ($files === []) {
            $this->warn('找不到影片檔。');
            return self::SUCCESS;
        }

        $entriesBySize = [];
        $failed = 0;

        foreach ($files as $filePath) {
            if (!is_file($filePath)) {
                $failed++;
                $this->warn('跳過不存在檔案：' . $filePath);
                continue;
            }

            $fileSize = @filesize($filePath);
            if ($fileSize === false) {
                $failed++;
                $this->error('無法取得檔案大小：' . $filePath);
                continue;
            }

            $entriesBySize[(string) $fileSize][] = [
                'path' => $filePath,
                'file_size_bytes' => (int) $fileSize,
                'file_modified_at' => (int) @filemtime($filePath),
                'file_created_at' => (int) @filectime($filePath),
            ];
        }

        $exactGroups = 0;
        $kept = 0;
        $deleted = 0;

        foreach ($entriesBySize as $sizeEntries) {
            if (count($sizeEntries) < 2) {
                continue;
            }

            $entriesByHash = [];

            foreach ($sizeEntries as $entry) {
                $hash = @hash_file('sha256', (string) $entry['path']);
                if (!is_string($hash) || $hash === '') {
                    $failed++;
                    $this->error('無法計算 SHA-256：' . $entry['path']);
                    continue;
                }

                $entry['sha256'] = strtolower($hash);
                $entriesByHash[$entry['sha256']][] = $entry;
            }

            foreach ($entriesByHash as $hashEntries) {
                if (count($hashEntries) < 2) {
                    continue;
                }

                foreach ($hashEntries as $index => $entry) {
                    $durationSeconds = null;
                    $durationError = null;

                    try {
                        $durationSeconds = round($durationProbeService->probeDurationSeconds((string) $entry['path']), 3);
                    } catch (Throwable $e) {
                        $durationError = $e->getMessage();
                    }

                    $hashEntries[$index]['duration_seconds'] = $durationSeconds;
                    $hashEntries[$index]['duration_error'] = $durationError;
                }

                usort($hashEntries, fn (array $left, array $right): int => $this->compareKeepPriority($left, $right));

                $keeper = array_shift($hashEntries);
                if (!is_array($keeper)) {
                    continue;
                }

                $exactGroups++;
                $kept++;

                $durationSummary = $this->formatDuration($keeper['duration_seconds'] ?? null, $keeper['duration_error'] ?? null);
                $this->warn(sprintf(
                    '完全相同：size=%s duration=%s sha256=%s…',
                    $this->formatBytes((int) $keeper['file_size_bytes']),
                    $durationSummary,
                    substr((string) ($keeper['sha256'] ?? ''), 0, 12)
                ));
                $this->line('  保留：' . $keeper['path']);

                foreach ($hashEntries as $duplicate) {
                    $duplicateDuration = $this->formatDuration(
                        $duplicate['duration_seconds'] ?? null,
                        $duplicate['duration_error'] ?? null
                    );

                    if ($dryRun) {
                        $this->line(sprintf(
                            '  dry-run 刪除：%s (duration=%s)',
                            $duplicate['path'],
                            $duplicateDuration
                        ));
                        continue;
                    }

                    if (!File::delete((string) $duplicate['path'])) {
                        $failed++;
                        $this->error(sprintf(
                            '  刪除失敗：%s (duration=%s)',
                            $duplicate['path'],
                            $duplicateDuration
                        ));
                        continue;
                    }

                    $deleted++;
                    $this->info(sprintf(
                        '  已刪除：%s (duration=%s)',
                        $duplicate['path'],
                        $duplicateDuration
                    ));
                }
            }
        }

        if ($exactGroups === 0) {
            $this->line('沒有找到完全相同的影片檔。');
        }

        $this->newLine();
        $this->info(sprintf(
            '完成，scanned=%d exact_groups=%d kept=%d deleted=%d failed=%d',
            count($files),
            $exactGroups,
            $kept,
            $deleted,
            $failed
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function compareKeepPriority(array $left, array $right): int
    {
        $leftModifiedAt = (int) ($left['file_modified_at'] ?? 0);
        $rightModifiedAt = (int) ($right['file_modified_at'] ?? 0);
        if ($leftModifiedAt !== $rightModifiedAt) {
            return $leftModifiedAt <=> $rightModifiedAt;
        }

        $leftCreatedAt = (int) ($left['file_created_at'] ?? 0);
        $rightCreatedAt = (int) ($right['file_created_at'] ?? 0);
        if ($leftCreatedAt !== $rightCreatedAt) {
            return $leftCreatedAt <=> $rightCreatedAt;
        }

        return strcmp(mb_strtolower((string) ($left['path'] ?? '')), mb_strtolower((string) ($right['path'] ?? '')));
    }

    private function collectVideoFiles(string $rootPath, bool $recursive): array
    {
        $result = [];

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
                if ($this->isVideoFile($path)) {
                    $result[] = $path;
                }
            }
        } else {
            foreach (File::files($rootPath) as $fileInfo) {
                $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
                if ($this->isVideoFile($path)) {
                    $result[] = $path;
                }
            }
        }

        usort($result, function (string $left, string $right): int {
            $leftMtime = (int) @filemtime($left);
            $rightMtime = (int) @filemtime($right);

            if ($leftMtime !== $rightMtime) {
                return $leftMtime <=> $rightMtime;
            }

            return strcmp(mb_strtolower($left), mb_strtolower($right));
        });

        return $result;
    }

    private function isVideoFile(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::VIDEO_EXTENSIONS, true);
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

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return number_format($bytes) . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $lastUnit = $units[array_key_last($units)];
        $value = (float) $bytes;

        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024 || $unit === $lastUnit) {
                return number_format($value, 2) . ' ' . $unit;
            }
        }

        return number_format($bytes) . ' B';
    }

    private function formatDuration(?float $seconds, ?string $errorMessage = null): string
    {
        if ($errorMessage !== null && $errorMessage !== '') {
            return 'probe-failed';
        }

        if ($seconds === null) {
            return 'unknown';
        }

        return number_format($seconds, 3, '.', '') . 's';
    }
}
