<?php

    namespace App\Http\Controllers;

    use Illuminate\Support\Facades\Http;

    class TestImageController extends Controller
    {
        public function show()
        {
            $imageUrl = 'https://10.147.18.147/video/o3ik.o%20(@o3ik.o)/o3ik.o%20(@o3ik.o)_1.jpg';

            return response()->view('test-image', ['imageUrl' => $imageUrl]);
        }
    }
