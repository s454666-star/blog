<?php

namespace App\Http\Controllers;

use App\Models\VideoFaceScreenshot;
use App\Models\VideoMaster;
use App\Models\VideoScreenshot;
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
        $videoType    = $request->input('video_type', '1');
        $missingOnly  = $request->boolean('missing_only', false);       // 新增：是否只列出尚未選主面
        $sortBy       = in_array($request->input('sort_by'), ['id','duration'])
            ? $request->input('sort_by') : 'duration';
        $sortDir      = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';
        $perPage      = 50;
        $focusId     = $request->input('focus_id');
        /* ---------- 建立基礎查詢 ---------- */
        $baseQuery = VideoMaster::where('video_type', $videoType);
        if ($missingOnly) {
            $baseQuery->whereDoesntHave('masterFaces');
        }

        $total    = $baseQuery->count();
        $lastPage = (int) ceil($total / $perPage);

        /* ---------- 最新一支（id 最大） ---------- */
        $latest   = (clone $baseQuery)->orderBy('id', 'desc')->first();
        $latestId = $latest?->id;
        $page     = 1;

        if ($focusId) {                                      // ★ 若使用者指定焦點
            $video = (clone $baseQuery)->where('id', $focusId)->first();

            if ($video) {
                switch ($sortBy) {
                    case 'id':
                        $position = ($sortDir === 'asc')
                            ? (clone $baseQuery)->where('id', '<=', $video->id)->count()
                            : (clone $baseQuery)->where('id', '>=', $video->id)->count();
                        break;

                    case 'duration':
                    default:
                        $position = ($sortDir === 'asc')
                            ? (clone $baseQuery)->where('duration', '<=', $video->duration)->count()
                            : (clone $baseQuery)->where('duration', '>=', $video->duration)->count();
                        break;
                }
                $page = (int) ceil($position / $perPage);
            }
        }
        else if ($latest) {
            switch ($sortBy) {

                case 'id':
                    // id desc ⇒ 第 1 頁；id asc ⇒ 最後一頁
                    $page = $sortDir === 'desc' ? 1 : $lastPage;
                    break;

                case 'duration':
                default:
                    if ($sortDir === 'asc') {
                        $position = (clone $baseQuery)
                            ->where('duration', '<=', $latest->duration)
                            ->count();
                    } else { // duration desc
                        $position = (clone $baseQuery)
                            ->where('duration', '>=', $latest->duration)
                            ->count();
                    }
                    $page = (int) ceil($position / $perPage);
                    break;
            }
        }

        /* ---------- 取主列表 ---------- */
        $videos = (clone $baseQuery)
            ->with('screenshots.faceScreenshots')
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage, ['*'], 'page', max($page, 1));

        $prevPage = $page > 1         ? $page - 1 : null;
        $nextPage = $page < $lastPage ? $page + 1 : null;

        /* ---------- 左欄主面人臉 ---------- */
        $masterFaces = VideoFaceScreenshot::where('is_master', 1)
            ->whereHas('videoScreenshot.videoMaster', fn ($q) =>
            $q->where('video_type', $videoType))
            ->with('videoScreenshot.videoMaster')
            ->get()
            ->sortBy(fn ($f) =>
            $sortBy === 'duration'
                ? (float) $f->videoScreenshot->videoMaster->duration
                : $f->videoScreenshot->videoMaster->id,
                SORT_NUMERIC,
                $sortDir === 'desc'
            );

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
        $missingOnly = $request->boolean('missing_only', false);        // 新增
        $sortBy      = in_array($request->input('sort_by'), ['id','duration'])
            ? $request->input('sort_by') : 'duration';
        $sortDir     = $request->input('sort_dir') === 'desc' ? 'desc' : 'asc';

        $query = VideoMaster::where('video_type', $videoType);
        if ($missingOnly) $query->whereDoesntHave('masterFaces');

        $videos = $query->with('screenshots.faceScreenshots')
            ->orderBy($sortBy, $sortDir)
            ->paginate(10, ['*'], 'page', $page);

        if ($videos->isEmpty()) {
            return response()->json(['success' => false], 204);
        }

        $html = view('video.partials.video_rows', compact('videos'))->render();

        return response()->json([
            'success'      => true,
            'data'         => $html,
            'next_page'    => $videos->currentPage() < $videos->lastPage()
                ? $videos->currentPage() + 1 : null,
            'prev_page'    => $videos->currentPage() > 1
                ? $videos->currentPage() - 1 : null,
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

        /* ---- 計算位置 ---- */
        switch ($sortBy) {
            case 'id':
                $position = ($sortDir === 'asc')
                    ? (clone $baseQuery)->where('id', '<=', $video->id)->count()
                    : (clone $baseQuery)->where('id', '>=', $video->id)->count();
                break;

            case 'duration':
            default:
                $position = ($sortDir === 'asc')
                    ? (clone $baseQuery)->where('duration', '<=', $video->duration)->count()
                    : (clone $baseQuery)->where('duration', '>=', $video->duration)->count();
                break;
        }

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
        // 查找影片
        $video = VideoMaster::find($id);

        if (!$video) {
            return response()->json([
                'success' => false,
                'message' => '找不到該影片。',
            ], 404);
        }

        // 取得影片資料夾名稱，例如 \自拍_1\自拍.mp4 取得 自拍_1
        $videoPath = $video->video_path;
        $videoFolder = pathinfo($videoPath, PATHINFO_DIRNAME);

        // 定義影片資料夾的完整路徑
        $folderPath = 'F:/video/' . $videoFolder;

        // 開始資料庫交易
        DB::beginTransaction();

        try {
            // 刪除相關的人臉截圖檔案及資料庫紀錄
            foreach ($video->screenshots as $screenshot) {
                foreach ($screenshot->faceScreenshots as $face) {
                    // 刪除人臉截圖檔案
                    $faceFile = "F:/video/" . $face->face_image_path;
                    if (File::exists($faceFile)) {
                        File::delete($faceFile);
                    }
                    // 刪除人臉截圖資料庫紀錄
                    $face->delete();
                }

                // 刪除截圖檔案
                $screenshotFile = "F:/video/" . $screenshot->screenshot_path;
                if (File::exists($screenshotFile)) {
                    File::delete($screenshotFile);
                }
                // 刪除截圖資料庫紀錄
                $screenshot->delete();
            }

            // 刪除影片資料庫紀錄
            $video->delete();

            // 刪除影片資料夾及其所有檔案
            if (File::exists($folderPath)) {
                File::deleteDirectory($folderPath);
            }

            // 提交交易
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => '影片已成功刪除。',
            ]);
        } catch (\Exception $e) {
            // 回滾交易
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => '刪除影片時發生錯誤，請稍後再試。',
            ], 500);
        }
    }
}
