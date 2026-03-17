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
        .match-card,
        .section-shell{
            backdrop-filter:blur(16px);
        }

        .hero{
            display:grid;
            grid-template-columns:1.45fr 1fr;
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
            grid-template-columns:repeat(2, minmax(0,1fr));
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
            font-size:1.4rem;
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

        .search-select{
            min-width:110px;
            padding:14px 16px;
            border-radius:16px;
            border:1px solid var(--line-strong);
            background:rgba(255,255,255,.94);
            color:var(--ink);
            font-size:.96rem;
            outline:none;
            transition:border-color .18s ease, box-shadow .18s ease, transform .18s ease;
        }

        .search-select:focus{
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

        .section-stack{
            display:grid;
            gap:22px;
            margin-top:22px;
        }

        .section-shell{
            padding:20px;
            border-radius:var(--radius-xl);
            border:1px solid var(--line);
            background:linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.78));
            box-shadow:var(--shadow);
            animation:riseIn .4s ease;
        }

        .section-head{
            display:flex;
            justify-content:space-between;
            gap:18px;
            align-items:flex-end;
            flex-wrap:wrap;
            margin-bottom:18px;
        }

        .section-kicker{
            margin:0 0 8px;
            color:#4a7799;
            font-size:.78rem;
            font-weight:700;
            letter-spacing:.08em;
            text-transform:uppercase;
        }

        .section-head h2{
            margin:0;
            font-size:1.5rem;
        }

        .section-copy{
            margin:8px 0 0;
            color:var(--muted);
            line-height:1.7;
        }

        .log-panel{
            padding:0;
            overflow:hidden;
        }

        .log-panel > summary{
            display:flex;
            justify-content:space-between;
            gap:18px;
            align-items:flex-end;
            flex-wrap:wrap;
            padding:20px;
            cursor:pointer;
            list-style:none;
        }

        .log-panel > summary::-webkit-details-marker{
            display:none;
        }

        .log-panel > summary::after{
            content:"展開";
            color:#4a7799;
            font-size:.82rem;
            font-weight:800;
            letter-spacing:.08em;
            text-transform:uppercase;
        }

        .log-panel[open] > summary::after{
            content:"收起";
        }

        .log-panel-content{
            padding:0 20px 20px;
            border-top:1px solid var(--line);
        }

        .log-panel[open] .log-panel-content{
            animation:riseIn .25s ease;
        }

        .toolbar{
            position:sticky;
            top:14px;
            z-index:12;
            display:flex;
            justify-content:space-between;
            align-items:center;
            gap:14px;
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

        .match-card.is-selected{
            border-color:rgba(93,156,236,.34);
            box-shadow:0 18px 42px rgba(93,156,236,.16);
        }

        .match-card.is-collapsed .match-head{
            margin-bottom:0;
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

        .match-body{
            display:grid;
            gap:18px;
        }

        .match-card.is-collapsed .match-body{
            display:none;
        }

        .card-toggle-btn{
            padding:10px 14px;
            min-width:104px;
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
            padding:40px 18px;
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
<div class="page">
    <section class="hero">
        <div class="hero-title">
            <span class="eyebrow">External Duplicate Review</span>
            <h1>外部重複影片審核板</h1>
            <p class="lead">
                上半部保留「實際被判成重複並搬走」的結果，下半部顯示所有跑過的比對 log。新的比對 log 會把雙方截圖與特徵直接用 base64 存進資料庫，不再額外落地到本機。
            </p>
        </div>

        <div class="hero-side">
            <div class="stat-grid">
                <div class="stat">
                    <div class="stat-label">重複結果總數</div>
                    <div class="stat-value">{{ number_format((int) ($stats['duplicate_count'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">本頁仍存在檔案</div>
                    <div class="stat-value">{{ number_format((int) ($stats['duplicates_existing_on_page'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">所有 log 總數</div>
                    <div class="stat-value">{{ number_format((int) ($stats['log_count'] ?? 0)) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-label">log 平均相似度</div>
                    <div class="stat-value">{{ number_format((float) ($stats['log_average_similarity'] ?? 0), 2) }}%</div>
                </div>
            </div>

            <div class="search-shell">
                <form method="GET" action="{{ route('videos.external-duplicates.index') }}">
                    <input
                        class="search-input"
                        type="text"
                        name="q"
                        value="{{ $q }}"
                        placeholder="搜尋檔名、來源路徑、疑似重複路徑、DB 影片名稱、狀態"
                    >
                    <select class="search-select" name="per_page">
                        @foreach (($perPageOptions ?? [20, 50, 100, 200, 500]) as $option)
                            <option value="{{ $option }}" @selected((int) ($perPage ?? 200) === (int) $option)>{{ $option }} 筆</option>
                        @endforeach
                    </select>
                    <button class="btn btn-primary" type="submit">搜尋</button>
                    @if ($q !== '')
                        <a class="btn btn-soft" href="{{ route('videos.external-duplicates.index', ['per_page' => $perPage ?? 200]) }}">清除</a>
                    @endif
                </form>
            </div>
        </div>
    </section>

    <div class="section-stack">
        <section class="section-shell">
            <div class="section-head">
                <div>
                    <p class="section-kicker">Moved Duplicates</p>
                    <h2>有重複的結果</h2>
                    <p class="section-copy">這一區只放真正命中門檻、且已搬到「疑似重複檔案」資料夾的資料。可直接在這裡做人工確認與批次刪除。</p>
                </div>
                <span class="selection-chip">平均相似度 <strong>{{ number_format((float) ($stats['duplicate_average_similarity'] ?? 0), 2) }}%</strong></span>
            </div>

            @if ($duplicateMatches->count() > 0)
                <section class="toolbar">
                    <div class="toolbar-left">
                        <label class="selection-chip">
                            <input id="toggle-all" type="checkbox">
                            <strong>全選本頁</strong>
                        </label>
                        <span class="selection-chip">已勾選 <strong id="selected-count">0</strong> 筆</span>
                    </div>

                    <div class="toolbar-right">
                        <span class="selection-chip">確認非重複只刪資料；批次刪除才會動到 <strong>疑似重複檔案</strong> 裡的外部影片</span>
                        <button id="batch-dismiss-btn" class="btn btn-soft" type="button" disabled>確認非重複</button>
                        <button id="batch-delete-btn" class="btn btn-danger" type="button" disabled>刪除勾選影片</button>
                    </div>
                </section>

                <section class="matches">
                    @foreach ($duplicateMatches as $entry)
                        @include('videos.external-duplicates._comparison-card', ['entry' => $entry, 'showCheckbox' => true])
                    @endforeach
                </section>

                <div class="footer-pager">
                    @if ($duplicateMatches->previousPageUrl())
                        <a class="btn btn-soft" href="{{ $duplicateMatches->previousPageUrl() }}">上一頁</a>
                    @endif

                    <span class="pager-text">
                        重複結果 第 {{ $duplicateMatches->currentPage() }} / {{ $duplicateMatches->lastPage() }} 頁，共 {{ number_format($duplicateMatches->total()) }} 筆
                    </span>

                    @if ($duplicateMatches->nextPageUrl())
                        <a class="btn btn-soft" href="{{ $duplicateMatches->nextPageUrl() }}">下一頁</a>
                    @endif
                </div>
            @else
                <section class="empty">
                    <h2>目前沒有已搬移的重複結果</h2>
                    <p>先跑 `php artisan video:move-duplicates "D:\incoming"`，命中且搬移成功後才會出現在這裡。</p>
                </section>
            @endif
        </section>

        <details class="section-shell log-panel">
            <summary>
                <div>
                    <p class="section-kicker">All Comparison Logs</p>
                    <h2>所有跑過的 log 相似度比對</h2>
                    <p class="section-copy">預設收起；要追查沒過門檻、dry-run、同路徑略過或錯誤案例時，再手動展開查看。</p>
                </div>
                <span class="selection-chip">目前共 <strong>{{ number_format((int) ($stats['log_count'] ?? 0)) }}</strong> 筆 log</span>
            </summary>

            <div class="log-panel-content">
                @if ($comparisonLogs->count() > 0)
                    <section class="matches">
                        @foreach ($comparisonLogs as $entry)
                            @include('videos.external-duplicates._comparison-card', ['entry' => $entry, 'showCheckbox' => false])
                        @endforeach
                    </section>

                    <div class="footer-pager">
                        @if ($comparisonLogs->previousPageUrl())
                            <a class="btn btn-soft" href="{{ $comparisonLogs->previousPageUrl() }}">上一頁</a>
                        @endif

                        <span class="pager-text">
                            log 第 {{ $comparisonLogs->currentPage() }} / {{ $comparisonLogs->lastPage() }} 頁，共 {{ number_format($comparisonLogs->total()) }} 筆
                        </span>

                        @if ($comparisonLogs->nextPageUrl())
                            <a class="btn btn-soft" href="{{ $comparisonLogs->nextPageUrl() }}">下一頁</a>
                        @endif
                    </div>
                @else
                    <section class="empty">
                        <h2>目前還沒有比對 log</h2>
                        <p>執行 `php artisan video:move-duplicates` 後，所有比對結果都會在這裡留下紀錄。</p>
                    </section>
                @endif
            </div>
        </details>
    </div>
</div>

<div id="toast" class="toast"></div>

<script>
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const toggleAll = document.getElementById('toggle-all');
    const selectedCountEl = document.getElementById('selected-count');
    const batchDismissBtn = document.getElementById('batch-dismiss-btn');
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

        if (selectedCountEl) {
            selectedCountEl.textContent = String(selectedIds.length);
        }

        if (batchDismissBtn) {
            batchDismissBtn.disabled = selectedIds.length === 0;
        }

        if (batchDeleteBtn) {
            batchDeleteBtn.disabled = selectedIds.length === 0;
        }

        if (!toggleAll) {
            return;
        }

        if (checkboxes.length === 0) {
            toggleAll.checked = false;
            toggleAll.indeterminate = false;
            return;
        }

        toggleAll.checked = selectedIds.length === checkboxes.length;
        toggleAll.indeterminate = selectedIds.length > 0 && selectedIds.length < checkboxes.length;
    }

    function showToast(message) {
        if (!toastEl) {
            return;
        }

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
            const card = checkbox.closest('[data-collapsible-card]');
            setCardSelected(card, checked);
            setCardCollapsed(card, checked);
        });
        syncSelectionState();
    });

    document.addEventListener('change', (event) => {
        if (event.target instanceof HTMLInputElement && event.target.matches('[data-match-checkbox]')) {
            const card = event.target.closest('[data-collapsible-card]');
            setCardSelected(card, event.target.checked);
            setCardCollapsed(card, event.target.checked);
            syncSelectionState();
        }
    });

    document.addEventListener('click', (event) => {
        const toggleButton = event.target instanceof HTMLElement
            ? event.target.closest('[data-card-toggle]')
            : null;

        if (!(toggleButton instanceof HTMLButtonElement)) {
            return;
        }

        const card = toggleButton.closest('[data-collapsible-card]');
        if (!(card instanceof HTMLElement)) {
            return;
        }

        setCardCollapsed(card, !card.classList.contains('is-collapsed'));
    });

    function setCardSelected(card, selected) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        card.classList.toggle('is-selected', selected);
    }

    function setCardCollapsed(card, collapsed) {
        if (!(card instanceof HTMLElement)) {
            return;
        }

        const body = card.querySelector('[data-card-body]');
        const toggleButton = card.querySelector('[data-card-toggle]');

        if (!(body instanceof HTMLElement) || !(toggleButton instanceof HTMLButtonElement)) {
            return;
        }

        card.classList.toggle('is-collapsed', collapsed);
        body.hidden = collapsed;
        toggleButton.textContent = collapsed ? '展開卡片' : '收起卡片';
        toggleButton.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    async function runBatchAction(url, action) {
        const ids = getSelectedIds();
        if (ids.length === 0) {
            return;
        }

        if (batchDismissBtn) {
            batchDismissBtn.disabled = true;
        }

        if (batchDeleteBtn) {
            batchDeleteBtn.disabled = true;
        }

        try {
            const response = await fetch(url, {
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

            const removedIds = payload.dismissed_ids || payload.deleted_ids || [];

            removedIds.forEach((id) => {
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
            const fallback = action === 'dismiss' ? '確認非重複失敗' : '批次刪除失敗';
            showToast(error instanceof Error ? error.message : fallback);
            syncSelectionState();
        } finally {
            if (batchDismissBtn) {
                batchDismissBtn.disabled = getSelectedIds().length === 0;
            }

            if (batchDeleteBtn) {
                batchDeleteBtn.disabled = getSelectedIds().length === 0;
            }
        }
    }

    batchDismissBtn?.addEventListener('click', async () => {
        await runBatchAction(@json(route('videos.external-duplicates.batch-dismiss')), 'dismiss');
    });

    batchDeleteBtn?.addEventListener('click', async () => {
        await runBatchAction(@json(route('videos.external-duplicates.batch-delete')), 'delete');
    });

    syncSelectionState();
</script>
</body>
</html>
