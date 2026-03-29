<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dialogues Token 統計看板</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+TC:wght@400;500;700;900&display=swap');

        :root {
            --sky-50: #f6fbff;
            --sky-100: #eef8ff;
            --sky-200: #dff1ff;
            --sky-300: #c7e7ff;
            --sky-400: #8ac8ff;
            --sky-500: #47a6ff;
            --mint-200: #dbfff2;
            --mint-400: #79dfc2;
            --peach-200: #ffe1cc;
            --peach-400: #ffab73;
            --rose-200: #ffd7ec;
            --rose-400: #ff8ec0;
            --ink-900: #163047;
            --ink-700: #36536b;
            --ink-500: #6f8ba1;
            --line: rgba(87, 153, 214, 0.22);
            --line-strong: rgba(79, 145, 216, 0.4);
            --panel: rgba(255, 255, 255, 0.72);
            --shadow: 0 28px 80px rgba(66, 116, 163, 0.16);
            --shadow-soft: 0 20px 50px rgba(93, 138, 182, 0.12);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Plus Jakarta Sans", "Noto Sans TC", sans-serif;
            color: var(--ink-900);
            background:
                radial-gradient(circle at top left, rgba(121, 223, 194, 0.38), transparent 34%),
                radial-gradient(circle at top right, rgba(255, 171, 115, 0.22), transparent 36%),
                linear-gradient(135deg, #fcfeff 0%, #f5fbff 38%, #f8f4ff 100%);
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 34rem;
            height: 34rem;
            border-radius: 50%;
            filter: blur(24px);
            opacity: 0.72;
            z-index: -2;
            animation: drift 18s ease-in-out infinite alternate;
        }

        body::before {
            top: -10rem;
            right: -8rem;
            background: radial-gradient(circle, rgba(108, 187, 255, 0.4) 0%, rgba(108, 187, 255, 0) 70%);
        }

        body::after {
            bottom: -12rem;
            left: -10rem;
            background: radial-gradient(circle, rgba(255, 171, 115, 0.34) 0%, rgba(255, 171, 115, 0) 72%);
            animation-duration: 24s;
        }

        .mesh {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: -1;
            background-image:
                linear-gradient(rgba(140, 190, 237, 0.06) 1px, transparent 1px),
                linear-gradient(90deg, rgba(140, 190, 237, 0.06) 1px, transparent 1px);
            background-size: 44px 44px;
            mask-image: radial-gradient(circle at center, black 48%, transparent 100%);
            animation: meshShift 22s linear infinite;
        }

        .shell {
            width: min(1380px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 40px 0 56px;
        }

        .hero,
        .summary-grid,
        .table-card {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 28px;
            background: var(--panel);
            backdrop-filter: blur(20px);
            box-shadow: var(--shadow);
        }

        .hero::before,
        .summary-grid::before,
        .table-card::before {
            content: "";
            position: absolute;
            inset: -40%;
            background: conic-gradient(from 160deg, rgba(71, 166, 255, 0.14), rgba(121, 223, 194, 0.1), rgba(255, 171, 115, 0.12), rgba(71, 166, 255, 0.14));
            animation: rotateAura 16s linear infinite;
            z-index: 0;
        }

        .hero-content,
        .summary-grid > *,
        .table-card > * {
            position: relative;
            z-index: 1;
        }

        .hero {
            padding: 34px 34px 28px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 9px 14px;
            border-radius: 999px;
            border: 1px solid rgba(118, 188, 255, 0.28);
            background: rgba(255, 255, 255, 0.82);
            color: var(--ink-700);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .hero-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.9fr);
            gap: 24px;
            margin-top: 22px;
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(2rem, 5vw, 3.8rem);
            line-height: 1;
            letter-spacing: -0.05em;
        }

        .hero h1 .gradient-text {
            background: linear-gradient(120deg, #2787db 0%, #11b4a0 48%, #f28a48 100%);
            -webkit-background-clip: text;
            color: transparent;
        }

        .hero p {
            margin: 18px 0 0;
            max-width: 720px;
            color: var(--ink-700);
            font-size: 1rem;
            line-height: 1.8;
        }

        .hero-meta {
            display: grid;
            gap: 14px;
        }

        .meta-box {
            padding: 18px 20px;
            border-radius: 22px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(242, 249, 255, 0.88));
            border: 1px solid rgba(102, 165, 226, 0.2);
            box-shadow: var(--shadow-soft);
            transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
        }

        .meta-box:hover {
            transform: translateY(-4px);
            border-color: var(--line-strong);
            box-shadow: 0 26px 55px rgba(82, 138, 190, 0.18);
        }

        .meta-label {
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.08em;
            color: var(--ink-500);
            text-transform: uppercase;
        }

        .meta-value {
            margin-top: 8px;
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--ink-900);
        }

        .meta-sub {
            margin-top: 6px;
            color: var(--ink-700);
            font-size: 0.92rem;
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 24px;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 18px;
            border-radius: 16px;
            border: 1px solid rgba(102, 165, 226, 0.24);
            text-decoration: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.98rem;
            color: var(--ink-900);
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.96), rgba(236, 248, 255, 0.82));
            box-shadow: 0 16px 30px rgba(101, 148, 193, 0.14);
            transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease, background 180ms ease;
        }

        .action-btn:hover {
            transform: translateY(-3px) scale(1.01);
            border-color: rgba(70, 146, 222, 0.44);
            background: linear-gradient(135deg, rgba(255, 255, 255, 1), rgba(227, 245, 255, 0.94));
            box-shadow: 0 20px 42px rgba(89, 138, 184, 0.22);
        }

        .action-btn.primary {
            background: linear-gradient(135deg, rgba(71, 166, 255, 0.16), rgba(121, 223, 194, 0.18));
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 18px;
            margin-top: 24px;
            padding: 18px;
        }

        .summary-card {
            padding: 22px 20px 20px;
            border-radius: 22px;
            background: linear-gradient(145deg, rgba(255, 255, 255, 0.95), rgba(246, 251, 255, 0.88));
            border: 1px solid rgba(109, 171, 231, 0.2);
            box-shadow: var(--shadow-soft);
            transition: transform 180ms ease, border-color 180ms ease, box-shadow 180ms ease;
        }

        .summary-card:hover {
            transform: translateY(-5px);
            border-color: rgba(83, 149, 219, 0.42);
            box-shadow: 0 24px 48px rgba(93, 145, 192, 0.18);
        }

        .summary-card h2 {
            margin: 12px 0 0;
            font-size: clamp(1.7rem, 3vw, 2.3rem);
            line-height: 1;
            letter-spacing: -0.04em;
        }

        .summary-card p {
            margin: 10px 0 0;
            color: var(--ink-700);
            font-size: 0.95rem;
        }

        .summary-chip {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.04em;
        }

        .chip-blue { background: rgba(71, 166, 255, 0.14); color: #2378c8; }
        .chip-green { background: rgba(101, 214, 176, 0.18); color: #158565; }
        .chip-orange { background: rgba(255, 171, 115, 0.2); color: #c76925; }
        .chip-pink { background: rgba(255, 142, 192, 0.16); color: #c95c96; }
        .chip-purple { background: rgba(142, 147, 255, 0.14); color: #606ae2; }

        .table-card {
            margin-top: 24px;
            padding: 22px;
        }

        .table-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 18px;
        }

        .table-title h3 {
            margin: 0;
            font-size: 1.45rem;
        }

        .table-title p {
            margin: 8px 0 0;
            color: var(--ink-700);
        }

        .table-wrap {
            overflow-x: auto;
            border-radius: 22px;
            border: 1px solid rgba(96, 158, 220, 0.22);
            background: rgba(255, 255, 255, 0.84);
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            min-width: 1080px;
        }

        thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            padding: 18px 16px;
            text-align: left;
            font-size: 12px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--ink-500);
            background: linear-gradient(180deg, rgba(246, 252, 255, 0.98), rgba(239, 248, 255, 0.95));
            border-bottom: 1px solid rgba(112, 173, 233, 0.2);
        }

        tbody tr {
            transition: transform 180ms ease, background 180ms ease, box-shadow 180ms ease;
        }

        tbody tr:nth-child(odd) {
            background: rgba(252, 254, 255, 0.86);
        }

        tbody tr:nth-child(even) {
            background: rgba(244, 250, 255, 0.88);
        }

        tbody tr:hover {
            transform: translateY(-2px);
            background: linear-gradient(90deg, rgba(216, 240, 255, 0.42), rgba(225, 252, 244, 0.52));
            box-shadow: inset 0 0 0 1px rgba(84, 149, 220, 0.2);
        }

        td {
            padding: 18px 16px;
            vertical-align: top;
            border-bottom: 1px solid rgba(112, 173, 233, 0.12);
        }

        .prefix-cell {
            min-width: 190px;
        }

        .prefix-token {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 14px;
            background: linear-gradient(135deg, rgba(71, 166, 255, 0.12), rgba(121, 223, 194, 0.16));
            border: 1px solid rgba(81, 149, 220, 0.22);
            font-weight: 800;
            color: #205f95;
        }

        .prefix-label {
            display: block;
            margin-top: 10px;
            color: var(--ink-700);
            font-weight: 700;
        }

        .metric {
            font-weight: 800;
            font-size: 1.15rem;
            color: var(--ink-900);
        }

        .metric-sub {
            margin-top: 6px;
            font-size: 0.88rem;
            color: var(--ink-500);
        }

        .progress-stack {
            min-width: 220px;
        }

        .progress-label {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: center;
            margin-bottom: 10px;
            font-weight: 800;
            color: var(--ink-900);
        }

        .progress-track {
            position: relative;
            height: 12px;
            border-radius: 999px;
            background: linear-gradient(90deg, rgba(170, 214, 247, 0.34), rgba(255, 219, 193, 0.28));
            overflow: hidden;
            border: 1px solid rgba(112, 173, 233, 0.18);
        }

        .progress-bar {
            position: absolute;
            inset: 0 auto 0 0;
            width: 0;
            border-radius: inherit;
            background: linear-gradient(90deg, #4aa9ff, #31d0ba 68%, #ffc17b 100%);
            box-shadow: 0 0 18px rgba(73, 166, 255, 0.22);
            animation: pulseBar 2.8s ease-in-out infinite;
        }

        .checkpoint {
            min-width: 360px;
        }

        .checkpoint-badge {
            display: inline-flex;
            padding: 7px 12px;
            border-radius: 999px;
            background: rgba(101, 214, 176, 0.16);
            color: #177455;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        .checkpoint-badge.pending {
            background: rgba(255, 171, 115, 0.18);
            color: #bf6528;
        }

        .token-line {
            margin-top: 12px;
            font-size: 0.94rem;
            line-height: 1.7;
            color: var(--ink-700);
            word-break: break-all;
        }

        .token-line strong {
            color: var(--ink-900);
        }

        .empty-state {
            padding: 34px 24px;
            text-align: center;
            color: var(--ink-700);
        }

        .count-up {
            display: inline-block;
        }

        @keyframes drift {
            from { transform: translate3d(0, 0, 0) scale(1); }
            to { transform: translate3d(-24px, 18px, 0) scale(1.08); }
        }

        @keyframes meshShift {
            from { transform: translate3d(0, 0, 0); }
            to { transform: translate3d(24px, 18px, 0); }
        }

        @keyframes rotateAura {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        @keyframes pulseBar {
            0%, 100% { filter: brightness(1); }
            50% { filter: brightness(1.08); }
        }

        @media (max-width: 1180px) {
            .hero-grid,
            .summary-grid {
                grid-template-columns: 1fr;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100vw - 20px, 1380px);
                padding: 24px 0 38px;
            }

            .hero,
            .table-card {
                padding: 20px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
                padding: 14px;
            }

            .hero-actions {
                flex-direction: column;
            }

            .action-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="mesh"></div>
    <main class="shell">
        <section class="hero">
            <div class="hero-content">
                <span class="eyebrow">Dialogues Token Progress</span>
                <div class="hero-grid">
                    <div>
                        <h1>淺色系 <span class="gradient-text">同步統計看板</span></h1>
                        <p>
                            這個頁面會掃描 <code>dialogues</code> 內抽出的 token，依照不同前綴做同步進度統計，
                            直接看出哪一種 token 已經收斂、哪一種前綴還有 backlog，以及目前同步到哪一筆。
                        </p>
                        <div class="hero-actions">
                            <button type="button" class="action-btn primary" onclick="window.location.reload()">
                                重新整理統計
                            </button>
                            <a class="action-btn" href="{{ route('dialogues.markRead.page') }}">
                                前往 Dialogues 標記頁
                            </a>
                        </div>
                    </div>
                    <div class="hero-meta">
                        <div class="meta-box">
                            <div class="meta-label">資料快照</div>
                            <div class="meta-value">{{ $generatedAt->format('Y-m-d H:i:s') }}</div>
                            <div class="meta-sub">快取 60 秒，避免每次開頁都重新掃完全部 dialogues。</div>
                        </div>
                        <div class="meta-box">
                            <div class="meta-label">掃描範圍</div>
                            <div class="meta-value">#{{ number_format($summary['max_dialogue_id']) }}</div>
                            <div class="meta-sub">目前最新 dialogues id，已納入這次統計快照。</div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="summary-grid">
            <article class="summary-card">
                <span class="summary-chip chip-blue">Dialogues 總筆數</span>
                <h2 class="count-up" data-target="{{ $summary['dialogue_count'] }}">{{ number_format($summary['dialogue_count']) }}</h2>
                <p>整張 <code>dialogues</code> 表目前的資料量。</p>
            </article>
            <article class="summary-card">
                <span class="summary-chip chip-green">含 Token Rows</span>
                <h2 class="count-up" data-target="{{ $summary['rows_with_tokens'] }}">{{ number_format($summary['rows_with_tokens']) }}</h2>
                <p>至少抽到一個 token 的 dialogues 筆數。</p>
            </article>
            <article class="summary-card">
                <span class="summary-chip chip-orange">Token 前綴數</span>
                <h2 class="count-up" data-target="{{ $summary['prefix_count'] }}">{{ number_format($summary['prefix_count']) }}</h2>
                <p>目前識別到的不同 token 前綴類型。</p>
            </article>
            <article class="summary-card">
                <span class="summary-chip chip-pink">已同步 / 未同步</span>
                <h2>
                    <span class="count-up" data-target="{{ $summary['synced_token_count'] }}">{{ number_format($summary['synced_token_count']) }}</span>
                    <span style="color: var(--ink-500);">/</span>
                    <span class="count-up" data-target="{{ $summary['pending_token_count'] }}">{{ number_format($summary['pending_token_count']) }}</span>
                </h2>
                <p>依 token 次數計算，不是只看 dialogues row。</p>
            </article>
            <article class="summary-card">
                <span class="summary-chip chip-purple">整體完成率</span>
                <h2>{{ number_format($summary['completion_percent'], 1) }}%</h2>
                <p>目前總 token 同步進度，越接近 100% 代表 backlog 越小。</p>
            </article>
        </section>

        <section class="table-card">
            <div class="table-head">
                <div class="table-title">
                    <h3>不同前綴 Token 統計表</h3>
                    <p>欄位包含總數、已同步、未同步、完成率，以及目前同步到哪一筆。</p>
                </div>
                <div class="summary-chip chip-blue">
                    共 {{ number_format(count($prefixStats)) }} 種前綴
                </div>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>前綴</th>
                            <th>總共多少筆</th>
                            <th>已同步</th>
                            <th>未同步</th>
                            <th>完成百分比</th>
                            <th>目前同步到哪一筆</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($prefixStats as $row)
                            <tr>
                                <td class="prefix-cell">
                                    <span class="prefix-token">{{ $row['prefix'] }}</span>
                                    <span class="prefix-label">{{ $row['label'] }}</span>
                                </td>
                                <td>
                                    <div class="metric">{{ number_format($row['total_count']) }}</div>
                                    <div class="metric-sub">抽出 token 次數</div>
                                </td>
                                <td>
                                    <div class="metric" style="color: #177455;">{{ number_format($row['synced_count']) }}</div>
                                    <div class="metric-sub">標記 <code>is_sync=1</code></div>
                                </td>
                                <td>
                                    <div class="metric" style="color: #bf6528;">{{ number_format($row['pending_count']) }}</div>
                                    <div class="metric-sub">仍待同步或待補標</div>
                                </td>
                                <td class="progress-stack">
                                    <div class="progress-label">
                                        <span>{{ number_format($row['completion_percent'], 1) }}%</span>
                                        <span>#{{ number_format($row['latest_dialogue_id']) }}</span>
                                    </div>
                                    <div class="progress-track">
                                        <div class="progress-bar" style="width: {{ max(min($row['completion_percent'], 100), 0) }}%;"></div>
                                    </div>
                                    <div class="metric-sub">最新看到的 row：{{ $row['latest_token'] ?? '—' }}</div>
                                </td>
                                <td class="checkpoint">
                                    @if ($row['latest_synced_token'])
                                        <span class="checkpoint-badge">最新已同步</span>
                                        <div class="token-line">
                                            <strong>#{{ number_format($row['latest_synced_dialogue_id']) }}</strong>
                                            {{ $row['latest_synced_token'] }}
                                        </div>
                                    @else
                                        <span class="checkpoint-badge pending">尚未開始</span>
                                        <div class="token-line">這個前綴目前還沒有已同步 token。</div>
                                    @endif

                                    @if ($row['latest_pending_token'])
                                        <div class="token-line">
                                            <strong>下一筆待同步：</strong>
                                            #{{ number_format($row['latest_pending_dialogue_id']) }}
                                            {{ $row['latest_pending_token'] }}
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="empty-state">
                                    目前掃不到可辨識的 token，請確認 <code>dialogues</code> 是否已有 token 內容。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const counters = document.querySelectorAll('.count-up[data-target]');
            counters.forEach((counter) => {
                const rawTarget = Number(counter.dataset.target || '0');
                if (!Number.isFinite(rawTarget)) {
                    return;
                }

                const duration = 900;
                const startTime = performance.now();

                const render = (now) => {
                    const progress = Math.min((now - startTime) / duration, 1);
                    const eased = 1 - Math.pow(1 - progress, 3);
                    const value = Math.round(rawTarget * eased);
                    counter.textContent = value.toLocaleString();

                    if (progress < 1) {
                        requestAnimationFrame(render);
                    }
                };

                requestAnimationFrame(render);
            });
        });
    </script>
</body>
</html>
