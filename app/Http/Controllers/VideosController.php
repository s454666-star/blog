<?php

    namespace App\Http\Controllers;

    use App\Models\VideoFaceScreenshot;
    use App\Models\VideoMaster;
    use App\Models\VideoScreenshot;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\DB;

    class VideosController extends Controller
    {
        /**
         * 顯示影片列表的主頁面。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\View\View
         */
        public function index(Request $request)
        {
            $videoType = $request->input('video_type', '1');

            // 獲取影片總數
            $total = VideoMaster::where('video_type', $videoType)->count();
            $perPage = 10;
            $lastPage = ceil($total / $perPage);

            // 取得ID最大的影片
            $maxIdVideo = VideoMaster::where('video_type', $videoType)->orderBy('id', 'desc')->first();

            if ($maxIdVideo) {
                // 找出該影片所在的頁數
                $position = VideoMaster::where('video_type', $videoType)
                    ->where('duration', '<=', $maxIdVideo->duration)
                    ->orderBy('duration', 'asc')
                    ->count();

                $page = ceil($position / $perPage);
            } else {
                $page = 1;
            }

            // 初始載入10筆資料，按時長排序並根據影片類別篩選
            $videos = VideoMaster::with(['screenshots.faceScreenshots'])
                ->where('video_type', $videoType)
                ->orderBy('duration', 'asc')
                ->paginate($perPage, ['*'], 'page', $page);

            // 計算前一頁
            $prev_page = $page > 1 ? $page - 1 : null;
            // 計算下一頁
            $next_page = $page < $lastPage ? $page + 1 : null;

            $masterFaces = VideoFaceScreenshot::where('is_master', 1)
                ->whereHas('videoScreenshot.videoMaster', function($query) use ($videoType) {
                    $query->where('video_type', $videoType);
                })
                ->with('videoScreenshot.videoMaster')
                ->get()
                ->sortBy(function($face) {
                    return $face->videoScreenshot->videoMaster->duration;
                });

            return view('video.index', compact('videos', 'masterFaces', 'next_page', 'prev_page'));
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
            $videoType = $request->input('video_type', '1');

            $videos = VideoMaster::with(['screenshots.faceScreenshots'])
                ->where('video_type', $videoType)
                ->orderBy('duration', 'asc')
                ->paginate(10, ['*'], 'page', $page);

            if ($videos->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => '沒有更多資料了。',
                ], 204);
            }

            // 計算前一頁和下一頁
            $prev_page = $videos->currentPage() > 1 ? $videos->currentPage() - 1 : null;
            $next_page = $videos->currentPage() < $videos->lastPage() ? $videos->currentPage() + 1 : null;

            // 回傳HTML片段
            $html = view('video.partials.video_rows', compact('videos'))->render();

            return response()->json([
                'success' => true,
                'data' => $html,
                'next_page' => $next_page,
                'prev_page' => $prev_page,
            ]);
        }

        /**
         * 找出影片所在的頁數。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function findPage(Request $request)
        {
            $videoId = $request->input('video_id');
            $videoType = $request->input('video_type', '1');

            $video = VideoMaster::where('id', $videoId)
                ->where('video_type', $videoType)
                ->first();

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該影片。',
                ], 404);
            }

            // 計算該影片在排序後的位置
            $position = VideoMaster::where('video_type', $videoType)
                ->where('duration', '<=', $video->duration)
                ->orderBy('duration', 'asc')
                ->count();

            $perPage = 10;
            $page = ceil($position / $perPage);

            return response()->json([
                'success' => true,
                'page' => $page,
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
                'video_type' => 'required|in:1,2,3,4',
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
                $videoFile = "F:/video/" . $video->video_path; // Windows 路徑使用正斜杠或雙反斜杠
                if (File::exists($videoFile)) {
                    File::delete($videoFile);
                }

                // 刪除相關截圖檔案
                foreach ($video->screenshots as $screenshot) {
                    $screenshotFile = "F:/video/" . $screenshot->screenshot_path;
                    if (File::exists($screenshotFile)) {
                        File::delete($screenshotFile);
                    }

                    foreach ($screenshot->faceScreenshots as $face) {
                        $faceFile = "F:/video/" . $face->face_image_path;
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
                'video_type' => 'required|in:1,2,3,4',
            ]);

            if ($request->hasFile('video_file')) {
                $file = $request->file('video_file');
                $videoName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $videoFolder = "{$videoName}"; // 例如 '自拍'

                // 確保目錄存在
                if (!Storage::disk('videos')->exists($videoFolder)) {
                    Storage::disk('videos')->makeDirectory($videoFolder, 0755, true);
                }

                $filename = time() . '_' . $file->getClientOriginalName();
                Storage::disk('videos')->putFileAs($videoFolder, $file, $filename);

                // 取得影片時長
                $duration = $this->getVideoDuration("F:/video/{$videoFolder}/{$filename}");

                // 儲存到資料庫
                $video = VideoMaster::create([
                    'video_name' => $videoName,
                    'video_path' => "{$videoFolder}/{$filename}",
                    'duration' => $duration,
                    'video_type' => $validated['video_type'],
                ]);

                // 創建第一筆截圖
                $screenshotFilename = "screenshot_1.jpg"; // 假設的截圖檔名
                $screenshotPath = "{$videoFolder}/{$screenshotFilename}";

                // 假設截圖已生成並儲存至指定路徑
                // 這裡僅模擬生成一個空檔案
                Storage::disk('videos')->put($screenshotPath, '');

                $screenshot = VideoScreenshot::create([
                    'video_master_id' => $video->id,
                    'screenshot_path' => "{$videoFolder}/{$screenshotFilename}",
                ]);

                // 回傳相關資料
                $video->screenshots = [$screenshot];

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
         * 刪除截圖或人臉截圖。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function deleteScreenshot(Request $request)
        {
            $id = $request->input('id');
            $type = $request->input('type');

            if ($type === 'screenshot') {
                $screenshot = VideoScreenshot::find($id);
                if (!$screenshot) {
                    return response()->json([
                        'success' => false,
                        'message' => '截圖不存在。',
                    ], 404);
                }

                // 刪除截圖檔案
                $screenshotFile = "F:/video/" . $screenshot->screenshot_path;
                if (File::exists($screenshotFile)) {
                    File::delete($screenshotFile);
                }

                // 刪除相關人臉截圖檔案
                foreach ($screenshot->faceScreenshots as $face) {
                    $faceFile = "F:/video/" . $face->face_image_path;
                    if (File::exists($faceFile)) {
                        File::delete($faceFile);
                    }
                    $face->delete();
                }

                // 刪除截圖資料庫紀錄
                $screenshot->delete();

                return response()->json([
                    'success' => true,
                    'message' => '截圖已成功刪除。',
                ]);
            } elseif ($type === 'face-screenshot') {
                $face = VideoFaceScreenshot::find($id);
                if (!$face) {
                    return response()->json([
                        'success' => false,
                        'message' => '人臉截圖不存在。',
                    ], 404);
                }

                // 刪除人臉截圖檔案
                $faceFile = "F:/video/" . $face->face_image_path;
                if (File::exists($faceFile)) {
                    File::delete($faceFile);
                }

                // 刪除人臉截圖資料庫紀錄
                $face->delete();

                return response()->json([
                    'success' => true,
                    'message' => '人臉截圖已成功刪除。',
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => '無效的刪除類型。',
                ], 400);
            }
        }

        /**
         * 上傳人臉截圖。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function uploadFaceScreenshot(Request $request)
        {
            $validated = $request->validate([
                'face_images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120', // 最大5MB
                'video_id' => 'required|exists:video_master,id',
            ]);

            if ($request->hasFile('face_images')) {
                $files = $request->file('face_images');
                $videoId = $request->input('video_id');
                $video = VideoMaster::find($videoId);

                if (!$video) {
                    return response()->json([
                        'success' => false,
                        'message' => '對應的影片不存在。',
                    ], 404);
                }

                // 獲取影片的資料夾名稱和基礎名稱
                $videoFolder = pathinfo($video->video_path, PATHINFO_DIRNAME); // e.g., '自拍'
                $videoBaseName = pathinfo($video->video_path, PATHINFO_FILENAME); // e.g., '自拍'

                // 獲取第一筆截圖
                $firstScreenshot = $video->screenshots()->first();
                if (!$firstScreenshot) {
                    return response()->json([
                        'success' => false,
                        'message' => '該影片沒有截圖，無法上傳人臉截圖。',
                    ], 400);
                }

                $uploadedFaces = [];

                foreach ($files as $file) {
                    // 生成檔案名稱
                    $faceCount = VideoFaceScreenshot::where('video_screenshot_id', $firstScreenshot->id)->count() + 1;
                    $filename = "{$videoBaseName}_face_{$faceCount}." . $file->getClientOriginalExtension();

                    // 構建儲存路徑
                    $storagePath = $videoFolder ? "{$videoFolder}/{$filename}" : $filename;

                    // 移動檔案到正確的資料夾
                    Storage::disk('videos')->putFileAs($videoFolder, $file, $filename);

                    // 確保路徑使用正斜杠
                    $facePath = ltrim(str_replace('\\', '/', $storagePath), '/');

                    // 儲存到資料庫，設定 video_screenshot_id 為第一筆截圖
                    $face = VideoFaceScreenshot::create([
                        'video_screenshot_id' => $firstScreenshot->id,
                        'face_image_path' => $facePath,
                        'is_master' => 0,
                    ]);

                    $uploadedFaces[] = $face;
                }

                return response()->json([
                    'success' => true,
                    'data' => $uploadedFaces,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => '沒有檔案上傳。',
            ], 400);
        }

        /**
         * 設定主面人臉。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function setMasterFace(Request $request): \Illuminate\Http\JsonResponse
        {
            $faceId = $request->input('face_id');

            $face = VideoFaceScreenshot::find($faceId);
            if(!$face){
                return response()->json([
                    'success' => false,
                    'message' => '人臉截圖不存在。'
                ]);
            }

            DB::transaction(function() use ($face) {
                // 取得相關影片的 VideoMaster ID
                $videoMasterId = $face->videoScreenshot->videoMaster->id;

                // 將同影片的其他人臉設為非主面
                VideoFaceScreenshot::whereHas('videoScreenshot.videoMaster', function($query) use ($videoMasterId) {
                    $query->where('id', $videoMasterId);
                })
                    ->update(['is_master' => 0]);

                // 將選定的人臉設為主面
                $face->is_master = 1;
                $face->save();
            });

            // 取得更新後的 master face data
            $updatedFace = VideoFaceScreenshot::with(['videoScreenshot.videoMaster'])->find($faceId);

            // 取得圖片尺寸
            $imagePath = public_path($updatedFace->face_image_path);
            if (file_exists($imagePath)) {
                list($width, $height) = getimagesize($imagePath);
                $updatedFace->width = $width;
                $updatedFace->height = $height;
            } else {
                $updatedFace->width = 0;
                $updatedFace->height = 0;
            }

            return response()->json([
                'success' => true,
                'data' => $updatedFace->toArray()
            ]);
        }

        /**
         * 載入主面人臉。
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function loadMasterFaces(Request $request): \Illuminate\Http\JsonResponse
        {
            $videoType = $request->input('video_type', '1');

            $masterFaces = VideoFaceScreenshot::where('is_master', 1)
                ->whereHas('videoScreenshot.videoMaster', function($query) use ($videoType) {
                    $query->where('video_type', $videoType);
                })
                ->with('videoScreenshot.videoMaster')
                ->get()
                ->sortBy(function($face) {
                    return $face->videoScreenshot->videoMaster->duration;
                });

            // 取得圖片尺寸
            foreach ($masterFaces as $face) {
                $imagePath = public_path($face->face_image_path);
                if (file_exists($imagePath)) {
                    list($width, $height) = getimagesize($imagePath);
                    $face->width = $width;
                    $face->height = $height;
                } else {
                    $face->width = 0;
                    $face->height = 0;
                }
            }

            return response()->json([
                'success' => true,
                'data' => $masterFaces->toArray()
            ]);
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
