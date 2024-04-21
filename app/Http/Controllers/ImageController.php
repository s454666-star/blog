<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ImageController extends Controller
{
    public function getImageBase64(): JsonResponse
    {
        $path = 'public/photo_2024-04-20_10-40-30.jpg';  // 圖片的存儲路徑
        if (!Storage::exists($path)) {
            return response()->json([ 'error' => 'File not found.' ], 404);
        }

        $image  = Storage::get($path);
        $base64 = base64_encode($image);

        return response()->json([ 'base64' => $base64 ]);
    }
}
