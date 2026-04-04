<?php

namespace App\Services;

use App\Models\VideoMaster;
use App\Models\VideoRerunSyncEntry;
use App\Models\VideoRerunSyncRun;
use App\Support\VideoRerunSyncSource;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use RuntimeException;

class VideoRerunSyncService
{
    public function __construct(
        private readonly VideoRerunEagleClient $eagleClient,
    ) {
    }

    public function scan(bool $force = false, array $limits = []): VideoRerunSyncRun
    {
        $run = VideoRerunSyncRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $stats = [
            VideoRerunSyncSource::DB => 0,
            VideoRerunSyncSource::RERUN_DISK => 0,
            VideoRerunSyncSource::EAGLE => 0,
            'hashed' => 0,
            'skipped' => 0,
            'missing' => 0,
        ];

        try {
            $this->scanDbEntries($run, $force, $stats, $this->normalizeLimit($limits[VideoRerunSyncSource::DB] ?? null));
            $this->scanRerunDirectory($run, $force, $stats, $this->normalizeLimit($limits[VideoRerunSyncSource::RERUN_DISK] ?? null));
            $this->scanEagleLibrary($run, $force, $stats, $this->normalizeLimit($limits[VideoRerunSyncSource::EAGLE] ?? null));

            $diffSummary = app(VideoRerunDiffService::class)->summary();

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'db_seen_count' => $stats[VideoRerunSyncSource::DB],
                'rerun_seen_count' => $stats[VideoRerunSyncSource::RERUN_DISK],
                'eagle_seen_count' => $stats[VideoRerunSyncSource::EAGLE],
                'hashed_count' => $stats['hashed'],
                'skipped_count' => $stats['skipped'],
                'missing_file_count' => $stats['missing'],
                'diff_group_count' => $diffSummary['diff_groups'],
                'issue_count' => $diffSummary['issues'],
                'summary_json' => $diffSummary,
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary_json' => [
                    'message' => $e->getMessage(),
                ],
            ])->save();

            throw $e;
        }

        return $run->fresh();
    }

    private function scanDbEntries(VideoRerunSyncRun $run, bool $force, array &$stats, ?int $limit = null): void
    {
        $diskRoot = $this->dbDiskRoot();
        $seenKeys = [];

        $query = VideoMaster::query()->where('video_type', 1);

        if ($limit !== null) {
            $query->orderByDesc('id');
            $this->scanDbChunk($query->limit($limit)->get(), $seenKeys, $stats, $run, $force, $diskRoot);
            return;
        }

        $query->orderBy('id');
        $query->chunk(250, function ($videos) use (&$seenKeys, &$stats, $run, $force, $diskRoot): void {
            $this->scanDbChunk($videos, $seenKeys, $stats, $run, $force, $diskRoot);
        });

        $this->markMissingSourceEntries(VideoRerunSyncSource::DB, $run, $seenKeys);
    }

    private function scanRerunDirectory(VideoRerunSyncRun $run, bool $force, array &$stats, ?int $limit = null): void
    {
        $root = $this->normalizeAbsolutePath((string) config('video_rerun_sync.rerun_root', ''));
        if ($root === '' || !File::isDirectory($root)) {
            throw new RuntimeException('重跑資料夾不存在：' . $root);
        }

        $seenKeys = [];
        $count = 0;
        foreach ($this->allFiles($root) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = ltrim(str_replace(['/', '\\'], '/', substr($absolutePath, strlen($root))), '/');
            $sourceKey = str_replace('\\', '/', $relativePath);

            $seenKeys[] = $sourceKey;
            $stats[VideoRerunSyncSource::RERUN_DISK]++;

            $this->upsertEntry(
                $run,
                $force,
                [
                    'source_type' => VideoRerunSyncSource::RERUN_DISK,
                    'source_key' => $sourceKey,
                    'resource_key' => pathinfo($file->getBasename(), PATHINFO_FILENAME),
                    'display_name' => $file->getBasename(),
                    'relative_path' => $relativePath,
                    'absolute_path' => $absolutePath,
                    'file_extension' => strtolower($file->getExtension()),
                    'metadata_json' => [
                        'root' => $root,
                    ],
                ],
                $stats
            );

            $count++;
            if ($limit !== null && $count >= $limit) {
                return;
            }
        }

        $this->markMissingSourceEntries(VideoRerunSyncSource::RERUN_DISK, $run, $seenKeys);
    }

    private function scanEagleLibrary(VideoRerunSyncRun $run, bool $force, array &$stats, ?int $limit = null): void
    {
        $library = $this->eagleClient->ensureConfiguredLibrary();
        $libraryPath = $this->normalizeAbsolutePath((string) ($library['path'] ?? ''));

        if ($libraryPath === '' || !File::isDirectory($libraryPath)) {
            throw new RuntimeException('Eagle library 不存在：' . $libraryPath);
        }

        $seenKeys = [];
        foreach ($this->eagleClient->listItems($limit) as $item) {
            $itemId = (string) ($item['id'] ?? '');
            if ($itemId === '') {
                continue;
            }

            $absolutePath = $this->eagleClient->resolveItemFilePath($libraryPath, $item);
            $relativePath = ltrim(str_replace(['/', '\\'], '/', substr($absolutePath, strlen($libraryPath))), '/');
            $name = (string) ($item['name'] ?? pathinfo($absolutePath, PATHINFO_FILENAME));
            $ext = strtolower((string) ($item['ext'] ?? pathinfo($absolutePath, PATHINFO_EXTENSION)));

            $seenKeys[] = $itemId;
            $stats[VideoRerunSyncSource::EAGLE]++;

            $this->upsertEntry(
                $run,
                $force,
                [
                    'source_type' => VideoRerunSyncSource::EAGLE,
                    'source_key' => $itemId,
                    'source_item_id' => $itemId,
                    'resource_key' => $name,
                    'display_name' => $name . ($ext !== '' ? '.' . $ext : ''),
                    'relative_path' => $relativePath,
                    'absolute_path' => $absolutePath,
                    'file_extension' => $ext,
                    'metadata_json' => [
                        'eagle_id' => $itemId,
                        'library_path' => $libraryPath,
                        'item' => $item,
                    ],
                ],
                $stats
            );
        }

        if ($limit !== null) {
            return;
        }

        $this->markMissingSourceEntries(VideoRerunSyncSource::EAGLE, $run, $seenKeys);
    }

    private function upsertEntry(VideoRerunSyncRun $run, bool $force, array $payload, array &$stats): void
    {
        $entry = VideoRerunSyncEntry::firstOrNew([
            'source_type' => $payload['source_type'],
            'source_key' => $payload['source_key'],
        ]);

        $absolutePath = $this->normalizeAbsolutePath((string) ($payload['absolute_path'] ?? ''));
        $payload['absolute_path'] = $absolutePath;
        $payload['relative_path'] = $this->normalizeRelativePath((string) ($payload['relative_path'] ?? ''));
        $payload['discovered_at'] = now();
        $payload['last_seen_run_id'] = $run->id;
        $payload['is_present'] = true;

        $entry->fill($payload);

        if ($absolutePath === '' || !File::exists($absolutePath) || !File::isFile($absolutePath)) {
            $entry->fill([
                'file_size_bytes' => null,
                'file_modified_at' => null,
                'content_sha1' => null,
                'fingerprint_status' => 'missing_file',
                'last_error' => '檔案不存在或不是一般檔案：' . $absolutePath,
                'fingerprinted_at' => now(),
            ])->save();

            $stats['missing']++;
            return;
        }

        clearstatcache(true, $absolutePath);
        $fileSize = (int) filesize($absolutePath);
        $modifiedAt = Carbon::createFromTimestamp((int) filemtime($absolutePath));

        $shouldSkipHash = !$force
            && $entry->exists
            && $entry->fingerprint_status === 'hashed'
            && $entry->file_size_bytes === $fileSize
            && $entry->file_modified_at?->equalTo($modifiedAt)
            && $entry->content_sha1 !== null
            && $entry->absolute_path === $absolutePath;

        $entry->file_size_bytes = $fileSize;
        $entry->file_modified_at = $modifiedAt;
        $entry->fingerprinted_at = now();
        $entry->last_error = null;

        if ($shouldSkipHash) {
            $stats['skipped']++;
            $entry->save();
            return;
        }

        $entry->content_sha1 = $this->fingerprintFile($absolutePath, $fileSize);
        $entry->fingerprint_status = $entry->content_sha1 !== null ? 'hashed' : 'hash_failed';
        $entry->last_error = $entry->content_sha1 !== null ? null : '無法計算 SHA1。';
        $entry->save();

        $stats['hashed']++;
    }

    private function markMissingSourceEntries(string $sourceType, VideoRerunSyncRun $run, array $seenKeys): void
    {
        $query = VideoRerunSyncEntry::query()->where('source_type', $sourceType);

        if ($seenKeys !== []) {
            $query->whereNotIn('source_key', $seenKeys);
        }

        $query->update([
            'is_present' => false,
            'last_seen_run_id' => $run->id,
        ]);
    }

    private function extractDbResourceKey(string $relativePath, string $videoName): string
    {
        $normalized = $this->normalizeRelativePath($relativePath);
        $directory = trim(pathinfo($normalized, PATHINFO_DIRNAME), '/.');

        if ($directory !== '') {
            return basename($directory);
        }

        return pathinfo($videoName, PATHINFO_FILENAME);
    }

    private function normalizeAbsolutePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        return ltrim($path, '/');
    }

    private function dbDiskRoot(): string
    {
        $disk = (string) config('video_rerun_sync.db_disk', 'videos');

        return $this->normalizeAbsolutePath((string) config("filesystems.disks.{$disk}.root", ''));
    }

    private function scanDbChunk(iterable $videos, array &$seenKeys, array &$stats, VideoRerunSyncRun $run, bool $force, string $diskRoot): void
    {
        foreach ($videos as $video) {
            $relativePath = (string) $video->video_path;
            $sourceKey = (string) $video->id;

            $seenKeys[] = $sourceKey;
            $stats[VideoRerunSyncSource::DB]++;

            $this->upsertEntry(
                $run,
                $force,
                [
                    'source_type' => VideoRerunSyncSource::DB,
                    'source_key' => $sourceKey,
                    'source_item_id' => $sourceKey,
                    'resource_key' => $this->extractDbResourceKey($relativePath, (string) $video->video_name),
                    'display_name' => (string) $video->video_name,
                    'relative_path' => $relativePath,
                    'absolute_path' => $this->joinPaths($diskRoot, $relativePath),
                    'file_extension' => strtolower(pathinfo($relativePath, PATHINFO_EXTENSION)),
                    'metadata_json' => [
                        'video_master_id' => (int) $video->id,
                        'video_path' => $relativePath,
                        'video_name' => (string) $video->video_name,
                        'videos_url' => route('video.index', ['video_type' => 1, 'focus_id' => (int) $video->id]),
                    ],
                ],
                $stats
            );
        }
    }

    private function joinPaths(string $root, string $relativePath): string
    {
        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));
    }

    private function normalizeLimit(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $limit = (int) $value;

        return $limit > 0 ? $limit : null;
    }

    private function allFiles(string $root): \Generator
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo) {
                yield $file;
            }
        }
    }

    private function fingerprintFile(string $absolutePath, int $fileSize): ?string
    {
        $chunkSize = 64 * 1024;

        if ($fileSize <= $chunkSize * 4) {
            return sha1_file($absolutePath) ?: null;
        }

        $positions = array_values(array_unique([
            0,
            max(0, (int) floor(($fileSize - $chunkSize) / 4)),
            max(0, (int) floor((($fileSize - $chunkSize) * 2) / 4)),
            max(0, (int) floor((($fileSize - $chunkSize) * 3) / 4)),
            max(0, $fileSize - $chunkSize),
        ]));

        $handle = @fopen($absolutePath, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $fragments = [$fileSize];

            foreach ($positions as $position) {
                if (fseek($handle, $position) !== 0) {
                    return null;
                }

                $bytesToRead = min($chunkSize, max(0, $fileSize - $position));
                $chunk = $bytesToRead > 0 ? fread($handle, $bytesToRead) : '';
                if ($chunk === false) {
                    return null;
                }

                $fragments[] = hash('sha1', $chunk);
            }

            return sha1(implode(':', $fragments));
        } finally {
            fclose($handle);
        }
    }
}
