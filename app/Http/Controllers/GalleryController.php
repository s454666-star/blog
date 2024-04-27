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
        $finder->files()->in($photoPath); // Correctly setting up the directory for Finder

        $folders = File::directories($photoPath);
        $images  = collect();

        foreach ($folders as $folder) {
            $files  = File::allFiles($folder);
            $images = $images->merge($files);
        }

        $images = $images->random(150)->values()->all();

        return view('gallery.index', compact('images', 'finder'));
    }
}

