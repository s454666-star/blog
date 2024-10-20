<?php

namespace App\Http\Controllers;

use App\Models\FileScreenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class FileScreenshotController extends Controller
{
    // 列出所有的檔案資料
    public function index(Request $request)
    {
        // 分頁，每頁20筆資料
        $perPage = $request->input('per_page', 20); // 可自定義每頁資料數

        // 篩選條件：評分和備註
        $query = FileScreenshot::query();

        // 只有當 rating 有傳入值且不為 'all' 時才進行篩選
        if ($request->has('rating') && $request->input('rating') !== '') {
            if ($request->input('rating') === 'unrated') {
                // 篩選未評分的檔案，根據實際情況選擇使用哪種方式來篩選未評分
                // 如果未評分是 null
                $query->whereNull('rating');

                // 如果未評分是 0，請取消下一行的註解
                // $query->where('rating', 0);
            } elseif ($request->input('rating') !== 'all') {
                // 如果評分不是 'all'，則進行評分篩選
                $query->where('rating', $request->input('rating'));
            }
        }

        // 篩選備註，僅在 notes 有傳入值時篩選
        if ($request->has('notes') && $request->input('notes') !== '') {
            $query->where('notes', 'like', '%' . $request->input('notes') . '%');
        }

        // 排序條件：依據傳入的欄位和方向排序，默認為依評分升序
        $sortBy = $request->input('sort_by', 'rating');
        $sortDirection = $request->input('sort_direction', 'asc');
        $allowedSortColumns = ['id', 'file_name', 'rating']; // 可排序的欄位

        if (in_array($sortBy, $allowedSortColumns)) {
            $query->orderBy($sortBy, $sortDirection === 'desc' ? 'desc' : 'asc');
        }

        // 日誌記錄查詢的 SQL 以便調試
        Log::info('SQL Query: ' . $query->toSql());
        Log::info('Query Bindings: ' . json_encode($query->getBindings()));

        // 回傳分頁結果
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
