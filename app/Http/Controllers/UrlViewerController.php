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

    // 解析影片直連 URL
    public function fetch(Request $request)
    {
        $url = $request->input('url');
        $debugLog = [];

        try {
            $debugLog[] = "開始解析 URL: " . $url;

            // 不帶 cookies
            $process = new Process([
                'yt-dlp', '-g', $url
            ]);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                $debugLog[] = "yt-dlp 錯誤:\n" . $process->getErrorOutput();
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

            // yt-dlp 可能回傳多行，取第一行
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

    // 直接下載影片（用日期+時間命名）
    public function download(Request $request)
    {
        $videoUrl = $request->query('url');
        if (!$videoUrl) {
            abort(404, '缺少影片網址');
        }

        $fileName = now()->format('Ymd_His') . ".mp4";
        $tempPath = storage_path("app/temp/{$fileName}");

        try {
            if (!is_dir(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }

            // 直接下載影片到暫存檔
            $process = new Process([
                'yt-dlp',
                '-o', $tempPath,
                '-f', 'mp4',
                $videoUrl
            ]);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new ProcessFailedException($process);
            }

            return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => "下載失敗: " . $e->getMessage()
            ]);
        }
    }
}
