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
        // 嚴格驗證 cookie 檔是否為 Netscape 格式；不是的話視為未登入
        $hasSession = $this->ensureValidCookieFile();
        return view('url_viewer', compact('hasSession'));
    }

    /**
     * 儲存 Instagram sessionid（或整串 cookie），統一寫成 Netscape Cookie 格式
     */
    public function saveSession(Request $request)
    {
        $raw = trim((string) $request->input('session'));

        if ($raw === '') {
            return response()->json(['success' => false, 'error' => 'sessionid 不能為空']);
        }

        // 允許貼「只 sessionid」或「整串 cookie（含 sessionid=...; ）」
        $sessionId = $this->extractSessionId($raw);
        if ($sessionId === null || $sessionId === '') {
            return response()->json(['success' => false, 'error' => '無法從輸入取得有效的 sessionid']);
        }

        $this->ensureCookiesDir();

        // 以 Netscape 格式寫入
        $this->writeNetscapeCookieFile($sessionId);

        // 再次驗證
        $ok = $this->isValidNetscapeCookieFile($this->igSessionFile);
        if (!$ok) {
            return response()->json(['success' => false, 'error' => '寫入 cookie 檔失敗，格式驗證未通過']);
        }

        return response()->json(['success' => true, 'message' => 'Session 已儲存 (Netscape 格式)']);
    }

    /**
     * 抓影片直連 URL (預覽)
     */
    public function fetch(Request $request)
    {
        $url = trim((string) $request->input('url'));
        if ($url === '') {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        $isIG = $this->isInstagramUrl($url);

        // 若為 IG 但沒有有效 cookie，請先輸入 session
        if ($isIG && !$this->isValidNetscapeCookieFile($this->igSessionFile)) {
            return response()->json([
                'success' => false,
                'error' => 'Instagram 需要有效的 sessionid，請先輸入。',
                'needSession' => true
            ]);
        }

        $args = [
            'yt-dlp',
            '-f', 'bestvideo+bestaudio/best',
            '-g', // 只回傳直連
            $url
        ];

        // 僅 IG 才帶 cookies，避免無關站點也讀取錯誤 cookie 檔而引發錯誤
        if ($isIG) {
            $args = [
                'yt-dlp',
                '--cookies', $this->igSessionFile,
                '-f', 'bestvideo+bestaudio/best',
                '-g',
                $url
            ];
        }

        $process = new Process($args);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            // 將 stderr 直接回傳前端
            return response()->json(['success' => false, 'error' => $process->getErrorOutput()]);
        }

        $output = trim($process->getOutput());
        $urls = preg_split("/\r\n|\n|\r/", $output) ?: [];
        $urls = array_values(array_filter($urls, function ($u) {
            return preg_match('#^https?://#i', $u);
        }));

        if (empty($urls)) {
            return response()->json(['success' => false, 'error' => '未取得可播放的直連 URL']);
        }

        return response()->json(['success' => true, 'urls' => $urls]);
    }

    /**
     * 下載影片（含聲音）
     */
    public function download(Request $request)
    {
        $url = trim((string) $request->query('url'));
        if ($url === '') {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        $isIG = $this->isInstagramUrl($url);
        if ($isIG && !$this->isValidNetscapeCookieFile($this->igSessionFile)) {
            return response()->json([
                'success' => false,
                'error' => 'Instagram 需要有效的 sessionid，請先輸入再下載。',
                'needSession' => true
            ]);
        }

        $fileName = "ig_video_" . date("Ymd_His") . ".mp4";
        $filePath = storage_path("app/public/" . $fileName);

        $args = [
            'yt-dlp',
            '-f', 'bestvideo+bestaudio/best',
            '--merge-output-format', 'mp4',
            '-o', $filePath,
            $url
        ];

        if ($isIG) {
            $args = [
                'yt-dlp',
                '--cookies', $this->igSessionFile,
                '-f', 'bestvideo+bestaudio/best',
                '--merge-output-format', 'mp4',
                '-o', $filePath,
                $url
            ];
        }

        $process = new Process($args);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful()) {
            return response()->json(['success' => false, 'error' => $process->getErrorOutput()]);
        }

        if (!is_file($filePath)) {
            return response()->json(['success' => false, 'error' => '下載失敗，找不到輸出檔案']);
        }

        return Response::download($filePath)->deleteFileAfterSend(true);
    }

    /* -------------------- 私有工具方法 -------------------- */

    private function ensureCookiesDir(): void
    {
        $dir = dirname($this->igSessionFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function isInstagramUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) return false;
        $host = strtolower($host);
        return (strpos($host, 'instagram.com') !== false);
    }

    /**
     * 驗證 Netscape Cookie 檔格式是否正確，至少要有一行 7 欄位（\t 分隔），且包含 sessionid
     */
    private function isValidNetscapeCookieFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }
        // 必須包含標頭字串
        if (strpos($content, 'Netscape HTTP Cookie File') === false) {
            return false;
        }
        // 檢查是否至少有一行 7 欄位，且 name= sessionid
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = explode("\t", $line);
            if (count($parts) === 7) {
                // [$domain, $flag, $path, $secure, $expiry, $name, $value]
                if ($parts[5] === 'sessionid' && $parts[6] !== '') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * 嘗試自動修復舊檔：若檔內只有一段字串（可能是 sessionid 或含 sessionid=...），轉寫為 Netscape 格式。
     * 回傳是否修復成功（或原本就有效）。
     */
    private function ensureValidCookieFile(): bool
    {
        if ($this->isValidNetscapeCookieFile($this->igSessionFile)) {
            return true;
        }

        if (!is_file($this->igSessionFile)) {
            return false;
        }

        $raw = trim((string) file_get_contents($this->igSessionFile));
        if ($raw === '') {
            return false;
        }

        // 嘗試從檔案內容抽出 sessionid
        $sessionId = $this->extractSessionId($raw);
        if ($sessionId) {
            $this->ensureCookiesDir();
            $this->writeNetscapeCookieFile($sessionId);
            return $this->isValidNetscapeCookieFile($this->igSessionFile);
        }

        return false;
    }

    /**
     * 由使用者輸入或舊檔內容抽取 sessionid
     * 支援：
     *   - 直接貼 sessionid 純值
     *   - 貼整串 Cookie: "...; csrftoken=...; sessionid=XXXX; ds_user_id=...;"
     */
    private function extractSessionId(string $input): ?string
    {
        $trimmed = trim($input, " \t\n\r\0\x0B;");

        // 若包含 sessionid=，從中抓取
        if (stripos($trimmed, 'sessionid=') !== false) {
            if (preg_match('/sessionid=([^;]+)/i', $trimmed, $m)) {
                return urldecode(trim($m[1]));
            }
        }

        // 否則視為直接貼 session 值
        // 做基本過濾：不含空白與分號
        if ($trimmed !== '' && !str_contains($trimmed, ' ') && !str_contains($trimmed, ';')) {
            return urldecode($trimmed);
        }

        return null;
    }

    /**
     * 以 Netscape 格式寫出 cookies 檔（僅 sessionid 一筆）
     * 欄位：domain, includeSubdomains, path, secure, expiry, name, value
     * expiry=0 代表 session cookie
     */
    private function writeNetscapeCookieFile(string $sessionId): void
    {
        $content = "# Netscape HTTP Cookie File\n";
        // 使用 .instagram.com（子網域皆適用），secure 設 TRUE，expiry=0
        $content .= ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t{$sessionId}\n";
        file_put_contents($this->igSessionFile, $content, LOCK_EX);
    }
}
