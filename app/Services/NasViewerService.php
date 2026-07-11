<?php

namespace App\Services;

use DirectoryIterator;
use Illuminate\Support\Str;
use SplFileInfo;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class NasViewerService
{
    /**
     * @return array<string, int|string|array<int, string>>
     */
    public function appConfig(): array
    {
        return [
            'version' => (string) config('nas_viewer.app_version'),
            'page_limit' => max(1, (int) config('nas_viewer.page_limit', 300)),
            'video_extensions' => $this->extensions('video_extensions'),
            'image_extensions' => $this->extensions('image_extensions'),
            'apk_extensions' => $this->extensions('apk_extensions'),
            'text_extensions' => $this->extensions('text_extensions'),
        ];
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function listDirectory(?string $directoryId, int $offset, int $limit): array
    {
        $offset = max(0, $offset);
        $limit = min(
            max(1, $limit),
            max(1, (int) config('nas_viewer.max_page_limit', 1000))
        );

        if ($directoryId === null || $directoryId === '') {
            return $this->listRoots($offset, $limit);
        }

        $identity = $this->decodeId($directoryId);
        $directory = $this->resolvePath($identity['root_id'], $identity['relative_path'], true);
        $entries = [];

        try {
            foreach (new DirectoryIterator($directory['path']) as $file) {
                if ($file->isDot() || $this->isHidden($file->getFilename())) {
                    continue;
                }

                $entry = $this->entryPayload(
                    $directory['root_id'],
                    $directory['relative_path'],
                    $file
                );
                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        } catch (Throwable $error) {
            throw new NotFoundHttpException('Unable to read this NAS directory.', $error);
        }

        usort($entries, static function (array $left, array $right): int {
            $leftDirectory = $left['kind'] === 'directory';
            $rightDirectory = $right['kind'] === 'directory';
            if ($leftDirectory !== $rightDirectory) {
                return $leftDirectory ? -1 : 1;
            }

            return strnatcasecmp((string) $left['name'], (string) $right['name']);
        });

        $total = count($entries);

        return [
            'entries' => array_slice($entries, $offset, $limit),
            'meta' => [
                'directory_id' => $directoryId,
                'title' => $this->directoryTitle($directory['root_id'], $directory['relative_path']),
                'breadcrumbs' => $this->breadcrumbs($directory['root_id'], $directory['relative_path']),
                'offset' => $offset,
                'limit' => $limit,
                'total' => $total,
                'has_more' => $offset + $limit < $total,
                'next_offset' => min($total, $offset + $limit),
            ],
        ];
    }

    /**
     * @return array{name: string, content: string, size_bytes: int, encoding: string}
     */
    public function readText(string $id): array
    {
        $identity = $this->decodeId($id);
        $file = $this->resolvePath($identity['root_id'], $identity['relative_path'], false);
        if ($this->kindForPath($file['path']) !== 'text') {
            throw new NotFoundHttpException('This file is not a supported text document.');
        }

        $size = (int) filesize($file['path']);
        $maxBytes = max(1024, (int) config('nas_viewer.text_max_bytes', 5 * 1024 * 1024));
        if ($size > $maxBytes) {
            throw new HttpException(413, 'Text file is too large to display.');
        }

        $bytes = file_get_contents($file['path']);
        if (! is_string($bytes)) {
            throw new NotFoundHttpException('Unable to read this text document.');
        }

        [$content, $encoding] = $this->decodeText($bytes);

        return [
            'name' => $this->validUtf8(basename($file['path'])),
            'content' => $content,
            'size_bytes' => $size,
            'encoding' => $encoding,
        ];
    }

    public function resolveFilePath(string $id): string
    {
        $identity = $this->decodeId($id);
        $file = $this->resolvePath($identity['root_id'], $identity['relative_path'], false);
        if (! in_array($this->kindForPath($file['path']), ['video', 'image', 'apk'], true)) {
            throw new NotFoundHttpException('This file is not supported by the media viewer.');
        }

        return $file['path'];
    }

    /**
     * @return array{entries: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    private function listRoots(int $offset, int $limit): array
    {
        $entries = [];
        foreach ($this->roots() as $rootId => $root) {
            $path = rtrim((string) ($root['path'] ?? ''), '\\/');
            $available = $path !== '' && is_dir($path);
            $entries[] = [
                'id' => $this->encodeId((string) $rootId, ''),
                'name' => (string) ($root['label'] ?? $rootId),
                'kind' => 'directory',
                'available' => $available,
                'size_bytes' => null,
                'modified_at' => $available ? $this->safeModifiedAt($path) : null,
                'media_url' => null,
                'download_url' => null,
            ];
        }

        $total = count($entries);

        return [
            'entries' => array_slice($entries, $offset, $limit),
            'meta' => [
                'directory_id' => null,
                'title' => 'NAS',
                'breadcrumbs' => [],
                'offset' => $offset,
                'limit' => $limit,
                'total' => $total,
                'has_more' => $offset + $limit < $total,
                'next_offset' => min($total, $offset + $limit),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function entryPayload(string $rootId, string $directoryRelativePath, SplFileInfo $file): ?array
    {
        $name = $this->validUtf8($file->getFilename());
        $relativePath = ltrim(
            str_replace('\\', '/', trim($directoryRelativePath, '\\/').'/'.$name),
            '/'
        );

        if ($file->isDir()) {
            return [
                'id' => $this->encodeId($rootId, $relativePath),
                'name' => $name,
                'kind' => 'directory',
                'available' => true,
                'size_bytes' => null,
                'modified_at' => $this->safeModifiedAt($file->getPathname()),
                'media_url' => null,
                'download_url' => null,
            ];
        }

        if (! $file->isFile()) {
            return null;
        }

        $kind = $this->kindForPath($file->getPathname());

        return [
            'id' => $this->encodeId($rootId, $relativePath),
            'name' => $name,
            'kind' => $kind,
            'nas_share' => (string) (($this->roots()[$rootId]['label'] ?? $rootId)),
            'relative_path' => $relativePath,
            'available' => true,
            'size_bytes' => $this->safeSize($file),
            'modified_at' => $this->safeModifiedAt($file->getPathname()),
            'media_url' => in_array($kind, ['video', 'image'], true)
                ? $this->mediaUrl($rootId, $relativePath)
                : null,
            'download_url' => $kind === 'apk'
                ? $this->mediaUrl($rootId, $relativePath)
                : null,
            'text_url' => $kind === 'text'
                ? route('nas-browser.text', ['id' => $this->encodeId($rootId, $relativePath)], false)
                : null,
        ];
    }

    /**
     * @return array{root_id: string, relative_path: string, path: string}
     */
    private function resolvePath(string $rootId, string $relativePath, bool $directory): array
    {
        $roots = $this->roots();
        if (! isset($roots[$rootId])) {
            throw new NotFoundHttpException('Unknown NAS share.');
        }

        $rootPath = rtrim((string) ($roots[$rootId]['path'] ?? ''), '\\/');
        $realRoot = realpath($rootPath);
        if ($realRoot === false || ! is_dir($realRoot)) {
            throw new NotFoundHttpException('NAS share is unavailable.');
        }

        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        $candidate = $relativePath === ''
            ? $realRoot
            : $realRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $realCandidate = realpath($candidate);
        if ($realCandidate === false) {
            throw new NotFoundHttpException('NAS item was not found.');
        }

        $normalizedRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $normalizedCandidate = str_replace('\\', '/', $realCandidate);
        $insideRoot = strcasecmp($normalizedCandidate, $normalizedRoot) === 0
            || str_starts_with(strtolower($normalizedCandidate), strtolower($normalizedRoot.'/'));
        if (! $insideRoot) {
            throw new NotFoundHttpException('NAS item is outside the configured share.');
        }

        if (($directory && ! is_dir($realCandidate)) || (! $directory && ! is_file($realCandidate))) {
            throw new NotFoundHttpException('NAS item has the wrong type.');
        }

        return [
            'root_id' => $rootId,
            'relative_path' => $relativePath,
            'path' => $realCandidate,
        ];
    }

    /**
     * @return array{root_id: string, relative_path: string}
     */
    private function decodeId(string $id): array
    {
        $padding = strlen($id) % 4;
        if ($padding !== 0) {
            $id .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode(strtr($id, '-_', '+/'), true);
        $payload = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($payload) || ! is_string($payload['r'] ?? null) || ! is_string($payload['p'] ?? null)) {
            throw new NotFoundHttpException('Invalid NAS item id.');
        }

        $relativePath = str_replace('\\', '/', $payload['p']);
        if (str_contains($relativePath, "\0") || Str::startsWith($relativePath, ['/'])) {
            throw new NotFoundHttpException('Invalid NAS item path.');
        }

        return [
            'root_id' => $payload['r'],
            'relative_path' => trim($relativePath, '/'),
        ];
    }

    private function encodeId(string $rootId, string $relativePath): string
    {
        $json = json_encode([
            'r' => $rootId,
            'p' => trim(str_replace('\\', '/', $relativePath), '/'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    private function mediaUrl(string $rootId, string $relativePath): string
    {
        $root = $this->roots()[$rootId] ?? [];
        $basePath = trim((string) ($root['stream_base_path'] ?? ''), '/');
        if ($basePath === '') {
            return route('nas-browser.stream', ['id' => $this->encodeId($rootId, $relativePath)], false);
        }

        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $relativePath)));

        return '/'.$basePath.'/'.$encodedPath;
    }

    private function kindForPath(string $path): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $filename = strtolower((string) pathinfo($path, PATHINFO_FILENAME));

        if (in_array($extension, $this->extensions('video_extensions'), true)) {
            return 'video';
        }
        if (in_array($extension, $this->extensions('image_extensions'), true)) {
            return 'image';
        }
        if (in_array($extension, $this->extensions('apk_extensions'), true)) {
            return 'apk';
        }
        if (
            in_array($extension, $this->extensions('text_extensions'), true)
            || ($extension === '' && in_array($filename, (array) config('nas_viewer.text_filenames', []), true))
        ) {
            return 'text';
        }

        return 'other';
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    private function breadcrumbs(string $rootId, string $relativePath): array
    {
        $root = $this->roots()[$rootId] ?? [];
        $breadcrumbs = [[
            'id' => $this->encodeId($rootId, ''),
            'label' => (string) ($root['label'] ?? $rootId),
        ]];
        $builtPath = '';

        foreach (array_filter(explode('/', trim($relativePath, '/'))) as $segment) {
            $builtPath = ltrim($builtPath.'/'.$segment, '/');
            $breadcrumbs[] = [
                'id' => $this->encodeId($rootId, $builtPath),
                'label' => $this->validUtf8($segment),
            ];
        }

        return $breadcrumbs;
    }

    private function directoryTitle(string $rootId, string $relativePath): string
    {
        $root = $this->roots()[$rootId] ?? [];
        if ($relativePath === '') {
            return (string) ($root['label'] ?? $rootId);
        }

        return $this->validUtf8((string) basename(str_replace('/', DIRECTORY_SEPARATOR, $relativePath)));
    }

    private function isHidden(string $name): bool
    {
        if ((bool) config('nas_viewer.hide_dot_files', true) && str_starts_with($name, '.')) {
            return true;
        }

        foreach ((array) config('nas_viewer.hidden_names', []) as $hiddenName) {
            if (strcasecmp($name, (string) $hiddenName) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function decodeText(string $bytes): array
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            return [substr($bytes, 3), 'UTF-8 BOM'];
        }
        if (str_starts_with($bytes, "\xFF\xFE")) {
            return [mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16LE'];
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return [mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16BE'];
        }
        if (mb_check_encoding($bytes, 'UTF-8')) {
            return [$bytes, 'UTF-8'];
        }

        $detected = mb_detect_encoding($bytes, ['BIG-5', 'CP950', 'UTF-8'], true) ?: 'CP950';

        return [mb_convert_encoding($bytes, 'UTF-8', $detected), $detected];
    }

    private function validUtf8(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8')
            ? $value
            : mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    private function safeSize(SplFileInfo $file): ?int
    {
        try {
            return $file->getSize();
        } catch (Throwable) {
            return null;
        }
    }

    private function safeModifiedAt(string $path): ?string
    {
        $timestamp = @filemtime($path);

        return is_int($timestamp) ? date(DATE_ATOM, $timestamp) : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function roots(): array
    {
        return (array) config('nas_viewer.roots', []);
    }

    /**
     * @return array<int, string>
     */
    private function extensions(string $key): array
    {
        return array_values(array_unique(array_map(
            static fn ($extension): string => strtolower(ltrim((string) $extension, '.')),
            (array) config('nas_viewer.'.$key, [])
        )));
    }
}
