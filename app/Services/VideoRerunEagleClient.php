<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VideoRerunEagleClient
{
    public function currentLibrary(): array
    {
        $response = $this->request()->get('/api/library/info')->throw()->json();

        return (array) ($response['data']['library'] ?? []);
    }

    public function ensureConfiguredLibrary(): array
    {
        $current = $this->currentLibrary();
        $configuredPath = trim((string) config('video_rerun_sync.eagle.library_path', ''));

        if ($configuredPath === '' || !File::isDirectory($configuredPath)) {
            return $current;
        }

        if (($current['path'] ?? null) === $configuredPath) {
            return $current;
        }

        $this->request()
            ->asJson()
            ->post('/api/library/switch', [
                'libraryPath' => $configuredPath,
            ])
            ->throw();

        return $this->currentLibrary();
    }

    public function listItems(?int $limit = null): array
    {
        $requestLimit = $limit ?? max(1, (int) config('video_rerun_sync.eagle.fetch_limit', 10000));

        $response = $this->request()
            ->get('/api/item/list', [
                'limit' => $requestLimit,
                'offset' => 0,
            ])
            ->throw()
            ->json();

        return array_values((array) ($response['data'] ?? []));
    }

    public function moveToTrash(string $itemId): void
    {
        $this->request()
            ->asJson()
            ->post('/api/item/moveToTrash', [
                'itemIds' => [$itemId],
            ])
            ->throw();
    }

    public function addFromPath(string $path, string $name): void
    {
        $this->request()
            ->asJson()
            ->post('/api/item/addFromPaths', [
                'items' => [[
                    'path' => $path,
                    'name' => $name,
                ]],
            ])
            ->throw();
    }

    public function resolveItemFilePath(string $libraryPath, array $item): string
    {
        $itemId = (string) ($item['id'] ?? '');
        if ($itemId === '') {
            throw new RuntimeException('Eagle item id is missing.');
        }

        $infoDirectory = rtrim($libraryPath, '/\\') . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $itemId . '.info';
        if (!File::isDirectory($infoDirectory)) {
            throw new RuntimeException('Eagle item folder not found: ' . $infoDirectory);
        }

        $name = (string) ($item['name'] ?? '');
        $ext = (string) ($item['ext'] ?? '');
        if ($name !== '' && $ext !== '') {
            $preferred = $infoDirectory . DIRECTORY_SEPARATOR . $name . '.' . $ext;
            if (File::exists($preferred)) {
                return $preferred;
            }
        }

        $candidates = collect(File::files($infoDirectory))
            ->reject(static function (\SplFileInfo $file): bool {
                $basename = $file->getBasename();

                return in_array($basename, ['metadata.json'], true)
                    || str_contains($basename, '_thumbnail.');
            })
            ->values();

        $match = $ext === ''
            ? $candidates->first()
            : $candidates->first(static fn (\SplFileInfo $file) => strcasecmp($file->getExtension(), $ext) === 0);

        if (!$match instanceof \SplFileInfo) {
            throw new RuntimeException('Eagle item original file not found: ' . $infoDirectory);
        }

        return $match->getPathname();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('video_rerun_sync.eagle.base_url', 'http://localhost:41595'), '/'))
            ->timeout(30)
            ->acceptJson();
    }
}
