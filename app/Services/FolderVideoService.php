<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
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
        ?int $newFirstAfter = null
    ): array
    {
        $limit = max(1, min($limit, 100));

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

                $durationSeconds = $this->resolveDurationSeconds(
                    $filename,
                    $path,
                    $stat,
                    $index,
                    $forceProbe,
                    $probeMissingDurations
                );

                return [
                    'id' => $this->encodeId($filename),
                    'filename' => $filename,
                    'duration_seconds' => $durationSeconds,
                    'duration_label' => $this->formatDuration($durationSeconds),
                    'size_bytes' => $stat['size'],
                    'mtime' => $stat['mtime'],
                    'ctime' => $stat['ctime'],
                    'stream_url' => route('folder-videos.stream', ['id' => $this->encodeId($filename)], false),
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
        $path = $this->rootPath().DIRECTORY_SEPARATOR.$filename;
        $realPath = realpath($path);
        $realRoot = realpath($this->rootPath());

        if (! $realRoot || ! $realPath || ! str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR) && $realPath !== $realRoot) {
            abort(404, 'Video not found.');
        }

        if (! is_file($realPath)) {
            abort(404, 'Video not found.');
        }

        return $realPath;
    }

    public function moveToGood(string $id): array
    {
        $sourcePath = $this->resolveVideoPath($id);
        $goodDirectory = $this->goodDirectoryPath();
        File::ensureDirectoryExists($goodDirectory);

        $destinationPath = $this->uniqueDestinationPath($goodDirectory, basename($sourcePath));

        if (! @rename($sourcePath, $destinationPath)) {
            throw new FileException('Unable to move video to good folder.');
        }

        $this->forgetIndexEntry(basename($sourcePath));

        return [
            'filename' => basename($destinationPath),
            'destination' => $destinationPath,
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
            'preview_max_connections' => max(1, min((int) config('folder_video.app_preview_max_connections', 6), 12)),
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

        if (($offset === 0 || $index === []) && $this->shouldRefreshIndex($index)) {
            $index = $this->refreshLightweightIndex($index);
        }

        if ($index !== []) {
            return $this->listIndexedVideosPage($index, $limit, $offset, $order, $seed, $newFirstAfter);
        }

        return $this->listDirectoryVideosPage($limit, $offset, $order, $seed, $newFirstAfter);
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

            $videos->push($this->videoPayloadFromFile($file));
        }

        return [
            'videos' => $videos,
            'has_more' => $hasMore,
            'next_offset' => $offset + $videos->count(),
        ];
    }

    protected function videoPayloadFromFile(\SplFileInfo $file): array
    {
        return [
            'id' => $this->encodeId($file->getFilename()),
            'filename' => $file->getFilename(),
            'duration_seconds' => 0.0,
            'duration_label' => $this->formatDuration(0.0),
            'size_bytes' => $file->getSize(),
            'modified_at' => $file->getMTime(),
            'created_at' => $file->getCTime(),
            'stream_url' => route('folder-videos.stream', ['id' => $this->encodeId($file->getFilename())], false),
        ];
    }

    protected function videoPayloadFromIndexEntry(array $entry): array
    {
        $filename = (string) ($entry['filename'] ?? '');
        $durationSeconds = (float) ($entry['duration_seconds'] ?? 0.0);

        return [
            'id' => $this->encodeId($filename),
            'filename' => $filename,
            'duration_seconds' => $durationSeconds,
            'duration_label' => (string) ($entry['duration_label'] ?? $this->formatDuration($durationSeconds)),
            'size_bytes' => (int) ($entry['size_bytes'] ?? 0),
            'modified_at' => (int) ($entry['mtime'] ?? 0),
            'created_at' => (int) ($entry['ctime'] ?? 0),
            'stream_url' => route('folder-videos.stream', ['id' => $this->encodeId($filename)], false),
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
