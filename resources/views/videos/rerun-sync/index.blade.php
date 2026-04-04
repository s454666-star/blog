<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>重跑資源三邊差異</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Noto+Sans+TC:wght@400;500;700;900&display=swap');

        :root{
            --bg:#f8f6ef;
            --bg-soft:#eef7f4;
            --ink:#1f2b27;
            --muted:#62766f;
            --line:rgba(31,43,39,.12);
            --panel:rgba(255,255,255,.84);
            --panel-strong:rgba(255,255,255,.95);
            --teal:#178f84;
            --teal-soft:rgba(23,143,132,.14);
            --amber:#ec9c3f;
            --amber-soft:rgba(236,156,63,.14);
            --coral:#d96f5d;
            --coral-soft:rgba(217,111,93,.14);
            --shadow:0 28px 70px rgba(36, 74, 64, .14);
            --radius-xl:30px;
            --radius-lg:22px;
            --radius-md:16px;
        }

        *{box-sizing:border-box}

        body{
            margin:0;
            font-family:"Sora","Noto Sans TC",sans-serif;
            color:var(--ink);
            background:
                radial-gradient(circle at top left, rgba(23,143,132,.18), transparent 28%),
                radial-gradient(circle at 88% 16%, rgba(236,156,63,.18), transparent 24%),
                linear-gradient(145deg, #faf8f1 0%, #f1f7f3 45%, #fbf7f0 100%);
            min-height:100vh;
        }

        body::before,
        body::after{
            content:"";
            position:fixed;
            inset:auto;
            width:24rem;
            height:24rem;
            border-radius:999px;
            filter:blur(42px);
            pointer-events:none;
            opacity:.42;
            z-index:0;
        }

        body::before{
            top:-8rem;
            left:-6rem;
            background:rgba(23,143,132,.24);
        }

        body::after{
            right:-7rem;
            bottom:-8rem;
            background:rgba(236,156,63,.22);
        }

        .page{
            position:relative;
            z-index:1;
            max-width:1500px;
            margin:0 auto;
            padding:26px 18px 60px;
        }

        .shell,
        .hero,
        .toolbar,
        .result-card,
        .issue-card{
            backdrop-filter:blur(16px);
        }

        .hero,
        .toolbar,
        .result-card,
        .issue-card{
            border:1px solid var(--line);
            background:var(--panel);
            box-shadow:var(--shadow);
        }

        .hero{
            border-radius:var(--radius-xl);
            padding:24px;
            display:grid;
            grid-template-columns:1.35fr .95fr;
            gap:18px;
        }

        .eyebrow{
            display:inline-flex;
            align-items:center;
            gap:.55rem;
            width:max-content;
            padding:.56rem .9rem;
            border-radius:999px;
            background:var(--teal-soft);
            border:1px solid rgba(23,143,132,.16);
            color:#0e7269;
            font-size:.78rem;
            font-weight:800;
            letter-spacing:.04em;
        }

        h1{
            margin:.95rem 0 0;
            font-size:clamp(2rem, 3.3vw, 3.3rem);
            line-height:1.02;
            letter-spacing:-.04em;
        }

        .lead{
            margin:1rem 0 0;
            color:var(--muted);
            max-width:55rem;
            line-height:1.78;
            font-size:.98rem;
        }

        .hero-actions{
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            margin-top:20px;
        }

        .pill,
        .badge,
        .status-chip{
            display:inline-flex;
            align-items:center;
            gap:.45rem;
            min-height:38px;
            padding:0 14px;
            border-radius:999px;
            border:1px solid rgba(31,43,39,.10);
            background:rgba(255,255,255,.88);
            font-size:.86rem;
            font-weight:700;
        }

        .hero-side{
            display:grid;
            gap:12px;
        }

        .stat-grid{
            display:grid;
            grid-template-columns:repeat(2, minmax(0,1fr));
            gap:12px;
        }

        .stat{
            padding:16px;
            border-radius:18px;
            border:1px solid rgba(31,43,39,.08);
            background:var(--panel-strong);
        }

        .stat-label{
            color:var(--muted);
            font-size:.78rem;
            margin-bottom:.42rem;
        }

        .stat-value{
            font-size:1.48rem;
            font-weight:800;
        }

        .hero-links{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
        }

        .link-btn,
        .action-btn{
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:.5rem;
            min-height:48px;
            padding:0 18px;
            border-radius:15px;
            border:none;
            text-decoration:none;
            cursor:pointer;
            font-size:.92rem;
            font-weight:800;
            transition:transform .18s ease, box-shadow .18s ease, opacity .18s ease;
        }

        .link-btn:hover,
        .action-btn:hover{transform:translateY(-1px)}

        .link-btn.primary,
        .action-btn.primary{
            color:#fff;
            background:linear-gradient(135deg, var(--teal), #4dbeb0);
            box-shadow:0 18px 36px rgba(23,143,132,.24);
        }

        .link-btn.secondary,
        .action-btn.secondary{
            color:#725118;
            background:linear-gradient(135deg, #f6d097, #f0a94f);
            box-shadow:0 18px 36px rgba(236,156,63,.20);
        }

        .link-btn.ghost,
        .action-btn.ghost{
            color:var(--ink);
            background:rgba(255,255,255,.92);
            border:1px solid rgba(31,43,39,.10);
        }

        .toolbar{
            margin-top:18px;
            border-radius:24px;
            padding:18px;
            display:grid;
            gap:14px;
        }

        .toolbar-top{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            align-items:center;
            justify-content:space-between;
        }

        .toolbar form{
            display:flex;
            gap:12px;
            flex-wrap:wrap;
            align-items:center;
        }

        .toolbar input[type="search"],
        .toolbar select{
            min-height:48px;
            border-radius:16px;
            border:1px solid rgba(31,43,39,.12);
            padding:0 16px;
            background:rgba(255,255,255,.95);
            font:inherit;
            color:var(--ink);
        }

        .toolbar input[type="search"]{
            min-width:320px;
            flex:1;
        }

        .banner{
            padding:14px 16px;
            border-radius:18px;
            border:1px solid rgba(23,143,132,.18);
            background:rgba(255,255,255,.92);
        }

        .banner strong{display:block}
        .banner-list{
            margin:10px 0 0;
            padding-left:18px;
            color:var(--muted);
            line-height:1.68;
        }

        .section-head{
            display:flex;
            align-items:flex-end;
            justify-content:space-between;
            gap:12px;
            margin:28px 0 16px;
        }

        .section-head h2{
            margin:0;
            font-size:1.45rem;
            letter-spacing:-.03em;
        }

        .section-head p{
            margin:8px 0 0;
            color:var(--muted);
            line-height:1.7;
        }

        .batch-bar{
            display:flex;
            flex-wrap:wrap;
            gap:12px;
            align-items:center;
            justify-content:space-between;
            margin-bottom:14px;
        }

        .batch-controls{
            display:flex;
            align-items:center;
            gap:12px;
            flex-wrap:wrap;
        }

        .check-label{
            display:inline-flex;
            align-items:center;
            gap:.55rem;
            font-weight:700;
        }

        .results{
            display:grid;
            gap:18px;
        }

        .result-card{
            border-radius:24px;
            padding:20px;
            background:linear-gradient(160deg, rgba(255,255,255,.94), rgba(255,255,255,.82));
        }

        .result-head{
            display:flex;
            gap:14px;
            justify-content:space-between;
            align-items:flex-start;
        }

        .result-title{
            display:flex;
            gap:12px;
            align-items:flex-start;
        }

        .result-checkbox{
            width:20px;
            height:20px;
            margin-top:4px;
        }

        .result-name{
            margin:0;
            font-size:1.32rem;
            line-height:1.2;
            letter-spacing:-.02em;
        }

        .result-meta{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top:10px;
        }

        .badge.hash{background:rgba(31,43,39,.06)}
        .badge.missing{background:var(--amber-soft); color:#8a5a0d}
        .badge.extra{background:var(--coral-soft); color:#8b3d31}
        .badge.fill{background:var(--teal-soft); color:#0d736a}

        .aliases{
            margin-top:12px;
            color:var(--muted);
            font-size:.92rem;
            line-height:1.7;
        }

        .source-grid{
            display:grid;
            grid-template-columns:repeat(3, minmax(0,1fr));
            gap:14px;
            margin-top:18px;
        }

        .source-card{
            border-radius:18px;
            border:1px solid rgba(31,43,39,.10);
            background:rgba(255,255,255,.92);
            padding:16px;
        }

        .source-card.is-missing{
            background:linear-gradient(180deg, rgba(236,156,63,.10), rgba(255,255,255,.92));
        }

        .source-card.is-present{
            background:linear-gradient(180deg, rgba(23,143,132,.08), rgba(255,255,255,.92));
        }

        .source-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            margin-bottom:12px;
        }

        .source-head strong{
            font-size:.95rem;
            line-height:1.5;
        }

        .source-count{
            color:var(--muted);
            font-size:.82rem;
        }

        .source-empty{
            margin:0;
            color:var(--muted);
            line-height:1.7;
            font-size:.9rem;
        }

        .source-list{
            display:grid;
            gap:10px;
        }

        .source-item{
            padding:12px;
            border-radius:14px;
            background:rgba(248,246,239,.86);
            border:1px solid rgba(31,43,39,.08);
        }

        .source-item-name{
            font-weight:800;
            font-size:.92rem;
        }

        .source-item-sub,
        .source-item-path{
            margin-top:6px;
            color:var(--muted);
            font-size:.84rem;
            line-height:1.6;
            word-break:break-word;
        }

        .source-item-link{
            display:inline-flex;
            margin-top:8px;
            color:#0d736a;
            font-size:.82rem;
            font-weight:800;
            text-decoration:none;
        }

        .resolution-note{
            display:flex;
            flex-wrap:wrap;
            gap:10px;
            margin-top:16px;
        }

        .issue-list{
            display:grid;
            gap:12px;
        }

        .issue-card{
            border-radius:18px;
            padding:16px;
            background:linear-gradient(160deg, rgba(255,255,255,.94), rgba(255,255,255,.80));
        }

        .issue-card h3{
            margin:0;
            font-size:1rem;
        }

        .issue-card p{
            margin:8px 0 0;
            color:var(--muted);
            line-height:1.7;
            word-break:break-word;
        }

        .empty-state{
            padding:26px;
            border-radius:22px;
            border:1px dashed rgba(31,43,39,.16);
            background:rgba(255,255,255,.74);
            color:var(--muted);
            line-height:1.8;
        }

        @media (max-width: 1080px){
            .hero{grid-template-columns:1fr}
            .source-grid{grid-template-columns:1fr}
        }

        @media (max-width: 720px){
            .page{padding:16px 12px 42px}
            .hero,.toolbar,.result-card,.issue-card{padding:16px}
            .stat-grid{grid-template-columns:1fr 1fr}
            .toolbar input[type="search"]{min-width:0;width:100%}
            .batch-bar,.toolbar-top,.result-head{flex-direction:column;align-items:flex-start}
            .hero-links,.batch-controls{width:100%}
            .link-btn,.action-btn{width:100%}
        }
    </style>
</head>
<body>
@php
    $formatBytes = static function (int $bytes): string {
        if ($bytes <= 0) {
            return '-';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return number_format($value, $power === 0 ? 0 : 2) . ' ' . $units[$power];
    };
@endphp

<main class="page">
    <section class="hero">
        <div>
            <span class="eyebrow">Video Rerun Sync</span>
            <h1>重跑資源三邊差異面板</h1>
            <p class="lead">
                這頁只顯示真正有差異的群組。A 來源取自 `video_master(type=1)` 的原始檔、B 掃 `Z:\video(重跑)`、
                C 掃 Eagle「重跑資源」library。即使三邊檔名不同，只要實體檔案指紋相同，就會歸成同一組。
            </p>
            <div class="hero-actions">
                <span class="pill">指紋比對：小檔完整 SHA1，大檔片段指紋</span>
                <span class="pill">增量掃描：未變動檔案直接跳過</span>
                <span class="pill">可直接批次刪除多出或補齊缺少</span>
            </div>
        </div>

        <div class="hero-side">
            <div class="stat-grid">
                <article class="stat">
                    <div class="stat-label">差異群組</div>
                    <div class="stat-value">{{ $groups->count() }}</div>
                </article>
                <article class="stat">
                    <div class="stat-label">指紋問題</div>
                    <div class="stat-value">{{ $issues->count() }}</div>
                </article>
                <article class="stat">
                    <div class="stat-label">上次掃描</div>
                    <div class="stat-value" style="font-size:1.08rem">{{ $latestRun?->finished_at?->format('Y-m-d H:i:s') ?? '尚未執行' }}</div>
                </article>
                <article class="stat">
                    <div class="stat-label">上次跳過</div>
                    <div class="stat-value">{{ $latestRun?->skipped_count ?? 0 }}</div>
                </article>
            </div>

            <div class="hero-links">
                <a class="link-btn primary" href="{{ route('command-runner.index') }}">去 command-runner 重跑掃描</a>
                <a class="link-btn secondary" href="{{ route('videos.external-duplicates.index') }}">參考既有影片審核頁</a>
            </div>
        </div>
    </section>

    <section class="toolbar">
        <div class="toolbar-top">
            <form method="get" action="{{ route('videos.rerun-sync.index') }}">
                <input type="search" name="q" value="{{ $search }}" placeholder="搜尋 hash、別名、資源代號">
                <select name="mode">
                    <option value="all" {{ $mode === 'all' ? 'selected' : '' }}>全部差異</option>
                    <option value="missing" {{ $mode === 'missing' ? 'selected' : '' }}>只看缺少</option>
                    <option value="extra" {{ $mode === 'extra' ? 'selected' : '' }}>只看多出</option>
                </select>
                <button class="action-btn ghost" type="submit">套用條件</button>
            </form>
            <div class="status-chip">目前模式：{{ $mode === 'all' ? '全部差異' : ($mode === 'missing' ? '只看缺少' : '只看多出') }}</div>
        </div>

        @if (!empty($flashResult))
            <div class="banner">
                <strong>
                    {{ ($flashResult['action'] ?? '') === 'delete_extras' ? '刪除多出來源' : '補齊缺少來源' }}
                    已處理 {{ $flashResult['processed'] ?? 0 }} 組，並重跑一次增量掃描。
                </strong>
                @if (!empty($flashResult['logs']))
                    <ul class="banner-list">
                        @foreach (array_slice($flashResult['logs'], 0, 6) as $log)
                            <li>[{{ $log['status'] }}] {{ $log['target_source'] }}: {{ $log['message'] }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </section>

    <section class="section-head">
        <div>
            <h2>差異群組</h2>
            <p>選取多筆後，可以決定要刪掉目前多出來的 B / C 來源，或是從現有來源補齊缺少的 B / C 來源。</p>
        </div>
        <div class="pill">每頁顯示 {{ $perPage }} 筆的視覺密度設計，實際目前共 {{ $groups->count() }} 組</div>
    </section>

    <form method="post" action="{{ route('videos.rerun-sync.apply') }}" id="batch-form">
        @csrf
        <input type="hidden" name="mode" value="{{ $mode }}">
        <input type="hidden" name="q" value="{{ $search }}">

        <div class="batch-bar">
            <div class="batch-controls">
                <label class="check-label">
                    <input type="checkbox" id="select-all">
                    全選目前列表
                </label>
                <span class="pill">已選 <strong id="selected-count">0</strong> 組</span>
            </div>
            <div class="batch-controls">
                <button class="action-btn secondary" type="submit" name="action" value="delete_extras" data-needs-selection>刪除多出來的</button>
                <button class="action-btn primary" type="submit" name="action" value="fill_missing" data-needs-selection>補齊其他缺少的</button>
            </div>
        </div>

        @if ($groups->isEmpty())
            <div class="empty-state">
                目前沒有符合條件的差異群組。
                @if ($latestRun === null)
                    先到 command runner 執行一次 `video:sync-rerun-sources`。
                @else
                    可以調整搜尋條件，或重新跑一次增量掃描。
                @endif
            </div>
        @else
            <div class="results">
                @foreach ($groups as $group)
                    <article class="result-card">
                        <div class="result-head">
                            <div class="result-title">
                                <input class="result-checkbox" type="checkbox" name="hashes[]" value="{{ $group['hash'] }}" data-group-checkbox>
                                <div>
                                    <h3 class="result-name">{{ $group['title'] }}</h3>
                                    <div class="result-meta">
                                        <span class="badge hash">指紋 {{ $group['hash_short'] }}</span>
                                        <span class="badge">{{ $formatBytes((int) $group['size_bytes']) }}</span>
                                        @if ($group['has_missing'])
                                            <span class="badge missing">有缺少來源</span>
                                        @endif
                                        @if ($group['has_extra'])
                                            <span class="badge extra">有多出來源</span>
                                        @endif
                                        @if ($group['can_fill_missing'])
                                            <span class="badge fill">可補齊 {{ implode(' / ', array_map([\App\Support\VideoRerunSyncSource::class, 'label'], $group['missing_targets'])) }}</span>
                                        @endif
                                    </div>
                                    @if (!empty($group['aliases']))
                                        <div class="aliases">別名 / 同檔案代號：{{ implode('、', $group['aliases']) }}</div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="source-grid">
                            @foreach ($group['sources'] as $sourceData)
                                <section class="source-card {{ $sourceData['present'] ? 'is-present' : 'is-missing' }}">
                                    <div class="source-head">
                                        <strong>{{ $sourceData['label'] }}</strong>
                                        <span class="source-count">{{ $sourceData['present'] ? $sourceData['count'] . ' 筆' : '缺少' }}</span>
                                    </div>

                                    @if (!$sourceData['present'])
                                        <p class="source-empty">這一邊目前沒有這個實體檔案。</p>
                                    @else
                                        <div class="source-list">
                                            @foreach ($sourceData['entries'] as $entry)
                                                <article class="source-item">
                                                    <div class="source-item-name">{{ $entry['resource_key'] ?: $entry['display_name'] }}</div>
                                                    <div class="source-item-sub">{{ $entry['display_name'] }}</div>
                                                    <div class="source-item-path">{{ $entry['relative_path'] ?: $entry['absolute_path'] }}</div>
                                                    @if (!empty($entry['metadata']['videos_url']))
                                                        <a class="source-item-link" href="{{ $entry['metadata']['videos_url'] }}" target="_blank" rel="noreferrer">打開 DB 影片頁</a>
                                                    @endif
                                                </article>
                                            @endforeach
                                        </div>
                                    @endif
                                </section>
                            @endforeach
                        </div>

                        <div class="resolution-note">
                            <span class="pill">
                                刪除策略：
                                {{ $group['can_delete_extras'] ? '會處理 ' . implode(' / ', array_map([\App\Support\VideoRerunSyncSource::class, 'label'], $group['delete_targets'])) : '目前沒有可自動刪除的目標' }}
                            </span>
                            <span class="pill">
                                補齊策略：
                                {{ $group['can_fill_missing'] ? '會補到 ' . implode(' / ', array_map([\App\Support\VideoRerunSyncSource::class, 'label'], $group['missing_targets'])) : '目前沒有可自動補齊的目標' }}
                            </span>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </form>

    <section class="section-head">
        <div>
            <h2>無法指紋比對</h2>
            <p>這些來源目前存在，但實體檔不存在、找不到原始檔，或無法計算檔案指紋，所以沒辦法自動歸到差異群組。</p>
        </div>
        <div class="pill">共 {{ $issues->count() }} 筆</div>
    </section>

    @if ($issues->isEmpty())
        <div class="empty-state">目前沒有指紋異常來源。</div>
    @else
        <div class="issue-list">
            @foreach ($issues as $issue)
                <article class="issue-card">
                    <h3>{{ $issue['title'] }} <span class="pill">{{ $issue['source_label'] }}</span></h3>
                    <p>{{ $issue['message'] }}</p>
                    @if (!empty($issue['display_name']))
                        <p>顯示名稱：{{ $issue['display_name'] }}</p>
                    @endif
                    @if (!empty($issue['path']))
                        <p>路徑：{{ $issue['path'] }}</p>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</main>

<script>
    (() => {
        const form = document.getElementById('batch-form');
        if (!form) {
            return;
        }

        const selectAll = document.getElementById('select-all');
        const checkboxes = [...form.querySelectorAll('[data-group-checkbox]')];
        const selectedCount = document.getElementById('selected-count');
        const buttons = [...form.querySelectorAll('[data-needs-selection]')];

        const updateState = () => {
            const checked = checkboxes.filter((input) => input.checked).length;
            selectedCount.textContent = String(checked);
            buttons.forEach((button) => {
                button.disabled = checked === 0;
                button.style.opacity = checked === 0 ? '.5' : '1';
            });

            if (selectAll) {
                selectAll.checked = checked > 0 && checked === checkboxes.length;
                selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
            }
        };

        selectAll?.addEventListener('change', () => {
            checkboxes.forEach((input) => {
                input.checked = selectAll.checked;
            });
            updateState();
        });

        checkboxes.forEach((input) => {
            input.addEventListener('change', updateState);
        });

        updateState();
    })();
</script>
</body>
</html>
