<?php

namespace App\Http\Controllers;

use App\Models\Video;
use App\Models\VideoTs;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
//        $query = Video::query();
        $query = VideoTs::query();

        if ($request->has('video_name')) {
            $query->where('video_name', 'like', '%' . $request->video_name . '%');
        }

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        if ($request->has('video_id')) {
            $query->where('id', $request->video_id);
        }

        $count = $request->input('counts', 1);  // Default to 50 if 'counts' not provided

        if (!$request->filled('offset')) {
//            $totalRows = Video::count();                                           // Get total number of rows in the table
            $totalRows = VideoTs::count();                                           // Get total number of rows in the table
            $skip      = $totalRows > 0 ? rand(0, max(0, $totalRows - $count)) : 0; // Calculate a random offset
            $videos    = $query->skip($skip)->take($count)->get();
        } else {
            $videos = $query->where('id', '>', $request->offset)->limit($count)->get();
        }

        return response()->json($videos);
    }
}
