<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class StaticProxyController extends Controller
{
    public function proxy($path)
    {
        // 將 /data/* 對應到實體來源主機的 /video/*
        $targetUrl = 'https://10.147.18.147/video/' . $path;

        try {
            $response = Http::withOptions(['verify' => false])->get($targetUrl);

            if ($response->successful()) {
                return response($response->body(), 200)
                    ->header('Content-Type', $response->header('Content-Type'));
            }

            return response('資源未找到', 404);
        } catch (\Exception $e) {
            return response('載入錯誤', 500);
        }
    }
}
