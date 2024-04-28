<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

class PhotoImportController extends Controller
{
    public function import(): string
    {
        $basePath        = '/mnt/nas/photo/圖/新整理';
        $validExtensions = [ 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'tiff', 'webp' ];  // 支持的圖片格式列表
        $albums          = glob($basePath . '/*', GLOB_ONLYDIR);                    // 只獲取目錄

        foreach ($albums as $album) {
            $albumName = basename($album);
            $allFiles  = glob($album . '/*.*');  // 獲取所有文件

            foreach ($allFiles as $file) {
                $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                if (in_array($extension, $validExtensions)) {  // 檢查是否為有效的圖片格式
                    $webUrl = 'https://s2.starweb.life/' . rawurlencode('新整理') . '/' . rawurlencode($albumName) . '/' . rawurlencode(basename($file));
                    // 確保 webUrl 唯一
                    $exists = DB::table('photos')->where('web_url', $webUrl)->exists();
                    if (!$exists) {
                        DB::table('photos')->insert([
                            'album_name'   => $albumName,
                            'photo_name'   => basename($file),
                            'file_path'    => $file,
                            'web_url'      => $webUrl,
                            'is_beautiful' => null,
                        ]);
                    }
                }
            }
        }

        return 'Photos have been imported successfully!';
    }
}
