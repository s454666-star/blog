<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use App\Models\Album;

    class AlbumController extends Controller
    {
        /**
         * Display a listing of albums with pagination and optional filtering by actor.
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

            // 檢查是否有篩選條件 (依照演員篩選)
            $actorName = $request->input('actor');

            // 如果有傳入演員名稱，就進行篩選
            if ($actorName) {
                $query = Album::whereHas('actor', function ($query) use ($actorName) {
                    $query->where('actor_name', 'like', '%' . $actorName . '%');
                });
            } else {
                // 沒有篩選條件時，回傳所有相簿
                $query = Album::query();
            }

            // 總數量
            $total = $query->count();

            // 分頁結果
            $albums = $query->offset($offset)->limit($perPage)->get();

            // 計算範圍
            $from = $offset + 1;
            $to = $offset + $albums->count();

            // 回應資料與分頁資訊
            return response()->json($albums)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }

        public function show($id)
        {
            $album = Album::find($id);
            if (!$album) {
                return response()->json(['message' => 'Album not found'], 404);
            }
            return response()->json($album);
        }
    }
