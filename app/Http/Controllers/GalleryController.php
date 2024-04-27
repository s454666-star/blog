<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class GalleryController extends Controller
{
    /**
     * Display a listing of the images.
     */
    public function index(Request $request)
    {
        Log::info('Entering index method.');
        $images = $this->loadImages($request);
        Log::info('Image paths:', [ 'paths' => $images ]);
        return view('gallery.index', [ 'imagePaths' => $images ]); // Updated to reference the correct view path
    }

    /**
     * Load images based on offset and limit.
     *
     * @param Request $request
     * @return array
     */
    public function loadImages(Request $request): array
    {
        ini_set('max_execution_time', 300); // 300 seconds = 5 minutes
        ini_set('memory_limit', '512M');
        ini_set('default_charset', 'UTF-8');
        mb_internal_encoding("UTF-8");

        Log::info('Loading images with offset: ' . $request->offset);
        $baseDir   = '新整理'; // This should be the directory under 'public'
        $photoPath = public_path($baseDir);

        if (!File::exists($photoPath)) {
            Log::error('Gallery directory not found at path: ' . $photoPath);
            return [];
        }

        $directories = File::directories($photoPath);
        if (empty($directories)) {
            Log::error('No albums found in the gallery directory.');
            return [];
        }

        $imagePaths       = [];
        $limit            = 50;
        $attempts         = 0;
        $totalDirectories = count($directories);

        while (count($imagePaths) < $limit && $attempts < $totalDirectories) {
            $selectedAlbum = $directories[array_rand($directories)];
            $directories   = array_diff($directories, [ $selectedAlbum ]); // Remove selected album from list to avoid repetition
            $files         = File::files($selectedAlbum);
            shuffle($files); // Randomize files to get random images from the album

            foreach ($files as $file) {
                if (count($imagePaths) >= $limit) {
                    break;
                }
                if ($file->isFile() && in_array(strtolower($file->getExtension()), [ 'jpg', 'jpeg', 'png', 'gif' ])) {
                    // Construct relative path correctly
                    $relativePath = $baseDir . '/' . File::relativePath($photoPath, $file->getPathname());
                    $imagePaths[] = asset($relativePath);
                    Log::info('Adding image to list: ' . $relativePath);
                }
            }
            $attempts++;
        }

        return $imagePaths;
    }


    /**
     * Compresses an image and returns the path to the compressed image if successful.
     *
     * @param string $sourcePath
     * @param string $destinationPath
     * @return bool|string
     */
    protected function compressImage(string $sourcePath, string $destinationPath)
    {
        Log::info("Starting compression for image at path: $sourcePath");
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

        list($originalWidth, $originalHeight) = getimagesize($sourcePath);
        $maxDimension = 600;  // Maximum dimension

        // Calculate scaling factor
        $scale = min($maxDimension / $originalWidth, $maxDimension / $originalHeight);

        $newWidth  = (int)($originalWidth * $scale);
        $newHeight = (int)($originalHeight * $scale);

        $thumb = imagecreatetruecolor($newWidth, $newHeight);
        if ($imageType == IMAGETYPE_PNG) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $imageResource, 0, 0, 0, 0, $newWidth, $newHeight, $originalWidth, $originalHeight);
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
        imagedestroy($imageResource); // Destroy the original resource to free memory
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
