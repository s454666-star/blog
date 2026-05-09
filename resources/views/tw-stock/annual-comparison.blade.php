<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台股年度營收 EPS 比較</title>
    <style>
        :root {
            --bg: #eef3f8;
            --ink: #172033;
            --muted: #627084;
            --line: #d8e1ee;
            --panel: rgba(255, 255, 255, 0.92);
            --blue: #2563eb;
            --teal: #0f766e;
            --rose: #e11d48;
            --amber: #b45309;
            --green: #047857;
            --shadow: 0 18px 44px rgba(23, 32, 51, 0.10);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--ink);
            background:
                linear-gradient(120deg, rgba(37, 99, 235, 0.10), transparent 34%),
                linear-gradient(250deg, rgba(15, 118, 110, 0.10), transparent 38%),
                linear-gradient(180deg, #fbfdff 0%, var(--bg) 100%);
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        body::before {
            position: fixed;
            inset: 0;
            z-index: -1;
            content: "";
            background-image:
                linear-gradient(rgba(23, 32, 51, 0.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(23, 32, 51, 0.055) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.82), rgba(0, 0, 0, 0.12));
        }

        .shell {
            width: min(1840px, calc(100vw - 20px));
            margin: 0 auto;
            padding: 24px 0 42px;
        }

        .topbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: end;
            margin-bottom: 14px;
        }

        h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.2;
        }

        .meta {
            margin-top: 7px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .nav a,
        .sort-link {
            display: inline-flex;
            min-height: 38px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 14px;
            color: #334155;
            background: rgba(255, 255, 255, 0.88);
            font-size: 13px;
            font-weight: 900;
            text-decoration: none;
            box-shadow: 0 10px 24px rgba(23, 32, 51, 0.07);
            transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
        }

        .nav a:hover,
        .sort-link:hover {
            transform: translateY(-1px);
            border-color: #9fb1c8;
            box-shadow: 0 14px 28px rgba(23, 32, 51, 0.11);
        }

        .nav a.active,
        .sort-link.active {
            border-color: #172033;
            color: #fff;
            background: #172033;
        }

        .control-panel {
            display: grid;
            grid-template-columns: minmax(260px, 1fr) auto;
            gap: 14px;
            align-items: stretch;
            margin-bottom: 14px;
            border: 1px solid rgba(216, 225, 238, 0.96);
            border-radius: 8px;
            padding: 14px;
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        .search-row,
        .sort-row,
        .filter-row {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .search-row {
            margin-bottom: 10px;
        }

        input[type="search"] {
            width: min(360px, 78vw);
            min-height: 40px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px;
            color: var(--ink);
            background: #fff;
            font: inherit;
            font-size: 14px;
            outline: none;
        }

        select {
            min-height: 40px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px;
            color: var(--ink);
            background: #fff;
            font: inherit;
            font-size: 14px;
            font-weight: 850;
            outline: none;
        }

        input[type="search"]:focus,
        select:focus {
            border-color: var(--blue);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(8, minmax(88px, 1fr));
            gap: 8px;
            min-width: 720px;
        }

        .stat {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            background: #fff;
        }

        .stat::after {
            position: absolute;
            inset: auto 10px 0 10px;
            height: 3px;
            content: "";
            border-radius: 99px 99px 0 0;
            background: linear-gradient(90deg, var(--blue), var(--teal), var(--amber));
        }

        .stat .label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .stat .value {
            margin-top: 8px;
            font-size: 24px;
            line-height: 1;
            font-weight: 950;
            font-variant-numeric: tabular-nums;
        }

        .check {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            min-height: 40px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px 0 10px;
            color: #263446;
            background: #fff;
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 8px 20px rgba(23, 32, 51, 0.06);
            transition: transform 160ms ease, border-color 160ms ease, box-shadow 160ms ease;
        }

        .check:hover {
            transform: translateY(-1px);
            border-color: #9fb1c8;
            box-shadow: 0 12px 24px rgba(23, 32, 51, 0.10);
        }

        .check input {
            width: 18px;
            height: 18px;
            accent-color: var(--blue);
        }

        .stock-list {
            display: grid;
            gap: 12px;
        }

        .stock-card {
            overflow: hidden;
            border: 1px solid rgba(216, 225, 238, 0.98);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.94);
            box-shadow: var(--shadow);
            transform: translateY(0);
            animation: enter 360ms ease both;
        }

        .stock-card:hover {
            border-color: #9fb1c8;
            box-shadow: 0 22px 54px rgba(23, 32, 51, 0.14);
        }

        @keyframes enter {
            from {
                opacity: 0;
                transform: translateY(8px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .stock-head {
            display: grid;
            grid-template-columns: minmax(240px, 1fr) minmax(360px, 1.6fr);
            gap: 12px;
            align-items: stretch;
            padding: 14px;
            border-bottom: 1px solid var(--line);
            background:
                linear-gradient(90deg, rgba(37, 99, 235, 0.08), transparent 46%),
                linear-gradient(180deg, rgba(248, 250, 252, 0.94), rgba(255, 255, 255, 0.88));
        }

        .identity {
            display: grid;
            align-content: center;
            gap: 8px;
        }

        .stock-title {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .code,
        .name {
            border: 0;
            padding: 0;
            color: var(--ink);
            background: transparent;
            cursor: pointer;
            font: inherit;
            font-weight: 950;
        }

        .code {
            font-size: 22px;
        }

        .name {
            font-size: 18px;
        }

        .badge {
            display: inline-flex;
            min-height: 26px;
            align-items: center;
            border-radius: 999px;
            padding: 0 10px;
            color: #1f2937;
            background: #eef2ff;
            font-size: 12px;
            font-weight: 900;
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(118px, 1fr));
            gap: 8px;
        }

        .metric {
            position: relative;
            overflow: hidden;
            min-height: 70px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px;
            background: #fff;
        }

        .metric .label {
            color: var(--muted);
            font-size: 11px;
            font-weight: 850;
        }

        .metric .value {
            margin-top: 7px;
            font-size: 19px;
            font-weight: 950;
            font-variant-numeric: tabular-nums;
        }

        .metric .bar {
            position: absolute;
            inset: auto 8px 7px 8px;
            height: 3px;
            border-radius: 99px;
            background: linear-gradient(90deg, var(--blue), var(--teal));
            transform-origin: left;
            animation: grow 620ms ease both;
        }

        @keyframes grow {
            from { transform: scaleX(0.18); }
            to { transform: scaleX(1); }
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1120px;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 13px;
            font-variant-numeric: tabular-nums;
        }

        th,
        td {
            padding: 10px 8px;
            border-bottom: 1px solid #e7edf5;
            text-align: right;
            white-space: nowrap;
        }

        th {
            color: #42526a;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 950;
        }

        td:first-child,
        th:first-child {
            text-align: left;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        tbody tr:hover td {
            background: #fbfdff;
        }

        .copyable {
            cursor: pointer;
            transition: color 140ms ease, transform 140ms ease;
        }

        .copyable:hover {
            color: var(--blue);
        }

        .pos {
            color: var(--rose);
            font-weight: 950;
        }

        .neg {
            color: var(--green);
            font-weight: 950;
        }

        .muted {
            color: var(--muted);
        }

        .pass {
            color: #fff;
            background: #166534;
        }

        .hot {
            color: #fff;
            background: linear-gradient(135deg, #e11d48, #f59e0b);
        }

        .fail {
            color: #475569;
            background: #f1f5f9;
        }

        .empty {
            border: 1px dashed #b9c7d9;
            border-radius: 8px;
            padding: 36px 16px;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.82);
            text-align: center;
            font-weight: 900;
        }

        .pager {
            display: flex;
            justify-content: center;
            margin-top: 18px;
        }

        .copy-tooltip {
            position: fixed;
            z-index: 50;
            pointer-events: none;
            transform: translate(-50%, -120%);
            border: 1px solid rgba(23, 32, 51, 0.14);
            border-radius: 8px;
            padding: 7px 10px;
            color: #fff;
            background: rgba(23, 32, 51, 0.94);
            font-size: 12px;
            font-weight: 900;
            opacity: 0;
            transition: opacity 140ms ease;
            box-shadow: 0 12px 28px rgba(23, 32, 51, 0.18);
        }

        .copy-tooltip.show {
            opacity: 1;
        }

        @media (max-width: 980px) {
            .topbar,
            .control-panel,
            .stock-head {
                grid-template-columns: 1fr;
            }

            .nav {
                justify-content: flex-start;
            }

            .summary,
            .metrics {
                min-width: 0;
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .shell {
                width: min(100vw - 12px, 1840px);
                padding-top: 14px;
            }
        }
@include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
@php
    $fmt = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals);
    $pct = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals) . '%';
    $tone = fn ($value): string => $value === null ? 'muted' : ((float) $value >= 0 ? 'pos' : 'neg');
    $passes = fn ($value, float $threshold): bool => $value !== null && (float) $value > $threshold;
    $filterChecked = fn (string $filter): bool => in_array($filter, $filters, true);
    $sortUrl = fn (string $target): string => request()->fullUrlWithQuery(['sort' => $target, 'page' => null]);
@endphp

<div class="shell">
    <header class="topbar">
        <div>
            <h1>台股年度營收 EPS 比較</h1>
            <div class="meta">2020-2025 年度比較，每檔股票 5 列；2026 Q1 與 1-4 月營收列為即時摘要。</div>
        </div>
        <nav class="nav" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a class="active" href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
        </nav>
    </header>

    <form class="control-panel" method="GET" action="{{ route('tw-stock.annual-comparison.index') }}" data-filter-form>
        <div>
            <div class="search-row">
                <input type="search" name="q" value="{{ $search }}" placeholder="搜尋股票代號或名稱" data-auto-submit>
                <a class="sort-link {{ $sort === 'revenue' ? 'active' : '' }}" href="{{ $sortUrl('revenue') }}">營收加權排序</a>
                <a class="sort-link {{ $sort === 'eps' ? 'active' : '' }}" href="{{ $sortUrl('eps') }}">EPS 加權排序</a>
                <input type="hidden" name="sort" value="{{ $sort }}">
                <select name="per_page" data-auto-submit aria-label="每頁筆數">
                    @foreach ($allowedPerPage as $option)
                        <option value="{{ $option }}" {{ $perPage === $option ? 'selected' : '' }}>每頁 {{ $option }} 檔</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-row">
                <label class="check">
                    <input type="checkbox" name="revenue_growth" value="1" {{ $filterChecked('revenue_growth') ? 'checked' : '' }} data-auto-submit>
                    營收 5 年 YoY 加權 &gt; 60%
                </label>
                <label class="check">
                    <input type="checkbox" name="eps_growth" value="1" {{ $filterChecked('eps_growth') ? 'checked' : '' }} data-auto-submit>
                    EPS 5 年 YoY 加權 &gt; 38%
                </label>
                <label class="check">
                    <input type="checkbox" name="current_q1_eps_yoy" value="1" {{ $filterChecked('current_q1_eps_yoy') ? 'checked' : '' }} data-auto-submit>
                    2026 Q1 EPS YoY &gt; 5%
                </label>
                <label class="check">
                    <input type="checkbox" name="end_year_revenue_yoy" value="1" {{ $filterChecked('end_year_revenue_yoy') ? 'checked' : '' }} data-auto-submit>
                    2025 年營收 YoY &gt; 15%
                </label>
                <label class="check">
                    <input type="checkbox" name="current_q1_revenue_yoy" value="1" {{ $filterChecked('current_q1_revenue_yoy') ? 'checked' : '' }} data-auto-submit>
                    2026 Q1 營收 YoY &gt; 8%
                </label>
                <label class="check">
                    <input type="checkbox" name="recent_two_month_high" value="1" {{ $filterChecked('recent_two_month_high') ? 'checked' : '' }} data-auto-submit>
                    近 3 日創兩月新高
                </label>
                <label class="check">
                    <input type="checkbox" name="net_margin" value="1" {{ $filterChecked('net_margin') ? 'checked' : '' }} data-auto-submit>
                    淨利率近 8 季或近 2 年平均 &gt; 15%
                </label>
            </div>
        </div>
        <section class="summary" aria-label="篩選摘要">
            <div class="stat">
                <div class="label">符合股票</div>
                <div class="value">{{ number_format($summary['total']) }}</div>
            </div>
            <div class="stat">
                <div class="label">營收條件</div>
                <div class="value">{{ number_format($summary['revenuePass']) }}</div>
            </div>
            <div class="stat">
                <div class="label">EPS 條件</div>
                <div class="value">{{ number_format($summary['epsPass']) }}</div>
            </div>
            <div class="stat">
                <div class="label">Q1 EPS YoY</div>
                <div class="value">{{ number_format($summary['currentQ1EpsYoyPass']) }}</div>
            </div>
            <div class="stat">
                <div class="label">2025 營收 YoY</div>
                <div class="value">{{ number_format($summary['endYearRevenueYoyPass']) }}</div>
            </div>
            <div class="stat">
                <div class="label">Q1 營收 YoY</div>
                <div class="value">{{ number_format($summary['currentQ1RevenueYoyPass']) }}</div>
            </div>
            <div class="stat">
                <div class="label">近 3 日新高</div>
                <div class="value">{{ number_format($summary['recentTwoMonthHighPass']) }}</div>
            </div>
            <div class="stat">
                <div class="label">淨利率條件</div>
                <div class="value">{{ number_format($summary['netMarginPass']) }}</div>
            </div>
        </section>
    </form>

    @if ($stocks->isEmpty())
        <div class="empty">目前沒有符合條件的股票。</div>
    @else
        <main class="stock-list">
            @foreach ($stocks as $stock)
                @php
                    $recentHigh = isset($recentTwoMonthHighKeys[$stock['exchange'] . '|' . $stock['stock_code']]);
                @endphp
                <article class="stock-card" style="animation-delay: {{ min($loop->index * 28, 360) }}ms">
                    <header class="stock-head">
                        <div class="identity">
                            <div class="stock-title">
                                <button class="code copyable" type="button" data-copy-value="{{ $stock['stock_code'] }}">{{ $stock['stock_code'] }}</button>
                                <button class="name copyable" type="button" data-copy-value="{{ $stock['stock_name'] }}">{{ $stock['stock_name'] }}</button>
                                <span class="badge">{{ $stock['exchange'] }}</span>
                                <span class="badge {{ $stock['revenue_filter_pass'] ? 'pass' : 'fail' }}">營收 {{ $stock['revenue_filter_pass'] ? 'PASS' : 'WAIT' }}</span>
                                <span class="badge {{ $stock['eps_filter_pass'] ? 'pass' : 'fail' }}">EPS {{ $stock['eps_filter_pass'] ? 'PASS' : 'WAIT' }}</span>
                                @if ($passes($stock['current_q1_eps_yoy_percent'], 5))
                                    <span class="badge pass">Q1 EPS YoY PASS</span>
                                @endif
                                @if ($passes($stock['end_year_revenue_yoy_percent'], 15))
                                    <span class="badge pass">2025 營收 PASS</span>
                                @endif
                                @if ($passes($stock['current_q1_revenue_yoy_percent'], 8))
                                    <span class="badge pass">Q1 營收 YoY PASS</span>
                                @endif
                                @if ($recentHigh)
                                    <span class="badge hot">近 3 日新高</span>
                                @endif
                                <span class="badge {{ $stock['net_margin_filter_pass'] ? 'pass' : 'fail' }}">淨利率 {{ $stock['net_margin_filter_pass'] ? 'PASS' : 'WAIT' }}</span>
                            </div>
                            <div class="meta">
                                股價 {{ $fmt($stock['latest_close_price']) }}，成交量 {{ $stock['volume_lots'] === null ? '--' : number_format((int) $stock['volume_lots']) }} 張
                            </div>
                        </div>
                        <div class="metrics">
                            <div class="metric">
                                <div class="label">營收 YoY 加權</div>
                                <div class="value {{ $tone($stock['revenue_yoy_sum']) }}">{{ $pct($stock['revenue_yoy_sum']) }}</div>
                                <div class="bar"></div>
                            </div>
                            <div class="metric">
                                <div class="label">EPS YoY 加權</div>
                                <div class="value {{ $tone($stock['eps_yoy_sum']) }}">{{ $pct($stock['eps_yoy_sum']) }}</div>
                                <div class="bar"></div>
                            </div>
                            <div class="metric">
                                <div class="label">近 8 季淨利率</div>
                                <div class="value {{ $tone($stock['recent_net_margin_average']) }}">{{ $pct($stock['recent_net_margin_average']) }}</div>
                                <div class="bar"></div>
                            </div>
                            <div class="metric">
                                <div class="label">2026 1-4 月營收</div>
                                <div class="value">{{ $fmt($stock['current_revenue_billion']) }}</div>
                                <div class="bar"></div>
                            </div>
                            <div class="metric">
                                <div class="label">2026 Q1 EPS</div>
                                <div class="value">{{ $fmt($stock['current_eps']) }}</div>
                                <div class="bar"></div>
                            </div>
                            <div class="metric">
                                <div class="label">2026 Q1 EPS YoY</div>
                                <div class="value {{ $tone($stock['current_q1_eps_yoy_percent']) }}">{{ $pct($stock['current_q1_eps_yoy_percent']) }}</div>
                                <div class="bar"></div>
                            </div>
                            <div class="metric">
                                <div class="label">2025 營收 YoY</div>
                                <div class="value {{ $tone($stock['end_year_revenue_yoy_percent']) }}">{{ $pct($stock['end_year_revenue_yoy_percent']) }}</div>
                                <div class="bar"></div>
                            </div>
                            <div class="metric">
                                <div class="label">2026 Q1 營收 YoY</div>
                                <div class="value {{ $tone($stock['current_q1_revenue_yoy_percent']) }}">{{ $pct($stock['current_q1_revenue_yoy_percent']) }}</div>
                                <div class="bar"></div>
                            </div>
                        </div>
                    </header>
                    <div class="table-wrap">
                        <table>
                            <thead>
                            <tr>
                                <th>年度比較</th>
                                <th>前一年營收(億)</th>
                                <th>當年營收(億)</th>
                                <th>營收 YoY</th>
                                <th>前一年 EPS</th>
                                <th>當年 EPS</th>
                                <th>EPS YoY</th>
                                <th>毛利率</th>
                                <th>營益率</th>
                                <th>淨利率</th>
                                <th>季數</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach ($stock['comparisons'] as $comparison)
                                <tr>
                                    <td>
                                        <span class="copyable" data-copy-value="{{ $stock['stock_code'] }} {{ $comparison['previous_year'] }}-{{ $comparison['year'] }}">
                                            {{ $comparison['previous_year'] }} → {{ $comparison['year'] }}
                                        </span>
                                    </td>
                                    <td class="copyable" data-copy-value="{{ $fmt($comparison['previous_revenue_billion']) }}">{{ $fmt($comparison['previous_revenue_billion']) }}</td>
                                    <td class="copyable" data-copy-value="{{ $fmt($comparison['revenue_billion']) }}">{{ $fmt($comparison['revenue_billion']) }}</td>
                                    <td class="{{ $tone($comparison['revenue_yoy_percent']) }} copyable" data-copy-value="{{ $pct($comparison['revenue_yoy_percent']) }}">{{ $pct($comparison['revenue_yoy_percent']) }}</td>
                                    <td class="copyable" data-copy-value="{{ $fmt($comparison['previous_eps']) }}">{{ $fmt($comparison['previous_eps']) }}</td>
                                    <td class="copyable" data-copy-value="{{ $fmt($comparison['eps']) }}">{{ $fmt($comparison['eps']) }}</td>
                                    <td class="{{ $tone($comparison['eps_yoy_percent']) }} copyable" data-copy-value="{{ $pct($comparison['eps_yoy_percent']) }}">{{ $pct($comparison['eps_yoy_percent']) }}</td>
                                    <td class="copyable" data-copy-value="{{ $pct($comparison['gross_margin_percent']) }}">{{ $pct($comparison['gross_margin_percent']) }}</td>
                                    <td class="copyable" data-copy-value="{{ $pct($comparison['operating_margin_percent']) }}">{{ $pct($comparison['operating_margin_percent']) }}</td>
                                    <td class="copyable" data-copy-value="{{ $pct($comparison['net_margin_percent']) }}">{{ $pct($comparison['net_margin_percent']) }}</td>
                                    <td>{{ $comparison['quarters'] }}/4</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </article>
            @endforeach
        </main>

        <div class="pager">
            {{ $stocks->links('tw-stock.partials.pagination') }}
        </div>
    @endif
</div>

<div class="copy-tooltip" data-copy-tooltip>點一下複製</div>

<script>
    const form = document.querySelector('[data-filter-form]');
    let timer = null;

    document.querySelectorAll('[data-auto-submit]').forEach((input) => {
        input.addEventListener(input.type === 'search' ? 'input' : 'change', () => {
            clearTimeout(timer);
            timer = setTimeout(() => form.submit(), input.type === 'search' ? 360 : 0);
        });
    });

    const tooltip = document.querySelector('[data-copy-tooltip]');
    const showTooltip = (target, text) => {
        const rect = target.getBoundingClientRect();
        tooltip.textContent = text;
        tooltip.style.left = `${rect.left + rect.width / 2}px`;
        tooltip.style.top = `${rect.top - 8}px`;
        tooltip.classList.add('show');
        clearTimeout(tooltip.hideTimer);
        tooltip.hideTimer = setTimeout(() => tooltip.classList.remove('show'), 900);
    };

    document.querySelectorAll('[data-copy-value]').forEach((item) => {
        item.addEventListener('mouseenter', () => showTooltip(item, '點一下複製'));
        item.addEventListener('mouseleave', () => tooltip.classList.remove('show'));
        item.addEventListener('click', async () => {
            const value = item.dataset.copyValue || item.textContent.trim();
            try {
                await navigator.clipboard.writeText(value);
                showTooltip(item, '已複製');
            } catch (error) {
                showTooltip(item, '複製失敗');
            }
        });
    });
</script>
</body>
</html>
