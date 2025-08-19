<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

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
            $debugLog[] = "開始解析 URL: " . $url;

            // 呼叫 yt-dlp 取得影片直連 URL
            $process = new Process([
                'yt-dlp', '-g', $url
            ]);
            $process->setTimeout(60); // 最多等 60 秒
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            $output = trim($process->getOutput());
            $debugLog[] = "yt-dlp 輸出:\n" . $output;

            if (empty($output)) {
                return response()->json([
                    'success' => false,
                    'error' => 'yt-dlp 沒有解析到影片連結',
                    'log' => $debugLog
                ]);
            }

            // yt-dlp 可能回傳多行，取第一個
            $videoUrl = explode("\n", $output)[0];

            $debugLog[] = "✅ 影片直連 URL: " . $videoUrl;

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

        $fileName = basename(parse_url($videoUrl, PHP_URL_PATH)) ?: "video.mp4";

        $ch = curl_init($videoUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $videoData = curl_exec($ch);
        curl_close($ch);

        return response($videoData, 200)
            ->header('Content-Type', 'video/mp4')
            ->header('Content-Disposition', "attachment; filename=\"$fileName\"");
    }
}
