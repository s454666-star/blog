<?php

    namespace App\Http\Controllers;

    use App\Models\VideoMaster;
    use Illuminate\Http\JsonResponse;

    class RandomVideoController extends Controller
    {
        /**
         * 隨機回傳一部影片的 URL
         *
         * @return JsonResponse
         */
        public function index(): JsonResponse
        {
            $video = VideoMaster::where('video_type', 1)
                ->inRandomOrder()
                ->first();

            $video = VideoMaster::where('id', 3733)
                ->inRandomOrder()
                ->first();

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => '沒有找到任何影片'
                ], 404);
            }

            // 加上環境變數的基底 URL
            $baseUrl = rtrim(env('VIDEO_BASE_URL'), '/');

            // 轉換路徑中的反斜線 -> 正斜線，並清理多餘的斜線
            $formattedPath = str_replace('\\', '/', $video->video_path);
            $formattedPath = preg_replace('#/+#', '/', $formattedPath);

            // 組合完整 URL
            $videoUrl = $baseUrl . '/' . ltrim($formattedPath, '/');

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $video->id,
                    'name' => $video->video_name,
                    'url' => $videoUrl,
                    'duration' => $video->duration,
                    'type' => $video->video_type,
                ]
            ]);
        }
    }
