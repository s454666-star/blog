<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use App\Models\Actor;

    class ActorController extends Controller
    {
        /**
         * Display a listing of actors with pagination.
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

            // 演員查詢
            $query = Actor::query();

            // 總數量
            $total = $query->count();

            // 分頁結果
            $actors = $query->offset($offset)->limit($perPage)->get();

            // 計算範圍
            $from = $offset + 1;
            $to = $offset + $actors->count();

            // 回應資料與分頁資訊
            return response()->json($actors)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }
    }
