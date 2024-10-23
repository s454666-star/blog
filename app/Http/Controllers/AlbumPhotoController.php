<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use App\Models\AlbumPhoto;

    class AlbumPhotoController extends Controller
    {
        /**
         * Display a listing of album photos with pagination and optional album filtering.
         *
         * @param Request $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function index(Request $request)
        {
            // 檢查前端是否傳遞了 per_page，否則預設為 20
            $perPage = $request->has('per_page') ? $request->input('per_page') : 20;
            $page = $request->input('page', 1);
            $offset = ($page - 1) * $perPage;

            // 檢查是否有篩選條件 (依照相簿篩選)
            $albumId = $request->input('album_id');

            // 相片查詢
            if ($albumId) {
                $query = AlbumPhoto::where('album_id', $albumId);
            } else {
                // 沒有篩選條件時，回傳所有相片
                $query = AlbumPhoto::query();
            }

            // 總數量
            $total = $query->count();

            // 分頁結果
            $photos = $query->offset($offset)->limit($perPage)->get();

            // 計算範圍
            $from = $offset + 1;
            $to = $offset + $photos->count();

            // 回應資料與分頁資訊
            return response()->json($photos)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }
    }
