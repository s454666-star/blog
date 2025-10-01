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
         * 產出 IPTV 友善的 M3U8（UTF-8），僅列 video_type=1 且 m3u8_path 有效者。
         * - 使用 EXTINF:-1（未知/直播式），相容 IPTV 客戶端
         * - 附加 tvg-id / tvg-name / group-title
         * - URL 全部做百分比編碼（處理中文、空白）
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

            $lines = [];
            $lines[] = "#EXTM3U";

            foreach ($rows as $row) {
                $title = is_string($row->video_name) && $row->video_name !== '' ? $row->video_name : "Video {$row->id}";
                $absUrl = $this->buildAbsoluteUrlFromRequest($request, $row->m3u8_path);

                // 將 URL 的 path 每一段做百分比編碼，避免中文/空白造成 xTeVe / FFmpeg 解析失敗
                $encodedUrl = $this->percentEncodeUrlPath($absUrl);

                // 產出更符合 IPTV 的 EXTINF 行（含欄位）
                $extinf = $this->buildIptvExtinfLine(
                    tvgId: "vm{$row->id}",
                    tvgName: $title,
                    groupTitle: "MyStar",
                    displayName: $title
                );

                $lines[] = $extinf;
                $lines[] = $encodedUrl;
            }

            $content = implode("\n", $lines) . "\n";

            return response($content, 200, [
                'Content-Type' => 'application/vnd.apple.mpegurl; charset=UTF-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ]);
        }

        /**
         * 以當前請求推導 Origin（scheme + host[:port]），優先 X-Forwarded-* 與 Host，再回退到 Request / APP_URL。
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

            // 依你的實際公開根路徑調整本前綴；你目前是 /30T-A
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
         * 將 URL 的 path 部分逐段做百分比編碼（保留 scheme/host/query/fragment）
         * 例如：https://host/中 文/自拍_1/video.m3u8
         */
        protected function percentEncodeUrlPath(?string $url): ?string
        {
            if (!$url) {
                return null;
            }
            $parts = parse_url($url);
            if ($parts === false) {
                return $url;
            }

            $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : '';
            $auth   = '';
            if (isset($parts['user'])) {
                $auth .= $parts['user'];
                if (isset($parts['pass'])) {
                    $auth .= ':' . $parts['pass'];
                }
                $auth .= '@';
            }
            $host   = $parts['host'] ?? '';
            $port   = isset($parts['port']) ? ':' . $parts['port'] : '';

            $path   = $parts['path'] ?? '';
            if ($path !== '') {
                $segments = explode('/', $path);
                foreach ($segments as &$seg) {
                    if ($seg === '') {
                        continue;
                    }
                    $seg = rawurlencode($seg);
                }
                $path = implode('/', $segments);
            }

            $query  = isset($parts['query']) ? '?' . $parts['query'] : '';
            $frag   = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

            return $scheme . $auth . $host . $port . $path . $query . $frag;
        }

        /**
         * 生成 IPTV 慣用 EXTINF 行（-1 + tvg-id/tvg-name/group-title），名稱在逗號後。
         * 例：#EXTINF:-1 tvg-id="vm123" tvg-name="自拍 123" group-title="MyStar",自拍 123
         */
        protected function buildIptvExtinfLine(string $tvgId, string $tvgName, string $groupTitle, string $displayName): string
        {
            // EXTINF 必須單行，標題去除換行符
            $title = str_replace(["\r", "\n"], ' ', trim($displayName));
            $tvgIdEsc = $this->escapeExtinfAttr($tvgId);
            $tvgNameEsc = $this->escapeExtinfAttr($tvgName);
            $groupEsc = $this->escapeExtinfAttr($groupTitle);

            return "#EXTINF:-1 tvg-id=\"{$tvgIdEsc}\" tvg-name=\"{$tvgNameEsc}\" group-title=\"{$groupEsc}\",{$title}";
        }

        protected function escapeExtinfAttr(string $v): string
        {
            // 雖然多數客戶端不會解析到引號，但保險處理
            return str_replace(['"', "\r", "\n"], ['\"', ' ', ' '], trim($v));
        }
    }
