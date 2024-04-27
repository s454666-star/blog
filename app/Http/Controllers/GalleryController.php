<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder; // 引入 Finder

class GalleryController extends Controller
{
    public function index()
    {
        $photoPath = config('gallery.photo_path');
        $folders = File::directories($photoPath);
        $images = collect();

        foreach ($folders as $folder) {
            $files = File::allFiles($folder);
            $images = $images->merge($files);
        }

        $images = $images->random(150)->values()->all();

        // 使用 Finder 準備圖片文件
        $finder = new Finder();
        $finder->files()->in($photoPath); // 設置要搜索的目錄

        return view('gallery.index', compact('images', 'finder'));
    }
}
