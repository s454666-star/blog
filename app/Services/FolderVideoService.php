<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Process\Process;
use Throwable;

class FolderVideoService
{
    public function listVideosPage(
        int $limit = 15,
        ?float $afterDuration = null,
        ?string $afterFilename = null,
        ?int $offset = null,
        string $order = 'duration',
        string $seed = '',
        ?int $newFirstAfter = null,
        bool $likedOnly = false
    ): array
    {
        $limit = max(1, min($limit, 100));
        if ($likedOnly) {
            return $this->listGoodVideosPage(
                $limit,
                max(0, (int) ($offset ?? 0)),
                $order,
                $seed,
                $newFirstAfter
            );
        }

        if (! (bool) config('folder_video.probe_on_request', false)) {
            return $this->listFastVideosPage(
                $limit,
                max(0, (int) ($offset ?? 0)),
                $order,
                $seed,
                $newFirstAfter
            );
        }

        $videos = $this->filteredVideos($afterDuration, $afterFilename);
        if ($this->isRandomOrder($order)) {
            $videos = $this->sortRandomVideos($videos, $seed, $newFirstAfter);
        }

        return [
            'videos' => $videos->take($limit)->values(),
            'has_more' => $videos->count() > $limit,
            'next_offset' => null,
        ];
    }

    public function listVideos(int $limit = 15, ?float $afterDuration = null, ?string $afterFilename = null): Collection
    {
        return $this->listVideosPage($limit, $afterDuration, $afterFilename)['videos'];
    }

    public function warmCache(bool $force = true): int
    {
        if (! $force && ! (bool) config('folder_video.probe_on_request', false)) {
            return count($this->refreshLightweightIndex());
        }

        return $this->collectVideoEntries($force)->count();
    }

    public function indexFilePath(): string
    {
        $configuredPath = (string) config('folder_video.index_path', '');

        if ($configuredPath !== '') {
            return $configuredPath;
        }

        return $this->rootPath().DIRECTORY_SEPARATOR.(string) config('folder_video.index_filename', 'folder-video-index.json');
    }

    protected function collectVideoEntries(bool $forceProbe = false): Collection
    {
        $root = $this->rootPath();
        File::ensureDirectoryExists($root);

        $index = $this->readIndex();
        $probeMissingDurations = $forceProbe || (bool) config('folder_video.probe_on_request', false);
        $knownFilenames = [];
        $videos = collect(File::files($root))
            ->filter(fn (\SplFileInfo $file) => $this->isPlayableVideo($file))
            ->map(function (\SplFileInfo $file) use (&$index, &$knownFilenames, $forceProbe, $probeMissingDurations) {
                $filename = $file->getFilename();
                $path = $file->getPathname();
                $stat = [
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime(),
                    'ctime' => $file->getCTime(),
                ];
                $knownFilenames[$filename] = true;
                $id = $this->encodeId($filename);

                $durationSeconds = $this->resolveDurationSeconds(
                    $filename,
                    $path,
                    $stat,
                    $index,
                    $forceProbe,
                    $probeMissingDurations
                );

                return [
                    'id' => $id,
                    'filename' => $filename,
                    'duration_seconds' => $durationSeconds,
                    'duration_label' => $this->formatDuration($durationSeconds),
                    'size_bytes' => $stat['size'],
                    'mtime' => $stat['mtime'],
                    'ctime' => $stat['ctime'],
                    'stream_url' => $this->streamUrlForFilename($filename),
                    'preview_url' => $this->previewUrlForFilename($filename),
                    'thumbnail_url' => $this->thumbnailUrlForFilename($filename),
                    'preview_cached' => $this->previewCachedForPath($path),
                    'thumbnail_cached' => $this->thumbnailCachedForPath($path),
                    'liked' => false,
                ];
            })
            ->sort(fn (array $left, array $right) => [
                $this->sortableDuration((float) $left['duration_seconds']),
                $left['filename'],
            ] <=> [
                $this->sortableDuration((float) $right['duration_seconds']),
                $right['filename'],
            ])
            ->values();

        $index = array_intersect_key($index, $knownFilenames);
        $this->writeIndex($index);

        return $videos;
    }

    protected function filteredVideos(?float $afterDuration = null, ?string $afterFilename = null): Collection
    {
        $videos = $this->collectVideoEntries();

        if ($afterDuration === null || $afterFilename === null) {
            return $videos;
        }

        return $videos->filter(function (array $video) use ($afterDuration, $afterFilename) {
            $sortableDuration = $this->sortableDuration((float) $video['duration_seconds']);

            if ($sortableDuration > $afterDuration) {
                return true;
            }

            return $sortableDuration === $afterDuration
                && strcmp($video['filename'], $afterFilename) > 0;
        })->values();
    }

    public function resolveVideoPath(string $id): string
    {
        $filename = $this->decodeId($id);
        foreach ([$this->rootPath(), $this->goodDirectoryPath()] as $directory) {
            $realPath = $this->safeVideoPathInDirectory($directory, $filename);
            if ($realPath !== null) {
                return $realPath;
            }
        }

        abort(404, 'Video not found.');
    }

    public function resolveThumbnailPath(string $id): ?string
    {
        $sourcePath = $this->resolveVideoPath($id);
        $thumbnailPath = $this->thumbnailPathForSource($sourcePath);

        if (is_file($thumbnailPath) || $this->ensureThumbnailImage($sourcePath, $thumbnailPath)) {
            return $thumbnailPath;
        }

        return null;
    }

    public function resolvePreviewPath(string $id): ?string
    {
        $sourcePath = $this->resolveVideoPath($id);
        $previewPath = $this->previewPathForSource($sourcePath);

        if (is_file($previewPath) || $this->ensurePreviewClip($sourcePath, $previewPath)) {
            return $previewPath;
        }

        return (bool) config('folder_video.preview_fallback_to_source', true) ? $sourcePath : null;
    }

    public function previewCachePath(): string
    {
        return rtrim((string) config('folder_video.preview_cache_path'), DIRECTORY_SEPARATOR);
    }

    public function previewQueuePath(): string
    {
        return rtrim((string) config('folder_video.preview_queue_path'), DIRECTORY_SEPARATOR);
    }

    public function previewCacheStatus(string $id): array
    {
        $sourcePath = $this->resolveVideoPath($id);
        $previewPath = $this->previewPathForSource($sourcePath);

        return [
            'id' => $id,
            'ready' => is_file($previewPath) && filesize($previewPath) > 0,
            'preview_url' => $this->previewUrlForFilename(basename($sourcePath)),
        ];
    }

    public function queuePreview(string $id): array
    {
        $sourcePath = $this->resolveVideoPath($id);
        $previewPath = $this->previewPathForSource($sourcePath);
        $status = [
            'id' => $id,
            'ready' => is_file($previewPath) && filesize($previewPath) > 0,
            'preview_url' => $this->previewUrlForFilename(basename($sourcePath)),
        ];
        if ($status['ready']) {
            return $status + ['queued' => false];
        }

        $queuePath = $this->previewQueuePath();
        File::ensureDirectoryExists($queuePath);
        $key = hash('sha256', $previewPath);
        $requestPath = $queuePath.DIRECTORY_SEPARATOR.$key.'.json';
        if (! is_file($requestPath) && ! is_file($queuePath.DIRECTORY_SEPARATOR.$key.'.working')) {
            $temporaryPath = $requestPath.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));
            $payload = json_encode([
                'source_path' => $sourcePath,
                'preview_path' => $previewPath,
                'queued_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($payload) && file_put_contents($temporaryPath, $payload, LOCK_EX) !== false) {
                if (! @rename($temporaryPath, $requestPath)) {
                    @unlink($temporaryPath);
                }
            }
        }

        return $status + ['queued' => true];
    }

    public function tvPreviewCacheStatus(string $id): array
    {
        $sourcePath = $this->resolveVideoPath($id);
        $path = $this->tvPreviewPathForSource($sourcePath);

        return [
            'id' => $id,
            'ready' => is_file($path) && filesize($path) > 0,
            'preview_url' => $this->staticCacheUrl('/folder-video-preview-cache/', $path, $this->previewCachePath()),
        ];
    }

    public function queueTvPreview(string $id): array
    {
        $sourcePath = $this->resolveVideoPath($id);
        $previewPath = $this->tvPreviewPathForSource($sourcePath);
        $status = [
            'id' => $id,
            'ready' => is_file($previewPath) && filesize($previewPath) > 0,
            'preview_url' => $this->staticCacheUrl('/folder-video-preview-cache/', $previewPath, $this->previewCachePath()),
        ];
        if ($status['ready']) {
            return $status + ['queued' => false];
        }

        $this->writeMediaQueueRequest($this->previewQueuePath(), hash('sha256', $previewPath), [
            'kind' => 'animated_webp',
            'source_path' => $sourcePath,
            'preview_path' => $previewPath,
            'queued_at' => now()->toIso8601String(),
        ]);

        return $status + ['queued' => true];
    }

    public function tvHlsStatus(string $id): array
    {
        $sourcePath = $this->resolveVideoPath($id);
        $hlsPath = $this->tvHlsPathForSource($sourcePath);
        $segments = glob($hlsPath.DIRECTORY_SEPARATOR.'segment_*.ts') ?: [];
        $playlist = $hlsPath.DIRECTORY_SEPARATOR.'index.m3u8';

        return [
            'id' => $id,
            'ready' => is_file($playlist) && count($segments) >= 2,
            'complete' => is_file($hlsPath.DIRECTORY_SEPARATOR.'.complete'),
            'available_seconds' => count($segments) * max(1, (int) config('folder_video.tv_hls_segment_seconds', 2)),
            'stream_url' => $this->staticCacheUrl('/folder-video-tv-hls-cache/', $playlist, $this->tvHlsCachePath()),
        ];
    }

    public function queueTvHls(string $id): array
    {
        $sourcePath = $this->resolveVideoPath($id);
        $hlsPath = $this->tvHlsPathForSource($sourcePath);
        $status = $this->tvHlsStatus($id);
        if ($status['ready']) {
            return $status + ['queued' => false];
        }

        $this->writeMediaQueueRequest($this->tvHlsQueuePath(), hash('sha256', $hlsPath), [
            'source_path' => $sourcePath,
            'hls_path' => $hlsPath,
            'queued_at' => now()->toIso8601String(),
        ]);

        return $status + ['queued' => true];
    }

    public function queueExternalHls(string $sourcePath, string $id): array
    {
        $hlsPath = $this->tvHlsPathForSource($sourcePath);
        $segments = glob($hlsPath.DIRECTORY_SEPARATOR.'segment_*.ts') ?: [];
        $playlist = $hlsPath.DIRECTORY_SEPARATOR.'index.m3u8';
        $status = [
            'id' => $id,
            'ready' => is_file($playlist) && count($segments) >= 2,
            'complete' => is_file($hlsPath.DIRECTORY_SEPARATOR.'.complete'),
            'available_seconds' => count($segments) * max(1, (int) config('folder_video.tv_hls_segment_seconds', 2)),
            'stream_url' => $this->staticCacheUrl('/folder-video-tv-hls-cache/', $playlist, $this->tvHlsCachePath()),
        ];
        if ($status['ready']) return $status + ['queued' => false];

        $this->writeMediaQueueRequest($this->tvHlsQueuePath(), hash('sha256', $hlsPath), [
            'source_path' => $sourcePath,
            'hls_path' => $hlsPath,
            'queued_at' => now()->toIso8601String(),
        ]);

        return $status + ['queued' => true];
    }

    public function tvHlsCachePath(): string
    {
        return rtrim((string) config('folder_video.tv_hls_cache_path'), DIRECTORY_SEPARATOR);
    }

    public function tvHlsQueuePath(): string
    {
        return rtrim((string) config('folder_video.tv_hls_queue_path'), DIRECTORY_SEPARATOR);
    }

    protected function writeMediaQueueRequest(string $queuePath, string $key, array $payload): void
    {
        File::ensureDirectoryExists($queuePath);
        $requestPath = $queuePath.DIRECTORY_SEPARATOR.$key.'.json';
        if (is_file($requestPath) || is_file($queuePath.DIRECTORY_SEPARATOR.$key.'.working')) {
            return;
        }
        $temporaryPath = $requestPath.'.tmp.'.getmypid().'.'.bin2hex(random_bytes(4));
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($encoded) && file_put_contents($temporaryPath, $encoded, LOCK_EX) !== false) {
            if (! @rename($temporaryPath, $requestPath)) {
                @unlink($temporaryPath);
            }
        }
    }

    public function thumbnailCachePath(): string
    {
        return rtrim((string) config('folder_video.thumbnail_cache_path'), DIRECTORY_SEPARATOR);
    }

    public function warmPreviewCache(int $limit = 0): int
    {
        $limit = max(0, $limit);
        $count = 0;

        $records = collect($this->collectLightweightFileStats())
            ->sortByDesc(fn (array $record) => max((int) ($record['mtime'] ?? 0), (int) ($record['ctime'] ?? 0)))
            ->values();

        foreach ($records as $record) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $filename = (string) ($record['filename'] ?? '');
            if ($filename === '') {
                continue;
            }

            $sourcePath = $this->safeVideoPathInDirectory($this->rootPath(), $filename);
            if ($sourcePath === null) {
                continue;
            }

            if ($this->ensurePreviewClip($sourcePath, $this->previewPathForSource($sourcePath))) {
                $count++;
            }
        }

        return $count;
    }

    public function warmThumbnailCache(int $limit = 0): int
    {
        $limit = max(0, $limit);
        $count = 0;

        $records = collect($this->collectLightweightFileStats())
            ->sortByDesc(fn (array $record) => max((int) ($record['mtime'] ?? 0), (int) ($record['ctime'] ?? 0)))
            ->values();

        foreach ($records as $record) {
            if ($limit > 0 && $count >= $limit) {
                break;
            }

            $filename = (string) ($record['filename'] ?? '');
            if ($filename === '') {
                continue;
            }

            $sourcePath = $this->safeVideoPathInDirectory($this->rootPath(), $filename);
            if ($sourcePath === null) {
                continue;
            }

            if ($this->ensureThumbnailImage($sourcePath, $this->thumbnailPathForSource($sourcePath))) {
                $count++;
            }
        }

        return $count;
    }

    public function moveToGood(string $id): array
    {
        $filename = $this->decodeId($id);
        $sourcePath = $this->safeVideoPathInDirectory($this->rootPath(), $filename);
        $goodDirectory = $this->goodDirectoryPath();
        File::ensureDirectoryExists($goodDirectory);

        if ($sourcePath === null) {
            $likedPath = $this->safeVideoPathInDirectory($goodDirectory, $filename);
            if ($likedPath !== null) {
                $likedId = $this->encodeId(basename($likedPath));

                return [
                    'id' => $likedId,
                    'filename' => basename($likedPath),
                    'destination' => $likedPath,
                    'liked' => true,
                    'stream_url' => $this->streamUrlForFilename(basename($likedPath), true),
                    'preview_url' => $this->previewUrlForFilename(basename($likedPath)),
                    'thumbnail_url' => $this->thumbnailUrlForFilename(basename($likedPath)),
                    'preview_cached' => $this->previewCachedForPath($likedPath),
                    'thumbnail_cached' => $this->thumbnailCachedForPath($likedPath),
                ];
            }

            abort(404, 'Video not found.');
        }

        $destinationPath = $this->uniqueDestinationPath($goodDirectory, basename($sourcePath));

        $this->moveVideoFile($sourcePath, $destinationPath, 'Unable to move video to good folder.');

        $this->forgetIndexEntry(basename($sourcePath));
        $destinationId = $this->encodeId(basename($destinationPath));

        return [
            'id' => $destinationId,
            'filename' => basename($destinationPath),
            'destination' => $destinationPath,
            'liked' => true,
            'stream_url' => $this->streamUrlForFilename(basename($destinationPath), true),
            'preview_url' => $this->previewUrlForFilename(basename($destinationPath)),
            'thumbnail_url' => $this->thumbnailUrlForFilename(basename($destinationPath)),
            'preview_cached' => $this->previewCachedForPath($destinationPath),
            'thumbnail_cached' => $this->thumbnailCachedForPath($destinationPath),
        ];
    }

    public function moveFromGood(string $id): array
    {
        $filename = $this->decodeId($id);
        $sourcePath = $this->safeVideoPathInDirectory($this->goodDirectoryPath(), $filename);

        if ($sourcePath === null) {
            $rootPath = $this->safeVideoPathInDirectory($this->rootPath(), $filename);
            if ($rootPath !== null) {
                $rootId = $this->encodeId(basename($rootPath));

                return [
                    'id' => $rootId,
                    'filename' => basename($rootPath),
                    'destination' => $rootPath,
                    'liked' => false,
                    'stream_url' => $this->streamUrlForFilename(basename($rootPath)),
                    'preview_url' => $this->previewUrlForFilename(basename($rootPath)),
                    'thumbnail_url' => $this->thumbnailUrlForFilename(basename($rootPath)),
                    'preview_cached' => $this->previewCachedForPath($rootPath),
                    'thumbnail_cached' => $this->thumbnailCachedForPath($rootPath),
                ];
            }

            abort(404, 'Video not found.');
        }

        File::ensureDirectoryExists($this->rootPath());
        $destinationPath = $this->uniqueDestinationPath($this->rootPath(), basename($sourcePath));

        $this->moveVideoFile($sourcePath, $destinationPath, 'Unable to move video back to video folder.');

        $destinationId = $this->encodeId(basename($destinationPath));

        return [
            'id' => $destinationId,
            'filename' => basename($destinationPath),
            'destination' => $destinationPath,
            'liked' => false,
            'stream_url' => $this->streamUrlForFilename(basename($destinationPath)),
            'preview_url' => $this->previewUrlForFilename(basename($destinationPath)),
            'thumbnail_url' => $this->thumbnailUrlForFilename(basename($destinationPath)),
            'preview_cached' => $this->previewCachedForPath($destinationPath),
            'thumbnail_cached' => $this->thumbnailCachedForPath($destinationPath),
        ];
    }

    public function delete(string $id): array
    {
        $path = $this->resolveVideoPath($id);
        $filename = basename($path);

        if (! @unlink($path)) {
            throw new FileException('Unable to delete video.');
        }

        $this->forgetIndexEntry($filename);

        return ['filename' => $filename];
    }

    public function rootPath(): string
    {
        return rtrim((string) config('folder_video.root'), DIRECTORY_SEPARATOR);
    }

    public function appConfig(): array
    {
        return [
            'version' => (string) config('folder_video.app_version', '2026.07.07.1'),
            'page_limit' => max(6, min((int) config('folder_video.app_page_limit', 36), 100)),
            'preview_max_connections' => max(1, min((int) config('folder_video.app_preview_max_connections', 36), 36)),
            'probe_on_request' => (bool) config('folder_video.probe_on_request', false),
            'root' => $this->rootPath(),
            'extensions' => array_values(config('folder_video.extensions', [])),
        ];
    }

    public function goodDirectoryPath(): string
    {
        return $this->rootPath().DIRECTORY_SEPARATOR.(string) config('folder_video.good_subdirectory', 'good');
    }

    public function cursorDuration(?float $durationSeconds): ?float
    {
        if ($durationSeconds === null) {
            return null;
        }

        return $this->sortableDuration($durationSeconds);
    }

    protected function isPlayableVideo(\SplFileInfo $file): bool
    {
        if (! $file->isFile()) {
            return false;
        }

        return in_array(strtolower($file->getExtension()), config('folder_video.extensions', []), true);
    }

    protected function listFastVideosPage(
        int $limit,
        int $offset = 0,
        string $order = 'duration',
        string $seed = '',
        ?int $newFirstAfter = null
    ): array
    {
        $index = $this->readIndex();

        if ($index === []) {
            $index = $this->refreshLightweightIndex($index);
        }

        if ($index !== []) {
            return $this->listIndexedVideosPage($index, $limit, $offset, $order, $seed, $newFirstAfter);
        }

        return $this->listDirectoryVideosPage($limit, $offset, $order, $seed, $newFirstAfter);
    }

    protected function listGoodVideosPage(
        int $limit,
        int $offset = 0,
        string $order = 'duration',
        string $seed = '',
        ?int $newFirstAfter = null
    ): array
    {
        $directory = $this->goodDirectoryPath();

        if (! is_dir($directory)) {
            return [
                'videos' => collect(),
                'has_more' => false,
                'next_offset' => $offset,
            ];
        }

        $records = collect();
        foreach (new \DirectoryIterator($directory) as $file) {
            if (! $this->isPlayableVideo($file)) {
                continue;
            }

            $records->push($this->videoPayloadFromFile($file, true));
        }

        $records = $this->sortRecords($records, $order, $seed, $newFirstAfter)->values();
        $slice = $records->slice($offset, $limit + 1)->values();
        $videos = $slice->take($limit)->values();

        return [
            'videos' => $videos,
            'has_more' => $slice->count() > $limit,
            'next_offset' => $offset + $videos->count(),
        ];
    }

    protected function listIndexedVideosPage(
        array $index,
        int $limit,
        int $offset = 0,
        string $order = 'duration',
        string $seed = '',
        ?int $newFirstAfter = null
    ): array
    {
        $records = collect($index)
            ->pipe(fn (Collection $records) => $this->sortRecords($records, $order, $seed, $newFirstAfter))
            ->values();

        $slice = $records->slice($offset, $limit + 1)->values();
        $videos = $slice->take($limit)
            ->map(fn (array $entry) => $this->videoPayloadFromIndexEntry($entry))
            ->values();

        return [
            'videos' => $videos,
            'has_more' => $slice->count() > $limit,
            'next_offset' => $offset + $videos->count(),
        ];
    }

    protected function listDirectoryVideosPage(
        int $limit,
        int $offset = 0,
        string $order = 'duration',
        string $seed = '',
        ?int $newFirstAfter = null
    ): array
    {
        $root = $this->rootPath();

        if (! is_dir($root)) {
            return [
                'videos' => collect(),
                'has_more' => false,
                'next_offset' => $offset,
            ];
        }

        if ($this->isRandomOrder($order)) {
            $index = $this->refreshLightweightIndex();

            return $this->listIndexedVideosPage($index, $limit, $offset, $order, $seed, $newFirstAfter);
        }

        $videos = collect();
        $seen = 0;
        $hasMore = false;

        foreach (new \DirectoryIterator($root) as $file) {
            if (! $this->isPlayableVideo($file)) {
                continue;
            }

            if ($seen++ < $offset) {
                continue;
            }

            if ($videos->count() >= $limit) {
                $hasMore = true;
                break;
            }

            $videos->push($this->videoPayloadFromFile($file, false));
        }

        return [
            'videos' => $videos,
            'has_more' => $hasMore,
            'next_offset' => $offset + $videos->count(),
        ];
    }

    protected function videoPayloadFromFile(\SplFileInfo $file, bool $liked = false): array
    {
        $id = $this->encodeId($file->getFilename());

        return [
            'id' => $id,
            'filename' => $file->getFilename(),
            'duration_seconds' => 0.0,
            'duration_label' => $this->formatDuration(0.0),
            'size_bytes' => $file->getSize(),
            'modified_at' => $file->getMTime(),
            'created_at' => $file->getCTime(),
            'stream_url' => $this->streamUrlForFilename($file->getFilename(), $liked),
            'preview_url' => $this->previewUrlForFilename($file->getFilename()),
            'thumbnail_url' => $this->thumbnailUrlForFilename($file->getFilename()),
            'preview_cached' => $this->previewCachedForPath($file->getPathname()),
            'thumbnail_cached' => $this->thumbnailCachedForPath($file->getPathname()),
            'liked' => $liked,
        ];
    }

    protected function videoPayloadFromIndexEntry(array $entry): array
    {
        $filename = (string) ($entry['filename'] ?? '');
        $durationSeconds = (float) ($entry['duration_seconds'] ?? 0.0);
        $size = (int) ($entry['size_bytes'] ?? 0);
        $mtime = (int) ($entry['mtime'] ?? 0);
        $id = $this->encodeId($filename);
        $previewPath = $this->previewPathForStat($filename, $size, $mtime);
        $thumbnailPath = $this->thumbnailPathForStat($filename, $size, $mtime);

        return [
            'id' => $id,
            'filename' => $filename,
            'duration_seconds' => $durationSeconds,
            'duration_label' => (string) ($entry['duration_label'] ?? $this->formatDuration($durationSeconds)),
            'size_bytes' => $size,
            'modified_at' => $mtime,
            'created_at' => (int) ($entry['ctime'] ?? 0),
            'stream_url' => $this->streamUrlForFilename($filename),
            'preview_url' => $this->staticCacheUrl('/folder-video-preview-cache/', $previewPath, $this->previewCachePath()),
            'thumbnail_url' => $this->thumbnailUrlForFilename($filename),
            'preview_cached' => is_file($previewPath) && filesize($previewPath) > 0,
            'thumbnail_cached' => is_file($thumbnailPath) && filesize($thumbnailPath) > 0,
            'liked' => false,
        ];
    }

    protected function resolveDurationSeconds(
        string $filename,
        string $path,
        array $stat,
        array &$index,
        bool $forceProbe = false,
        bool $probeMissingDurations = false
    ): float
    {
        $indexed = $index[$filename] ?? null;

        if (! $forceProbe && $this->hasFreshIndexEntry($indexed, $stat)) {
            return (float) ($indexed['duration_seconds'] ?? 0.0);
        }

        if (! $probeMissingDurations) {
            $durationSeconds = 0.0;
            $index[$filename] = $this->indexRecord($filename, $stat, $durationSeconds);

            return $durationSeconds;
        }

        $durationSeconds = $this->probeDurationSeconds($path);
        $index[$filename] = $this->indexRecord($filename, $stat, $durationSeconds);

        return $durationSeconds;
    }

    protected function shouldRefreshIndex(array $index): bool
    {
        if ($index === []) {
            return true;
        }

        $payload = $this->readIndexPayload();
        $indexedDirectoryMtime = (int) ($payload['directory_mtime'] ?? 0);
        $currentDirectoryMtime = $this->rootDirectoryMTime();

        if ($currentDirectoryMtime > 0 && ($indexedDirectoryMtime === 0 || $currentDirectoryMtime > $indexedDirectoryMtime)) {
            return true;
        }

        $refreshSeconds = max(0, (int) config('folder_video.index_refresh_seconds', 300));
        if ($refreshSeconds === 0) {
            return false;
        }

        $generatedAt = (int) ($payload['generated_at_unix'] ?? 0);

        return $generatedAt === 0 || time() - $generatedAt >= $refreshSeconds;
    }

    protected function refreshLightweightIndex(?array $existingIndex = null): array
    {
        $root = $this->rootPath();

        if (! is_dir($root)) {
            return [];
        }

        $existingIndex ??= $this->readIndex();
        $index = [];

        foreach ($this->collectLightweightFileStats() as $file) {
            $filename = (string) ($file['filename'] ?? '');
            if ($filename === '') {
                continue;
            }

            $stat = [
                'size' => (int) ($file['size'] ?? 0),
                'mtime' => (int) ($file['mtime'] ?? 0),
                'ctime' => (int) ($file['ctime'] ?? 0),
            ];
            $indexed = $existingIndex[$filename] ?? null;
            $durationSeconds = $this->hasFreshIndexEntry($indexed, $stat)
                ? (float) ($indexed['duration_seconds'] ?? 0.0)
                : 0.0;

            $index[$filename] = $this->indexRecord($filename, $stat, $durationSeconds);
        }

        $this->writeIndex($index);

        return $index;
    }

    protected function collectLightweightFileStats(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $records = $this->collectLightweightFileStatsWithPowerShell();

            if (is_array($records)) {
                return $records;
            }
        }

        $records = [];
        $root = $this->rootPath();

        foreach (new \DirectoryIterator($root) as $file) {
            if (! $this->isPlayableVideo($file)) {
                continue;
            }

            $records[] = [
                'filename' => $file->getFilename(),
                'size' => $file->getSize(),
                'mtime' => $file->getMTime(),
                'ctime' => $file->getCTime(),
            ];
        }

        return $records;
    }

    protected function collectLightweightFileStatsWithPowerShell(): ?array
    {
        $root = $this->rootPath();
        $extensions = array_values(config('folder_video.extensions', []));
        $rootBase64 = base64_encode($root);
        $extensionsJson = json_encode($extensions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($extensionsJson)) {
            return null;
        }

        $extensionsBase64 = base64_encode($extensionsJson);
        $script = <<<POWERSHELL
[Console]::OutputEncoding = New-Object System.Text.UTF8Encoding \$false
\$ProgressPreference = 'SilentlyContinue'
\$VerbosePreference = 'SilentlyContinue'
\$WarningPreference = 'SilentlyContinue'
\$root = [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('$rootBase64'))
\$extensions = [System.Text.Encoding]::UTF8.GetString([Convert]::FromBase64String('$extensionsBase64')) | ConvertFrom-Json
\$records = Get-ChildItem -LiteralPath \$root -File -ErrorAction Stop |
    Where-Object { \$extensions -contains \$_.Extension.TrimStart('.').ToLowerInvariant() } |
    ForEach-Object {
        [pscustomobject]@{
            filename = \$_.Name
            size = [int64]\$_.Length
            mtime = ([DateTimeOffset]\$_.LastWriteTimeUtc).ToUnixTimeSeconds()
            ctime = ([DateTimeOffset]\$_.CreationTimeUtc).ToUnixTimeSeconds()
        }
    }
\$records | ConvertTo-Json -Compress
POWERSHELL;

        $encodedScript = iconv('UTF-8', 'UTF-16LE', $script);
        if (! is_string($encodedScript)) {
            return null;
        }

        $encoded = base64_encode($encodedScript);
        $output = shell_exec('powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass -EncodedCommand '.$encoded.' 2>NUL');

        if (! is_string($output) || trim($output) === '') {
            return [];
        }

        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            return null;
        }

        if (array_key_exists('filename', $decoded)) {
            $decoded = [$decoded];
        }

        return collect($decoded)
            ->filter(fn ($item) => is_array($item) && isset($item['filename']))
            ->map(fn (array $item) => [
                'filename' => (string) $item['filename'],
                'size' => (int) ($item['size'] ?? 0),
                'mtime' => (int) ($item['mtime'] ?? 0),
                'ctime' => (int) ($item['ctime'] ?? 0),
            ])
            ->values()
            ->all();
    }

    protected function previewPathForSource(string $sourcePath): string
    {
        $stat = @stat($sourcePath) ?: [];

        return $this->previewPathForStat(
            basename($sourcePath),
            (int) ($stat['size'] ?? 0),
            (int) ($stat['mtime'] ?? 0)
        );
    }

    protected function thumbnailPathForSource(string $sourcePath): string
    {
        $stat = @stat($sourcePath) ?: [];

        return $this->thumbnailPathForStat(
            basename($sourcePath),
            (int) ($stat['size'] ?? 0),
            (int) ($stat['mtime'] ?? 0)
        );
    }

    protected function previewPathForStat(string $filename, int $size, int $mtime): string
    {
        $key = hash('sha256', implode('|', [$filename, (string) $size, (string) $mtime, 'preview-mp4-v2-aspect-safe']));

        return $this->previewCachePath().DIRECTORY_SEPARATOR.substr($key, 0, 2).DIRECTORY_SEPARATOR.$key.'.mp4';
    }

    protected function thumbnailPathForStat(string $filename, int $size, int $mtime): string
    {
        $key = hash('sha256', implode('|', [$filename, (string) $size, (string) $mtime, 'jpg']));

        return $this->thumbnailCachePath().DIRECTORY_SEPARATOR.substr($key, 0, 2).DIRECTORY_SEPARATOR.$key.'.jpg';
    }

    protected function tvPreviewPathForSource(string $sourcePath): string
    {
        $stat = @stat($sourcePath) ?: [];
        $key = hash('sha256', implode('|', [basename($sourcePath), (string) ($stat['size'] ?? 0), (string) ($stat['mtime'] ?? 0), 'tv-webp-v3']));

        return $this->previewCachePath().DIRECTORY_SEPARATOR.'tv-animated'.DIRECTORY_SEPARATOR.substr($key, 0, 2).DIRECTORY_SEPARATOR.$key.'.webp';
    }

    protected function tvHlsPathForSource(string $sourcePath): string
    {
        $stat = @stat($sourcePath) ?: [];
        $key = hash('sha256', implode('|', [basename($sourcePath), (string) ($stat['size'] ?? 0), (string) ($stat['mtime'] ?? 0), 'tv-hls-v6']));

        return $this->tvHlsCachePath().DIRECTORY_SEPARATOR.$key;
    }

    protected function staticCacheUrl(string $prefix, string $path, string $root): string
    {
        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        return $prefix.implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    protected function previewCachedForFilename(string $filename): bool
    {
        $path = $this->safeVideoPathInDirectory($this->rootPath(), $filename);

        return $path !== null && $this->previewCachedForPath($path);
    }

    protected function thumbnailCachedForFilename(string $filename): bool
    {
        $path = $this->safeVideoPathInDirectory($this->rootPath(), $filename);

        return $path !== null && $this->thumbnailCachedForPath($path);
    }

    protected function previewCachedForPath(string $sourcePath): bool
    {
        $path = $this->previewPathForSource($sourcePath);

        return is_file($path) && filesize($path) > 0;
    }

    protected function thumbnailCachedForPath(string $sourcePath): bool
    {
        $path = $this->thumbnailPathForSource($sourcePath);

        return is_file($path) && filesize($path) > 0;
    }

    protected function ensureThumbnailImage(string $sourcePath, string $thumbnailPath): bool
    {
        if (is_file($thumbnailPath) && filesize($thumbnailPath) > 0) {
            return true;
        }

        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === '') {
            return false;
        }

        File::ensureDirectoryExists(dirname($thumbnailPath));

        $lockPath = $thumbnailPath.'.lock';
        $lock = @fopen($lockPath, 'c');
        if (! $lock) {
            return false;
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                return false;
            }

            if (is_file($thumbnailPath) && filesize($thumbnailPath) > 0) {
                return true;
            }

            return $this->runThumbnailExtract($ffmpeg, $sourcePath, $thumbnailPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function runThumbnailExtract(string $ffmpeg, string $sourcePath, string $thumbnailPath): bool
    {
        $second = max(0, min((int) config('folder_video.thumbnail_second', 2), 120));
        $width = max(160, min((int) config('folder_video.thumbnail_width', 480), 1280));
        $timeout = max(10, min((int) config('folder_video.thumbnail_timeout', 45), 180));
        $temporaryPath = $thumbnailPath.'.tmp.'.getmypid().'.jpg';

        @unlink($temporaryPath);

        $process = new Process([
            $ffmpeg,
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-ss',
            (string) $second,
            '-i',
            $sourcePath,
            '-frames:v',
            '1',
            '-vf',
            'scale='.$width.':-2',
            '-q:v',
            '5',
            $temporaryPath,
        ], null, null, null, $timeout);

        $process->run();

        if (! $process->isSuccessful() || ! is_file($temporaryPath) || filesize($temporaryPath) <= 0) {
            @unlink($temporaryPath);

            return false;
        }

        if (! @rename($temporaryPath, $thumbnailPath)) {
            @unlink($temporaryPath);

            return false;
        }

        return true;
    }

    protected function ensurePreviewClip(string $sourcePath, string $previewPath): bool
    {
        if (is_file($previewPath) && filesize($previewPath) > 0) {
            return true;
        }

        $ffmpeg = $this->ffmpegBinary();
        if ($ffmpeg === '') {
            return false;
        }

        File::ensureDirectoryExists(dirname($previewPath));

        $lockPath = $previewPath.'.lock';
        $lock = @fopen($lockPath, 'c');
        if (! $lock) {
            return false;
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                return false;
            }

            if (is_file($previewPath) && filesize($previewPath) > 0) {
                return true;
            }

            return $this->runPreviewTranscode($ffmpeg, $sourcePath, $previewPath);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function runPreviewTranscode(string $ffmpeg, string $sourcePath, string $previewPath): bool
    {
        $seconds = max(4, min((int) config('folder_video.preview_seconds', 18), 120));
        $height = max(144, min((int) config('folder_video.preview_height', 360), 720));
        $timeout = max(15, min((int) config('folder_video.preview_timeout', 90), 600));
        $temporaryPath = $previewPath.'.tmp.'.getmypid().'.mp4';

        @unlink($temporaryPath);

        $process = new Process([
            $ffmpeg,
            '-hide_banner',
            '-loglevel',
            'error',
            '-y',
            '-ss',
            '0',
            '-i',
            $sourcePath,
            '-t',
            (string) $seconds,
            '-vf',
            'scale=-2:'.$height,
            '-an',
            '-c:v',
            'libx264',
            '-preset',
            'veryfast',
            '-tune',
            'fastdecode',
            '-crf',
            '32',
            '-pix_fmt',
            'yuv420p',
            '-movflags',
            '+faststart',
            $temporaryPath,
        ], null, null, null, $timeout);

        $process->run();

        if (! $process->isSuccessful() || ! is_file($temporaryPath) || filesize($temporaryPath) <= 0) {
            @unlink($temporaryPath);

            return false;
        }

        if (! @rename($temporaryPath, $previewPath)) {
            @unlink($temporaryPath);

            return false;
        }

        return true;
    }

    protected function ffmpegBinary(): string
    {
        $configured = trim((string) config('folder_video.ffmpeg_bin', ''));

        if ($configured !== '') {
            return $configured;
        }

        return 'ffmpeg';
    }

    protected function indexRecord(string $filename, array $stat, float $durationSeconds): array
    {
        return [
            'filename' => $filename,
            'size_bytes' => (int) ($stat['size'] ?? 0),
            'mtime' => (int) ($stat['mtime'] ?? 0),
            'ctime' => (int) ($stat['ctime'] ?? 0),
            'duration_seconds' => $durationSeconds,
            'duration_label' => $this->formatDuration($durationSeconds),
            'scanned_at' => now()->toIso8601String(),
        ];
    }

    protected function probeDurationSeconds(string $path): float
    {
        $binary = (string) config('folder_video.ffprobe_bin');

        if ($binary === '') {
            return 0.0;
        }

        try {
            return round(app(MediaDurationProbeService::class)->probeDurationSeconds(
                $path,
                $binary,
                null,
                30
            ), 3);
        } catch (Throwable) {
            return 0.0;
        }
    }

    protected function formatDuration(float $durationSeconds): string
    {
        $totalSeconds = max(0, (int) round($durationSeconds));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    protected function sortableDuration(float $durationSeconds): float
    {
        return $durationSeconds > 0 ? $durationSeconds : 999999999.0;
    }

    protected function sortRecords(Collection $records, string $order, string $seed = '', ?int $newFirstAfter = null): Collection
    {
        if ($this->isRandomOrder($order)) {
            return $this->sortRandomVideos($records, $seed, $newFirstAfter);
        }

        return $records->sort(fn (array $left, array $right) => [
            $this->sortableDuration((float) ($left['duration_seconds'] ?? 0.0)),
            $left['filename'] ?? '',
        ] <=> [
            $this->sortableDuration((float) ($right['duration_seconds'] ?? 0.0)),
            $right['filename'] ?? '',
        ]);
    }

    protected function sortRandomVideos(Collection $records, string $seed = '', ?int $newFirstAfter = null): Collection
    {
        $safeSeed = $seed !== '' ? $seed : now()->format('YmdHi');

        return $records->sort(function (array $left, array $right) use ($safeSeed, $newFirstAfter) {
            $leftNew = $this->newnessRank($left, $newFirstAfter);
            $rightNew = $this->newnessRank($right, $newFirstAfter);

            if ($leftNew !== $rightNew) {
                return $rightNew <=> $leftNew;
            }

            $leftHash = hash('sha256', $safeSeed.'|'.($left['filename'] ?? ''));
            $rightHash = hash('sha256', $safeSeed.'|'.($right['filename'] ?? ''));

            return [$leftHash, $left['filename'] ?? ''] <=> [$rightHash, $right['filename'] ?? ''];
        });
    }

    protected function newnessRank(array $record, ?int $newFirstAfter = null): int
    {
        if ($newFirstAfter === null || $newFirstAfter <= 0) {
            return 0;
        }

        $timestamp = max((int) ($record['mtime'] ?? $record['modified_at'] ?? 0), (int) ($record['ctime'] ?? $record['created_at'] ?? 0));

        return $timestamp > $newFirstAfter ? 1 : 0;
    }

    protected function isRandomOrder(string $order): bool
    {
        return in_array($order, ['random', 'random_new_first'], true);
    }

    protected function uniqueDestinationPath(string $directory, string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $candidate = $directory.DIRECTORY_SEPARATOR.$filename;
        $suffix = 1;

        while (file_exists($candidate)) {
            $nextName = $name.'_'.$suffix;
            $candidate = $directory.DIRECTORY_SEPARATOR.$nextName.($extension !== '' ? '.'.$extension : '');
            $suffix++;
        }

        return $candidate;
    }

    protected function streamUrlForFilename(string $filename, bool $liked = false): string
    {
        $basePath = trim((string) config('folder_video.stream_base_path', ''));
        if ($basePath !== '') {
            $goodSubdirectory = trim((string) config('folder_video.good_subdirectory', 'good'), '\\/');
            $relativePath = $liked && $goodSubdirectory !== ''
                ? $goodSubdirectory.'/'.$filename
                : $filename;

            return rtrim($basePath, '/').'/'.$this->encodeUrlPath($relativePath);
        }

        return route('folder-videos.stream', ['id' => $this->encodeId($filename)], false);
    }

    protected function previewUrlForFilename(string $filename): string
    {
        $sourcePath = null;
        foreach ([$this->rootPath(), $this->goodDirectoryPath()] as $directory) {
            $sourcePath = $this->safeVideoPathInDirectory($directory, $filename);
            if ($sourcePath !== null) {
                break;
            }
        }

        if ($sourcePath !== null) {
            $relative = str_replace('\\', '/', substr(
                $this->previewPathForSource($sourcePath),
                strlen($this->previewCachePath()) + 1
            ));
            $encoded = implode('/', array_map('rawurlencode', explode('/', $relative)));

            return '/folder-video-preview-cache/'.$encoded;
        }

        return route('folder-videos.preview', ['id' => $this->encodeId($filename)], false);
    }

    protected function thumbnailUrlForFilename(string $filename): string
    {
        return route('folder-videos.thumbnail', ['id' => $this->encodeId($filename)], false);
    }

    protected function moveVideoFile(string $sourcePath, string $destinationPath, string $message): void
    {
        $lastError = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            if (@rename($sourcePath, $destinationPath)) {
                return;
            }

            $lastError = error_get_last();
            clearstatcache(true, $sourcePath);
            clearstatcache(true, $destinationPath);
            usleep(150000 * $attempt);
        }

        $detail = is_array($lastError) && isset($lastError['message'])
            ? ' '.$lastError['message']
            : '';

        throw new FileException($message.' Source: '.$sourcePath.' Destination: '.$destinationPath.'.'.$detail);
    }

    protected function encodeUrlPath(string $path): string
    {
        $segments = preg_split('#[\\\\/]#', $path) ?: [];

        return implode('/', array_map(
            fn (string $segment): string => rawurlencode($segment),
            array_values(array_filter($segments, fn (string $segment): bool => $segment !== ''))
        ));
    }

    protected function encodeId(string $filename): string
    {
        return rtrim(strtr(base64_encode($filename), '+/', '-_'), '=');
    }

    protected function decodeId(string $id): string
    {
        $normalized = strtr($id, '-_', '+/');
        $padding = strlen($normalized) % 4;

        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);

        if (! is_string($decoded) || $decoded === '' || str_contains($decoded, '/') || str_contains($decoded, '\\')) {
            abort(404, 'Video not found.');
        }

        return $decoded;
    }

    protected function safeVideoPathInDirectory(string $directory, string $filename): ?string
    {
        $realDirectory = realpath($directory);
        if (! $realDirectory) {
            return null;
        }

        $realPath = realpath($realDirectory.DIRECTORY_SEPARATOR.$filename);
        if (! $realPath || ! is_file($realPath)) {
            return null;
        }

        $insideDirectory = str_starts_with($realPath, $realDirectory.DIRECTORY_SEPARATOR) || $realPath === $realDirectory;

        return $insideDirectory ? $realPath : null;
    }

    protected function hasFreshIndexEntry(?array $entry, array $stat): bool
    {
        return is_array($entry)
            && ($entry['size_bytes'] ?? null) === $stat['size']
            && ($entry['mtime'] ?? null) === $stat['mtime']
            && array_key_exists('duration_seconds', $entry);
    }

    protected function readIndex(): array
    {
        $payload = $this->readIndexPayload();
        $videos = $payload['videos'] ?? [];

        return collect(is_array($videos) ? $videos : [])
            ->filter(fn ($item) => is_array($item) && isset($item['filename']))
            ->mapWithKeys(fn (array $item) => [$item['filename'] => $item])
            ->all();
    }

    protected function readIndexPayload(): array
    {
        $path = $this->indexFilePath();

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        return is_array($payload) ? $payload : [];
    }

    protected function writeIndex(array $index): void
    {
        $records = collect($index)
            ->sort(fn (array $left, array $right) => [
                $this->sortableDuration((float) ($left['duration_seconds'] ?? 0.0)),
                $left['filename'] ?? '',
            ] <=> [
                $this->sortableDuration((float) ($right['duration_seconds'] ?? 0.0)),
                $right['filename'] ?? '',
            ])
            ->values()
            ->all();

        $path = $this->indexFilePath();
        File::ensureDirectoryExists(dirname($path));

        file_put_contents(
            $path,
            json_encode([
                'generated_at' => now()->toIso8601String(),
                'generated_at_unix' => time(),
                'root' => $this->rootPath(),
                'directory_mtime' => $this->rootDirectoryMTime(),
                'videos' => $records,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function rootDirectoryMTime(): int
    {
        $root = $this->rootPath();

        return is_dir($root) ? (int) @filemtime($root) : 0;
    }

    protected function forgetIndexEntry(string $filename): void
    {
        $index = $this->readIndex();
        unset($index[$filename]);
        $this->writeIndex($index);
    }
}
