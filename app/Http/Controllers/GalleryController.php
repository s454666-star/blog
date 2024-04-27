<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Facades\Log;

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
        }
        catch (\Exception $e) {
            Log::error("Finder setup error: " . $e->getMessage());
            return abort(500, 'Error setting up file finder.');
        }

        $imagePaths = [];
        foreach ($finder as $file) {
            $path           = $file->getRealPath();
            $compressedPath = $this->compressImage($path, 'thumbnails/' . $file->getFilename());
            if ($compressedPath) {
                $imagePaths[] = $compressedPath;
            }
        }

        $selectedImages = array_slice($imagePaths, 0, 50);

        return view('gallery.index', compact('selectedImages'));
    }

    protected function compressImage($sourcePath, $destinationPath)
    {
        // Determine image type from file contents
        $imageType = exif_imagetype($sourcePath);

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
                return false;  // Exit function if file is not supported
        }

        list($width, $height) = getimagesize($sourcePath);
        $newWidth  = 320;
        $newHeight = 240;

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if ($imageType == IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        // Resize image
        imagecopyresized($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        $destinationFullPath = public_path($destinationPath);
        $directory           = dirname($destinationFullPath);

        // Ensure directory exists and is writable
        if (!file_exists($directory) && !mkdir($directory, 0775, true)) {
            Log::error("Failed to create directory: " . $directory);
            return false;
        }

        if (!is_writable($directory)) {
            Log::error("Directory not writable: " . $directory);
            return false;
        }

        // Save the resized image based on its type
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

        imagedestroy($thumb); // Free up memory
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
