<?php

    declare(strict_types=1);

    namespace App\Console\Commands;

    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use GuzzleHttp\Cookie\FileCookieJar;
    use Throwable;

    class FetchBtdigHtmlCommand extends Command
    {
        protected $signature = 'btdig:fetch
                            {keyword=tokyo-hot+n0861+fhd : 搜尋關鍵字 (可含空白或 url encode 格式)}';

        protected $description = '抓取 btdig HTML / API（設定寫死在程式內），輸出 raw 與 meta 到 storage/logs/btdig';

        public function handle(): int
        {
            $keywordArg = (string) $this->argument('keyword');

            /*
             * ==============================
             * 設定區（全部寫死，不使用 env）
             * ==============================
             */

            $mode = 'html';

            $urlOverride = '';

            $timeoutSeconds = 30;

            // 建議仍維持 1，不要頻繁連打；若你確定要重試再自行改大
            $maxAttempts = 1;

            // 每次請求後休息一下（毫秒）
            $sleepAfterRequestMs = 800;

            // 關閉 warmup（可自行改 true）
            $useWarmup = false;

            $warmupUrls = [
                'https://en.btdig.com/',
                'https://en.btdig.com/index.html',
            ];

            // 改成瀏覽器 UA，避免 PostmanRuntime 太容易被擋
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';

            $cookie = '';

            $proxy = '';

            $caBundlePath = storage_path('certs/cacert.pem');

            // 預設啟用冷卻保護，避免你一直打到被封
            $enableCooldownGuard = true;

            // 被擋時冷卻秒數（30 分鐘）
            $cooldownSeconds = 1800;

            /*
             * ==============================
             * 主流程
             * ==============================
             */

            if (!in_array($mode, ['html', 'api'], true)) {
                $mode = 'html';
            }

            if ($timeoutSeconds < 1) {
                $timeoutSeconds = 30;
            }

            if ($maxAttempts < 1) {
                $maxAttempts = 1;
            }

            if ($sleepAfterRequestMs < 0) {
                $sleepAfterRequestMs = 0;
            }

            if ($cooldownSeconds < 60) {
                $cooldownSeconds = 60;
            }

            $keyword = $this->normalizeKeywordToQuery($keywordArg);
            if ($keyword === '') {
                $this->error('keyword 不可為空');
                return self::FAILURE;
            }

            $url = $urlOverride !== '' ? $urlOverride : $this->buildUrl($mode, $keyword);

            $this->info("開始抓取: {$url}");
            $this->info("模式: {$mode}");
            $this->info("keyword(raw): {$keywordArg}");
            $this->info("keyword(q): {$keyword}");

            $cooldownFile = $this->cooldownFilePath();
            if ($enableCooldownGuard) {
                $cooldown = $this->readCooldownState();
                if ($cooldown !== null && isset($cooldown['blocked_until_iso']) && is_string($cooldown['blocked_until_iso'])) {
                    $blockedUntil = strtotime($cooldown['blocked_until_iso']);
                    if ($blockedUntil !== false && $blockedUntil > time()) {
                        $this->error('目前處於冷卻中，本次不發送請求。');
                        $this->error('blocked_until=' . $cooldown['blocked_until_iso']);
                        $this->error('cooldown_file=' . $cooldownFile);
                        return self::FAILURE;
                    }
                }
            }

            $folder = storage_path('logs/btdig');
            if (!is_dir($folder)) {
                @mkdir($folder, 0777, true);
            }

            $cookieJarPath = $folder . DIRECTORY_SEPARATOR . '_cookiejar.json';
            $cookieJar = new FileCookieJar($cookieJarPath, true);

            $headers = $this->buildHeadersLikeBrowser($mode, $userAgent, $cookie);
            $verifyOption = $this->buildVerifyOption($caBundlePath);
            $options = $this->buildCurlOptions($verifyOption, $proxy, $cookieJar);

            $this->info('CookieJar=' . $cookieJarPath);
            $this->info('SSL verify=' . (is_bool($verifyOption) ? ($verifyOption ? 'true' : 'false') : 'ca_bundle'));
            if (is_string($verifyOption)) {
                $this->info('CA bundle=' . $verifyOption);
            }

            try {
                if ($useWarmup) {
                    foreach ($warmupUrls as $warmUrl) {
                        $this->info('Warmup: ' . $warmUrl);
                        try {
                            $warmResp = Http::timeout($timeoutSeconds)
                                ->withOptions($options)
                                ->withHeaders($headers)
                                ->get($warmUrl);

                            $this->info('Warmup status: ' . $warmResp->status());
                            usleep(200000);
                        } catch (Throwable $e) {
                            $this->warn('Warmup 失敗但繼續: ' . $e->getMessage());
                        }
                    }
                }

                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    $this->info('Request attempt=' . $attempt);

                    $start = microtime(true);

                    $response = Http::timeout($timeoutSeconds)
                        ->withOptions($options)
                        ->withHeaders($headers)
                        ->get($url);

                    $elapsedMs = (int) round((microtime(true) - $start) * 1000);
                    $status = (int) $response->status();
                    $body = (string) $response->body();
                    $respHeaders = $response->headers();

                    $stamp = now()->format('Ymd_His');
                    $safeKeyword = $this->safeFilename($keywordArg);
                    $base = $stamp . '_' . $mode . '_' . $safeKeyword . '_attempt' . $attempt;

                    $rawExt = $mode === 'api' ? 'json' : 'html';
                    $rawPath = $folder . DIRECTORY_SEPARATOR . $base . '_raw.' . $rawExt;
                    $metaPath = $folder . DIRECTORY_SEPARATOR . $base . '_meta.json';

                    file_put_contents($rawPath, $body);

                    $bodySnippet = mb_substr($body, 0, 2000);

                    $antiBot = $this->detectAntiBotPage($body);
                    $isAntiBot = $antiBot['is_antibot'];
                    $antiBotReason = $antiBot['reason'];

                    $retryAfterSeconds = $this->parseRetryAfterSeconds($respHeaders);

                    $meta = [
                        'url' => $url,
                        'mode' => $mode,
                        'keyword_raw' => $keywordArg,
                        'keyword_query' => $keyword,
                        'attempt' => $attempt,
                        'status' => $status,
                        'elapsed_ms' => $elapsedMs,
                        'fetched_at' => now()->toIso8601String(),
                        'request_headers' => $this->redactHeadersForLog($headers),
                        'response_headers' => $respHeaders,
                        'content_length_bytes' => strlen($body),
                        'body_snippet_first_2000' => $bodySnippet,
                        'cooldown_file' => $cooldownFile,
                        'cookiejar_file' => $cookieJarPath,
                        'url_override' => $urlOverride !== '' ? true : false,
                        'enable_cooldown_guard' => $enableCooldownGuard,
                        'verify' => is_bool($verifyOption) ? $verifyOption : 'ca_bundle',
                        'ca_bundle' => is_string($verifyOption) ? $verifyOption : null,
                        'is_antibot' => $isAntiBot,
                        'antibot_reason' => $antiBotReason,
                        'retry_after_seconds' => $retryAfterSeconds,
                    ];

                    file_put_contents(
                        $metaPath,
                        json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    );

                    Log::info('BTDIG fetch done', [
                        'url' => $url,
                        'mode' => $mode,
                        'status' => $status,
                        'elapsed_ms' => $elapsedMs,
                        'rawPath' => $rawPath,
                        'metaPath' => $metaPath,
                        'attempt' => $attempt,
                        'is_antibot' => $isAntiBot,
                        'antibot_reason' => $antiBotReason,
                    ]);

                    $this->info("HTTP 狀態: {$status}");
                    $this->info("耗時(ms): {$elapsedMs}");
                    $this->info("Raw 已寫入: {$rawPath}");
                    $this->info("Meta 已寫入: {$metaPath}");

                    if ($sleepAfterRequestMs > 0) {
                        usleep($sleepAfterRequestMs * 1000);
                    }

                    if ($status === 429 || $isAntiBot) {
                        $cooldownToWrite = $cooldownSeconds;
                        if (is_int($retryAfterSeconds) && $retryAfterSeconds > 0) {
                            $cooldownToWrite = max($cooldownToWrite, $retryAfterSeconds);
                        }

                        $reason = $status === 429 ? 'rate_limited_429' : ('antibot:' . $antiBotReason);
                        $this->writeCooldownState($cooldownToWrite, $status, $reason, $retryAfterSeconds);

                        $this->error('本次被擋或限流，已寫入 cooldown 檔。');
                        $this->error('reason=' . $reason);
                        $this->error('cooldown_file=' . $cooldownFile);

                        return self::FAILURE;
                    }

                    if ($status < 200 || $status >= 300) {
                        $this->warn('非 2xx 回應，請看 raw/meta 檔案內容。');
                        return self::FAILURE;
                    }

                    if ($mode === 'api') {
                        $decoded = json_decode($body, true);
                        if (is_array($decoded)) {
                            $prettyPath = $folder . DIRECTORY_SEPARATOR . $base . '_pretty.json';
                            file_put_contents(
                                $prettyPath,
                                json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                            );
                            $this->info('Pretty JSON 已寫入: ' . $prettyPath);
                        }
                    }

                    return self::SUCCESS;
                }

                return self::FAILURE;
            } catch (Throwable $e) {
                $this->error('發生錯誤: ' . $e->getMessage());
                Log::error('BTDIG fetch failed', [
                    'url' => $url,
                    'mode' => $mode,
                    'keyword_raw' => $keywordArg,
                    'keyword_query' => $keyword,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                $folder = storage_path('logs/btdig');
                if (!is_dir($folder)) {
                    @mkdir($folder, 0777, true);
                }

                $stamp = now()->format('Ymd_His');
                $safeKeyword = $this->safeFilename($keywordArg);
                $base = $stamp . '_' . $mode . '_' . $safeKeyword . '_exception';
                $metaPath = $folder . DIRECTORY_SEPARATOR . $base . '_meta.json';

                $meta = [
                    'url' => $url,
                    'mode' => $mode,
                    'keyword_raw' => $keywordArg,
                    'keyword_query' => $keyword,
                    'fetched_at' => now()->toIso8601String(),
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ];

                @file_put_contents(
                    $metaPath,
                    json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                );

                $this->error('Exception meta 已寫入: ' . $metaPath);

                return self::FAILURE;
            }
        }

        private function buildUrl(string $mode, string $keywordQuery): string
        {
            if ($mode === 'api') {
                return 'https://api.btdig.com/search?q=' . $keywordQuery;
            }

            return 'https://en.btdig.com/search?q=' . $keywordQuery;
        }

        private function normalizeKeywordToQuery(string $keyword): string
        {
            $k = trim($keyword);
            if ($k === '') {
                return '';
            }

            if (preg_match('/%[0-9A-Fa-f]{2}/', $k) === 1) {
                return $k;
            }

            $k = preg_replace('/\s+/', ' ', $k);
            if (!is_string($k) || $k === '') {
                return '';
            }

            $parts = preg_split('/\s+|\+/', $k, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($parts) || count($parts) === 0) {
                return '';
            }

            $encodedParts = [];
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p === '') {
                    continue;
                }
                $encodedParts[] = rawurlencode($p);
            }

            if (count($encodedParts) === 0) {
                return '';
            }

            return implode('+', $encodedParts);
        }

        private function buildHeadersLikeBrowser(string $mode, string $userAgent, string $cookie): array
        {
            $ua = trim($userAgent);
            if ($ua === '') {
                $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';
            }

            $headers = [
                'User-Agent' => $ua,
                'Accept' => $mode === 'api'
                    ? 'application/json,text/plain,*/*'
                    : 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                'Accept-Encoding' => 'gzip, deflate, br',
                'Connection' => 'keep-alive',
                'Accept-Language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                'Cache-Control' => 'no-cache',
                'Pragma' => 'no-cache',
            ];

            if ($mode !== 'api') {
                $headers['Referer'] = 'https://en.btdig.com/';
                $headers['Upgrade-Insecure-Requests'] = '1';
            }

            if (trim($cookie) !== '') {
                $headers['Cookie'] = $cookie;
            }

            return $headers;
        }

        private function buildVerifyOption(string $caBundlePath)
        {
            $path = trim($caBundlePath);

            if ($path !== '' && file_exists($path)) {
                return $path;
            }

            return false;
        }

        private function buildCurlOptions($verifyOption, string $proxy, FileCookieJar $cookieJar): array
        {
            $options = [
                'verify' => $verifyOption,
                'cookies' => $cookieJar,
                'curl' => [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2_0') ? CURL_HTTP_VERSION_2_0 : 0,
                    CURLOPT_ENCODING => '',
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_IPRESOLVE => defined('CURL_IPRESOLVE_V4') ? CURL_IPRESOLVE_V4 : 0,
                ],
            ];

            $proxyValue = trim($proxy);
            if ($proxyValue !== '') {
                $options['proxy'] = $proxyValue;
            }

            return $options;
        }

        private function cooldownFilePath(): string
        {
            $folder = storage_path('logs/btdig');
            if (!is_dir($folder)) {
                @mkdir($folder, 0777, true);
            }

            return $folder . DIRECTORY_SEPARATOR . '_cooldown.json';
        }

        private function readCooldownState(): ?array
        {
            $path = $this->cooldownFilePath();
            if (!file_exists($path)) {
                return null;
            }

            $raw = @file_get_contents($path);
            if (!is_string($raw) || $raw === '') {
                return null;
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return null;
            }

            return $decoded;
        }

        private function writeCooldownState(int $cooldownSeconds, int $status, string $reason, ?int $retryAfterSeconds): void
        {
            $path = $this->cooldownFilePath();
            $blockedUntil = now()->addSeconds($cooldownSeconds)->toIso8601String();

            $payload = [
                'blocked_until_iso' => $blockedUntil,
                'written_at_iso' => now()->toIso8601String(),
                'cooldown_seconds' => $cooldownSeconds,
                'status' => $status,
                'reason' => $reason,
                'retry_after_seconds' => $retryAfterSeconds,
            ];

            @file_put_contents(
                $path,
                json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );
        }

        private function parseRetryAfterSeconds(array $respHeaders): ?int
        {
            foreach ($respHeaders as $key => $vals) {
                if (strcasecmp((string) $key, 'Retry-After') !== 0) {
                    continue;
                }

                if (is_array($vals) && isset($vals[0]) && is_string($vals[0])) {
                    $v = trim($vals[0]);
                    if ($v !== '' && ctype_digit($v)) {
                        $n = (int) $v;
                        return $n > 0 ? $n : null;
                    }
                }
            }

            return null;
        }

        private function detectAntiBotPage(string $body): array
        {
            $hay = strtolower($body);

            $patterns = [
                'g-recaptcha',
                'recaptcha',
                'cdn-cgi',
                'cloudflare',
                'turnstile',
                'checking your browser',
                'one more step',
                'attention required',
                'verify you are human',
                'rate limited',
                'captcha',
            ];

            foreach ($patterns as $p) {
                if (strpos($hay, $p) !== false) {
                    return [
                        'is_antibot' => true,
                        'reason' => $p,
                    ];
                }
            }

            return [
                'is_antibot' => false,
                'reason' => '',
            ];
        }

        private function safeFilename(string $text): string
        {
            $t = preg_replace('/[^a-zA-Z0-9\-\._]+/', '_', $text);
            if (!is_string($t) || $t === '') {
                $t = 'keyword';
            }

            if (strlen($t) > 80) {
                $t = substr($t, 0, 80);
            }

            return $t;
        }

        private function redactHeadersForLog(array $headers): array
        {
            $out = $headers;

            if (array_key_exists('Cookie', $out)) {
                $cookieVal = (string) $out['Cookie'];
                $out['Cookie'] = $cookieVal === '' ? '' : 'REDACTED';
            }

            return $out;
        }
    }
