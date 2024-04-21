<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ImageFullController extends Controller
{
    public function getImages()
    {
        // 指定網絡磁碟上的主文件夾路徑
        $path = '新整理';  // 確保路徑是正確的並且適用於你的設置

        // 列出所有文件夾中的所有文件，包括子文件夾
        $allFiles = collect(Storage::disk('local2')->allFiles($path));

        // 過濾出圖片文件
        $imageFiles = $allFiles->filter(function ($file) {
            return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png']);
        });

        // 隨機選取100張圖片，如果少於100張則返回全部
        $randomImages = $imageFiles->random(min(10, $imageFiles->count()))->values();

        // 將圖片轉換為 base64
        $imagesBase64 = [];
        foreach ($randomImages as $image) {
            $filename = basename($image);
            try {
                $fileContents = Storage::disk('local2')->get($image);
                $base64 = base64_encode($fileContents);
                $imagesBase64[$filename] = $base64;
            } catch (\Exception $e) {
                return response()->json(['error' => 'File not found: ' . $image], 404);
            }
        }

        return response()->json($imagesBase64);
    }
}
