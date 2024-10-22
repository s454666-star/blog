<?php

    namespace App\Http\Controllers;

    use App\Models\Product;
    use Illuminate\Http\Request;

    class ProductController extends Controller
    {
        // 根據ID獲取商品
        public function index(Request $request)
        {
            // 解析前端傳來的 `range` 參數，預設為[0, 49]
            $range = $request->input('range', [0, 49]);
            if (is_string($range)) {
                $range = json_decode($range, true);
            }
            $from = $range[0];
            $to = $range[1];

            // 解析前端傳來的 `sort` 參數，預設為 ['id', 'asc']
            $sort = $request->input('sort', ['id', 'asc']);
            if (is_string($sort)) {
                $sort = json_decode($sort, true);
            }

            // 檢查排序欄位和方向
            $sortField = $sort[0];
            $sortDirection = isset($sort[1]) ? strtolower($sort[1]) : 'asc';
            if (!in_array($sortDirection, ['asc', 'desc'])) {
                return response()->json(['message' => 'Invalid sort direction. Must be "asc" or "desc".'], 400);
            }

            // 建立查詢並應用過濾條件
            $query = Product::query();

            // 解析過濾參數
            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where(function ($subQuery) use ($q) {
                        $subQuery->where('product_name', 'like', "%{$q}%")
                            ->orWhere('description', 'like', "%{$q}%");
                    });
                }

                // 其他過濾條件
                if (isset($filters['category_id'])) {
                    $query->where('category_id', $filters['category_id']);
                }

                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
            }

            // 計算總筆數
            $total = $query->count();

            // 查詢數據並應用分頁和排序
            $products = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($to - $from + 1)
                ->get();

            // 返回資料並添加 X-Total-Count 和 Content-Range 標頭
            return response()->json($products, 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }


        // 創建新商品
        public function store(Request $request)
        {
            // 驗證輸入數據
            $validatedData = $request->validate([
                                                    'category_id' => 'required|integer',
                                                    'product_name' => 'required|string|max:255',
                                                    'price' => 'required|numeric',
                                                    'stock_quantity' => 'required|integer',
                                                    'status' => 'required|in:available,out_of_stock,discontinued',
                                                    'image_base64' => 'nullable|string', // 驗證 Base64 圖片
                                                ]);

            // 創建商品數據
            $product = Product::create($validatedData);

            return response()->json($product, 201);
        }

        // 根據ID顯示單一商品
        public function show($id)
        {
            $product = Product::find($id);

            if (!$product) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            return response()->json($product);
        }


        // 更新商品
        public function update(Request $request, $id)
        {
            $product = Product::find($id);

            if (!$product) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            // 驗證輸入數據
            $validatedData = $request->validate([
                                                    'category_id' => 'integer',
                                                    'product_name' => 'string|max:255',
                                                    'price' => 'numeric',
                                                    'stock_quantity' => 'integer',
                                                    'status' => 'in:available,out_of_stock,discontinued',
                                                    'image_base64' => 'nullable|string', // 驗證 Base64 圖片
                                                ]);

            // 更新商品數據
            $product->update($validatedData);

            return response()->json($product);
        }

        // 刪除商品
        public function destroy($id)
        {
            $product = Product::find($id);

            if (!$product) {
                return response()->json(['message' => 'Product not found'], 404);
            }

            $product->delete();

            return response()->json(['message' => 'Product deleted successfully']);
        }
    }
