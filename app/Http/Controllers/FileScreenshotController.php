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
        // Pagination: default to 20 items per page
        $perPage = $request->input('per_page', 20);

        // Build the query
        $query = FileScreenshot::query();

        // Filter by rating if provided and not 'all'
        if ($request->has('rating') && $request->input('rating') !== '' && $request->input('rating') !== 'all') {
            if ($request->input('rating') == 'unrated') {
                // Select records where rating is null
                $query->whereNull('rating');
            } else {
                // Select records with the specified rating
                $query->where('rating', $request->input('rating'));
            }
        }

        // Filter by notes if provided
        if ($request->has('notes') && $request->input('notes') !== '') {
            $query->where('notes', 'like', '%' . $request->input('notes') . '%');
        }

        // Sorting parameters
        $sortBy = $request->input('sort_by', 'rating');
        $sortDirection = $request->input('sort_direction', 'asc');
        $allowedSortColumns = ['id', 'file_name', 'rating'];

        if (in_array($sortBy, $allowedSortColumns)) {
            $sortDirection = $sortDirection === 'desc' ? 'DESC' : 'ASC';

            if ($sortBy == 'rating') {
                // Adjust sorting to include null ratings
                // Place null ratings at the beginning if ascending, at the end if descending
                if ($sortDirection === 'ASC') {
                    $query->orderByRaw("CASE WHEN rating IS NULL THEN 0 ELSE 1 END, rating ASC");
                } else {
                    $query->orderByRaw("CASE WHEN rating IS NULL THEN 1 ELSE 0 END, rating DESC");
                }
            } else {
                // Standard ordering for other columns
                $query->orderBy($sortBy, $sortDirection);
            }
        }

        // Log the SQL query for debugging purposes
        Log::info('SQL Query: ' . $query->toSql());
        Log::info('Query Bindings: ' . json_encode($query->getBindings()));

        // Get paginated results
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
