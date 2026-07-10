<?php

namespace App\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class FolderPhotoService
{
    /**
     * @return array<string, int|string>
     */
    public function appConfig(): array
    {
        $minSeconds = max(1, (int) config('folder_photo.display_min_seconds', 3));
        $maxSeconds = max($minSeconds, (int) config('folder_photo.display_max_seconds', 5));

        return [
            'version' => (string) config('folder_photo.app_version'),
            'initial_columns' => max(1, (int) config('folder_photo.initial_columns', 3)),
            'initial_rows' => max(1, (int) config('folder_photo.initial_rows', 4)),
            'max_columns' => max(1, (int) config('folder_photo.max_columns', 6)),
            'max_rows' => max(1, (int) config('folder_photo.max_rows', 8)),
            'display_min_ms' => $minSeconds * 1000,
            'display_max_ms' => $maxSeconds * 1000,
            'random_pool_limit' => max(12, (int) config('folder_photo.random_pool_limit', 500)),
        ];
    }

    /**
     * @return array<int, array{id: string, url: string}>
     */
    public function randomPhotos(int $count): array
    {
        $files = $this->loadIndex();
        $limit = max(1, (int) config('folder_photo.random_pool_limit', 500));
        $count = min(max(1, $count), $limit, count($files));

        if ($count === 0) {
            return [];
        }

        $selected = [];
        $usedIndexes = [];
        $fileCount = count($files);

        while (count($selected) < $count) {
            $index = mt_rand(0, $fileCount - 1);
            if (isset($usedIndexes[$index])) {
                continue;
            }

            $usedIndexes[$index] = true;
            $relativePath = $files[$index];
            $selected[] = [
                'id' => $this->encodeId($relativePath),
                'url' => $this->photoUrl($relativePath),
            ];
        }

        return $selected;
    }

    public function resolvePhotoPath(string $id): string
    {
        $relativePath = $this->decodeId($id);
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $root = $this->rootPath();
        $candidate = $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $realRoot = realpath($root);
        $realCandidate = realpath($candidate);

        if ($realRoot === false || $realCandidate === false || ! is_file($realCandidate)) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/').'/';
        $normalizedCandidate = str_replace('\\', '/', $realCandidate);
        if (! str_starts_with(strtolower($normalizedCandidate), strtolower($normalizedRoot))) {
            throw new NotFoundHttpException('Photo not found.');
        }

        $extension = strtolower((string) pathinfo($realCandidate, PATHINFO_EXTENSION));
        if (! in_array($extension, $this->extensions(), true)) {
            throw new NotFoundHttpException('Photo not found.');
        }

        return $realCandidate;
    }

    /**
     * @return array<int, string>
     */
    public function rebuildIndex(): array
    {
        $root = $this->rootPath();
        if (! is_dir($root)) {
            throw new RuntimeException("Folder Photo root is not available: {$root}");
        }

        $extensions = array_fill_keys($this->extensions(), true);
        $files = [];
        $rootPrefixLength = strlen(rtrim($root, '\\/')) + 1;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());
                if (! isset($extensions[$extension])) {
                    continue;
                }

                $relativePath = substr($file->getPathname(), $rootPrefixLength);
                if ($relativePath !== false && $relativePath !== '') {
                    $files[] = str_replace('\\', '/', $relativePath);
                }
            }
        } catch (Throwable $error) {
            throw new RuntimeException('Unable to scan Folder Photo root: '.$error->getMessage(), 0, $error);
        }

        sort($files, SORT_NATURAL | SORT_FLAG_CASE);
        $this->writeIndex($root, $files);

        return $files;
    }

    /**
     * @return array<int, string>
     */
    private function loadIndex(): array
    {
        $indexPath = $this->indexPath();
        $refreshSeconds = max(0, (int) config('folder_photo.index_refresh_seconds', 3600));
        $isFresh = is_file($indexPath)
            && ($refreshSeconds === 0 || (time() - (int) filemtime($indexPath)) < $refreshSeconds);

        if ($isFresh) {
            $files = $this->readIndex($indexPath);
            if ($files !== null) {
                return $files;
            }
        }

        $lockPath = $indexPath.'.lock';
        $directory = dirname($indexPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create Folder Photo index directory: {$directory}");
        }

        $lock = fopen($lockPath, 'c+');
        if ($lock === false) {
            return $this->rebuildIndex();
        }

        try {
            flock($lock, LOCK_EX);
            $files = is_file($indexPath) ? $this->readIndex($indexPath) : null;
            $becameFresh = is_file($indexPath)
                && ($refreshSeconds === 0 || (time() - (int) filemtime($indexPath)) < $refreshSeconds);

            if ($files !== null && $becameFresh) {
                return $files;
            }

            return $this->rebuildIndex();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @return array<int, string>|null
     */
    private function readIndex(string $path): ?array
    {
        $payload = json_decode((string) @file_get_contents($path), true);
        if (! is_array($payload) || ($payload['root'] ?? null) !== $this->rootPath() || ! is_array($payload['files'] ?? null)) {
            return null;
        }

        return array_values(array_filter($payload['files'], static fn ($path): bool => is_string($path) && $path !== ''));
    }

    /**
     * @param array<int, string> $files
     */
    private function writeIndex(string $root, array $files): void
    {
        $indexPath = $this->indexPath();
        $directory = dirname($indexPath);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create Folder Photo index directory: {$directory}");
        }

        $payload = json_encode([
            'generated_at' => now()->toIso8601String(),
            'root' => $root,
            'count' => count($files),
            'files' => $files,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (! is_string($payload)) {
            throw new RuntimeException('Unable to encode Folder Photo index.');
        }

        $temporaryPath = $indexPath.'.'.getmypid().'.tmp';
        if (file_put_contents($temporaryPath, $payload) === false) {
            throw new RuntimeException("Unable to write Folder Photo index: {$indexPath}");
        }

        @unlink($indexPath);
        if (! rename($temporaryPath, $indexPath)) {
            @unlink($temporaryPath);
            throw new RuntimeException("Unable to replace Folder Photo index: {$indexPath}");
        }
    }

    private function photoUrl(string $relativePath): string
    {
        $basePath = trim((string) config('folder_photo.stream_base_path', ''));
        if ($basePath === '') {
            return route('folder-photos.show', ['id' => $this->encodeId($relativePath)], false);
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', str_replace('\\', '/', $relativePath))));

        return '/'.trim($basePath, '/').'/'.$encodedPath;
    }

    private function encodeId(string $relativePath): string
    {
        return rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=');
    }

    private function decodeId(string $id): string
    {
        $padding = strlen($id) % 4;
        if ($padding !== 0) {
            $id .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($id, '-_', '+/'), true);

        return is_string($decoded) ? $decoded : '';
    }

    private function rootPath(): string
    {
        return rtrim((string) config('folder_photo.root'), '\\/');
    }

    private function indexPath(): string
    {
        return (string) config('folder_photo.index_path');
    }

    /**
     * @return array<int, string>
     */
    private function extensions(): array
    {
        return array_values(array_unique(array_map(
            static fn ($extension): string => strtolower(ltrim((string) $extension, '.')),
            (array) config('folder_photo.extensions', [])
        )));
    }
}
