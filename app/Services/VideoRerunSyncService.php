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
    /**
     * @var array<string, array<string, \App\Models\VideoRerunSyncEntry>>
     */
    private array $entryCache = [];

    public function __construct(
        private readonly VideoRerunEagleClient $eagleClient,
    ) {
    }

    public function scan(bool $force = false, array $limits = [], ?callable $progress = null): VideoRerunSyncRun
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
            $normalizedLimits = [
                VideoRerunSyncSource::DB => $this->normalizeLimit($limits[VideoRerunSyncSource::DB] ?? null),
                VideoRerunSyncSource::RERUN_DISK => $this->normalizeLimit($limits[VideoRerunSyncSource::RERUN_DISK] ?? null),
                VideoRerunSyncSource::EAGLE => $this->normalizeLimit($limits[VideoRerunSyncSource::EAGLE] ?? null),
            ];

            $plan = $this->buildScanPlan($normalizedLimits);
            $progressState = $this->createProgressState($plan['source_totals']);

            $this->emitProgress($progress, [
                'type' => 'start',
                'source_totals' => $plan['source_totals'],
                'overall_processed' => 0,
                'overall_total' => $progressState['overall_total'],
                'overall_percent' => $this->calculateProgressPercent(0, $progressState['overall_total']),
                'stats' => $stats,
            ]);

            $this->startProgressStage($progress, $progressState, VideoRerunSyncSource::DB, $stats);
            $this->scanDbEntries(
                $run,
                $force,
                $stats,
                $normalizedLimits[VideoRerunSyncSource::DB],
                function (array $item) use ($progress, &$progressState, &$stats): void {
                    $this->advanceProgress($progress, $progressState, VideoRerunSyncSource::DB, $item['display_name'], $stats);
                }
            );

            $this->startProgressStage($progress, $progressState, VideoRerunSyncSource::RERUN_DISK, $stats);
            $this->scanRerunDirectory(
                $run,
                $force,
                $stats,
                $plan['rerun_root'],
                $normalizedLimits[VideoRerunSyncSource::RERUN_DISK],
                function (array $item) use ($progress, &$progressState, &$stats): void {
                    $this->advanceProgress($progress, $progressState, VideoRerunSyncSource::RERUN_DISK, $item['display_name'], $stats);
                }
            );

            $this->startProgressStage($progress, $progressState, VideoRerunSyncSource::EAGLE, $stats);
            $this->scanEagleLibrary(
                $run,
                $force,
                $stats,
                $plan['eagle_library_path'],
                $plan['eagle_items'],
                $normalizedLimits[VideoRerunSyncSource::EAGLE] === null,
                function (array $item) use ($progress, &$progressState, &$stats): void {
                    $this->advanceProgress($progress, $progressState, VideoRerunSyncSource::EAGLE, $item['display_name'], $stats);
                }
            );

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

            $this->emitProgress($progress, [
                'type' => 'finish',
                'source_totals' => $plan['source_totals'],
                'overall_processed' => $progressState['overall_total'],
                'overall_total' => $progressState['overall_total'],
                'overall_percent' => $this->calculateProgressPercent($progressState['overall_total'], $progressState['overall_total']),
                'stats' => $stats,
            ]);
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

    private function scanDbEntries(VideoRerunSyncRun $run, bool $force, array &$stats, ?int $limit = null, ?callable $afterItem = null): void
    {
        $diskRoot = $this->dbDiskRoot();
        $seenKeys = [];

        $query = VideoMaster::query()->where('video_type', 1);

        if ($limit !== null) {
            $query->orderByDesc('id');
            $this->scanDbChunk($query->limit($limit)->get(), $seenKeys, $stats, $run, $force, $diskRoot, $afterItem);
            return;
        }

        $query->orderBy('id');
        $query->chunk(250, function ($videos) use (&$seenKeys, &$stats, $run, $force, $diskRoot, $afterItem): void {
            $this->scanDbChunk($videos, $seenKeys, $stats, $run, $force, $diskRoot, $afterItem);
        });

        $this->markMissingSourceEntries(VideoRerunSyncSource::DB, $run, $seenKeys);
    }

    private function scanRerunDirectory(
        VideoRerunSyncRun $run,
        bool $force,
        array &$stats,
        string $root,
        ?int $limit = null,
        ?callable $afterItem = null,
    ): void
    {
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

            if ($afterItem !== null) {
                $afterItem([
                    'display_name' => $file->getBasename(),
                ]);
            }

            $count++;
            if ($limit !== null && $count >= $limit) {
                return;
            }
        }

        $this->markMissingSourceEntries(VideoRerunSyncSource::RERUN_DISK, $run, $seenKeys);
    }

    private function scanEagleLibrary(
        VideoRerunSyncRun $run,
        bool $force,
        array &$stats,
        string $libraryPath,
        array $items,
        bool $markMissing = true,
        ?callable $afterItem = null,
    ): void
    {
        $seenKeys = [];
        foreach ($items as $item) {
            $itemId = (string) ($item['id'] ?? '');
            if ($itemId === '') {
                continue;
            }

            $absolutePath = $this->eagleClient->resolveItemFilePath($libraryPath, $item);

            if ($absolutePath === null) {
                $seenKeys[] = $itemId;
                $stats[VideoRerunSyncSource::EAGLE]++;

                $this->upsertMissingEntry(
                    $run,
                    [
                        'source_type' => VideoRerunSyncSource::EAGLE,
                        'source_key' => $itemId,
                        'source_item_id' => $itemId,
                        'resource_key' => (string) ($item['name'] ?? $itemId),
                        'display_name' => (string) ($item['name'] ?? $itemId) . (($item['ext'] ?? '') !== '' ? '.' . strtolower((string) $item['ext']) : ''),
                        'relative_path' => null,
                        'absolute_path' => rtrim($libraryPath, '/\\') . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $itemId . '.info',
                        'file_extension' => strtolower((string) ($item['ext'] ?? '')),
                        'metadata_json' => [
                            'eagle_id' => $itemId,
                            'library_path' => $libraryPath,
                            'item' => $item,
                        ],
                    ],
                    $stats,
                    'Eagle item original file not found: ' . rtrim($libraryPath, '/\\') . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $itemId . '.info'
                );

                if ($afterItem !== null) {
                    $afterItem([
                        'display_name' => (string) ($item['name'] ?? $itemId),
                    ]);
                }

                continue;
            }

            $name = (string) ($item['name'] ?? pathinfo($absolutePath, PATHINFO_FILENAME));
            $ext = strtolower((string) ($item['ext'] ?? pathinfo($absolutePath, PATHINFO_EXTENSION)));

            $seenKeys[] = $itemId;
            $stats[VideoRerunSyncSource::EAGLE]++;

            $relativePath = ltrim(str_replace(['/', '\\'], '/', substr($absolutePath, strlen($libraryPath))), '/');

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

            if ($afterItem !== null) {
                $afterItem([
                    'display_name' => $name . ($ext !== '' ? '.' . $ext : ''),
                ]);
            }
        }

        if (!$markMissing) {
            return;
        }

        $this->markMissingSourceEntries(VideoRerunSyncSource::EAGLE, $run, $seenKeys);
    }

    private function upsertEntry(VideoRerunSyncRun $run, bool $force, array $payload, array &$stats): void
    {
        $entry = $this->resolveEntryModel(
            (string) $payload['source_type'],
            (string) $payload['source_key']
        );

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
        $modifiedTimestamp = (int) filemtime($absolutePath);
        $modifiedAt = Carbon::createFromTimestamp($modifiedTimestamp, (string) config('app.timezone', 'UTC'));

        $shouldSkipHash = !$force
            && $entry->exists
            && $entry->fingerprint_status === 'hashed'
            && $entry->file_size_bytes === $fileSize
            && $entry->file_modified_at?->timestamp === $modifiedTimestamp
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

    private function upsertMissingEntry(VideoRerunSyncRun $run, array $payload, array &$stats, string $message): void
    {
        $entry = $this->resolveEntryModel(
            (string) $payload['source_type'],
            (string) $payload['source_key']
        );

        $entry->fill(array_merge($payload, [
            'discovered_at' => now(),
            'last_seen_run_id' => $run->id,
            'is_present' => true,
            'file_size_bytes' => null,
            'file_modified_at' => null,
            'content_sha1' => null,
            'fingerprint_status' => 'missing_file',
            'last_error' => $message,
            'fingerprinted_at' => now(),
        ]));

        $entry->save();
        $this->rememberEntry($entry);
        $stats['missing']++;
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

    private function scanDbChunk(
        iterable $videos,
        array &$seenKeys,
        array &$stats,
        VideoRerunSyncRun $run,
        bool $force,
        string $diskRoot,
        ?callable $afterItem = null,
    ): void
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

            if ($afterItem !== null) {
                $afterItem([
                    'display_name' => (string) $video->video_name,
                ]);
            }
        }
    }

    private function buildScanPlan(array $limits): array
    {
        $rerunRoot = $this->normalizeAbsolutePath((string) config('video_rerun_sync.rerun_root', ''));
        if ($rerunRoot === '' || !File::isDirectory($rerunRoot)) {
            throw new RuntimeException('重跑資料夾不存在：' . $rerunRoot);
        }

        $eagle = $this->prepareEagleScan($limits[VideoRerunSyncSource::EAGLE] ?? null);

        return [
            'source_totals' => [
                VideoRerunSyncSource::DB => $this->countDbEntries($limits[VideoRerunSyncSource::DB] ?? null),
                VideoRerunSyncSource::RERUN_DISK => $this->countFiles($rerunRoot, $limits[VideoRerunSyncSource::RERUN_DISK] ?? null),
                VideoRerunSyncSource::EAGLE => count($eagle['items']),
            ],
            'rerun_root' => $rerunRoot,
            'eagle_library_path' => $eagle['library_path'],
            'eagle_items' => $eagle['items'],
        ];
    }

    private function countDbEntries(?int $limit = null): int
    {
        $count = VideoMaster::query()
            ->where('video_type', 1)
            ->count();

        return $limit === null ? $count : min($count, $limit);
    }

    private function countFiles(string $root, ?int $limit = null): int
    {
        $count = 0;

        foreach ($this->allFiles($root) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $count++;

            if ($limit !== null && $count >= $limit) {
                break;
            }
        }

        return $count;
    }

    private function prepareEagleScan(?int $limit = null): array
    {
        $library = $this->eagleClient->ensureConfiguredLibrary();
        $libraryPath = $this->normalizeAbsolutePath((string) ($library['path'] ?? ''));

        if ($libraryPath === '' || !File::isDirectory($libraryPath)) {
            throw new RuntimeException('Eagle library 不存在：' . $libraryPath);
        }

        $items = array_values(array_filter(
            $this->eagleClient->listItems($limit),
            static fn (array $item): bool => (string) ($item['id'] ?? '') !== ''
        ));

        return [
            'library_path' => $libraryPath,
            'items' => $items,
        ];
    }

    private function createProgressState(array $sourceTotals): array
    {
        return [
            'source_totals' => $sourceTotals,
            'overall_total' => array_sum($sourceTotals),
            'overall_processed' => 0,
            'current_source' => null,
            'current_source_processed' => 0,
        ];
    }

    private function startProgressStage(?callable $progress, array &$state, string $sourceType, array $stats): void
    {
        $state['current_source'] = $sourceType;
        $state['current_source_processed'] = 0;

        $this->emitProgress($progress, [
            'type' => 'stage_start',
            'source_type' => $sourceType,
            'source_label' => VideoRerunSyncSource::label($sourceType),
            'source_processed' => 0,
            'source_total' => $state['source_totals'][$sourceType] ?? 0,
            'source_percent' => $this->calculateProgressPercent(0, (int) ($state['source_totals'][$sourceType] ?? 0)),
            'overall_processed' => $state['overall_processed'],
            'overall_total' => $state['overall_total'],
            'overall_percent' => $this->calculateProgressPercent((int) $state['overall_processed'], (int) $state['overall_total']),
            'stats' => $stats,
        ]);
    }

    private function advanceProgress(
        ?callable $progress,
        array &$state,
        string $sourceType,
        string $displayName,
        array $stats,
    ): void {
        if (($state['current_source'] ?? null) !== $sourceType) {
            $state['current_source'] = $sourceType;
            $state['current_source_processed'] = 0;
        }

        $state['overall_processed']++;
        $state['current_source_processed']++;

        $sourceTotal = (int) ($state['source_totals'][$sourceType] ?? 0);

        $this->emitProgress($progress, [
            'type' => 'advance',
            'source_type' => $sourceType,
            'source_label' => VideoRerunSyncSource::label($sourceType),
            'source_processed' => (int) $state['current_source_processed'],
            'source_total' => $sourceTotal,
            'source_percent' => $this->calculateProgressPercent((int) $state['current_source_processed'], $sourceTotal),
            'overall_processed' => (int) $state['overall_processed'],
            'overall_total' => (int) $state['overall_total'],
            'overall_percent' => $this->calculateProgressPercent((int) $state['overall_processed'], (int) $state['overall_total']),
            'display_name' => $displayName,
            'stats' => $stats,
        ]);
    }

    private function calculateProgressPercent(int $processed, int $total): float
    {
        if ($total <= 0) {
            return 100.0;
        }

        return round(min(100, ($processed / $total) * 100), 1);
    }

    private function emitProgress(?callable $progress, array $payload): void
    {
        if ($progress === null) {
            return;
        }

        $progress($payload);
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

    private function resolveEntryModel(string $sourceType, string $sourceKey): VideoRerunSyncEntry
    {
        if (!array_key_exists($sourceType, $this->entryCache)) {
            $this->entryCache[$sourceType] = VideoRerunSyncEntry::query()
                ->where('source_type', $sourceType)
                ->get()
                ->keyBy('source_key')
                ->all();
        }

        if (isset($this->entryCache[$sourceType][$sourceKey])) {
            return $this->entryCache[$sourceType][$sourceKey];
        }

        $entry = new VideoRerunSyncEntry([
            'source_type' => $sourceType,
            'source_key' => $sourceKey,
        ]);

        $this->entryCache[$sourceType][$sourceKey] = $entry;

        return $entry;
    }

    private function rememberEntry(VideoRerunSyncEntry $entry): void
    {
        $sourceType = (string) $entry->source_type;
        $sourceKey = (string) $entry->source_key;

        if (!isset($this->entryCache[$sourceType])) {
            $this->entryCache[$sourceType] = [];
        }

        $this->entryCache[$sourceType][$sourceKey] = $entry;
    }
}
