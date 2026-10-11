<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台股三年 EPS 成長排行</title>
    <style>
        :root {
            --bg: #f2f7ff;
            --panel: rgba(255, 255, 255, 0.93);
            --panel-strong: #ffffff;
            --line: #dbe8f8;
            --line-hot: #8db8ec;
            --text: #0f2747;
            --muted: #5b6f8c;
            --cyan: #0098d6;
            --blue: #0080ff;
            --violet: #6b4fe0;
            --gold: #b7791f;
            --up: #e5364b;
            --down: #0f9960;
            --flat: #7b8ea8;
            --shadow: 0 16px 44px rgba(18, 74, 160, 0.10);
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            font-family: "Segoe UI", "Microsoft JhengHei", "Noto Sans TC", sans-serif;
            background: var(--bg);
            overflow-x: hidden;
        }

        @keyframes rise {
            from { opacity: 0; transform: translateY(14px); }
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
            background: var(--panel);
            box-shadow: var(--shadow);
        }

        .hero {
            position: relative;
            animation: rise 0.6s ease both;
        }

        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 32px;
            padding: 6px 13px;
            border: 1px solid rgba(255, 255, 255, 0.32);
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .meta-pill strong { color: #fff; }

        .snapshot-picker {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 10px 12px 10px 16px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 16px;
            background: rgba(0, 40, 120, 0.16);
        }

        .snapshot-picker label {
            color: rgba(255, 255, 255, 0.9);
            font-size: 12px;
            font-weight: 750;
        }

        .snapshot-picker select {
            min-width: min(320px, 52vw);
            font-size: 13px;
            font-weight: 750;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin: 0 0 16px;
        }

        .summary-card {
            min-height: 120px;
            padding: 18px 20px;
            animation: rise 0.62s ease both;
        }

        .summary-card:nth-child(2) { animation-delay: 0.04s; }
        .summary-card:nth-child(3) { animation-delay: 0.08s; }
        .summary-card:nth-child(4) { animation-delay: 0.12s; }
        .summary-card:nth-child(5) { animation-delay: 0.16s; }
        .summary-card:nth-child(6) { animation-delay: 0.2s; }

        .summary-label {
            font-size: 12px;
            font-weight: 750;
            letter-spacing: 0.05em;
        }

        .summary-value {
            margin-top: 10px;
            font-size: clamp(1.5rem, 2.4vw, 2.2rem);
            font-weight: 900;
            line-height: 1.05;
        }

        .summary-note {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
        }

        .toolbar {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) auto auto auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 14px;
            padding: 12px;
        }

        .search-box {
            display: flex;
            align-items: center;
            gap: 9px;
            min-height: 44px;
            padding: 0 14px;
            border: 1px solid var(--line-hot);
            border-radius: 12px;
            color: var(--blue);
            background: #fff;
        }

        .search-box:focus-within { border-color: var(--blue); box-shadow: 0 0 0 4px rgba(0, 128, 255, 0.16); }

        .tw-stock-experience .search-box input {
            width: 100%;
            min-height: 0;
            padding: 0;
            border: 0;
            outline: 0;
            color: var(--text);
            background: transparent;
            box-shadow: none !important;
            font: inherit;
        }

        .eps-basis-picker {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .toggle {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 44px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 12px;
            color: var(--text);
            background: #fff;
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: border-color 0.16s ease, background-color 0.16s ease;
        }

        .toggle:hover { border-color: var(--line-hot); }
        .toggle:has(input:checked) { border-color: var(--blue); color: #0062d6; background: #e8f3ff; box-shadow: 0 0 0 1px rgba(0, 128, 255, 0.2); }

        .visible-count {
            min-width: 94px;
            color: var(--blue);
            text-align: right;
            font-size: 13px;
            font-weight: 800;
        }

        .table-panel {
            overflow: hidden;
            animation: rise 0.7s 0.1s ease both;
        }

        table {
            width: 100%;
            min-width: 1400px;
            border-collapse: separate;
            border-spacing: 0;
            font-variant-numeric: tabular-nums;
        }

        th {
            padding: 13px 11px;
            font-size: 13px;
            text-align: right;
            white-space: nowrap;
        }

        th:nth-child(1), th:nth-child(2), td:nth-child(1), td:nth-child(2) { text-align: left; }

        td {
            padding: 13px 11px;
            border-bottom: 1px solid #e2ecf8;
            color: var(--text);
            font-size: 13px;
            text-align: right;
            white-space: nowrap;
        }

        .rank-cell { font-size: 14px; font-weight: 900; }

        .rank-orb {
            display: inline-grid;
            place-items: center;
            width: 32px;
            height: 32px;
            border-radius: 10px;
            color: #0062d6;
            background: #e8f3ff;
        }

        tr.top-1 .rank-orb { color: #5b3a00; background: linear-gradient(135deg, #ffe58a, #f5b942); box-shadow: 0 6px 16px rgba(245, 185, 66, 0.4); }
        tr.top-2 .rank-orb { color: #233452; background: linear-gradient(135deg, #f3f6fb, #c3cfe2); }
        tr.top-3 .rank-orb { color: #4a250b; background: linear-gradient(135deg, #f7c9a8, #d58d5c); }

        .stock-name { color: var(--text); font-size: 14px; font-weight: 850; }
        .stock-group { color: #0080c8; font-size: 12px; font-weight: 700; }
        .stock-meta { margin-top: 3px; color: var(--muted); font-size: 11.5px; font-weight: 650; }
        .stock-meta a { color: var(--blue); text-decoration: none; }
        .stock-meta a:hover { text-decoration: underline; }

        .price { color: var(--text); font-weight: 900; text-align: center; }
        .price-value { font-size: 15px; }
        .expected-price-value {
            display: inline-flex;
            align-items: baseline;
            gap: 5px;
            white-space: nowrap;
        }
        .potential-return { font-size: 11px; }
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
            font-size: 11px;
            font-weight: 800;
            line-height: 1.2;
        }
        .moving-average-signal.above { color: var(--up); }
        .moving-average-signal.below { color: var(--down); }
        .moving-average-signal.unavailable { color: var(--flat); }
        .moving-average-arrow { font-size: 13px; line-height: 1; }
        .positive { color: var(--up); font-weight: 850; }
        .negative { color: var(--down); font-weight: 850; }

        .weighted-score {
            display: inline-flex;
            align-items: baseline;
            justify-content: center;
            gap: 4px;
            min-width: 92px;
            padding: 7px 11px;
            border-radius: 11px;
            color: #fff;
            background: linear-gradient(135deg, #0080ff, #2894ff 60%, #19c3ff);
            font-weight: 900;
            box-shadow: 0 6px 16px rgba(0, 128, 255, 0.25);
        }

        .weighted-score small { color: rgba(255, 255, 255, 0.78); font-size: 10px; }

        .rank-change {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 48px;
            min-height: 28px;
            padding: 4px 9px;
            border-radius: 999px;
            font-weight: 900;
        }

        .rank-change.up { color: #c81e36; background: #ffe8ec; }
        .rank-change.down { color: #0b7a4c; background: #e3f7ee; }
        .rank-change.flat { color: #5b6f8c; background: #eef2f8; }

        .low-base {
            display: inline-flex;
            margin-left: 5px;
            padding: 2px 7px;
            border-radius: 6px;
            color: #9a5b00;
            background: #fff3d6;
            font-size: 11px;
            font-weight: 800;
        }

        .neutral-estimate {
            display: inline-flex;
            margin-left: 5px;
            padding: 2px 8px;
            border-radius: 999px;
            color: #5a3fd0;
            background: #efeaff;
            font-size: 10.5px;
            font-weight: 850;
            vertical-align: middle;
        }

        .empty-state {
            padding: 70px 24px;
            border-radius: 22px;
            color: var(--muted);
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
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .formula { color: var(--blue); font-family: ui-monospace, SFMono-Regular, Consolas, monospace; font-weight: 800; }

        @media (max-width: 1100px) {
            .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 760px) {
            .page-shell { padding-top: 10px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .toolbar { grid-template-columns: 1fr; }
            .visible-count { text-align: left; }
            .method-note { grid-template-columns: 1fr; }
            .snapshot-picker { align-items: stretch; flex-direction: column; }
            .snapshot-picker select { width: 100%; min-width: 0; }
        }

        @include('tw-stock.partials.shared-shell-width')
    </style>
    @include('tw-stock.partials.experience-head')
</head>
<body class="tw-stock-experience">

<main class="page-shell">
    @php
        $hasAudit = !empty($run?->forecast_audit);
    @endphp
    <header class="hero">
        <div class="eyebrow">TW EQUITY GROWTH RADAR</div>
        <h1>台股三年 EPS 成長排行</h1>
        <p class="hero-copy">
            將 2025→2026、2026→2027、2027→2028 三段 EPS 年增率依 1.8：2.5：1 合成，
            再換算為滿分 100 的當週百分位分數，並同步追蹤最新收盤價與前次週排行。
        </p>

        <div class="hero-meta">
            <span class="meta-pill">快照 <strong>{{ $run?->snapshot_date?->format('Y/m/d') ?? '尚未建立' }}</strong></span>
            <span class="meta-pill">{{ $usesLatestPrices ? '最新股價日' : '快照股價最晚日' }} <strong>{{ $displayPriceDate ? \Illuminate\Support\Carbon::parse($displayPriceDate)->format('Y/m/d') : '—' }}</strong></span>
            <span class="meta-pill">比較前週 <strong>{{ $previousRun?->snapshot_date?->format('Y/m/d') ?? '首次快照' }}</strong></span>
            <span class="meta-pill">完整樣本 <strong>{{ number_format($run?->eligible_count ?? 0) }} 檔</strong></span>
        </div>

        @include('tw-stock.partials.nav', ['navClass' => 'nav-actions'])

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
                <div class="summary-label">{{ $hasAudit ? '逐年查核' : '中性情境' }}</div>
                <div class="summary-value" style="color: var(--violet)">{{ $hasAudit ? count($run->forecast_audit) : $summary['neutral_estimates'] }}</div>
                <div class="summary-note">{{ $hasAudit ? '含缺值與過期來源，詳見下方查核表' : '5 檔固定參考股的 2028E 中性估算' }}</div>
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
                    實際2026（H1＋H1×1.05）
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
                        <th>{{ $usesLatestPrices ? '最新收盤' : '當期收盤' }}</th>
                        <th>2027預期價格</th>
                        <th>2025A</th>
                        <th>{{ $epsBasis === 'actual' ? '2026實際推估' : '2026E' }}</th>
                        <th>2027E</th>
                        <th>2028E</th>
                        <th>25→26</th>
                        <th>26→27</th>
                        <th>27→28</th>
                        <th>加權分</th>
                        <th title="被同業取代、訂單轉移的結構性風險；靜態參考；0～100%">掉單風險</th>
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
                                    <span data-copy-value="{{ $row->stock_name }}">{{ $row->stock_name }}</span>@if($row->stock_group)<span class="stock-group">（{{ $row->stock_group }}）</span>@endif
                                    @if ($row->low_base)<span class="low-base">低基期</span>@endif
                                    @if ($row->is_neutral_estimate)<span class="neutral-estimate">中性估算</span>@endif
                                </div>
                                <div class="stock-meta">
                                    <span data-copy-value="{{ $row->stock_code }}">{{ $row->stock_code }}</span>
                                    @if ($row->news_id)
                                        · <a href="https://news.cnyes.com/news/id/{{ $row->news_id }}" target="_blank" rel="noopener">FactSet ↗</a>
                                    @elseif ($row->forecast_source_url)
                                        · <a href="{{ $row->forecast_source_url }}" target="_blank" rel="noopener">{{ $row->forecast_source_label ?? '預估來源' }} ↗</a>
                                    @endif
                                </div>
                            </td>
                            <td title="{{ $epsBasis === 'actual' ? '預估2026名次' : '前次名次' }}：{{ $row->previous_rank ?? '無' }}"><span class="rank-change {{ $changeClass }}">{{ $changeText }}</span></td>
                            <td class="price">
                                <div class="price-value">{{ $row->close_price === null ? '—' : number_format($row->close_price, $row->close_price < 100 ? 2 : ($row->close_price < 1000 ? 1 : 0)) }}</div>
                                <div class="stock-meta">{{ $row->price_date?->format('m/d') ?? '—' }}</div>
                                <div class="moving-average-signals">
                                    @foreach ($movingAverageSignals as $signal)
                                        @if ($row->close_price !== null && $signal['average'] !== null)
                                            @php
$isAbove = $row->close_price >= $signal['average'];
@endphp
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
                            <td class="price" title="{{ $row->stock_group ?? '族群' }}平均本益比 {{ $row->valuation_group_pe === null ? '—' : number_format($row->valuation_group_pe, 1) . ' 倍' }} × 2027E EPS {{ number_format($row->eps_2027, 2) }}；相對{{ $usesLatestPrices ? '最新' : '當期' }}收盤 {{ $row->close_price === null ? '—' : number_format($row->close_price, 2) }}">
                                <div class="price-value expected-price-value">
                                    <span>{{ $row->expected_price_2027 === null ? '—' : number_format($row->expected_price_2027, $row->expected_price_2027 < 100 ? 2 : ($row->expected_price_2027 < 1000 ? 1 : 0)) }}</span>
                                    @if ($row->expected_price_2027_return_percentage !== null)
                                        @php
                                            $isPotentialProfit = $row->expected_price_2027_return_percentage >= 0;
                                        @endphp
                                        <span class="potential-return {{ $isPotentialProfit ? 'positive' : 'negative' }}">（{{ $isPotentialProfit ? '+' : '' }}{{ number_format($row->expected_price_2027_return_percentage, 1) }}%）</span>
                                    @else
                                        <span class="potential-return stock-meta">（—）</span>
                                    @endif
                                </div>
                                @if ($row->valuation_group_pe !== null)
                                    <div class="stock-meta">族群 {{ number_format($row->valuation_group_pe, 1) }}x</div>
                                @endif
                            </td>
                            <td>{{ number_format($row->eps_2025, 2) }}@if($hasAudit)<div class="stock-meta">2025 年度實績</div>@endif</td>
                            <td>
                                <div>{{ number_format($row->eps_2026, 2) }}</div>
                                @if($epsBasis === 'forecast')
                                    @include('tw-stock.partials.eps-forecast-provenance', ['source' => $row->forecast_metadata[2026] ?? null])
                                @elseif($hasAudit)
                                    <div class="stock-meta">本站年化情境，非全年實績</div>
                                @endif
                                @if ($epsBasis === 'actual')
                                    <div class="stock-meta">（H1 {{ number_format($row->reported_half_year_eps, 2) }} ＋ H1×1.05）</div>
                                @else
                                    <div class="stock-meta">（目前 Q1+Q2：{{ $row->reported_half_year_eps === null ? '—' : number_format($row->reported_half_year_eps, 2) }}）</div>
                                @endif
                            </td>
                            <td>{{ number_format($row->eps_2027, 2) }}@include('tw-stock.partials.eps-forecast-provenance', ['source' => $row->forecast_metadata[2027] ?? null])</td>
                            <td>{{ number_format($row->eps_2028, 2) }}@include('tw-stock.partials.eps-forecast-provenance', ['source' => $row->forecast_metadata[2028] ?? null])</td>
                            @foreach ([$row->growth_2025_2026, $row->growth_2026_2027, $row->growth_2027_2028] as $growth)
                                <td class="{{ $growth >= 0 ? 'positive' : 'negative' }}">{{ $growth >= 0 ? '+' : '' }}{{ number_format($growth, 1) }}%</td>
                            @endforeach
                            <td><span class="weighted-score">{{ number_format($row->weighted_score, 2) }} <small>/ 100</small></span></td>
                            <td class="order-loss-risk" title="{{ $row->order_loss_risk_basis ?? '公開資料尚不足以判定替代風險' }}">{{ $row->order_loss_risk === null ? '未提供評級' : $row->order_loss_risk.'%' }}
                                @if ($row->order_loss_risk_source_url || $row->order_loss_risk_assessed_at)
                                    <div class="stock-meta">
                                        @if ($row->order_loss_risk_source_url)<a href="{{ $row->order_loss_risk_source_url }}" target="_blank" rel="noopener">依據 ↗</a>@endif
                                        {{ $row->order_loss_risk_assessed_at }}
                                    </div>
                                @endif
                            </td>
                            <td class="analyst-summary">
                                @if(!empty($row->forecast_metadata))
                                    @include('tw-stock.partials.eps-source-summary', ['metadata' => $row->forecast_metadata, 'column' => 'analysts'])
                                @else
                                    {{ $row->analyst_count === null ? '來源未披露人數' : $row->analyst_count.' 位' }}
                                @endif
                            </td>
                            <td class="forecast-date-summary">
                                @if(!empty($row->forecast_metadata))
                                    @include('tw-stock.partials.eps-source-summary', ['metadata' => $row->forecast_metadata, 'column' => 'date'])
                                @else
                                    {{ $row->forecast_date?->format('Y-m-d') ?? '來源未提供日期' }}
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        @if($hasAudit)
            <details class="glass" style="margin-top:16px;padding:18px;border-radius:18px" id="epsSourceAudit">
                <summary style="cursor:pointer">全部 {{ count($run->forecast_audit) }} 家逐年 EPS 查核（含未納入排名公司）</summary>
                <p>單位：新台幣元／股。查核快照 {{ $run->snapshot_date->format('Y-m-d') }}；各年度來源日另列。此表保留共識資料，不隨上方年化情境切換。</p>
                <div class="table-scroll">
                    <table style="min-width:900px">
                        <thead><tr><th>公司</th><th>2025A</th><th>2026E</th><th>2027E</th><th>2028E</th><th>完整排名</th></tr></thead>
                        <tbody>
                        @foreach(collect($run->forecast_audit)->sortBy('stock_code') as $audited)
                            <tr data-audit-code="{{ $audited['stock_code'] }}">
                                <td>{{ $audited['stock_code'] }} {{ $audited['stock_name'] }}</td>
                                <td title="{{ $audited['actual_source'] }}">{{ $audited['eps_2025'] === null ? '缺值' : number_format($audited['eps_2025'], 2) }}<div class="stock-meta">2025 年度實績</div></td>
                                @foreach([2026, 2027, 2028] as $year)
                                    @php
                                        $source = $audited['years'][$year];
                                    @endphp
                                    <td>{{ $source['value'] === null ? '缺值' : number_format($source['value'], 2) }}
                                        @include('tw-stock.partials.eps-forecast-provenance', ['source' => $source])
                                    </td>
                                @endforeach
                                <td>{{ $audited['rankable'] ? '符合完整樣本條件' : '未納入：缺值／過期／非正值' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif

        <aside class="method-note glass">
            <div>
                <strong>計算方式：</strong>
                先依
                <span class="formula">(25→26年增率×1.8 + 26→27年增率×2.5 + 27→28年增率×1) ÷ 5.3</span>
                算出原始加權成長率，再將該結果換算成當週完整樣本中的 0～100 百分位分數並排序。這可確保權重直接作用於原始成長率，不會因三段各自先轉百分位而扭曲。預設「預估2026」使用 FactSet 2026E；切換「實際2026」時以已公告的 <span class="formula">H1 EPS ＋ H1 EPS × 1.05</span> 取代 2026E，下半年以上半年的 1.05 倍估算，重新計算 25→26、26→27、加權分數及排行，27→28 仍沿用原預估。缺少完整 H1 或年化 EPS 不為正數的股票不納入實際模式排行。2025A 原則上為四季 EPS 加總；永擎因 FinMind 僅回傳上市後的三筆資料，採用 2025 年度公開 EPS 13.04 元。2027E～2028E 為各股票最新可取得的 FactSet 中位數或下述固定參考來源。排行是相對分數，不等於投資品質。
                2027預期價格以<span class="formula">該股票族群平均本益比 × 2027E EPS</span>計算；旁邊百分比為<span class="formula">（2027預期價格 ÷ 顯示收盤價 − 1）× 100%</span>。最新一期使用每日股價表的最新官方收盤，歷史週快照則保留該週收盤，僅供估值參考。
                @if($hasAudit)
                    各年度分別核對 FactSet 中位數與具日期的原始新聞；日期標示為調查資料日或新聞發布日，不代表本站查核日。相同數值轉載保留原始資料日；同日數值不同時優先採原始資料。樣本僅 1 位會特別標示。
                    缺少可信年度資料原則上不外推；經指定的補充參考股可列本站中性推估，另附方法、股數假設與不確定性，並非分析師共識。來源超過 90 天標示過期。僅 2025A 與三年預估皆為正值且來源未過期的公司納入完整排名，其餘留在查核表。符合完整條件的指定補充參考股即使名次超過 50，仍列於表格後方。2026「實際」模式仍為本站年化情境，並非全年實績。
                @else
                全新（2455）與聯亞（3081）若 FactSet 尚缺 2028E，會以最新 2026E、2027E 共識為基礎；大量（3167）使用 2026/6/11 富果研究員預估的 2026E 19.53 元、2027E 30.59 元；IET-KY（4971）因 FactSet 頁面未提供 EPS 共識，改用 2026/8/31 公開法人預估的 2026E 6.10 元、2027E 10.94 元；永擎（7711）使用 2026/4/23 元大投顧預估的 2026E 31.31 元、2027E 42.11 元。五檔的 2028E 均將 2026→2027 成長率折半（限制於 0～30%）作為中性年增率，並以「中性估算」標示。
                五檔為固定參考列；即使實際名次低於第 50 名，仍會顯示在前 50 名表格後方。
                @endif
                <br><strong>掉單風險：</strong>被同業取代、訂單轉移的結構性風險，以 0～100% 表示；越高代表越容易被取代。百分比綜合產品替代性、客戶集中度與 2026～2027 年需求（供不應求者調低）判斷，為人工估計而非機率預測。人工固定百分比（寫入資料庫），僅反映結構性替代風險，不代表近期訂單流失預測，不影響 EPS 排名；歷史週也顯示同一份固定參考。評級為人工質性判斷，來源支持產品與技術事實，各產品線及客戶可能不同。
                <br><strong>分析師與預估日期：</strong>逐年列出原預估來源的人數與日期；新聞只披露特定年度的調查人數時，其他年度註明未分列，括號保留已披露年度及人數。研究中性參考與本站模型不列為分析師共識。實際2026模式的2026 EPS採H1年化，這兩欄仍保留原預估來源資料。
            </div>
            <div>EPS 排行每週一更新<br>掉單風險為固定參考，不隨週更重評</div>
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
