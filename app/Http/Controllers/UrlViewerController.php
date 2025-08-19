<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UrlViewerController extends Controller
{
    private $igSessionFile;
    private $ytCookieFile;
    private $threadsCookieFile;     // .threads.net
    private $threadsComCookieFile;  // .threads.com

    public function __construct()
    {
        $this->igSessionFile         = storage_path("app/cookies/ig_session.txt");
        $this->ytCookieFile          = storage_path("app/cookies/youtube_cookies.txt");
        $this->threadsCookieFile     = storage_path("app/cookies/threads_cookies.txt");       // .threads.net
        $this->threadsComCookieFile  = storage_path("app/cookies/threads_com_cookies.txt");   // .threads.com
    }

    public function index()
    {
        $hasIGSession   = $this->ensureValidIGCookieFile();
        $hasYTCookie    = $this->isValidGenericCookieFile($this->ytCookieFile);
        $hasThreadsCook = $this->isValidGenericCookieFile($this->threadsCookieFile)
            || $this->isValidGenericCookieFile($this->threadsComCookieFile);

        return view('url_viewer', [
            'hasSession'     => $hasIGSession,
            'hasYTCookie'    => $hasYTCookie,
            'hasThreadsCook' => $hasThreadsCook,
        ]);
    }

    /**
     * 儲存 Cookie：
     * site=ig       -> 接受整串 Cookies 或純 sessionid；會把所有 pair（含 csrftoken 等）寫入 .instagram.com 檔
     * site=yt       -> 接受整串 Cookies，寫入 .youtube.com 檔
     * site=threads  -> 接受整串 Cookies，寫入 .threads.net 與 .threads.com，並「同步同一批」寫入 IG 檔（常見需求）
     */
    public function saveSession(Request $request)
    {
        $site = strtolower((string) $request->input('site', 'ig'));
        $raw  = trim((string) $request->input('session'));

        if ($raw === '') {
            return response()->json(['success' => false, 'error' => '輸入不能為空']);
        }

        if ($site === 'ig') {
            $pairs = $this->parseCookiePairs($raw);
            if (empty($pairs)) {
                $sessionId = $this->extractSessionId($raw);
                if (!$sessionId) {
                    return response()->json(['success' => false, 'error' => '請貼上有效的 IG Cookies 或 sessionid']);
                }
                $pairs = ['sessionid' => $sessionId];
            }
            $this->ensureCookiesDir();
            $this->writeNetscapeIGFromPairs($pairs);
            if (!$this->isValidIGCookieFile($this->igSessionFile)) {
                return response()->json(['success' => false, 'error' => '寫入 IG Cookie 檔失敗，格式驗證未通過']);
            }
            return response()->json(['success' => true, 'message' => 'Instagram Cookies 已儲存（含 sessionid/csrftoken）']);
        }

        if ($site === 'yt') {
            $pairs = $this->parseCookiePairs($raw);
            if (empty($pairs)) {
                return response()->json(['success' => false, 'error' => '請貼上有效的 YouTube Cookies（name=value; 形式）']);
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
                return response()->json(['success' => false, 'error' => '請貼上有效的 Threads/IG Cookies（name=value; 形式）']);
            }
            $this->ensureCookiesDir();
            // 1) 寫入 .threads.net
            $this->writeNetscapeForDomain('.threads.net', $pairs, $this->threadsCookieFile);
            // 2) 也寫入 .threads.com（避免被轉 .com 時 Cookie 不生效）
            $this->writeNetscapeForDomain('.threads.com', $pairs, $this->threadsComCookieFile);
            // 3) 同步同一批到 IG（確保有 csrftoken 等）
            $this->writeNetscapeIGFromPairs($pairs);

            if (!$this->isValidGenericCookieFile($this->threadsCookieFile)
                && !$this->isValidGenericCookieFile($this->threadsComCookieFile)) {
                return response()->json(['success' => false, 'error' => '寫入 Threads Cookie 檔失敗，格式驗證未通過']);
            }
            return response()->json(['success' => true, 'message' => 'Threads Cookies 已儲存（net/com 雙檔），並已同步至 Instagram 檔案。']);
        }

        return response()->json(['success' => false, 'error' => '未知的 site 參數']);
    }

    /**
     * 抓影片直連 URL (預覽)
     */
    public function fetch(Request $request)
    {
        $rawUrl = trim((string) $request->input('url'));
        $debug  = (bool)$request->input('debug', false) || (bool)env('THREADS_DEBUG', false);
        $trace  = Str::uuid()->toString();

        if ($rawUrl === '') {
            return response()->json(['success' => false, 'error' => '缺少 URL']);
        }

        // 站點偵測 + 正規化
        $url = $this->normalizeAll($rawUrl);

        $isIG      = $this->isInstagramUrl($url);
        $isYT      = $this->isYouTubeUrl($url);
        $isBili    = $this->isBilibiliUrl($url);
        $isThreads = $this->isThreadsUrl($url);

        if ($isIG && !$this->isValidIGCookieFile($this->igSessionFile)) {
            return response()->json([
                'success' => false,
                'error' => 'Instagram 需要有效的 Cookies（至少 sessionid 與 csrftoken），請先於上方儲存。',
                'needSession' => true,
                'traceId' => $trace
            ]);
        }

        // Threads：優先用「免登入 GraphQL 後備」+ HTML/yt-dlp 多重嘗試
        if ($isThreads) {
            $res = $this->extractThreadsVideoUrlsDetailed($url, $debug, $trace);
            if (!empty($res['urls'])) {
                $payload = ['success' => true, 'urls' => $res['urls'], 'traceId' => $trace];
                if ($debug) $payload['diag'] = $res['diag'];
                return response()->json($payload);
            }
            $payload = [
                'success' => false,
                'error' => $res['diag']['reason'] ?? 'Threads 仍無法取得直鏈',
                'needThreadsCookie' => $res['diag']['hints']['mightNeedCookies'] ?? false,
                'traceId' => $trace
            ];
            if ($debug) $payload['diag'] = $res['diag'];
            return response()->json($payload);
        }

        // 其他站點交給 yt-dlp
        if ($isYT)   { $url = $this->normalizeYouTubeWatchUrl($url); }
        if ($isBili) { $url = $this->normalizeBilibiliUrl($url); }

        $args = $this->buildArgsBase($url, $isIG, $isYT, $isBili, false, true);
        $run  = $this->runYtDlpWithFallback($args, 140, $isYT);

        if (!$run['ok']) {
            $resp = ['success' => false, 'error' => $run['stderr'], 'traceId' => $trace];
            if ($isYT && $run['needYTCookie']) {
                $resp['needYTCookie'] = true;
            }
            return response()->json($resp);
        }

        $urls = $this->splitUrls($run['stdout']);
        if (empty($urls)) {
            return response()->json(['success' => false, 'error' => '未取得可播放的直連 URL', 'traceId' => $trace]);
        }

        return response()->json(['success' => true, 'urls' => $urls, 'traceId' => $trace]);
    }

    /**
     * 下載影片（含聲音）
     */
    public function download(Request $request)
    {
        $rawUrl = trim((string) $request->query('url'));
        $debug  = (bool)$request->query('debug', false) || (bool)env('THREADS_DEBUG', false);
        $trace  = Str::uuid()->toString();

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
                'error' => 'Instagram 需要有效的 Cookies（至少 sessionid 與 csrftoken），請先於上方儲存。',
                'needSession' => true,
                'traceId' => $trace
            ]);
        }

        if ($isYT)   { $url = $this->normalizeYouTubeWatchUrl($url); }
        if ($isBili) { $url = $this->normalizeBilibiliUrl($url); }

        $fileName = "video_" . date("Ymd_His") . ".mp4";
        $filePath = storage_path("app/public/" . $fileName);

        if ($isThreads) {
            $res = $this->extractThreadsVideoUrlsDetailed($url, $debug, $trace);
            $directs = $res['urls'];
            if (empty($directs)) {
                $payload = [
                    'success' => false,
                    'error' => 'Threads 無法取得直鏈（未登入模式失敗）。可嘗試貼上 Cookies 後再試。',
                    'needThreadsCookie' => true,
                    'traceId' => $trace
                ];
                if ($debug) $payload['diag'] = $res['diag'];
                return response()->json($payload);
            }
            $dlUrl = $directs[0];

            $args = [
                'yt-dlp', '--ignore-config', '--force-ipv4', '--no-playlist',
                '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                '--add-header', 'Referer: https://www.threads.net',
                '--add-header', 'X-IG-App-ID: 238260118697367',
                '--user-agent', $this->desktopUA(),
                '-o', $filePath, '--merge-output-format', 'mp4',
                $dlUrl
            ];

            // 若使用者仍提供 Cookies，就一起帶（但不是必要條件）
            $cookieHeader = $this->buildThreadsCombinedCookieHeader();
            if ($cookieHeader !== '') {
                $args = [
                    'yt-dlp', '--ignore-config', '--force-ipv4', '--no-playlist',
                    '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                    '--add-header', 'Referer: https://www.threads.net',
                    '--add-header', 'X-IG-App-ID: 238260118697367',
                    '--add-header', 'Cookie: ' . $cookieHeader,
                    '--user-agent', $this->desktopUA(),
                    '-o', $filePath, '--merge-output-format', 'mp4',
                    $dlUrl
                ];
            }

            $r = $this->run($args, 420);
            if (!$r['ok']) {
                if ($debug) {
                    Log::warning('[THREADS][DL]['.$trace.'] yt-dlp error', ['stderr' => $r['stderr']]);
                }
                return response()->json(['success' => false, 'error' => $r['stderr'], 'traceId' => $trace]);
            }
            if (!is_file($filePath)) {
                return response()->json(['success' => false, 'error' => '下載失敗，找不到輸出檔案', 'traceId' => $trace]);
            }
            return Response::download($filePath)->deleteFileAfterSend(true);
        }

        $args = $this->buildArgsBase($url, $isIG, $isYT, $isBili, true, false);
        $args = array_merge($args, ['--merge-output-format', 'mp4', '-o', $filePath]);

        $run = $this->runYtDlpWithFallback($args, 420, $isYT);
        if (!$run['ok']) {
            $resp = ['success' => false, 'error' => $run['stderr'], 'traceId' => $trace];
            if ($isYT && $run['needYTCookie']) {
                $resp['needYTCookie'] = true;
            }
            return response()->json($resp);
        }

        if (!is_file($filePath)) {
            return response()->json(['success' => false, 'error' => '下載失敗，找不到輸出檔案', 'traceId' => $trace]);
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

        $ua = $this->androidUA();

        if ($isYT) {
            $base = array_merge($base, ['--extractor-args', 'youtube:player_client=android']);
        }

        if ($isBili) {
            $ua = $this->desktopUA();
            $base = array_merge($base, [
                '--add-header', 'Referer: https://www.bilibili.com',
                '--add-header', 'Origin: https://www.bilibili.com',
            ]);
        }

        if ($isIG) {
            $base = array_merge($base, [
                '--cookies', $this->igSessionFile,
                '--add-header', 'Referer: https://www.instagram.com',
            ]);
        }

        $base = array_merge($base, ['--user-agent', $ua]);

        if ($forPreview) {
            $base = array_merge($base, ['-f', 'bestvideo+bestaudio/best', '-g']);
        } else {
            $base = array_merge($base, ['-f', 'bestvideo+bestaudio/best']);
        }

        $base = array_merge($base, ['--retries', '10', '--retry-sleep', '3']);
        $base[] = $url;
        return $base;
    }

    private function runYtDlpWithFallback(array $args, int $timeoutSec, bool $isYT): array
    {
        $needYTCookie = false;

        $r1 = $this->run($args, $timeoutSec);
        if ($r1['ok']) return $r1;

        if ($isYT && $this->looksLikeYTRateLimit($r1['stderr'])) {
            $args2 = $this->swapYTClient($args, 'ios');
            $r2 = $this->run($args2, $timeoutSec);
            if ($r2['ok']) return $r2;

            if ($this->looksLikeYTRateLimit($r2['stderr'])) {
                $args3 = $this->swapYTClient($args, 'tv');
                $r3 = $this->run($args3, $timeoutSec);
                if ($r3['ok']) return $r3;
            }

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

    /* -------------------- Threads：解析（HTML → GraphQL 免登入 → yt-dlp） -------------------- */

    private function isThreadsUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host)) return false;
        $h = strtolower($host);
        return str_contains($h, 'threads.net') || str_contains($h, 'threads.com');
    }

    private function normalizeThreadsUrl(string $url): string
    {
        // 保留使用者原網域，讓伺服器自由 301；我們支援 .net / .com
        // 這裡只把 threads.com 沒有 www 的補上 www
        $url = preg_replace('#://threads\.com/#i', '://www.threads.com/', $url);
        $url = preg_replace('#://threads\.net/#i', '://www.threads.net/', $url);
        $url = preg_replace('#://(www\.)?threads\.com$#i', '://www.threads.com/', $url);
        $url = preg_replace('#://(www\.)?threads\.net$#i', '://www.threads.net/', $url);
        return $url;
    }

    private function extractThreadsVideoUrlsDetailed(string $url, bool $debug, string $trace): array
    {
        $url = $this->normalizeThreadsUrl($url);
        $cookieHeader = $this->buildThreadsCombinedCookieHeader(); // 若沒有，也會繼續嘗試免登入

        $diag = [
            'traceId' => $trace,
            'target' => $url,
            'cookies' => [
                'hasThreadsNetFile' => is_file($this->threadsCookieFile),
                'hasThreadsComFile' => is_file($this->threadsComCookieFile),
                'hasIGFile' => is_file($this->igSessionFile),
                'combinedLength' => strlen($cookieHeader),
            ],
            'steps' => [],
            'reason' => '',
            'hints' => [
                'mightNeedCookies' => false,
                'ipv6OrHttp2Issue' => false,
            ],
        ];

        $headers = [
            'User-Agent: ' . $this->desktopUA(),
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer: https://www.threads.net/',
        ];
        if ($cookieHeader !== '') $headers[] = 'Cookie: ' . $cookieHeader;

        // Step 1: 原頁（可能 301 → .com，可能登入牆）
        $main = $this->curlFetchDetailed($url, $headers, $trace);
        $diag['steps'][] = $this->diagFromCurl('main', $url, $main);
        if ($debug) Log::info('[THREADS]['.$trace.'] MAIN fetch', $diag['steps'][count($diag['steps'])-1]);

        $cand1 = $this->pickVideoUrlsFromHtml($main['body'] ?? null);
        $analysis1 = $this->analyzeThreadsHtml($main['body'] ?? null);
        $diag['steps'][count($diag['steps'])-1]['analysis'] = $analysis1;

        // Step 2: /embed（有時可直接出 og:video）
        $cand = $cand1;
        if (empty($cand)) {
            $embedUrl = rtrim($url, '/') . '/embed';
            $embed = $this->curlFetchDetailed($embedUrl, $headers, $trace);
            $diag['steps'][] = $this->diagFromCurl('embed', $embedUrl, $embed);
            if ($debug) Log::info('[THREADS]['.$trace.'] EMBED fetch', $diag['steps'][count($diag['steps'])-1]);

            $cand2 = $this->pickVideoUrlsFromHtml($embed['body'] ?? null);
            $analysis2 = $this->analyzeThreadsHtml($embed['body'] ?? null);
            $diag['steps'][count($diag['steps'])-1]['analysis'] = $analysis2;
            $cand = $cand2;
        }

        // 如果 HTML 命中就回傳
        if (!empty($cand)) {
            $cand = $this->cleanupMediaList($cand);
            $urls = $this->preferMp4ThenM3u8($cand);
            if (!empty($urls)) {
                $diag['reason'] = 'HTML 中已找到媒體 URL';
                return ['urls' => $urls, 'diag' => $diag];
            }
        }

        // Step 3: 免登入 GraphQL 後備（關鍵）
        $lsd = $this->extractLsdTokenFromHtml($main['body'] ?? '')
            ?: $this->extractLsdTokenFromHtml($diag['steps'][0]['bodySample'] ?? '')
                ?: null;

        // 也嘗試從 /embed 抓 LSD
        if (!$lsd && isset($diag['steps'][1]['bodySample'])) {
            $lsd = $this->extractLsdTokenFromHtml($diag['steps'][1]['bodySample']);
        }

        $docIds = $this->findGraphQLDocIds($main['body'] ?? '');
        if (empty($docIds) && isset($diag['steps'][1]['bodySample'])) {
            $docIds = $this->findGraphQLDocIds($diag['steps'][1]['bodySample']);
        }

        // 若頁面沒露出 doc_id，試內建常見 doc_id（會持續嘗試，不保證每次都中，但多數版本有效）
        if (empty($docIds)) {
            $docIds = [
                // Barcelona / Threads Post Page / Post Detail 常見 doc_id 候補（可能更新；留多個保險）
                '6730361129922562',
                '9553316661403441',
                '8845750582119845',
                '9392364588730776',
                '9440461789398731',
            ];
        }

        $postCode = $this->extractThreadsCodeFromUrl($url); // 例如 DNf0V2MT19b
        $graphqlTried = [];
        $graphqlFound = [];

        if ($lsd && $postCode) {
            foreach ($docIds as $doc) {
                $variablesList = $this->buildGraphQLVariablesCandidates($postCode, $url);
                foreach ($variablesList as $vars) {
                    $out = $this->callThreadsGraphQL($lsd, $doc, $vars, $cookieHeader, $trace);
                    $graphqlTried[] = ['doc_id' => $doc, 'vars' => $vars, 'status' => $out['status']];
                    if (is_array($out['json'])) {
                        $urls = $this->deepFindMediaUrls($out['json']);
                        if (!empty($urls)) {
                            $graphqlFound = $this->preferMp4ThenM3u8($urls);
                            if (!empty($graphqlFound)) break 2;
                        }
                    } elseif (is_string($out['body'])) {
                        // 有些情況會直接給 m3u8 文字，做最後掃描
                        $urls = $this->pickVideoUrlsFromHtml($out['body']);
                        if (!empty($urls)) {
                            $graphqlFound = $this->preferMp4ThenM3u8($urls);
                            if (!empty($graphqlFound)) break 2;
                        }
                    }
                }
            }
        }

        $diag['steps'][] = [
            'step' => 'graphql',
            'lsdFound' => (bool)$lsd,
            'docIds' => array_values(array_unique($docIds)),
            'tried' => $graphqlTried,
            'found' => array_slice($graphqlFound, 0, 3),
        ];

        if (!empty($graphqlFound)) {
            $diag['reason'] = '以 GraphQL（免登入）取得媒體 URL';
            return ['urls' => $graphqlFound, 'diag' => $diag];
        }

        // Step 4: yt-dlp 後備（有時新版 yt-dlp 能解 Threads）
        $viaYt = $this->extractThreadsViaYtDlp($url, $cookieHeader, $trace, $debug);
        $diag['steps'][] = ['step' => 'yt-dlp', 'found' => count($viaYt), 'urls' => array_slice($viaYt, 0, 3)];
        if (!empty($viaYt)) {
            $diag['reason'] = '以 yt-dlp -g 取得直鏈';
            return ['urls' => $viaYt, 'diag' => $diag];
        }

        // 判斷可能原因
        $diag['reason'] = $this->detectThreadsFailureReason($main, $diag);
        if (($main['status'] ?? 0) === 403 || ($main['status'] ?? 0) === 401) {
            $diag['hints']['mightNeedCookies'] = true;
        }
        if (($main['info']['http_version'] ?? '') === '2' || ($main['info']['primary_ip'] ?? '') === '' ) {
            $diag['hints']['ipv6OrHttp2Issue'] = true;
        }

        return ['urls' => [], 'diag' => $diag];
    }

    private function cleanupMediaList(array $arr): array
    {
        $arr = array_values(array_unique(array_map(function ($u) {
            $u = (string)$u;
            $u = html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return str_replace(['\\u002F', '\\/'], '/', $u);
        }, $arr)));
        return $arr;
    }

    private function preferMp4ThenM3u8(array $candidates): array
    {
        $mp4 = array_values(array_filter($candidates, fn($u) => stripos($u, '.mp4') !== false));
        $m3u8 = array_values(array_filter($candidates, fn($u) => stripos($u, '.m3u8') !== false));
        if (!empty($mp4)) return $mp4;
        if (!empty($m3u8)) return $m3u8;
        return [];
    }

    private function extractThreadsViaYtDlp(string $url, string $cookieHeader, string $trace, bool $debug): array
    {
        $args = [
            'yt-dlp', '--ignore-config', '--force-ipv4', '--no-playlist',
            '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            '--add-header', 'Referer: https://www.threads.net',
            '--add-header', 'X-IG-App-ID: 238260118697367',
            '--user-agent', $this->desktopUA(),
            '-g',
            $url
        ];
        if ($cookieHeader !== '') {
            $args = [
                'yt-dlp', '--ignore-config', '--force-ipv4', '--no-playlist',
                '--add-header', 'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                '--add-header', 'Referer: https://www.threads.net',
                '--add-header', 'X-IG-App-ID: 238260118697367',
                '--add-header', 'Cookie: ' . $cookieHeader,
                '--user-agent', $this->desktopUA(),
                '-g',
                $url
            ];
        }

        $r = $this->run($args, 140);
        if ($debug && !$r['ok']) {
            Log::warning('[THREADS]['.$trace.'] yt-dlp fallback failed', ['stderr' => $r['stderr']]);
        }
        if (!$r['ok']) return [];
        return $this->splitUrls($r['stdout']);
    }

    private function pickVideoUrlsFromHtml(?string $html): array
    {
        if (!is_string($html) || $html === '') return [];
        $out = [];

        // og:video / og:video:url / og:video:secure_url
        if (preg_match_all('#<meta[^>]+property=["\']og:video(?::url)?["\'][^>]+content=["\']([^"\']+)#i', $html, $m)) {
            foreach ($m[1] as $u) $out[] = $u;
        }
        if (preg_match_all('#<meta[^>]+property=["\']og:video:secure_url["\'][^>]+content=["\']([^"\']+)#i', $html, $m2)) {
            foreach ($m2[1] as $u) $out[] = $u;
        }

        // 常見 JSON 欄位
        $patterns = [
            '#"video_url"\s*:\s*"([^"]+)"#i',
            '#"video_versions"\s*:\s*\[\s*{[^}]*"url"\s*:\s*"([^"]+)"#i',
            '#"contentUrl"\s*:\s*"([^"]+)"#i',
            '#"playable_url"\s*:\s*"([^"]+)"#i',
            '#"url"\s*:\s*"([^"]+?\.mp4[^"]*)"#i',
            '#"src"\s*:\s*"([^"]+?\.mp4[^"]*)"#i',
            '#https?://[^"\']+?\.m3u8[^"\']*#i',
        ];
        foreach ($patterns as $re) {
            if (preg_match_all($re, $html, $mm)) {
                foreach (($mm[1] ?? $mm[0]) as $u) $out[] = $u;
            }
        }
        return $out;
    }

    private function analyzeThreadsHtml(?string $html): array
    {
        if (!is_string($html)) return ['length' => 0, 'mp4' => 0, 'm3u8' => 0, 'loginWall' => false, 'hasOgVideo' => false];
        $mp4 = preg_match_all('#\.mp4#i', $html);
        $m3u8 = preg_match_all('#\.m3u8#i', $html);
        $login = preg_match('#(login|log in|請登入|sign in)#i', $html) ? true : false;
        $hasOg = preg_match('#og:video#i', $html) ? true : false;
        return [
            'length' => strlen($html),
            'mp4' => (int)$mp4,
            'm3u8' => (int)$m3u8,
            'loginWall' => $login,
            'hasOgVideo' => $hasOg,
        ];
    }

    private function detectThreadsFailureReason(array $main, array $diag): string
    {
        $status = $main['status'] ?? 0;
        $body   = $main['body'] ?? '';
        if ($status === 0 && ($main['error'] ?? '') !== '') {
            return '連線失敗：' . $main['error'];
        }
        if ($status === 403 || $status === 401) {
            return '被拒絕（' . $status . '），可能需要 Cookies 或 IP/協定受限';
        }
        if (stripos($body, 'login') !== false || stripos($body, 'log in') !== false) {
            return '頁面要求登入（HTML 出現 login 字樣）';
        }
        if ($status >= 300 && $status < 400) {
            return '被重導（' . $status . '），可能導向同意/登入頁';
        }
        if ($body === '' || strlen($body) < 1000) {
            return '取得的 HTML 內容過少，可能被壓縮/阻擋或需要 JS 執行';
        }
        return 'HTML/GraphQL 均未取得媒體資訊；可能為區域/版控限制或貼文本身非公開';
    }

    /* ------------- GraphQL：取得 LSD、doc_id、呼叫 API（免登入） ------------- */

    private function extractLsdTokenFromHtml(string $html): ?string
    {
        if ($html === '') return null;
        // 兼容幾種常見寫法
        $reList = [
            '#"LSD"\s*,\s*\[\s*\]\s*,\s*\{\s*"token"\s*:\s*"([^"]+)"#i',
            '#"lsd"\s*:\s*\{\s*"token"\s*:\s*"([^"]+)"#i',
            '#\["LSD",\s*\[\],\s*\{"token":"([^"]+)"\}\]#i',
        ];
        foreach ($reList as $re) {
            if (preg_match($re, $html, $m)) {
                return $m[1];
            }
        }
        return null;
    }

    private function findGraphQLDocIds(string $html): array
    {
        if ($html === '') return [];
        $found = [];
        if (preg_match_all('#doc_id=(\d{6,})#', $html, $m)) {
            foreach ($m[1] as $id) $found[] = $id;
        }
        if (preg_match_all('#"doc_id"\s*:\s*"(\d{6,})"#', $html, $m2)) {
            foreach ($m2[1] as $id) $found[] = $id;
        }
        return array_values(array_unique($found));
    }

    private function extractThreadsCodeFromUrl(string $url): ?string
    {
        // /post/<code>
        if (preg_match('#/post/([A-Za-z0-9\-_]+)#', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function buildGraphQLVariablesCandidates(string $postCode, string $url): array
    {
        // 構造幾種常見 variables 形態，盡量通吃不同版本
        $vars = [];

        // 最基本：postID
        $vars[] = ['postID' => $postCode];

        // 帶 actor（從 URL 抽 user）
        if (preg_match('#/(?:@|profile/)([A-Za-z0-9\._]+)#', $url, $mUser)) {
            $username = $mUser[1];
            $vars[] = ['postID' => $postCode, 'username' => $username];
        }

        // 一些 Relay 旗標（不同 A/B 版本）
        $vars[] = [
            'postID' => $postCode,
            '__relay_internal__pv__BarcelonaIsLoggedInrelayprovider' => false
        ];

        return $vars;
    }

    private function callThreadsGraphQL(string $lsd, string $docId, array $variables, string $cookieHeader, string $trace): array
    {
        $endpoint = 'https://www.threads.net/api/graphql';
        $headers = [
            'User-Agent: ' . $this->desktopUA(),
            'Accept: */*',
            'Accept-Language: zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Content-Type: application/x-www-form-urlencoded',
            'X-FB-LSD: ' . $lsd,
            'X-IG-App-ID: 238260118697367',
            'Origin: https://www.threads.net',
            'Referer: https://www.threads.net/',
        ];
        if ($cookieHeader !== '') $headers[] = 'Cookie: ' . $cookieHeader;

        $postFields = http_build_query([
            'lsd' => $lsd,
            'variables' => json_encode($variables, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'doc_id' => $docId,
        ], '', '&');

        if (!function_exists('curl_init')) {
            return ['status' => 0, 'json' => null, 'body' => null];
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
            CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_1_1') ? CURL_HTTP_VERSION_1_1 : 2,
            CURLOPT_USERAGENT => $this->desktopUA(),
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            Log::warning('[THREADS][GRAPHQL]['.$trace.'] cURL error', ['errno' => $errno, 'error' => $error, 'status' => $status]);
        }

        $json = null;
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) $json = $decoded;
        }

        return ['status' => $status, 'json' => $json, 'body' => $body];
    }

    private function deepFindMediaUrls($node): array
    {
        $found = [];
        $walker = function($v) use (&$found, &$walker) {
            if (is_array($v)) {
                foreach ($v as $k => $vv) {
                    if (is_string($vv)) {
                        if (preg_match('#^https?://[^"\']+?\.mp4[^"\']*#i', $vv)) $found[] = $vv;
                        if (preg_match('#^https?://[^"\']+?\.m3u8[^"\']*#i', $vv)) $found[] = $vv;
                    } else {
                        $walker($vv);
                    }
                }
            } elseif (is_string($v)) {
                if (preg_match('#^https?://[^"\']+?\.mp4[^"\']*#i', $v)) $found[] = $v;
                if (preg_match('#^https?://[^"\']+?\.m3u8[^"\']*#i', $v)) $found[] = $v;
            }
        };
        $walker($node);
        $found = $this->cleanupMediaList($found);
        return $found;
    }

    /* ------------- cURL 詳細請求（IPv4 + HTTP/1.1 + header/診斷 + HTML 快照） ------------- */

    private function curlFetchDetailed(string $url, array $headers, string $trace): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL 未啟用', 'status' => 0, 'headers' => [], 'info' => []];
        }

        $this->ensureTmpDir();

        $headerLines = [];
        $headerFn = function($ch, $header) use (&$headerLines) {
            $trim = trim($header);
            if ($trim !== '') $headerLines[] = $trim;
            return strlen($header);
        };

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
            CURLOPT_HEADERFUNCTION => $headerFn,
            CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 1,
            CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_1_1') ? CURL_HTTP_VERSION_1_1 : 2,
            CURLOPT_USERAGENT => $this->desktopUA(),
        ]);
        $body   = curl_exec($ch);
        $errno  = curl_errno($ch);
        $error  = curl_error($ch);
        $info   = curl_getinfo($ch);
        curl_close($ch);

        $status = (int)($info['http_code'] ?? 0);
        $ok = ($errno === 0 && $status >= 200 && $status < 400);

        // 紀錄 HTML 快照（前 200KB）
        $snap = is_string($body) ? substr($body, 0, 200 * 1024) : '';
        $this->writeSnapshot($trace, ($status ? $status : 'NA') . '_' . parse_url($url, PHP_URL_PATH), $snap);

        return [
            'ok' => $ok,
            'status' => $status,
            'headers' => $headerLines,
            'body' => $body,
            'error' => $error,
            'errno' => $errno,
            'info' => [
                'total_time' => $info['total_time'] ?? null,
                'namelookup_time' => $info['namelookup_time'] ?? null,
                'connect_time' => $info['connect_time'] ?? null,
                'pretransfer_time' => $info['pretransfer_time'] ?? null,
                'starttransfer_time' => $info['starttransfer_time'] ?? null,
                'primary_ip' => $info['primary_ip'] ?? null,
                'primary_port' => $info['primary_port'] ?? null,
                'redirect_count' => $info['redirect_count'] ?? null,
                'redirect_url' => $info['redirect_url'] ?? null,
                'effective_url' => $info['url'] ?? $url,
                'http_version' => (string)($info['http_version'] ?? ''),
                'size_download' => $info['size_download'] ?? null,
            ],
        ];
    }

    private function diagFromCurl(string $step, string $url, array $r): array
    {
        return [
            'step' => $step,
            'url' => $url,
            'status' => $r['status'] ?? 0,
            'ok' => $r['ok'] ?? false,
            'errno' => $r['errno'] ?? 0,
            'error' => $r['error'] ?? '',
            'info' => $r['info'] ?? [],
            'headers' => array_slice($r['headers'] ?? [], 0, 30),
            'bodySample' => $this->ellipsize($r['body'] ?? '', 800),
        ];
    }

    private function writeSnapshot(string $trace, string $name, string $content): void
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '_', $name);
        $file = storage_path('app/tmp/threads_' . $trace . '_' . $safe . '.html');
        @file_put_contents($file, $content);
    }

    private function ensureTmpDir(): void
    {
        $dir = storage_path('app/tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
    }

    private function ellipsize(string $s, int $limit): string
    {
        if ($limit <= 0) return '';
        if (mb_strlen($s, 'UTF-8') <= $limit) return $s;
        return mb_substr($s, 0, $limit, 'UTF-8') . ' …(truncated)';
    }

    /* -------------------- 共用：URL 正規化與 Cookie 檔 -------------------- */

    private function normalizeAll(string $url): string
    {
        if ($this->isThreadsUrl($ $url)) { // 修正防呆（若打錯）
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

        $hasSession = false;

        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || (strlen($line) > 0 && $line[0] === '#')) continue;
            $parts = explode("\t", $line);
            if (count($parts) === 7) {
                if ($parts[5] === 'sessionid' && $parts[6] !== '') $hasSession = true;
            }
        }
        return $hasSession;
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
            $this->writeNetscapeIGFromPairs(['sessionid' => $sessionId]);
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

    private function writeNetscapeIGFromPairs(array $pairs): void
    {
        $content = "# Netscape HTTP Cookie File\n";
        $keys = array_unique(array_merge(
            array_keys($pairs),
            ['sessionid', 'csrftoken']
        ));
        foreach ($keys as $k) {
            if (!isset($pairs[$k])) continue;
            $v = $pairs[$k];
            if ($v === '') continue;
            $content .= ".instagram.com\tTRUE\t/\tTRUE\t0\t{$k}\t{$v}\n";
        }
        file_put_contents($this->igSessionFile, $content, LOCK_EX);
    }

    private function writeNetscapeForDomain(string $domain, array $pairs, string $filePath): void
    {
        $content = "# Netscape HTTP Cookie File\n";
        foreach ($pairs as $k => $v) {
            if ($k === '' || $v === '') continue;
            $content .= "{$domain}\tTRUE\t/\tTRUE\t0\t{$k}\t{$v}\n";
        }
        file_put_contents($filePath, $content, LOCK_EX);
    }

    private function buildThreadsCombinedCookieHeader(): string
    {
        $pairs = [];
        $pairs = array_merge($pairs, $this->readAllPairsFromNetscape($this->threadsCookieFile));
        $pairs = array_merge($pairs, $this->readAllPairsFromNetscape($this->threadsComCookieFile));
        $pairs = array_merge($pairs, $this->readAllPairsFromNetscape($this->igSessionFile));

        if (empty($pairs)) return '';
        $chunks = [];
        foreach ($pairs as $k => $v) {
            if ($k === '' || $v === '') continue;
            $chunks[] = $k . '=' . $v;
        }
        return implode('; ', $chunks);
    }

    private function readAllPairsFromNetscape(string $file): array
    {
        $out = [];
        if (!is_file($file)) return $out;
        $content = file_get_contents($file);
        if ($content === false) return $out;
        $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || (isset($line[0]) && $line[0] === '#')) continue;
            $parts = explode("\t", $line);
            if (count($parts) === 7) {
                $name  = $parts[5];
                $value = $parts[6];
                if ($name !== '' && $value !== '') {
                    $out[$name] = $value;
                }
            }
        }
        return $out;
    }

    /* -------------------- UA 與共用 -------------------- */

    private function androidUA(): string
    {
        return 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Mobile Safari/537.36';
    }

    private function desktopUA(): string
    {
        return 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';
    }

    private function splitUrls(string $output): array
    {
        $urls = preg_split("/\r\n|\n|\r/", $output) ?: [];
        return array_values(array_filter($urls, function ($u) {
            return preg_match('#^https?://#i', $u);
        }));
    }
}
