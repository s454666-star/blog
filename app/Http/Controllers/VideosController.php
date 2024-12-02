<?php

    namespace App\Http\Controllers;

    use App\Models\VideoMaster;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\File;

    class VideosController extends Controller
    {
        /**
         * 顯示影片列表的主頁面。
         *
         * @return \Illuminate\View\View
         */
        public function index()
        {
            // 初始載入300筆資料，按時長排序
            $videos = VideoMaster::with(['screenshots.faceScreenshots'])
                ->orderBy('duration', 'asc')
                ->paginate(300);

            return view('video.index', compact('videos'));
        }

        /**
         * 載入更多影片資料 (AJAX請求)。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function loadMore(Request $request)
        {
            $page = $request->input('page', 1);

            $videos = VideoMaster::with(['screenshots.faceScreenshots'])
                ->orderBy('duration', 'desc')
                ->paginate(300, ['*'], 'page', $page);

            if ($videos->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '沒有更多資料了。',
                ], 204);
            }

            // 回傳HTML片段
            $html = view('video.partials.video_rows', compact('videos'))->render();

            return response()->json([
                'success' => true,
                'data' => $html,
                'next_page' => $videos->currentPage() + 1,
            ]);
        }

        /**
         * 儲存新的影片資料。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function store(Request $request)
        {
            // 驗證輸入資料
            $validated = $request->validate([
                'video_name' => 'required|string|max:255',
                'video_path' => 'required|string|max:500',
                'duration' => 'required|numeric',
            ]);

            // 檢查是否有重複匯入
            $duplicate = VideoMaster::where('video_path', $validated['video_path'])
                ->where('duration', $validated['duration'])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => '影片已經匯入過。',
                ], 409);
            }

            // 創建新影片
            $video = VideoMaster::create($validated);

            return response()->json([
                'success' => true,
                'data' => $video,
            ], 201);
        }

        /**
         * 刪除選中的影片資料及檔案。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function deleteSelected(Request $request)
        {
            $ids = $request->input('ids', []);

            if (empty($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => '沒有選擇任何影片。',
                ], 400);
            }

            $videos = VideoMaster::whereIn('id', $ids)->get();

            foreach ($videos as $video) {
                // 刪除影片檔案
                $videoFile = 'F:/video/' . $video->video_path;
                if (File::exists($videoFile)) {
                    File::delete($videoFile);
                }

                // 刪除相關截圖檔案
                foreach ($video->screenshots as $screenshot) {
                    $screenshotFile = 'F:/video/' . $screenshot->screenshot_path;
                    if (File::exists($screenshotFile)) {
                        File::delete($screenshotFile);
                    }

                    foreach ($screenshot->faceScreenshots as $face) {
                        $faceFile = 'F:/video/' . $face->face_image_path;
                        if (File::exists($faceFile)) {
                            File::delete($faceFile);
                        }
                    }
                }

                // 刪除資料庫紀錄
                $video->delete();
            }

            return response()->json([
                'success' => true,
                'message' => '選中的影片已成功刪除。',
            ]);
        }

        /**
         * 上傳新的影片檔案。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function upload(Request $request)
        {
            $validated = $request->validate([
                'video_file' => 'required|mimes:mp4,mov,avi,wmv|max:204800', // 最大200MB
            ]);

            if ($request->hasFile('video_file')) {
                $file = $request->file('video_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $destinationPath = 'F:/video/';
                $file->move($destinationPath, $filename);

                // 取得影片時長
                $duration = $this->getVideoDuration($destinationPath . $filename);

                // 儲存到資料庫
                $video = VideoMaster::create([
                    'video_name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                    'video_path' => $filename,
                    'duration' => $duration,
                ]);

                return response()->json([
                    'success' => true,
                    'data' => $video,
                ], 201);
            }

            return response()->json([
                'success' => false,
                'message' => '檔案上傳失敗。',
            ], 500);
        }

        /**
         * 取得影片時長（秒）
         *
         * @param string $filePath
         * @return float
         */
        private function getVideoDuration($filePath)
        {
            // 使用FFmpeg或其他方法取得影片時長
            // 這裡假設使用FFmpeg並已安裝
            $cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$filePath}\"";
            $output = shell_exec($cmd);
            return round(floatval($output), 2);
        }
    }
