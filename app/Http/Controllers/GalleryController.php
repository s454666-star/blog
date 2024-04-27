<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Facades\Log;

class GalleryController extends Controller
{
    public function index()
    {
        return $this->loadImages(new Request(['offset' => 0]));
    }
    public function loadImages(Request $request): \Illuminate\Http\JsonResponse
    {
        $photoPath = config('gallery.photo_path');
        $offset = $request->offset ?? 0;
        $limit = 50;  // Load 50 images at a time

        if (!File::exists($photoPath) || !File::isDirectory($photoPath)) {
            $error = ['error' => 'Gallery directory not found.'];
            return $request->expectsJson() ? response()->json($error, 404) : abort(404, $error['error']);
        }

        $finder = new Finder();
        $finder->files()->in($photoPath)->sortByName();
        $finder->skip($offset)->limit($limit);

        $imagePaths = [];
        foreach ($finder as $file) {
            $path = $file->getRealPath();
            $compressedPath = $this->compressImage($path, 'thumbnails/' . $file->getFilename());
            if ($compressedPath) {
                $imagePaths[] = asset($compressedPath); // Use asset() to get the correct URL path
            }
        }

        if ($request->expectsJson()) {
            return response()->json($imagePaths);
        } else {
            return view('gallery.index', compact('imagePaths'));
        }
    }

    protected function compressImage($sourcePath, $destinationPath)
    {
        if (!file_exists($sourcePath) || !is_readable($sourcePath)) {
            Log::error("File not found or not readable: " . $sourcePath);
            return false;
        }

        $imageType = @exif_imagetype($sourcePath);
        if (!$imageType) {
            Log::error("Failed to determine image type or unsupported image: " . $sourcePath);
            return false;
        }

        switch ($imageType) {
            case IMAGETYPE_JPEG:
                $source = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $source = imagecreatefrompng($sourcePath);
                break;
            case IMAGETYPE_GIF:
                $source = imagecreatefromgif($sourcePath);
                break;
            default:
                Log::error("Unsupported image type: " . $sourcePath);
                return false;
        }

        list($width, $height) = getimagesize($sourcePath);
        $newWidth = 320;
        $newHeight = 240;
        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if ($imageType == IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        imagecopyresized($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $destinationFullPath = public_path($destinationPath);
        $directory = dirname($destinationFullPath);

        if (!file_exists($directory) && !mkdir($directory, 0775, true)) {
            Log::error("Failed to create directory: " . $directory);
            return false;
        }

        if (!is_writable($directory)) {
            Log::error("Directory not writable: " . $directory);
            return false;
        }

        switch ($imageType) {
            case IMAGETYPE_JPEG:
                imagejpeg($thumb, $destinationFullPath, 75);
                break;
            case IMAGETYPE_PNG:
                imagepng($thumb, $destinationFullPath);
                break;
            case IMAGETYPE_GIF:
                imagegif($thumb, $destinationFullPath);
                break;
        }

        imagedestroy($thumb);
        return $destinationPath;
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
