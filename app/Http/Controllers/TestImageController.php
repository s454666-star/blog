<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Http;

    class TestImageController extends Controller
    {
        public function proxy(Request $request)
        {
            // 你可以之後改成從 query string 帶入 URL
            $imageUrl = 'https://10.147.18.147/video/o3ik.o%20(@o3ik.o)/o3ik.o%20(@o3ik.o)_1.jpg';

            try {
                $response = Http::withOptions([
                    'verify' => false
                ])->get($imageUrl);

                if ($response->successful()) {
                    return response($response->body(), 200)
                        ->header('Content-Type', $response->header('Content-Type'));
                }

                return response('Image not found', 404);
            } catch (\Exception $e) {
                return response('Error loading image', 500);
            }
        }
    }
