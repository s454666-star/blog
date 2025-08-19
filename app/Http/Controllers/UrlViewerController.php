<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Illuminate\Support\Facades\Response;

class UrlViewerController extends Controller
{
    private $igSessionFile;

    public function __construct()
    {
        $this->igSessionFile = storage_path("app/cookies/ig_session.txt");
    }

    public function index()
    {
        // ✅ 判斷是否已經有 IG session
        $hasSession = file_exists($this->igSessionFile) && filesize($this->igSessionFile) > 0;
        return view('url_viewer', compact('hasSession'));
    }

    // ✅ 儲存 Instagram sessionid 並轉換成 Netscape cookie 格式
    public function saveSession(Request $request)
    {
        $session = trim($request->input('session'));
        if (!$session) {
            return response()->json(['success' => false, 'error' => 'sessionid 不能為空']);
        }

        if (!is_dir(dirname($this->igSessionFile))) {
            mkdir(dirname($this->igSessionFile), 0777, true);
        }

        // 產生 Netscape 格式
        $cookieContent = "# Netscape HTTP Cookie File\n";
        $cookieContent .= "# This file was generated automatically\n";
        $cookieContent .= ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t" . $session . "\n";

        file_put_contents($this->igSessionFile, $cookieContent);

        return response()->json(['success' => true, 'message' => 'Session 已儲存 (Netscape 格式)' ]);
    }

    // 抓影片直連 URL (預覽)
    public function fetch(Request $request)
    {
        $url = $request->input('url');
        if (!$url) {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        $args = [
            'yt-dlp',
            '--cookies', $this->igSessionFile,
            '-f', 'bestvideo+bestaudio/best',
            '-g', // 只取直連
            $url
        ];

        $process = new Process($args);
        $process->run();

        if (!$process->isSuccessful()) {
            return response()->json(['success' => false, 'error' => $process->getErrorOutput()]);
        }

        $output = trim($process->getOutput());
        $urls = preg_split("/\r\n|\n|\r/", $output);

        return response()->json(['success' => true, 'urls' => $urls]);
    }

    // 下載影片（含聲音）
    public function download(Request $request)
    {
        $url = $request->query('url');
        if (!$url) {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        $fileName = "ig_video_" . date("Ymd_His") . ".mp4";
        $filePath = storage_path("app/public/" . $fileName);

        $args = [
            'yt-dlp',
            '--cookies', $this->igSessionFile,
            '-f', 'bestvideo+bestaudio/best',
            '--merge-output-format', 'mp4',
            '-o', $filePath,
            $url
        ];

        $process = new Process($args);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            return response()->json(['success' => false, 'error' => $process->getErrorOutput()]);
        }

        return Response::download($filePath)->deleteFileAfterSend(true);
    }
}
