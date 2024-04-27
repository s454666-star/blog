<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

class GalleryController extends Controller
{
    public function index()
    {
        $photoPath = config('gallery.photo_path');
        $finder    = new Finder();
        $finder->files()->in($photoPath); // Setup directory for Finder

        // Create an array to store file paths for the view
        $imagePaths = [];

        foreach ($finder as $file) {
            $imagePaths[] = $file->getRelativePathname();
        }

        // Get random selection if necessary, here we simplify to just passing paths
        if (count($imagePaths) > 150) {
            $randomKeys     = array_rand($imagePaths, 150);
            $selectedImages = array_intersect_key($imagePaths, array_flip($randomKeys));
        } else {
            $selectedImages = $imagePaths;
        }

        return view('gallery.index', compact('selectedImages'));
    }
}
