<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>重複影片檢視</title>
    <style>
        :root{
            --bg1:#f8fafc;
            --bg2:#eef2ff;

            --card:#ffffff;
            --card2:#ffffff;

            --line:rgba(2,6,23,.10);
            --line2:rgba(2,6,23,.16);

            --txt:rgba(2,6,23,.92);
            --muted:rgba(2,6,23,.62);
            --muted2:rgba(2,6,23,.48);

            --accent:#7c3aed;
            --accent2:#22c55e;
            --warn:#f59e0b;
            --bad:#ef4444;
            --good:#10b981;

            --glow: 0 0 0.7rem rgba(124,58,237,.18), 0 0 1.6rem rgba(34,197,94,.10);
            --shadow: 0 18px 60px rgba(2,6,23,.10);
            --shadow2: 0 22px 70px rgba(2,6,23,.10);
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Noto Sans TC, "Microsoft JhengHei", Arial, "Apple Color Emoji","Segoe UI Emoji";
            color:var(--txt);
            background:
                radial-gradient(1200px 700px at 12% 10%, rgba(124,58,237,.12), transparent 60%),
                radial-gradient(900px 600px at 70% 25%, rgba(34,197,94,.10), transparent 60%),
                radial-gradient(800px 800px at 55% 90%, rgba(59,130,246,.08), transparent 55%),
                linear-gradient(140deg, var(--bg1), var(--bg2));
            min-height:100vh;
            overflow-x:hidden;
        }

        .bg-noise{
            position:fixed; inset:0;
            pointer-events:none;
            background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='140'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='140' height='140' filter='url(%23n)' opacity='.12'/%3E%3C/svg%3E");
            opacity:.14;
            mix-blend-mode:multiply;
        }

        .wrap{max-width:1400px; margin:0 auto; padding:28px 18px 60px}

        .topbar{
            display:flex; gap:12px; align-items:center; justify-content:space-between;
            padding:18px 18px;
            border:1px solid var(--line);
            background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.78));
            border-radius:18px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
            position:sticky;
            top:12px;
            z-index:20;
        }

        .title{
            display:flex; flex-direction:column; gap:6px;
        }

        .title h1{margin:0; font-size:18px; letter-spacing:.5px}
        .subtitle{color:var(--muted); font-size:13px}

        .stats{display:flex; gap:10px; flex-wrap:wrap; align-items:center; justify-content:flex-end}
        .chip{
            display:inline-flex; gap:8px; align-items:center;
            padding:8px 10px;
            border:1px solid var(--line);
            border-radius:999px;
            background: rgba(255,255,255,.70);
            color:var(--muted);
            font-size:12px;
        }
        .chip b{color:var(--txt); font-weight:800}

        .search{
            display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end;
        }

        .input{
            width:340px; max-width:70vw;
            padding:10px 12px;
            border-radius:12px;
            border:1px solid var(--line2);
            background: rgba(255,255,255,.86);
            color:var(--txt);
            outline:none;
            transition: .18s ease;
        }
        .input:focus{
            border-color: rgba(124,58,237,.45);
            box-shadow: var(--glow);
        }

        .btn{
            padding:10px 12px;
            border-radius:12px;
            border:1px solid rgba(124,58,237,.20);
            background: linear-gradient(180deg, rgba(124,58,237,.14), rgba(124,58,237,.07));
            color: rgba(15,23,42,.92);
            cursor:pointer;
            transition:.18s ease;
            user-select:none;
        }
        .btn:hover{transform: translateY(-1px); box-shadow: var(--glow)}
        .btn:active{transform: translateY(0px); box-shadow:none}
        .btn.secondary{
            border-color: var(--line2);
            background: rgba(255,255,255,.72);
            color: rgba(15,23,42,.92);
        }

        .groups{
            margin-top:18px;
            display:flex;
            flex-direction:column;
            gap:14px;
        }

        .group{
            border-radius:22px;
            border:2px solid rgba(124,58,237,.45);
            background: linear-gradient(180deg, rgba(255,255,255,.96), rgba(255,255,255,.86));
            box-shadow:
                0 0 0 3px rgba(124,58,237,.08),
                0 22px 70px rgba(2,6,23,.12);
            overflow:hidden;
            position:relative;
            margin-bottom:22px;
            transition:.25s ease;
        }

        .group::after{
            content:"";
            position:absolute;
            left:0;
            top:0;
            bottom:0;
            width:6px;
            background: linear-gradient(180deg,
            rgba(124,58,237,.9),
            rgba(34,197,94,.9)
            );
        }

        .group:hover{
            border-color: rgba(124,58,237,.70);
            box-shadow:
                0 0 0 4px rgba(124,58,237,.16),
                0 26px 80px rgba(2,6,23,.16);
        }

        .group::before{
            content:"";
            position:absolute;
            inset:-2px;
            background: radial-gradient(520px 220px at 10% 0%, rgba(124,58,237,.10), transparent 60%),
            radial-gradient(540px 260px at 90% 0%, rgba(34,197,94,.08), transparent 60%);
            opacity:.95;
            pointer-events:none;
            filter: blur(18px);
        }

        .group-inner{position:relative; padding:16px 16px 18px}

        .group-head{
            display:flex;
            gap:10px;
            align-items:flex-end;
            justify-content:space-between;
            padding:10px 10px 14px;
            border-bottom:1px dashed rgba(2,6,23,.16);
        }

        .g-left{display:flex; flex-direction:column; gap:6px}

        .g-title{
            display:flex; gap:10px; align-items:center; flex-wrap:wrap;
            font-size:14px;
            color:var(--txt);
        }

        .badge{
            display:inline-flex; align-items:center; gap:8px;
            padding:6px 10px;
            border-radius:999px;
            border:1px solid rgba(2,6,23,.14);
            background: rgba(255,255,255,.70);
            color:var(--muted);
            font-size:12px;
        }
        .badge strong{color:var(--txt); font-weight:900}
        .g-meta{color:var(--muted); font-size:12px}
        .g-actions{display:flex; gap:8px; align-items:center; flex-wrap:wrap}

        .grid{
            margin-top:14px;
            display:grid;
            grid-template-columns: repeat(4, minmax(0,1fr));
            gap:12px;
        }
        @media (max-width: 1200px){
            .grid{grid-template-columns: repeat(3, minmax(0,1fr));}
        }
        @media (max-width: 860px){
            .grid{grid-template-columns: repeat(2, minmax(0,1fr));}
            .input{width:100%}
            .topbar{flex-direction:column; align-items:stretch}
            .stats{justify-content:flex-start}
            .search{justify-content:flex-start}
        }
        @media (max-width: 520px){
            .grid{grid-template-columns: 1fr;}
        }

        .card{
            border-radius:18px;
            border:1px solid rgba(2,6,23,.12);
            background: linear-gradient(180deg, rgba(255,255,255,.92), rgba(255,255,255,.80));
            overflow:hidden;
            position:relative;
            transition:.18s ease;
        }

        .card:hover{
            transform: translateY(-2px);
            box-shadow: 0 18px 55px rgba(2,6,23,.14), var(--glow);
            border-color: rgba(124,58,237,.35);
        }

        .card-top{
            padding:12px 12px 10px;
            border-bottom:1px solid rgba(2,6,23,.10);
            display:flex;
            flex-direction:column;
            gap:8px;
        }

        .fn{
            display:flex;
            gap:10px;
            align-items:flex-start;
            justify-content:space-between;
        }

        .fn .name{
            font-weight:900;
            font-size:13px;
            line-height:1.25;
            letter-spacing:.2px;
            word-break: break-word;
            color: rgba(2,6,23,.92);
        }

        .pillrow{display:flex; gap:6px; flex-wrap:wrap}

        .pill{
            display:inline-flex;
            align-items:center;
            gap:6px;
            padding:5px 8px;
            border-radius:999px;
            border:1px solid rgba(2,6,23,.12);
            background: rgba(255,255,255,.78);
            color:var(--muted);
            font-size:11px;
        }
        .pill.ok{border-color: rgba(16,185,129,.20)}
        .pill.warn{border-color: rgba(245,158,11,.20)}
        .pill.bad{border-color: rgba(239,68,68,.20)}

        .card-body{padding:12px; display:flex; flex-direction:column; gap:10px}

        .row{
            display:flex;
            gap:10px;
            align-items:flex-start;
            justify-content:space-between;
            flex-wrap:wrap;
        }

        .kv{
            display:flex;
            flex-direction:column;
            gap:4px;
            min-width: 180px;
            flex:1;
        }

        .k{font-size:11px; color:var(--muted2)}
        .v{font-size:12px; color:var(--txt); word-break: break-word}

        .path{
            display:flex;
            flex-direction:column;
            gap:6px;
        }

        .path .pathtext{
            font-size:12px;
            color: rgba(2,6,23,.88);
            padding:10px 10px;
            border-radius:12px;
            border:1px solid rgba(2,6,23,.12);
            background: rgba(248,250,252,.95);
            cursor:pointer;
            transition:.18s ease;
        }
        .path .pathtext:hover{
            border-color: rgba(34,197,94,.30);
            box-shadow: 0 0 0.7rem rgba(34,197,94,.14);
        }

        .actions{
            display:flex;
            gap:8px;
            flex-wrap:wrap;
            align-items:center;
        }

        .mini{
            padding:8px 10px;
            border-radius:12px;
            border:1px solid rgba(2,6,23,.12);
            background: rgba(255,255,255,.78);
            color: rgba(2,6,23,.90);
            cursor:pointer;
            transition:.18s ease;
            font-size:12px;
        }
        .mini:hover{transform: translateY(-1px); box-shadow: var(--glow)}
        .mini.green{
            border-color: rgba(34,197,94,.22);
            background: linear-gradient(180deg, rgba(34,197,94,.12), rgba(34,197,94,.06));
        }
        .mini.purple{
            border-color: rgba(124,58,237,.22);
            background: linear-gradient(180deg, rgba(124,58,237,.12), rgba(124,58,237,.06));
        }
        .mini.red{
            background: linear-gradient(180deg, rgba(239,68,68,.10), rgba(239,68,68,.05));
            border-color: rgba(239,68,68,.20);
        }
        .mini.red:hover{
            box-shadow: 0 0 0.7rem rgba(239,68,68,.14), 0 0 1.2rem rgba(239,68,68,.10);
        }

        .shots{
            display:grid;
            grid-template-columns: repeat(2, minmax(0,1fr));
            gap:8px;
        }

        .shot{
            border-radius:14px;
            overflow:hidden;
            border:1px solid rgba(2,6,23,.10);
            background: rgba(248,250,252,.95);
            position:relative;
        }

        .shot img{
            width:100%;
            height:100%;
            object-fit:cover;
            display:block;
            transform: scale(1.02);
            transition: .18s ease;
        }
        .shot:hover img{transform: scale(1.06)}
        .shot::after{
            content:"";
            position:absolute; inset:0;
            background: radial-gradient(450px 160px at 20% 0%, rgba(124,58,237,.08), transparent 65%);
            pointer-events:none;
        }

        .empty{
            margin-top:18px;
            padding:22px 18px;
            border:1px solid var(--line);
            border-radius:18px;
            background: rgba(255,255,255,.80);
            color:var(--muted);
            box-shadow: var(--shadow);
        }

        .toast{
            position: fixed;
            right: 16px;
            bottom: 16px;
            z-index: 80;
            display:flex;
            flex-direction:column;
            gap:10px;
            max-width: 86vw;
        }

        .toast-item{
            padding:12px 14px;
            border-radius:16px;
            border:1px solid rgba(2,6,23,.12);
            background: rgba(255,255,255,.92);
            box-shadow: 0 18px 55px rgba(2,6,23,.14);
            backdrop-filter: blur(10px);
            font-size:13px;
            color: rgba(2,6,23,.92);
            animation: pop .18s ease;
        }
        .toast-item.ok{border-color: rgba(16,185,129,.22)}
        .toast-item.bad{border-color: rgba(239,68,68,.22)}
        @keyframes pop{
            from{transform: translateY(6px); opacity:0}
            to{transform: translateY(0); opacity:1}
        }

        .muted{color:var(--muted)}
        .sep{opacity:.45}
        .small{font-size:12px}

        .fade-out{
            animation: fadeout .22s ease forwards;
        }
        @keyframes fadeout{
            to{opacity:0; transform: translateY(6px); height:0; margin:0; padding:0}
        }
    </style>
</head>
<body>
<div class="bg-noise"></div>

<div class="wrap">
    <div class="topbar">
        <div class="title">
            <h1>重複影片清單（只顯示重複群組）</h1>
            <div class="subtitle">
                similar_video_ids 會包含自己，拆開 unique >= 2 才會顯示；每個群組用大框框包起來；可把單一影片標記為不重複。
            </div>
        </div>

        <div class="stats">
            <span class="chip">群組 <b>{{ (int)($stats['group_count'] ?? 0) }}</b></span>
            <span class="chip">影片卡片 <b>{{ (int)($stats['video_count'] ?? 0) }}</b></span>
        </div>

        <form class="search" method="GET" action="{{ route('videos.duplicates.index') }}">
            <input class="input" type="text" name="q" value="{{ $q }}" placeholder="搜尋檔名 / 路徑 / 錯誤訊息（即時過濾 + 送出查詢）" id="searchInput">
            <button class="btn" type="submit">查詢</button>
            <button class="btn secondary" type="button" id="clearBtn">清除</button>
        </form>
    </div>

    @if (count($groups) === 0)
        <div class="empty">
            目前沒有符合條件的重複群組（similar_video_ids 拆開後 unique >= 2）。
        </div>
    @else
        <div class="groups" id="groupsRoot">
            @foreach ($groups as $gi => $g)
                <div class="group"
                     data-group-index="{{ $gi }}"
                     data-group-ids="{{ implode(',', $g['id_list']) }}"
                >
                    <div class="group-inner">
                        <div class="group-head">
                            <div class="g-left">
                                <div class="g-title">
                                    <span class="badge"><strong>群組 #{{ $gi + 1 }}</strong></span>
                                    <span class="badge">影片數 <strong>{{ (int)$g['count'] }}</strong></span>
                                    <span class="badge">總大小 <strong>{{ $g['total_size_human'] }}</strong></span>
                                    <span class="badge">時長範圍 <strong>{{ $g['duration_range'] }}</strong></span>
                                </div>
                                <div class="g-meta">
                                    群組 key：<span class="muted">{{ $g['key'] }}</span>
                                </div>
                            </div>
                            <div class="g-actions">
                                <button type="button" class="btn secondary" data-action="toggleGroup" data-target="{{ $gi }}">收合 / 展開</button>
                                <button type="button" class="btn" data-action="highlightGroup" data-target="{{ $gi }}">高亮此群組</button>
                                <button type="button" class="btn secondary" data-action="markGroupUnique" data-target="{{ $gi }}">群組標記不重複</button>
                            </div>
                        </div>

                        <div class="grid" data-group-body="{{ $gi }}">
                            @foreach ($g['members'] as $v)
                                <div class="card"
                                     data-card
                                     data-video-id="{{ $v->id }}"
                                     data-filename="{{ strtolower((string)$v->filename) }}"
                                     data-path="{{ strtolower((string)$v->full_path) }}"
                                     data-error="{{ strtolower((string)($v->last_error ?? '')) }}"
                                >
                                    <div class="card-top">
                                        <div class="fn">
                                            <div class="name">
                                                #{{ $v->id }} <span class="sep">·</span> {{ $v->filename }}
                                            </div>
                                        </div>

                                        <div class="pillrow">
                                            <span class="pill ok">大小 {{ $v->file_size_human }}</span>
                                            <span class="pill warn">時長 {{ $v->duration_hms }}</span>
                                            <span class="pill">mtime {{ $v->mtime_human }}</span>
                                        </div>
                                    </div>

                                    <div class="card-body">
                                        <div class="path">
                                            <div class="k">完整路徑（點一下會開啟檔案管理並選取）</div>
                                            <div class="pathtext"
                                                 data-open-id="{{ $v->id }}"
                                                 title="點一下：開啟檔案管理並選取此檔案"
                                            >{{ $v->full_path }}</div>

                                            <div class="actions">
                                                <button type="button" class="mini green" data-open-id="{{ $v->id }}">開啟檔案管理</button>
                                                <button type="button" class="mini purple" data-copy-text="{{ $v->full_path }}">複製路徑</button>
                                                <button type="button" class="mini" data-copy-text="{{ $v->filename }}">複製檔名</button>
                                                <button type="button" class="mini red" data-mark-unique-id="{{ $v->id }}">標記為不重複</button>
                                                <button type="button" class="mini red" data-delete-id="{{ $v->id }}">刪除檔案</button>
                                            </div>
                                        </div>

                                        <div class="row">
                                            <div class="kv">
                                                <div class="k">hash1~4</div>
                                                <div class="v">
                                                    <span class="muted">{{ $v->hash1_hex }}</span><br>
                                                    <span class="muted">{{ $v->hash2_hex }}</span><br>
                                                    <span class="muted">{{ $v->hash3_hex }}</span><br>
                                                    <span class="muted">{{ $v->hash4_hex }}</span>
                                                </div>
                                            </div>

                                            <div class="kv">
                                                <div class="k">similar_video_ids（原字串）</div>
                                                <div class="v">{{ $v->similar_video_ids }}</div>
                                                <div class="k">similar_video_ids（unique）</div>
                                                <div class="v small muted">{{ implode(',', $v->similar_video_id_list_unique) }}</div>
                                            </div>
                                        </div>

                                        @php
                                            $shots = $v->snapshots;
                                        @endphp

                                        <div>
                                            <div class="k">截圖（預設全部顯示）</div>

                                            @if (count($shots) === 0)
                                                <div class="muted small">沒有截圖資料</div>
                                            @else
                                                <div class="shots">
                                                    @foreach ($shots as $b64)
                                                        <div class="shot">
                                                            <img loading="lazy" alt="snapshot"
                                                                 src="data:image/jpeg;base64,{{ $b64 }}">
                                                        </div>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>

                                        @if (is_string($v->last_error) && trim($v->last_error) !== '')
                                            <div>
                                                <div class="k">最後錯誤</div>
                                                <div class="v" style="color: rgba(185,28,28,.92); white-space: pre-wrap;">{{ $v->last_error }}</div>
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

<div class="toast" id="toast"></div>

<script>
    (function(){
        const csrf = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        const toastRoot = document.getElementById('toast');
        const searchInput = document.getElementById('searchInput');
        const clearBtn = document.getElementById('clearBtn');
        const groupsRoot = document.getElementById('groupsRoot');

        function toast(msg, ok){
            const el = document.createElement('div');
            el.className = 'toast-item ' + (ok ? 'ok' : 'bad');
            el.textContent = msg;
            toastRoot.appendChild(el);
            setTimeout(() => {
                el.style.opacity = '0';
                el.style.transform = 'translateY(6px)';
                el.style.transition = '250ms ease';
                setTimeout(() => el.remove(), 260);
            }, 2200);
        }

        async function openFileById(id){
            try{
                const resp = await fetch("{{ route('videos.duplicates.open') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ id: Number(id) })
                });
                const data = await resp.json().catch(() => ({}));
                if(resp.ok && data && data.ok){
                    toast('已嘗試開啟檔案管理並選取檔案', true);
                    return;
                }
                const msg = (data && data.message) ? data.message : ('開啟失敗（HTTP ' + resp.status + '）');
                toast(msg, false);
            }catch(e){
                toast('開啟失敗：' + String(e && e.message ? e.message : e), false);
            }
        }

        async function markUniqueById(id){
            try{
                const resp = await fetch("{{ route('videos.duplicates.markUnique') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ id: Number(id) })
                });

                const data = await resp.json().catch(() => ({}));
                if(resp.ok && data && data.ok){
                    toast('已標記為不重複，並同步移除關聯 ids', true);

                    const card = document.querySelector('[data-card][data-video-id="' + id + '"]');
                    if(card){
                        card.classList.add('fade-out');
                        setTimeout(() => {
                            card.remove();
                            cleanupGroupsAfterRemove();
                        }, 240);
                    }else{
                        cleanupGroupsAfterRemove();
                    }
                    return;
                }

                const msg = (data && data.message) ? data.message : ('更新失敗（HTTP ' + resp.status + '）');
                toast(msg, false);
            }catch(e){
                toast('更新失敗：' + String(e && e.message ? e.message : e), false);
            }
        }

        async function deleteById(id){
            const sure = window.confirm('確定要刪除這個檔案？會同時刪除資料庫記錄並移除關聯 ids。');
            if(!sure){
                return;
            }

            try{
                const resp = await fetch("{{ route('videos.duplicates.delete') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ id: Number(id) })
                });

                const data = await resp.json().catch(() => ({}));
                if(resp.ok && data && data.ok){
                    const fileDeleted = (data.file_deleted === true);
                    const fileError = (data.file_error ? String(data.file_error) : '');

                    if(fileDeleted){
                        toast('已刪除檔案並移除資料庫記錄', true);
                    }else{
                        const msg = fileError !== '' ? ('已刪除資料庫記錄，但檔案未刪除：' + fileError) : '已刪除資料庫記錄，但檔案未刪除';
                        toast(msg, true);
                    }

                    const card = document.querySelector('[data-card][data-video-id="' + id + '"]');
                    if(card){
                        card.classList.add('fade-out');
                        setTimeout(() => {
                            if(card && card.isConnected){
                                card.remove();
                            }
                            cleanupGroupsAfterRemove();
                        }, 240);
                    }else{
                        cleanupGroupsAfterRemove();
                    }

                    return;
                }

                const msg = (data && data.message) ? data.message : ('刪除失敗（HTTP ' + resp.status + '）');
                toast(msg, false);
            }catch(e){
                toast('刪除失敗：' + String(e && e.message ? e.message : e), false);
            }
        }

        async function markGroupUniqueByIndex(groupIndex){
            const group = document.querySelector('.group[data-group-index="' + groupIndex + '"]');
            if(!group){
                toast('找不到群組', false);
                return;
            }

            const idsRaw = String(group.getAttribute('data-group-ids') || '').trim();
            if(idsRaw === ''){
                toast('群組 ids 為空', false);
                return;
            }

            const ids = idsRaw.split(',').map(s => Number(String(s).trim())).filter(n => Number.isFinite(n) && n > 0);
            if(ids.length < 2){
                toast('群組 ids 不足 2 筆', false);
                return;
            }

            try{
                const resp = await fetch("{{ route('videos.duplicates.markGroupUnique') }}", {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ ids: ids })
                });

                const data = await resp.json().catch(() => ({}));
                if(resp.ok && data && data.ok){
                    toast('已將整個群組標記為不重複', true);

                    group.classList.add('fade-out');
                    setTimeout(() => {
                        if(group && group.isConnected){
                            group.remove();
                        }
                    }, 260);

                    return;
                }

                const msg = (data && data.message) ? data.message : ('更新失敗（HTTP ' + resp.status + '）');
                toast(msg, false);
            }catch(e){
                toast('更新失敗：' + String(e && e.message ? e.message : e), false);
            }
        }

        function cleanupGroupsAfterRemove(){
            if(!groupsRoot){
                return;
            }

            const groups = groupsRoot.querySelectorAll('.group');
            groups.forEach(g => {
                const cards = Array.from(g.querySelectorAll('[data-card]'));
                const visibleCards = cards.filter(c => c && c.isConnected && c.style.display !== 'none');
                if(visibleCards.length < 2){
                    g.classList.add('fade-out');
                    setTimeout(() => {
                        if(g && g.isConnected){
                            g.remove();
                        }
                    }, 260);
                }
            });
        }

        async function copyText(t){
            try{
                await navigator.clipboard.writeText(String(t));
                toast('已複製到剪貼簿', true);
            }catch(e){
                const ta = document.createElement('textarea');
                ta.value = String(t);
                document.body.appendChild(ta);
                ta.select();
                try{
                    document.execCommand('copy');
                    toast('已複製到剪貼簿', true);
                }catch(err){
                    toast('複製失敗', false);
                }finally{
                    ta.remove();
                }
            }
        }

        function filterCards(keyword){
            if(!groupsRoot){
                return;
            }
            const k = (keyword || '').trim().toLowerCase();
            const cards = groupsRoot.querySelectorAll('[data-card]');
            cards.forEach(card => {
                const fn = card.getAttribute('data-filename') || '';
                const path = card.getAttribute('data-path') || '';
                const err = card.getAttribute('data-error') || '';
                const hit = (k === '') || fn.includes(k) || path.includes(k) || err.includes(k);
                card.style.display = hit ? '' : 'none';
            });

            const groups = groupsRoot.querySelectorAll('.group');
            groups.forEach(g => {
                const visible = Array.from(g.querySelectorAll('[data-card]')).some(c => c.style.display !== 'none');
                g.style.display = visible ? '' : 'none';
            });
        }

        document.addEventListener('click', (ev) => {
            const t = ev.target;

            const openId = t.getAttribute && t.getAttribute('data-open-id');
            if(openId){
                ev.preventDefault();
                openFileById(openId);
                return;
            }

            const markId = t.getAttribute && t.getAttribute('data-mark-unique-id');
            if(markId){
                ev.preventDefault();
                const idNum = Number(markId);
                if(!Number.isFinite(idNum) || idNum <= 0){
                    toast('id 不正確', false);
                    return;
                }

                t.disabled = true;
                t.style.opacity = '0.75';
                const oldText = t.textContent;
                t.textContent = '處理中...';

                markUniqueById(idNum).finally(() => {
                    if(t && t.isConnected){
                        t.disabled = false;
                        t.style.opacity = '';
                        t.textContent = oldText;
                    }
                });
                return;
            }

            const delId = t.getAttribute && t.getAttribute('data-delete-id');
            if(delId){
                ev.preventDefault();
                const idNum = Number(delId);
                if(!Number.isFinite(idNum) || idNum <= 0){
                    toast('id 不正確', false);
                    return;
                }

                t.disabled = true;
                t.style.opacity = '0.75';
                const oldText = t.textContent;
                t.textContent = '刪除中...';

                deleteById(idNum).finally(() => {
                    if(t && t.isConnected){
                        t.disabled = false;
                        t.style.opacity = '';
                        t.textContent = oldText;
                    }
                });
                return;
            }

            const copy = t.getAttribute && t.getAttribute('data-copy-text');
            if(copy !== null){
                ev.preventDefault();
                copyText(copy);
                return;
            }

            const action = t.getAttribute && t.getAttribute('data-action');
            if(action === 'toggleGroup'){
                ev.preventDefault();
                const idx = t.getAttribute('data-target');
                const body = document.querySelector('[data-group-body="' + idx + '"]');
                if(!body){
                    return;
                }
                const isHidden = body.style.display === 'none';
                body.style.display = isHidden ? '' : 'none';
                toast(isHidden ? '已展開群組' : '已收合群組', true);
                return;
            }

            if(action === 'highlightGroup'){
                ev.preventDefault();
                const idx = t.getAttribute('data-target');
                const group = document.querySelector('.group[data-group-index="' + idx + '"]');
                if(!group){
                    return;
                }
                group.scrollIntoView({ behavior: 'smooth', block: 'start' });
                group.animate([
                    { boxShadow: '0 0 0 rgba(0,0,0,0)' },
                    { boxShadow: '0 0 0.9rem rgba(124,58,237,.22), 0 0 1.8rem rgba(34,197,94,.14)' },
                    { boxShadow: '0 0 0 rgba(0,0,0,0)' }
                ], { duration: 900, easing: 'ease' });
                return;
            }

            if(action === 'markGroupUnique'){
                ev.preventDefault();
                const idx = t.getAttribute('data-target');
                const groupIndex = Number(idx);

                if(!Number.isFinite(groupIndex) || groupIndex < 0){
                    toast('群組 index 不正確', false);
                    return;
                }

                t.disabled = true;
                t.style.opacity = '0.75';
                const oldText = t.textContent;
                t.textContent = '處理中...';

                markGroupUniqueByIndex(groupIndex).finally(() => {
                    if(t && t.isConnected){
                        t.disabled = false;
                        t.style.opacity = '';
                        t.textContent = oldText;
                    }
                });
                return;
            }
        });

        if(searchInput){
            searchInput.addEventListener('input', () => filterCards(searchInput.value));
        }

        if(clearBtn){
            clearBtn.addEventListener('click', () => {
                if(searchInput){
                    searchInput.value = '';
                    filterCards('');
                    const url = new URL(window.location.href);
                    url.searchParams.delete('q');
                    window.history.replaceState({}, '', url.toString());
                }
            });
        }

        filterCards(searchInput ? searchInput.value : '');
    })();
</script>
</body>
</html>
