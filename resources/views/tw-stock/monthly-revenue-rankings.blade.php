<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>每月營收排行</title>
    <style>
        :root {
            --bg: #0d1412;
            --panel: rgba(250, 253, 248, 0.94);
            --panel-dark: rgba(12, 22, 19, 0.78);
            --line: rgba(185, 219, 202, 0.22);
            --ink: #15201b;
            --text: #f5fbf7;
            --muted: #89a096;
            --green: #17a06a;
            --red: #d94458;
            --gold: #f0bd4d;
            --cyan: #54d4c5;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            color: var(--text);
            background:
                linear-gradient(145deg, #0d1412 0%, #12201b 42%, #201a17 72%, #101a1d 100%);
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        body::before {
            position: fixed;
            inset: 0;
            z-index: -2;
            content: "";
            background-image:
                linear-gradient(rgba(84, 212, 197, 0.09) 1px, transparent 1px),
                linear-gradient(90deg, rgba(240, 189, 77, 0.08) 1px, transparent 1px),
                linear-gradient(120deg, transparent 0%, rgba(255, 255, 255, 0.05) 45%, transparent 55%);
            background-size: 48px 48px, 48px 48px, 320px 100%;
            animation: gridDrift 20s linear infinite;
        }

        body::after {
            position: fixed;
            inset: 0;
            z-index: -1;
            content: "";
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.08), transparent 20%, transparent 76%, rgba(23, 160, 106, 0.08)),
                repeating-linear-gradient(90deg, transparent 0 128px, rgba(255, 255, 255, 0.03) 128px 129px);
            pointer-events: none;
        }

        @keyframes gridDrift {
            from { background-position: 0 0, 0 0, -320px 0; }
            to { background-position: 48px 48px, 48px 48px, 320px 0; }
        }

        @keyframes glint {
            0%, 100% { transform: translateX(-38%); opacity: 0.42; }
            50% { transform: translateX(38%); opacity: 1; }
        }

        .shell {
            width: min(1560px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 28px 0 44px;
        }

        @include('tw-stock.partials.shared-shell-width')

        .topbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: end;
            margin-bottom: 18px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 28px;
            margin-bottom: 8px;
            padding: 0 10px;
            border: 1px solid rgba(84, 212, 197, 0.34);
            border-radius: 8px;
            color: #c3f8ef;
            background: rgba(8, 19, 17, 0.62);
            font-size: 12px;
            font-weight: 850;
        }

        .eyebrow::before {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 14px rgba(84, 212, 197, 0.42);
            content: "";
        }

        h1 {
            margin: 0;
            font-size: clamp(30px, 4vw, 54px);
            line-height: 1.08;
            letter-spacing: 0;
        }

        .meta {
            margin-top: 10px;
            color: #bad0c5;
            font-size: 14px;
            line-height: 1.55;
        }

        .nav-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
            max-width: 860px;
        }

        .nav-actions a,
        .submit-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border: 1px solid rgba(235, 247, 239, 0.2);
            border-radius: 8px;
            color: #e2f4eb;
            background: rgba(10, 20, 17, 0.66);
            text-decoration: none;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.18);
            transition: transform 180ms ease, border-color 180ms ease, background 180ms ease;
        }

        .nav-actions a { padding: 0 13px; }

        .nav-actions a:hover,
        .submit-button:hover {
            border-color: rgba(84, 212, 197, 0.58);
            transform: translateY(-1px);
        }

        .nav-actions a.active,
        .submit-button {
            border-color: rgba(240, 189, 77, 0.76);
            color: #121611;
            background: linear-gradient(135deg, #f7cf69, #58d9c9);
        }

        .filter-panel,
        .summary-card,
        .table-panel {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel-dark);
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.26);
            backdrop-filter: blur(18px);
        }

        .filter-panel::before,
        .summary-card::before,
        .table-panel::before {
            position: absolute;
            inset: 0 0 auto;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(84, 212, 197, 0.84), rgba(240, 189, 77, 0.76), transparent);
            content: "";
            animation: glint 5.2s ease-in-out infinite;
        }

        .filter-panel {
            display: grid;
            grid-template-columns: repeat(5, minmax(130px, 1fr)) auto;
            gap: 12px;
            align-items: end;
            margin-bottom: 16px;
            padding: 16px;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: #bdd2c7;
            font-size: 12px;
            font-weight: 850;
        }

        .field input,
        .field select {
            width: 100%;
            min-height: 40px;
            padding: 0 12px;
            border: 1px solid rgba(235, 247, 239, 0.18);
            border-radius: 8px;
            color: var(--text);
            background: rgba(7, 13, 11, 0.74);
            font: inherit;
            font-size: 14px;
            outline: none;
        }

        .field input:focus,
        .field select:focus {
            border-color: rgba(84, 212, 197, 0.78);
            box-shadow: 0 0 0 3px rgba(84, 212, 197, 0.13);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .summary-card {
            min-height: 94px;
            padding: 16px;
            background: var(--panel);
            color: var(--ink);
        }

        .summary-card .label {
            color: #65746b;
            font-size: 12px;
            font-weight: 900;
        }

        .summary-card .value {
            margin-top: 8px;
            font-size: clamp(22px, 2.2vw, 32px);
            font-weight: 950;
            line-height: 1.05;
        }

        .summary-card .note {
            margin-top: 7px;
            color: #6d7d73;
            font-size: 12px;
            font-weight: 800;
        }

        .table-panel {
            background: rgba(248, 252, 247, 0.95);
            color: var(--ink);
        }

        .table-headline {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(21, 32, 27, 0.1);
        }

        .table-headline strong {
            font-size: 17px;
            font-weight: 950;
        }

        .table-headline span {
            color: #6d7d73;
            font-size: 12px;
            font-weight: 850;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px 14px;
            border-bottom: 1px solid rgba(21, 32, 27, 0.09);
            text-align: right;
            vertical-align: middle;
        }

        th:first-child,
        td:first-child,
        th:nth-child(2),
        td:nth-child(2) {
            text-align: left;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #edf5ef;
            color: #45544d;
            font-size: 12px;
            font-weight: 950;
            white-space: nowrap;
        }

        th a {
            color: inherit;
            text-decoration: none;
        }

        tbody tr {
            transition: background 150ms ease, transform 150ms ease, box-shadow 150ms ease;
        }

        tbody tr:hover {
            background: #f8fbf2;
            box-shadow: inset 4px 0 0 var(--gold);
        }

        .rank {
            display: inline-flex;
            width: 30px;
            height: 30px;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            color: #12211b;
            background: linear-gradient(135deg, #f8d66d, #69decf);
            font-size: 13px;
            font-weight: 950;
        }

        .stock-code {
            display: block;
            color: #14201a;
            font-size: 18px;
            font-weight: 950;
            line-height: 1.1;
        }

        .stock-name {
            display: block;
            margin-top: 4px;
            color: #5f6f66;
            font-size: 12px;
            font-weight: 850;
        }

        .exchange-badge {
            display: inline-flex;
            min-width: 44px;
            justify-content: center;
            padding: 4px 7px;
            border-radius: 7px;
            color: #12624c;
            background: rgba(84, 212, 197, 0.18);
            font-size: 12px;
            font-weight: 950;
        }

        .num {
            font-variant-numeric: tabular-nums;
            font-weight: 900;
        }

        .positive { color: var(--red); }
        .negative { color: var(--green); }
        .neutral { color: #5f6f66; }

        .sum-chip {
            display: inline-flex;
            min-width: 86px;
            justify-content: center;
            padding: 7px 10px;
            border-radius: 8px;
            color: #111a16;
            background: linear-gradient(135deg, rgba(248, 214, 109, 0.9), rgba(105, 222, 207, 0.9));
            box-shadow: 0 8px 18px rgba(84, 212, 197, 0.18);
            font-weight: 950;
        }

        .empty {
            padding: 46px 18px;
            color: #5f6f66;
            text-align: center;
            font-weight: 850;
        }

        @media (max-width: 1180px) {
            .topbar { grid-template-columns: 1fr; }
            .nav-actions { justify-content: flex-start; max-width: none; }
            .filter-panel { grid-template-columns: repeat(3, minmax(0, 1fr)); }
            .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100vw - 18px, 640px);
                padding-top: 18px;
            }

            .nav-actions {
                flex-wrap: nowrap;
                justify-content: flex-start;
                overflow-x: auto;
                padding-bottom: 4px;
            }

            .nav-actions a { flex: 0 0 auto; }
            .filter-panel { grid-template-columns: 1fr; padding: 13px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }

            .table-headline {
                align-items: flex-start;
                flex-direction: column;
            }

            table,
            thead,
            tbody,
            th,
            td,
            tr {
                display: block;
            }

            thead { display: none; }

            tbody {
                display: grid;
                gap: 10px;
                padding: 10px;
            }

            tbody tr {
                border: 1px solid rgba(21, 32, 27, 0.1);
                border-radius: 8px;
                background: #ffffff;
                box-shadow: 0 12px 24px rgba(21, 32, 27, 0.08);
            }

            td {
                display: flex;
                justify-content: space-between;
                gap: 14px;
                padding: 10px 12px;
                border-bottom: 1px solid rgba(21, 32, 27, 0.08);
                text-align: right !important;
            }

            td:last-child { border-bottom: 0; }

            td::before {
                content: attr(data-label);
                color: #6d7d73;
                font-size: 12px;
                font-weight: 900;
                text-align: left;
            }

            td[data-label="股票"] {
                display: grid;
                grid-template-columns: auto 1fr;
                align-items: center;
                text-align: left !important;
            }

            td[data-label="股票"]::before { display: none; }
        }

        @media (max-width: 440px) {
            .summary-grid { grid-template-columns: 1fr; }
            h1 { font-size: 30px; }
        }
    </style>
</head>
<body>
@php
    $periodLabel = sprintf('%04d/%02d', $year, $month);
    $periodValue = sprintf('%04d-%02d', $year, $month);
    $fmtInt = fn ($value): string => $value === null ? '-' : number_format((float) $value);
    $fmtRevenue = fn ($thousands): string => $thousands === null ? '-' : number_format((float) $thousands / 100000, 2) . ' 億';
    $fmtPct = function ($value, bool $signed = false): string {
        if ($value === null || $value === '') {
            return '-';
        }

        $number = (float) $value;
        $prefix = $signed && $number > 0 ? '+' : '';

        return $prefix . number_format($number, 2) . '%';
    };
    $tone = function ($value): string {
        if ($value === null || $value === '') {
            return 'neutral';
        }

        $number = (float) $value;

        return $number > 0 ? 'positive' : ($number < 0 ? 'negative' : 'neutral');
    };
    $exchangeLabel = fn (string $exchange): string => $exchange === 'TPEx' ? '上櫃' : '上市';
    $thresholdText = fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    $queryBase = [
        'period' => $periodValue,
        'mom_gt' => $thresholdText($thresholds['mom']),
        'yoy_gt' => $thresholdText($thresholds['yoy']),
        'sum_gt' => $thresholdText($thresholds['sum']),
    ];
    $sortUrl = function (string $key) use ($queryBase, $sort, $direction): string {
        $nextDirection = $sort === $key && $direction === 'desc' ? 'asc' : 'desc';

        return route('tw-stock.monthly-revenues.index', array_merge($queryBase, [
            'sort' => $key,
            'direction' => $nextDirection,
        ]));
    };
    $sortMark = fn (string $key): string => $sort === $key ? ($direction === 'desc' ? '▼' : '▲') : '↕';
@endphp

<main class="shell">
    <section class="topbar">
        <div>
            <div class="eyebrow">MOPS 月營收</div>
            <h1>每月營收排行</h1>
            <div class="meta">
                {{ $periodLabel }} 已公告上市櫃公司，預設月增 &gt; {{ $thresholdText($thresholds['mom']) }}%、
                年增 &gt; {{ $thresholdText($thresholds['yoy']) }}%、月增+年增 &gt; {{ $thresholdText($thresholds['sum']) }}%，最多顯示前 100 筆。
            </div>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
            <a class="active" href="{{ route('tw-stock.monthly-revenues.index') }}">月營收</a>
            <a href="{{ route('tw-stock.active-etf-operations.index') }}">主動ETF</a>
            <a href="{{ route('tw-stock.taiex-futures.kline') }}">台指期K線</a>
        </nav>
    </section>

    <form class="filter-panel" method="get" action="{{ route('tw-stock.monthly-revenues.index') }}">
        <div class="field">
            <label for="period">資料月份</label>
            <select id="period" name="period">
                @forelse ($availablePeriods as $period)
                    <option value="{{ sprintf('%04d-%02d', $period['year'], $period['month']) }}" {{ $period['year'] === $year && $period['month'] === $month ? 'selected' : '' }}>
                        {{ $period['label'] }}
                    </option>
                @empty
                    <option value="{{ $periodValue }}">{{ $periodLabel }}</option>
                @endforelse
            </select>
        </div>
        <div class="field">
            <label for="mom_gt">月增 &gt; %</label>
            <input id="mom_gt" name="mom_gt" type="number" step="0.01" value="{{ $thresholdText($thresholds['mom']) }}">
        </div>
        <div class="field">
            <label for="yoy_gt">年增 &gt; %</label>
            <input id="yoy_gt" name="yoy_gt" type="number" step="0.01" value="{{ $thresholdText($thresholds['yoy']) }}">
        </div>
        <div class="field">
            <label for="sum_gt">月增+年增 &gt; %</label>
            <input id="sum_gt" name="sum_gt" type="number" step="0.01" value="{{ $thresholdText($thresholds['sum']) }}">
        </div>
        <div class="field">
            <label for="sort">排序</label>
            <select id="sort" name="sort">
                <option value="sum" {{ $sort === 'sum' ? 'selected' : '' }}>月增+年增</option>
                <option value="revenue" {{ $sort === 'revenue' ? 'selected' : '' }}>當月營收</option>
                <option value="mom" {{ $sort === 'mom' ? 'selected' : '' }}>月增</option>
                <option value="yoy" {{ $sort === 'yoy' ? 'selected' : '' }}>年增</option>
                <option value="cumulative" {{ $sort === 'cumulative' ? 'selected' : '' }}>累計年增</option>
                <option value="day_change" {{ $sort === 'day_change' ? 'selected' : '' }}>一日漲跌</option>
                <option value="five_day" {{ $sort === 'five_day' ? 'selected' : '' }}>五日漲跌</option>
            </select>
        </div>
        <input type="hidden" name="direction" value="{{ $direction }}">
        <button class="submit-button" type="submit">套用</button>
    </form>

    <section class="summary-grid" aria-label="月營收摘要">
        <div class="summary-card">
            <div class="label">已公告</div>
            <div class="value">{{ $fmtInt($summary['announced_count']) }}</div>
            <div class="note">上市 {{ $fmtInt($summary['twse_count']) }} / 上櫃 {{ $fmtInt($summary['tpex_count']) }}</div>
        </div>
        <div class="summary-card">
            <div class="label">符合篩選</div>
            <div class="value">{{ $fmtInt($summary['total_matches']) }}</div>
            <div class="note">頁面顯示 {{ $fmtInt($summary['visible_count']) }} 筆</div>
        </div>
        <div class="summary-card">
            <div class="label">最高動能</div>
            <div class="value {{ $tone($summary['max_sum']) }}">{{ $fmtPct($summary['max_sum'], true) }}</div>
            <div class="note">月增+年增</div>
        </div>
        <div class="summary-card">
            <div class="label">最大營收</div>
            <div class="value">{{ $fmtRevenue($summary['max_revenue']) }}</div>
            <div class="note">符合篩選內</div>
        </div>
        <div class="summary-card">
            <div class="label">公告日</div>
            <div class="value">{{ $summary['latest_announced_date'] ?? '-' }}</div>
            <div class="note">最新出表日期</div>
        </div>
        <div class="summary-card">
            <div class="label">股價日</div>
            <div class="value">{{ $summary['latest_price_date'] ?? '-' }}</div>
            <div class="note">最近日漲跌來源</div>
        </div>
    </section>

    <section class="table-panel">
        <div class="table-headline">
            <strong>{{ $periodLabel }} 排行</strong>
            <span>共 {{ $fmtInt($summary['total_matches']) }} 筆符合條件，{{ $summary['total_matches'] > 100 ? '顯示前 100 筆' : '全數顯示' }}</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th><a href="{{ $sortUrl('stock') }}">股票 {{ $sortMark('stock') }}</a></th>
                    <th><a href="{{ $sortUrl('exchange') }}">上市/櫃 {{ $sortMark('exchange') }}</a></th>
                    <th><a href="{{ $sortUrl('revenue') }}">當月營收 {{ $sortMark('revenue') }}</a></th>
                    <th><a href="{{ $sortUrl('mom') }}">月增 {{ $sortMark('mom') }}</a></th>
                    <th><a href="{{ $sortUrl('yoy') }}">年增 {{ $sortMark('yoy') }}</a></th>
                    <th><a href="{{ $sortUrl('sum') }}">月增+年增 {{ $sortMark('sum') }}</a></th>
                    <th><a href="{{ $sortUrl('cumulative') }}">今年累計年增 {{ $sortMark('cumulative') }}</a></th>
                    <th><a href="{{ $sortUrl('day_change') }}">最近一日漲跌 {{ $sortMark('day_change') }}</a></th>
                    <th><a href="{{ $sortUrl('five_day') }}">最近5日漲跌 {{ $sortMark('five_day') }}</a></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($rows as $index => $row)
                    <tr>
                        <td data-label="#"><span class="rank">{{ $index + 1 }}</span></td>
                        <td data-label="股票">
                            <span>
                                <span class="stock-code">{{ $row->stock_code }}</span>
                                <span class="stock-name">{{ $row->stock_name }}{{ $row->industry ? ' · ' . $row->industry : '' }}</span>
                            </span>
                        </td>
                        <td data-label="上市/櫃"><span class="exchange-badge">{{ $exchangeLabel((string) $row->exchange) }}</span></td>
                        <td data-label="當月營收" class="num">{{ $fmtRevenue($row->monthly_revenue_thousands) }}</td>
                        <td data-label="月增" class="num {{ $tone($row->month_over_month_percent) }}">{{ $fmtPct($row->month_over_month_percent, true) }}</td>
                        <td data-label="年增" class="num {{ $tone($row->year_over_year_percent) }}">{{ $fmtPct($row->year_over_year_percent, true) }}</td>
                        <td data-label="月增+年增"><span class="sum-chip">{{ $fmtPct($row->mom_yoy_sum_percent, true) }}</span></td>
                        <td data-label="今年累計年增" class="num {{ $tone($row->cumulative_yoy_percent) }}">{{ $fmtPct($row->cumulative_yoy_percent, true) }}</td>
                        <td data-label="最近一日漲跌" class="num {{ $tone($row->one_day_change_percent) }}">{{ $fmtPct($row->one_day_change_percent, true) }}</td>
                        <td data-label="最近5日漲跌" class="num {{ $tone($row->five_day_change_percent) }}">{{ $fmtPct($row->five_day_change_percent, true) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td class="empty" colspan="10">目前沒有符合條件的已公告資料。</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
