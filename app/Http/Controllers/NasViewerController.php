<?php

namespace App\Http\Controllers;

use App\Services\NasViewerService;
use App\Services\FolderVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class NasViewerController extends Controller
{
    public function __construct(
        private readonly NasViewerService $nasViewerService,
        private readonly FolderVideoService $folderVideoService,
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $directoryId = $request->query('directory');
        $directoryId = is_string($directoryId) && $directoryId !== '' ? $directoryId : null;
        $defaultLimit = (int) ($this->nasViewerService->appConfig()['page_limit'] ?? 300);
        $offset = max(0, (int) $request->integer('offset', 0));
        $limit = max(1, (int) $request->integer('limit', $defaultLimit));
        $page = $this->nasViewerService->listDirectory($directoryId, $offset, $limit);

        return response()->json([
            'data' => $page['entries'],
            'meta' => $page['meta'],
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function appConfig(): JsonResponse
    {
        return response()->json([
            'data' => $this->nasViewerService->appConfig(),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function text(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->nasViewerService->readText((string) $request->query('id', '')),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function stream(Request $request): BinaryFileResponse
    {
        $path = $this->nasViewerService->resolveFilePath((string) $request->query('id', ''));
        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'apk') {
            $response = response()->download($path, basename($path), [
                'Content-Type' => 'application/vnd.android.package-archive',
                'X-Content-Type-Options' => 'nosniff',
            ]);
            $response->setPrivate();
            $response->setMaxAge(600);

            return $response;
        }

        $response = response()->file($path, [
            'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPrivate();
        $response->setMaxAge(600);

        return $response;
    }

    public function queueHls(string $id): JsonResponse
    {
        $path = $this->nasViewerService->resolveFilePath($id);
        $data = $this->folderVideoService->queueExternalHls($path, $id);

        return response()->json(['data' => $data], $data['ready'] ? 200 : 202)
            ->header('Cache-Control', 'no-store, max-age=0');
    }
}
