<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Str;
    use Illuminate\Support\Facades\Response;
    use Symfony\Component\Process\Process;

    class IgGrabController extends Controller
    {
        private string $tmpDir;
        private ?string $threadsAppId;
        private ?string $threadsAppSecret;

        // 追蹤與偵錯
        private string $traceId;
        private string $traceFile;
        private array $traceBuffer = [];

        public function __construct()
        {
            $this->tmpDir = storage_path('app/tmp');
            if (!is_dir($this->tmpDir)) {
                @mkdir($this->tmpDir, 0775, true);
            }
            $this->threadsAppId = env('THREADS_APP_ID');
            $this->threadsAppSecret = env('THREADS_APP_SECRET');

            $this->traceId = (string) Str::uuid();
            $this->traceFile = $this->tmpDir . DIRECTORY_SEPARATOR . "ig_trace_{$this->traceId}.log";

            $this->trace("INIT controller", [
                'php' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'threads_app_id_present' => $this->threadsAppId ? true : false,
                'threads_app_secret_present' => $this->threadsAppSecret ? true : false,
            ]);
        }

        public function index(Request $request)
        {
            return view('ig_grabber', [
                'url' => '',
                'meta' => null,
                'probe' => null,
                'error' => null,
                'ig_session' => session('ig_session'),
                'diag' => [
                    'traceId' => $this->traceId,
                    'traceFile' => basename($this->traceFile),
                    'logs' => $this->traceBuffer,
                    'env' => $this->runtimeEnvDiag(),
                ],
            ]);
        }

        public function fetch(Request $request)
        {
            $request->validate([
                'url' => ['required', 'url'],
                'ig_session' => ['nullable', 'string'],
            ]);

            $url = trim($request->input('url'));
            $igSession = trim((string)$request->input('ig_session', ''));
            if ($igSession !== '') {
                session(['ig_session' => $igSession]);
            }

            $this->trace("FETCH begin", [
                'url' => $url,
                'ig_session_present' => $igSession !== '',
            ]);

            $envDiag = $this->runtimeEnvDiag();
            $this->trace("ENV check", $envDiag);

            $meta = null;
            $probe = null;
            $error = null;

            // 1) oEmbed
            try {
                $meta = $this->fetchOEmbed($url);
                $this->trace("oEmbed done", [
                    'has_html' => is_array($meta) && !empty($meta['html']),
                    'author_name' => $meta['author_name'] ?? null,
                    'provider_name' => $meta['provider_name'] ?? null,
                    'title' => $meta['title'] ?? null,
                    'thumb_present' => !empty($meta['thumbnail_url']),
                ]);
            } catch (\Throwable $e) {
                $this->trace("oEmbed failed", [
                    'exception' => get_class($e),
                    'message' => $this->maskSensitive($e->getMessage()),
                ], 'warning');
                Log::warning('[IG] oEmbed 取得失敗：' . $e->getMessage(), ['url' => $url, 'traceId' => $this->traceId]);
            }

            // 2) yt-dlp 探測
            try {
                $probe = $this->probeWithYtDlp($url, $igSession !== '' ? $igSession : null);
                $this->trace("yt-dlp probe result", [
                    'ok' => $probe['ok'] ?? false,
                    'is_hls' => $probe['is_hls'] ?? null,
                    'title' => $probe['title'] ?? null,
                    'id' => $probe['id'] ?? null,
                    'duration' => $probe['duration'] ?? null,
                    'direct_url_present' => !empty($probe['direct_url']),
                ]);
            } catch (\Throwable $e) {
                $error = 'yt-dlp 偵測失敗：' . $e->getMessage();
                $this->trace("yt-dlp probe threw", [
                    'exception' => get_class($e),
                    'message' => $this->maskSensitive($e->getMessage()),
                ], 'error');
                Log::error('[IG] yt-dlp 偵測失敗：' . $e->getMessage(), ['url' => $url, 'traceId' => $this->traceId]);
            }

            $this->trace("FETCH end");

            return view('ig_grabber', [
                'url' => $url,
                'meta' => $meta,
                'probe' => $probe,
                'error' => $error,
                'ig_session' => session('ig_session'),
                'diag' => [
                    'traceId' => $this->traceId,
                    'traceFile' => basename($this->traceFile),
                    'logs' => $this->traceBuffer,
                    'env' => $envDiag,
                    'meta_raw' => $this->truncateForUi($meta),
                    'probe_raw' => $this->truncateForUi($probe),
                ],
            ]);
        }

        public function download(Request $request)
        {
            $request->validate([
                'url' => ['required', 'url'],
            ]);
            $url = trim($request->query('url'));
            $igSession = session('ig_session');

            $this->trace("DOWNLOAD begin", ['url' => $url, 'ig_session_present' => !empty($igSession)]);

            $yt = $this->locateYtDlp();

            $targetName = 'ig_' . Str::uuid()->toString() . '.%(ext)s';
            $outTpl = $this->tmpDir . DIRECTORY_SEPARATOR . $targetName;

            $cmd = [
                $yt,
                '-f', 'best[ext=mp4]/best',
                '--no-playlist',
                '--no-progress',
                '-o', $outTpl,
                '--print', 'after_move:filepath',
                $url,
            ];

            $cookiePath = null;
            if (is_string($igSession) && $igSession !== '') {
                $cookiePath = $this->writeInstagramSessionCookie($igSession);
                $cmd = array_merge([$yt], [
                    '-f', 'best[ext=mp4]/best',
                    '--no-playlist',
                    '--no-progress',
                    '--cookies', $cookiePath,
                    '-o', $outTpl,
                    '--print', 'after_move:filepath',
                    $url,
                ]);
            }

            $this->trace('DOWNLOAD command', [
                'command' => $this->maskSensitive($this->prettyCmd($cmd)),
                'cookie_file' => $cookiePath ? basename($cookiePath) : null,
            ]);

            $process = new Process($cmd, null, null, null, 600);
            $process->run();

            if ($cookiePath && file_exists($cookiePath)) {
                @unlink($cookiePath);
            }

            if (!$process->isSuccessful()) {
                $err = $process->getErrorOutput() ?: $process->getOutput();
                $this->trace('DOWNLOAD failed', [
                    'exit_code' => $process->getExitCode(),
                    'stderr_excerpt' => $this->maskSensitive($this->excerpt($err)),
                ], 'error');
                Log::error('[IG] 下載失敗', ['cmd' => $cmd, 'error' => $err, 'traceId' => $this->traceId]);
                return back()->with('error', '下載失敗：' . $this->excerpt($err));
            }

            $filePath = trim($process->getOutput());
            $this->trace('DOWNLOAD path', ['file' => $filePath]);

            if (!file_exists($filePath)) {
                $this->trace('DOWNLOAD file missing', ['expected' => $filePath], 'error');
                Log::error('[IG] 下載檔案不存在', ['expected' => $filePath, 'traceId' => $this->traceId]);
                return back()->with('error', '下載失敗：檔案不存在。');
            }

            $downloadName = pathinfo($filePath, PATHINFO_BASENAME);
            $this->trace('DOWNLOAD success', ['download' => $downloadName]);

            return Response::download($filePath, $downloadName)->deleteFileAfterSend(true);
        }

        private function fetchOEmbed(string $url): ?array
        {
            if (!$this->threadsAppId || !$this->threadsAppSecret) {
                $this->trace("oEmbed skipped: missing app credentials", [], 'warning');
                return null;
            }
            $accessToken = $this->threadsAppId . '|' . $this->threadsAppSecret;

            $query = [
                'url' => $url,
                'access_token' => $accessToken,
                'omitscript' => true,
                'hidecaption' => true,
                'maxwidth' => 720,
            ];
            $this->trace("oEmbed request", ['endpoint' => 'https://graph.facebook.com/v20.0/instagram_oembed', 'query' => $this->maskSensitive($query)]);

            $resp = Http::timeout(12)->get('https://graph.facebook.com/v20.0/instagram_oembed', $query);

            $this->trace("oEmbed response", [
                'http_ok' => $resp->ok(),
                'status' => $resp->status(),
                'length' => strlen((string) $resp->body()),
            ]);

            if (!$resp->ok()) {
                throw new \RuntimeException('oEmbed HTTP ' . $resp->status() . ' ' . $resp->body());
            }

            $data = $resp->json();
            if (!is_array($data)) {
                throw new \RuntimeException('oEmbed JSON 格式錯誤');
            }

            return [
                'author_name' => $data['author_name'] ?? null,
                'provider_name' => $data['provider_name'] ?? 'Instagram',
                'thumbnail_url' => $data['thumbnail_url'] ?? null,
                'title' => $data['title'] ?? null,
                'html' => $data['html'] ?? null,
            ];
        }

        private function probeWithYtDlp(string $url, ?string $igSessionId = null): array
        {
            $yt = $this->locateYtDlp();
            $ff = $this->locateFfmpeg();

            $this->trace("yt-dlp locate", ['yt_dlp' => $yt, 'ffmpeg' => $ff]);

            $ytVer = $this->tryVersion([$yt, '--version']);
            $ffVer = $this->tryVersion([$ff, '-version']);
            $this->trace("bin versions", ['yt_dlp' => $ytVer, 'ffmpeg' => $ffVer]);

            $cmd = [
                $yt,
                '-J',
                '-f', 'bestvideo*+bestaudio/best',
                '--no-playlist',
                '--no-progress',
                $url,
            ];

            $cookiePath = null;
            if ($igSessionId) {
                $cookiePath = $this->writeInstagramSessionCookie($igSessionId);
                $cmd = [
                    $yt,
                    '-J',
                    '-f', 'bestvideo*+bestaudio/best',
                    '--no-playlist',
                    '--no-progress',
                    '--cookies', $cookiePath,
                    $url,
                ];
            }

            $this->trace("yt-dlp command", [
                'command' => $this->maskSensitive($this->prettyCmd($cmd)),
                'cookie_file' => $cookiePath ? basename($cookiePath) : null,
            ]);

            $process = new Process($cmd, null, null, null, 90);
            $process->run();

            if ($cookiePath && file_exists($cookiePath)) {
                @unlink($cookiePath);
            }

            if (!$process->isSuccessful()) {
                $err = $process->getErrorOutput() ?: $process->getOutput();
                $this->trace("yt-dlp failed", [
                    'exit_code' => $process->getExitCode(),
                    'stderr_excerpt' => $this->maskSensitive($this->excerpt($err)),
                ], 'warning');
                return ['ok' => false, 'error' => 'yt-dlp 失敗', 'stderr' => $this->excerpt($err)];
            }

            $raw = $process->getOutput();
            the:
            $this->trace("yt-dlp stdout length", ['bytes' => strlen($raw)]);

            $json = json_decode($raw, true);
            if (!is_array($json)) {
                $this->trace("yt-dlp json decode failed", [], 'warning');
                return ['ok' => false];
            }

            $direct = $json['url'] ?? null;
            $isHls = false;
            $fmtCount = is_array($json['formats'] ?? null) ? count($json['formats']) : 0;

            if (!$direct && isset($json['formats']) && is_array($json['formats'])) {
                $best = null;
                foreach ($json['formats'] as $fmt) {
                    if (!empty($fmt['url'])) {
                        if (isset($fmt['ext']) && $fmt['ext'] === 'mp4') {
                            $best = $fmt;
                            break;
                        }
                        if (!$best) {
                            $best = $fmt;
                        }
                    }
                }
                if ($best) {
                    $direct = $best['url'];
                    $isHls = isset($best['protocol']) && str_contains((string)$best['protocol'], 'm3u8');
                }
            } else {
                $isHls = is_string($direct) && str_contains($direct, '.m3u8');
            }

            $this->trace("yt-dlp parsed", [
                'formats' => $fmtCount,
                'direct_present' => $direct ? true : false,
                'is_hls' => $isHls,
            ]);

            return [
                'ok' => (bool)$direct,
                'direct_url' => $direct,
                'is_hls' => (bool)$isHls,
                'title' => $json['title'] ?? null,
                'id' => $json['id'] ?? null,
                'duration' => $json['duration'] ?? null,
                'thumbnail' => $json['thumbnail'] ?? null,
            ];
        }

        private function locateYtDlp(): string
        {
            $candidates = [
                'yt-dlp',
                '/usr/local/bin/yt-dlp',
                '/usr/bin/yt-dlp',
                '/opt/homebrew/bin/yt-dlp',
            ];
            foreach ($candidates as $bin) {
                $p = new Process(['bash', '-lc', 'command -v ' . escapeshellarg($bin)]);
                $p->run();
                if ($p->isSuccessful()) {
                    $found = trim($p->getOutput());
                    if ($found !== '') {
                        return $found;
                    }
                }
                if (is_file($bin) && is_executable($bin)) {
                    return $bin;
                }
            }
            return 'yt-dlp';
        }

        private function locateFfmpeg(): string
        {
            $candidates = [
                'ffmpeg',
                '/usr/local/bin/ffmpeg',
                '/usr/bin/ffmpeg',
                '/opt/homebrew/bin/ffmpeg',
            ];
            foreach ($candidates as $bin) {
                $p = new Process(['bash', '-lc', 'command -v ' . escapeshellarg($bin)]);
                $p->run();
                if ($p->isSuccessful()) {
                    $found = trim($p->getOutput());
                    if ($found !== '') {
                        return $found;
                    }
                }
                if (is_file($bin) && is_executable($bin)) {
                    return $bin;
                }
            }
            return 'ffmpeg';
        }

        private function tryVersion(array $cmd): ?string
        {
            try {
                $p = new Process($cmd, null, null, null, 5);
                $p->run();
                if ($p->isSuccessful()) {
                    $out = trim($p->getOutput());
                    return $out !== '' ? $this->excerpt($out, 180) : null;
                }
            } catch (\Throwable $e) {
                // ignore
            }
            return null;
        }

        private function writeInstagramSessionCookie(string $sessionId): string
        {
            $cookiePath = $this->tmpDir . DIRECTORY_SEPARATOR . 'ig_' . Str::uuid()->toString() . '.cookies.txt';
            $expires = 2147483647;
            $content = "# Netscape HTTP Cookie File\n"
                . ".instagram.com\tTRUE\t/\tTRUE\t{$expires}\tsessionid\t{$sessionId}\n";
            file_put_contents($cookiePath, $content);
            $this->trace("cookie file created", ['file' => basename($cookiePath), 'size' => strlen($content)]);
            return $cookiePath;
        }

        private function runtimeEnvDiag(): array
        {
            return [
                'php' => PHP_VERSION,
                'os' => PHP_OS_FAMILY,
                'app_env' => config('app.env'),
                'debug' => config('app.debug'),
            ];
        }

        private function trace(string $event, array $context = [], string $level = 'info'): void
        {
            $ts = gmdate('c');
            $entry = [
                'ts' => $ts,
                'event' => $event,
                'level' => $level,
                'traceId' => $this->traceId,
                'context' => $this->maskSensitive($context),
            ];
            $line = json_encode($entry, JSON_UNESCAPED_UNICODE);
            $this->traceBuffer[] = $entry;
            @file_put_contents($this->traceFile, $line . PHP_EOL, FILE_APPEND);

            // 修正：使用動態方法呼叫
            $logMethod = $level === 'error' ? 'error' : ($level === 'warning' ? 'warning' : 'info');
            Log::{$logMethod}('[IG] ' . $event, ['traceId' => $this->traceId, 'context' => $entry['context']]);
        }

        private function maskSensitive($v)
        {
            // 針對 sessionid 和 access_token 遮罩
            $maskString = function ($s) {
                if (!is_string($s)) {
                    return $s;
                }
                // 1) sessionid=xxxxxxxx（URL 或文字）
                $s = preg_replace_callback('/(sessionid=)([^\\s;&]+)/i', function ($m) {
                    $val = $m[2] ?? '';
                    $keep = $val !== '' ? substr($val, -4) : '';
                    return ($m[1] ?? 'sessionid=') . '***' . $keep;
                }, $s);

                // 2) "sessionid":"xxxxxxxx" 或 sessionid: "xxxxxxxx"
                $s = preg_replace_callback('/("sessionid"\\s*:\\s*")[^"]+(")/i', function ($m) {
                    return ($m[1] ?? '"sessionid":"') . '***masked***' . ($m[2] ?? '"');
                }, $s);

                // 3) Netscape cookie 格式行：\tsessionid\tvalue
                $s = preg_replace_callback('/(\\tsessionid\\t)([^\\t\\r\\n]+)/i', function ($m) {
                    $val = $m[2] ?? '';
                    $keep = $val !== '' ? substr($val, -4) : '';
                    return ($m[1] ?? "\tsessionid\t") . '***' . $keep;
                }, $s);

                // 4) access_token=xxxxx
                $s = preg_replace('/(access_token=)([^\\s&]+)/i', '$1***masked***', $s);

                return $s;
            };

            if (is_array($v)) {
                $out = [];
                foreach ($v as $k => $val) {
                    $out[$k] = $this->maskSensitive($val);
                }
                return $out;
            }
            if (is_object($v)) {
                return $v;
            }
            return $maskString($v);
        }

        private function prettyCmd(array $cmd): string
        {
            $parts = [];
            foreach ($cmd as $c) {
                if (preg_match('/[\\s"\']/', $c)) {
                    $parts[] = '"' . str_replace('"', '\\"', $c) . '"';
                } else {
                    $parts[] = $c;
                }
            }
            return implode(' ', $parts);
        }

        private function excerpt(?string $s, int $len = 500): string
        {
            if ($s === null) {
                return '';
            }
            $s = trim($s);
            if (mb_strlen($s) <= $len) {
                return $s;
            }
            return mb_substr($s, 0, $len) . '... (truncated)';
        }

        private function truncateForUi($data)
        {
            if (!is_array($data)) {
                return $data;
            }
            $copy = $data;
            if (isset($copy['formats']) && is_array($copy['formats'])) {
                $copy['formats'] = 'array(' . count($copy['formats']) . ')';
            }
            return $this->maskSensitive($copy);
        }
    }
