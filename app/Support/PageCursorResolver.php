<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageCursorResolver
{
    private const THEMES = [
        'wand' => [
            'behavior' => 'spark',
        ],
        'balloon' => [
            'behavior' => 'float',
        ],
        'flower' => [
            'behavior' => 'petal',
        ],
        'butterfly' => [
            'behavior' => 'flutter',
        ],
        'bunny' => [
            'behavior' => 'paw',
        ],
        'cat' => [
            'behavior' => 'paw',
        ],
        'snake' => [
            'behavior' => 'trail',
        ],
    ];

    private const PRESETS = [
        'home' => 'balloon',
        'dashboard' => 'butterfly',
        'blog' => 'flower',
        'blog-preserved' => 'flower',
        'blog-bt' => 'flower',
        'btdig' => 'cat',
        'gallery' => 'butterfly',
        'my-page' => 'balloon',
        'product' => 'flower',
        'snake' => 'snake',
        'ocr' => 'wand',
        'upload' => 'balloon',
        'encrypt' => 'wand',
        'videos' => 'cat',
        'videos-management' => 'cat',
        'videos-search' => 'bunny',
        'video-player' => 'cat',
        'product-import2' => 'balloon',
        'verify-success' => 'butterfly',
        'already-verified' => 'butterfly',
        'extract' => 'wand',
        'url-viewer' => 'balloon',
        'ig-grabber' => 'flower',
        'dialogues-mark-read' => 'wand',
        'dialogues-token-stats' => 'wand',
        'tdl' => 'wand',
        'videos-duplicates' => 'cat',
        'videos-external-duplicates' => 'bunny',
        'videos-rerun-sync' => 'bunny',
        'face-identities' => 'butterfly',
        'command-runner' => 'wand',
    ];

    private const HASHABLE_THEMES = [
        'wand',
        'balloon',
        'flower',
        'butterfly',
        'bunny',
        'cat',
    ];

    public function __construct(
        private readonly PageFaviconResolver $faviconResolver,
    ) {
    }

    public function resolveRequest(Request $request): array
    {
        $faviconSpec = $this->faviconResolver->resolveRequest($request);

        return $this->resolveBySlug((string) ($faviconSpec['slug'] ?? 'page'));
    }

    public function resolveBySlug(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        $variant = self::PRESETS[$slug] ?? $this->variantFor($slug);
        $theme = self::THEMES[$variant] ?? self::THEMES['wand'];

        return array_merge([
            'slug' => $slug,
            'variant' => $variant,
        ], $theme);
    }

    private function variantFor(string $slug): string
    {
        $index = ((int) sprintf('%u', crc32($slug))) % count(self::HASHABLE_THEMES);

        return self::HASHABLE_THEMES[$index];
    }

    private function normalizeSlug(string $slug): string
    {
        $normalized = Str::of($slug)
            ->lower()
            ->replace(['.', '/', '_', ' '], '-')
            ->replaceMatches('/[^a-z0-9-]+/', '')
            ->replaceMatches('/-+/', '-')
            ->trim('-')
            ->value();

        return $normalized !== '' ? $normalized : 'page';
    }
}
