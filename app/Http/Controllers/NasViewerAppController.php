<?php

namespace App\Http\Controllers;

use App\Services\NasViewerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NasViewerAppController extends Controller
{
    public function __construct(private readonly NasViewerService $nasViewerService)
    {
    }

    public function index(): Response
    {
        return response()->view('nas-viewer-app.index', [
            'appConfig' => $this->nasViewerService->appConfig(),
        ]);
    }

    public function version(): JsonResponse
    {
        return response()->json([
            'data' => [
                'version' => $this->nasViewerService->appConfig()['version'],
                'checked_at' => now()->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function androidVersion(Request $request): JsonResponse
    {
        $apkPath = (string) config('nas_viewer.android_apk_path');
        $exists = is_file($apkPath);

        return response()->json([
            'data' => [
                'version_code' => (int) config('nas_viewer.android_apk_version_code'),
                'version_name' => (string) config('nas_viewer.android_apk_version_name'),
                'apk_url' => $this->publicUrl($request, route('nas-viewer-app.android-apk', [], false)),
                'sha256' => $exists ? hash_file('sha256', $apkPath) : null,
                'size_bytes' => $exists ? filesize($apkPath) : null,
                'checked_at' => now()->toIso8601String(),
            ],
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function androidApk(): BinaryFileResponse|JsonResponse
    {
        $apkPath = (string) config('nas_viewer.android_apk_path');
        if (! is_file($apkPath)) {
            return response()->json(['message' => 'APK file is not available.'], 404)
                ->header('Cache-Control', 'no-store, max-age=0');
        }

        return response()->download($apkPath, 'nas-viewer-app.apk', [
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
