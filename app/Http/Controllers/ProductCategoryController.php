<?php

    namespace App\Http\Controllers;

    use App\Models\ProductCategory;
    use Illuminate\Http\Request;

    class ProductCategoryController extends Controller
    {
        // 列出所有商品類別
        public function index(Request $request)
        {
            // 解析前端傳來的 `range` 參數，預設返回 0 到 49 筆
            $range = $request->input('range', [0, 49]);
            if (is_string($range)) {
                $range = json_decode($range, true);
            }
            $from = $range[0];                                                                                                                                                                                                                                                                                                                                          // 起始行
            $to   = $range[1];                                                                                                                                                                                                                                                                                                                                          // 結束行

            // 解析前端傳來的 `sort` 參數
            $sort = $request->input('sort', ['id', 'asc']);
            if (is_string($sort)) {
                $sort = json_decode($sort, true);
            }

            // 檢查是否解析成功並為陣列
            if (!is_array($sort) || count($sort) < 2) {
                return response()->json(['message' => 'Invalid sort parameter format.'], 400);
            }

            // 取得排序欄位與方向
            $sortField     = $sort[0];                                                                                                                                                                                                                                                                                                                                      // 排序欄位
            $sortDirection = strtolower($sort[1]);                                                                                                                                                                                                                                                                                                                      // 排序方向 ("asc" 或 "desc")

            // 檢查排序方向是否為 "asc" 或 "desc"
            if (!in_array($sortDirection, ['asc', 'desc'])) {
                return response()->json(['message' => 'Invalid sort direction. Must be "asc" or "desc".'], 400);
            }

            // 建立查詢，並應用篩選條件（如果有的話）
            $query = ProductCategory::query();

            // 解析過濾參數
            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where('category_name', 'like', "%{$q}%")
                        ->orWhere('description', 'like', "%{$q}%");
                }
                // 可添加更多的過濾條件
                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
            }

            // 計算總筆數（應用篩選條件後）
            $total = $query->count();

            // 查詢數據，並按照範圍和排序返回
            $categories = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($to - $from + 1)
                ->get();

            // 返回資料，並添加 X-Total-Count 和其他必要的標頭
            return response()->json($categories, 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }

        // 根據ID獲取特定商品類別
        public function show($id)
        {
            $category = ProductCategory::find($id);
            if (!$category) {
                return response()->json(['message' => 'Category not found'], 404);
            }
            return response()->json($category);
        }

        // 創建新商品類別
        public function store(Request $request)
        {
            $validatedData = $request->validate([
                                                    'category_name' => 'required|string|max:255',
                                                    'description'   => 'nullable|string',
                                                    'status'        => 'required|integer|in:0,1',
                                                ]);

            $category = ProductCategory::create($validatedData);
            return response()->json($category, 201);
        }

        // 更新商品類別
        public function update(Request $request, $id)
        {
            $category = ProductCategory::find($id);
            if (!$category) {
                return response()->json(['message' => 'Category not found'], 404);
            }

            $validatedData = $request->validate([
                                                    'category_name' => 'sometimes|required|string|max:255',
                                                    'description'   => 'nullable|string',
                                                    'status'        => 'required|integer|in:0,1',
                                                ]);

            $category->update($validatedData);
            return response()->json($category);
        }

        // 刪除商品類別
        public function destroy($id)
        {
            $category = ProductCategory::find($id);
            if (!$category) {
                return response()->json(['message' => 'Category not found'], 404);
            }

            $category->delete();
            return response()->json(['message' => 'Category deleted']);
        }
    }
