<?php

namespace App\Http\Controllers;

use App\Services\FolderPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FolderPhotoController extends Controller
{
    public function __construct(private readonly FolderPhotoService $folderPhotoService)
    {
    }

    public function random(Request $request): JsonResponse
    {
        $count = max(1, (int) $request->integer('count', 120));

        return response()->json([
            'data' => $this->folderPhotoService->randomPhotos($count),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function appConfig(): JsonResponse
    {
        return response()->json([
            'data' => $this->folderPhotoService->appConfig(),
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function show(string $id): BinaryFileResponse
    {
        $path = $this->folderPhotoService->resolvePhotoPath($id);

        return response()->file($path, [
            'Content-Type' => mime_content_type($path) ?: 'application/octet-stream',
            'Cache-Control' => 'public, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
