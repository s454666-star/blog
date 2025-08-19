<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Response;

class UrlViewerController extends Controller
{
    private $igSessionFile;
    private $ytCookieFile;

    public function __construct()
    {
        $this->igSessionFile = storage_path("app/cookies/ig_session.txt");
        $this->ytCookieFile  = storage_path("app/cookies/youtube_cookies.txt");
    }

    public function index()
    {
        // 嚴格驗證 IG cookie 檔；不是正確 Netscape + sessionid 就視為未登入
        $hasSession   = $this->ensureValidIGCookieFile();
        $hasYTCookie  = $this->isValidGenericCookieFile($this->ytCookieFile);
        return view('url_viewer', compact('hasSession', 'hasYTCookie'));
    }

    /**
     * 儲存 Cookie：
     * site=ig  -> 接受 sessionid 或整串 Cookie，寫成 IG Netscape（僅 sessionid）
     * site=yt  -> 接受整串 Cookie（name=value;...），轉為 .youtube.com 的 Netscape 多行
     */
    public function saveSession(Request $request)
    {
        $site = strtolower((string) $request->input('site', 'ig'));
        $raw  = trim((string) $request->input('session'));

        if ($raw === '') {
            return response()->json(['success' => false, 'error' => '輸入不能為空']);
        }

        if ($site === 'ig') {
            $sessionId = $this->extractSessionId($raw);
            if ($sessionId === null || $sessionId === '') {
                return response()->json(['success' => false, 'error' => '無法從輸入取得有效的 Instagram sessionid']);
            }
            $this->ensureCookiesDir();
            $this->writeNetscapeIG($sessionId);
            if (!$this->isValidIGCookieFile($this->igSessionFile)) {
                return response()->json(['success' => false, 'error' => '寫入 IG Cookie 檔失敗，格式驗證未通過']);
            }
            return response()->json(['success' => true, 'message' => 'Instagram Session 已儲存 (Netscape 格式)']);
        }

        if ($site === 'yt') {
            $pairs = $this->parseCookiePairs($raw);
            if (empty($pairs)) {
                return response()->json(['success' => false, 'error' => '請貼上有效的 YouTube Cookie（name=value; 形式）']);
            }
            $this->ensureCookiesDir();
            $this->writeNetscapeForDomain('.youtube.com', $pairs, $this->ytCookieFile);
            if (!$this->isValidGenericCookieFile($this->ytCookieFile)) {
                return response()->json(['success' => false, 'error' => '寫入 YouTube Cookie 檔失敗，格式驗證未通過']);
            }
            return response()->json(['success' => true, 'message' => 'YouTube Cookies 已儲存 (Netscape 格式)']);
        }

        return response()->json(['success' => false, 'error' => '未知的 site 參數']);
    }

    /**
     * 抓直連 URL（預覽）
     */
    public function fetch(Request $request)
    {
        $url = trim((string) $request->input('url'));
        if ($url === '') {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        $isIG = $this->isInstagramUrl($url);
        $isYT = $this->isYouTubeUrl($url);

        if ($isYT) {
            $url = $this->normalizeYouTubeWatchUrl($url);
        }

        if ($isIG && !$this->isValidIGCookieFile($this->igSessionFile)) {
            return response()->json([
                'success' => false,
                'error' => 'Instagram 需要有效的 sessionid，請先輸入。',
                'needSession' => true
            ]);
        }

        // 準備第一階段參數
        $args = $this->buildArgsBase($url, $isIG, $isYT, true);

        // 嘗試執行；若遇到 429/驗證，啟動 YouTube 後備流程
        $run = $this->runYtDlpWithFallback($args, 120, $isYT, true);

        if (!$run['ok']) {
            $resp = ['success' => false, 'error' => $run['stderr']];
            if ($isYT && $run['needYTCookie']) {
                $resp['needYTCookie'] = true;
            }
            return response()->json($resp);
        }

        $urls = $this->splitUrls($run['stdout']);
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
        $isYT = $this->isYouTubeUrl($url);

        if ($isYT) {
            $url = $this->normalizeYouTubeWatchUrl($url);
        }

        if ($isIG && !$this->isValidIGCookieFile($this->igSessionFile)) {
            return response()->json([
                'success' => false,
                'error' => 'Instagram 需要有效的 sessionid，請先輸入再下載。',
                'needSession' => true
            ]);
        }

        $fileName = "video_" . date("Ymd_His") . ".mp4";
        $filePath = storage_path("app/public/" . $fileName);

        $args = $this->buildArgsBase($url, $isIG, $isYT, false);
        // 下載用：輸出 mp4 檔案
        $args = array_merge($args, [
            '--merge-output-format', 'mp4',
            '-o', $filePath
        ]);

        $run = $this->runYtDlpWithFallback($args, 420, $isYT, false);

        if (!$run['ok']) {
            $resp = ['success' => false, 'error' => $run['stderr']];
            if ($isYT && $run['needYTCookie']) {
                $resp['needYTCookie'] = true;
            }
            return response()->json($resp);
        }

        if (!is_file($filePath)) {
            return response()->json(['success' => false, 'error' => '下載失敗，找不到輸出檔案']);
        }

        return Response::download($filePath)->deleteFileAfterSend(true);
    }

    /* -------------------- yt-dlp 參數與執行（含後備） -------------------- */

    private function buildArgsBase(string $url, bool $isIG, bool $isYT, bool $forPreview): array
    {
        // 共同參數：忽略全域設定、強制 IPv4、語系標頭、關閉播放清單
        $base = [
            'yt-dlp',
            '--ignore-config',
            '--force-ipv4',
            '--no-playlist',
            '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            '--user-agent', 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36',
        ];

        // YouTube：優先用 android client（較少觸發驗證）
        if ($isYT) {
            $base = array_merge($base, ['--extractor-args', 'youtube:player_client=android']);
        }

        // IG：僅 IG 連結才帶 cookies
        if ($isIG) {
            $base = array_merge($base, ['--cookies', $this->igSessionFile]);
        }

        // 輸出種類
        if ($forPreview) {
            $base = array_merge($base, ['-f', 'bestvideo+bestaudio/best', '-g']);
        } else {
            $base = array_merge($base, ['-f', 'bestvideo+bestaudio/best']);
        }

        // 較保守的重試策略（避免直接失敗）
        $base = array_merge($base, [
            '--retries', '10',
            '--retry-sleep', '3',
        ]);

        $base[] = $url;
        return $base;
    }

    /**
     * 執行 yt-dlp；若偵測到 YT 429 或驗證需求，嘗試後備：
     * 1) 更換 client: ios → tv
     * 2) 若已有 YouTube Cookie（Netscape），加入 --cookies 再試
     */
    private function runYtDlpWithFallback(array $args, int $timeoutSec, bool $isYT, bool $forPreview): array
    {
        $needYTCookie = false;

        // 第一次嘗試（已經是 android client）
        $r1 = $this->run($args, $timeoutSec);
        if ($r1['ok']) return $r1;

        if ($isYT && $this->looksLikeYTRateLimit($r1['stderr'])) {
            // 第二次嘗試：切換成 iOS client
            $args2 = $this->swapYTClient($args, 'ios');
            $r2 = $this->run($args2, $timeoutSec);
            if ($r2['ok']) return $r2;

            if ($this->looksLikeYTRateLimit($r2['stderr'])) {
                // 第三次：TV client
                $args3 = $this->swapYTClient($args, 'tv');
                $r3 = $this->run($args3, $timeoutSec);
                if ($r3['ok']) return $r3;
            }

            // 若仍失敗且有 YT Cookies 檔，帶 cookies 再試一次（用 ios client）
            if ($this->isValidGenericCookieFile($this->ytCookieFile)) {
                $needYTCookie = false;
                $args4 = $this->ensureArg($args, '--cookies', $this->ytCookieFile);
                $args4 = $this->swapYTClient($args4, 'ios');
                $r4 = $this->run($args4, $timeoutSec);
                if ($r4['ok']) return $r4;
            } else {
                $needYTCookie = true;
            }

            // 最後再嘗試一次：TV client + cookies（如果有）
            if ($this->isValidGenericCookieFile($this->ytCookieFile)) {
                $args5 = $this->ensureArg($args, '--cookies', $this->ytCookieFile);
                $args5 = $this->swapYTClient($args5, 'tv');
                $r5 = $this->run($args5, $timeoutSec);
                if ($r5['ok']) return $r5;
            }
        }

        return [
            'ok' => false,
            'stdout' => $r1['stdout'],
            'stderr' => $r1['stderr'],
            'needYTCookie' => $needYTCookie
        ];
    }

    private function run(array $args, int $timeoutSec): array
    {
        try {
            $process = new Process($args);
            $process->setTimeout($timeoutSec);
            $process->run();

            return [
                'ok' => $process->isSuccessful(),
                'stdout' => trim($process->getOutput()),
                'stderr' => trim($process->getErrorOutput()),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'stdout' => '',
                'stderr' => '執行例外：' . $e->getMessage(),
            ];
        }
    }

    private function looksLikeYTRateLimit(string $stderr): bool
    {
        $s = strtolower($stderr);
        if ($s === '') return false;
        return str_contains($s, 'http error 429')
            || str_contains($s, 'too many requests')
            || str_contains($s, 'confirm you’re not a bot')
            || str_contains($s, 'confirm youre not a bot')
            || str_contains($s, 'sign in to confirm you’re not a bot');
    }

    private function swapYTClient(array $args, string $client): array
    {
        $out = [];
        $skipNext = false;
        for ($i = 0; $i < count($args); $i++) {
            if ($skipNext) { $skipNext = false; continue; }
            if ($args[$i] === '--extractor-args' && isset($args[$i + 1]) && str_starts_with($args[$i + 1], 'youtube:player_client=')) {
                $out[] = '--extractor-args';
                $out[] = 'youtube:player_client=' . $client;
                $skipNext = true;
            } else {
                $out[] = $args[$i];
            }
        }
        // 若原本沒有，補上
        if (!in_array('--extractor-args', $out, true)) {
            $out[] = '--extractor-args';
            $out[] = 'youtube:player_client=' . $client;
        }
        return $out;
    }

    private function ensureArg(array $args, string $flag, string $value): array
    {
        // 若已存在該 flag，更新其值；否則追加
        $out = [];
        $replaced = false;
        for ($i = 0; $i < count($args); $i++) {
            if ($args[$i] === $flag) {
                $out[] = $flag;
                if (isset($args[$i + 1])) {
                    $out[] = $value;
                    $i++;
                } else {
                    $out[] = $value;
                }
                $replaced = true;
            } else {
                $out[] = $args[$i];
            }
        }
        if (!$replaced) {
            $out[] = $flag;
            $out[] = $value;
        }
        return $out;
    }

    private function splitUrls(string $output): array
    {
        $urls = preg_split("/\r\n|\n|\r/", $output) ?: [];
        return array_values(array_filter($urls, function ($u) {
            return preg_match('#^https?://#i', $u);
        }));
    }

    /* -------------------- 站點偵測/URL 正規化 -------------------- */

    private function isInstagramUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) return false;
        $host = strtolower($host);
        return (strpos($host, 'instagram.com') !== false);
    }

    private function isYouTubeUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) return false;
        $host = strtolower($host);
        return (strpos($host, 'youtube.com') !== false) || (strpos($host, 'youtu.be') !== false);
    }

    private function normalizeYouTubeWatchUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) return $url;

        // youtu.be 短連結轉換
        if (!empty($parts['host']) && !empty($parts['path'])) {
            $host = strtolower($parts['host']);
            if (strpos($host, 'youtu.be') !== false) {
                $videoId = ltrim($parts['path'], '/');
                if ($videoId !== '') {
                    return 'https://www.youtube.com/watch?v=' . $videoId;
                }
            }
        }

        // youtube.com/watch? 只保留 v、t
        $query = [];
        if (isset($parts['query'])) parse_str($parts['query'], $query);
        $keep = [];
        if (!empty($query['v'])) $keep['v'] = $query['v'];
        if (!empty($query['t'])) $keep['t'] = $query['t'];

        $base = 'https://www.youtube.com/watch';
        if (!empty($keep)) {
            return $base . '?' . http_build_query($keep);
        }
        return $url;
    }

    /* -------------------- Cookie 檔驗證/寫入 -------------------- */

    private function ensureCookiesDir(): void
    {
        $dir = dirname($this->igSessionFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }

    private function isValidIGCookieFile(string $path): bool
    {
        if (!is_file($path)) return false;
        $content = file_get_contents($path);
        if ($content === false) return false;
        if (strpos($content, 'Netscape HTTP Cookie File') === false) return false;

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || (strlen($line) > 0 && $line[0] === '#')) continue;
            $parts = explode("\t", $line);
            if (count($parts) === 7 && $parts[5] === 'sessionid' && $parts[6] !== '') {
                return true;
            }
        }
        return false;
    }

    private function isValidGenericCookieFile(string $path): bool
    {
        if (!is_file($path)) return false;
        $content = file_get_contents($path);
        if ($content === false) return false;
        if (strpos($content, 'Netscape HTTP Cookie File') === false) return false;

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || (strlen($line) > 0 && $line[0] === '#')) continue;
            $parts = explode("\t", $line);
            if (count($parts) === 7 && $parts[5] !== '' && $parts[6] !== '') {
                return true;
            }
        }
        return false;
    }

    private function ensureValidIGCookieFile(): bool
    {
        if ($this->isValidIGCookieFile($this->igSessionFile)) {
            return true;
        }

        if (!is_file($this->igSessionFile)) {
            return false;
        }

        $raw = trim((string) file_get_contents($this->igSessionFile));
        if ($raw === '') {
            return false;
        }

        $sessionId = $this->extractSessionId($raw);
        if ($sessionId) {
            $this->ensureCookiesDir();
            $this->writeNetscapeIG($sessionId);
            return $this->isValidIGCookieFile($this->igSessionFile);
        }

        return false;
    }

    private function extractSessionId(string $input): ?string
    {
        $trimmed = trim($input, " \t\n\r\0\x0B;");

        if (stripos($trimmed, 'sessionid=') !== false) {
            if (preg_match('/sessionid=([^;]+)/i', $trimmed, $m)) {
                return urldecode(trim($m[1]));
            }
        }

        if ($trimmed !== '' && !str_contains($trimmed, ' ') && !str_contains($trimmed, ';')) {
            return urldecode($trimmed);
        }

        return null;
    }

    private function parseCookiePairs(string $input): array
    {
        // 將 "name=value; name2=value2" 轉為鍵值陣列
        $pairs = [];
        $segments = array_filter(array_map('trim', explode(';', $input)));
        foreach ($segments as $seg) {
            if ($seg === '') continue;
            $eqPos = strpos($seg, '=');
            if ($eqPos === false) continue;
            $name = trim(substr($seg, 0, $eqPos));
            $val  = trim(substr($seg, $eqPos + 1));
            if ($name !== '' && $val !== '') {
                $pairs[$name] = urldecode($val);
            }
        }
        return $pairs;
    }

    private function writeNetscapeIG(string $sessionId): void
    {
        $content = "# Netscape HTTP Cookie File\n";
        $content .= ".instagram.com\tTRUE\t/\tTRUE\t0\tsessionid\t{$sessionId}\n";
        file_put_contents($this->igSessionFile, $content, LOCK_EX);
    }

    private function writeNetscapeForDomain(string $domain, array $pairs, string $filePath): void
    {
        $content = "# Netscape HTTP Cookie File\n";
        foreach ($pairs as $k => $v) {
            $content .= "{$domain}\tTRUE\t/\tTRUE\t0\t{$k}\t{$v}\n";
        }
        file_put_contents($filePath, $content, LOCK_EX);
    }
}
