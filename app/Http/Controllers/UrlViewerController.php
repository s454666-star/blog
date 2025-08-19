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
        return view('url_viewer', [
            'hasSession' => file_exists($this->igSessionFile)
        ]);
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

    // 解析影片（給前端預覽）
    public function fetch(Request $request)
    {
        $url = $request->input('url');
        $debugLog = [];

        try {
            $debugLog[] = "開始解析 URL: " . $url;

            // 用 -f best 讓 yt-dlp 輸出可播放直連
            $cmd = ['yt-dlp', '-f', 'b', '--get-url'];

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
                $cookiePath = storage_path("app/cookies/ig_cookie_tmp.txt");
                $cookieContent = "# Netscape HTTP Cookie File\n";
                $cookieContent .= ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t{$sessionId}\n";
                file_put_contents($cookiePath, $cookieContent);

                $cmd = ['yt-dlp', '-f', 'b', '--cookies', $cookiePath, '--get-url', $url];
                $debugLog[] = "使用 IG sessionid 嘗試下載";
            } else {
                $cmd[] = $url;
            }

            $process = new Process($cmd);
            $process->setTimeout(60);
            $process->run();

            if (!$process->isSuccessful()) {
                $debugLog[] = "yt-dlp 錯誤:\n" . $process->getErrorOutput();
                if (strpos($url, 'instagram.com') !== false) {
                    @unlink($this->igSessionFile);
                    return response()->json([
                        'success' => false,
                        'error' => 'IG sessionid 已失效，請重新輸入',
                        'needSession' => true,
                        'log' => $debugLog
                    ]);
                }
                throw new ProcessFailedException($process);
            }

            $output = trim($process->getOutput());
            $videoUrl = explode("\n", $output)[0];

            $debugLog[] = "yt-dlp 輸出:\n" . $output;
            $debugLog[] = "✅ 影片直連 URL: " . $videoUrl;

            return response()->json([
                'success' => true,
                'videoUrl' => $videoUrl,
                'sourceUrl' => $url, // 保留原始網址給下載用
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

    // 正確下載（用原始網址）
    public function download(Request $request)
    {
        $sourceUrl = $request->query('source');
        if (!$sourceUrl) {
            abort(404, '缺少影片原始網址');
        }

        $fileName = now()->format('Ymd_His') . ".mp4";
        $tempPath = storage_path("app/temp/{$fileName}");

        if (!is_dir(dirname($tempPath))) {
            mkdir(dirname($tempPath), 0755, true);
        }

        $process = new Process([
            'yt-dlp',
            '-f', 'bestvideo+bestaudio/best',
            '--merge-output-format', 'mp4',
            '-o', $tempPath,
            $sourceUrl
        ]);
        $process->setTimeout(180);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return response()->download($tempPath, $fileName)->deleteFileAfterSend(true);
    }
}
