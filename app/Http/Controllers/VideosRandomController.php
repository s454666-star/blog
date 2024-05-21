<?php

namespace App\Http\Controllers;

use App\Models\VideoTs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideosRandomController
{
    public function index(Request $request): JsonResponse
    {
        // 隨機抓出40筆VideoTs資料
        $videos = VideoTs::query()->inRandomOrder()->limit(40)->get();

        return response()->json($videos);
    }
}
