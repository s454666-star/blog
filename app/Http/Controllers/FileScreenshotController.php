<?php

namespace App\Http\Controllers;

use App\Models\FileScreenshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class FileScreenshotController extends Controller
{
    // 列出所有的檔案資料
    public function index(Request $request)
    {
        // 分頁，每頁20筆資料
        $perPage = $request->input('per_page', 20); // 可自定義每頁資料數

        // 篩選條件：評分和備註
        $query = FileScreenshot::query();

        // 篩選評分，僅在 rating 有傳入值時篩選
        if ($request->has('rating') && $request->input('rating') !== '') {
            if ($request->input('rating') == 'unrated') {
                $query->whereNull('rating');
            } else {
                $query->where('rating', $request->input('rating'));
            }
        }

        // 篩選備註，僅在 notes 有傳入值時篩選
        if ($request->has('notes') && $request->input('notes') !== '') {
            $query->where('notes', 'like', '%' . $request->input('notes') . '%');
        }

        // 排序條件：依據評分排序，默認為升序
        if ($request->has('sort_by_rating')) {
            $query->orderBy('rating', $request->input('sort_by_rating') === 'desc' ? 'desc' : 'asc');
        }

        // 回傳分頁結果
        $screenshots = $query->paginate($perPage);

        return response()->json($screenshots);
    }


    // 更新評分
    public function updateRating(Request $request, $id)
    {
        $request->validate([
            'rating' => 'required|integer',
        ]);

        $fileScreenshot = FileScreenshot::findOrFail($id);
        $fileScreenshot->rating = $request->input('rating');
        $fileScreenshot->save();

        return response()->json(['message' => 'Rating updated successfully']);
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

        return response()->json(['message' => 'Notes updated successfully']);
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

        return response()->json(['message' => 'File and related screenshots deleted successfully']);
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

        return response()->json(['message' => 'Screenshots deleted and paths updated successfully']);
    }
}
