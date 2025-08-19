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

            // cookies 路徑
            $cookieFile = storage_path('app/cookies/ig_cookies.txt');

            // yt-dlp 指令 (有帶 cookies)
            $cmd = ['yt-dlp', '-g', '--cookies', $cookieFile, $url];
            $process = new \Symfony\Component\Process\Process($cmd);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                $debugLog[] = "❌ yt-dlp 錯誤輸出:\n" . $process->getErrorOutput();
                throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
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
        $url = $request->query('url');
        if (!$url) {
            abort(404, '缺少影片網址');
        }

        // 產生暫存檔路徑
        $fileName = now()->format('Ymd_His') . ".mp4";
        $tempPath = storage_path("app/temp/{$fileName}");

        try {
            // 確保目錄存在
            if (!is_dir(dirname($tempPath))) {
                mkdir(dirname($tempPath), 0755, true);
            }

            // 使用 yt-dlp 直接下載影片到暫存檔
            $process = new \Symfony\Component\Process\Process([
                'yt-dlp',
                '-o', $tempPath,   // 輸出檔案位置
                '-f', 'mp4',       // 指定格式
                $url
            ]);
            $process->setTimeout(120); // 最多等 2 分鐘
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \Symfony\Component\Process\Exception\ProcessFailedException($process);
            }

            // 回傳檔案下載
            return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => "下載失敗: " . $e->getMessage(),
                'log' => $process->getErrorOutput() ?? ''
            ]);
        }
    }

}
