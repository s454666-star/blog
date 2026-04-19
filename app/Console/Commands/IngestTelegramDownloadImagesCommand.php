<?php

namespace App\Console\Commands;

use App\Services\ImagePerceptualHashService;
use FilesystemIterator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class IngestTelegramDownloadImagesCommand extends Command
{
    protected $signature = 'image:ingest-telegram-downloads
        {--source=C:\Users\User\Downloads : 要掃描的來源資料夾}
        {--target=Y:\圖 : 要搬進去並做去重的目標資料夾}
        {--pattern=telegram-image*.jpg : 來源檔名樣式}
        {--recursive=1 : 1=遞迴掃描 target 子資料夾，0=只掃一層}
        {--threshold=90 : 視為相似重複的最低相似度百分比}
        {--dry-run : 只顯示結果，不移動也不刪除}';

    protected $description = '把 Downloads 裡 telegram-image*.jpg 搬到指定資料夾，然後刪除目標資料夾中相似度達門檻的 JPG/JPEG。';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg'];

    public function handle(ImagePerceptualHashService $hashService): int
    {
        $sourceDir = $this->normalizeAbsolutePath((string) $this->option('source'));
        $targetDir = $this->normalizeAbsolutePath((string) $this->option('target'));
        $pattern = trim((string) $this->option('pattern'));
        $recursive = (int) $this->option('recursive') === 1;
        $dryRun = (bool) $this->option('dry-run');
        $threshold = (float) $this->option('threshold');

        if ($sourceDir === '' || !is_dir($sourceDir)) {
            $this->error('source 不是有效資料夾：' . $this->option('source'));
            return self::FAILURE;
        }

        if ($targetDir === '') {
            $this->error('target 不可為空。');
            return self::FAILURE;
        }

        if ($pattern === '') {
            $this->error('pattern 不可為空。');
            return self::FAILURE;
        }

        if ($threshold < 0 || $threshold > 100) {
            $this->error('threshold 必須介於 0 到 100。');
            return self::FAILURE;
        }

        if (!$dryRun) {
            File::ensureDirectoryExists($targetDir);
        }

        $sourceFiles = $this->collectSourceFiles($sourceDir, $pattern);

        $this->info('Source: ' . $sourceDir);
        $this->info('Target: ' . $targetDir);
        $this->line(sprintf(
            'pattern=%s recursive=%d threshold=%.2f dry-run=%d source_matches=%d',
            $pattern,
            $recursive ? 1 : 0,
            $threshold,
            $dryRun ? 1 : 0,
            count($sourceFiles)
        ));

        $moved = 0;
        $moveFailed = 0;
        $reservedDestinationPaths = [];
        if (is_dir($targetDir)) {
            foreach ($this->collectTargetImages($targetDir, $recursive) as $existingPath) {
                $reservedDestinationPaths[mb_strtolower($existingPath)] = true;
            }
        }
        $dryRunPlannedEntries = [];

        foreach ($sourceFiles as $sourcePath) {
            $destinationPath = $this->buildUniqueDestination(
                $targetDir,
                basename($sourcePath),
                array_keys($reservedDestinationPaths)
            );
            $reservedDestinationPaths[mb_strtolower($destinationPath)] = true;

            if ($dryRun) {
                $this->line('準備搬移：' . $sourcePath . ' -> ' . $destinationPath);
                $moved++;
                $dryRunPlannedEntries[] = [
                    'display_path' => $destinationPath,
                    'actual_path' => $sourcePath,
                ];
                continue;
            }

            try {
                $this->moveFile($sourcePath, $destinationPath);
                $moved++;
                $this->info('已搬移：' . $sourcePath . ' -> ' . $destinationPath);
            } catch (Throwable $e) {
                $moveFailed++;
                $this->error('搬移失敗：' . $sourcePath . ' (' . $e->getMessage() . ')');
            }
        }

        if (!is_dir($targetDir)) {
            $this->warn('target 尚不存在，略過去重：' . $targetDir);

            return $moveFailed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $analysisTargets = $this->collectAnalysisTargets($targetDir, $recursive, $dryRunPlannedEntries);
        $this->line('target_scan_files=' . count($analysisTargets));

        if ($analysisTargets === []) {
            $this->warn('目標資料夾沒有 JPG/JPEG 可掃描。');

            return $moveFailed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $entries = [];
        $analysisFailed = 0;

        foreach ($analysisTargets as $analysisTarget) {
            $displayPath = (string) ($analysisTarget['display_path'] ?? '');
            $actualPath = (string) ($analysisTarget['actual_path'] ?? '');

            try {
                $sha256 = @hash_file('sha256', $actualPath);
                if (!is_string($sha256) || $sha256 === '') {
                    throw new RuntimeException('無法計算 SHA-256');
                }

                $entries[] = [
                    'path' => $displayPath,
                    'actual_path' => $actualPath,
                    'sha256' => strtolower($sha256),
                    'dhash_hex' => $hashService->computeDhashHex($actualPath),
                    'file_modified_at' => (int) @filemtime($actualPath),
                    'file_created_at' => (int) @filectime($actualPath),
                    'file_size_bytes' => (int) (@filesize($actualPath) ?: 0),
                ];
            } catch (Throwable $e) {
                $analysisFailed++;
                $this->warn('跳過無法分析的圖片：' . $displayPath . ' (' . $e->getMessage() . ')');
            }
        }

        usort($entries, fn (array $left, array $right): int => $this->compareKeepPriority($left, $right));

        $keepers = [];
        $deleted = 0;
        $duplicateHits = 0;
        $deleteFailed = 0;

        foreach ($entries as $entry) {
            $bestMatch = $this->findBestDuplicateMatch($entry, $keepers, $hashService, $threshold);

            if ($bestMatch === null) {
                $keepers[] = $entry;
                continue;
            }

            $duplicateHits++;
            $message = sprintf(
                '%s：%s (keep=%s similarity=%s match=%s size=%s)',
                $dryRun ? 'dry-run 刪除' : '已刪除',
                $entry['path'],
                $bestMatch['keeper']['path'],
                number_format((float) $bestMatch['similarity_percent'], 2) . '%',
                $bestMatch['match_type'],
                $this->formatBytes((int) ($entry['file_size_bytes'] ?? 0))
            );

            if ($dryRun) {
                $this->line($message);
                $deleted++;
                continue;
            }

            if (!@unlink((string) ($entry['actual_path'] ?? $entry['path'] ?? ''))) {
                $deleteFailed++;
                $this->error('刪除失敗：' . $entry['path']);
                continue;
            }

            $deleted++;
            $this->warn($message);
        }

        $this->newLine();
        $this->info(sprintf(
            '完成，source_matches=%d moved=%d target_scanned=%d kept=%d duplicate_hits=%d deleted=%d move_failed=%d analysis_failed=%d delete_failed=%d',
            count($sourceFiles),
            $moved,
            count($entries),
            count($keepers),
            $duplicateHits,
            $deleted,
            $moveFailed,
            $analysisFailed,
            $deleteFailed
        ));

        return ($moveFailed + $analysisFailed + $deleteFailed) > 0
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function collectSourceFiles(string $sourceDir, string $pattern): array
    {
        $globPattern = rtrim($sourceDir, '\\/') . DIRECTORY_SEPARATOR . $pattern;
        $files = File::glob($globPattern) ?: [];

        $result = [];
        foreach ($files as $path) {
            $normalizedPath = $this->normalizeAbsolutePath((string) $path);
            if ($normalizedPath !== '' && is_file($normalizedPath)) {
                $result[] = $normalizedPath;
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

    private function collectTargetImages(string $targetDir, bool $recursive): array
    {
        $result = [];

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($targetDir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
                if ($this->isSupportedImage($path)) {
                    $result[] = $path;
                }
            }
        } else {
            foreach (File::files($targetDir) as $fileInfo) {
                $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
                if ($this->isSupportedImage($path)) {
                    $result[] = $path;
                }
            }
        }

        return $result;
    }

    private function collectAnalysisTargets(string $targetDir, bool $recursive, array $dryRunPlannedEntries = []): array
    {
        $targets = [];
        $seen = [];

        foreach ($this->collectTargetImages($targetDir, $recursive) as $path) {
            $normalized = mb_strtolower($path);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $targets[] = [
                'display_path' => $path,
                'actual_path' => $path,
            ];
        }

        foreach ($dryRunPlannedEntries as $entry) {
            $displayPath = (string) ($entry['display_path'] ?? '');
            $actualPath = (string) ($entry['actual_path'] ?? '');
            if ($displayPath === '' || $actualPath === '') {
                continue;
            }

            $normalized = mb_strtolower($displayPath);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $targets[] = [
                'display_path' => $displayPath,
                'actual_path' => $actualPath,
            ];
        }

        return $targets;
    }

    private function isSupportedImage(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
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

    private function findBestDuplicateMatch(
        array $entry,
        array $keepers,
        ImagePerceptualHashService $hashService,
        float $threshold
    ): ?array {
        $bestMatch = null;

        foreach ($keepers as $keeper) {
            if (($entry['sha256'] ?? '') === ($keeper['sha256'] ?? '')) {
                return [
                    'keeper' => $keeper,
                    'similarity_percent' => 100.0,
                    'match_type' => 'sha256',
                ];
            }

            $similarity = $hashService->similarityPercent(
                (string) ($entry['dhash_hex'] ?? ''),
                (string) ($keeper['dhash_hex'] ?? '')
            );

            if ($similarity < $threshold) {
                continue;
            }

            if ($bestMatch === null || $similarity > (float) $bestMatch['similarity_percent']) {
                $bestMatch = [
                    'keeper' => $keeper,
                    'similarity_percent' => $similarity,
                    'match_type' => 'dhash',
                ];
            }
        }

        return $bestMatch;
    }

    private function buildUniqueDestination(string $targetDir, string $basename, array $reservedPaths = []): string
    {
        $basename = trim($basename);
        if ($basename === '') {
            $basename = 'telegram-image.jpg';
        }

        $info = pathinfo($basename);
        $name = (string) ($info['filename'] ?? 'telegram-image');
        $extension = (string) ($info['extension'] ?? '');
        $candidate = rtrim($targetDir, '\\/') . DIRECTORY_SEPARATOR . $basename;
        $suffix = 2;

        $reservedLookup = [];
        foreach ($reservedPaths as $reservedPath) {
            $reservedLookup[mb_strtolower((string) $reservedPath)] = true;
        }

        while (file_exists($candidate) || isset($reservedLookup[mb_strtolower($candidate)])) {
            $candidate = rtrim($targetDir, '\\/') . DIRECTORY_SEPARATOR . sprintf(
                '%s_%d%s',
                $name,
                $suffix,
                $extension !== '' ? '.' . $extension : ''
            );
            $suffix++;
        }

        return $candidate;
    }

    private function moveFile(string $sourcePath, string $destinationPath): void
    {
        File::ensureDirectoryExists(dirname($destinationPath));

        $modifiedAt = @filemtime($sourcePath);
        $accessedAt = @fileatime($sourcePath);

        if (@rename($sourcePath, $destinationPath)) {
            return;
        }

        if (!@copy($sourcePath, $destinationPath)) {
            throw new RuntimeException('rename / copy 都失敗。');
        }

        if ($modifiedAt !== false) {
            @touch(
                $destinationPath,
                (int) $modifiedAt,
                $accessedAt !== false ? (int) $accessedAt : (int) $modifiedAt
            );
        }

        if (!@unlink($sourcePath)) {
            @unlink($destinationPath);
            throw new RuntimeException('copy 成功，但刪除來源檔失敗。');
        }
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
}
