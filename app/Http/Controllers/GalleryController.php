<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Image;// Intervention Image library

class GalleryController extends Controller
{
    public function index()
    {
        $photoPath = config('gallery.photo_path');
        Log::info("Photo path: " . $photoPath);

        if (!File::exists($photoPath) || !File::isDirectory($photoPath)) {
            Log::error("Invalid directory: " . $photoPath);
            return abort(404, 'Gallery directory not found.');
        }

        $finder = new Finder();
        try {
            $finder->files()->in($photoPath);
        } catch (\Exception $e) {
            Log::error("Finder setup error: " . $e->getMessage());
            return abort(500, 'Error setting up file finder.');
        }

        $imagePaths = [];
        foreach ($finder as $file) {
            $compressedImage = Image::make($file->getRealPath())->resize(320, 240)->encode('jpg', 75);
            $compressedPath = 'thumbnails/' . $file->getFilename();
            $compressedImage->save(public_path($compressedPath));
            $imagePaths[] = $compressedPath;
        }

        $selectedImages = array_slice($imagePaths, 0, 50);

        return view('gallery.index', compact('selectedImages'));
    }

    public function show($filename)
    {
        $photoPath = config('gallery.photo_path') . '/' . $filename;
        if (!File::exists($photoPath)) {
            return abort(404, 'Image not found.');
        }
        return response()->file($photoPath);
    }
}
