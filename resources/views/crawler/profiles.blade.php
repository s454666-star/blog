<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>85sugarbaby Profile Dashboard</title>
    <style>
        :root {
            --bg: #f3f4ff;
            --ink: #111827;
            --soft: #d1fae5;
            --line: #dbeafe;
            --card: rgba(255, 255, 255, 0.9);
            --primary: #4f46e5;
            --accent: #10b981;
        }
        * { box-sizing: border-box; }
        html, body {
            margin: 0;
            font-family: "Noto Sans TC","Segoe UI","PingFang TC","Helvetica Neue",Arial,sans-serif;
            color: var(--ink);
            background:
                radial-gradient(1200px 800px at 15% 10%, rgba(79,70,229,0.16), transparent 60%),
                radial-gradient(1200px 900px at 85% 0%, rgba(16,185,129,0.15), transparent 56%),
                var(--bg);
            min-height: 100vh;
        }
        .page {
            max-width: 1280px;
            margin: 0 auto;
            padding: 24px 18px 42px;
        }
        .hero {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
            padding: 24px;
            border-radius: 28px;
            border: 1px solid rgba(79,70,229,0.14);
            background: linear-gradient(160deg, rgba(255,255,255,0.94), rgba(255,255,255,0.82));
            box-shadow: 0 20px 60px rgba(79,70,229,0.13);
            margin-bottom: 18px;
            animation: rise 0.5s ease;
        }
        .hero h1 {
            margin: 0;
            font-size: clamp(1.8rem, 3.4vw, 2.45rem);
            letter-spacing: 0.01em;
        }
        .hero p { margin: 10px 0 0; color: #374151; line-height: 1.8; }
        .stats {
            display: grid;
            grid-template-columns: repeat(2,minmax(140px,1fr));
            gap: 12px;
        }
        .stat {
            border: 1px solid rgba(17,24,39,0.1);
            background: #fff;
            border-radius: 18px;
            padding: 12px 14px;
        }
        .stat .label { color: #4b5563; font-size: 0.83rem; }
        .stat .value { font-size: 1.45rem; font-weight: 700; margin-top: 6px; }
        .toolbar {
            margin-top: 14px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
        }
        .toolbar form {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            width: 100%;
        }
        .toolbar input,
        .toolbar select,
        .toolbar button {
            border: 1px solid rgba(17,24,39,0.14);
            border-radius: 12px;
            padding: 11px 14px;
            font-size: 0.95rem;
        }
        .toolbar input { flex: 1; min-width: 220px; }
        .toolbar button { background: linear-gradient(160deg, var(--primary), #6366f1); color: #fff; cursor: pointer; font-weight: 700; }
        .toolbar a { text-decoration: none; color: var(--primary); font-weight: 700; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 14px;
            margin-top: 18px;
        }
        .card {
            border-radius: 24px;
            border: 1px solid var(--line);
            background: var(--card);
            box-shadow: 0 16px 38px rgba(79,70,229,0.11);
            overflow: hidden;
            animation: rise 0.5s ease;
        }
        .card-inner {
            padding: 16px;
        }
        .title {
            margin: 0;
            font-size: 1.2rem;
            letter-spacing: 0.01em;
        }
        .meta {
            margin: 10px 0 14px;
            color: #4b5563;
            font-size: 0.93rem;
            line-height: 1.7;
        }
        .meta b { color: #111827; }
        .profile-link {
            display: inline-block;
            margin-bottom: 12px;
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
        }
        .thumbs {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(95px, 1fr));
            gap: 10px;
        }
        .thumb {
            border: 1px solid rgba(17,24,39,0.12);
            border-radius: 14px;
            overflow: hidden;
            cursor: pointer;
            aspect-ratio: 1 / 1;
            position: relative;
            transition: transform .2s ease, box-shadow .2s ease;
            background: #f8fafc;
        }
        .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .3s ease;
        }
        .thumb:hover { transform: translateY(-4px); box-shadow: 0 14px 28px rgba(17,24,39,.16); }
        .thumb:hover img { transform: scale(1.08); }
        .empty-image {
            color: #6b7280;
            font-size: 0.88rem;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .pagination-wrap {
            margin-top: 18px;
            border: 1px solid rgba(17,24,39,0.12);
            border-radius: 18px;
            padding: 14px;
            background: rgba(255,255,255,.88);
            box-shadow: 0 16px 30px rgba(2,6,23,.08);
        }
        .lightbox {
            position: fixed;
            inset: 0;
            background: rgba(15,23,42,.72);
            z-index: 30;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            backdrop-filter: blur(3px);
        }
        .lightbox img {
            max-width: min(92vw, 960px);
            max-height: 90vh;
            border-radius: 16px;
            border: 2px solid #fff;
            box-shadow: 0 20px 70px rgba(0,0,0,.5);
        }
        .hover-preview {
            position: fixed;
            inset: 0;
            z-index: 45;
            display: none;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        .hover-preview img {
            width: 75vw;
            height: 75vh;
            max-width: 75vw;
            max-height: 75vh;
            object-fit: contain;
            border-radius: 16px;
            border: 2px solid #fff;
            box-shadow: 0 20px 80px rgba(0,0,0,.55);
            background: #fff;
        }
        .pulse {
            position: relative;
            overflow: hidden;
        }
        .pulse::after {
            content: "";
            position: absolute;
            width: 180px;
            aspect-ratio: 1;
            right: -60px;
            top: -60px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(16,185,129,.35) 0, transparent 68%);
            animation: orbit 4.5s infinite linear;
        }
        @keyframes rise {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes orbit {
            from { transform: rotate(0deg) translateX(0); }
            to { transform: rotate(360deg) translateX(0); }
        }
        .no-result {
            margin-top: 10px;
            border: 1px dashed rgba(75,85,99,.45);
            border-radius: 16px;
            padding: 16px;
            background: rgba(255,255,255,.68);
            text-align: center;
            color: #374151;
        }
        .area-filter {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }
        .chip {
            border-radius: 999px;
            border: 1px solid rgba(17,24,39,.18);
            background: #fff;
            padding: 8px 12px;
            font-size: 0.83rem;
            color: #334155;
            text-decoration: none;
        }
        .chip.active {
            color: #fff;
            border-color: var(--primary);
            background: linear-gradient(135deg, var(--primary), #6366f1);
        }
        @media (max-width: 860px) {
            .stats { grid-template-columns: 1fr; }
            .toolbar { flex-direction: column; align-items: stretch; }
        }
    </style>
</head>
<body>
@php
    $recentCaptured = is_string($stats['recent_captured_at'] ?? null) ? \Illuminate\Support\Carbon::parse($stats['recent_captured_at']) : $stats['recent_captured_at'];
@endphp
<div class="page">
    <section class="hero">
        <div>
            <h1>85sugarbaby 會員資料看板</h1>
            <p>
                每筆資料都會照「<strong>抓取時間新到舊</strong>」排序，並顯示個資欄位與圖片。
                目前 Source：<strong>{{ $source }}</strong>，每次抓取會去重複存入同一個人。
            </p>
        </div>
        <div class="stats">
            <div class="stat"><div class="label">總筆數</div><div class="value">{{ number_format((int) $stats['total']) }}</div></div>
            <div class="stat"><div class="label">台北</div><div class="value">{{ number_format((int) $stats['taibei']) }}</div></div>
            <div class="stat"><div class="label">新北</div><div class="value">{{ number_format((int) $stats['newTaipei']) }}</div></div>
            <div class="stat"><div class="label">最近抓取</div><div class="value">{{ $recentCaptured instanceof \Illuminate\Support\Carbon ? $recentCaptured->format('m/d H:i') : '-' }}</div></div>
        </div>

        <div class="toolbar">
            <form method="get" action="{{ route('crawler.profiles') }}">
                <input type="hidden" name="source" value="{{ $source }}">
                <input name="q" value="{{ $query }}" placeholder="搜尋暱稱 / user_id / URL">
                <select name="area">
                    <option value="">全部地區</option>
                    @foreach ($areas as $item)
                        <option value="{{ $item }}" @selected($selectedArea === $item)>{{ $item }}</option>
                    @endforeach
                </select>
                <select name="per_page">
                    @foreach ([12, 24, 36, 48] as $item)
                        <option value="{{ $item }}" @selected((int) $perPage === $item)>{{ $item }} 張/頁</option>
                    @endforeach
                </select>
                <button type="submit">查詢</button>
                @if($query !== '' || $selectedArea !== '')
                    <a href="{{ route('crawler.profiles', ['source' => $source]) }}">清除條件</a>
                @endif
            </form>

            <div class="area-filter">
                <a class="chip {{ $selectedArea === '' ? 'active' : '' }}" href="{{ route('crawler.profiles', ['source'=>$source, 'per_page'=>$perPage, 'q'=>$query]) }}">全部</a>
                @foreach ($areas as $item)
                    <a class="chip {{ $selectedArea === $item ? 'active' : '' }}" href="{{ route('crawler.profiles', ['source' => $source, 'area' => $item, 'per_page' => $perPage, 'q' => $query]) }}">{{ $item }}</a>
                @endforeach
            </div>
        </div>
    </section>

    @if($candidates->isEmpty())
        <section class="no-result">目前還沒有這個 source 的資料，先執行一次 `crawler:85sugarbaby-import` 後再回來。</section>
    @else
        <section class="grid">
            @foreach($candidates as $candidate)
                <article class="card pulse">
                    <div class="card-inner">
                        <h2 class="title">{{ $candidate->nickname ?: '未命名' }}</h2>
                        <div class="meta">
                            年齡：<b>{{ (int) $candidate->age }}</b> / 地區：<b>{{ $candidate->area ?: '-' }}</b><br>
                            使用者ID：<b>{{ $candidate->external_user_id }}</b><br>
                            建檔時間：<b>{{ optional($candidate->captured_at)->format('Y-m-d H:i:s') ?? '-' }}</b>
                        </div>
                        <a class="profile-link" href="{{ $candidate->profile_url }}" target="_blank" rel="noreferrer">查看個人頁面</a>
                        <div class="thumbs">
                            @forelse($candidate->images as $image)
                                @php
                                    $proxyImage = route('crawler.image-proxy', ['url' => $image->image_url]);
                                @endphp
                                <button class="thumb" type="button" data-lightbox="{{ $image->image_url }}">
                                    <img
                                        src="{{ $image->image_url }}"
                                        data-original-src="{{ $image->image_url }}"
                                        data-proxy-src="{{ $proxyImage }}"
                                        loading="lazy"
                                        alt="image {{ $candidate->external_user_id }}"
                                        onerror="
                                            const fallback = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent('<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"480\" height=\"480\"><defs><linearGradient id=\"bg\" x1=\"0\" y1=\"0\" x2=\"1\" y2=\"1\"><stop offset=\"0%\" stop-color=\"#dbeafe\"/><stop offset=\"100%\" stop-color=\"#bbf7d0\"/></linearGradient></defs><rect width=\"480\" height=\"480\" fill=\"url(%23bg)\"/><text x=\"50%\" y=\"50%\" text-anchor=\"middle\" dominant-baseline=\"middle\" fill=\"%23374151\" font-size=\"20\" font-family=\"Arial\">圖片無法讀取</text></svg>');
                                            if (this.dataset.broken === '1') {
                                                this.src = fallback;
                                                return;
                                            }
                                            this.dataset.broken = '1';
                                            if (this.dataset.proxySrc && this.src !== this.dataset.proxySrc) {
                                                this.src = this.dataset.proxySrc;
                                                return;
                                            }
                                            this.src = fallback;
                                        "
                                    >
                                </button>
                            @empty
                                <div class="empty-image">尚未取得圖片</div>
                            @endforelse
                        </div>
                    </div>
                </article>
            @endforeach
        </section>

        <div class="pagination-wrap">
            {{ $candidates->links() }}
        </div>
    @endif
</div>

<div class="lightbox" id="lightbox">
    <img id="lightbox-image" src="" alt="profile image">
</div>

<div class="hover-preview" id="hover-preview">
    <img id="hover-preview-image" src="" alt="hover preview image">
</div>

<script>
    (function () {
        const layer = document.getElementById('lightbox');
        const picture = document.getElementById('lightbox-image');
        const hoverLayer = document.getElementById('hover-preview');
        const hoverImage = document.getElementById('hover-preview-image');

        const showHover = (src) => {
            if (!src || hoverImage.src === src) {
                return;
            }
            hoverImage.src = src;
            hoverLayer.style.display = 'flex';
        };

        const hideHover = () => {
            hoverLayer.style.display = 'none';
        };

        document.querySelectorAll('[data-lightbox]').forEach((btn) => {
            btn.addEventListener('mouseenter', () => {
                const src = btn.getAttribute('data-lightbox');
                showHover(src);
            });
            btn.addEventListener('mouseleave', () => {
                hideHover();
            });
            btn.addEventListener('focus', () => {
                const src = btn.getAttribute('data-lightbox');
                showHover(src);
            });
            btn.addEventListener('blur', () => {
                hideHover();
            });
            btn.addEventListener('click', () => {
                const src = btn.getAttribute('data-lightbox');
                if (!src) {
                    return;
                }
                picture.src = src;
                layer.style.display = 'flex';
            });
        });

        layer.addEventListener('click', () => {
            layer.style.display = 'none';
            picture.removeAttribute('src');
        });
    })();
</script>
</body>
</html>
