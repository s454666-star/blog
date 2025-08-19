<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class UrlViewerController extends Controller
{
    public function index()
    {
        return view('url_viewer');
    }

    public function fetch(Request $request)
    {
        $url = $request->input('url');

        try {
            $response = Http::get($url);
            $html = $response->body();

            // 嘗試抓 <video src="...">
            preg_match('/<video[^>]+src=["\']([^"\']+)["\']/i', $html, $matches);
            $videoUrl = $matches[1] ?? null;

            // 或 meta property="og:video"
            if (!$videoUrl) {
                preg_match('/<meta[^>]+property=["\']og:video["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches);
                $videoUrl = $matches[1] ?? null;
            }

            if (!$videoUrl) {
                return response()->json([
                    'success' => false,
                    'error' => '找不到影片連結'
                ]);
            }

            return response()->json([
                'success' => true,
                'videoUrl' => $videoUrl
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
    }

    public function download(Request $request)
    {
        $videoUrl = $request->query('url');
        if (!$videoUrl) {
            abort(404, '缺少影片網址');
        }

        $fileName = basename(parse_url($videoUrl, PHP_URL_PATH));

        $response = Http::get($videoUrl);

        return response($response->body(), 200)
            ->header('Content-Type', 'video/mp4')
            ->header('Content-Disposition', "attachment; filename=\"$fileName\"");
    }
}
