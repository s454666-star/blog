<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <title>IG 影片抓取工具（含偵錯日誌）</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Tailwind -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- hls.js -->
    <script src="https://cdn.jsdelivr.net/npm/hls.js@latest"></script>
    <!-- Instagram Embed -->
    <script async src="https://www.instagram.com/embed.js"></script>
</head>
<body class="bg-gray-50 min-h-screen text-gray-800">
<div class="max-w-5xl mx-auto px-4 py-8">
    <header class="mb-8">
        <h1 class="text-3xl font-bold tracking-tight">IG 影片抓取工具</h1>
        <p class="text-sm text-gray-500 mt-2">輸入 Instagram 連結，預覽並下載 Reels／Post。內建詳細偵錯日誌。</p>
    </header>

    @if (session('error'))
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">
            {{ session('error') }}
        </div>
    @endif

    @if (!empty($error))
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-red-700">
            {{ $error }}
        </div>
    @endif

    <div class="rounded-2xl bg-white shadow p-6">
        <form action="{{ route('ig.fetch') }}" method="post" class="space-y-4">
            @csrf
            <label class="block">
                <span class="text-sm font-medium text-gray-700">Instagram 影片連結（Reel／Post）</span>
                <input
                    type="url"
                    name="url"
                    required
                    value="{{ old('url', $url) }}"
                    placeholder="例如：https://www.instagram.com/reel/DMkUu75vR1e/"
                    class="mt-2 w-full rounded-xl border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                >
            </label>

            <details class="rounded-xl border border-gray-200 p-4 bg-gray-50">
                <summary class="cursor-pointer text-sm font-medium text-gray-700">進階選項（可選）</summary>
                <div class="mt-4 space-y-2">
                    <label class="block">
                        <span class="text-xs text-gray-600">Instagram sessionid（若貼文需要登入，填入可提高成功率）</span>
                        <input
                            type="text"
                            name="ig_session"
                            value="{{ old('ig_session', $ig_session) }}"
                            placeholder="請填你自己的合法 sessionid（不會外傳）"
                            class="mt-1 w-full rounded-lg border-gray-300 focus:border-indigo-500 focus:ring-indigo-500"
                        >
                    </label>
                    <p class="text-xs text-gray-500">我們會遮罩日誌中的敏感資訊。</p>
                </div>
            </details>

            <div class="pt-2">
                <button
                    type="submit"
                    class="inline-flex items-center gap-2 rounded-xl bg-indigo-600 px-5 py-2.5 text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
                >
                    取得預覽
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                </button>
            </div>
        </form>
    </div>

    @if ($url)
        <section class="mt-8 space-y-6">
            <div class="rounded-2xl bg-white shadow p-6">
                <h2 class="text-xl font-semibold mb-4">預覽</h2>

                @php
                    $canDirectPlay = is_array($probe) && ($probe['ok'] ?? false) && !empty($probe['direct_url']);
                    $isHls = (bool)($probe['is_hls'] ?? false);
                    $directUrl = $canDirectPlay ? $probe['direct_url'] : null;
                @endphp

                @if ($canDirectPlay)
                    <div class="aspect-video w-full overflow-hidden rounded-xl bg-black">
                        <video id="videoPlayer" class="w-full h-full" controls playsinline></video>
                    </div>
                    <script>
                        (function(){
                            const src = @json($directUrl);
                            const isHls = @json($isHls);
                            const video = document.getElementById('videoPlayer');

                            if (isHls) {
                                if (Hls.isSupported()) {
                                    const hls = new Hls();
                                    hls.loadSource(src);
                                    hls.attachMedia(video);
                                } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
                                    video.src = src;
                                } else {
                                    video.outerHTML = '<div class="p-4 text-sm text-red-600">此瀏覽器不支援 HLS 播放，請改用 Safari，或點選下方下載。</div>';
                                }
                            } else {
                                video.src = src;
                            }
                        })();
                    </script>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        @if (!empty($probe['title']))
                            <div class="text-sm"><span class="text-gray-500">標題：</span>{{ $probe['title'] }}</div>
                        @endif
                        @if (!empty($probe['duration']))
                            <div class="text-sm"><span class="text-gray-500">長度：</span>{{ $probe['duration'] }} 秒</div>
                        @endif
                    </div>
                @elseif (is_array($meta) && !empty($meta['html']))
                    <div class="prose max-w-none">
                        {!! $meta['html'] !!}
                    </div>
                    <script>
                        if (window.instgrm && window.instgrm.Embeds) {
                            window.instgrm.Embeds.process();
                        }
                    </script>
                @else
                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-800">
                        無法自動產生預覽，請確認網址是否公開或稍後重試。
                    </div>
                @endif

                <div class="mt-6">
                    <a
                        href="{{ route('ig.download', ['url' => $url]) }}"
                        class="inline-flex items-center gap-2 rounded-xl bg-emerald-600 px-5 py-2.5 text-white hover:bg-emerald-700 focus:outline-none focus:ring-2 focus:ring-emerald-500"
                    >
                        下載影片
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5m0 0l5-5m-5 5V4" />
                        </svg>
                    </a>
                </div>
            </div>

            <div class="rounded-2xl bg-white shadow p-6">
                <h3 class="text-lg font-semibold mb-2">貼文摘要</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="text-xs text-gray-500">作者</div>
                        <div class="text-sm">
                            @if(is_array($meta) && !empty($meta['author_name']))
                                {{ $meta['author_name'] }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">來源</div>
                        <div class="text-sm">{{ is_array($meta) && !empty($meta['provider_name']) ? $meta['provider_name'] : 'Instagram' }}</div>
                    </div>
                    <div class="sm:col-span-2">
                        <div class="text-xs text-gray-500">標題</div>
                        <div class="text-sm">
                            @if(is_array($probe) && !empty($probe['title']))
                                {{ $probe['title'] }}
                            @elseif(is_array($meta) && !empty($meta['title']))
                                {{ $meta['title'] }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                </div>
                @if(is_array($meta) && !empty($meta['thumbnail_url']))
                    <img src="{{ $meta['thumbnail_url'] }}" alt="thumbnail" class="mt-4 w-full max-w-md rounded-xl border">
                @elseif(is_array($probe) && !empty($probe['thumbnail']))
                    <img src="{{ $probe['thumbnail'] }}" alt="thumbnail" class="mt-4 w-full max-w-md rounded-xl border">
                @endif
            </div>

            <div class="rounded-2xl bg-white shadow p-6">
                <h3 class="text-lg font-semibold mb-4">偵錯日誌</h3>
                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <div class="text-xs text-gray-500">Trace ID</div>
                        <div class="text-sm font-mono">{{ $diag['traceId'] ?? '-' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">Log 檔案（伺服器）</div>
                        <div class="text-sm font-mono">storage/app/tmp/{{ $diag['traceFile'] ?? '' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">PHP / OS</div>
                        <div class="text-sm font-mono">
                            {{ $diag['env']['php'] ?? '?' }} / {{ $diag['env']['os'] ?? '?' }}
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-gray-500">APP_ENV / DEBUG</div>
                        <div class="text-sm font-mono">
                            {{ $diag['env']['app_env'] ?? '?' }} / {{ $diag['env']['debug'] ? 'true' : 'false' }}
                        </div>
                    </div>
                </div>

                @if (!empty($diag['meta_raw']))
                    <details class="mt-4 rounded-lg border bg-gray-50 p-3">
                        <summary class="cursor-pointer text-sm font-medium">oEmbed 原始資料（已遮罩）</summary>
                        <pre class="mt-2 text-xs overflow-x-auto">{{ json_encode($diag['meta_raw'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>
                    </details>
                @endif

                @if (!empty($diag['probe_raw']))
                    <details class="mt-4 rounded-lg border bg-gray-50 p-3">
                        <summary class="cursor-pointer text-sm font-medium">yt-dlp 偵測資料（已遮罩）</summary>
                        <pre class="mt-2 text-xs overflow-x-auto">{{ json_encode($diag['probe_raw'], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>
                    </details>
                @endif

                <details class="mt-4 rounded-lg border bg-gray-50 p-3" open>
                    <summary class="cursor-pointer text-sm font-medium">即時日誌（最新在下方）</summary>
                    <div class="mt-2 space-y-2 max-h-80 overflow-y-auto">
                        @if (is_array($diag['logs'] ?? null))
                            @foreach ($diag['logs'] as $log)
                                <div class="rounded border bg-white p-2">
                                    <div class="text-[11px] text-gray-500">{{ $log['ts'] ?? '' }} · <span class="uppercase">{{ $log['level'] ?? 'info' }}</span> · {{ $log['event'] ?? '' }}</div>
                                    <pre class="mt-1 text-xs overflow-x-auto">{{ json_encode($log['context'] ?? [], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT) }}</pre>
                                </div>
                            @endforeach
                        @else
                            <div class="text-sm text-gray-500">（沒有可顯示的日誌）</div>
                        @endif
                    </div>
                </details>
            </div>
        </section>
    @endif

    <footer class="mt-10 text-center text-xs text-gray-400">
        <p>請遵守平台條款與著作權規範。此工具僅供合法用途。</p>
    </footer>
</div>
</body>
</html>
