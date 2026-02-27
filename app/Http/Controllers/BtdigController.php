<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;

    class BtdigController extends Controller
    {
        public function index(Request $request)
        {
            $type = (string) $request->query('type', '2'); // 預設 type=2

            // 改成關鍵字範圍（可空）
            // type=1：輸入 900 ~ 902 會對應 n0900 ~ n0902
            // type=2：輸入 1247868 ~ 1247975 會對應 FC2-PPV-1247868 ~ FC2-PPV-1247975
            $keywordFrom = $request->query('keyword_from');
            $keywordTo = $request->query('keyword_to');

            // 關鍵字排序（asc/desc）
            $keywordSortDir = (string) $request->query('keyword_sort', 'asc');
            $keywordSortDir = strtolower($keywordSortDir);
            if (!in_array($keywordSortDir, ['asc', 'desc'], true)) {
                $keywordSortDir = 'asc';
            }

            // 隱藏有 disabled（已複製）群組
            $hideDisabledGroups = (string) $request->query('hide_disabled_groups', '0');
            $hideDisabledGroups = $hideDisabledGroups === '1';

            // 每頁筆數（100/200/400/600），預設 400
            $perPage = (int) $request->query('per_page', 400);
            $allowedPerPage = [100, 200, 400, 600];
            if (!in_array($perPage, $allowedPerPage, true)) {
                $perPage = 400;
            }

            $allowedTypes = ['1', '2', 'all'];
            if (!in_array($type, $allowedTypes, true)) {
                $type = '2';
            }

            $keywordFromInt = null;
            $keywordToInt = null;

            if ($keywordFrom !== null && $keywordFrom !== '') {
                $keywordFromInt = (int) $keywordFrom;
                if ($keywordFromInt < 0) {
                    $keywordFromInt = 0;
                }
            }

            if ($keywordTo !== null && $keywordTo !== '') {
                $keywordToInt = (int) $keywordTo;
                if ($keywordToInt < 0) {
                    $keywordToInt = 0;
                }
            }

            if ($keywordFromInt !== null && $keywordToInt !== null && $keywordToInt < $keywordFromInt) {
                $tmp = $keywordFromInt;
                $keywordFromInt = $keywordToInt;
                $keywordToInt = $tmp;
            }

            $nbsp = ' '; // 注意：這是一個 NBSP 字元（不是一般空白）

            // 把 search_keyword 轉成可排序/可篩選的數字
            // type=1：n0900 -> 900
            // type=2：FC2-PPV-1247868 -> 1247868
            $keywordSortExpr = "
            CASE
                WHEN btdig_results.type = '1' THEN CAST(SUBSTRING(btdig_results.search_keyword, 2) AS UNSIGNED)
                WHEN btdig_results.type = '2' THEN CAST(SUBSTRING_INDEX(btdig_results.search_keyword, '-', -1) AS UNSIGNED)
                ELSE 0
            END
        ";

            // size 轉 GB 數字（用於排序）
            $sizeGbExpr = "
            CASE
                WHEN btdig_results.size IS NULL OR TRIM(btdig_results.size) = '' THEN 0
                WHEN UPPER(btdig_results.size) LIKE '%TB%' THEN
                    CAST(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(UPPER(btdig_results.size), '{$nbsp}', ''),
                                'TB', ''),
                            ' ', ''),
                        ',', '')
                    AS DECIMAL(12,4)) * 1024
                WHEN UPPER(btdig_results.size) LIKE '%GB%' THEN
                    CAST(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(UPPER(btdig_results.size), '{$nbsp}', ''),
                                'GB', ''),
                            ' ', ''),
                        ',', '')
                    AS DECIMAL(12,4))
                WHEN UPPER(btdig_results.size) LIKE '%MB%' THEN
                    CAST(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(UPPER(btdig_results.size), '{$nbsp}', ''),
                                'MB', ''),
                            ' ', ''),
                        ',', '')
                    AS DECIMAL(12,4)) / 1024
                WHEN UPPER(btdig_results.size) LIKE '%KB%' THEN
                    CAST(
                        REPLACE(
                            REPLACE(
                                REPLACE(
                                    REPLACE(UPPER(btdig_results.size), '{$nbsp}', ''),
                                'KB', ''),
                            ' ', ''),
                        ',', '')
                    AS DECIMAL(12,4)) / 1024 / 1024
                ELSE
                    CAST(
                        REPLACE(
                            REPLACE(
                                REPLACE(UPPER(btdig_results.size), '{$nbsp}', ''),
                            ' ', ''),
                        ',', '')
                    AS DECIMAL(12,4))
            END
        ";

            $query = DB::table('btdig_results')
                ->select([
                    'btdig_results.id',
                    'btdig_results.search_keyword',
                    'btdig_results.type',
                    'btdig_results.detail_url',
                    'btdig_results.magnet',
                    'btdig_results.name',
                    'btdig_results.size',
                    'btdig_results.age',
                    'btdig_results.files',
                    'btdig_results.created_at',
                    'btdig_results.copied_at',
                ])
                ->selectRaw("{$keywordSortExpr} AS keyword_sort_num")
                ->selectRaw("{$sizeGbExpr} AS size_gb_num");

            if ($type !== 'all') {
                $query->where('btdig_results.type', '=', $type);
            }

            if ($keywordFromInt !== null) {
                $query->whereRaw("({$keywordSortExpr}) >= ?", [$keywordFromInt]);
            }

            if ($keywordToInt !== null) {
                $query->whereRaw("({$keywordSortExpr}) <= ?", [$keywordToInt]);
            }

            // 隱藏含已複製群組：該 search_keyword 只要有任何 copied_at，就整組排除
            if ($hideDisabledGroups) {
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('btdig_results as btdig_results_sub')
                        ->whereColumn('btdig_results_sub.search_keyword', 'btdig_results.search_keyword')
                        ->whereNotNull('btdig_results_sub.copied_at');
                });
            }

            $query->orderBy('btdig_results.type', 'asc')
                ->orderByRaw("{$keywordSortExpr} {$keywordSortDir}")
                ->orderByRaw("{$sizeGbExpr} ASC")
                ->orderBy('btdig_results.id', 'asc');

            $results = $query->paginate($perPage)->appends($request->query());

            return view('btdig.index', [
                'results' => $results,
                'type' => $type,
                'keywordFrom' => $keywordFrom,
                'keywordTo' => $keywordTo,
                'keywordSortDir' => $keywordSortDir,
                'hideDisabledGroups' => $hideDisabledGroups,
                'perPage' => $perPage,
            ]);
        }

        public function markCopied(Request $request)
        {
            $ids = $request->input('ids', []);
            if (!is_array($ids)) {
                return response()->json(['ok' => false, 'message' => 'ids 格式錯誤'], 422);
            }

            $cleanIds = [];
            foreach ($ids as $id) {
                $idInt = (int) $id;
                if ($idInt > 0) {
                    $cleanIds[] = $idInt;
                }
            }
            $cleanIds = array_values(array_unique($cleanIds));

            if (count($cleanIds) === 0) {
                return response()->json(['ok' => false, 'message' => '沒有可更新的 id'], 422);
            }

            DB::table('btdig_results')
                ->whereIn('id', $cleanIds)
                ->whereNull('copied_at')
                ->update([
                    'copied_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);

            return response()->json(['ok' => true]);
        }
    }
