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
        public function index(Request $request)
        {
            $videoType = $request->input('video_type', '1');

            $total = VideoMaster::where('video_type', $videoType)->count();
            $perPage = 10;
            $lastPage = ceil($total / $perPage);

            $maxIdVideo = VideoMaster::where('video_type', $videoType)->orderBy('id', 'desc')->first();

            if ($maxIdVideo) {
                $position = VideoMaster::where('video_type', $videoType)
                    ->where('duration', '<=', $maxIdVideo->duration)
                    ->orderBy('duration', 'asc')
                    ->count();

                $page = ceil($position / $perPage);
            } else {
                $page = 1;
            }

            $videos = VideoMaster::with(['screenshots.faceScreenshots'])
                ->where('video_type', $videoType)
                ->orderBy('duration', 'asc')
                ->paginate($perPage, ['*'], 'page', $page);

            $prev_page = $page > 1 ? $page - 1 : null;
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

            // 將 lastPage 傳到前端
            return view('video.index', compact('videos', 'masterFaces', 'next_page', 'prev_page', 'lastPage'));
        }

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

            $prev_page = $videos->currentPage() > 1 ? $videos->currentPage() - 1 : null;
            $next_page = $videos->currentPage() < $videos->lastPage() ? $videos->currentPage() + 1 : null;

            $html = view('video.partials.video_rows', compact('videos'))->render();

            return response()->json([
                'success' => true,
                'data' => $html,
                'next_page' => $next_page,
                'prev_page' => $prev_page,
                'last_page' => $videos->lastPage(),
                'current_page' => $videos->currentPage()
            ]);
        }

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

        public function store(Request $request)
        {
            $validated = $request->validate([
                'video_name' => 'required|string|max:255',
                'video_path' => 'required|string|max:500',
                'duration' => 'required|numeric',
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
                'data' => $video,
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

            $videos = VideoMaster::whereIn('id', $ids)->get();

            foreach ($videos as $video) {
                $videoFile = "F:/video/" . $video->video_path;
                if (File::exists($videoFile)) {
                    File::delete($videoFile);
                }

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

                $video->delete();
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
                $file = $request->file('video_file');
                $videoName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
                $videoFolder = "{$videoName}";

                if (!Storage::disk('videos')->exists($videoFolder)) {
                    Storage::disk('videos')->makeDirectory($videoFolder, 0755, true);
                }

                $filename = time() . '_' . $file->getClientOriginalName();
                Storage::disk('videos')->putFileAs($videoFolder, $file, $filename);

                $duration = $this->getVideoDuration("F:/video/{$videoFolder}/{$filename}");

                $video = VideoMaster::create([
                    'video_name' => $videoName,
                    'video_path' => "{$videoFolder}/{$filename}",
                    'duration' => $duration,
                    'video_type' => $validated['video_type'],
                ]);

                $screenshotFilename = "screenshot_1.jpg";
                $screenshotPath = "{$videoFolder}/{$screenshotFilename}";
                Storage::disk('videos')->put($screenshotPath, '');

                $screenshot = VideoScreenshot::create([
                    'video_master_id' => $video->id,
                    'screenshot_path' => "{$videoFolder}/{$screenshotFilename}",
                ]);

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

                $screenshotFile = "F:/video/" . $screenshot->screenshot_path;
                if (File::exists($screenshotFile)) {
                    File::delete($screenshotFile);
                }

                foreach ($screenshot->faceScreenshots as $face) {
                    $faceFile = "F:/video/" . $face->face_image_path;
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

                $faceFile = "F:/video/" . $face->face_image_path;
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

                $videoFolder = pathinfo($video->video_path, PATHINFO_DIRNAME);
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
                    $filename = "{$videoBaseName}_face_{$faceCount}." . $file->getClientOriginalExtension();
                    $storagePath = $videoFolder ? "{$videoFolder}/{$filename}" : $filename;

                    Storage::disk('videos')->putFileAs($videoFolder, $file, $filename);

                    $facePath = ltrim(str_replace('\\', '/', $storagePath), '/');

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

            $masterFaces = VideoFaceScreenshot::where('is_master', 1)
                ->whereHas('videoScreenshot.videoMaster', function($query) use ($videoType) {
                    $query->where('video_type', $videoType);
                })
                ->with('videoScreenshot.videoMaster')
                ->get()
                ->sortBy(function($face) {
                    return $face->videoScreenshot->videoMaster->duration;
                });

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
         * @return \Illuminate\View\View
         */
        public function player()
        {
            return view('video.player');
        }

        /**
         * Fetch a random video with video_type = 3.
         *
         * @param \Illuminate\Http\Request $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function getRandomVideoType3(Request $request)
        {
            $video = VideoMaster::where('video_type', 3)
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
    }
