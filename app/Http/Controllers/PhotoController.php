<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Photo::query();

        if ($request->has('photo_name')) {
            $query->where('photo_name', 'like', '%' . $request->photo_name . '%');
        }

        if ($request->has('rating')) {
            $query->where('rating', $request->rating);
        }

        $count = $request->input('counts', 50);  // Default to 50 if 'counts' not provided

        // If 'offset' is not provided, select a random start point
        if (!$request->filled('offset')) {
            $totalRows = Photo::count();                                             // Get total number of rows in the table
            $skip      = $totalRows > 0 ? rand(0, max(0, $totalRows - $count)) : 0;  // Calculate a random offset
            $photos    = $query->skip($skip)->take($count)->get();
        } else {
            $photos = $query->where('id', '>', $request->offset)->limit($count)->get();
        }

        return response()->json($photos);
    }
}

