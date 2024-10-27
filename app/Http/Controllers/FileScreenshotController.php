<?php

    namespace App\Http\Controllers;

    use App\Models\FileScreenshot;
    use Illuminate\Http\Request;

    class FileScreenshotController extends Controller
    {
        // 查詢所有截圖資料
        public function index(Request $request)
        {
            // 獲取分頁參數
            $perPage = $request->input('perPage', 20);
            $page    = $request->input('page', 1);
            $from    = ($page - 1) * $perPage;
            $to      = $page * $perPage - 1;

            // 獲取排序參數
            $sort = $request->input('sort', ['id', 'asc']);
            if (is_string($sort)) {
                $sort = json_decode($sort, true);
            }
            $sortField     = $sort[0] ?? 'id';
            $sortDirection = strtolower($sort[1] ?? 'asc');

            // 初始化查詢
            $query = FileScreenshot::query();

            // 處理過濾參數
            // 如果前端使用 'filter' 陣列，則保留以下代碼
            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where(function ($subQuery) use ($q) {
                        $subQuery->where('file_name', 'like', "%{$q}%")
                            ->orWhere('notes', 'like', "%{$q}%");
                    });
                }
                if (isset($filters['type'])) {
                    $query->where('type', $filters['type']);
                }
            }

            // 新增：處理頂層的 'type' 參數
            if ($request->has('type')) {
                $type = $request->input('type');
                $query->where('type', $type);
            }

            // 處理 'rating' 和 'is_view' 作為頂層參數
            if ($request->has('rating')) {
                $rating = floatval($request->input('rating'));
                $query->where('rating', $rating);
            }

            if ($request->has('is_view')) {
                $is_view = intval($request->input('is_view'));
                $query->where('is_view', $is_view);
            }

            // 計算總數
            $total = $query->count();

            // 獲取分頁數據
            $fileScreenshots = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($perPage)
                ->get()
                ->map(function ($fileScreenshot) {
                    $screenshots                 = explode(',', $fileScreenshot->screenshot_paths);
                    $fileScreenshot->cover_image = $fileScreenshot->cover_image ?? ($screenshots[5] ?? null);
                    return $fileScreenshot;
                });

            // 返回 JSON 響應
            return response()->json([
                'data'  => $fileScreenshots,
                'total' => $total
            ], 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }

        public function updateCoverImage(Request $request, $id)
        {
            $fileScreenshot = FileScreenshot::find($id);
            if (!$fileScreenshot) {
                return response()->json(['message' => 'File screenshot not found'], 404);
            }

            $validated = $request->validate([
                'cover_image' => 'required|string',
            ]);

            $fileScreenshot->update(['cover_image' => $validated['cover_image']]);

            return response()->json(['cover_image' => $validated['cover_image']], 200);
        }

        // 查詢單筆截圖資料
        public function show($id)
        {
            $fileScreenshot = FileScreenshot::find($id);
            if (!$fileScreenshot) {
                return response()->json(['message' => 'File screenshot not found'], 404);
            }
            return response()->json($fileScreenshot, 200);
        }

        // 新增截圖資料
        public function store(Request $request)
        {
            $validated = $request->validate([
                'file_name'        => 'required|string',
                'file_path'        => 'required|string',
                'screenshot_paths' => 'nullable|string',
                'rating'           => 'nullable|numeric|min:0|max:5',
                'notes'            => 'nullable|string',
                'type'             => 'nullable|string',
            ]);

            $fileScreenshot = FileScreenshot::create($validated);

            return response()->json($fileScreenshot, 201);
        }

        // 更新截圖資料
        public function update(Request $request, $id)
        {
            $fileScreenshot = FileScreenshot::find($id);
            if (!$fileScreenshot) {
                return response()->json(['message' => 'File screenshot not found'], 404);
            }

            $validated = $request->validate([
                'file_name'        => 'nullable|string',
                'file_path'        => 'nullable|string',
                'screenshot_paths' => 'nullable|string',
                'rating'           => 'nullable|numeric|min:0|max:5',
                'notes'            => 'nullable|string',
                'type'             => 'nullable|string',
            ]);

            $fileScreenshot->update($validated);

            return response()->json($fileScreenshot, 200);
        }

        // 刪除截圖資料
        public function destroy($id)
        {
            $fileScreenshot = FileScreenshot::find($id);
            if (!$fileScreenshot) {
                return response()->json(['message' => 'File screenshot not found'], 404);
            }

            $fileScreenshot->delete();

            return response()->json(['message' => 'File screenshot deleted'], 200);
        }

        public function updateIsView(Request $request, $id)
        {
            $fileScreenshot = FileScreenshot::find($id);
            if (!$fileScreenshot) {
                return response()->json(['message' => 'File screenshot not found'], 404);
            }

            $validated = $request->validate([
                'is_view' => 'required|boolean',
            ]);

            $fileScreenshot->update(['is_view' => $validated['is_view']]);

            return response()->json(['is_view' => $validated['is_view']], 200);
        }

        public function updateRating(Request $request, $id)
        {
            $fileScreenshot = FileScreenshot::find($id);
            if (!$fileScreenshot) {
                return response()->json(['message' => 'File screenshot not found'], 404);
            }

            $validated = $request->validate([
                'rating' => 'required|numeric|min:0|max:10', // 根據您的要求，可設定為 10 分制
            ]);

            $fileScreenshot->update(['rating' => $validated['rating']]);

            return response()->json(['rating' => $validated['rating']], 200);
        }
    }
