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
            return in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), [ 'jpg', 'jpeg', 'png' ]);
        });

        // 假設文件名格式中包含了相簿名稱和一個連接符號，如 album1-image1.jpg
        $groupedImages = $imageFiles->groupBy(function ($file) {
            return explode('-', basename($file))[0];  // 分組條件根據文件名調整
        });

        // 按相簿選取最多六張圖片，直到滿足數量要求
        $selectedImages = collect();
        $albums         = $groupedImages->keys()->shuffle();
        foreach ($albums as $album) {
            $currentAlbumImages = $groupedImages[$album]->shuffle();
            $needed             = 6 - $selectedImages->count();
            $selectedImages     = $selectedImages->concat($currentAlbumImages->take($needed));
            if ($selectedImages->count() >= 6) {
                break;
            }
        }

        // 將圖片轉換為 base64
        $imagesBase64 = [];
        foreach ($selectedImages as $image) {
            $filename = basename($image);
            try {
                $fileContents = Storage::disk('local2')->get($image);

                // 使用 GD 庫壓縮圖片
                $gdImage = imagecreatefromstring($fileContents);
                if ($gdImage === false) {
                    throw new \Exception('Unable to create image from file.');
                }

                // 設定壓縮後的最大寬度和高度
                $maxWidth  = 800;
                $maxHeight = 800;

                $originalWidth  = imagesx($gdImage);
                $originalHeight = imagesy($gdImage);
                $ratio          = min($maxWidth / $originalWidth, $maxHeight / $originalHeight);
                $targetWidth    = (int)($originalWidth * $ratio);
                $targetHeight   = (int)($originalHeight * $ratio);

                // 創建一個新圖像
                $newImage = imagecreatetruecolor($targetWidth, $targetHeight);

                // 複製並調整圖像大小
                imagecopyresampled($newImage, $gdImage, 0, 0, 0, 0, $targetWidth, $targetHeight, $originalWidth, $originalHeight);

                // 捕捉輸出到變數中
                ob_start();
                imagejpeg($newImage, null, 75); // 設置JPEG質量為75
                $compressedData = ob_get_clean();

                $base64                  = base64_encode($compressedData);
                $imagesBase64[$filename] = $base64;

                // 釋放內存
                imagedestroy($gdImage);
                imagedestroy($newImage);
            }
            catch (\Exception $e) {
                return response()->json([ 'error' => 'File not found or image processing failed: ' . $image ], 404);
            }
        }

        return response()->json($imagesBase64);
    }
}
