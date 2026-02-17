<?php

    namespace App\Http\Controllers;

    use App\Models\VideoFaceScreenshot;
    use App\Models\VideoMaster;
    use App\Models\VideoScreenshot;
    use App\Models\VideoTs;
    use Illuminate\Http\Request;
    use Illuminate\Pagination\LengthAwarePaginator;
    use Illuminate\Support\Facades\Storage;
    use Illuminate\Support\Facades\File;
    use Illuminate\Support\Facades\DB;

    class VideosController extends Controller
    {
        public function index(Request $request)
        {
            /* ---------- 參數 ---------- */
            $videoType   = $request->input('video_type', '1');
            $missingOnly = $request->boolean('missing_only', false);       // 是否只列出尚未選主面
            $sortBy      = in_array($request->input('sort_by'), ['id','duration']) ? $request->input('sort_by') : 'duration';
            $sortDir     = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';
            $perPage     = 20;
            $focusId     = $request->input('focus_id');

            /* ---------- 建立基礎查詢 ---------- */
            $baseQuery = VideoMaster::where('video_type', $videoType);
            if ($missingOnly) {
                $baseQuery->whereDoesntHave('masterFaces');
            }

            $total    = (clone $baseQuery)->count();
            $lastPage = (int) ceil($total / $perPage);

            /* ---------- 最新一支（id 最大） ---------- */
            $latest   = (clone $baseQuery)->orderBy('id', 'desc')->first();
            $latestId = $latest?->id;
            $page     = 1;

            if ($focusId) {
                $video = (clone $baseQuery)->where('id', $focusId)->first();
                if ($video) {
                    // 使用與實際列表相同的排序規則計算位置
                    $position = $this->countPositionWithTiebreaker((clone $baseQuery), $sortBy, $sortDir, $video);
                    $page = (int) ceil($position / $perPage);
                }
            } elseif ($latest) {
                // 沒指定 focus 時，預設聚焦於「最新一支」
                $position = $this->countPositionWithTiebreaker((clone $baseQuery), $sortBy, $sortDir, $latest);
                $page = (int) ceil($position / $perPage);
            }

            /* ---------- 取主列表（穩定排序 + 精簡 eager load 欄位） ---------- */
            $videos = $this->applyOrdering((clone $baseQuery), $sortBy, $sortDir)
                ->with([
                    'screenshots' => function ($q) {
                        $q->select(['id', 'video_master_id', 'screenshot_path']);
                    },
                    'screenshots.faceScreenshots' => function ($q) {
                        $q->select(['id', 'video_screenshot_id', 'face_image_path', 'is_master']);
                    },
                ])
                ->paginate($perPage, ['*'], 'page', max($page, 1));

            $prevPage = $page > 1 ? $page - 1 : null;
            $nextPage = $page < $lastPage ? $page + 1 : null;

            /* ---------- 左欄主面人臉（改用 DB 排序，避免 PHP sortBy） ---------- */
            $masterFacesQuery = VideoFaceScreenshot::query()
                ->select('video_face_screenshots.*')
                ->join('video_screenshots', 'video_screenshots.id', '=', 'video_face_screenshots.video_screenshot_id')
                ->join('video_master', 'video_master.id', '=', 'video_screenshots.video_master_id')
                ->where('video_face_screenshots.is_master', 1)
                ->where('video_master.video_type', $videoType);

            if ($sortBy === 'duration') {
                $masterFacesQuery
                    ->orderBy('video_master.duration', $sortDir)
                    ->orderBy('video_master.id', $sortDir);
            } else {
                $masterFacesQuery->orderBy('video_master.id', $sortDir);
            }

            $masterFaces = $masterFacesQuery
                ->with('videoScreenshot.videoMaster')
                ->get();

            return view('video.index', compact(
                'videos', 'masterFaces',
                'prevPage', 'nextPage', 'lastPage',
                'videoType', 'sortBy', 'sortDir',
                'latestId', 'missingOnly',
                'focusId'
            ));
        }

        public function loadMore(Request $request)
        {
            $page        = (int) $request->input('page', 1);
            $videoType   = $request->input('video_type', '1');
            $missingOnly = $request->boolean('missing_only', false);
            $sortBy      = in_array($request->input('sort_by'), ['id','duration']) ? $request->input('sort_by') : 'duration';
            $sortDir     = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';

            $query = VideoMaster::where('video_type', $videoType);
            if ($missingOnly) {
                $query->whereDoesntHave('masterFaces');
            }

            // 套用穩定排序 + 精簡 eager load 欄位（減少 SQL/記憶體/HTML 生成成本）
            $videos = $this->applyOrdering($query, $sortBy, $sortDir)
                ->with([
                    'screenshots' => function ($q) {
                        $q->select(['id', 'video_master_id', 'screenshot_path']);
                    },
                    'screenshots.faceScreenshots' => function ($q) {
                        $q->select(['id', 'video_screenshot_id', 'face_image_path', 'is_master']);
                    },
                ])
                ->paginate(10, ['*'], 'page', $page);

            if ($videos->isEmpty()) {
                return response()->json(['success' => false], 204);
            }

            $html = view('video.partials.video_rows', compact('videos'))->render();

            return response()->json([
                'success'      => true,
                'data'         => $html,
                'next_page'    => $videos->currentPage() < $videos->lastPage() ? $videos->currentPage() + 1 : null,
                'prev_page'    => $videos->currentPage() > 1 ? $videos->currentPage() - 1 : null,
                'last_page'    => $videos->lastPage(),
                'current_page' => $videos->currentPage(),
            ]);
        }

        /**
         * 只允許 duration 或 id 排序
         */
        private function parseSortBy(string $sortBy): string
        {
            return in_array($sortBy, ['id','duration']) ? $sortBy : 'duration';
        }

        /**
         * 只允許 asc 或 desc
         */
        private function parseSortDir(string $dir): string
        {
            return strtolower($dir) === 'desc' ? 'desc' : 'asc';
        }

        public function findPage(Request $request)
        {
            $videoId     = $request->input('video_id');
            $videoType   = $request->input('video_type', '1');
            $missingOnly = $request->boolean('missing_only', false);
            $sortBy      = $this->parseSortBy($request->input('sort_by', 'duration'));
            $sortDir     = $this->parseSortDir($request->input('sort_dir', 'asc'));
            $perPage     = 10;

            /* ---- 目標影片 ---- */
            $video = VideoMaster::where('id', $videoId)
                ->where('video_type', $videoType)
                ->first();

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該影片。',
                ], 404);
            }

            /* ---- 建立基礎查詢（與列表一致） ---- */
            $baseQuery = VideoMaster::where('video_type', $videoType);
            if ($missingOnly) {
                $baseQuery->whereDoesntHave('masterFaces');
            }

            /* ---- 計算位置（與實際列表相同排序規則） ---- */
            $position = $this->countPositionWithTiebreaker((clone $baseQuery), $sortBy, $sortDir, $video);
            $page = (int) ceil($position / $perPage);

            return response()->json([
                'success' => true,
                'page'    => $page,
            ]);
        }

        public function store(Request $request)
        {
            $validated = $request->validate([
                'video_name' => 'required|string|max:255',
                'video_path' => 'required|string|max:500',
                'duration'   => 'required|numeric',
                'video_type' => 'required|in:1,2,3,4',
            ]);

            $duplicate = VideoMaster::where('video_path', $validated['video_path'])
                ->where('duration', $validated['duration'])
                ->exists();

            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => '影片已經匯入過。',
                ], 409);
            }

            $video = VideoMaster::create($validated);

            return response()->json([
                'success' => true,
                'data'    => $video,
            ], 201);
        }

        public function deleteSelected(Request $request)
        {
            $ids = $request->input('ids', []);

            if (empty($ids)) {
                return response()->json([
                    'success' => false,
                    'message' => '沒有選擇任何影片。',
                ], 400);
            }

            // 預設根目錄（可用 .env 覆寫）
            $videoRoot = rtrim(env('VIDEO_SOURCE_ROOT', 'D:/video'), '/\\');
            $m3u8Root  = rtrim(env('M3U8_TARGET_ROOT', 'Z:/m3u8'), '/\\');

            $videos = VideoMaster::whereIn('id', $ids)->with('screenshots.faceScreenshots')->get();

            foreach ($videos as $video) {
                DB::beginTransaction();
                try {
                    // 1) 刪除原影片與其截圖、人臉檔案
                    $this->deleteVideoPhysicalFiles($video, $videoRoot);

                    // 2) 若有 m3u8，刪除 videos_ts 與 Z:\m3u8 的檔案與資料夾
                    $this->deleteM3u8AssetsAndRows($video, $m3u8Root);

                    // 3) 刪除 DB：screenshots、faceScreenshots 已設置 on delete cascade（或手動刪除保險）
                    foreach ($video->screenshots as $screenshot) {
                        foreach ($screenshot->faceScreenshots as $face) {
                            $face->delete();
                        }
                        $screenshot->delete();
                    }

                    // 4) 刪除 video_master
                    $video->delete();

                    DB::commit();
                } catch (\Throwable $e) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => '刪除影片時發生錯誤：' . $e->getMessage(),
                    ], 500);
                }

                // 5) 嘗試刪除空資料夾（原影片資料夾）
                $this->deleteVideoFolderIfExists($video, $videoRoot);
            }

            return response()->json([
                'success' => true,
                'message' => '選中的影片已成功刪除。',
            ]);
        }

        public function upload(Request $request)
        {
            $validated = $request->validate([
                'video_file' => 'required|mimes:mp4,mov,avi,wmv|max:204800',
                'video_type' => 'required|in:1,2,3,4',
            ]);

            if ($request->hasFile('video_file')) {
                $file       = $request->file('video_file');
                $videoName  = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $videoFolder= "{$videoName}";

                if (!Storage::disk('videos')->exists($videoFolder)) {
                    Storage::disk('videos')->makeDirectory($videoFolder, 0755, true);
                }

                $filename = time() . '_' . $file->getClientOriginalName();
                Storage::disk('videos')->putFileAs($videoFolder, $file, $filename);

                $duration = $this->getVideoDuration("D:/video/{$videoFolder}/{$filename}");

                $video = VideoMaster::create([
                    'video_name' => $videoName,
                    'video_path' => "{$videoFolder}/{$filename}",
                    'duration'   => $duration,
                    'video_type' => $validated['video_type'],
                ]);

                $screenshotFilename = "screenshot_1.jpg";
                $screenshotPath     = "{$videoFolder}/{$screenshotFilename}";
                Storage::disk('videos')->put($screenshotPath, '');

                $screenshot = VideoScreenshot::create([
                    'video_master_id' => $video->id,
                    'screenshot_path' => "{$videoFolder}/{$screenshotFilename}",
                ]);

                $video->screenshots = [$screenshot];

                return response()->json([
                    'success' => true,
                    'data'    => $video,
                ], 201);
            }

            return response()->json([
                'success' => false,
                'message' => '檔案上傳失敗。',
            ], 500);
        }

        public function deleteScreenshot(Request $request)
        {
            $id   = $request->input('id');
            $type = $request->input('type');

            if ($type === 'screenshot') {
                $screenshot = VideoScreenshot::find($id);
                if (!$screenshot) {
                    return response()->json([
                        'success' => false,
                        'message' => '截圖不存在。',
                    ], 404);
                }

                $screenshotFile = "D:/video/" . $screenshot->screenshot_path;
                if (File::exists($screenshotFile)) {
                    File::delete($screenshotFile);
                }

                foreach ($screenshot->faceScreenshots as $face) {
                    $faceFile = "D:/video/" . $face->face_image_path;
                    if (File::exists($faceFile)) {
                        File::delete($faceFile);
                    }
                    $face->delete();
                }

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

                $faceFile = "D:/video/" . $face->face_image_path;
                if (File::exists($faceFile)) {
                    File::delete($faceFile);
                }

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

        public function uploadFaceScreenshot(Request $request)
        {
            $validated = $request->validate([
                'face_images.*' => 'required|image|mimes:jpeg,png,jpg,gif|max:5120',
                'video_id'      => 'required|exists:video_master,id',
            ]);

            if ($request->hasFile('face_images')) {
                $files  = $request->file('face_images');
                $videoId= $request->input('video_id');
                $video  = VideoMaster::find($videoId);

                if (!$video) {
                    return response()->json([
                        'success' => false,
                        'message' => '對應的影片不存在。',
                    ], 404);
                }

                $videoFolder   = pathinfo($video->video_path, PATHINFO_DIRNAME);
                $videoBaseName = pathinfo($video->video_path, PATHINFO_FILENAME);

                $firstScreenshot = $video->screenshots()->first();
                if (!$firstScreenshot) {
                    return response()->json([
                        'success' => false,
                        'message' => '該影片沒有截圖，無法上傳人臉截圖。',
                    ], 400);
                }

                $uploadedFaces = [];

                foreach ($files as $file) {
                    $faceCount = VideoFaceScreenshot::where('video_screenshot_id', $firstScreenshot->id)->count() + 1;
                    $filename  = "{$videoBaseName}_face_{$faceCount}." . $file->getClientOriginalExtension();
                    $storagePath = $videoFolder ? "{$videoFolder}/{$filename}" : $filename;

                    Storage::disk('videos')->putFileAs($videoFolder, $file, $filename);

                    $facePath = ltrim(str_replace('\\', '/', $storagePath), '/');

                    $face = VideoFaceScreenshot::create([
                        'video_screenshot_id' => $firstScreenshot->id,
                        'face_image_path'     => $facePath,
                        'is_master'           => 0,
                    ]);

                    $uploadedFaces[] = $face;
                }

                return response()->json([
                    'success' => true,
                    'data'    => $uploadedFaces,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => '沒有檔案上傳。',
            ], 400);
        }

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
                $videoMasterId = $face->videoScreenshot->videoMaster->id;

                VideoFaceScreenshot::whereHas('videoScreenshot.videoMaster', function($query) use ($videoMasterId) {
                    $query->where('id', $videoMasterId);
                })
                    ->update(['is_master' => 0]);

                $face->is_master = 1;
                $face->save();
            });

            $updatedFace = VideoFaceScreenshot::with(['videoScreenshot.videoMaster'])->find($faceId);

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

        public function loadMasterFaces(Request $request): \Illuminate\Http\JsonResponse
        {
            $videoType = $request->input('video_type', '1');

            // 讓左欄排序與右欄一致
            $sortBy  = in_array($request->input('sort_by'), ['id', 'duration']) ? $request->input('sort_by') : 'duration';
            $sortDir = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';

            $masterFaces = VideoFaceScreenshot::where('is_master', 1)
                ->whereHas('videoScreenshot.videoMaster', function($query) use ($videoType) {
                    $query->where('video_type', $videoType);
                })
                ->with('videoScreenshot.videoMaster')
                ->get()
                ->sortBy(function($face) use ($sortBy) {
                    return $sortBy === 'duration'
                        ? (float) ($face->videoScreenshot->videoMaster->duration ?? 0)
                        : (int)   ($face->videoScreenshot->videoMaster->id ?? 0);
                }, SORT_NUMERIC, $sortDir === 'desc');

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
                'data'    => $masterFaces->toArray()
            ]);
        }

        private function getVideoDuration($filePath)
        {
            $cmd = "ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 \"{$filePath}\"";
            $output = shell_exec($cmd);
            return round(floatval($output), 2);
        }

        public function getRandomVideos(): \Illuminate\Http\JsonResponse
        {
            $serverUrl = "http://10.0.0.19:8000";

            $videos = VideoMaster::inRandomOrder()
                ->limit(100)
                ->pluck('video_path')
                ->map(function ($path) use ($serverUrl) {
                    return "{$serverUrl}/video/{$path}";
                });

            return response()->json([
                'success' => true,
                'data' => $videos,
            ]);
        }

        public function getTest(): \Illuminate\Http\JsonResponse
        {
            $serverUrl = "http://10.0.0.19:8000";

            $videos = VideoMaster::inRandomOrder()
                ->where('id','1260')
                ->limit(100)
                ->pluck('m3u8_path')
                ->map(function ($path) use ($serverUrl) {
                    return "{$serverUrl}/video/{$path}";
                });

            return response()->json([
                'success' => true,
                'data' => $videos,
            ]);
        }

        /**
         * Display the video player page.
         *
         * @param \Illuminate\Http\Request $request
         * @return \Illuminate\View\View
         */
        public function player(Request $request)
        {
            // Get video_type from query parameters, default to 3
            $videoType = $request->query('video_type', 3);
            return view('video.player', compact('videoType'));
        }

        /**
         * Fetch a random video based on video_type.
         *
         * @param \Illuminate\Http\Request $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function getRandomVideoType(Request $request)
        {
            // Get video_type from query parameters, default to 3
            $videoType = $request->query('video_type', 3);

            $video = VideoMaster::where('video_type', $videoType)
                ->inRandomOrder()
                ->first();

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => 'No videos found.',
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'video_path' => $video->video_path,
                    'video_name' => $video->video_name,
                ],
            ]);
        }

        /**
         * 處理影片搜尋請求
         *
         * @param  \Illuminate\Http\Request  $request
         * @return \Illuminate\View\View|\Illuminate\Http\JsonResponse
         */
        public function search(Request $request)
        {
            $keyword = $request->input('keyword');

            if ($keyword) {
                // 將關鍵字以逗號分割成陣列並去除前後空白
                $keywords = array_map('trim', explode(',', $keyword));

                // 根據每個關鍵字進行模糊搜尋
                $videos = VideoMaster::where(function ($query) use ($keywords) {
                    foreach ($keywords as $key) {
                        $query->orWhere('video_name', 'LIKE', "%{$key}%");
                    }
                })->paginate(20);
            } else {
                // 沒有關鍵字時返回空的分頁結果
                $videos = new LengthAwarePaginator([], 0, 20);
            }

            if ($request->ajax()) {
                // 如果是 AJAX 請求，返回只包含影片列表和分頁的部分視圖
                return view('partials.video_list', compact('videos'))->render();
            }

            // 如果不是 AJAX 請求，返回完整的搜尋頁面
            return view('search', compact('videos', 'keyword'));
        }

        /**
         * 刪除指定的影片及相關檔案和資料庫紀錄
         *
         * @param int $id 影片的ID
         * @return \Illuminate\Http\JsonResponse
         */
        public function destroy($id)
        {
            // 預設根目錄（可用 .env 覆寫）
            $videoRoot = rtrim(env('VIDEO_SOURCE_ROOT', 'D:/video'), '/\\');
            $m3u8Root  = rtrim(env('M3U8_TARGET_ROOT', 'Z:/m3u8'), '/\\');

            // 查找影片
            $video = VideoMaster::with('screenshots.faceScreenshots')->find($id);

            if (!$video) {
                return response()->json([
                    'success' => false,
                    'message' => '找不到該影片。',
                ], 404);
            }

            DB::beginTransaction();

            try {
                // 1) 刪除原影片與其截圖、人臉檔案
                $this->deleteVideoPhysicalFiles($video, $videoRoot);

                // 2) 若有 m3u8，刪除 videos_ts 與 Z:\m3u8 的檔案與資料夾
                $this->deleteM3u8AssetsAndRows($video, $m3u8Root);

                // 3) 刪除關聯資料（雙保險：有外鍵 on delete cascade 時可省，但這裡保留）
                foreach ($video->screenshots as $screenshot) {
                    foreach ($screenshot->faceScreenshots as $face) {
                        $face->delete();
                    }
                    $screenshot->delete();
                }

                // 4) 刪除影片主檔
                $video->delete();

                DB::commit();
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => '刪除影片時發生錯誤，請稍後再試。',
                ], 500);
            }

            // 5) 刪除空的影片資料夾（若存在）
            $this->deleteVideoFolderIfExists($video, $videoRoot);

            return response()->json([
                'success' => true,
                'message' => '影片已成功刪除。',
            ]);
        }

        /**
         * 套用穩定排序：當以 duration 排序時，補上次要鍵 id，確保同秒數順序固定。
         */
        private function applyOrdering($query, string $sortBy, string $sortDir)
        {
            if ($sortBy === 'duration') {
                return $query->orderBy('duration', $sortDir)
                    ->orderBy('id', $sortDir);
            }
            return $query->orderBy('id', $sortDir);
        }

        /**
         * 依目前排序規則計算「該影片的序位」（用於求所在頁碼）。
         * ASC  : (duration < D) 或 (duration = D 且 id <= ID)
         * DESC : (duration > D) 或 (duration = D 且 id >= ID)
         * 若 sort_by = id，則退化為單鍵比較。
         */
        private function countPositionWithTiebreaker($baseQuery, string $sortBy, string $sortDir, VideoMaster $video): int
        {
            if ($sortBy === 'id') {
                if ($sortDir === 'asc') {
                    return (clone $baseQuery)->where('id', '<=', $video->id)->count();
                }
                return (clone $baseQuery)->where('id', '>=', $video->id)->count();
            }

            // sortBy === 'duration'
            $D  = $video->duration;
            $ID = $video->id;

            if ($sortDir === 'asc') {
                return (clone $baseQuery)
                    ->where(function ($q) use ($D, $ID) {
                        $q->where('duration', '<', $D)
                            ->orWhere(function ($q2) use ($D, $ID) {
                                $q2->where('duration', '=', $D)
                                    ->where('id', '<=', $ID);
                            });
                    })
                    ->count();
            }

            // desc
            return (clone $baseQuery)
                ->where(function ($q) use ($D, $ID) {
                    $q->where('duration', '>', $D)
                        ->orWhere(function ($q2) use ($D, $ID) {
                            $q2->where('duration', '=', $D)
                                ->where('id', '>=', $ID);
                        });
                })
                ->count();
        }

        /* ==============================
         *  以下為本次新增／抽取的共用方法
         * ============================== */

        /**
         * 刪除影片本體與其關聯的截圖、人臉檔案（F:\video 之下）。
         */
        private function deleteVideoPhysicalFiles(VideoMaster $video, string $videoRoot): void
        {
            // 刪影片檔
            $videoFile = $this->joinPaths($videoRoot, $video->video_path);
            if (File::exists($videoFile)) {
                File::delete($videoFile);
            }

            // 刪截圖與人臉
            $screenshots = $video->relationLoaded('screenshots') ? $video->screenshots : $video->screenshots()->with('faceScreenshots')->get();
            foreach ($screenshots as $screenshot) {
                $screenshotFile = $this->joinPaths($videoRoot, $screenshot->screenshot_path);
                if (File::exists($screenshotFile)) {
                    File::delete($screenshotFile);
                }
                foreach ($screenshot->faceScreenshots as $face) {
                    $faceFile = $this->joinPaths($videoRoot, $face->face_image_path);
                    if (File::exists($faceFile)) {
                        File::delete($faceFile);
                    }
                }
            }
        }

        /**
         * 若該影片有 m3u8_path，則：
         * 1) 刪除 videos_ts 中對應資料（path like /m3u8/<folder>/%）
         * 2) 刪除 Z:\m3u8\<folder>\ 下的 m3u8 與 ts 檔案與資料夾
         */
        private function deleteM3u8AssetsAndRows(VideoMaster $video, string $m3u8Root): void
        {
            if (empty($video->m3u8_path)) {
                return;
            }

            // m3u8_path 樣式：/m3u8/<folder>/video.m3u8
            // 取 <folder>
            $folder = basename(dirname(str_replace('\\', '/', $video->m3u8_path)));

            if ($folder === '' || $folder === '/' || $folder === '.' || $folder === '..') {
                return;
            }

            // 1) 刪 DB：videos_ts.path like '/m3u8/<folder>/%'
            $prefix = '/m3u8/' . $folder . '/';
            VideoTs::where('path', 'like', $prefix . '%')->delete();

            // 2) 刪實體檔案：Z:\m3u8\<folder>
            $targetDir = $this->joinPaths($m3u8Root, $folder);
            if (File::exists($targetDir)) {
                File::deleteDirectory($targetDir);
            }
        }

        /**
         * 刪除影片的資料夾（若存在）。例如 video_path = 自拍_1/自拍.mp4 -> 刪除 F:\video\自拍_1
         */
        private function deleteVideoFolderIfExists(VideoMaster $video, string $videoRoot): void
        {
            $videoPath   = str_replace('\\', '/', (string) $video->video_path);
            $videoFolder = trim(pathinfo($videoPath, PATHINFO_DIRNAME), '/');
            if ($videoFolder === '' || $videoFolder === '.' || $videoFolder === '..') {
                return;
            }

            $folderPath = $this->joinPaths($videoRoot, $videoFolder);
            if (File::exists($folderPath)) {
                // 直接刪整個資料夾（不管內部是否仍有殘留）
                File::deleteDirectory($folderPath);
            }
        }

        /**
         * 安全組合 Windows / Linux 路徑
         */
        private function joinPaths(string ...$parts): string
        {
            $trimmed = array_map(function ($p) {
                return trim((string) $p, "/\\");
            }, $parts);

            $joined = implode(DIRECTORY_SEPARATOR, $trimmed);
            // 讓像 C: 這種磁碟標示不被 trim 影響
            $joined = str_replace([':/', ':\\'], ':/', $joined);
            return $joined;
        }
    }
