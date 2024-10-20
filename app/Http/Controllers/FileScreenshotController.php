<?php

namespace App\Http\Controllers;

use App\Models\FileScreenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileScreenshotController extends Controller
{
    public function index(Request $request)
    {
        // 分頁：預設每頁 20 筆資料
        $perPage = $request->input('per_page', 20);

        // 建立查詢
        $query = FileScreenshot::query();

        // 如果提供了 rating 並且不是 'all'，則進行篩選
        if ($request->filled('rating') && $request->input('rating') !== 'all') {
            if ($request->input('rating') === 'unrated') {
                // 選取 rating 為 NULL 的記錄
                $query->whereNull('rating');
            } else {
                // 選取指定 rating 的記錄
                $query->where('rating', $request->input('rating'));
            }
        }

        // 如果提供了 notes，則進行篩選
        if ($request->filled('notes')) {
            $query->where('notes', 'like', '%' . $request->input('notes') . '%');
        }

        // 排序參數
        $sortBy = $request->input('sort_by', 'rating');
        $sortDirection = strtolower($request->input('sort_direction', 'asc')) === 'desc' ? 'DESC' : 'ASC';
        $allowedSortColumns = ['id', 'file_name', 'rating'];

        if (in_array($sortBy, $allowedSortColumns)) {
            if ($sortBy === 'rating') {
                // 使用 CASE WHEN 來處理 NULL 排序
                if ($sortDirection === 'ASC') {
                    $query->orderByRaw("CASE WHEN rating IS NULL THEN 0 ELSE 1 END ASC");
                    $query->orderBy('rating', 'ASC');
                } else {
                    $query->orderByRaw("CASE WHEN rating IS NULL THEN 1 ELSE 0 END DESC");
                    $query->orderBy('rating', 'DESC');
                }
            } else {
                // 其他欄位的標準排序
                $query->orderBy($sortBy, $sortDirection);
            }
        }

        // 記錄 SQL 查詢以便除錯
        Log::info('SQL Query: ' . $query->toSql());
        Log::info('Query Bindings: ' . json_encode($query->getBindings()));

        // 取得分頁結果
        $screenshots = $query->paginate($perPage);

        return response()->json($screenshots);
    }

    // 更新評分
    public function updateRating(Request $request, $id)
    {
        $request->validate([
            'rating' => 'required|integer|min:1|max:10', // 調整評分範圍為1到10
        ]);

        $fileScreenshot = FileScreenshot::findOrFail($id);
        $fileScreenshot->rating = $request->input('rating');
        $fileScreenshot->save();

        return response()->json(['message' => '評分已成功更新']);
    }

    // 更新備註
    public function updateNotes(Request $request, $id)
    {
        $request->validate([
            'notes' => 'required|string',
        ]);

        $fileScreenshot = FileScreenshot::findOrFail($id);
        $fileScreenshot->notes = $request->input('notes');
        $fileScreenshot->save();

        return response()->json(['message' => '備註已成功更新']);
    }

    // 刪除某個檔案及對應資料
    public function deleteFile($id)
    {
        $fileScreenshot = FileScreenshot::findOrFail($id);

        // 刪除對應的檔案
        if (File::exists($fileScreenshot->file_path)) {
            File::delete($fileScreenshot->file_path);
        }

        // 刪除截圖
        $screenshots = explode(',', $fileScreenshot->screenshot_paths);
        foreach ($screenshots as $screenshot) {
            if (File::exists($screenshot)) {
                File::delete($screenshot);
            }
        }

        // 刪除資料表中的資料
        $fileScreenshot->delete();

        return response()->json(['message' => '檔案及相關截圖已成功刪除']);
    }

    // 刪除某些截圖，並重組截圖的資料
    public function deleteScreenshots(Request $request, $id)
    {
        $request->validate([
            'screenshots' => 'required|array',
        ]);

        $fileScreenshot = FileScreenshot::findOrFail($id);
        $currentScreenshots = explode(',', $fileScreenshot->screenshot_paths);

        // 過濾出不需刪除的截圖
        $remainingScreenshots = array_diff($currentScreenshots, $request->input('screenshots'));

        // 刪除選擇的截圖
        foreach ($request->input('screenshots') as $screenshot) {
            if (File::exists($screenshot)) {
                File::delete($screenshot);
            }
        }

        // 更新資料表中的截圖路徑
        $fileScreenshot->screenshot_paths = implode(',', $remainingScreenshots);
        $fileScreenshot->save();

        return response()->json(['message' => '截圖已成功刪除並更新路徑']);
    }
}
