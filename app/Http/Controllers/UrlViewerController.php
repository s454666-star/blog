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

            $response = Http::withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
            ])->get($url);

            $html = $response->body();
            $debugLog[] = "回傳長度: " . strlen($html) . " bytes";
            $debugLog[] = "HTML 前 500 字:\n" . substr($html, 0, 500);

            $jsonRaw = null;

            // 嘗試找 SIGI_STATE
            if (preg_match('/<script id="SIGI_STATE"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
                $jsonRaw = $matches[1];
                $debugLog[] = "找到 SIGI_STATE JSON，長度: " . strlen($jsonRaw);
            }

            // 如果沒找到 SIGI_STATE，嘗試 NEXT_DATA
            if (!$jsonRaw && preg_match('/<script id="__NEXT_DATA__"[^>]*>(.*?)<\/script>/s', $html, $matches)) {
                $jsonRaw = $matches[1];
                $debugLog[] = "找到 __NEXT_DATA__ JSON，長度: " . strlen($jsonRaw);
            }

            if (!$jsonRaw) {
                $debugLog[] = "❌ 沒找到 SIGI_STATE 或 __NEXT_DATA__";
                return response()->json([
                    'success' => false,
                    'error' => '找不到影片 JSON',
                    'log' => $debugLog
                ]);
            }

            $debugLog[] = "JSON 前 500 字:\n" . substr($jsonRaw, 0, 500);

            $json = json_decode($jsonRaw, true);
            if (!$json) {
                $debugLog[] = "❌ JSON decode 失敗";
                return response()->json([
                    'success' => false,
                    'error' => 'JSON decode 失敗',
                    'log' => $debugLog
                ]);
            }

            $videoUrl = null;

            // 如果是 SIGI_STATE 結構
            if (isset($json['ItemModule']) && is_array($json['ItemModule'])) {
                foreach ($json['ItemModule'] as $itemId => $item) {
                    if (isset($item['video']['playAddr'])) {
                        $videoUrl = $item['video']['playAddr'];
                        $debugLog[] = "找到 playAddr: " . $videoUrl;
                        break;
                    } elseif (isset($item['video']['downloadAddr'])) {
                        $videoUrl = $item['video']['downloadAddr'];
                        $debugLog[] = "找到 downloadAddr: " . $videoUrl;
                        break;
                    }
                }
            }

            // 如果是 NEXT_DATA 結構
            if (!$videoUrl && isset($json['props']['pageProps']['itemInfo']['itemStruct']['video'])) {
                $videoData = $json['props']['pageProps']['itemInfo']['itemStruct']['video'];
                if (isset($videoData['playAddr'])) {
                    $videoUrl = $videoData['playAddr'];
                    $debugLog[] = "NEXT_DATA.playAddr: " . $videoUrl;
                } elseif (isset($videoData['downloadAddr'])) {
                    $videoUrl = $videoData['downloadAddr'];
                    $debugLog[] = "NEXT_DATA.downloadAddr: " . $videoUrl;
                }
            }

            if (!$videoUrl) {
                $debugLog[] = "❌ JSON 裡沒有找到影片連結";
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
