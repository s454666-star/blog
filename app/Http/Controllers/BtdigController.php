<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BtdigController extends Controller
{
    public function index(Request $request)
    {
        $type = (string) $request->query('type', '2');
        $keywordFrom = $request->query('keyword_from');
        $keywordTo = $request->query('keyword_to');

        $keywordSortDir = strtolower((string) $request->query('keyword_sort', 'asc'));
        if (!in_array($keywordSortDir, ['asc', 'desc'], true)) {
            $keywordSortDir = 'asc';
        }

        $hideDisabledGroups = (string) $request->query('hide_disabled_groups', '0') === '1';

        $perPage = (int) $request->query('per_page', 400);
        if (!in_array($perPage, [100, 200, 400, 600], true)) {
            $perPage = 400;
        }

        if (!in_array($type, ['1', '2', 'all'], true)) {
            $type = '2';
        }

        $keywordFromInt = $this->normalizeKeywordBoundary($keywordFrom);
        $keywordToInt = $this->normalizeKeywordBoundary($keywordTo);

        if ($keywordFromInt !== null && $keywordToInt !== null && $keywordToInt < $keywordFromInt) {
            [$keywordFromInt, $keywordToInt] = [$keywordToInt, $keywordFromInt];
        }

        $nbsp = ' ';
        $keywordSortExpr = "
            CASE
                WHEN btdig_results.type = '1' THEN CAST(SUBSTRING(btdig_results.search_keyword, 2) AS UNSIGNED)
                WHEN btdig_results.type = '2' THEN CAST(SUBSTRING_INDEX(btdig_results.search_keyword, '-', -1) AS UNSIGNED)
                ELSE 0
            END
        ";

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
        $resultItems = collect($results->items());
        $previewImagesByKeyword = $this->loadPreviewImagesByKeyword(
            $resultItems->pluck('search_keyword')->filter()->unique()->values()
        );

        return view('btdig.index', [
            'results' => $results,
            'type' => $type,
            'keywordFrom' => $keywordFrom,
            'keywordTo' => $keywordTo,
            'keywordSortDir' => $keywordSortDir,
            'hideDisabledGroups' => $hideDisabledGroups,
            'perPage' => $perPage,
            'previewImagesByKeyword' => $previewImagesByKeyword,
        ]);
    }

    public function image(int $imageId)
    {
        if (!Schema::hasTable('btdig_result_images')) {
            abort(404);
        }

        $image = DB::table('btdig_result_images')
            ->select(['image_base64', 'image_mime_type', 'updated_at'])
            ->where('id', '=', $imageId)
            ->first();

        if ($image === null) {
            abort(404);
        }

        $binary = base64_decode((string) $image->image_base64, true);
        if ($binary === false || $binary === '') {
            abort(404);
        }

        $mimeType = (string) ($image->image_mime_type ?: 'application/octet-stream');
        $lastModified = $image->updated_at ? strtotime((string) $image->updated_at) : time();

        return response($binary, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) strlen($binary),
            'Cache-Control' => 'public, max-age=86400',
            'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
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

    private function normalizeKeywordBoundary($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = (int) $value;

        return $normalized < 0 ? 0 : $normalized;
    }

    private function loadPreviewImagesByKeyword(Collection $keywords): Collection
    {
        if ($keywords->isEmpty() || !Schema::hasTable('btdig_result_images')) {
            return collect();
        }

        return DB::table('btdig_result_images')
            ->select([
                'id',
                'search_keyword',
                'sort_order',
                'article_url',
                'viewimage_url',
                'image_mime_type',
            ])
            ->whereIn('search_keyword', $keywords->all())
            ->orderBy('search_keyword')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->groupBy('search_keyword');
    }
}
