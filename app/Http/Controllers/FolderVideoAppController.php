<?php

namespace App\Http\Controllers;

use App\Services\FolderVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
            'start_url' => route('folder-video-app.index', [], false),
            'scope' => '/folder-video-app',
            'display' => 'standalone',
            'orientation' => 'portrait',
            'background_color' => '#090b0f',
            'theme_color' => '#090b0f',
            'icons' => [
                [
                    'src' => '/folder-video-app/icon-192.png',
                    'sizes' => '192x192',
                    'type' => 'image/png',
                ],
                [
                    'src' => '/folder-video-app/icon-512.png',
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
        $shellUrl = route('folder-video-app.index', [], false);
        $manifestUrl = route('folder-video-app.manifest', [], false);
        $versionUrl = route('folder-video-app.version', [], false);
        $icon192 = '/folder-video-app/icon-192.png';
        $icon512 = '/folder-video-app/icon-512.png';

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

  if (url.pathname.includes('/stream') || url.pathname.includes('/preview')) {
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

    public function androidVersion(Request $request): JsonResponse
    {
        $apkPath = (string) config('folder_video.android_apk_path');
        $exists = is_file($apkPath);

        return response()->json([
            'data' => [
                'version_code' => (int) config('folder_video.android_apk_version_code'),
                'version_name' => (string) config('folder_video.android_apk_version_name'),
                'apk_url' => $this->publicUrl($request, route('folder-video-app.android-apk', [], false)),
                'sha256' => $exists ? hash_file('sha256', $apkPath) : null,
                'size_bytes' => $exists ? filesize($apkPath) : null,
                'checked_at' => now()->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function androidApk(): BinaryFileResponse|JsonResponse
    {
        $apkPath = (string) config('folder_video.android_apk_path');

        if (! is_file($apkPath)) {
            return response()->json([
                'message' => 'APK file is not available.',
            ], 404)->header('Cache-Control', 'no-store, max-age=0');
        }

        return response()->download($apkPath, 'folder-video-app.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    public function tvAndroidVersion(Request $request): JsonResponse
    {
        $apkPath = (string) config('folder_video.tv_android_apk_path');
        $exists = is_file($apkPath);

        return response()->json(['data' => [
            'version_code' => (int) config('folder_video.tv_android_apk_version_code'),
            'version_name' => (string) config('folder_video.tv_android_apk_version_name'),
            'apk_url' => $this->publicUrl($request, route('folder-video-app.tv-android-apk', [], false)),
            'sha256' => $exists ? hash_file('sha256', $apkPath) : null,
            'size_bytes' => $exists ? filesize($apkPath) : null,
            'checked_at' => now()->toIso8601String(),
        ]])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function tvAndroidApk(): BinaryFileResponse|JsonResponse
    {
        $apkPath = (string) config('folder_video.tv_android_apk_path');
        if (! is_file($apkPath)) {
            return response()->json(['message' => 'TV APK file is not available.'], 404)
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        return response()->download($apkPath, 'folder-video-tv.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
            'Cache-Control' => 'no-store, max-age=0',
        ]);
    }

    private function publicUrl(Request $request, string $path): string
    {
        $host = $request->headers->get('x-forwarded-host') ?: $request->getHttpHost();
        $scheme = $request->headers->get('x-forwarded-proto') ?: $request->getScheme();
        $port = $request->headers->get('x-forwarded-port');

        if ($port !== null && ctype_digit((string) $port) && ! str_contains($host, ':')) {
            $isDefaultPort = ($scheme === 'http' && $port === '80') || ($scheme === 'https' && $port === '443');
            if (! $isDefaultPort) {
                $host .= ':'.$port;
            }
        }

        return rtrim($scheme.'://'.$host, '/').'/'.ltrim($path, '/');
    }
}
