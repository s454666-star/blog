<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>人臉作品分群</title>
    <style>
        :root{
            --bg:#f8fcff;
            --bg-soft:#eff7f4;
            --ink:#233546;
            --muted:#6c8194;
            --line:rgba(35,53,70,.12);
            --line-strong:rgba(35,53,70,.18);
            --card:rgba(255,255,255,.84);
            --card-strong:rgba(255,255,255,.94);
            --shadow:0 22px 60px rgba(97,126,148,.17);
            --mint:#a9decf;
            --rose:#f7cfdd;
            --sky:#a7c9f2;
            --sun:#f7e9b4;
            --accent:#619fe7;
            --accent-strong:#4ab69f;
            --danger:#df758a;
            --radius-xl:30px;
            --radius-lg:22px;
            --radius-md:18px;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            color:var(--ink);
            font-family:"Segoe UI Variable","Microsoft JhengHei UI","PingFang TC","Noto Sans TC",sans-serif;
            min-height:100vh;
            background:
                radial-gradient(circle at top left, rgba(169,222,207,.45), transparent 28%),
                radial-gradient(circle at 88% 10%, rgba(247,207,221,.44), transparent 24%),
                radial-gradient(circle at 78% 82%, rgba(167,201,242,.30), transparent 26%),
                linear-gradient(145deg, #f9fdff 0%, #eef8fb 46%, #fbfdfd 100%);
        }

        body::before,
        body::after{
            content:"";
            position:fixed;
            width:30rem;
            height:30rem;
            border-radius:999px;
            filter:blur(48px);
            opacity:.42;
            pointer-events:none;
            z-index:0;
            animation:floatBlob 13s ease-in-out infinite;
        }

        body::before{
            top:-8rem;
            left:-8rem;
            background:rgba(169,222,207,.56);
        }

        body::after{
            right:-9rem;
            bottom:-9rem;
            background:rgba(247,207,221,.48);
            animation-delay:-5s;
        }

        @keyframes floatBlob{
            0%,100%{transform:translate3d(0,0,0) scale(1)}
            50%{transform:translate3d(1.8rem,-1.2rem,0) scale(1.06)}
        }

        @keyframes riseIn{
            from{opacity:0;transform:translateY(18px)}
            to{opacity:1;transform:translateY(0)}
        }

        .page{
            position:relative;
            z-index:1;
            max-width:1560px;
            margin:0 auto;
            padding:28px 18px 64px;
        }

        .hero,
        .group-card,
        .toolbar{
            backdrop-filter:blur(16px);
        }

        .hero{
            display:grid;
            grid-template-columns:1.35fr .95fr;
            gap:18px;
            padding:24px;
            border-radius:var(--radius-xl);
            border:1px solid var(--line);
            background:linear-gradient(160deg, rgba(255,255,255,.94), rgba(255,255,255,.78));
            box-shadow:var(--shadow);
            animation:riseIn .45s ease;
        }

        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:.55rem;
            width:max-content;
            padding:.55rem .9rem;
            border-radius:999px;
            color:#4574a0;
            background:rgba(97,159,231,.11);
            border:1px solid rgba(97,159,231,.16);
            font-size:.8rem;
            font-weight:800;
            letter-spacing:.05em;
        }

        h1{
            margin:.9rem 0 .8rem;
            font-family:"Georgia","Times New Roman","Noto Serif TC",serif;
            font-size:clamp(2.2rem, 3.8vw, 3.35rem);
            line-height:1.03;
            font-weight:700;
        }

        .lead{
            margin:0;
            max-width:50rem;
            line-height:1.75;
            color:var(--muted);
            font-size:1rem;
        }

        .hero-side{
            display:grid;
            gap:14px;
        }

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:12px;
        }

        .stat{
            padding:16px 16px 14px;
            border-radius:18px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.72);
        }

        .stat-label{
            color:var(--muted);
            font-size:.83rem;
            margin-bottom:.45rem;
        }

        .stat-value{
            font-size:1.55rem;
            font-weight:800;
        }

        .search-shell{
            display:flex;
            gap:12px;
            align-items:center;
            justify-content:space-between;
            padding:14px;
            border-radius:20px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.75);
            flex-wrap:wrap;
        }

        .search-shell form{
            display:flex;
            gap:10px;
            flex:1;
            flex-wrap:wrap;
        }

        .search-input{
            min-width:260px;
            flex:1;
            padding:14px 16px;
            border-radius:16px;
            border:1px solid var(--line-strong);
            background:rgba(255,255,255,.94);
            color:var(--ink);
            font-size:.96rem;
            outline:none;
            transition:border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .search-input:focus{
            border-color:rgba(97,159,231,.42);
            box-shadow:0 0 0 5px rgba(97,159,231,.10);
            transform:translateY(-1px);
        }

        .btn{
            appearance:none;
            border:none;
            cursor:pointer;
            border-radius:16px;
            padding:13px 16px;
            font-size:.94rem;
            font-weight:800;
            transition:transform .18s ease, box-shadow .18s ease, opacity .18s ease;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn:hover{transform:translateY(-1px)}

        .btn-primary{
            color:#fff;
            background:linear-gradient(135deg, #69a8ef, #4db9a4);
            box-shadow:0 16px 34px rgba(97,159,231,.24);
        }

        .btn-soft{
            color:#456177;
            background:rgba(255,255,255,.9);
            border:1px solid var(--line);
        }

        .toolbar{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:14px;
            margin:20px 0 18px;
            padding:14px 18px;
            border-radius:22px;
            border:1px solid var(--line);
            background:linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.82));
            box-shadow:0 14px 34px rgba(97,126,148,.13);
            flex-wrap:wrap;
        }

        .chip-row{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .chip{
            display:inline-flex;
            align-items:center;
            gap:.45rem;
            padding:.64rem .92rem;
            border-radius:999px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.84);
            color:var(--muted);
            font-size:.88rem;
        }

        .chip strong{color:var(--ink)}

        .groups{
            display:grid;
            gap:20px;
        }

        .group-card{
            padding:22px;
            border-radius:var(--radius-xl);
            border:1px solid rgba(97,159,231,.16);
            background:
                linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,255,255,.84)),
                radial-gradient(circle at top right, rgba(169,222,207,.18), transparent 28%);
            box-shadow:var(--shadow);
            animation:riseIn .38s ease;
        }

        .group-head{
            display:grid;
            grid-template-columns:minmax(0, 1fr) minmax(240px, 320px);
            gap:18px;
            align-items:stretch;
        }

        .group-meta{
            display:flex;
            flex-direction:column;
            gap:14px;
        }

        .group-badge{
            display:inline-flex;
            width:max-content;
            align-items:center;
            gap:.55rem;
            padding:.55rem .86rem;
            border-radius:999px;
            color:#437198;
            background:rgba(167,201,242,.20);
            border:1px solid rgba(167,201,242,.24);
            font-size:.85rem;
            font-weight:800;
            letter-spacing:.04em;
        }

        .group-title{
            margin:0;
            font-size:1.85rem;
            font-weight:800;
        }

        .group-subtitle{
            margin:0;
            color:var(--muted);
            line-height:1.72;
        }

        .group-stats{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
        }

        .mini-stat{
            padding:.7rem .95rem;
            border-radius:16px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.82);
            color:var(--muted);
            font-size:.88rem;
        }

        .mini-stat strong{
            display:block;
            color:var(--ink);
            font-size:1rem;
            margin-top:.2rem;
        }

        .cover-shell{
            position:relative;
            overflow:hidden;
            min-height:220px;
            border-radius:24px;
            border:1px solid var(--line);
            background:
                linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.05)),
                linear-gradient(145deg, rgba(169,222,207,.44), rgba(167,201,242,.36), rgba(247,207,221,.38));
        }

        .cover-shell img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }

        .cover-shell::after{
            content:"";
            position:absolute;
            inset:auto 0 0 0;
            height:45%;
            background:linear-gradient(180deg, transparent, rgba(19,35,51,.34));
        }

        .cover-caption{
            position:absolute;
            left:16px;
            right:16px;
            bottom:14px;
            z-index:1;
            color:#fff;
            font-size:.9rem;
            font-weight:700;
            text-shadow:0 2px 8px rgba(0,0,0,.22);
        }

        .works{
            display:grid;
            grid-template-columns:repeat(auto-fit, minmax(250px, 1fr));
            gap:16px;
            margin-top:20px;
        }

        .work-card{
            position:relative;
            overflow:hidden;
            border-radius:22px;
            border:1px solid var(--line);
            background:linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,255,255,.86));
            box-shadow:0 14px 32px rgba(97,126,148,.11);
            transition:transform .2s ease, box-shadow .2s ease, opacity .22s ease;
        }

        .work-card:hover{
            transform:translateY(-2px);
            box-shadow:0 22px 44px rgba(97,126,148,.16);
        }

        .work-card.is-busy{
            opacity:.62;
            pointer-events:none;
        }

        .work-card.is-detached{
            transform:scale(.98);
            opacity:0;
        }

        .work-thumb{
            position:relative;
            aspect-ratio:16 / 9;
            overflow:hidden;
            background:#0f1720;
            border-bottom:1px solid var(--line);
        }

        .work-thumb img{
            width:100%;
            height:100%;
            object-fit:contain;
            display:block;
            background:#0f1720;
        }

        .work-thumb video{
            width:100%;
            height:100%;
            object-fit:contain;
            display:block;
            background:#0f1720;
        }

        .work-badges{
            position:absolute;
            left:12px;
            right:52px;
            top:12px;
            display:flex;
            gap:8px;
            flex-wrap:wrap;
        }

        .pill{
            display:inline-flex;
            align-items:center;
            gap:.35rem;
            padding:.46rem .72rem;
            border-radius:999px;
            font-size:.76rem;
            font-weight:800;
            backdrop-filter:blur(10px);
        }

        .pill-soft{
            color:#3f627c;
            background:rgba(255,255,255,.78);
            border:1px solid rgba(255,255,255,.48);
        }

        .pill-manual{
            color:#8d4760;
            background:rgba(247,207,221,.82);
            border:1px solid rgba(223,117,138,.18);
        }

        .detach-btn{
            position:absolute;
            top:12px;
            right:12px;
            width:34px;
            height:34px;
            border:none;
            cursor:pointer;
            border-radius:999px;
            color:#fff;
            background:linear-gradient(135deg, #ed95a8, #d96d83);
            box-shadow:0 10px 24px rgba(223,117,138,.28);
            font-size:1rem;
            font-weight:900;
            transition:transform .18s ease, box-shadow .18s ease;
        }

        .detach-btn:hover{
            transform:scale(1.05);
            box-shadow:0 16px 28px rgba(223,117,138,.34);
        }

        .work-body{
            padding:16px 16px 18px;
            display:grid;
            gap:10px;
        }

        .work-name{
            margin:0;
            font-size:1.02rem;
            line-height:1.5;
            font-weight:800;
            word-break:break-word;
        }

        .work-path{
            margin:0;
            color:var(--muted);
            line-height:1.65;
            font-size:.88rem;
            word-break:break-all;
        }

        .meta-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:10px;
        }

        .sample-section{
            display:grid;
            gap:10px;
        }

        .sample-gallery{
            display:grid;
            grid-template-rows:repeat(3, minmax(0, 168px));
            grid-auto-flow:column;
            grid-auto-columns:minmax(168px, 168px);
            gap:12px;
            overflow-x:auto;
            padding:4px 6px 10px 0;
            scrollbar-width:thin;
        }

        .sample-thumb{
            position:relative;
            display:block;
            aspect-ratio:1 / 1;
            overflow:hidden;
            border-radius:14px;
            border:1px solid var(--line);
            background:linear-gradient(145deg, rgba(255,255,255,.92), rgba(167,201,242,.18));
        }

        .sample-thumb img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
        }

        .sample-index{
            position:absolute;
            left:8px;
            bottom:8px;
            padding:.28rem .48rem;
            border-radius:999px;
            font-size:.72rem;
            font-weight:800;
            color:#fff;
            background:rgba(27,45,64,.62);
            backdrop-filter:blur(10px);
        }

        .meta-box{
            padding:.78rem .88rem;
            border-radius:15px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.82);
        }

        .meta-label{
            color:var(--muted);
            font-size:.76rem;
            margin-bottom:.3rem;
        }

        .meta-value{
            color:var(--ink);
            font-size:.92rem;
            font-weight:800;
        }

        .empty{
            padding:42px 24px;
            text-align:center;
            border-radius:var(--radius-xl);
            border:1px solid var(--line);
            background:linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.84));
            box-shadow:var(--shadow);
        }

        .empty h2{
            margin:0 0 .8rem;
            font-size:1.7rem;
        }

        .empty p{
            margin:0;
            color:var(--muted);
            line-height:1.8;
        }

        .pagination-shell{
            margin-top:20px;
            padding:14px 18px;
            border-radius:22px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.84);
            box-shadow:0 14px 30px rgba(97,126,148,.11);
            overflow:auto;
        }

        .pagination-shell nav{
            display:flex;
            justify-content:center;
        }

        @media (max-width: 1080px){
            .hero,
            .group-head{
                grid-template-columns:1fr;
            }
        }

        @media (max-width: 720px){
            .page{padding:18px 14px 42px}
            .hero,
            .group-card{padding:18px}
            .stat-grid,
            .meta-grid{grid-template-columns:1fr}
            .works{grid-template-columns:1fr}
            .sample-gallery{
                grid-template-rows:repeat(3, minmax(0, 132px));
                grid-auto-columns:minmax(132px, 132px);
                gap:10px;
            }
            h1{font-size:2rem}
        }
    </style>
</head>
<body>
@php
    $lastScanned = $stats['last_scanned_at'] ?? null;
    if (is_string($lastScanned) && $lastScanned !== '') {
        $lastScanned = \Illuminate\Support\Carbon::parse($lastScanned);
    }
@endphp
<div class="page">
    <section class="hero">
        <div>
            <span class="eyebrow">Face Identity Studio</span>
            <h1>同一個人的作品，現在獨立成一套人臉分群頁。</h1>
            <p class="lead">
                這個頁面只讀取新的 `face_identity_*` 表，不會混進原本影片管理功能。
                系統會從 `train` 專案每 1 秒檢查一次畫面，以清晰人臉為優先，允許微側臉與微歪角度，但會剃除明顯側臉；一旦抓到有效樣本，就直接往後跳 20 秒再繼續，比對人物特徵後歸到同一編號。若有誤判，直接按作品右上角的 `X` 就能拆成新的群組。
            </p>
        </div>
        <div class="hero-side">
            <div class="stat-grid">
                <div class="stat">
                    <div class="stat-label">人物群組</div>
                    <div class="stat-value">{{ number_format((int) ($stats['people_count'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">作品總數</div>
                    <div class="stat-value">{{ number_format((int) ($stats['video_count'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">手動鎖定</div>
                    <div class="stat-value">{{ number_format((int) ($stats['manual_lock_count'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">最近掃描</div>
                    <div class="stat-value" style="font-size:1rem;">
                        {{ $lastScanned instanceof \Illuminate\Support\Carbon ? $lastScanned->format('Y-m-d H:i') : '尚未掃描' }}
                    </div>
                </div>
            </div>

            <div class="search-shell">
                <form method="get" action="{{ route('face-identities.index') }}">
                    <input
                        class="search-input"
                        type="search"
                        name="q"
                        value="{{ $q }}"
                        placeholder="搜尋人物編號、影片檔名、路徑或來源資料夾">
                    <select class="search-input" name="per_page" aria-label="每頁筆數">
                        @foreach (($perPageOptions ?? [50, 100, 200, 500]) as $option)
                            <option value="{{ $option }}" @selected((int) ($perPage ?? 50) === (int) $option)>
                                每頁 {{ $option }} 群
                            </option>
                        @endforeach
                    </select>
                    <button class="btn btn-primary" type="submit">查詢分群</button>
                </form>
                @if ($q !== '')
                    <a class="btn btn-soft" href="{{ route('face-identities.index', ['per_page' => $perPage ?? 50]) }}">清除搜尋</a>
                @endif
            </div>
        </div>
    </section>

    <section class="toolbar">
        <div class="chip-row">
            <span class="chip"><strong>抽樣規則</strong> 每 1 秒檢查一次；抓到一張後往後跳 20 秒，最多收 20 張清晰人臉，明顯側臉會剃除</span>
            <span class="chip"><strong>比對方式</strong> Facenet embedding + 影片對影片交叉驗證，重建群組時會避免鏈式誤併</span>
        </div>
        <div class="chip-row">
            <span class="chip"><strong>解除分群</strong> 點作品右上角 `X` 即拆成新編號</span>
        </div>
    </section>

    @if ($people->isEmpty())
        <section class="empty">
            <h2>目前還沒有可顯示的人臉作品群組</h2>
            <p>先在 `C:\Users\User\Pictures\train` 執行 `python face_identity_scan.py`，系統會把掃描結果寫進新的 face identity 資料表，之後這裡就會自動出現。</p>
        </section>
    @else
        <section class="groups">
            @foreach ($people as $person)
                <article class="group-card">
                    <div class="group-head">
                        <div class="group-meta">
                            <span class="group-badge">人物編號 {{ $person->display_code }}</span>
                            <h2 class="group-title">同一個人的作品群</h2>
                            <p class="group-subtitle">
                                這個群組目前收錄 {{ number_format((int) $person->video_count) }} 部作品，
                                累積 {{ number_format((int) $person->sample_count) }} 張有效樣本。
                                任何誤歸類的作品都可以直接拆走，不會影響舊系統頁面。
                            </p>
                            <div class="group-stats">
                                <span class="mini-stat">作品數<strong>{{ number_format((int) $person->video_count) }}</strong></span>
                                <span class="mini-stat">樣本數<strong>{{ number_format((int) $person->sample_count) }}</strong></span>
                                <span class="mini-stat">最近辨識<strong>{{ optional($person->last_seen_at)->format('Y-m-d H:i') ?? '-' }}</strong></span>
                            </div>
                        </div>

                        <div class="cover-shell">
                            @if ($person->cover_image_url)
                                <img src="{{ $person->cover_image_url }}" alt="人物封面">
                            @endif
                            <div class="cover-caption">封面使用該群組目前最佳樣本</div>
                        </div>
                    </div>

                    <div class="works">
                        @foreach ($person->videos as $video)
                            <article class="work-card" data-video-card data-video-id="{{ $video->id }}">
                                <div class="work-thumb">
                                    @if ($video->stream_url)
                                        <video
                                            controls
                                            playsinline
                                            preload="metadata"
                                            poster="{{ $video->preview_image_url ?: '' }}">
                                            <source src="{{ $video->stream_url }}">
                                        </video>
                                    @elseif ($video->preview_image_url)
                                        <img src="{{ $video->preview_image_url }}" alt="{{ $video->file_name }}">
                                    @endif

                                    <div class="work-badges">
                                        <span class="pill pill-soft">{{ $video->source_root_label ?: '未命名來源' }}</span>
                                        @if ($video->group_locked)
                                            <span class="pill pill-manual">手動鎖定</span>
                                        @endif
                                    </div>

                                    <button
                                        class="detach-btn"
                                        type="button"
                                        data-detach-button
                                        data-url="{{ route('face-identities.detach', $video) }}"
                                        title="解除此作品的同人分群">X</button>
                                </div>

                                <div class="work-body">
                                    <h3 class="work-name">{{ $video->file_name }}</h3>
                                    <p class="work-path">{{ $video->relative_path }}</p>

                                    <div class="meta-grid">
                                        <div class="meta-box">
                                            <div class="meta-label">作品長度</div>
                                            <div class="meta-value">{{ $video->display_duration }}</div>
                                        </div>
                                        <div class="meta-box">
                                            <div class="meta-label">有效樣本</div>
                                            <div class="meta-value">{{ number_format((int) $video->accepted_sample_count) }}</div>
                                        </div>
                                        <div class="meta-box">
                                            <div class="meta-label">分群來源</div>
                                            <div class="meta-value">{{ $video->assignment_source === 'manual' ? '手動' : '自動' }}</div>
                                        </div>
                                        <div class="meta-box">
                                            <div class="meta-label">比對信心</div>
                                            <div class="meta-value">
                                                {{ $video->match_confidence !== null ? number_format((float) $video->match_confidence, 3) : '-' }}
                                            </div>
                                        </div>
                                    </div>

                                    <p class="work-path">最後掃描：{{ optional($video->last_scanned_at)->format('Y-m-d H:i:s') ?? '-' }}</p>

                                    @if ($video->samples->isNotEmpty())
                                        <div class="sample-section">
                                            <div class="meta-label">本作品全部抓到的照片</div>
                                            <div class="sample-gallery">
                                                @foreach ($video->samples as $sample)
                                                    <a
                                                        class="sample-thumb"
                                                        href="{{ $sample->image_url }}"
                                                        target="_blank"
                                                        rel="noreferrer">
                                                        <img
                                                            src="{{ $sample->image_url }}"
                                                            alt="{{ $video->file_name }} sample {{ $sample->capture_order }}">
                                                        <span class="sample-index">#{{ $sample->capture_order }}</span>
                                                    </a>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </article>
                        @endforeach
                    </div>
                </article>
            @endforeach
        </section>

        <div class="pagination-shell">
            {{ $people->links() }}
        </div>
    @endif
</div>

<script>
    (() => {
        const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        const buttons = document.querySelectorAll('[data-detach-button]');

        buttons.forEach((button) => {
            button.addEventListener('click', async () => {
                if (!csrf) {
                    alert('缺少 CSRF token，無法解除分群。');
                    return;
                }

                if (!window.confirm('確定把這部作品拆出目前人物群組？系統會給它新的編號。')) {
                    return;
                }

                const card = button.closest('[data-video-card]');
                card?.classList.add('is-busy');

                try {
                    const response = await fetch(button.dataset.url, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrf,
                        },
                        body: JSON.stringify({
                            note: 'detached from face identities web',
                        }),
                    });

                    const payload = await response.json().catch(() => ({}));
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || '解除分群失敗');
                    }

                    card?.classList.remove('is-busy');
                    card?.classList.add('is-detached');
                    window.setTimeout(() => window.location.reload(), 320);
                } catch (error) {
                    card?.classList.remove('is-busy');
                    alert(error instanceof Error ? error.message : '解除分群失敗');
                }
            });
        });
    })();
</script>
</body>
</html>
