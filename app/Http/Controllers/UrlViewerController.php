<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Response;

class UrlViewerController extends Controller
{
    private $igSessionFile;
    private $ytCookieFile;
    private $threadsCookieFile;

    public function __construct()
    {
        $this->igSessionFile       = storage_path("app/cookies/ig_session.txt");
        $this->ytCookieFile        = storage_path("app/cookies/youtube_cookies.txt");
        $this->threadsCookieFile   = storage_path("app/cookies/threads_cookies.txt");
    }

    public function index()
    {
        $hasIGSession   = $this->ensureValidIGCookieFile();
        $hasYTCookie    = $this->isValidGenericCookieFile($this->ytCookieFile);
        $hasThreadsCook = $this->isValidGenericCookieFile($this->threadsCookieFile);
        return view('url_viewer', [
            'hasSession'     => $hasIGSession,
            'hasYTCookie'    => $hasYTCookie,
            'hasThreadsCook' => $hasThreadsCook,
        ]);
    }

    /**
     * 儲存 Cookie：
     * site=ig       -> 接受 sessionid 或整串 Cookie，寫成 IG Netscape（僅 sessionid）
     * site=yt       -> 接受整串 Cookie（name=value;...），轉為 .youtube.com Netscape
     * site=threads  -> 接受整串 Cookie（name=value;...），轉為 .threads.net Netscape
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

        if ($site === 'threads') {
            $pairs = $this->parseCookiePairs($raw);
            if (empty($pairs)) {
                return response()->json(['success' => false, 'error' => '請貼上有效的 Threads Cookie（name=value; 形式）']);
            }
            $this->ensureCookiesDir();
            $this->writeNetscapeForDomain('.threads.net', $pairs, $this->threadsCookieFile);
            if (!$this->isValidGenericCookieFile($this->threadsCookieFile)) {
                return response()->json(['success' => false, 'error' => '寫入 Threads Cookie 檔失敗，格式驗證未通過']);
            }
            return response()->json(['success' => true, 'message' => 'Threads Cookies 已儲存 (Netscape 格式)']);
        }

        return response()->json(['success' => false, 'error' => '未知的 site 參數']);
    }

    /**
     * 抓影片直連 URL (預覽)
     */
    public function fetch(Request $request)
    {
        $rawUrl = trim((string) $request->input('url'));
        if ($rawUrl === '') {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        // 站點偵測 + 正規化
        $url = $this->normalizeAll($rawUrl);

        $isIG     = $this->isInstagramUrl($url);
        $isYT     = $this->isYouTubeUrl($url);
        $isBili   = $this->isBilibiliUrl($url);
        $isThreads= $this->isThreadsUrl($url);

        if ($isIG && !$this->isValidIGCookieFile($this->igSessionFile)) {
            return response()->json([
                'success' => false,
                'error' => 'Instagram 需要有效的 sessionid，請先輸入。',
                'needSession' => true
            ]);
        }

        // Threads：先用自家解析器抓直鏈
        if ($isThreads) {
            $urls = $this->extractThreadsVideoUrls($url);
            if (!empty($urls)) {
                return response()->json(['success' => true, 'urls' => $urls]);
            }
            // 解析不到就提示 Cookie
            return response()->json([
                'success' => false,
                'error' => 'Threads 需要 Cookies 或該貼文無法匿名存取，請貼上 Threads Cookies 後再試。',
                'needThreadsCookie' => true
            ]);
        }

        // 其他站點交給 yt-dlp
        if ($isYT)   { $url = $this->normalizeYouTubeWatchUrl($url); }
        if ($isBili) { $url = $this->normalizeBilibiliUrl($url); }

        $args = $this->buildArgsBase($url, $isIG, $isYT, $isBili, false, true);
        $run  = $this->runYtDlpWithFallback($args, 120, $isYT);

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
        $rawUrl = trim((string) $request->query('url'));
        if ($rawUrl === '') {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        $url = $this->normalizeAll($rawUrl);

        $isIG      = $this->isInstagramUrl($url);
        $isYT      = $this->isYouTubeUrl($url);
        $isBili    = $this->isBilibiliUrl($url);
        $isThreads = $this->isThreadsUrl($url);

        if ($isIG && !$this->isValidIGCookieFile($this->igSessionFile)) {
            return response()->json([
                'success' => false,
                'error' => 'Instagram 需要有效的 sessionid，請先輸入再下載。',
                'needSession' => true
            ]);
        }

        if ($isYT)   { $url = $this->normalizeYouTubeWatchUrl($url); }
        if ($isBili) { $url = $this->normalizeBilibiliUrl($url); }

        $fileName = "video_" . date("Ymd_His") . ".mp4";
        $filePath = storage_path("app/public/" . $fileName);

        // Threads：先取直鏈，再交給 yt-dlp 下載（支援 m3u8/mp4）
        if ($isThreads) {
            $directs = $this->extractThreadsVideoUrls($url);
            if (empty($directs)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Threads 無法取得直鏈，可能需要 Cookies。',
                    'needThreadsCookie' => true
                ]);
            }

            // 取最優先的直鏈（mp4 優先，其次 m3u8）
            $dlUrl = $directs[0];

            $args = [
                'yt-dlp',
                '--ignore-config',
                '--force-ipv4',
                '--no-playlist',
                '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                '--add-header', 'Referer: https://www.threads.net',
                '--user-agent', $this->desktopUA(),
                '-o', $filePath,
                '--merge-output-format', 'mp4',
                $dlUrl
            ];

            // 若有 Threads Cookies，帶入
            if ($this->isValidGenericCookieFile($this->threadsCookieFile)) {
                $args = array_merge(['yt-dlp', '--ignore-config'], [
                    '--force-ipv4',
                    '--no-playlist',
                    '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                    '--add-header', 'Referer: https://www.threads.net',
                    '--user-agent', $this->desktopUA(),
                    '--cookies', $this->threadsCookieFile,
                    '-o', $filePath,
                    '--merge-output-format', 'mp4',
                    $dlUrl
                ]);
            }

            $r = $this->run($args, 420);
            if (!$r['ok']) {
                return response()->json(['success' => false, 'error' => $r['stderr']]);
            }

            if (!is_file($filePath)) {
                return response()->json(['success' => false, 'error' => '下載失敗，找不到輸出檔案']);
            }

            return Response::download($filePath)->deleteFileAfterSend(true);
        }

        // 其他站點：交給 yt-dlp
        $args = $this->buildArgsBase($url, $isIG, $isYT, $isBili, true, false);
        $args = array_merge($args, ['--merge-output-format', 'mp4', '-o', $filePath]);

        $run = $this->runYtDlpWithFallback($args, 420, $isYT);
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

    private function buildArgsBase(string $url, bool $isIG, bool $isYT, bool $isBili, bool $forDownload, bool $forPreview): array
    {
        $base = [
            'yt-dlp',
            '--ignore-config',
            '--force-ipv4',
            '--no-playlist',
            '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
        ];

        // 預設 UA：Android 對 YT 較友善
        $ua = $this->androidUA();

        // YT：指定 client
        if ($isYT) {
            $base = array_merge($base, ['--extractor-args', 'youtube:player_client=android']);
        }

        // Bilibili：用桌面 UA + 必要標頭
        if ($isBili) {
            $ua = $this->desktopUA();
            $base = array_merge($base, [
                '--add-header', 'Referer: https://www.bilibili.com',
                '--add-header', 'Origin: https://www.bilibili.com',
            ]);
        }

        // IG：僅 IG 連結才帶 cookies
        if ($isIG) {
            $base = array_merge($base, ['--cookies', $this->igSessionFile]);
        }

        $base = array_merge($base, ['--user-agent', $ua]);

        // 預覽/下載格式
        if ($forPreview) {
            $base = array_merge($base, ['-f', 'bestvideo+bestaudio/best', '-g']);
        } else {
            $base = array_merge($base, ['-f', 'bestvideo+bestaudio/best']);
        }

        // 重試
        $base = array_merge($base, ['--retries', '10', '--retry-sleep', '3']);

        $base[] = $url;
        return $base;
    }

    /**
     * 針對 YouTube 的 429/驗證後備。
     */
    private function runYtDlpWithFallback(array $args, int $timeoutSec, bool $isYT): array
    {
        $needYTCookie = false;

        $r1 = $this->run($args, $timeoutSec);
        if ($r1['ok']) return $r1;

        if ($isYT && $this->looksLikeYTRateLimit($r1['stderr'])) {
            // iOS
            $args2 = $this->swapYTClient($args, 'ios');
            $r2 = $this->run($args2, $timeoutSec);
            if ($r2['ok']) return $r2;

            // TV
            if ($this->looksLikeYTRateLimit($r2['stderr'])) {
                $args3 = $this->swapYTClient($args, 'tv');
                $r3 = $this->run($args3, $timeoutSec);
                if ($r3['ok']) return $r3;
            }

            // 加 Cookies 再試
            if ($this->isValidGenericCookieFile($this->ytCookieFile)) {
                $needYTCookie = false;
                $args4 = $this->ensureArg($args, '--cookies', $this->ytCookieFile);
                $args4 = $this->swapYTClient($args4, 'ios');
                $r4 = $this->run($args4, $timeoutSec);
                if ($r4['ok']) return $r4;

                $args5 = $this->swapYTClient($args4, 'tv');
                $r5 = $this->run($args5, $timeoutSec);
                if ($r5['ok']) return $r5;
            } else {
                $needYTCookie = true;
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
        if (!in_array('--extractor-args', $out, true)) {
            $out[] = '--extractor-args';
            $out[] = 'youtube:player_client=' . $client;
        }
        return $out;
    }

    private function ensureArg(array $args, string $flag, string $value): array
    {
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

    /* -------------------- Threads 專用：正規化 + 直鏈解析 -------------------- */

    private function isThreadsUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) return false;
        $h = strtolower($host);
        return strpos($h, 'threads.net') !== false || strpos($h, 'threads.com') !== false;
    }

    private function normalizeThreadsUrl(string $url): string
    {
        // threads.com -> threads.net
        $url = preg_replace('#://(www\.)?threads\.com/#i', '://www.threads.net/', $url);
        // 沒有 www 的加上 www（避免某些跨網域導致 Referer 檢查）
        $url = preg_replace('#://threads\.net/#i', '://www.threads.net/', $url);
        return $url;
    }

    private function extractThreadsVideoUrls(string $url): array
    {
        $url = $this->normalizeThreadsUrl($url);

        $headers = [
            'User-Agent: ' . $this->desktopUA(),
            'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: https://www.threads.net/',
        ];

        // 若有 Threads Cookies，附上
        $cookieHeader = $this->buildCookieHeaderFromNetscape($this->threadsCookieFile, 'www.threads.net');
        if ($cookieHeader !== '') {
            $headers[] = 'Cookie: ' . $cookieHeader;
        }

        $html = $this->curlGet($url, $headers);
        if ($html === null) {
            return [];
        }

        $candidates = [];

        // 1) og:video / og:video:url
        if (preg_match_all('#<meta[^>]+property=["\']og:video(?::url)?["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            foreach ($m[1] as $u) $candidates[] = $u;
        }

        // 2) JSON 內常見欄位：video_url、video_versions[].url、contentUrl
        $patterns = [
            '#"video_url"\s*:\s*"([^"]+)"#i',
            '#"video_versions"\s*:\s*\[\s*{[^}]*"url"\s*:\s*"([^"]+)"#i',
            '#"contentUrl"\s*:\s*"([^"]+)"#i',
            '#"playable_url"\s*:\s*"([^"]+)"#i',
            '#"url"\s*:\s*"([^"]+?\.mp4[^"]*)"#i',
            '#"src"\s*:\s*"([^"]+?\.mp4[^"]*)"#i',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $html, $m2)) {
                foreach ($m2[1] as $u) $candidates[] = $u;
            }
        }

        // 3) m3u8（必要時當作備援）
        if (preg_match_all('#https?://[^"\']+?\.m3u8[^"\']*#i', $html, $m3)) {
            foreach ($m3[0] as $u) $candidates[] = $u;
        }

        // 處理跳脫字元與去重
        $candidates = array_values(array_unique(array_map(function ($u) {
            $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $u = str_replace(['\\u002F', '\\/'], '/', $u);
            return $u;
        }, $candidates)));

        // 優先回傳 mp4，再回傳 m3u8
        $mp4 = array_values(array_filter($candidates, fn($u) => stripos($u, '.mp4') !== false));
        $m3u8 = array_values(array_filter($candidates, fn($u) => stripos($u, '.m3u8') !== false));

        if (!empty($mp4)) return $mp4;
        if (!empty($m3u8)) return $m3u8;
        return [];
    }

    private function curlGet(string $url, array $headers): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        if (!is_string($body) || $body === '') {
            return null;
        }
        return $body;
    }

    private function buildCookieHeaderFromNetscape(string $file, string $host): string
    {
        if (!$this->isValidGenericCookieFile($file)) return '';
        $host = ltrim(strtolower($host), '.');
        $content = file_get_contents($file);
        if ($content === false) return '';
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        $pairs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || (isset($line[0]) && $line[0] === '#')) continue;
            $parts = explode("\t", $line);
            if (count($parts) === 7) {
                $domain = ltrim(strtolower($parts[0]), '.');
                $name   = $parts[5];
                $value  = $parts[6];
                if ($name === '' || $value === '') continue;
                // host 尾段比對
                if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                    $pairs[$name] = $value;
                }
            }
        }
        if (empty($pairs)) return '';
        $chunks = [];
        foreach ($pairs as $k => $v) {
            $chunks[] = $k . '=' . $v;
        }
        return implode('; ', $chunks);
    }

    /* -------------------- 站點偵測/URL 正規化 -------------------- */

    private function normalizeAll(string $url): string
    {
        // Threads 正規化要最先做（避免 .com）
        if ($this->isThreadsUrl($url)) {
            $url = $this->normalizeThreadsUrl($url);
        }
        return $url;
    }

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

    private function isBilibiliUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) return false;
        $host = strtolower($host);
        return (strpos($host, 'bilibili.com') !== false) || (strpos($host, 'b23.tv') !== false);
    }

    private function normalizeYouTubeWatchUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) return $url;

        if (!empty($parts['host']) && !empty($parts['path'])) {
            $host = strtolower($parts['host']);
            if (strpos($host, 'youtu.be') !== false) {
                $videoId = ltrim($parts['path'], '/');
                if ($videoId !== '') {
                    return 'https://www.youtube.com/watch?v=' . $videoId;
                }
            }
        }

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

    private function normalizeBilibiliUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) return $url;

        $scheme = $parts['scheme'] ?? 'https';
        $host   = strtolower($parts['host'] ?? '');
        $path   = $parts['path'] ?? '';
        $query  = [];
        if (isset($parts['query'])) parse_str($parts['query'], $query);

        if (strpos($host, 'b23.tv') !== false) {
            return $url;
        }

        if (strpos($host, 'bilibili.com') !== false) {
            $host = 'www.bilibili.com';
        }

        if (preg_match('#^/video/(BV[0-9A-Za-z]+)#', $path, $m)) {
            $bv = $m[1];
            $keep = [];
            if (!empty($query['p'])) {
                $keep['p'] = (int) $query['p'];
            }
            $normalized = $scheme . '://' . $host . '/video/' . $bv . '/';
            if (!empty($keep)) {
                $normalized .= '?' . http_build_query($keep);
            }
            return $normalized;
        }

        return $scheme . '://' . $host . $path . (isset($parts['query']) ? ('?' . $parts['query']) : '');
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

    /* -------------------- UA -------------------- */

    private function androidUA(): string
    {
        return 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';
    }

    private function desktopUA(): string
    {
        return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    }

    /* -------------------- 工具 -------------------- */

    private function splitUrls(string $output): array
    {
        $urls = preg_split("/\r\n|\n|\r/", $output) ?: [];
        return array_values(array_filter($urls, function ($u) {
            return preg_match('#^https?://#i', $u);
        }));
    }
}
