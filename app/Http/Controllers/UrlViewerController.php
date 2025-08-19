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
        $debugLog = [];

        try {
            $debugLog[] = "開始抓取: " . $url;

            $response = Http::get($url);
            $html = $response->body();

            $debugLog[] = "回傳長度: " . strlen($html) . " bytes";

            // 預覽前 500 字
            $debugLog[] = "HTML 前 500 字:\n" . substr($html, 0, 500);

            // 嘗試抓 <video src="...">
            preg_match('/<video[^>]+src=["\']([^"\']+)["\']/i', $html, $matches1);
            if (!empty($matches1)) {
                $debugLog[] = "匹配到 <video src>: " . $matches1[1];
            } else {
                $debugLog[] = "❌ 沒有匹配到 <video src>";
            }

            // 嘗試抓 meta og:video
            preg_match('/<meta[^>]+property=["\']og:video["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $matches2);
            if (!empty($matches2)) {
                $debugLog[] = "匹配到 og:video: " . $matches2[1];
            } else {
                $debugLog[] = "❌ 沒有匹配到 og:video";
            }

            $videoUrl = $matches1[1] ?? ($matches2[1] ?? null);

            if (!$videoUrl) {
                return response()->json([
                    'success' => false,
                    'error' => '找不到影片連結',
                    'log' => $debugLog
                ]);
            }

            $debugLog[] = "✅ 最終影片連結: " . $videoUrl;

            return response()->json([
                'success' => true,
                'videoUrl' => $videoUrl,
                'log' => $debugLog
            ]);
        } catch (\Exception $e) {
            $debugLog[] = "❌ 例外: " . $e->getMessage();
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
                'log' => $debugLog
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
