<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use App\Models\Album;

    class AlbumController extends Controller
    {
        /**
         * Display a listing of albums with pagination and optional filtering by actor and deleted status.
         *
         * @param Request $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function index(Request $request)
        {
            // 檢查前端是否傳遞了 per_page，否則預設為 20
            $perPage = $request->has('per_page') ? (int) $request->input('per_page') : 20;
            $page = $request->input('page', 1);
            $offset = ($page - 1) * $perPage;

            // 檢查是否有篩選條件 (依照演員篩選)
            $actorId = $request->input('actor'); // 'actor' 參數代表演員 ID

            // 檢查是否有傳入 deleted 篩選條件，預設為 0
            $deleted = $request->input('deleted', 0);

            // 建立查詢對象
            $query = Album::where('deleted', $deleted);

            // 如果有傳入演員 ID，就進行篩選
            if ($actorId) {
                $query->where('actor_id', $actorId);
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

        /**
         * Update the deleted status of multiple albums.
         *
         * @param Request $request
         * @return \Illuminate\Http\JsonResponse
         */
        public function updateDeleted(Request $request)
        {
            // 從請求中取得要更新的相簿 ID 和 deleted 狀態
            $albumIds = $request->input('album_ids', []); // 相簿的 ID 列表
            $deleted = $request->input('deleted', 0);     // 要更新的 deleted 狀態 (預設為 0)

            // 檢查是否有提供 ID 列表
            if (empty($albumIds)) {
                return response()->json(['message' => 'No album IDs provided'], 400);
            }

            // 更新指定的相簿 deleted 狀態
            Album::whereIn('id', $albumIds)->update(['deleted' => $deleted]);

            return response()->json(['message' => 'Albums updated successfully']);
        }

        /**
         * Update the is_viewed status of an album.
         *
         * @param Request $request
         * @param int $id
         * @return \Illuminate\Http\JsonResponse
         */
        public function updateIsViewed(Request $request, $id)
        {
            // 找到指定的相簿
            $album = Album::find($id);

            if (!$album) {
                return response()->json(['message' => 'Album not found'], 404);
            }

            // 從請求中取得 is_viewed 的狀態，預設為 false
            $isViewed = $request->input('is_viewed', false);

            // 更新相簿的 is_viewed 欄位
            $album->is_viewed = $isViewed;
            $album->save();

            return response()->json(['message' => 'Album is_viewed status updated successfully']);
        }
    }
