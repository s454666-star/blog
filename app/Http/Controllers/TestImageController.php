<?php

    namespace App\Http\Controllers;

    use Illuminate\Support\Facades\Http;

    class TestImageController extends Controller
    {
        public function show()
        {
            $imageUrl = 'https://10.147.18.147/video/o3ik.o%20(@o3ik.o)/o3ik.o%20(@o3ik.o)_1.jpg';

            // 抓取圖片並忽略 SSL 驗證
            $response = Http::withOptions(['verify' => false])->get($imageUrl);

            if ($response->successful()) {
                $mime = $response->header('Content-Type');
                $base64 = base64_encode($response->body());
                $imageData = "data:{$mime};base64,{$base64}";

                return view('test-image', ['imageData' => $imageData]);
            }

            return response('圖片載入失敗', 500);
        }
    }
