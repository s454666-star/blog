<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Http;

    class TestImageController extends Controller
    {
        public function proxy(Request $request)
        {
            // test url
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
