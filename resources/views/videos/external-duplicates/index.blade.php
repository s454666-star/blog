<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>外部重複影片審核</title>
    <style>
        :root{
            --bg:#f6fbff;
            --bg-soft:#eef7f8;
            --ink:#213547;
            --muted:#607489;
            --line:rgba(33,53,71,.12);
            --line-strong:rgba(33,53,71,.18);
            --card:rgba(255,255,255,.86);
            --card-strong:rgba(255,255,255,.96);
            --accent:#5d9cec;
            --accent-soft:#9ed8cc;
            --rose:#f5cbd8;
            --sun:#f7e1ab;
            --good:#4ebca3;
            --warn:#f0b35f;
            --soft:#9eb7cc;
            --excellent:#3ea1df;
            --danger:#dd6b7b;
            --shadow:0 22px 60px rgba(76, 111, 137, .18);
            --radius-xl:28px;
            --radius-lg:20px;
            --radius-md:16px;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            color:var(--ink);
            font-family:"Segoe UI Variable","Microsoft JhengHei UI","PingFang TC","Noto Sans TC",sans-serif;
            background:
                radial-gradient(circle at top left, rgba(158,216,204,.42), transparent 28%),
                radial-gradient(circle at 90% 12%, rgba(245,203,216,.34), transparent 24%),
                radial-gradient(circle at 72% 88%, rgba(93,156,236,.20), transparent 30%),
                linear-gradient(145deg, #f9fcff 0%, #eff7fb 48%, #f8fbff 100%);
            min-height:100vh;
        }

        body::before,
        body::after{
            content:"";
            position:fixed;
            inset:auto;
            width:28rem;
            height:28rem;
            border-radius:999px;
            filter:blur(40px);
            opacity:.35;
            pointer-events:none;
            animation:floatBlob 12s ease-in-out infinite;
            z-index:0;
        }

        body::before{
            top:-8rem;
            left:-7rem;
            background:rgba(158,216,204,.55);
        }

        body::after{
            right:-7rem;
            bottom:-8rem;
            background:rgba(245,203,216,.44);
            animation-delay:-4s;
        }

        @keyframes floatBlob{
            0%,100%{transform:translate3d(0,0,0) scale(1)}
            50%{transform:translate3d(1.5rem,-1rem,0) scale(1.05)}
        }

        @keyframes riseIn{
            from{opacity:0; transform:translateY(16px)}
            to{opacity:1; transform:translateY(0)}
        }

        .page{
            position:relative;
            z-index:1;
            max-width:1500px;
            margin:0 auto;
            padding:28px 18px 64px;
        }

        .hero,
        .toolbar,
        .match-card{
            backdrop-filter:blur(16px);
        }

        .hero{
            display:grid;
            grid-template-columns:1.5fr 1fr;
            gap:18px;
            padding:22px;
            border:1px solid var(--line);
            border-radius:var(--radius-xl);
            background:linear-gradient(160deg, rgba(255,255,255,.94), rgba(255,255,255,.78));
            box-shadow:var(--shadow);
            animation:riseIn .45s ease;
        }

        .hero-title{
            display:flex;
            flex-direction:column;
            gap:12px;
        }

        .eyebrow{
            display:inline-flex;
            width:max-content;
            align-items:center;
            gap:.55rem;
            padding:.52rem .86rem;
            border-radius:999px;
            background:rgba(93,156,236,.12);
            border:1px solid rgba(93,156,236,.18);
            color:#3b6d97;
            font-size:.78rem;
            font-weight:700;
            letter-spacing:.04em;
        }

        h1{
            margin:0;
            font-family:"Georgia","Times New Roman","Noto Serif TC",serif;
            font-size:clamp(2rem, 3.2vw, 3.1rem);
            line-height:1.04;
            font-weight:700;
        }

        .lead{
            margin:0;
            color:var(--muted);
            font-size:1rem;
            line-height:1.7;
            max-width:52rem;
        }

        .hero-side{
            display:grid;
            gap:14px;
        }

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(3, minmax(0,1fr));
            gap:12px;
        }

        .stat{
            padding:16px 14px;
            border-radius:18px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.70);
        }

        .stat-label{
            color:var(--muted);
            font-size:.8rem;
            margin-bottom:.45rem;
        }

        .stat-value{
            font-size:1.5rem;
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
            background:rgba(255,255,255,.72);
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
            border-color:rgba(93,156,236,.45);
            box-shadow:0 0 0 5px rgba(93,156,236,.12);
            transform:translateY(-1px);
        }

        .btn{
            appearance:none;
            border:none;
            cursor:pointer;
            border-radius:16px;
            padding:13px 16px;
            font-size:.95rem;
            font-weight:700;
            transition:transform .18s ease, box-shadow .18s ease, opacity .18s ease;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
        }

        .btn:hover{transform:translateY(-1px)}
        .btn:disabled{opacity:.45; cursor:not-allowed; transform:none}

        .btn-primary{
            color:white;
            background:linear-gradient(135deg, #68a4ee, #4ebca3);
            box-shadow:0 14px 32px rgba(93,156,236,.28);
        }

        .btn-soft{
            color:#3f5870;
            background:rgba(255,255,255,.92);
            border:1px solid var(--line);
        }

        .btn-danger{
            color:white;
            background:linear-gradient(135deg, #ef8ea2, #d46877);
            box-shadow:0 14px 30px rgba(221,107,123,.24);
        }

        .toolbar{
            position:sticky;
            top:14px;
            z-index:12;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
            margin-top:18px;
            margin-bottom:18px;
            padding:14px 18px;
            border-radius:22px;
            border:1px solid var(--line);
            background:linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.80));
            box-shadow:0 14px 34px rgba(76,111,137,.14);
            flex-wrap:wrap;
        }

        .toolbar-left,
        .toolbar-right{
            display:flex;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .selection-chip,
        .tone-chip{
            display:inline-flex;
            align-items:center;
            gap:.45rem;
            padding:.62rem .9rem;
            border-radius:999px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.82);
            font-size:.88rem;
            color:var(--muted);
        }

        .selection-chip strong,
        .tone-chip strong{
            color:var(--ink);
        }

        .matches{
            display:grid;
            gap:20px;
        }

        .match-card{
            padding:22px;
            border-radius:var(--radius-xl);
            border:1px solid rgba(93,156,236,.16);
            background:
                linear-gradient(180deg, rgba(255,255,255,.94), rgba(255,255,255,.84)),
                radial-gradient(circle at top right, rgba(158,216,204,.18), transparent 26%);
            box-shadow:var(--shadow);
            animation:riseIn .4s ease;
        }

        .match-head{
            display:flex;
            justify-content:space-between;
            gap:16px;
            align-items:flex-start;
            margin-bottom:18px;
            flex-wrap:wrap;
        }

        .match-title{
            display:flex;
            gap:12px;
            align-items:flex-start;
        }

        .match-checkbox{
            width:1.08rem;
            height:1.08rem;
            margin-top:.35rem;
            accent-color:#5d9cec;
        }

        .match-name{
            margin:0;
            font-size:1.32rem;
            line-height:1.2;
        }

        .match-subtitle{
            margin-top:.4rem;
            color:var(--muted);
            font-size:.92rem;
            word-break:break-all;
        }

        .match-head-right{
            display:flex;
            gap:10px;
            flex-wrap:wrap;
            justify-content:flex-end;
        }

        .tone-chip.excellent{background:rgba(62,161,223,.13); border-color:rgba(62,161,223,.22)}
        .tone-chip.good{background:rgba(78,188,163,.13); border-color:rgba(78,188,163,.22)}
        .tone-chip.warn{background:rgba(240,179,95,.14); border-color:rgba(240,179,95,.24)}
        .tone-chip.soft{background:rgba(158,183,204,.18); border-color:rgba(158,183,204,.24)}
        .tone-chip.bad{background:rgba(221,107,123,.13); border-color:rgba(221,107,123,.22)}

        .compare-panels{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:16px;
            margin-bottom:18px;
        }

        .panel{
            padding:18px;
            border-radius:22px;
            background:var(--card);
            border:1px solid var(--line);
        }

        .panel-head{
            display:flex;
            justify-content:space-between;
            gap:12px;
            align-items:center;
            margin-bottom:14px;
            flex-wrap:wrap;
        }

        .panel-title{
            display:flex;
            flex-direction:column;
            gap:4px;
        }

        .panel-title strong{
            font-size:1rem;
        }

        .panel-title span{
            color:var(--muted);
            font-size:.85rem;
        }

        .video-box{
            position:relative;
            overflow:hidden;
            border-radius:18px;
            background:linear-gradient(135deg, rgba(93,156,236,.10), rgba(158,216,204,.10));
            border:1px solid rgba(93,156,236,.12);
            min-height:220px;
        }

        .video-box video{
            display:block;
            width:100%;
            aspect-ratio:16 / 9;
            background:#dfeaf2;
        }

        .video-fallback{
            min-height:220px;
            display:grid;
            place-items:center;
            text-align:center;
            color:var(--muted);
            padding:18px;
            line-height:1.8;
        }

        .meta-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:10px;
            margin-top:14px;
        }

        .meta{
            padding:12px 13px;
            border-radius:16px;
            background:rgba(255,255,255,.82);
            border:1px solid var(--line);
        }

        .meta-label{
            color:var(--muted);
            font-size:.76rem;
            margin-bottom:.3rem;
        }

        .meta-value{
            font-size:.94rem;
            line-height:1.45;
            word-break:break-word;
        }

        .compare-strip{
            display:grid;
            gap:12px;
        }

        .strip-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:10px;
            margin-bottom:2px;
            flex-wrap:wrap;
        }

        .strip-head h2{
            margin:0;
            font-size:1rem;
        }

        .strip-head p{
            margin:0;
            color:var(--muted);
            font-size:.86rem;
        }

        .frame-row{
            display:grid;
            grid-template-columns:minmax(0,1fr) 170px minmax(0,1fr);
            gap:12px;
            align-items:stretch;
            padding:12px;
            border-radius:20px;
            border:1px solid var(--line);
            background:rgba(255,255,255,.76);
        }

        .frame-figure{
            display:flex;
            flex-direction:column;
            gap:10px;
            padding:12px;
            border-radius:18px;
            background:var(--card-strong);
            border:1px solid rgba(93,156,236,.10);
        }

        .frame-figure img{
            width:100%;
            aspect-ratio:16 / 9;
            object-fit:cover;
            display:block;
            border-radius:14px;
            background:#dbe8ef;
        }

        .frame-caption{
            display:flex;
            justify-content:space-between;
            gap:10px;
            color:var(--muted);
            font-size:.82rem;
            flex-wrap:wrap;
        }

        .frame-center{
            display:flex;
            flex-direction:column;
            align-items:center;
            justify-content:center;
            gap:10px;
            padding:12px;
            border-radius:18px;
            background:linear-gradient(180deg, rgba(246,251,255,.96), rgba(238,247,248,.92));
            border:1px dashed rgba(93,156,236,.18);
            text-align:center;
        }

        .score-ring{
            width:86px;
            height:86px;
            border-radius:999px;
            display:grid;
            place-items:center;
            font-size:1.08rem;
            font-weight:800;
            color:var(--ink);
            background:
                radial-gradient(circle at center, rgba(255,255,255,.96) 52%, transparent 53%),
                conic-gradient(from -90deg, rgba(93,156,236,.92), rgba(78,188,163,.86), rgba(247,225,171,.86), rgba(93,156,236,.92));
            box-shadow:0 16px 36px rgba(93,156,236,.18);
        }

        .score-label{
            color:var(--muted);
            font-size:.82rem;
            line-height:1.5;
        }

        .missing-box{
            display:grid;
            place-items:center;
            min-height:160px;
            border-radius:16px;
            border:1px dashed rgba(221,107,123,.28);
            background:rgba(255,246,248,.9);
            color:#a05a68;
            text-align:center;
            padding:16px;
            line-height:1.7;
        }

        .footer-pager{
            display:flex;
            justify-content:center;
            gap:12px;
            align-items:center;
            margin-top:24px;
            flex-wrap:wrap;
        }

        .pager-text{
            color:var(--muted);
            font-size:.92rem;
        }

        .empty{
            padding:56px 18px;
            text-align:center;
            border-radius:28px;
            border:1px dashed rgba(93,156,236,.24);
            background:rgba(255,255,255,.70);
            color:var(--muted);
            box-shadow:var(--shadow);
        }

        .toast{
            position:fixed;
            right:18px;
            bottom:18px;
            z-index:30;
            min-width:260px;
            max-width:min(92vw, 420px);
            padding:14px 16px;
            border-radius:18px;
            box-shadow:0 18px 40px rgba(61, 88, 109, .18);
            border:1px solid rgba(33,53,71,.08);
            background:rgba(255,255,255,.96);
            color:var(--ink);
            transform:translateY(20px);
            opacity:0;
            pointer-events:none;
            transition:transform .22s ease, opacity .22s ease;
        }

        .toast.show{
            opacity:1;
            transform:translateY(0);
        }

        @media (max-width: 1100px){
            .hero,
            .compare-panels{
                grid-template-columns:1fr;
            }

            .frame-row{
                grid-template-columns:1fr;
            }

            .frame-center{
                order:-1;
            }
        }

        @media (max-width: 720px){
            .page{padding-inline:14px}
            .hero{padding:18px}
            .stat-grid{grid-template-columns:1fr}
            .meta-grid{grid-template-columns:1fr}
            .toolbar{padding:14px}
            .match-card{padding:18px}
        }
</style>
</head>
<body>
@php
    $humanBytes = function ($bytes) {
        $bytes = (int) ($bytes ?? 0);
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024.0 && $i < count($units) - 1) {
            $size /= 1024.0;
            $i++;
        }

        return $i === 0
            ? $bytes . ' ' . $units[$i]
            : number_format($size, 2) . ' ' . $units[$i];
    };
@endphp

<div class="page">
    <section class="hero">
        <div class="hero-title">
            <span class="eyebrow">External Duplicate Review</span>
            <h1>外部重複影片審核板</h1>
            <p class="lead">
                這裡只顯示外部掃描進來後，被判定與 DB 影片重複的檔案。卡片會直接把外部影片、DB 影片和 4 張截圖逐張對照，方便快速確認是否真的該刪。
            </p>
        </div>

        <div class="hero-side">
            <div class="stat-grid">
                <div class="stat">
                    <div class="stat-label">符合條件資料</div>
                    <div class="stat-value">{{ number_format((int) ($stats['total_matches'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">目前頁面仍存在檔案</div>
                    <div class="stat-value">{{ number_format((int) ($stats['existing_on_page'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">平均相似度</div>
                    <div class="stat-value">{{ number_format((float) ($stats['average_similarity'] ?? 0), 2) }}%</div>
                </div>
            </div>

            <div class="search-shell">
                <form method="GET" action="{{ route('videos.external-duplicates.index') }}">
                    <input
                        class="search-input"
                        type="text"
                        name="q"
                        value="{{ $q }}"
                        placeholder="搜尋檔名、來源路徑、疑似重複路徑、DB 影片名稱"
                    >
                    <button class="btn btn-primary" type="submit">搜尋</button>
                    @if ($q !== '')
                        <a class="btn btn-soft" href="{{ route('videos.external-duplicates.index') }}">清除</a>
                    @endif
                </form>
            </div>
        </div>
    </section>

    <section class="toolbar">
        <div class="toolbar-left">
            <label class="selection-chip">
                <input id="toggle-all" type="checkbox">
                <strong>全選本頁</strong>
            </label>
            <span class="selection-chip">已勾選 <strong id="selected-count">0</strong> 筆</span>
        </div>

        <div class="toolbar-right">
            <span class="selection-chip">批次刪除只會動到 <strong>疑似重複檔案</strong> 裡面的外部影片</span>
            <button id="batch-delete-btn" class="btn btn-danger" type="button" disabled>刪除勾選影片</button>
        </div>
    </section>

    @if ($matches->count() === 0)
        <section class="empty">
            <h2>目前沒有可審核的外部重複影片</h2>
            <p>先跑 `php artisan video:move-duplicates "D:\incoming"`，命中重複後才會出現在這裡。</p>
        </section>
    @else
        <section class="matches">
            @foreach ($matches as $match)
                @php
                    $feature = $match->matchedFeature;
                    $dbFrames = $feature?->frames?->keyBy('capture_order') ?? collect();
                    $toneClass = 'soft';
                    $similarityValue = (float) ($match->similarity_percent ?? 0);

                    if ($similarityValue >= 95) {
                        $toneClass = 'excellent';
                    } elseif ($similarityValue >= 90) {
                        $toneClass = 'good';
                    } elseif ($similarityValue >= 80) {
                        $toneClass = 'warn';
                    }
                @endphp
                <article class="match-card" data-match-id="{{ $match->id }}">
                    <div class="match-head">
                        <div class="match-title">
                            <input class="match-checkbox" data-match-checkbox type="checkbox" value="{{ $match->id }}">
                            <div>
                                <h2 class="match-name">{{ $match->file_name }}</h2>
                                <div class="match-subtitle">{{ $match->source_file_path }}</div>
                            </div>
                        </div>

                        <div class="match-head-right">
                            <span class="tone-chip {{ $toneClass }}"><strong>{{ number_format($similarityValue, 2) }}%</strong> 總相似度</span>
                            <span class="tone-chip"><strong>{{ (int) $match->matched_frames }}/{{ (int) $match->compared_frames }}</strong> 命中張數</span>
                            @if (!$match->duplicate_file_exists)
                                <span class="tone-chip bad"><strong>檔案已不存在</strong></span>
                            @endif
                        </div>
                    </div>

                    <div class="compare-panels">
                        <section class="panel">
                            <div class="panel-head">
                                <div class="panel-title">
                                    <strong>外部疑似重複檔</strong>
                                    <span>目前位於：{{ $match->duplicate_directory_path }}</span>
                                </div>
                            </div>

                            <div class="video-box">
                                @if ($match->duplicate_file_exists)
                                    <video controls preload="metadata" playsinline>
                                        <source src="{{ $match->external_stream_url }}">
                                    </video>
                                @else
                                    <div class="video-fallback">
                                        找不到外部影片檔案。<br>
                                        仍可用下方截圖與 DB 影片做人工確認。
                                    </div>
                                @endif
                            </div>

                            <div class="meta-grid">
                                <div class="meta">
                                    <div class="meta-label">外部檔名</div>
                                    <div class="meta-value">{{ $match->file_name }}</div>
                                </div>
                                <div class="meta">
                                    <div class="meta-label">時長 / 大小</div>
                                    <div class="meta-value">{{ $match->duration_hms }} / {{ $match->file_size_human }}</div>
                                </div>
                                <div class="meta">
                                    <div class="meta-label">來源建立時間</div>
                                    <div class="meta-value">{{ $match->file_created_at_human }}</div>
                                </div>
                                <div class="meta">
                                    <div class="meta-label">來源修改時間</div>
                                    <div class="meta-value">{{ $match->file_modified_at_human }}</div>
                                </div>
                                <div class="meta" style="grid-column:1 / -1;">
                                    <div class="meta-label">目前疑似重複路徑</div>
                                    <div class="meta-value">{{ $match->duplicate_file_path }}</div>
                                </div>
                            </div>
                        </section>

                        <section class="panel">
                            <div class="panel-head">
                                <div class="panel-title">
                                    <strong>
                                        DB 影片
                                        @if ($match->videoMaster)
                                            #{{ $match->videoMaster->id }} {{ $match->videoMaster->video_name }}
                                        @else
                                            已不存在
                                        @endif
                                    </strong>
                                    <span>
                                        @if ($match->videoMaster)
                                            feature #{{ $feature?->id ?? '-' }}
                                        @else
                                            對應的 DB 影片或 feature 已被刪除
                                        @endif
                                    </span>
                                </div>

                                @if ($match->db_video_page_url)
                                    <a class="btn btn-soft" href="{{ $match->db_video_page_url }}" target="_blank" rel="noreferrer">打開 DB 影片頁</a>
                                @endif
                            </div>

                            <div class="video-box">
                                @if ($match->db_video_url)
                                    <video controls preload="metadata" playsinline>
                                        <source src="{{ $match->db_video_url }}">
                                    </video>
                                @else
                                    <div class="video-fallback">
                                        找不到 DB 影片或對應播放路徑。<br>
                                        仍可用下方 DB 截圖確認。
                                    </div>
                                @endif
                            </div>

                            <div class="meta-grid">
                                <div class="meta">
                                    <div class="meta-label">DB 影片名稱</div>
                                    <div class="meta-value">{{ $match->videoMaster?->video_name ?? '-' }}</div>
                                </div>
                                <div class="meta">
                                    <div class="meta-label">DB 時長 / 大小</div>
                                    <div class="meta-value">
                                        {{ $feature ? gmdate('H:i:s', (int) round((float) $feature->duration_seconds)) : '-' }}
                                        /
                                        {{ $feature ? $humanBytes($feature->file_size_bytes) : '-' }}
                                    </div>
                                </div>
                                <div class="meta">
                                    <div class="meta-label">相差秒數</div>
                                    <div class="meta-value">{{ $match->duration_delta_seconds !== null ? number_format((float) $match->duration_delta_seconds, 3) . ' 秒' : '-' }}</div>
                                </div>
                                <div class="meta">
                                    <div class="meta-label">相差大小</div>
                                    <div class="meta-value">{{ $match->file_size_delta_bytes !== null ? $humanBytes(abs((int) $match->file_size_delta_bytes)) : '-' }}</div>
                                </div>
                                <div class="meta" style="grid-column:1 / -1;">
                                    <div class="meta-label">DB 影片路徑</div>
                                    <div class="meta-value">{{ $match->videoMaster?->video_path ?? '-' }}</div>
                                </div>
                            </div>
                        </section>
                    </div>

	                    <section class="compare-strip">
	                        <div class="strip-head">
	                            <div>
	                                <h2>逐張截圖對照</h2>
	                                <p>左邊是外部檔案截圖，右邊是 DB 已存在影片的對應 frame。</p>
	                            </div>
	                            <span class="selection-chip">門檻 <strong>{{ (int) $match->threshold_percent }}%</strong></span>
	                        </div>
	                        @foreach ($match->frames as $frame)
	                            @php
	                                $dbFrame = $frame->comparison_frame ?? $dbFrames->get((int) $frame->capture_order);
	                                $similarity = $frame->similarity_percent;
	                                $tone = $frame->similarity_tone ?? 'soft';
	                            @endphp
	                            <div class="frame-row">
	                                <figure class="frame-figure">
	                                    @if ($frame->external_image_url)
	                                        <img src="{{ $frame->external_image_url }}" alt="外部截圖 {{ $frame->capture_order }}">
	                                    @else
	                                        <div class="missing-box">找不到外部截圖</div>
	                                    @endif
	                                    <figcaption class="frame-caption">
	                                        <span>外部截圖 #{{ $frame->capture_order }}</span>
	                                        <span>{{ number_format((float) $frame->capture_second, 3) }} 秒</span>
	                                    </figcaption>
	                                </figure>

	                                <div class="frame-center">
	                                    <div class="score-ring">
	                                        {{ $similarity !== null ? (int) $similarity . '%' : '--' }}
	                                    </div>
	                                    <span class="tone-chip {{ $tone }}">
	                                        <strong>{{ $frame->is_threshold_match ? '達門檻' : '未達門檻' }}</strong>
	                                    </span>
	                                    <div class="score-label">
	                                        比對截圖 #{{ $frame->capture_order }}<br>
	                                        {{ $similarity !== null ? '逐張 dHash 相似度 ' . (int) $similarity . '%' : '此張沒有可用相似度' }}
	                                    </div>
	                                </div>

	                                <figure class="frame-figure">
	                                    @if ($frame->db_image_url)
	                                        <img src="{{ $frame->db_image_url }}" alt="DB 截圖 {{ $frame->capture_order }}">
	                                    @elseif ($dbFrame)
	                                        <div class="missing-box">DB 截圖 URL 無法建立</div>
	                                    @else
	                                        <div class="missing-box">找不到對應 DB 截圖</div>
	                                    @endif
	                                    <figcaption class="frame-caption">
	                                        <span>DB 截圖 #{{ $frame->capture_order }}</span>
	                                        <span>
	                                            @if ($dbFrame)
	                                                {{ number_format((float) $dbFrame->capture_second, 3) }} 秒
	                                            @else
	                                                -
	                                            @endif
	                                        </span>
	                                    </figcaption>
	                                </figure>
	                            </div>
	                        @endforeach
	                    </section>
	                </article>
	            @endforeach
	        </section>

	        <div class="footer-pager">
	            @if ($matches->previousPageUrl())
	                <a class="btn btn-soft" href="{{ $matches->previousPageUrl() }}">上一頁</a>
	            @endif

	            <span class="pager-text">
	                第 {{ $matches->currentPage() }} / {{ $matches->lastPage() }} 頁，共 {{ number_format($matches->total()) }} 筆
	            </span>

	            @if ($matches->nextPageUrl())
	                <a class="btn btn-soft" href="{{ $matches->nextPageUrl() }}">下一頁</a>
	            @endif
	        </div>
	    @endif
	</div>

	<div id="toast" class="toast"></div>

	<script>
	    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
	    const toggleAll = document.getElementById('toggle-all');
	    const selectedCountEl = document.getElementById('selected-count');
	    const batchDeleteBtn = document.getElementById('batch-delete-btn');
	    const toastEl = document.getElementById('toast');

	    function getCheckboxes() {
	        return Array.from(document.querySelectorAll('[data-match-checkbox]'));
	    }

	    function getSelectedIds() {
	        return getCheckboxes()
	            .filter((checkbox) => checkbox.checked)
	            .map((checkbox) => Number(checkbox.value))
	            .filter((value) => Number.isInteger(value) && value > 0);
	    }

	    function syncSelectionState() {
	        const checkboxes = getCheckboxes();
	        const selectedIds = getSelectedIds();

	        selectedCountEl.textContent = String(selectedIds.length);
	        batchDeleteBtn.disabled = selectedIds.length === 0;

	        if (checkboxes.length === 0) {
	            toggleAll.checked = false;
	            toggleAll.indeterminate = false;
	            return;
	        }

	        toggleAll.checked = selectedIds.length === checkboxes.length;
	        toggleAll.indeterminate = selectedIds.length > 0 && selectedIds.length < checkboxes.length;
	    }

	    function showToast(message) {
	        toastEl.textContent = message;
	        toastEl.classList.add('show');
	        window.clearTimeout(showToast._timer);
	        showToast._timer = window.setTimeout(() => {
	            toastEl.classList.remove('show');
	        }, 2600);
	    }

	    toggleAll?.addEventListener('change', () => {
	        const checked = toggleAll.checked;
	        getCheckboxes().forEach((checkbox) => {
	            checkbox.checked = checked;
	        });
	        syncSelectionState();
	    });

	    document.addEventListener('change', (event) => {
	        if (event.target instanceof HTMLInputElement && event.target.matches('[data-match-checkbox]')) {
	            syncSelectionState();
	        }
	    });

	    batchDeleteBtn?.addEventListener('click', async () => {
	        const ids = getSelectedIds();
	        if (ids.length === 0) {
	            return;
	        }

	        if (!window.confirm(`確定要刪除已勾選的 ${ids.length} 支外部疑似重複影片嗎？`)) {
	            return;
	        }

	        batchDeleteBtn.disabled = true;

	        try {
	            const response = await fetch(@json(route('videos.external-duplicates.batch-delete')), {
	                method: 'POST',
	                headers: {
	                    'Content-Type': 'application/json',
	                    'X-CSRF-TOKEN': csrfToken,
	                    'Accept': 'application/json',
	                },
	                body: JSON.stringify({ ids }),
	            });

	            const payload = await response.json();
	            if (!response.ok) {
	                throw new Error(payload?.message || '批次刪除失敗');
	            }

	            (payload.deleted_ids || []).forEach((id) => {
	                const card = document.querySelector(`[data-match-id="${id}"]`);
	                card?.remove();
	            });

	            syncSelectionState();
	            showToast(payload.message || '刪除完成');

	            if ((payload.failed || []).length > 0) {
	                showToast((payload.failed || []).map((item) => `#${item.id} ${item.message}`).join(' / '));
	            }

	            if (document.querySelectorAll('[data-match-id]').length === 0) {
	                window.location.reload();
	            }
	        } catch (error) {
	            showToast(error instanceof Error ? error.message : '批次刪除失敗');
	            syncSelectionState();
	        } finally {
	            batchDeleteBtn.disabled = getSelectedIds().length === 0;
	        }
	    });

	    syncSelectionState();
	</script>
</body>
</html>
