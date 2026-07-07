<?php

namespace App\Http\Controllers;

use App\Services\FolderVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FolderVideoController extends Controller
{
    public function __construct(private readonly FolderVideoService $folderVideoService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $defaultLimit = (int) ($this->folderVideoService->appConfig()['page_limit'] ?? 36);
        $limit = max(1, min((int) $request->integer('limit', $defaultLimit), 100));
        $afterDuration = $request->query('after_duration');
        $afterFilename = $request->query('after_filename');
        $afterDurationValue = is_numeric($afterDuration) ? (float) $afterDuration : null;
        $afterFilenameValue = is_string($afterFilename) && $afterFilename !== '' ? $afterFilename : null;
        $offset = $request->has('offset') ? max(0, (int) $request->integer('offset', 0)) : null;
        $page = $this->folderVideoService->listVideosPage($limit, $afterDurationValue, $afterFilenameValue, $offset);
        $videos = $page['videos'];
        $lastVideo = $videos->last();

        return response()->json([
            'data' => $videos,
            'meta' => [
                'next_after_duration' => $this->folderVideoService->cursorDuration($lastVideo['duration_seconds'] ?? null),
                'next_after_filename' => $lastVideo['filename'] ?? null,
                'has_more' => $page['has_more'],
                'next_offset' => $page['next_offset'] ?? null,
            ],
        ]);
    }

    public function appConfig(): JsonResponse
    {
        return response()->json([
            'data' => $this->folderVideoService->appConfig(),
        ]);
    }

    public function stream(string $id): BinaryFileResponse
    {
        $path = $this->folderVideoService->resolveVideoPath($id);

        return response()->file($path, [
            'Content-Type' => mime_content_type($path) ?: 'video/mp4',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function like(string $id): JsonResponse
    {
        $result = $this->folderVideoService->moveToGood($id);

        return response()->json([
            'message' => 'Video moved to good folder.',
            'data' => $result,
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $result = $this->folderVideoService->delete($id);

        return response()->json([
            'message' => 'Video deleted.',
            'data' => $result,
        ]);
    }
}
