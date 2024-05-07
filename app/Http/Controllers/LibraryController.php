<?php

// app/Http/Controllers/LibraryController.php
namespace App\Http\Controllers;

use App\Models\VideoTs;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class LibraryController extends Controller
{
    public function index(Request $request)
    {
        // Default sorting parameters
        $sort      = $request->input('sort', 'id');
        $direction = $request->input('direction', 'asc');

        // Retrieve videos with sorting and pagination
        $videos = VideoTs::orderBy($sort, $direction)->paginate(40);

        // Pass sorting data back to the view for persistent sorting links
        return view('videos.index', compact('videos', 'sort', 'direction'));
    }

    public function generateThumbnails(): \Illuminate\Http\JsonResponse
    {
        Artisan::call('video:generate-thumbnails');
        return response()->json(['message' => 'Thumbnail generation started.']);
    }
}

