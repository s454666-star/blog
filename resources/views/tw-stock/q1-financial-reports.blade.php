<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $year }} Q1 財報評分排名</title>
    <style>
        :root {
            --bg: #f4f7fb;
            --panel: rgba(255, 255, 255, 0.92);
            --panel-strong: #ffffff;
            --line: #d7dfeb;
            --text: #111827;
            --muted: #64748b;
            --blue: #1d4ed8;
            --amber: #b45309;
            --red: #dc2626;
            --green: #047857;
            --dark: #172033;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(120deg, rgba(29, 78, 216, 0.08), transparent 36%),
                linear-gradient(240deg, rgba(180, 83, 9, 0.08), transparent 42%),
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
                linear-gradient(rgba(17, 24, 39, 0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(17, 24, 39, 0.045) 1px, transparent 1px);
            background-size: 36px 36px;
            mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.78), rgba(0, 0, 0, 0.10));
        }

        .shell {
            width: min(1960px, calc(100vw - 12px));
            margin: 0 auto;
            padding: 28px 0 42px;
        }

        .topbar {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 16px;
        }

        h1 {
            margin: 0;
            font-size: 30px;
            line-height: 1.2;
        }

        .meta {
            margin-top: 8px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.55;
        }

        .nav-actions,
        .filters {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        .nav-actions {
            justify-content: flex-end;
        }

        .nav-actions a,
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: #334155;
            background: rgba(255, 255, 255, 0.88);
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
            transition: transform 160ms ease, box-shadow 160ms ease, border-color 160ms ease;
        }

        .nav-actions a:hover,
        .btn:hover {
            transform: translateY(-1px);
            border-color: #a9b7ca;
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.10);
        }

        .btn.primary {
            border-color: #1f2937;
            color: #ffffff;
            background: #1f2937;
        }

        .nav-actions a.active {
            border-color: #1f2937;
            color: #ffffff;
            background: #1f2937;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .summary-card,
        .filter-panel,
        .table-panel {
            border: 1px solid rgba(215, 223, 235, 0.94);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 14px 34px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(10px);
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            min-height: 116px;
            padding: 16px;
        }

        .summary-card::after {
            position: absolute;
            inset: auto 14px 0 14px;
            height: 3px;
            content: "";
            border-radius: 999px 999px 0 0;
            background: linear-gradient(90deg, var(--blue), var(--amber), var(--red));
            opacity: 0.76;
        }

        .label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .value {
            margin-top: 10px;
            font-size: 28px;
            line-height: 1.1;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
        }

        .sub {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .filter-panel {
            margin-bottom: 14px;
            padding: 14px;
        }

        label {
            display: grid;
            gap: 5px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        input,
        select {
            min-height: 38px;
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 0 12px;
            color: var(--text);
            background: #ffffff;
            font: inherit;
            font-size: 14px;
            outline: none;
        }

        input:focus,
        select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.14);
        }

        input[type="search"] {
            width: min(320px, 72vw);
        }

        input[type="number"] {
            width: 120px;
        }

        .table-panel {
            overflow: visible;
        }

        .table-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px;
            border-bottom: 1px solid var(--line);
            background: rgba(248, 250, 252, 0.82);
        }

        .panel-title {
            margin: 0;
            font-size: 18px;
            line-height: 1.3;
        }

        .count-text {
            color: var(--muted);
            font-size: 13px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1780px;
            border-collapse: collapse;
            table-layout: fixed;
            font-size: 11px;
            font-variant-numeric: tabular-nums;
        }

        th,
        td {
            overflow: hidden;
            padding: 9px 4px;
            border-bottom: 1px solid #e7edf5;
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
            text-overflow: ellipsis;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 5;
            color: #475569;
            background: #f8fafc;
            box-shadow: 0 1px 0 #d7dfeb, 0 8px 18px rgba(15, 23, 42, 0.08);
            font-size: 10px;
            font-weight: 900;
            white-space: normal;
        }

        .sort-link {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 3px;
            min-height: 24px;
            color: inherit;
            text-decoration: none;
            transition: color 150ms ease, transform 150ms ease;
        }

        .sort-link:hover,
        .sort-link.active {
            color: #111827;
        }

        .sort-link:hover {
            transform: translateY(-1px);
        }

        .sort-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 12px;
            color: #94a3b8;
            font-size: 9px;
            line-height: 1;
        }

        .sort-link.active .sort-icon {
            color: #1d4ed8;
        }

        tbody tr {
            --group-color: var(--blue);
            --group-bg: rgba(29, 78, 216, 0.045);
            background: linear-gradient(90deg, var(--group-bg), transparent 28%);
            transition: background 160ms ease, box-shadow 160ms ease;
        }

        tbody tr:hover {
            background: #f8fbff;
            box-shadow: inset 3px 0 0 var(--group-color);
        }

        tbody tr.group-front {
            --group-color: #2563eb;
            --group-bg: rgba(37, 99, 235, 0.07);
        }

        tbody tr.group-middle {
            --group-color: #b45309;
            --group-bg: rgba(180, 83, 9, 0.07);
        }

        tbody tr.group-back {
            --group-color: #64748b;
            --group-bg: rgba(100, 116, 139, 0.07);
        }

        th:nth-child(1),
        td:nth-child(1) {
            width: 2.8%;
            text-align: center;
        }

        th:nth-child(1) .sort-link,
        th:nth-child(2) .sort-link {
            justify-content: center;
        }

        th:nth-child(2),
        td:nth-child(2) {
            width: 4%;
            text-align: center;
        }

        th:nth-child(3),
        td:nth-child(3) {
            width: 6.5%;
            text-align: left;
        }

        th:nth-child(3) .sort-link {
            justify-content: flex-start;
        }

        th:nth-child(4),
        td:nth-child(4) {
            width: 5.5%;
        }

        th:nth-child(15),
        td:nth-child(15) {
            width: 6.8%;
        }

        .copy-stock {
            position: relative;
            display: inline-flex;
            max-width: 100%;
            min-height: 22px;
            align-items: center;
            border: 0;
            padding: 0;
            color: inherit;
            background: transparent;
            font: inherit;
            line-height: 1.15;
            text-align: left;
            cursor: pointer;
            transition: color 140ms ease, transform 140ms ease;
            transform-origin: left center;
        }

        .copy-stock:hover,
        .copy-stock:focus-visible {
            color: #1d4ed8;
            transform: scale(1.18);
            outline: none;
        }

        .stock-code {
            color: #0f172a;
            font-size: 13px;
            font-weight: 900;
        }

        .stock-name {
            margin-top: 3px;
            color: var(--muted);
            font-size: 12px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .copy-tooltip {
            position: fixed;
            z-index: 9999;
            min-width: max-content;
            padding: 6px 8px;
            border-radius: 6px;
            color: #ffffff;
            background: #111827;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.22);
            font-size: 11px;
            font-weight: 900;
            line-height: 1;
            opacity: 0;
            pointer-events: none;
            transform: translate(-50%, -8px);
            transition: opacity 140ms ease, transform 140ms ease;
        }

        .copy-tooltip.visible {
            opacity: 1;
            transform: translate(-50%, -12px);
        }

        .rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            min-height: 28px;
            border-radius: 8px;
            color: #172033;
            background: #eef2f7;
            font-weight: 900;
        }

        .group-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 58px;
            min-height: 24px;
            border: 1px solid color-mix(in srgb, var(--group-color), white 60%);
            border-radius: 999px;
            color: var(--group-color);
            background: #ffffff;
            font-size: 11px;
            font-weight: 900;
        }

        .rank.top {
            color: #ffffff;
            background: linear-gradient(135deg, #1d4ed8, #172033);
        }

        .score-cell {
            display: grid;
            gap: 7px;
            justify-items: end;
        }

        .score-value {
            color: #0f172a;
            font-weight: 900;
        }

        .score-track {
            width: 58px;
            height: 7px;
            overflow: hidden;
            border-radius: 999px;
            background: #e2e8f0;
        }

        .score-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #047857, #b45309, #dc2626);
        }

        .positive {
            color: var(--red);
            font-weight: 800;
        }

        .negative {
            color: var(--green);
            font-weight: 800;
        }

        .neutral {
            color: var(--muted);
        }

        .price {
            color: #0f172a;
            font-weight: 900;
        }

        .expected-price-cell {
            display: grid;
            gap: 4px;
            justify-items: end;
        }

        .expected-price-main {
            display: grid;
            gap: 2px;
            justify-items: end;
        }

        .expected-diff {
            display: inline-flex;
            max-width: 100%;
            font-weight: 900;
            white-space: nowrap;
        }

        .valuation-group-note {
            max-width: 100%;
            color: var(--muted);
            font-size: 10px;
            font-weight: 800;
            line-height: 1.25;
            white-space: normal;
        }

        .monthly-cell {
            display: grid;
            gap: 4px;
            justify-items: end;
        }

        .monthly-metrics {
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            align-items: flex-end;
            gap: 3px;
            color: var(--muted);
            font-size: 10px;
            line-height: 1.2;
        }

        .metric-chip {
            display: inline-flex;
            align-items: center;
            min-height: 20px;
            padding: 0 4px;
            border-radius: 999px;
            background: #eef2f7;
            font-weight: 900;
        }

        .metric-chip.positive {
            background: rgba(220, 38, 38, 0.10);
        }

        .metric-chip.negative {
            background: rgba(4, 120, 87, 0.10);
        }

        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px 16px;
            border-top: 1px solid var(--line);
            background: rgba(248, 250, 252, 0.72);
        }

        .pagination nav {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .pagination a,
        .pagination span {
            color: #334155;
            font-size: 13px;
            text-decoration: none;
        }

        .pagination svg {
            width: 18px;
            height: 18px;
        }

        .empty {
            padding: 40px 16px;
            color: var(--muted);
            text-align: center;
        }

        @media (max-width: 1000px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .summary-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 640px) {
            .shell {
                width: min(100vw - 12px, 1960px);
                padding-top: 20px;
            }

            h1 {
                font-size: 24px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .table-head {
                align-items: flex-start;
                flex-direction: column;
            }
        }

        @media (max-width: 900px) {
            .filters,
            .nav-actions {
                width: 100%;
            }

            label,
            input[type="search"],
            input[type="number"],
            select,
            .btn {
                width: 100%;
            }

            .table-panel {
                border: 0;
                background: transparent;
                box-shadow: none;
                backdrop-filter: none;
            }

            .table-head,
            .pagination {
                border: 1px solid var(--line);
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.92);
            }

            .table-wrap {
                overflow: visible;
            }

            table,
            thead,
            tbody,
            tr,
            th,
            td {
                display: block;
                min-width: 0;
                width: 100%;
            }

            thead {
                display: none;
            }

            tbody {
                display: grid;
                gap: 12px;
                margin-top: 12px;
            }

            tbody tr {
                overflow: hidden;
                border: 1px solid var(--line);
                border-left: 5px solid var(--group-color);
                border-radius: 8px;
                background: rgba(255, 255, 255, 0.94);
                box-shadow: 0 12px 26px rgba(15, 23, 42, 0.08);
            }

            tbody tr:hover {
                box-shadow: 0 14px 30px rgba(15, 23, 42, 0.11);
            }

            td {
                display: grid;
                grid-template-columns: 112px minmax(0, 1fr);
                gap: 12px;
                align-items: center;
                min-height: 40px;
                padding: 10px 12px;
                text-align: right;
                white-space: normal;
            }

            td::before {
                content: attr(data-label);
                color: var(--muted);
                font-size: 12px;
                font-weight: 900;
                text-align: left;
            }

            td:nth-child(1),
            td:nth-child(2),
            td:nth-child(3),
            td:nth-child(4) {
                width: 100%;
                text-align: right;
            }

            .score-cell,
            .monthly-cell {
                justify-items: end;
            }

            .score-track {
                width: min(120px, 42vw);
            }
        }
@include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
@php
    $fmt = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals);
    $pctText = fn ($value): string => $value === null ? '--' : number_format((float) $value, 2, '.', '') . '%';
    $pctShort = fn ($value): string => $value === null ? '--' : number_format((float) $value, abs((float) $value) >= 100 ? 0 : 2, '.', '') . '%';
    $signedPct = fn ($value): string => $value === null ? '--' : ((float) $value >= 0 ? '+' : '') . number_format((float) $value, abs((float) $value) >= 100 ? 0 : 2, '.', '') . '%';
    $pctClass = fn ($value): string => $value === null ? 'neutral' : ((float) $value >= 0 ? 'positive' : 'negative');
    $scoreWidth = fn ($value): float => max(0, min(100, (float) ($value ?? 0)));
    $dateText = fn ($value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d') : '--';
    $dateTimeText = fn ($value): string => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d H:i') : '--';
    $priceValue = fn ($value): string => $value === null ? '' : rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    $defaultSortDirection = fn (string $key): string => in_array($key, ['rank', 'group', 'stock'], true) ? 'asc' : 'desc';
    $sortUrl = function (string $key) use ($sort, $direction, $defaultSortDirection): string {
        return route('tw-stock.q1-financial-reports.index', [
            ...request()->except('page'),
            'sort' => $key,
            'direction' => $sort === $key
                ? ($direction === 'asc' ? 'desc' : 'asc')
                : $defaultSortDirection($key),
        ]);
    };
    $sortNextDirection = fn (string $key): string => $sort === $key
        ? ($direction === 'asc' ? 'desc' : 'asc')
        : $defaultSortDirection($key);
    $sortTooltip = fn (string $key): string => '點一下排序：' . ($sortNextDirection($key) === 'desc' ? '高到低' : '低到高');
    $sortIcon = fn (string $key): string => $sort !== $key ? '↕' : ($direction === 'asc' ? '▲' : '▼');
    $sortAria = fn (string $key): string => $sort !== $key ? 'none' : ($direction === 'asc' ? 'ascending' : 'descending');
    $groupMeta = function (?int $rank, int $total): array {
        $rank = max(1, (int) ($rank ?? 1));
        $total = max(1, $total);
        $frontCutoff = (int) ceil($total / 3);
        $middleCutoff = (int) ceil($total * 2 / 3);

        if ($rank <= $frontCutoff) {
            return ['class' => 'front', 'label' => '前段班'];
        }

        if ($rank <= $middleCutoff) {
            return ['class' => 'middle', 'label' => '中段班'];
        }

        return ['class' => 'back', 'label' => '後段班'];
    };
@endphp

<main class="shell">
    <header class="topbar">
        <div>
            <h1>{{ $year }} Q1 財報評分排名</h1>
            <div class="meta">
                只列入已公布 Q1 財報且符合 1,000 張以上流動性門檻的普通股，排序依 EPS YoY、毛利率、營益率、淨利率加權評分由高到低。
            </div>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a class="active" href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
        </nav>
    </header>

    <section class="summary-grid" aria-label="Q1 財報摘要">
        <article class="summary-card">
            <div class="label">已入榜股票</div>
            <div class="value">{{ number_format($totalRows) }}</div>
            <div class="sub">流動性門檻：1,000 張</div>
        </article>
        <article class="summary-card">
            <div class="label">最高財報評分</div>
            <div class="value">{{ $fmt($topScoreRow?->q1_revenue_score, 2) }}</div>
            <div class="sub">{{ $topScoreRow ? $topScoreRow->stock_code . ' ' . $topScoreRow->stock_name : '--' }}</div>
        </article>
        <article class="summary-card">
            <div class="label">Q1 營收最大</div>
            <div class="value">{{ $fmt($topRevenueRow?->q1_revenue_billion, 2) }} 億</div>
            <div class="sub">{{ $topRevenueRow ? $topRevenueRow->stock_code . ' ' . $topRevenueRow->stock_name : '--' }}</div>
        </article>
        <article class="summary-card">
            <div class="label">營收年增最高</div>
            <div class="value">{{ $fmt($topGrowthRow?->q1_revenue_yoy_percent, 2) }}%</div>
            <div class="sub">更新：{{ $dateTimeText($lastFetchedAt) }}</div>
        </article>
    </section>

    <section class="filter-panel" aria-label="篩選">
        <form class="filters" method="get" action="{{ route('tw-stock.q1-financial-reports.index') }}" data-auto-submit-form>
            <input type="hidden" name="sort" value="{{ $sort }}">
            <input type="hidden" name="direction" value="{{ $direction }}">
            <label>
                年度
                <select name="year">
                    @forelse ($availableYears as $availableYear)
                        <option value="{{ $availableYear }}" @selected($availableYear === $year)>{{ $availableYear }}</option>
                    @empty
                        <option value="{{ $year }}">{{ $year }}</option>
                    @endforelse
                </select>
            </label>
            <label>
                搜尋
                <input type="search" name="q" value="{{ $search }}" placeholder="代號、名稱、產業" data-auto-submit-field>
            </label>
            <label>
                股價下限
                <input type="number" name="price_min" value="{{ $priceValue($priceMin) }}" min="0" step="0.01" placeholder="可不填" data-auto-submit-field>
            </label>
            <label>
                股價上限
                <input type="number" name="price_max" value="{{ $priceValue($priceMax) }}" min="0" step="0.01" placeholder="可不填" data-auto-submit-field>
            </label>
            <label>
                每頁
                <select name="per_page">
                    @foreach ($allowedPerPage as $option)
                        <option value="{{ $option }}" @selected($option === $perPage)>{{ $option }}</option>
                    @endforeach
                </select>
            </label>
        </form>
    </section>

    <section class="table-panel" aria-label="Q1 財報排名表">
        <div class="table-head">
            <h2 class="panel-title">公開資訊比較表</h2>
            <div class="count-text">
                價格日：{{ $dateText($latestPriceDate) }}，第 {{ number_format($rows->firstItem() ?? 0) }} - {{ number_format($rows->lastItem() ?? 0) }} 筆
            </div>
        </div>

        @if ($rows->isEmpty())
            <div class="empty">目前沒有符合條件的 Q1 財報資料。</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        @foreach ($sortableColumns as $key => $label)
                            <th aria-sort="{{ $sortAria($key) }}">
                                <a class="sort-link {{ $sort === $key ? 'active' : '' }}" href="{{ $sortUrl($key) }}" data-tooltip="{{ $sortTooltip($key) }}" title="{{ $sortTooltip($key) }}" aria-label="{{ $label }}，{{ $sortTooltip($key) }}">
                                    <span>{{ $label }}</span>
                                    <span class="sort-icon" aria-hidden="true">{{ $sortIcon($key) }}</span>
                                </a>
                            </th>
                        @endforeach
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($rows as $row)
                        @php
                            $monthlyRevenueRows = collect($row->recent_monthly_revenues ?? [])->take(4)->values();
                            $group = $groupMeta($row->rank, $groupTotalRows);
                            $expectedPrice = $row->expectedPrice();
                            $expectedPriceChangePercent = $row->expectedPriceChangePercent();
                            $reasonablePeRatio = $row->reasonablePeRatio();
                            $valuationGroup = $row->valuation_group;
                            $valuationGroupPe = $row->valuation_group_pe;
                        @endphp
                        <tr class="group-{{ $group['class'] }}">
                            <td data-label="排名"><span class="rank {{ $row->rank <= 10 ? 'top' : '' }}">{{ $row->rank }}</span></td>
                            <td data-label="分組"><span class="group-badge">{{ $group['label'] }}</span></td>
                            <td data-label="股票">
                                <div class="stock-code">
                                    <button class="copy-stock" type="button" data-copy-value="{{ $row->stock_code }}" data-tooltip="點一下複製代碼" title="點一下複製代碼" aria-label="複製股票代碼 {{ $row->stock_code }}">
                                        {{ $row->stock_code }}
                                    </button>
                                </div>
                                <div class="stock-name">
                                    <button class="copy-stock" type="button" data-copy-value="{{ $row->stock_name }}" data-tooltip="點一下複製名稱" title="點一下複製名稱" aria-label="複製股票名稱 {{ $row->stock_name }}">
                                        {{ $row->stock_name }}
                                    </button>
                                </div>
                            </td>
                            <td data-label="Q1整體財報評分">
                                <div class="score-cell">
                                    <span class="score-value">{{ $fmt($row->q1_revenue_score, 2) }}</span>
                                    <span class="score-track" aria-hidden="true">
                                        <span class="score-fill" style="width: {{ $scoreWidth($row->q1_revenue_score) }}%"></span>
                                    </span>
                                </div>
                            </td>
                            <td data-label="Q1營收(億)">{{ $fmt($row->q1_revenue_billion, 2) }}</td>
                            <td data-label="營收YoY" class="{{ $pctClass($row->q1_revenue_yoy_percent) }}">{{ $pctShort($row->q1_revenue_yoy_percent) }}</td>
                            <td data-label="Q1 EPS">{{ $fmt($row->q1_eps, 2) }}</td>
                            <td data-label="EPS YoY" class="{{ $pctClass($row->q1_eps_yoy_percent) }}">{{ $pctShort($row->q1_eps_yoy_percent) }}</td>
                            <td data-label="毛利率">{{ $pctShort($row->q1_gross_margin_percent) }}</td>
                            <td data-label="營益率">{{ $pctShort($row->q1_operating_margin_percent) }}</td>
                            <td data-label="淨利率">{{ $pctShort($row->q1_net_margin_percent) }}</td>
                            <td data-label="ROE">{{ $pctShort($row->roe_percent) }}</td>
                            <td data-label="本業佔比">{{ $pctShort($row->operating_profit_mix_percent) }}</td>
                            <td data-label="股價">
                                <div class="price">{{ $fmt($row->latest_close_price, 2) }}</div>
                                <div class="sub">{{ $dateText($row->latest_price_date) }}</div>
                            </td>
                            <td data-label="預期股價">
                                @if ($expectedPrice === null)
                                    <span class="neutral">--</span>
                                @else
                                    <div class="expected-price-cell">
                                        <div class="expected-price-main">
                                            <span class="price">{{ $fmt($expectedPrice, 2) }}</span>
                                            <span class="expected-diff {{ $pctClass($expectedPriceChangePercent) }}">({{ $signedPct($expectedPriceChangePercent) }})</span>
                                        </div>
                                        <div class="sub">PE {{ $fmt($reasonablePeRatio, 1) }}x</div>
                                        @if ($valuationGroup && $valuationGroupPe)
                                            <div class="valuation-group-note">{{ $valuationGroup }} {{ $fmt($valuationGroupPe, 1) }}x</div>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td data-label="1日" class="{{ $pctClass($row->price_change_1d_percent) }}">{{ $pctShort($row->price_change_1d_percent) }}</td>
                            <td data-label="5日" class="{{ $pctClass($row->price_change_5d_percent) }}">{{ $pctShort($row->price_change_5d_percent) }}</td>
                            <td data-label="20日" class="{{ $pctClass($row->price_change_20d_percent) }}">{{ $pctShort($row->price_change_20d_percent) }}</td>
                            @for ($monthIndex = 0; $monthIndex < 4; $monthIndex++)
                                @php
                                    $monthlyRevenue = $monthlyRevenueRows->get($monthIndex);
                                @endphp
                                <td data-label="近{{ $monthIndex + 1 }}月營收">
                                    @if ($monthlyRevenue)
                                        <div class="monthly-cell">
                                            <span class="monthly-metrics">
                                                <span class="metric-chip {{ $pctClass($monthlyRevenue['revenue_yoy_percent'] ?? null) }}">Y {{ $pctText($monthlyRevenue['revenue_yoy_percent'] ?? null) }}</span>
                                                <span class="metric-chip {{ $pctClass($monthlyRevenue['revenue_mom_percent'] ?? null) }}">M {{ $pctText($monthlyRevenue['revenue_mom_percent'] ?? null) }}</span>
                                            </span>
                                        </div>
                                    @else
                                        <span class="neutral">--</span>
                                    @endif
                                </td>
                            @endfor
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="pagination">
                <div class="count-text">共 {{ number_format($rows->total()) }} 筆</div>
                {{ $rows->links() }}
            </div>
        @endif
    </section>
</main>
<script>
    const filterForm = document.querySelector('[data-auto-submit-form]');
    if (filterForm) {
        const submitFieldsSelector = '[data-auto-submit-field]';
        let filterSubmitTimer = null;
        let lastFilterSignature = new URLSearchParams(new FormData(filterForm)).toString();

        function submitFilters() {
            const nextSignature = new URLSearchParams(new FormData(filterForm)).toString();
            if (nextSignature === lastFilterSignature || !filterForm.reportValidity()) {
                return;
            }

            lastFilterSignature = nextSignature;
            filterForm.submit();
        }

        function submitFiltersAfterFocusSettles() {
            window.clearTimeout(filterSubmitTimer);
            filterSubmitTimer = window.setTimeout(() => {
                if (document.activeElement && filterForm.contains(document.activeElement)) {
                    return;
                }

                submitFilters();
            }, 80);
        }

        filterForm.querySelectorAll(submitFieldsSelector).forEach((field) => {
            field.addEventListener('blur', submitFiltersAfterFocusSettles);
            field.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter') {
                    return;
                }

                event.preventDefault();
                submitFilters();
            });
        });

        filterForm.querySelectorAll('select').forEach((field) => {
            field.addEventListener('change', submitFilters);
        });
    }

    const copyTooltip = document.createElement('div');
    copyTooltip.className = 'copy-tooltip';
    copyTooltip.setAttribute('role', 'status');
    document.body.appendChild(copyTooltip);

    let copyTooltipTimer = null;

    function positionCopyTooltip(target) {
        const rect = target.getBoundingClientRect();
        const left = Math.max(12, Math.min(window.innerWidth - 12, rect.left + (rect.width / 2)));
        const top = Math.max(12, rect.top - 8);

        copyTooltip.style.left = `${left}px`;
        copyTooltip.style.top = `${top}px`;
    }

    function showCopyTooltip(target, text = null) {
        window.clearTimeout(copyTooltipTimer);
        copyTooltip.textContent = text || target.dataset.tooltip || '點一下操作';
        positionCopyTooltip(target);
        copyTooltip.classList.add('visible');
    }

    function hideCopyTooltip() {
        copyTooltip.classList.remove('visible');
    }

    document.addEventListener('pointerover', (event) => {
        const tooltipTarget = event.target.closest('[data-tooltip]');
        if (!tooltipTarget) {
            return;
        }

        showCopyTooltip(tooltipTarget);
    });

    document.addEventListener('pointerout', (event) => {
        const tooltipTarget = event.target.closest('[data-tooltip]');
        if (!tooltipTarget || tooltipTarget.contains(event.relatedTarget)) {
            return;
        }

        hideCopyTooltip();
    });

    document.addEventListener('focusin', (event) => {
        const tooltipTarget = event.target.closest('[data-tooltip]');
        if (tooltipTarget) {
            showCopyTooltip(tooltipTarget);
        }
    });

    document.addEventListener('focusout', (event) => {
        if (event.target.closest('[data-tooltip]')) {
            hideCopyTooltip();
        }
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('.copy-stock');
        if (!button) {
            return;
        }

        const value = button.dataset.copyValue || button.textContent.trim();
        try {
            await navigator.clipboard.writeText(value);
        } catch (error) {
            const textarea = document.createElement('textarea');
            textarea.value = value;
            textarea.setAttribute('readonly', 'readonly');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            document.execCommand('copy');
            textarea.remove();
        }

        button.classList.add('copied');
        showCopyTooltip(button, '已複製');
        copyTooltipTimer = window.setTimeout(() => {
            button.classList.remove('copied');
            hideCopyTooltip();
        }, 900);
    });

    window.addEventListener('scroll', hideCopyTooltip, { passive: true });
    window.addEventListener('resize', hideCopyTooltip);
</script>
</body>
</html>
