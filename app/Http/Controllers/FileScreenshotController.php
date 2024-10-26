<?php

    namespace App\Http\Controllers;

    use App\Models\FileScreenshot;
    use Illuminate\Http\Request;

    class FileScreenshotController extends Controller
    {
        // 查詢所有截圖資料
        public function index(Request $request)
        {
            // 解析 range 參數，未提供則預設返回 20 筆資料
            $range = $request->input('range', [0, 19]);
            if (is_string($range)) {
                $range = json_decode($range, true);
            }
            $from = $range[0];
            $to   = $range[1];

            // 解析 sort 參數，未提供則預設為 id 升序
            $sort = $request->input('sort', ['id', 'asc']);
            if (is_string($sort)) {
                $sort = json_decode($sort, true);
            }
            $sortField     = $sort[0];
            $sortDirection = strtolower($sort[1] ?? 'asc');

            // 解析篩選條件
            $filters = $request->input('filter', []);
            $query   = FileScreenshot::query();

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

            // 總筆數
            $total = $query->count();

            // 查詢數據，應用排序、分頁，並抓取第一張截圖作為封面
            $fileScreenshots = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($to - $from + 1)
                ->get()
                ->map(function ($fileScreenshot) {
                    $screenshots                 = explode(',', $fileScreenshot->screenshot_paths);
                    $fileScreenshot->cover_image = $screenshots[0] ?? null; // 預設第一張為封面
                    return $fileScreenshot;
                });

            // 返回 JSON 資料
            return response()->json($fileScreenshots, 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
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
    }
