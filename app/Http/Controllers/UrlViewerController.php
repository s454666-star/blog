<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class UrlViewerController extends Controller
{
    private $igSessionFile;

    public function __construct()
    {
        $this->igSessionFile = storage_path("app/cookies/ig_session.txt");
    }

    public function index()
    {
        return view('url_viewer');
    }

    public function saveSession(Request $request)
    {
        $session = trim($request->input('session'));
        if (!$session) {
            return response()->json(['success' => false, 'error' => 'sessionid 不能為空']);
        }

        if (!is_dir(dirname($this->igSessionFile))) {
            mkdir(dirname($this->igSessionFile), 0755, true);
        }

        file_put_contents($this->igSessionFile, $session);
        return response()->json(['success' => true, 'message' => 'IG session 已儲存']);
    }

    public function fetch(Request $request)
    {
        $url = $request->input('url');
        $debugLog = [];
        $videoUrl = null;

        try {
            $debugLog[] = "開始解析 URL: " . $url;

            $cmd = ['yt-dlp', '-g'];

            // 如果是 Instagram
            if (strpos($url, 'instagram.com') !== false) {
                if (!file_exists($this->igSessionFile)) {
                    return response()->json([
                        'success' => false,
                        'error' => '需要 IG sessionid，請先輸入',
                        'needSession' => true,
                        'log' => $debugLog
                    ]);
                }

                $sessionId = trim(file_get_contents($this->igSessionFile));

                // 建立 cookies.txt 給 yt-dlp 用
                $cookiePath = storage_path("app/cookies/ig_cookie_tmp.txt");
                $cookieLine = ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t{$sessionId}\n";
                file_put_contents($cookiePath, $cookieLine);

                $cmd = ['yt-dlp', '-g', '--cookies', $cookiePath, $url];
                $debugLog[] = "使用 IG sessionid 下載";
            } else {
                $cmd[] = $url;
            }

            $process = new Process($cmd);
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

        $fileName = now()->format('Ymd_His') . ".mp4";
        $tempPath = storage_path("app/temp/{$fileName}");

        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $process = new Process(['yt-dlp', '-o', $tempPath, '-f', 'mp4', $videoUrl]);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);
    }
}
