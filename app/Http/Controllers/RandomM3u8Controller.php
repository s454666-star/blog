<?php

    namespace App\Http\Controllers;

    use App\Models\VideoMaster;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Http\Response;

    class RandomM3u8Controller extends Controller
    {
        public function index(Request $request): JsonResponse
        {
            $row = VideoMaster::query()
                ->whereNotNull('m3u8_path')
                ->where('m3u8_path', '!=', '')
                ->where('m3u8_path', '!=', 'null')
                ->inRandomOrder()
                ->first();

            if (!$row) {
                return response()->json([
                    'success' => false,
                    'message' => '目前沒有可播放的 m3u8。',
                ], 404);
            }

            $relativePath = $row->m3u8_path;
            $m3u8Url = $this->buildAbsoluteUrlFromRequest($request, $relativePath);

            return response()->json([
                'success' => true,
                'data' => [
                    'id'        => $row->id,
                    'name'      => $row->video_name,
                    'm3u8_path' => $this->normalizePathForWeb($relativePath),
                    'm3u8_url'  => $m3u8Url,
                    'duration'  => $row->duration,
                    'type'      => $row->video_type,
                ],
            ]);
        }

        /**
         * 產出一份 M3U8（UTF-8）播放清單，列出所有可播放的 m3u8（僅取 video_type=1 且 m3u8_path 有效者）。
         * 每個項目用 #EXTINF 標題（影片名稱），URL 為推導後的絕對 m3u8 位置，可被播放器逐條播放。
         */
        public function playlist(Request $request): Response
        {
            $rows = VideoMaster::query()
                ->where('video_type', 1)
                ->whereNotNull('m3u8_path')
                ->where('m3u8_path', '!=', '')
                ->where('m3u8_path', '!=', 'null')
                ->orderByDesc('id')
                ->get();

            if ($rows->isEmpty()) {
                $content = "#EXTM3U\n";
                return response($content, 200, [
                    'Content-Type' => 'application/vnd.apple.mpegurl; charset=UTF-8',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate',
                ]);
            }

            $lines = [];
            $lines[] = "#EXTM3U";

            foreach ($rows as $row) {
                $title = is_string($row->video_name) && $row->video_name !== '' ? $row->video_name : "Video {$row->id}";
                $duration = $this->normalizeDurationForExtinf($row->duration);
                $url = $this->buildAbsoluteUrlFromRequest($request, $row->m3u8_path);

                // #EXTINF:<秒數>,<顯示名稱>
                $lines[] = "#EXTINF:{$duration},{$this->escapeExtinfTitle($title)}";
                $lines[] = $url;
            }

            $content = implode("\n", $lines) . "\n";

            return response($content, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        }

        /**
         * 以當前請求推導正確的 Origin（scheme + host[:port]），
         * 優先使用 X-Forwarded-* 與 Host，再回退到 Request 與 APP_URL。
         */
        protected function buildAbsoluteUrlFromRequest(Request $request, ?string $relativePath): ?string
        {
            if ($relativePath === null || $relativePath === '') {
                return null;
            }

            $lower = strtolower($relativePath);
            if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
                return $relativePath;
            }

            $origin = $this->resolveOrigin($request);
            $path = $this->normalizePathForWeb($relativePath);

            // 視你的實際公開路徑調整這段前綴（你原本就是接在 /30T-A）
            return $origin . '/30T-A' . $path;
        }

        /**
         * 解析 Origin，處理各種代理情境。
         */
        protected function resolveOrigin(Request $request): string
        {
            $xfh = $request->header('X-Forwarded-Host');
            if ($xfh) {
                $xfhParts = array_map('trim', explode(',', $xfh));
                $forwardedHost = $xfhParts[0] ?? null;
            } else {
                $forwardedHost = null;
            }

            $xfproto = $request->header('X-Forwarded-Proto');
            $xfport  = $request->header('X-Forwarded-Port');

            $host = $forwardedHost ?: $request->header('Host');
            if (!$host || $host === '') {
                $host = $request->getHost();
                if (!$host || $host === '') {
                    $appUrlHost = parse_url(config('app.url'), PHP_URL_HOST);
                    if (is_string($appUrlHost) && $appUrlHost !== '') {
                        $host = $appUrlHost;
                    }
                }
            }

            $scheme = $xfproto ?: ($request->isSecure() ? 'https' : 'http');

            $hostWithPort = $host;
            $hasPortInHost = (strpos($host, ':') !== false);

            if (!$hasPortInHost && $xfport) {
                if (!(($scheme === 'http' && $xfport == '80') || ($scheme === 'https' && $xfport == '443'))) {
                    $hostWithPort = $host . ':' . $xfport;
                }
            } elseif (!$hasPortInHost) {
                $port = (int) $request->getPort();
                if (!(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))) {
                    $hostWithPort = $host . ':' . $port;
                }
            }

            return $scheme . '://' . $hostWithPort;
        }

        /**
         * 轉為 Web 路徑：反斜線->斜線，且確保前綴 '/'
         */
        protected function normalizePathForWeb(string $path): string
        {
            $normalized = str_replace('\\', '/', $path);
            return '/' . ltrim($normalized, '/');
        }

        /**
         * EXTINF 的秒數需為整數。若資料庫沒有秒數，使用 -1（未知時長），可被大多播放器接受。
         */
        protected function normalizeDurationForExtinf($duration): int
        {
            if (is_numeric($duration)) {
                $sec = (int) round((float) $duration);
                if ($sec >= 0) {
                    return $sec;
                }
            }
            return -1;
        }

        /**
         * 安全處理標題中的換行與控制字元，避免破壞清單格式。
         */
        protected function escapeExtinfTitle(string $title): string
        {
            $title = str_replace(["\r", "\n"], ' ', $title);
            return trim($title);
        }
    }
