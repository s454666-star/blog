<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GalleryController extends Controller
{
    /**
     * Display a listing of the images.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        Log::info('Entering index method.');
        return $this->loadImages(new Request([ 'offset' => 0 ]));
    }

    /**
     * Load images based on offset and limit.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function loadImages(Request $request): JsonResponse
    {
        Log::info('Loading images with offset: ' . $request->offset);
        $photoPath = config('gallery.photo_path');

        if (!File::exists($photoPath) || !File::isDirectory($photoPath)) {
            Log::error('Gallery directory not found at path: ' . $photoPath);
            return response()->json([ 'error' => 'Gallery directory not found.' ], 404);
        }

        $offset = $request->offset ?? 0;
        $limit  = 10; // Load 10 images at a time

        $finder = new Finder();
        $finder->files()->in($photoPath)->sortByName();

        $imagePaths = [];
        $count      = 0;
        foreach ($finder as $file) {
            if ($count++ < $offset) {
                continue;
            }
            if ($count > $offset + $limit) {
                Log::info('Reached the limit of images to load: ' . $limit);
                break;
            }

            $path           = $file->getRealPath();
            $compressedPath = $this->compressImage($path, 'thumbnails/' . $file->getFilename());
            if ($compressedPath) {
                $imagePaths[] = asset($compressedPath);
            } else {
                Log::error('Failed to compress image at path: ' . $path);
            }
        }

        return response()->json($imagePaths);
    }

    /**
     * Compresses an image and returns the path to the compressed image if successful.
     *
     * @param string $sourcePath
     * @param string $destinationPath
     * @return bool|string
     */
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

        $imageResource = $this->createImageResource($imageType, $sourcePath);
        if (!$imageResource) {
            Log::error('Failed to create image resource for path: ' . $sourcePath);
            return false;
        }

        list($width, $height) = getimagesize($sourcePath);
        $newWidth  = 320;
        $newHeight = 240;

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if ($imageType == IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }
        imagecopyresized($thumb, $imageResource, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        $destinationFullPath = public_path($destinationPath);
        $directory           = dirname($destinationFullPath);

        if (!File::isDirectory($directory) && !mkdir($directory, 0775, true)) {
            Log::error("Failed to create directory: " . $directory);
            return false;
        }

        if (!File::isWritable($directory)) {
            Log::error("Directory not writable: " . $directory);
            return false;
        }

        if (!$this->saveImage($thumb, $destinationFullPath, $imageType)) {
            Log::error('Failed to save image at path: ' . $destinationFullPath);
            return false;
        }

        imagedestroy($thumb);
        Log::info('Successfully compressed and saved image at: ' . $destinationFullPath);
        return $destinationPath;
    }

    /**
     * Create a new image resource from a file based on its type.
     *
     * @param int $imageType
     * @param string $sourcePath
     * @return resource|false
     */
    private function createImageResource(int $imageType, string $sourcePath)
    {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($sourcePath);
            case IMAGETYPE_PNG:
                return imagecreatefrompng($sourcePath);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($sourcePath);
            default:
                Log::error("Unsupported image type: " . $sourcePath);
                return false;
        }
    }

    /**
     * Save an image resource to a file.
     *
     * @param resource $imageResource
     * @param string $destinationPath
     * @param int $imageType
     * @return bool
     */
    private function saveImage($imageResource, string $destinationPath, int $imageType): bool
    {
        switch ($imageType) {
            case IMAGETYPE_JPEG:
                return imagejpeg($imageResource, $destinationPath, 75);
            case IMAGETYPE_PNG:
                return imagepng($imageResource, $destinationPath);
            case IMAGETYPE_GIF:
                return imagegif($imageResource, $destinationPath);
            default:
                return false;
        }
    }

    /**
     * Display a specific image.
     *
     * @param string $filename
     * @return BinaryFileResponse
     */
    public function show(string $filename): BinaryFileResponse
    {
        $photoPath = config('gallery.photo_path') . '/' . $filename;
        if (!File::exists($photoPath)) {
            Log::error('Image not found at path: ' . $photoPath);
            return abort(404, 'Image not found.');
        }
        Log::info('Displaying image: ' . $filename);
        return response()->file($photoPath);
    }
}
