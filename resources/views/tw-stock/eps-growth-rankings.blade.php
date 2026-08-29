<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <title>台股三年 EPS 成長排行</title>
    <style>
        :root {
            --bg-deep: #050816;
            --bg-mid: #09112a;
            --panel: rgba(11, 20, 48, 0.78);
            --panel-strong: rgba(14, 26, 60, 0.94);
            --line: rgba(142, 175, 255, 0.18);
            --line-hot: rgba(99, 218, 255, 0.48);
            --text: #f4f7ff;
            --muted: #9daed1;
            --cyan: #67e8f9;
            --blue: #8aa8ff;
            --violet: #c4a7ff;
            --gold: #ffd875;
            --up: #ff6b83;
            --down: #45d6a4;
            --flat: #9aa8c5;
            --shadow: 0 24px 80px rgba(0, 0, 0, 0.34);
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", "Noto Sans TC", sans-serif;
            background:
                radial-gradient(circle at 14% 4%, rgba(69, 93, 255, 0.28), transparent 32rem),
                radial-gradient(circle at 88% 12%, rgba(0, 224, 255, 0.18), transparent 28rem),
                radial-gradient(circle at 60% 90%, rgba(153, 80, 255, 0.16), transparent 34rem),
                linear-gradient(145deg, var(--bg-deep), var(--bg-mid) 52%, #071227);
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            opacity: 0.28;
            background-image:
                linear-gradient(rgba(105, 142, 220, 0.08) 1px, transparent 1px),
                linear-gradient(90deg, rgba(105, 142, 220, 0.08) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: linear-gradient(to bottom, black, transparent 88%);
        }

        .aurora {
            position: fixed;
            width: 34rem;
            height: 34rem;
            border-radius: 50%;
            filter: blur(85px);
            opacity: 0.18;
            pointer-events: none;
            animation: drift 15s ease-in-out infinite alternate;
        }

        .aurora.one { top: -14rem; right: 6%; background: #36d9ff; }
        .aurora.two { bottom: -17rem; left: 8%; background: #8259ff; animation-delay: -6s; }

        @keyframes drift {
            from { transform: translate3d(-4%, -2%, 0) scale(0.92); }
            to { transform: translate3d(8%, 9%, 0) scale(1.12); }
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(16px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .page-shell {
            position: relative;
            z-index: 1;
            width: min(var(--tw-stock-shell-max), calc(100% - (var(--tw-stock-shell-gutter) * 2)));
            margin: 0 auto;
            padding: 24px 0 56px;
        }

        .glass {
            border: 1px solid var(--line);
            background: linear-gradient(145deg, rgba(16, 29, 66, 0.82), rgba(7, 15, 38, 0.74));
            box-shadow: var(--shadow), inset 0 1px rgba(255, 255, 255, 0.04);
            backdrop-filter: blur(20px);
        }

        .hero {
            position: relative;
            overflow: hidden;
            padding: clamp(24px, 4vw, 48px);
            border-radius: 30px;
            animation: rise 0.6s ease both;
        }

        .hero::after {
            content: "EPS";
            position: absolute;
            right: clamp(12px, 5vw, 70px);
            bottom: -34px;
            color: rgba(127, 229, 255, 0.055);
            font-size: clamp(8rem, 18vw, 17rem);
            font-weight: 950;
            line-height: 1;
            letter-spacing: -0.09em;
            pointer-events: none;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 14px;
            color: var(--cyan);
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.16em;
            text-transform: uppercase;
        }

        .eyebrow::before {
            content: "";
            width: 28px;
            height: 2px;
            background: linear-gradient(90deg, var(--cyan), transparent);
            box-shadow: 0 0 16px var(--cyan);
        }

        h1 {
            max-width: 900px;
            margin: 0;
            font-size: clamp(2.15rem, 5vw, 5.2rem);
            line-height: 0.98;
            letter-spacing: -0.055em;
        }

        h1 span {
            color: transparent;
            background: linear-gradient(100deg, #ffffff 10%, var(--cyan) 48%, var(--violet));
            background-clip: text;
            -webkit-background-clip: text;
        }

        .hero-copy {
            max-width: 850px;
            margin: 20px 0 0;
            color: #bec9e4;
            font-size: clamp(0.94rem, 1.45vw, 1.08rem);
            line-height: 1.8;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 24px;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 34px;
            padding: 7px 13px;
            border: 1px solid rgba(143, 181, 255, 0.23);
            border-radius: 999px;
            color: #d7e2fb;
            background: rgba(5, 13, 34, 0.54);
            font-size: 0.78rem;
            font-weight: 700;
        }

        .meta-pill strong { color: #fff; }

        .nav-actions {
            display: flex;
            gap: 8px;
            margin: 16px 0 0;
            padding: 10px;
            overflow-x: auto;
            border: 1px solid rgba(139, 171, 244, 0.16);
            border-radius: 18px;
            background: rgba(4, 10, 27, 0.74);
            scrollbar-width: thin;
        }

        .nav-actions a {
            flex: 0 0 auto;
            padding: 9px 13px;
            border: 1px solid transparent;
            border-radius: 11px;
            color: #9fb0d1;
            font-size: 0.78rem;
            font-weight: 750;
            text-decoration: none;
            transition: 0.22s ease;
        }

        .nav-actions a:hover {
            color: #fff;
            border-color: rgba(103, 232, 249, 0.28);
            background: rgba(83, 144, 255, 0.12);
            transform: translateY(-1px);
        }

        .nav-actions a.active {
            color: #071324;
            background: linear-gradient(110deg, var(--cyan), #9db6ff);
            box-shadow: 0 8px 24px rgba(103, 232, 249, 0.22);
        }

        .snapshot-picker {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-top: 12px;
            padding: 12px 14px;
            border: 1px solid rgba(139, 171, 244, 0.16);
            border-radius: 16px;
            background: rgba(4, 10, 27, 0.64);
        }

        .snapshot-picker label {
            color: #aebddd;
            font-size: 0.76rem;
            font-weight: 780;
        }

        .snapshot-picker select {
            min-width: min(300px, 52vw);
            min-height: 39px;
            padding: 0 36px 0 12px;
            border: 1px solid rgba(103, 232, 249, 0.3);
            border-radius: 11px;
            outline: none;
            color: #f4f7ff;
            background: #0b1735;
            font: inherit;
            font-size: 0.78rem;
            font-weight: 800;
            cursor: pointer;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin: 16px 0;
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            min-height: 126px;
            padding: 20px;
            border-radius: 20px;
            animation: rise 0.62s ease both;
        }

        .summary-card:nth-child(2) { animation-delay: 0.04s; }
        .summary-card:nth-child(3) { animation-delay: 0.08s; }
        .summary-card:nth-child(4) { animation-delay: 0.12s; }
        .summary-card:nth-child(5) { animation-delay: 0.16s; }

        .summary-card::after {
            content: "";
            position: absolute;
            width: 100px;
            height: 100px;
            right: -45px;
            bottom: -45px;
            border-radius: 50%;
            background: var(--accent, var(--cyan));
            filter: blur(28px);
            opacity: 0.18;
        }

        .summary-label {
            color: var(--muted);
            font-size: 0.72rem;
            font-weight: 780;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .summary-value {
            margin-top: 12px;
            font-size: clamp(1.55rem, 2.6vw, 2.4rem);
            font-weight: 900;
            line-height: 1;
        }

        .summary-note {
            margin-top: 9px;
            color: #8fa2c8;
            font-size: 0.72rem;
        }

        .toolbar {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) auto auto auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            padding: 12px;
            border-radius: 18px;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 9px;
            min-height: 42px;
            padding: 0 13px;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(3, 9, 25, 0.7);
        }

        .search-box input {
            width: 100%;
            border: 0;
            outline: 0;
            color: #fff;
            background: transparent;
            font: inherit;
        }

        .search-box input::placeholder { color: #6f80a3; }

        .eps-basis-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 42px;
            padding: 0 13px;
            border: 1px solid var(--line);
            border-radius: 12px;
            color: #b8c5df;
            background: rgba(3, 9, 25, 0.62);
            font-size: 0.76rem;
            font-weight: 750;
            cursor: pointer;
        }

        .toggle input { accent-color: var(--cyan); }

        .visible-count {
            min-width: 94px;
            color: var(--cyan);
            text-align: right;
            font-size: 0.78rem;
            font-weight: 850;
        }

        .table-panel {
            overflow: hidden;
            border-radius: 22px;
            animation: rise 0.7s 0.1s ease both;
        }

        .table-scroll { overflow: auto; max-height: 78vh; }

        table {
            width: 100%;
            min-width: 1500px;
            border-collapse: separate;
            border-spacing: 0;
            font-variant-numeric: tabular-nums;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 5;
            padding: 13px 11px;
            border-bottom: 1px solid rgba(126, 160, 232, 0.24);
            color: #9eb1d7;
            background: rgba(7, 15, 37, 0.96);
            font-size: 0.69rem;
            font-weight: 850;
            letter-spacing: 0.04em;
            text-align: right;
            white-space: nowrap;
            backdrop-filter: blur(18px);
        }

        th:nth-child(1), th:nth-child(2), td:nth-child(1), td:nth-child(2) { text-align: left; }

        td {
            padding: 13px 11px;
            border-bottom: 1px solid rgba(125, 157, 220, 0.1);
            color: #dbe5fa;
            font-size: 0.78rem;
            text-align: right;
            white-space: nowrap;
            transition: background 0.18s ease, color 0.18s ease;
        }

        tbody tr { background: rgba(7, 14, 34, 0.32); transition: 0.18s ease; }
        tbody tr:nth-child(even) { background: rgba(20, 34, 69, 0.22); }
        tbody tr:hover { background: rgba(59, 107, 185, 0.2); }
        tbody tr:hover td { color: #fff; }

        .rank-cell { font-size: 0.92rem; font-weight: 950; }

        .rank-orb {
            display: inline-grid;
            place-items: center;
            width: 31px;
            height: 31px;
            border: 1px solid rgba(133, 173, 255, 0.2);
            border-radius: 10px;
            background: rgba(17, 31, 68, 0.72);
        }

        tr.top-1 .rank-orb { color: #2b1c00; border-color: #ffe394; background: linear-gradient(135deg, #fff0a4, #f5b942); box-shadow: 0 0 22px rgba(255, 207, 88, 0.32); }
        tr.top-2 .rank-orb { color: #172033; border-color: #e8efff; background: linear-gradient(135deg, #f6f8ff, #aebed9); }
        tr.top-3 .rank-orb { color: #2c1606; border-color: #eeb58f; background: linear-gradient(135deg, #f7c5a3, #b9774b); }

        .stock-name { color: #fff; font-size: 0.88rem; font-weight: 880; }
        .stock-group { color: #9fdcf2; font-size: 0.72rem; font-weight: 780; }
        .stock-meta { margin-top: 3px; color: #7184aa; font-size: 0.67rem; font-weight: 700; }
        .stock-meta a { color: #8eeaff; text-decoration: none; }

        .price { color: #fff; font-weight: 900; text-align: center; }
        .price-value { font-size: 0.94rem; }
        .expected-price-value {
            display: inline-flex;
            align-items: baseline;
            gap: 5px;
            white-space: nowrap;
        }
        .potential-return { font-size: 0.67rem; }
        .moving-average-signals {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 7px;
            flex-wrap: nowrap;
            white-space: nowrap;
        }
        .moving-average-signal {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 0.66rem;
            font-weight: 850;
            line-height: 1.2;
        }
        .moving-average-signal.above { color: var(--up); }
        .moving-average-signal.below { color: var(--down); }
        .moving-average-signal.unavailable { color: var(--flat); }
        .moving-average-arrow { font-size: 0.82rem; line-height: 1; }
        .positive { color: var(--up); font-weight: 850; }
        .negative { color: var(--down); font-weight: 850; }

        .sum-score {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            min-width: 86px;
            padding: 7px 10px;
            border: 1px solid rgba(103, 232, 249, 0.22);
            border-radius: 10px;
            color: var(--cyan);
            background: rgba(54, 205, 239, 0.08);
            font-weight: 950;
            box-shadow: inset 0 0 22px rgba(64, 204, 255, 0.05);
        }

        .weighted-score {
            display: inline-flex;
            align-items: baseline;
            justify-content: center;
            gap: 4px;
            min-width: 92px;
            padding: 8px 11px;
            border: 1px solid rgba(255, 211, 105, 0.42);
            border-radius: 11px;
            color: #ffd976;
            background: linear-gradient(135deg, rgba(255, 194, 71, 0.18), rgba(255, 107, 131, 0.08));
            font-weight: 950;
            box-shadow: 0 0 24px rgba(255, 194, 71, 0.1), inset 0 0 18px rgba(255, 223, 133, 0.05);
        }

        .weighted-score small { color: #9baccc; font-size: 0.62rem; }

        .rank-change {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            min-height: 29px;
            padding: 5px 8px;
            border-radius: 999px;
            font-weight: 950;
        }

        .rank-change.up { color: #ffdce2; border: 1px solid rgba(255, 107, 131, 0.35); background: rgba(255, 70, 104, 0.15); }
        .rank-change.down { color: #b9ffe7; border: 1px solid rgba(69, 214, 164, 0.35); background: rgba(36, 190, 136, 0.13); }
        .rank-change.flat { color: #aab8d1; border: 1px solid rgba(153, 170, 204, 0.18); background: rgba(112, 129, 164, 0.1); }

        .low-base {
            display: inline-flex;
            margin-left: 5px;
            padding: 3px 6px;
            border: 1px solid rgba(255, 216, 117, 0.3);
            border-radius: 6px;
            color: #ffe7a5;
            background: rgba(255, 194, 63, 0.1);
            font-size: 0.61rem;
            font-weight: 850;
        }

        .neutral-estimate {
            display: inline-flex;
            margin-left: 5px;
            padding: 3px 7px;
            border: 1px solid rgba(196, 167, 255, 0.38);
            border-radius: 999px;
            color: #d9c7ff;
            background: rgba(145, 104, 222, 0.15);
            font-size: 0.58rem;
            font-weight: 900;
            vertical-align: middle;
        }

        .empty-state {
            padding: 70px 24px;
            border-radius: 22px;
            color: #b9c6e2;
            text-align: center;
        }

        .method-note {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 20px;
            align-items: center;
            margin-top: 14px;
            padding: 18px 20px;
            border-radius: 18px;
            color: #9fb0cf;
            font-size: 0.76rem;
            line-height: 1.7;
        }

        .formula { color: var(--cyan); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-weight: 800; }

        @media (max-width: 1100px) {
            .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 760px) {
            .page-shell { padding-top: 10px; }
            .hero { border-radius: 22px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .toolbar { grid-template-columns: 1fr; }
            .visible-count { text-align: left; }
            .method-note { grid-template-columns: 1fr; }
            .snapshot-picker { align-items: stretch; flex-direction: column; }
            .snapshot-picker select { width: 100%; min-width: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; }
        }

        @include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
<div class="aurora one"></div>
<div class="aurora two"></div>

<main class="page-shell">
    <header class="hero glass">
        <div class="eyebrow">TW EQUITY GROWTH RADAR</div>
        <h1>台股三年 <span>EPS 成長排行</span></h1>
        <p class="hero-copy">
            將 2025→2026、2026→2027、2027→2028 三段 EPS 年增率依 1.8：2.5：1 合成，
            再換算為滿分 100 的當週百分位分數，並同步追蹤最新收盤價與前次週排行。
        </p>

        <div class="hero-meta">
            <span class="meta-pill">快照 <strong>{{ $run?->snapshot_date?->format('Y/m/d') ?? '尚未建立' }}</strong></span>
            <span class="meta-pill">股價日 <strong>{{ $run?->price_date?->format('Y/m/d') ?? '—' }}</strong></span>
            <span class="meta-pill">比較前週 <strong>{{ $previousRun?->snapshot_date?->format('Y/m/d') ?? '首次快照' }}</strong></span>
            <span class="meta-pill">完整樣本 <strong>{{ number_format($run?->eligible_count ?? 0) }} 檔</strong></span>
        </div>

        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
            <a href="{{ route('tw-stock.monthly-revenues.index') }}">月營收</a>
            <a class="active" href="{{ route('tw-stock.eps-growth-rankings.index') }}">EPS 三年成長</a>
            <a href="{{ route('tw-stock.active-etf-operations.index') }}">主動 ETF</a>
            <a href="{{ route('tw-stock.taiex-index.kline') }}">加權指數 K 線</a>
            <a href="{{ route('tw-stock.taiex-futures.kline') }}">台指期 K 線</a>
        </nav>

        @if ($availableRuns->isNotEmpty())
            <form class="snapshot-picker" method="get" action="{{ route('tw-stock.eps-growth-rankings.index') }}">
                <label for="snapshotRun">歷史週快照 · 每週資料永久保留，可切換回看</label>
                <input type="hidden" name="eps_basis" value="{{ $epsBasis }}">
                <select id="snapshotRun" name="run" onchange="this.form.submit()">
                    @foreach ($availableRuns as $availableRun)
                        <option value="{{ $availableRun->id }}" @selected($run?->id === $availableRun->id)>
                            {{ $availableRun->snapshot_date->format('Y/m/d') }} · {{ $availableRun->eligible_count }} 檔完整樣本 · 股價 {{ $availableRun->price_date?->format('m/d') ?? '—' }}
                        </option>
                    @endforeach
                </select>
            </form>
        @endif
    </header>

    @if ($run !== null)
        <section class="summary-grid" aria-label="排行摘要">
            <article class="summary-card glass" style="--accent: #67e8f9">
                <div class="summary-label">本期第一名</div>
                <div class="summary-value">{{ $rows->first()?->stock_name ?? '—' }}</div>
                <div class="summary-note">加權分 {{ number_format($rows->first()?->weighted_score ?? 0, 2) }} / 100</div>
            </article>
            <article class="summary-card glass" style="--accent: #ff6b83">
                <div class="summary-label">排名上升</div>
                <div class="summary-value" style="color: var(--up)">+{{ $summary['up'] }}</div>
                <div class="summary-note">{{ $epsBasis === 'actual' ? '相較預估2026排行' : '相較 ' . ($previousRun?->snapshot_date?->format('m/d') ?? '首次快照') }}</div>
            </article>
            <article class="summary-card glass" style="--accent: #45d6a4">
                <div class="summary-label">排名下降</div>
                <div class="summary-value" style="color: var(--down)">-{{ $summary['down'] }}</div>
                <div class="summary-note">下降以綠色標示</div>
            </article>
            <article class="summary-card glass" style="--accent: #9aa8c5">
                <div class="summary-label">排名持平</div>
                <div class="summary-value">{{ $summary['flat'] }}</div>
                <div class="summary-note">表格顯示「-」</div>
            </article>
            <article class="summary-card glass" style="--accent: #c4a7ff">
                <div class="summary-label">連續正成長</div>
                <div class="summary-value" style="color: var(--violet)">{{ $summary['positive_all_three'] }}</div>
                <div class="summary-note">三段年增率均大於 0</div>
            </article>
            <article class="summary-card glass" style="--accent: #d8b4fe">
                <div class="summary-label">中性情境</div>
                <div class="summary-value" style="color: #d9c7ff">{{ $summary['neutral_estimates'] }}</div>
                <div class="summary-note">全新、聯亞的 2028E 參考估算</div>
            </article>
        </section>

        <section class="toolbar glass" aria-label="排行篩選">
            <label class="search-box">
                <span aria-hidden="true">⌕</span>
                <input id="rankingSearch" type="search" placeholder="搜尋股票代號或名稱…" autocomplete="off">
            </label>
            <form class="eps-basis-picker" method="get" action="{{ route('tw-stock.eps-growth-rankings.index') }}">
                @if ($run !== null)<input type="hidden" name="run" value="{{ $run->id }}">@endif
                <label class="toggle">
                    <input type="radio" name="eps_basis" value="forecast" @checked($epsBasis === 'forecast') onchange="this.form.submit()">
                    預估2026
                </label>
                <label class="toggle">
                    <input type="radio" name="eps_basis" value="actual" @checked($epsBasis === 'actual') onchange="this.form.submit()">
                    實際2026（H1×2）
                </label>
            </form>
            <label class="toggle">
                <input id="positiveOnly" type="checkbox">
                只看三段均正成長
            </label>
            <label class="toggle">
                <input id="excludeLowBase" type="checkbox">
                排除低基期
            </label>
            <div id="visibleCount" class="visible-count">顯示 {{ $rows->count() }} / {{ $rows->count() }}</div>
        </section>

        <section class="table-panel glass">
            <div class="table-scroll">
                <table>
                    <thead>
                    <tr>
                        <th>名次</th>
                        <th>股票</th>
                        <th>{{ $epsBasis === 'actual' ? '相較預估' : '週排名' }}</th>
                        <th>當期收盤</th>
                        <th>2027預期價格</th>
                        <th>2025A</th>
                        <th>{{ $epsBasis === 'actual' ? '2026實際推估' : '2026E' }}</th>
                        <th>2027E</th>
                        <th>2028E</th>
                        <th>25→26</th>
                        <th>26→27</th>
                        <th>27→28</th>
                        <th>加權分</th>
                        <th>三段合計</th>
                        <th>營收成長預估</th>
                        <th>分析師</th>
                        <th>預估日期</th>
                    </tr>
                    </thead>
                    <tbody id="rankingRows">
                    @foreach ($rows as $row)
                        @php
                            $allPositive = $row->growth_2025_2026 > 0 && $row->growth_2026_2027 > 0 && $row->growth_2027_2028 > 0;
                            $changeClass = $row->rank_change > 0 ? 'up' : ($row->rank_change < 0 ? 'down' : 'flat');
                            $changeText = $row->rank_change > 0 ? '+' . $row->rank_change : ($row->rank_change < 0 ? (string) $row->rank_change : '-');
                            $revenueGrowth2627 = $row->revenue_2026_thousands > 0
                                ? (($row->revenue_2027_thousands / $row->revenue_2026_thousands) - 1) * 100
                                : null;
                            $revenueGrowth2728 = $row->revenue_2027_thousands > 0
                                ? (($row->revenue_2028_thousands / $row->revenue_2027_thousands) - 1) * 100
                                : null;
                            $movingAverageSignals = [
                                ['label' => '月線', 'days' => 20, 'average' => $row->monthly_moving_average],
                                ['label' => '季線', 'days' => 60, 'average' => $row->quarterly_moving_average],
                            ];
                        @endphp
                        <tr class="{{ $row->rank <= 3 ? 'top-' . $row->rank : '' }}"
                            data-search="{{ mb_strtolower($row->stock_code . ' ' . $row->stock_name . ' ' . ($row->stock_group ?? '')) }}"
                            data-all-positive="{{ $allPositive ? '1' : '0' }}"
                            data-low-base="{{ $row->low_base ? '1' : '0' }}">
                            <td class="rank-cell"><span class="rank-orb">{{ $row->rank }}</span></td>
                            <td>
                                <div class="stock-name">
                                    {{ $row->stock_name }}@if($row->stock_group)<span class="stock-group">（{{ $row->stock_group }}）</span>@endif
                                    @if ($row->low_base)<span class="low-base">低基期</span>@endif
                                    @if ($row->is_neutral_estimate)<span class="neutral-estimate">中性估算</span>@endif
                                </div>
                                <div class="stock-meta">
                                    {{ $row->stock_code }}
                                    @if ($row->news_id)
                                        · <a href="https://news.cnyes.com/news/id/{{ $row->news_id }}" target="_blank" rel="noopener">FactSet ↗</a>
                                    @endif
                                </div>
                            </td>
                            <td title="{{ $epsBasis === 'actual' ? '預估2026名次' : '前次名次' }}：{{ $row->previous_rank ?? '無' }}"><span class="rank-change {{ $changeClass }}">{{ $changeText }}</span></td>
                            <td class="price">
                                <div class="price-value">{{ $row->close_price === null ? '—' : number_format($row->close_price, $row->close_price < 100 ? 2 : ($row->close_price < 1000 ? 1 : 0)) }}</div>
                                <div class="moving-average-signals">
                                    @foreach ($movingAverageSignals as $signal)
                                        @if ($row->close_price !== null && $signal['average'] !== null)
                                            @php($isAbove = $row->close_price >= $signal['average'])
                                            <span class="moving-average-signal {{ $isAbove ? 'above' : 'below' }}"
                                                  title="{{ $signal['days'] }} 日均線 {{ number_format($signal['average'], 2) }}">
                                                <span class="moving-average-arrow" aria-hidden="true">{{ $isAbove ? '↑' : '↓' }}</span>
                                                {{ $signal['label'] }}{{ $isAbove ? '上' : '下' }}
                                            </span>
                                        @else
                                            <span class="moving-average-signal unavailable">— {{ $signal['label'] }}</span>
                                        @endif
                                    @endforeach
                                </div>
                            </td>
                            <td class="price" title="{{ $row->stock_group ?? '族群' }}平均本益比 {{ $row->valuation_group_pe === null ? '—' : number_format($row->valuation_group_pe, 1) . ' 倍' }} × 2027E EPS {{ number_format($row->eps_2027, 2) }}；相對當期收盤 {{ $row->close_price === null ? '—' : number_format($row->close_price, 2) }}">
                                <div class="price-value expected-price-value">
                                    <span>{{ $row->expected_price_2027 === null ? '—' : number_format($row->expected_price_2027, $row->expected_price_2027 < 100 ? 2 : ($row->expected_price_2027 < 1000 ? 1 : 0)) }}</span>
                                    @if ($row->expected_price_2027_return_percentage !== null)
                                        @php($isPotentialProfit = $row->expected_price_2027_return_percentage >= 0)
                                        <span class="potential-return {{ $isPotentialProfit ? 'positive' : 'negative' }}">（潛在{{ $isPotentialProfit ? '獲利' : '虧損' }} {{ $isPotentialProfit ? '+' : '' }}{{ number_format($row->expected_price_2027_return_percentage, 1) }}%）</span>
                                    @else
                                        <span class="potential-return stock-meta">（潛在報酬 —）</span>
                                    @endif
                                </div>
                                @if ($row->valuation_group_pe !== null)
                                    <div class="stock-meta">族群 {{ number_format($row->valuation_group_pe, 1) }}x</div>
                                @endif
                            </td>
                            <td>{{ number_format($row->eps_2025, 2) }}</td>
                            <td>
                                <div>{{ number_format($row->eps_2026, 2) }}</div>
                                @if ($epsBasis === 'actual')
                                    <div class="stock-meta">（H1 {{ number_format($row->reported_half_year_eps, 2) }} × 2）</div>
                                @else
                                    <div class="stock-meta">（目前 Q1+Q2：{{ $row->reported_half_year_eps === null ? '—' : number_format($row->reported_half_year_eps, 2) }}）</div>
                                @endif
                            </td>
                            <td>{{ number_format($row->eps_2027, 2) }}</td>
                            <td>{{ number_format($row->eps_2028, 2) }}</td>
                            @foreach ([$row->growth_2025_2026, $row->growth_2026_2027, $row->growth_2027_2028] as $growth)
                                <td class="{{ $growth >= 0 ? 'positive' : 'negative' }}">{{ $growth >= 0 ? '+' : '' }}{{ number_format($growth, 1) }}%</td>
                            @endforeach
                            <td><span class="weighted-score">{{ number_format($row->weighted_score, 2) }} <small>/ 100</small></span></td>
                            <td><span class="sum-score">{{ number_format($row->growth_sum, 1) }}%</span></td>
                            <td title="FactSet 營收中位數年增預估">
                                @if ($revenueGrowth2627 !== null && $revenueGrowth2728 !== null)
                                    <span class="{{ $revenueGrowth2627 >= 0 ? 'positive' : 'negative' }}">27E {{ $revenueGrowth2627 >= 0 ? '+' : '' }}{{ number_format($revenueGrowth2627, 1) }}%</span>
                                    <span class="stock-meta">28E {{ $revenueGrowth2728 >= 0 ? '+' : '' }}{{ number_format($revenueGrowth2728, 1) }}%</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td>{{ $row->analyst_count ?? '—' }}</td>
                            <td>{{ $row->forecast_date?->format('m/d') ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <aside class="method-note glass">
            <div>
                <strong>計算方式：</strong>
                先依
                <span class="formula">(25→26年增率×1.8 + 26→27年增率×2.5 + 27→28年增率×1) ÷ 5.3</span>
                算出原始加權成長率，再將該結果換算成當週完整樣本中的 0～100 百分位分數並排序。這可確保權重直接作用於原始成長率，不會因三段各自先轉百分位而扭曲。預設「預估2026」使用 FactSet 2026E；切換「實際2026」時以已公告的 H1 EPS × 2 取代 2026E，重新計算 25→26、26→27、加權分數及排行，27→28 仍沿用原預估。缺少完整 H1 或年化 EPS 不為正數的股票不納入實際模式排行。2025A 為四季 EPS 加總；2027E～2028E 與營收成長展望為各股票最新可取得的 FactSet 中位數。排行是相對分數，不等於投資品質。
                2027預期價格以<span class="formula">該股票族群平均本益比 × 2027E EPS</span>計算；旁邊的潛在獲利／虧損為<span class="formula">（2027預期價格 ÷ 當期收盤價 − 1）× 100%</span>，僅供估值參考。
                全新（2455）與聯亞（3081）若 FactSet 尚缺 2028E，會以最新 2026E、2027E 共識為基礎，將 2026→2027 成長率折半（限制於 0～30%）作為中性 2028E 年增率，並以「中性估算」標示。
                兩檔為固定參考列；即使實際名次低於第 50 名，仍會顯示在前 50 名表格後方。
            </div>
            <div>資料每週一更新</div>
        </aside>
    @else
        <section class="empty-state glass">
            <h2>尚未建立 EPS 成長排行</h2>
            <p>請先執行 <code>php artisan tw-stock:refresh-eps-growth-rankings</code>。</p>
        </section>
    @endif
</main>

@if ($run !== null)
<script>
(() => {
    const search = document.getElementById('rankingSearch');
    const positiveOnly = document.getElementById('positiveOnly');
    const excludeLowBase = document.getElementById('excludeLowBase');
    const count = document.getElementById('visibleCount');
    const rows = Array.from(document.querySelectorAll('#rankingRows tr'));

    const applyFilters = () => {
        const query = search.value.trim().toLocaleLowerCase('zh-Hant');
        let visible = 0;
        rows.forEach((row) => {
            const matchesSearch = query === '' || row.dataset.search.includes(query);
            const matchesPositive = !positiveOnly.checked || row.dataset.allPositive === '1';
            const matchesBase = !excludeLowBase.checked || row.dataset.lowBase !== '1';
            const show = matchesSearch && matchesPositive && matchesBase;
            row.hidden = !show;
            if (show) visible += 1;
        });
        count.textContent = `顯示 ${visible} / ${rows.length}`;
    };

    [search, positiveOnly, excludeLowBase].forEach((control) => control.addEventListener('input', applyFilters));
})();
</script>
@endif
</body>
</html>
