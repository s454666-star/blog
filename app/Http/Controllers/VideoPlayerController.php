<?php

    namespace App\Http\Controllers;

    use App\Models\VideoMaster;
    use Illuminate\Http\Request;

    class VideoPlayerController extends Controller
    {
        /**
         * 依照 video_type 抓取 100 筆隨機影片，回傳 JSON。
         * 回傳格式： ["https://public.test/video/path1.mp4", "https://public.test/video/path2.mp4", ...]
         */
        public function getVideoPlayerList(Request $request)
        {
            // 從前端 query 參數拿到 video_type，預設為 3
            $videoType = $request->input('video_type', 3);

            // 隨機抓 100 筆該類型影片
            $videos = VideoMaster::where('video_type', $videoType)
                ->inRandomOrder()
                ->limit(100)
                ->get(['video_path']);

            // 取得本次請求的 domain + scheme，例如 https://public.test
            // 這樣就可以自動適應不同部署環境
            $serverUrl = $request->getSchemeAndHttpHost();
            // 如果您確定永遠只會是 https://public.test，也可以直接寫死 $serverUrl = 'https://public.test';

            if ($videos->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No videos found for this video_type.',
                ], 404);
            }

            // 把 DB 的 video_path 轉成完整的可播放連結
            // e.g. https://public.test/video/ + $video->video_path
            $videoUrls = $videos->map(function ($video) use ($serverUrl) {
                return $serverUrl . '/video/' . ltrim($video->video_path, '/');
            });

            return response()->json([
                'success' => true,
                'data' => $videoUrls,
            ]);
        }
    }
