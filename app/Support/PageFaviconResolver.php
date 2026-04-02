<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PageFaviconResolver
{
    private const PALETTES = [
        'sky' => [
            'bg_start' => '#7dd3fc',
            'bg_end' => '#2563eb',
            'wave_start' => '#c4b5fd',
            'wave_end' => '#38bdf8',
            'bubble' => '#fde68a',
            'ink' => '#f8fafc',
            'theme' => '#38bdf8',
        ],
        'coral' => [
            'bg_start' => '#fb7185',
            'bg_end' => '#f97316',
            'wave_start' => '#fdba74',
            'wave_end' => '#fb7185',
            'bubble' => '#fef08a',
            'ink' => '#fff7ed',
            'theme' => '#f97316',
        ],
        'jade' => [
            'bg_start' => '#34d399',
            'bg_end' => '#0f766e',
            'wave_start' => '#99f6e4',
            'wave_end' => '#34d399',
            'bubble' => '#fef08a',
            'ink' => '#ecfeff',
            'theme' => '#14b8a6',
        ],
        'berry' => [
            'bg_start' => '#c084fc',
            'bg_end' => '#7c3aed',
            'wave_start' => '#f9a8d4',
            'wave_end' => '#c084fc',
            'bubble' => '#fde68a',
            'ink' => '#faf5ff',
            'theme' => '#8b5cf6',
        ],
        'amber' => [
            'bg_start' => '#fbbf24',
            'bg_end' => '#d97706',
            'wave_start' => '#fde68a',
            'wave_end' => '#f59e0b',
            'bubble' => '#fef3c7',
            'ink' => '#fffbeb',
            'theme' => '#f59e0b',
        ],
        'rose' => [
            'bg_start' => '#fb7185',
            'bg_end' => '#be185d',
            'wave_start' => '#fbcfe8',
            'wave_end' => '#f43f5e',
            'bubble' => '#fde68a',
            'ink' => '#fff1f2',
            'theme' => '#f43f5e',
        ],
        'indigo' => [
            'bg_start' => '#818cf8',
            'bg_end' => '#4338ca',
            'wave_start' => '#c7d2fe',
            'wave_end' => '#818cf8',
            'bubble' => '#fef08a',
            'ink' => '#eef2ff',
            'theme' => '#6366f1',
        ],
        'mint' => [
            'bg_start' => '#5eead4',
            'bg_end' => '#0f766e',
            'wave_start' => '#ccfbf1',
            'wave_end' => '#14b8a6',
            'bubble' => '#fde68a',
            'ink' => '#f0fdfa',
            'theme' => '#14b8a6',
        ],
        'slate' => [
            'bg_start' => '#94a3b8',
            'bg_end' => '#334155',
            'wave_start' => '#cbd5e1',
            'wave_end' => '#64748b',
            'bubble' => '#fcd34d',
            'ink' => '#f8fafc',
            'theme' => '#64748b',
        ],
    ];

    private const PRESETS = [
        'home' => ['label' => 'HM', 'palette' => 'sky', 'title' => 'Home'],
        'dashboard' => ['label' => 'DB', 'palette' => 'indigo', 'title' => 'Dashboard'],
        'blog' => ['label' => 'BG', 'palette' => 'berry', 'title' => 'Blog'],
        'blog-preserved' => ['label' => 'SV', 'palette' => 'rose', 'title' => 'Saved Blog'],
        'blog-bt' => ['label' => 'BT', 'palette' => 'jade', 'title' => 'BT Blog'],
        'btdig' => ['label' => 'BD', 'palette' => 'mint', 'title' => 'BTDig'],
        'gallery' => ['label' => 'GL', 'palette' => 'amber', 'title' => 'Gallery'],
        'my-page' => ['label' => 'ME', 'palette' => 'coral', 'title' => 'Profile'],
        'product' => ['label' => 'FD', 'palette' => 'amber', 'title' => 'Breakfast Menu'],
        'snake' => ['label' => 'SN', 'palette' => 'jade', 'title' => 'Snake Game'],
        'ocr' => ['label' => 'OC', 'palette' => 'sky', 'title' => 'OCR'],
        'upload' => ['label' => 'UP', 'palette' => 'coral', 'title' => 'Upload'],
        'encrypt' => ['label' => 'EN', 'palette' => 'slate', 'title' => 'Encrypt'],
        'videos' => ['label' => 'VL', 'palette' => 'indigo', 'title' => 'Video List'],
        'videos-management' => ['label' => 'VM', 'palette' => 'berry', 'title' => 'Video Manager'],
        'videos-search' => ['label' => 'VS', 'palette' => 'sky', 'title' => 'Video Search'],
        'video-player' => ['label' => 'VP', 'palette' => 'coral', 'title' => 'Video Player'],
        'product-import2' => ['label' => 'PI', 'palette' => 'mint', 'title' => 'Product Import'],
        'verify-success' => ['label' => 'OK', 'palette' => 'jade', 'title' => 'Verification Success'],
        'already-verified' => ['label' => 'AV', 'palette' => 'sky', 'title' => 'Already Verified'],
        'extract' => ['label' => 'EX', 'palette' => 'rose', 'title' => 'Extract'],
        'url-viewer' => ['label' => 'UV', 'palette' => 'slate', 'title' => 'URL Viewer'],
        'ig-grabber' => ['label' => 'IG', 'palette' => 'rose', 'title' => 'IG Grabber'],
        'dialogues-mark-read' => ['label' => 'MR', 'palette' => 'sky', 'title' => 'Mark Read'],
        'dialogues-token-stats' => ['label' => 'TS', 'palette' => 'amber', 'title' => 'Token Stats'],
        'tdl' => ['label' => 'TD', 'palette' => 'indigo', 'title' => 'TDL'],
        'videos-duplicates' => ['label' => 'DP', 'palette' => 'slate', 'title' => 'Duplicate Videos'],
        'videos-external-duplicates' => ['label' => 'XD', 'palette' => 'berry', 'title' => 'External Duplicates'],
        'face-identities' => ['label' => 'FI', 'palette' => 'mint', 'title' => 'Face Identities'],
    ];

    public function resolveRequest(Request $request): array
    {
        return $this->resolveBySlug($this->slugFromRequest($request));
    }

    public function resolveBySlug(string $slug): array
    {
        $slug = $this->normalizeSlug($slug);
        $preset = self::PRESETS[$slug] ?? [];
        $paletteName = $preset['palette'] ?? $this->paletteFor($slug);
        $palette = self::PALETTES[$paletteName] ?? self::PALETTES['sky'];

        return array_merge(
            [
                'slug' => $slug,
                'label' => $preset['label'] ?? $this->labelFromSlug($slug),
                'title' => $preset['title'] ?? Str::headline(str_replace('-', ' ', $slug)),
            ],
            $palette,
            $preset,
        );
    }

    public function renderSvg(array $spec): string
    {
        $uid = 'fav' . substr(md5((string) ($spec['slug'] ?? 'page')), 0, 8);
        $title = $this->escapeXml((string) ($spec['title'] ?? 'Page Icon'));
        $label = $this->escapeXml((string) ($spec['label'] ?? 'PG'));
        $fontSize = strlen((string) ($spec['label'] ?? 'PG')) > 2 ? '16' : '19';

        $bgStart = $spec['bg_start'];
        $bgEnd = $spec['bg_end'];
        $waveStart = $spec['wave_start'];
        $waveEnd = $spec['wave_end'];
        $bubble = $spec['bubble'];
        $ink = $spec['ink'];

        return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-labelledby="{$uid}-title">
  <title id="{$uid}-title">{$title}</title>
  <defs>
    <linearGradient id="{$uid}-bg" x1="10" y1="10" x2="54" y2="58" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="{$bgStart}"/>
      <stop offset="1" stop-color="{$bgEnd}"/>
    </linearGradient>
    <linearGradient id="{$uid}-wave" x1="12" y1="31" x2="52" y2="54" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="{$waveStart}" stop-opacity="0.92"/>
      <stop offset="1" stop-color="{$waveEnd}" stop-opacity="0.85"/>
    </linearGradient>
    <linearGradient id="{$uid}-shine" x1="16" y1="12" x2="16" y2="28" gradientUnits="userSpaceOnUse">
      <stop offset="0" stop-color="#ffffff" stop-opacity="0.84"/>
      <stop offset="1" stop-color="#ffffff" stop-opacity="0.08"/>
    </linearGradient>
  </defs>
  <rect x="6" y="6" width="52" height="52" rx="16" fill="url(#{$uid}-bg)"/>
  <rect x="10" y="10" width="44" height="18" rx="9" fill="url(#{$uid}-shine)"/>
  <path d="M12 44C18 35 24 38 31 34C38 30 45 31 52 41V54H12Z" fill="url(#{$uid}-wave)"/>
  <circle cx="47" cy="18" r="5" fill="{$bubble}" fill-opacity="0.96"/>
  <circle cx="19" cy="18" r="2.6" fill="#ffffff" fill-opacity="0.32"/>
  <text x="32" y="40.5" text-anchor="middle" font-family="Segoe UI, Arial, sans-serif" font-size="{$fontSize}" font-weight="700" letter-spacing="1.1" fill="{$ink}">{$label}</text>
  <rect x="20" y="45" width="24" height="4" rx="2" fill="{$ink}" fill-opacity="0.22"/>
</svg>
SVG;
    }

    private function slugFromRequest(Request $request): string
    {
        $path = trim($request->path(), '/');

        return match (true) {
            $path === '' => 'home',
            $request->routeIs('dashboard') => 'dashboard',
            $request->routeIs('blog.index') => 'blog',
            $request->is('blog/show-preserved') => 'blog-preserved',
            $request->routeIs('blogBt.index') => 'blog-bt',
            $request->routeIs('btdig.index') => 'btdig',
            $request->routeIs('gallery.index') => 'gallery',
            $request->routeIs('my-page') => 'my-page',
            $request->routeIs('product') => 'product',
            $request->routeIs('snake-game') => 'snake',
            $request->routeIs('encrypt.index') => 'encrypt',
            $request->routeIs('video.index') => 'videos',
            $request->routeIs('videos.index') => 'videos-management',
            $request->routeIs('videos.search') => 'videos-search',
            $request->routeIs('videos.player') => 'video-player',
            $request->routeIs('verify.success') => 'verify-success',
            $request->routeIs('verify.already') => 'already-verified',
            $request->routeIs('extract.index') => 'extract',
            $request->routeIs('ig.index') => 'ig-grabber',
            $request->routeIs('dialogues.markRead.page') || $request->routeIs('dialogues.markRead.mark') => 'dialogues-mark-read',
            $request->routeIs('dialogues.tokenStats') => 'dialogues-token-stats',
            $request->routeIs('videos.duplicates.index') => 'videos-duplicates',
            $request->routeIs('videos.external-duplicates.index') => 'videos-external-duplicates',
            $request->routeIs('face-identities.index') => 'face-identities',
            $path === 'ocr' => 'ocr',
            $path === 'upload' => 'upload',
            $path === 'product-import2' => 'product-import2',
            $path === 'url-viewer' => 'url-viewer',
            $path === 'tdl' => 'tdl',
            default => $this->normalizeSlug($path === '' ? 'home' : $path),
        };
    }

    private function labelFromSlug(string $slug): string
    {
        $parts = collect(explode('-', $slug))
            ->filter()
            ->values();

        if ($parts->isEmpty()) {
            return 'PG';
        }

        if ($parts->count() === 1) {
            return Str::upper(Str::substr($parts->first(), 0, 2));
        }

        return Str::upper(
            $parts
                ->take(2)
                ->map(fn (string $part) => Str::substr($part, 0, 1))
                ->implode('')
        );
    }

    private function paletteFor(string $slug): string
    {
        $keys = array_keys(self::PALETTES);
        $index = ((int) sprintf('%u', crc32($slug))) % count($keys);

        return $keys[$index];
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

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
