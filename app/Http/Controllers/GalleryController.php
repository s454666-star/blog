<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Facades\Log; // Import Log facade for debugging

class GalleryController extends Controller
{
    public function index()
    {
        $photoPath = config('gallery.photo_path');

        // Debugging: log the path
        Log::info("Photo path: " . $photoPath);

        if (!File::exists($photoPath) || !File::isDirectory($photoPath)) {
            // Log error if directory doesn't exist or is not a directory
            Log::error("Invalid directory: " . $photoPath);
            return abort(404, 'Gallery directory not found.');
        }

        $finder = new Finder();
        try {
            $finder->files()->in($photoPath); // Ensure this is a correct path
        } catch (\Exception $e) {
            // Catch exceptions related to Finder setup
            Log::error("Finder setup error: " . $e->getMessage());
            return abort(500, 'Error setting up file finder.');
        }

        $imagePaths = [];

        foreach ($finder as $file) {
            $imagePaths[] = $file->getRelativePathname();
        }

        if (count($imagePaths) > 50) {
            $randomKeys = array_rand($imagePaths, 50);
            $selectedImages = array_intersect_key($imagePaths, array_flip($randomKeys));
        } else {
            $selectedImages = $imagePaths;
        }

        return view('gallery.index', compact('selectedImages'));
    }
}
