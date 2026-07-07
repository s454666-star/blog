<?php

namespace App\Http\Controllers;

use App\Services\FolderVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class FolderVideoAppController extends Controller
{
    public function __construct(private readonly FolderVideoService $folderVideoService)
    {
    }

    public function index(): Response
    {
        return response()->view('folder-video-app.index', [
            'appConfig' => $this->folderVideoService->appConfig(),
        ]);
    }

    public function version(): JsonResponse
    {
        return response()->json([
            'data' => [
                'version' => $this->folderVideoService->appConfig()['version'],
                'checked_at' => now()->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function manifest(): JsonResponse
    {
        return response()->json([
            'name' => 'Folder Video',
            'short_name' => 'Folder Video',
            'start_url' => route('folder-video-app.index'),
            'scope' => url('/folder-video-app'),
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#090b0f',
            'theme_color' => '#090b0f',
            'icons' => [
                [
                    'src' => asset('folder-video-app/icon-192.png'),
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => asset('folder-video-app/icon-512.png'),
                    'sizes' => '512x512',
                    'type' => 'image/png',
                    'purpose' => 'any maskable',
                ],
            ],
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function serviceWorker(): Response
    {
        $version = $this->folderVideoService->appConfig()['version'];
        $shellUrl = route('folder-video-app.index');
        $manifestUrl = route('folder-video-app.manifest');
        $versionUrl = route('folder-video-app.version');
        $icon192 = asset('folder-video-app/icon-192.png');
        $icon512 = asset('folder-video-app/icon-512.png');

        $script = <<<JS
const CACHE_NAME = 'folder-video-app-{$version}';
const SHELL_ASSETS = [
  '{$shellUrl}',
  '{$manifestUrl}',
  '{$versionUrl}',
  '{$icon192}',
  '{$icon512}'
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)));
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter((key) => key.startsWith('folder-video-app-') && key !== CACHE_NAME).map((key) => caches.delete(key)));
    await self.clients.claim();
  })());
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  if (url.pathname.includes('/stream')) {
    return;
  }

  if (url.pathname.startsWith('/api/folder-videos')) {
    event.respondWith(fetch(event.request, {cache: 'no-store'}));
    return;
  }

  if (event.request.mode === 'navigate') {
    event.respondWith((async () => {
      try {
        const fresh = await fetch(event.request);
        const cache = await caches.open(CACHE_NAME);
        cache.put('{$shellUrl}', fresh.clone());
        return fresh;
      } catch (error) {
        const cached = await caches.match('{$shellUrl}');
        if (cached) {
          return cached;
        }
        throw error;
      }
    })());
    return;
  }

  event.respondWith(caches.match(event.request).then((cached) => cached || fetch(event.request)));
});
JS;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }
}
