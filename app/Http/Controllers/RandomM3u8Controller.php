<?php

    namespace App\Http\Controllers;

    use App\Models\VideoMaster;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;

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
         * 以當前請求推導正確的 Origin（scheme + host[:port]），
         * 優先使用 X-Forwarded-* 與 Host，再回退到 Request 與 APP_URL。
         */
        protected function buildAbsoluteUrlFromRequest(Request $request, ?string $relativePath): ?string
        {
            if ($relativePath === null || $relativePath === '') {
                return null;
            }

            // 若資料庫已存絕對網址，直接回傳
            $lower = strtolower($relativePath);
            if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
                return $relativePath;
            }

            $origin = $this->resolveOrigin($request);
            $path = $this->normalizePathForWeb($relativePath);

            return $origin . '/30T-A'. $path;
        }

        /**
         * 解析 Origin，處理各種代理情境。
         */
        protected function resolveOrigin(Request $request): string
        {
            // 取 X-Forwarded-Host（可能含多個，用第一個）
            $xfh = $request->header('X-Forwarded-Host');
            if ($xfh) {
                $xfhParts = array_map('trim', explode(',', $xfh));
                $forwardedHost = $xfhParts[0] ?? null;
            } else {
                $forwardedHost = null;
            }

            // 取 X-Forwarded-Proto 與 X-Forwarded-Port
            $xfproto = $request->header('X-Forwarded-Proto'); // http or https
            $xfport  = $request->header('X-Forwarded-Port');

            // Host（優先 Host header，沒有才回退）
            $host = $forwardedHost ?: $request->header('Host');
            if (!$host || $host === '') {
                // 再退到 Request 物件解析
                $host = $request->getHost();
                // 最後再退到 APP_URL 的 host
                if (!$host || $host === '') {
                    $appUrlHost = parse_url(config('app.url'), PHP_URL_HOST);
                    if (is_string($appUrlHost) && $appUrlHost !== '') {
                        $host = $appUrlHost;
                    }
                }
            }

            // Scheme（優先 X-Forwarded-Proto）
            $scheme = $xfproto ?: ($request->isSecure() ? 'https' : 'http');

            // 加上 port（若需要）
            $hostWithPort = $host;
            $hasPortInHost = (strpos($host, ':') !== false);

            if (!$hasPortInHost && $xfport) {
                // 若 forwarded port 不是預設埠才附加
                if (!($scheme === 'http' && $xfport == '80') && !($scheme === 'https' && $xfport == '443')) {
                    $hostWithPort = $host . ':' . $xfport;
                }
            } elseif (!$hasPortInHost) {
                // 沒有 X-Forwarded-Port 的情況，嘗試從 request 推估實際連線埠
                $port = (int) $request->getPort();
                if (!($scheme === 'http' && $port === 80) && !($scheme === 'https' && $port === 443)) {
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
    }
