<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ReferenceVideoFeatureIndexService
{
    private const LOG_CHANNEL = 'video_duplicate_scan';

    private const INDEX_FILENAME = 'video-feature-index.json';

    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'm4v', 'mpeg', 'mpg'];

    public function __construct(
        private readonly VideoFeatureExtractionService $featureExtractionService
    ) {
    }

    public function syncDirectory(string $directoryPath, int $limit = 0): array
    {
        $directoryPath = $this->normalizeAbsolutePath($directoryPath);
        $limit = max(0, $limit);
        if ($directoryPath === '') {
            throw new RuntimeException('reference dir 不可為空。');
        }

        File::ensureDirectoryExists($directoryPath);

        if (!is_dir($directoryPath)) {
            throw new RuntimeException('reference dir 不是有效資料夾：' . $directoryPath);
        }

        $indexPath = $directoryPath . DIRECTORY_SEPARATOR . self::INDEX_FILENAME;
        $existingSnapshots = $this->loadSnapshotsFromIndex($indexPath);
        Log::channel(self::LOG_CHANNEL)->info('reference video feature index sync started', [
            'directory_path' => $directoryPath,
            'index_path' => $indexPath,
            'existing_snapshot_count' => count($existingSnapshots),
            'limit' => $limit,
            'pid' => getmypid(),
        ]);
        $existingSnapshotsByPathHash = [];

        foreach ($existingSnapshots as $snapshot) {
            $normalizedSnapshot = $this->normalizeSnapshot($snapshot);
            if ($normalizedSnapshot === null) {
                continue;
            }

            $existingSnapshotsByPathHash[$this->hashPath($normalizedSnapshot['absolute_path'])] = $normalizedSnapshot;
        }

        $duplicateDir = $this->normalizeAbsolutePath($directoryPath . DIRECTORY_SEPARATOR . '疑似重複檔案');
        $files = $this->collectVideoFiles($directoryPath, $duplicateDir);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }
        Log::channel(self::LOG_CHANNEL)->info('reference video feature index collected files', [
            'directory_path' => $directoryPath,
            'duplicate_directory_path' => $duplicateDir,
            'file_count' => count($files),
            'limit' => $limit,
        ]);
        $currentPathHashes = [];
        $snapshots = [];
        $reusedCount = 0;
        $extractedCount = 0;
        $failedFiles = [];

        foreach ($files as $filePath) {
            $pathHash = $this->hashPath($filePath);
            $currentPathHashes[] = $pathHash;

            $existingSnapshot = $existingSnapshotsByPathHash[$pathHash] ?? null;
            if ($existingSnapshot !== null && $this->isSnapshotFresh($existingSnapshot, $filePath)) {
                $snapshots[] = $this->refreshSnapshotMetadata($existingSnapshot, $filePath);
                $reusedCount++;
                Log::channel(self::LOG_CHANNEL)->info('reference video feature index reused existing snapshot', [
                    'directory_path' => $directoryPath,
                    'file_path' => $filePath,
                ]);
                continue;
            }

            $payload = null;
            Log::channel(self::LOG_CHANNEL)->info('reference video feature index extracting file payload', [
                'directory_path' => $directoryPath,
                'file_path' => $filePath,
            ]);

            try {
                $payload = $this->featureExtractionService->inspectFile($filePath);
                $snapshots[] = $this->snapshotFromPayload($payload);
                $extractedCount++;
                Log::channel(self::LOG_CHANNEL)->info('reference video feature index extracted file payload', [
                    'directory_path' => $directoryPath,
                    'file_path' => $filePath,
                    'duration_seconds' => $payload['duration_seconds'] ?? null,
                    'file_size_bytes' => $payload['file_size_bytes'] ?? null,
                    'frame_count' => count((array) ($payload['frames'] ?? [])),
                    'capture_rule' => $payload['capture_rule'] ?? null,
                ]);
            } catch (Throwable $e) {
                $failedFiles[] = [
                    'absolute_path' => $filePath,
                    'message' => $e->getMessage(),
                ];
                Log::channel(self::LOG_CHANNEL)->warning('reference video feature index failed to extract file payload', [
                    'directory_path' => $directoryPath,
                    'file_path' => $filePath,
                    'error_message' => $e->getMessage(),
                ]);
            } finally {
                if (is_array($payload)) {
                    $this->featureExtractionService->cleanupPayload($payload);
                    Log::channel(self::LOG_CHANNEL)->info('reference video feature index cleaned temporary payload', [
                        'directory_path' => $directoryPath,
                        'file_path' => $filePath,
                    ]);
                }
            }
        }

        usort($snapshots, function (array $left, array $right): int {
            return strcmp(
                mb_strtolower((string) ($left['absolute_path'] ?? '')),
                mb_strtolower((string) ($right['absolute_path'] ?? ''))
            );
        });

        $removedCount = count(array_diff(array_keys($existingSnapshotsByPathHash), array_values(array_unique($currentPathHashes))));
        Log::channel(self::LOG_CHANNEL)->info('reference video feature index writing json file', [
            'directory_path' => $directoryPath,
            'index_path' => $indexPath,
            'total_files' => count($snapshots),
            'reused_count' => $reusedCount,
            'extracted_count' => $extractedCount,
            'removed_count' => $removedCount,
            'failed_count' => count($failedFiles),
            'limit' => $limit,
        ]);

        $this->writeIndex($indexPath, [
            'version' => 1,
            'reference_directory_path' => $directoryPath,
            'generated_at' => now()->toIso8601String(),
            'total_files' => count($snapshots),
            'failed_count' => count($failedFiles),
            'snapshots' => $snapshots,
            'failed_files' => $failedFiles,
        ]);
        Log::channel(self::LOG_CHANNEL)->info('reference video feature index sync finished', [
            'directory_path' => $directoryPath,
            'index_path' => $indexPath,
            'total_files' => count($snapshots),
            'reused_count' => $reusedCount,
            'extracted_count' => $extractedCount,
            'removed_count' => $removedCount,
            'failed_count' => count($failedFiles),
            'limit' => $limit,
        ]);

        return [
            'directory_path' => $directoryPath,
            'index_path' => $indexPath,
            'snapshots' => $snapshots,
            'total_files' => count($snapshots),
            'reused_count' => $reusedCount,
            'extracted_count' => $extractedCount,
            'removed_count' => $removedCount,
            'failed_count' => count($failedFiles),
            'failed_files' => $failedFiles,
            'limit' => $limit,
        ];
    }

    public function buildComparisonSnapshots(array $snapshots, string $sourceFilePath): array
    {
        $sourceFilePathLower = mb_strtolower($this->normalizeAbsolutePath($sourceFilePath));

        return array_values(array_filter($snapshots, function (array $snapshot) use ($sourceFilePathLower): bool {
            $candidatePath = $this->normalizeAbsolutePath((string) ($snapshot['absolute_path'] ?? ''));

            return $candidatePath !== ''
                && mb_strtolower($candidatePath) !== $sourceFilePathLower;
        }));
    }

    public function normalizeAbsolutePath(string $path): string
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

    public function hashPath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));

        return sha1(mb_strtolower($normalized));
    }

    private function loadSnapshotsFromIndex(string $indexPath): array
    {
        if (!is_file($indexPath)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            Log::channel(self::LOG_CHANNEL)->warning('reference video feature index json parse failed; fallback to empty snapshots', [
                'index_path' => $indexPath,
            ]);
            return [];
        }

        $snapshots = $decoded['snapshots'] ?? [];

        return is_array($snapshots) ? $snapshots : [];
    }

    private function isSnapshotFresh(array $snapshot, string $filePath): bool
    {
        $snapshot = $this->normalizeSnapshot($snapshot);
        if ($snapshot === null) {
            return false;
        }

        if ($snapshot['absolute_path'] !== $filePath) {
            return false;
        }

        $currentSize = @filesize($filePath);
        $currentModifiedTimestamp = @filemtime($filePath);

        if (!is_int($currentModifiedTimestamp)) {
            return false;
        }

        if (
            isset($snapshot['file_size_bytes'])
            && $currentSize !== false
            && (int) $snapshot['file_size_bytes'] !== (int) $currentSize
        ) {
            return false;
        }

        if (($snapshot['file_modified_timestamp'] ?? null) !== $currentModifiedTimestamp) {
            return false;
        }

        return !empty($snapshot['frames']) && (float) ($snapshot['duration_seconds'] ?? 0) > 0;
    }

    private function refreshSnapshotMetadata(array $snapshot, string $filePath): array
    {
        $snapshot = $this->normalizeSnapshot($snapshot);
        if ($snapshot === null) {
            throw new RuntimeException('無法刷新無效的 snapshot。');
        }

        $snapshot['absolute_path'] = $filePath;
        $snapshot['directory_path'] = dirname($filePath);
        $snapshot['video_name'] = (string) ($snapshot['video_name'] ?? basename($filePath));
        $snapshot['file_name'] = basename($filePath);
        $snapshot['path_sha1'] = $this->hashPath($filePath);
        $snapshot['file_size_bytes'] = ($size = @filesize($filePath)) !== false ? (int) $size : null;
        $snapshot['file_created_timestamp'] = $this->normalizeTimestamp(@filectime($filePath));
        $snapshot['file_modified_timestamp'] = $this->normalizeTimestamp(@filemtime($filePath));

        return $snapshot;
    }

    private function snapshotFromPayload(array $payload): array
    {
        $absolutePath = $this->normalizeAbsolutePath((string) ($payload['absolute_path'] ?? ''));

        return [
            'absolute_path' => $absolutePath,
            'directory_path' => $absolutePath !== '' ? dirname($absolutePath) : null,
            'video_name' => (string) ($payload['video_name'] ?? basename($absolutePath)),
            'file_name' => (string) ($payload['file_name'] ?? basename($absolutePath)),
            'path_sha1' => $absolutePath !== '' ? $this->hashPath($absolutePath) : null,
            'file_size_bytes' => isset($payload['file_size_bytes']) ? (int) $payload['file_size_bytes'] : null,
            'duration_seconds' => isset($payload['duration_seconds']) ? (float) $payload['duration_seconds'] : null,
            'file_created_timestamp' => $this->extractTimestamp($payload['file_created_at'] ?? null),
            'file_modified_timestamp' => $this->extractTimestamp($payload['file_modified_at'] ?? null),
            'screenshot_count' => count((array) ($payload['frames'] ?? [])),
            'feature_version' => (string) ($payload['feature_version'] ?? 'v1'),
            'capture_rule' => (string) ($payload['capture_rule'] ?? '10s_x4'),
            'frames' => $this->normalizeFrames((array) ($payload['frames'] ?? [])),
        ];
    }

    private function normalizeSnapshot(mixed $snapshot): ?array
    {
        if (!is_array($snapshot)) {
            return null;
        }

        $absolutePath = $this->normalizeAbsolutePath((string) ($snapshot['absolute_path'] ?? ''));
        if ($absolutePath === '') {
            return null;
        }

        $frames = $this->normalizeFrames((array) ($snapshot['frames'] ?? []));
        if ($frames === []) {
            return null;
        }

        return [
            'absolute_path' => $absolutePath,
            'directory_path' => $absolutePath !== '' ? dirname($absolutePath) : null,
            'video_name' => (string) ($snapshot['video_name'] ?? basename($absolutePath)),
            'file_name' => (string) ($snapshot['file_name'] ?? basename($absolutePath)),
            'path_sha1' => (string) ($snapshot['path_sha1'] ?? $this->hashPath($absolutePath)),
            'file_size_bytes' => isset($snapshot['file_size_bytes']) ? (int) $snapshot['file_size_bytes'] : null,
            'duration_seconds' => isset($snapshot['duration_seconds']) ? (float) $snapshot['duration_seconds'] : null,
            'file_created_timestamp' => $this->normalizeTimestamp($snapshot['file_created_timestamp'] ?? null),
            'file_modified_timestamp' => $this->normalizeTimestamp($snapshot['file_modified_timestamp'] ?? null),
            'screenshot_count' => isset($snapshot['screenshot_count']) ? (int) $snapshot['screenshot_count'] : count($frames),
            'feature_version' => (string) ($snapshot['feature_version'] ?? 'v1'),
            'capture_rule' => (string) ($snapshot['capture_rule'] ?? '10s_x4'),
            'frames' => $frames,
        ];
    }

    private function normalizeFrames(array $frames): array
    {
        $normalizedFrames = [];

        foreach ($frames as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $captureOrder = (int) ($frame['capture_order'] ?? 0);
            if ($captureOrder <= 0) {
                continue;
            }

            $dhashHex = strtolower(trim((string) ($frame['dhash_hex'] ?? '')));
            if ($dhashHex === '') {
                continue;
            }

            $normalizedFrames[] = [
                'capture_order' => $captureOrder,
                'label_second' => isset($frame['label_second']) ? (float) $frame['label_second'] : null,
                'capture_second' => isset($frame['capture_second']) ? (float) $frame['capture_second'] : null,
                'dhash_hex' => $dhashHex,
                'dhash_prefix' => (string) ($frame['dhash_prefix'] ?? substr($dhashHex, 0, 2)),
                'frame_sha1' => $frame['frame_sha1'] ?? null,
                'image_width' => isset($frame['image_width']) ? (int) $frame['image_width'] : null,
                'image_height' => isset($frame['image_height']) ? (int) $frame['image_height'] : null,
            ];
        }

        usort($normalizedFrames, fn (array $left, array $right): int => $left['capture_order'] <=> $right['capture_order']);

        return $normalizedFrames;
    }

    private function collectVideoFiles(string $rootPath, string $duplicateDir): array
    {
        $result = [];
        $duplicateDirLower = mb_strtolower($duplicateDir);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
            if ($duplicateDirLower !== '' && str_starts_with(mb_strtolower($path), $duplicateDirLower)) {
                continue;
            }

            if ($this->isVideoFile($path)) {
                $result[] = $path;
            }
        }

        usort($result, function (string $left, string $right): int {
            return strcmp(mb_strtolower($left), mb_strtolower($right));
        });

        return $result;
    }

    private function isVideoFile(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    private function writeIndex(string $indexPath, array $payload): void
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new RuntimeException('無法寫入影片特徵索引 JSON。');
        }

        File::put($indexPath, $encoded);
    }

    private function extractTimestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return $this->normalizeTimestamp($value);
    }

    private function normalizeTimestamp(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }
}
