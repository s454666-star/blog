<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Throwable;

class FolderVideoService
{
    public function listVideosPage(int $limit = 15, ?float $afterDuration = null, ?string $afterFilename = null): array
    {
        $limit = max(1, min($limit, 100));
        $videos = $this->filteredVideos($afterDuration, $afterFilename);

        return [
            'videos' => $videos->take($limit)->values(),
            'has_more' => $videos->count() > $limit,
        ];
    }

    public function listVideos(int $limit = 15, ?float $afterDuration = null, ?string $afterFilename = null): Collection
    {
        return $this->listVideosPage($limit, $afterDuration, $afterFilename)['videos'];
    }

    public function warmCache(bool $force = true): int
    {
        return $this->collectVideoEntries($force)->count();
    }

    public function indexFilePath(): string
    {
        return $this->rootPath().DIRECTORY_SEPARATOR.(string) config('folder_video.index_filename', 'folder-video-index.json');
    }

    protected function collectVideoEntries(bool $forceProbe = false): Collection
    {
        $root = $this->rootPath();
        File::ensureDirectoryExists($root);

        $index = $this->readIndex();
        $knownFilenames = [];
        $videos = collect(File::files($root))
            ->filter(fn (\SplFileInfo $file) => $this->isPlayableVideo($file))
            ->map(function (\SplFileInfo $file) use (&$index, &$knownFilenames, $forceProbe) {
                $filename = $file->getFilename();
                $path = $file->getPathname();
                $stat = [
                    'size' => $file->getSize(),
                    'mtime' => $file->getMTime(),
                ];
                $knownFilenames[$filename] = true;

                $durationSeconds = $this->resolveDurationSeconds($filename, $path, $stat, $index, $forceProbe);

                return [
                    'id' => $this->encodeId($filename),
                    'filename' => $filename,
                    'duration_seconds' => $durationSeconds,
                    'duration_label' => $this->formatDuration($durationSeconds),
                    'size_bytes' => $stat['size'],
                    'stream_url' => route('folder-videos.stream', ['id' => $this->encodeId($filename)]),
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

    protected function resolveDurationSeconds(string $filename, string $path, array $stat, array &$index, bool $forceProbe = false): float
    {
        $indexed = $index[$filename] ?? null;

        if (! $forceProbe && $this->hasFreshIndexEntry($indexed, $stat)) {
            return (float) ($indexed['duration_seconds'] ?? 0.0);
        }

        $durationSeconds = $this->probeDurationSeconds($path);
        $index[$filename] = [
            'filename' => $filename,
            'size_bytes' => $stat['size'],
            'mtime' => $stat['mtime'],
            'duration_seconds' => $durationSeconds,
            'duration_label' => $this->formatDuration($durationSeconds),
            'scanned_at' => now()->toIso8601String(),
        ];

        return $durationSeconds;
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
        $path = $this->indexFilePath();

        if (! is_file($path)) {
            return [];
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            return [];
        }

        $videos = $payload['videos'] ?? [];

        return collect(is_array($videos) ? $videos : [])
            ->filter(fn ($item) => is_array($item) && isset($item['filename']))
            ->mapWithKeys(fn (array $item) => [$item['filename'] => $item])
            ->all();
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

        file_put_contents(
            $this->indexFilePath(),
            json_encode([
                'generated_at' => now()->toIso8601String(),
                'root' => $this->rootPath(),
                'videos' => $records,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    protected function forgetIndexEntry(string $filename): void
    {
        $index = $this->readIndex();
        unset($index[$filename]);
        $this->writeIndex($index);
    }
}
