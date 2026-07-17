<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台股每日漲幅排行</title>
    <script src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.2/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        :root {
            --bg: #eaf2ff;
            --panel: #ffffff;
            --line: #c8d7e8;
            --line-strong: #aebfd3;
            --text: #122039;
            --muted: #66758a;
            --dark: #10264b;
            --red: #dc2626;
            --green: #15803d;
            --blue: #2563eb;
            --cyan: #0891b2;
            --amber: #b45309;
        }

        * { box-sizing: border-box; }

        body {
            position: relative;
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(rgba(37, 99, 235, 0.055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37, 99, 235, 0.055) 1px, transparent 1px),
                radial-gradient(circle at 14% 5%, rgba(56, 189, 248, 0.28), transparent 25%),
                radial-gradient(circle at 88% 8%, rgba(99, 102, 241, 0.23), transparent 26%),
                radial-gradient(circle at 70% 82%, rgba(20, 184, 166, 0.14), transparent 28%),
                linear-gradient(145deg, #eff7ff 0%, #e7effc 48%, #f4f7ff 100%);
            background-size: 34px 34px, 34px 34px, auto, auto, auto, auto;
            background-attachment: fixed;
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
        }

        body::before,
        body::after {
            position: fixed;
            z-index: -1;
            width: 340px;
            height: 340px;
            border-radius: 999px;
            content: "";
            pointer-events: none;
            filter: blur(12px);
            opacity: 0.5;
        }

        body::before {
            top: 12%;
            left: -180px;
            background: radial-gradient(circle, rgba(14, 165, 233, 0.34), transparent 68%);
        }

        body::after {
            right: -170px;
            bottom: 7%;
            background: radial-gradient(circle, rgba(79, 70, 229, 0.28), transparent 68%);
        }

        button,
        input,
        select { font: inherit; }

        .shell {
            width: min(1960px, calc(100vw - 24px));
            margin: 0 auto;
            padding: 24px 0 46px;
        }

        .topbar {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            position: relative;
            overflow: hidden;
            margin-bottom: 16px;
            padding: 24px;
            border: 1px solid rgba(147, 197, 253, 0.28);
            border-radius: 18px;
            color: #fff;
            background:
                radial-gradient(circle at 18% 0%, rgba(56, 189, 248, 0.34), transparent 34%),
                radial-gradient(circle at 88% 100%, rgba(99, 102, 241, 0.38), transparent 36%),
                linear-gradient(135deg, #0b1f3f 0%, #173f78 54%, #203a7c 100%);
            box-shadow: 0 22px 48px rgba(15, 39, 82, 0.24);
        }

        .topbar::after {
            position: absolute;
            top: -90px;
            right: 8%;
            width: 270px;
            height: 270px;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 50%;
            content: "";
            box-shadow:
                0 0 0 36px rgba(255, 255, 255, 0.035),
                0 0 0 72px rgba(255, 255, 255, 0.02);
            pointer-events: none;
        }

        .hero-copy,
        .nav-actions {
            position: relative;
            z-index: 1;
        }

        h1 {
            margin: 0;
            font-size: 32px;
            line-height: 1.18;
            letter-spacing: 0.02em;
            text-shadow: 0 3px 18px rgba(3, 12, 32, 0.34);
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            color: #cbdcf5;
            font-size: 13px;
            font-weight: 700;
        }

        .market-pill,
        .refresh-pill {
            display: inline-flex;
            align-items: center;
            min-height: 27px;
            padding: 0 10px;
            border: 1px solid rgba(191, 219, 254, 0.28);
            border-radius: 999px;
            color: #dcecff;
            background: rgba(255, 255, 255, 0.09);
            backdrop-filter: blur(8px);
        }

        .market-pill.live {
            color: #d9fff5;
            border-color: rgba(45, 212, 191, 0.55);
            background: rgba(13, 148, 136, 0.22);
            box-shadow: 0 0 18px rgba(45, 212, 191, 0.18);
        }

        .market-pill.live::before {
            width: 7px;
            height: 7px;
            margin-right: 7px;
            border-radius: 50%;
            content: "";
            background: #5eead4;
            box-shadow: 0 0 0 4px rgba(94, 234, 212, 0.14);
            animation: live-pulse 1.8s infinite;
        }

        @keyframes live-pulse {
            50% { opacity: 0.46; transform: scale(0.82); }
        }

        .nav-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .nav-actions a,
        .sort-link,
        .detail-link,
        .realtime-chart-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 13px;
            border: 1px solid var(--line);
            border-radius: 10px;
            color: #334155;
            background: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-size: 13px;
            font-weight: 850;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
            cursor: pointer;
            transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease, box-shadow 0.16s ease;
        }

        .nav-actions a {
            color: #e7f1ff;
            border-color: rgba(191, 219, 254, 0.24);
            background: rgba(255, 255, 255, 0.09);
            box-shadow: none;
            backdrop-filter: blur(8px);
        }

        .nav-actions a:hover,
        .sort-link:hover,
        .detail-link:hover,
        .realtime-chart-button:hover {
            transform: translateY(-2px);
            border-color: #89a8c7;
            box-shadow: 0 11px 24px rgba(26, 61, 111, 0.13);
        }

        .nav-actions a:hover { background: rgba(255, 255, 255, 0.16); }

        .nav-actions a.active,
        .sort-link.active {
            color: #fff;
            border-color: transparent;
            background: linear-gradient(135deg, #2563eb, #0891b2);
            box-shadow: 0 10px 24px rgba(3, 105, 161, 0.28);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .summary-card,
        .control-panel,
        .table-panel {
            border: 1px solid rgba(148, 177, 211, 0.58);
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 16px 38px rgba(30, 64, 113, 0.11);
            backdrop-filter: blur(14px);
        }

        .summary-card {
            position: relative;
            overflow: hidden;
            min-height: 108px;
            padding: 16px;
            border-top: 4px solid #d7e2ea;
            transition: transform 0.18s ease, box-shadow 0.18s ease;
        }

        .summary-card::after {
            position: absolute;
            right: -24px;
            bottom: -34px;
            width: 92px;
            height: 92px;
            border-radius: 50%;
            content: "";
            background: rgba(37, 99, 235, 0.07);
        }

        .summary-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 20px 42px rgba(30, 64, 113, 0.16);
        }

        .summary-card.hot { border-top-color: var(--red); }
        .summary-card.cool { border-top-color: var(--green); }
        .summary-card.info { border-top-color: var(--blue); }

        .label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 850;
        }

        .value {
            margin-top: 9px;
            font-size: 27px;
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

        .positive { color: var(--red); }
        .negative { color: var(--green); }
        .muted { color: var(--muted); }

        .control-panel {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px;
            margin-bottom: 14px;
        }

        .controls,
        .sort-links {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 9px;
        }

        input,
        select {
            min-height: 40px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            padding: 0 12px;
            color: var(--text);
            font-size: 14px;
            font-weight: 750;
            outline: none;
        }

        input:focus,
        select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .table-panel {
            overflow-x: auto;
            border-color: var(--line-strong);
            box-shadow: 0 24px 56px rgba(30, 64, 113, 0.16);
            scrollbar-color: #8aa5c6 #eaf1f8;
            scrollbar-width: thin;
        }

        .ranking-table {
            width: 100%;
            min-width: 1710px;
            border-collapse: separate;
            border-spacing: 0;
            background: var(--panel);
        }

        .ranking-table th,
        .ranking-table td {
            padding: 13px 12px;
            border-right: 1px solid #d7e2ef;
            border-bottom: 1px solid #d7e2ef;
            text-align: right;
            white-space: nowrap;
            font-size: 14px;
        }

        .ranking-table th:last-child,
        .ranking-table td:last-child { border-right: 0; }

        .ranking-table th {
            position: sticky;
            top: 0;
            z-index: 3;
            color: #e8f2ff;
            border-color: rgba(148, 181, 222, 0.36);
            background: linear-gradient(180deg, #193b6b 0%, #102b53 100%);
            font-size: 12px;
            font-weight: 900;
            letter-spacing: 0.025em;
            text-shadow: 0 1px 4px rgba(4, 16, 39, 0.45);
        }

        .ranking-table td {
            background: rgba(255, 255, 255, 0.97);
            font-weight: 750;
            font-variant-numeric: tabular-nums;
            transition: background 0.16s ease, box-shadow 0.16s ease;
        }

        .ranking-table tbody tr:nth-child(even) td { background: #f7faff; }

        .ranking-table tbody tr:hover td {
            background: #edf6ff;
            box-shadow: inset 0 1px rgba(37, 99, 235, 0.07), inset 0 -1px rgba(37, 99, 235, 0.07);
        }

        .ranking-table th:first-child,
        .ranking-table td:first-child,
        .ranking-table th:nth-child(2),
        .ranking-table td:nth-child(2) { text-align: left; }

        .ranking-table th:first-child,
        .ranking-table td:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
            width: 78px;
        }

        .ranking-table th:nth-child(2),
        .ranking-table td:nth-child(2) {
            position: sticky;
            left: 78px;
            z-index: 2;
            min-width: 180px;
            box-shadow: 8px 0 16px -14px rgba(15, 41, 77, 0.85);
        }

        .ranking-table thead th:first-child,
        .ranking-table thead th:nth-child(2) { z-index: 5; }

        .rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            min-height: 32px;
            border: 1px solid rgba(255, 255, 255, 0.24);
            border-radius: 10px;
            color: #fff;
            background: linear-gradient(135deg, #173c80, #2563eb 64%, #0ea5e9);
            box-shadow: 0 7px 16px rgba(37, 99, 235, 0.24);
            font-weight: 900;
        }

        .stock-main {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 145px;
            padding: 4px 2px;
            border-radius: 8px;
            outline: none;
            cursor: help;
        }

        .stock-main:focus-visible {
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
        }

        .stock-main a {
            color: #0f172a;
            text-decoration: none;
            font-size: 16px;
            font-weight: 900;
        }

        .stock-main a:hover { color: var(--blue); }

        .stock-sub {
            color: var(--muted);
            font-size: 12px;
        }

        .live-price {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            font-weight: 900;
        }

        .live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #14b8a6;
            box-shadow: 0 0 0 4px rgba(20, 184, 166, 0.12);
        }

        .metric-stack {
            display: flex;
            align-items: flex-end;
            flex-direction: column;
            gap: 4px;
            min-width: 132px;
        }

        .metric-value {
            color: #15284a;
            font-size: 14px;
            font-weight: 900;
        }

        .metric-note {
            color: var(--muted);
            font-size: 11px;
            font-weight: 750;
        }

        .pe-chip {
            display: inline-flex;
            align-items: center;
            min-height: 28px;
            padding: 0 9px;
            border: 1px solid #b8d5f6;
            border-radius: 999px;
            color: #164e8a;
            background: linear-gradient(135deg, #eff8ff, #e5f0ff);
            font-weight: 900;
            box-shadow: inset 0 1px rgba(255, 255, 255, 0.9);
        }

        .yoy { font-weight: 900; }

        .realtime-chart-button {
            color: #075985;
            border-color: #bae6fd;
            background: linear-gradient(135deg, #f0f9ff, #e0f2fe);
        }

        .pager {
            padding: 18px 14px;
            border-top: 1px solid var(--line);
            background: #f8fbfd;
        }

        .chart-modal {
            position: fixed;
            z-index: 1000;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 22px;
            background: rgba(5, 16, 38, 0.7);
            backdrop-filter: blur(9px);
        }

        .chart-modal[hidden] { display: none; }

        .chart-dialog {
            width: min(1060px, 96vw);
            max-height: 92vh;
            overflow: auto;
            border: 1px solid rgba(147, 197, 253, 0.52);
            border-radius: 18px;
            background: #f8fbff;
            box-shadow: 0 36px 90px rgba(2, 12, 35, 0.45);
        }

        .chart-dialog__head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 18px 20px;
            color: #fff;
            background:
                radial-gradient(circle at 88% 0%, rgba(56, 189, 248, 0.3), transparent 34%),
                linear-gradient(135deg, #102b53, #1e4d8f);
        }

        .chart-dialog__title {
            font-size: 21px;
            font-weight: 900;
        }

        .chart-dialog__sub {
            margin-top: 4px;
            color: #c8dcf5;
            font-size: 12px;
            font-weight: 750;
        }

        .chart-close {
            display: inline-grid;
            width: 38px;
            height: 38px;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.24);
            border-radius: 10px;
            color: #fff;
            background: rgba(255, 255, 255, 0.1);
            font-size: 20px;
            cursor: pointer;
        }

        .chart-tabs {
            display: flex;
            gap: 8px;
            padding: 14px 18px 0;
        }

        .chart-tab {
            min-height: 38px;
            padding: 0 15px;
            border: 1px solid var(--line);
            border-radius: 10px;
            color: #475569;
            background: #fff;
            font-weight: 850;
            cursor: pointer;
        }

        .chart-tab.active {
            color: #fff;
            border-color: #2563eb;
            background: linear-gradient(135deg, #2563eb, #0891b2);
        }

        .chart-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-height: 36px;
            padding: 10px 18px 0;
        }

        .chart-meta__item {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 0 10px;
            border: 1px solid #c8d8ea;
            border-radius: 999px;
            color: #40536f;
            background: #fff;
            font-size: 12px;
            font-weight: 850;
            font-variant-numeric: tabular-nums;
        }

        .chart-stage {
            position: relative;
            height: min(560px, 62vh);
            margin: 14px 18px 18px;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: #fff;
        }

        .chart-panel {
            width: 100%;
            height: 100%;
        }

        .chart-panel[hidden] { display: none; }

        .chart-message {
            position: absolute;
            z-index: 2;
            inset: 0;
            display: grid;
            place-items: center;
            padding: 20px;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.88);
            text-align: center;
            font-weight: 850;
        }

        .preview-popover {
            position: fixed;
            z-index: 900;
            width: min(410px, calc(100vw - 24px));
            overflow: hidden;
            border: 1px solid #9bb9db;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 24px 62px rgba(9, 35, 74, 0.28);
        }

        .preview-popover[hidden] { display: none; }

        .preview-head {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 11px 13px;
            color: #eaf4ff;
            background: linear-gradient(135deg, #123360, #2458a0);
        }

        .preview-title { font-weight: 900; }
        .preview-note { color: #bfd4ee; font-size: 11px; font-weight: 750; }
        .preview-chart { height: 235px; }

        @media (max-width: 1100px) {
            .topbar,
            .control-panel {
                align-items: flex-start;
                flex-direction: column;
            }

            .nav-actions { justify-content: flex-start; }
            .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 720px) {
            .shell { padding-top: 10px; }
            .topbar { padding: 19px; border-radius: 14px; }
            h1 { font-size: 25px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .value { font-size: 22px; }
            .ranking-table th, .ranking-table td { padding: 11px 10px; }
            .ranking-table th:first-child, .ranking-table td:first-child { width: 68px; }
            .ranking-table th:nth-child(2), .ranking-table td:nth-child(2) { left: 68px; }
            .chart-modal { padding: 8px; }
            .chart-stage { height: 58vh; margin: 10px; }
        }
@include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
@php
    $fmt = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals);
    $pct = fn ($value): string => $value === null ? '--' : (($value > 0 ? '+' : '') . number_format((float) $value, 2) . '%');
    $pctClass = fn ($value): string => $value === null ? 'muted' : ((float) $value >= 0 ? 'positive' : 'negative');
    $fmtRevenue = fn ($thousands): string => $thousands === null ? '--' : number_format((float) $thousands / 100000, 2) . ' 億';
    $sortUrl = fn (string $key): string => request()->fullUrlWithQuery([
        'sort' => $key,
        'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
        'page' => null,
    ]);
@endphp

<main class="shell">
    <section class="topbar">
        <div class="hero-copy">
            <h1>台股每日漲幅排行</h1>
            <div class="meta">
                <span>價格日：<span data-price-date>{{ $latestDate ?? '--' }}</span></span>
                <span class="market-pill {{ $initialMarket['isOpen'] ? 'live' : '' }}" data-market-status>{{ $initialMarket['label'] }}</span>
                <span class="refresh-pill" data-refresh-status>{{ $initialMarket['isOpen'] ? '每 15 秒更新' : '盤後停止更新' }}</span>
                <span>滑鼠移到股票可看近 10 日 K 線</span>
            </div>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a class="active" href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
            <a href="{{ route('tw-stock.monthly-revenues.index') }}">月營收</a>
            <a href="{{ route('tw-stock.active-etf-operations.index') }}">主動ETF</a>
            <a href="{{ route('tw-stock.taiex-futures.kline') }}">台指期K線</a>
        </nav>
    </section>

    <section class="summary-grid">
        <div class="summary-card info">
            <div class="label">排行股票</div>
            <div class="value" data-summary="total">{{ number_format($summary['total']) }}</div>
            <div class="sub">目前排行的股票數</div>
        </div>
        <div class="summary-card hot">
            <div class="label">上漲</div>
            <div class="value positive" data-summary="up">{{ number_format($summary['up']) }}</div>
            <div class="sub">高於前一交易日收盤</div>
        </div>
        <div class="summary-card cool">
            <div class="label">下跌</div>
            <div class="value negative" data-summary="down">{{ number_format($summary['down']) }}</div>
            <div class="sub">低於前一交易日收盤</div>
        </div>
        <div class="summary-card">
            <div class="label">平盤</div>
            <div class="value" data-summary="flat">{{ number_format($summary['flat']) }}</div>
            <div class="sub">漲跌幅等於 0</div>
        </div>
        <div class="summary-card hot">
            <div class="label">最大漲幅</div>
            <div class="value positive" data-summary="maxChange">{{ $pct($summary['maxChange']) }}</div>
            <div class="sub">目前漲幅第一名</div>
        </div>
        <div class="summary-card cool">
            <div class="label">最大跌幅</div>
            <div class="value negative" data-summary="minChange">{{ $pct($summary['minChange']) }}</div>
            <div class="sub">目前跌幅最大</div>
        </div>
    </section>

    <form class="control-panel" method="get" action="{{ route('tw-stock.daily-prices.index') }}">
        <input type="hidden" name="sort" value="{{ $sort }}">
        <input type="hidden" name="direction" value="{{ $direction }}">
        <div class="controls">
            <input name="q" value="{{ $keyword }}" placeholder="搜尋代碼或名稱">
            <select name="per_page" onchange="this.form.submit()">
                @foreach($allowedPerPage as $size)
                    <option value="{{ $size }}" @selected($perPage === $size)>每頁 {{ $size }} 檔</option>
                @endforeach
            </select>
        </div>
        <div class="sort-links">
            <a class="sort-link {{ $sort === 'change' ? 'active' : '' }}" href="{{ $sortUrl('change') }}">漲幅排序</a>
            <a class="sort-link {{ $sort === 'amount' ? 'active' : '' }}" href="{{ $sortUrl('amount') }}">漲跌點排序</a>
            <a class="sort-link {{ $sort === 'volume' ? 'active' : '' }}" href="{{ $sortUrl('volume') }}">成交量排序</a>
            <a class="sort-link {{ $sort === 'price' ? 'active' : '' }}" href="{{ $sortUrl('price') }}">股價排序</a>
        </div>
    </form>

    <section class="table-panel">
        <table class="ranking-table">
            <thead>
            <tr>
                <th>排名</th>
                <th>股票</th>
                <th>收盤／即時</th>
                <th>漲跌</th>
                <th>漲幅</th>
                <th>本益比（近四季）</th>
                <th>近一月營收（YoY）</th>
                <th>上上個月營收（YoY）</th>
                <th>成交量(張)</th>
                <th>交易日</th>
                <th>即時圖</th>
                <th>明細</th>
            </tr>
            </thead>
            <tbody data-ranking-body>
            @foreach($rows as $row)
                @php
                    $metric = $stockMetrics[$row->exchange . '|' . $row->stock_code] ?? null;
                    $latestRevenue = $metric['latestRevenue'] ?? null;
                    $previousRevenue = $metric['previousRevenue'] ?? null;
                @endphp
                <tr>
                    <td><span class="rank">{{ $rows->firstItem() + $loop->index }}</span></td>
                    <td>
                        <div
                            class="stock-main"
                            tabindex="0"
                            data-preview-stock
                            data-stock-code="{{ $row->stock_code }}"
                            data-stock-name="{{ $row->stock_name }}"
                            data-exchange="{{ $row->exchange }}"
                        >
                            <a href="{{ route('tw-stock.daily-prices.show', ['stockCode' => $row->stock_code, 'exchange' => $row->exchange]) }}">{{ $row->stock_code }}</a>
                            <span class="stock-sub">{{ $row->stock_name }} · {{ $row->exchange }}</span>
                        </div>
                    </td>
                    <td><span class="live-price">{{ $fmt($row->close_price, 2) }}</span></td>
                    <td class="{{ $pctClass($row->price_change_amount) }}">{{ $row->price_change_amount > 0 ? '+' : '' }}{{ $fmt($row->price_change_amount, 2) }}</td>
                    <td class="{{ $pctClass($row->price_change_percent) }}">{{ $pct($row->price_change_percent) }}</td>
                    <td>
                        <div class="metric-stack">
                            <span class="{{ ($metric['trailingPe'] ?? null) !== null ? 'pe-chip' : 'metric-value muted' }}">
                                {{ ($metric['trailingPe'] ?? null) === null ? '--' : number_format((float) $metric['trailingPe'], 2) . ' 倍' }}
                            </span>
                            <span class="metric-note">
                                @if(($metric['trailingEps'] ?? null) !== null)
                                    EPS {{ number_format((float) $metric['trailingEps'], 2) }} · {{ $metric['trailingPeriod'] }}
                                @else
                                    {{ ($metric['trailingQuarterCount'] ?? 0) > 0 ? '目前僅 ' . $metric['trailingQuarterCount'] . ' 季' : '尚無季報資料' }}
                                @endif
                            </span>
                        </div>
                    </td>
                    <td>
                        <div class="metric-stack">
                            <span class="metric-value">
                                {{ $fmtRevenue($latestRevenue['revenueThousands'] ?? null) }}
                                <span class="yoy {{ $pctClass($latestRevenue['yoyPercent'] ?? null) }}">({{ $pct($latestRevenue['yoyPercent'] ?? null) }})</span>
                            </span>
                            <span class="metric-note">{{ $latestRevenue['period'] ?? '尚無月營收' }}</span>
                        </div>
                    </td>
                    <td>
                        <div class="metric-stack">
                            <span class="metric-value">
                                {{ $fmtRevenue($previousRevenue['revenueThousands'] ?? null) }}
                                <span class="yoy {{ $pctClass($previousRevenue['yoyPercent'] ?? null) }}">({{ $pct($previousRevenue['yoyPercent'] ?? null) }})</span>
                            </span>
                            <span class="metric-note">{{ $previousRevenue['period'] ?? '尚無前月資料' }}</span>
                        </div>
                    </td>
                    <td>{{ number_format((int) $row->volume_lots) }}</td>
                    <td>{{ $row->trade_date?->toDateString() }}</td>
                    <td>
                        <button
                            class="realtime-chart-button"
                            type="button"
                            data-open-intraday
                            data-stock-code="{{ $row->stock_code }}"
                            data-stock-name="{{ $row->stock_name }}"
                            data-exchange="{{ $row->exchange }}"
                            data-previous-close="{{ $row->previous_close_price ?? ((float) $row->close_price - (float) $row->price_change_amount) }}"
                        >走勢／K</button>
                    </td>
                    <td><a class="detail-link" href="{{ route('tw-stock.daily-prices.show', ['stockCode' => $row->stock_code, 'exchange' => $row->exchange]) }}">日 K</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="pager">{{ $rows->links('tw-stock.partials.pagination') }}</div>
    </section>
</main>

<div class="chart-modal" data-chart-modal hidden>
    <section class="chart-dialog" role="dialog" aria-modal="true" aria-labelledby="intraday-chart-title">
        <header class="chart-dialog__head">
            <div>
                <div class="chart-dialog__title" id="intraday-chart-title" data-chart-title>即時圖</div>
                <div class="chart-dialog__sub" data-chart-subtitle>讀取盤中資料中</div>
            </div>
            <button class="chart-close" type="button" data-chart-close aria-label="關閉">×</button>
        </header>
        <div class="chart-tabs" role="tablist">
            <button class="chart-tab active" type="button" data-chart-tab="trend">即時走勢</button>
            <button class="chart-tab" type="button" data-chart-tab="kline">即時 K 線</button>
        </div>
        <div class="chart-meta" data-chart-meta aria-live="polite"></div>
        <div class="chart-stage">
            <div class="chart-message" data-chart-message>讀取盤中資料中…</div>
            <div class="chart-panel" data-chart-panel="trend"></div>
            <div class="chart-panel" data-chart-panel="kline" hidden></div>
        </div>
    </section>
</div>

<aside class="preview-popover" data-preview-popover hidden>
    <div class="preview-head">
        <div>
            <div class="preview-title" data-preview-title>近 10 日 K 線</div>
            <div class="preview-note">正式日價 · 最近 10 個交易日</div>
        </div>
        <div class="preview-note" data-preview-period></div>
    </div>
    <div class="preview-chart" data-preview-chart></div>
</aside>

<script>
    const initialMarket = @json($initialMarket, JSON_UNESCAPED_UNICODE);
    const realtimeUrl = @json($realtimeUrl);
    const intradayUrlTemplate = @json($intradayUrlTemplate);
    const previewUrlTemplate = @json($previewUrlTemplate);
    const rankingBody = document.querySelector('[data-ranking-body]');
    const marketStatusElement = document.querySelector('[data-market-status]');
    const refreshStatusElement = document.querySelector('[data-refresh-status]');
    const modal = document.querySelector('[data-chart-modal]');
    const chartMessage = document.querySelector('[data-chart-message]');
    const chartMeta = document.querySelector('[data-chart-meta]');
    const previewPopover = document.querySelector('[data-preview-popover]');
    const previewCache = new Map();
    const taipeiTimeFormatter = new Intl.DateTimeFormat('zh-TW', {
        timeZone: 'Asia/Taipei',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
    const taipeiDateFormatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'Asia/Taipei',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    let realtimeTimer = null;
    let realtimeLoading = false;
    let trendChart = null;
    let klineChart = null;
    let previewChart = null;
    let previewTimer = null;
    let previewHideTimer = null;
    let previewTrigger = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function finiteNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function taipeiDateKey(value) {
        const date = new Date(value);
        if (!Number.isFinite(date.getTime())) return '';
        const parts = Object.fromEntries(
            taipeiDateFormatter.formatToParts(date)
                .filter(part => part.type !== 'literal')
                .map(part => [part.type, part.value]),
        );

        return `${parts.year}-${parts.month}-${parts.day}`;
    }

    function chartBusinessDay(time) {
        if (!time || typeof time !== 'object') return null;
        const year = Number(time.year);
        const month = Number(time.month);
        const day = Number(time.day);
        return [year, month, day].every(Number.isFinite) ? { year, month, day } : null;
    }

    function chartTimeLabel(time, daily = false, compact = false) {
        const businessDay = chartBusinessDay(time);
        if (businessDay) {
            if (compact) return `${businessDay.month}/${businessDay.day}`;
            return `${businessDay.year}/${String(businessDay.month).padStart(2, '0')}/${String(businessDay.day).padStart(2, '0')}`;
        }

        const timestamp = finiteNumber(time);
        if (timestamp === null) return '--';
        const date = new Date(timestamp * 1000);
        if (!Number.isFinite(date.getTime())) return '--';
        if (!daily) return taipeiTimeFormatter.format(date);

        const dateKey = taipeiDateKey(date);
        return compact ? dateKey.slice(5).replace('-', '/') : dateKey.replaceAll('-', '/');
    }

    function formatNumber(value, decimals = 2) {
        const number = finiteNumber(value);
        return number === null ? '--' : number.toLocaleString('zh-TW', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    function formatInteger(value) {
        const number = finiteNumber(value);
        return number === null ? '--' : Math.round(number).toLocaleString('zh-TW');
    }

    function formatPercent(value) {
        const number = finiteNumber(value);
        return number === null ? '--' : `${number > 0 ? '+' : ''}${formatNumber(number, 2)}%`;
    }

    function toneClass(value) {
        const number = finiteNumber(value);
        return number === null ? 'muted' : (number >= 0 ? 'positive' : 'negative');
    }

    function formatRevenue(value) {
        const number = finiteNumber(value);
        return number === null ? '--' : `${formatNumber(number / 100000, 2)} 億`;
    }

    function metricPeHtml(metric) {
        const pe = finiteNumber(metric?.trailingPe);
        const eps = finiteNumber(metric?.trailingEps);
        const quarterCount = Number(metric?.trailingQuarterCount || 0);
        const note = eps !== null
            ? `EPS ${formatNumber(eps, 2)} · ${escapeHtml(metric?.trailingPeriod || '')}`
            : (quarterCount > 0 ? `目前僅 ${quarterCount} 季` : '尚無季報資料');

        return `
            <div class="metric-stack">
                <span class="${pe === null ? 'metric-value muted' : 'pe-chip'}">${pe === null ? '--' : `${formatNumber(pe, 2)} 倍`}</span>
                <span class="metric-note">${note}</span>
            </div>
        `;
    }

    function metricRevenueHtml(revenue, emptyLabel) {
        const yoy = finiteNumber(revenue?.yoyPercent);
        return `
            <div class="metric-stack">
                <span class="metric-value">
                    ${formatRevenue(revenue?.revenueThousands)}
                    <span class="yoy ${toneClass(yoy)}">(${formatPercent(yoy)})</span>
                </span>
                <span class="metric-note">${escapeHtml(revenue?.period || emptyLabel)}</span>
            </div>
        `;
    }

    function renderRankingRows(rows) {
        rankingBody.innerHTML = rows.map(row => {
            const code = escapeHtml(row.stock_code);
            const name = escapeHtml(row.stock_name);
            const exchange = escapeHtml(row.exchange);
            const detailUrl = escapeHtml(row.detail_url);
            const liveDot = row.is_realtime ? '<span class="live-dot" title="盤中即時價"></span>' : '';
            const change = finiteNumber(row.price_change_amount);
            const changeText = change === null ? '--' : `${change > 0 ? '+' : ''}${formatNumber(change, 2)}`;

            return `
                <tr>
                    <td><span class="rank">${formatInteger(row.rank)}</span></td>
                    <td>
                        <div class="stock-main" tabindex="0" data-preview-stock data-stock-code="${code}" data-stock-name="${name}" data-exchange="${exchange}">
                            <a href="${detailUrl}">${code}</a>
                            <span class="stock-sub">${name} · ${exchange}</span>
                        </div>
                    </td>
                    <td><span class="live-price">${liveDot}${formatNumber(row.close_price, 2)}</span></td>
                    <td class="${toneClass(change)}">${changeText}</td>
                    <td class="${toneClass(row.price_change_percent)}">${formatPercent(row.price_change_percent)}</td>
                    <td>${metricPeHtml(row.metrics)}</td>
                    <td>${metricRevenueHtml(row.metrics?.latestRevenue, '尚無月營收')}</td>
                    <td>${metricRevenueHtml(row.metrics?.previousRevenue, '尚無前月資料')}</td>
                    <td>${formatInteger(row.volume_lots)}</td>
                    <td>${escapeHtml(row.trade_date || '--')}</td>
                    <td><button class="realtime-chart-button" type="button" data-open-intraday data-stock-code="${code}" data-stock-name="${name}" data-exchange="${exchange}" data-previous-close="${escapeHtml(row.previous_close_price ?? '')}">走勢／K</button></td>
                    <td><a class="detail-link" href="${detailUrl}">日 K</a></td>
                </tr>
            `;
        }).join('');
    }

    function updateSummary(summary) {
        if (!summary) return;
        ['total', 'up', 'down', 'flat'].forEach(key => {
            const element = document.querySelector(`[data-summary="${key}"]`);
            if (element) element.textContent = formatInteger(summary[key]);
        });
        ['maxChange', 'minChange'].forEach(key => {
            const element = document.querySelector(`[data-summary="${key}"]`);
            if (element) element.textContent = formatPercent(summary[key]);
        });
    }

    function updateMarketStatus(market, servedAt = null, source = null) {
        marketStatusElement.textContent = market?.label || '--';
        marketStatusElement.classList.toggle('live', Boolean(market?.isOpen));
        if (market?.isOpen) {
            const time = servedAt ? new Date(servedAt).toLocaleTimeString('zh-TW', { hour12: false }) : '--';
            refreshStatusElement.textContent = `每 15 秒更新 · ${source?.label || '即時報價'} · ${time}`;
        } else {
            refreshStatusElement.textContent = '盤後停止即時更新';
        }
    }

    async function refreshRealtime() {
        if (realtimeLoading) return;
        realtimeLoading = true;
        refreshStatusElement.textContent = '更新即時排行中…';

        try {
            const url = new URL(realtimeUrl, window.location.origin);
            const current = new URLSearchParams(window.location.search);
            ['q', 'sort', 'direction', 'per_page', 'page'].forEach(key => {
                if (current.has(key)) url.searchParams.set(key, current.get(key));
            });
            const response = await fetch(url.toString(), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            updateMarketStatus(payload.market, payload.servedAt, payload.source);
            if (Array.isArray(payload.rows) && payload.rows.length) {
                renderRankingRows(payload.rows);
                updateSummary(payload.summary);
                document.querySelector('[data-price-date]').textContent = payload.rows[0]?.trade_date || '--';
            }
            if (payload.market?.isOpen === false && realtimeTimer) {
                clearInterval(realtimeTimer);
                realtimeTimer = null;
            }
        } catch (error) {
            refreshStatusElement.textContent = '即時排行暫時讀取失敗，15 秒後重試';
        } finally {
            realtimeLoading = false;
        }
    }

    function scheduleRealtime(market) {
        updateMarketStatus(market);
        if (!market?.isOpen) return;
        refreshRealtime();
        realtimeTimer = setInterval(refreshRealtime, 15000);
    }

    function chartOptions(element, options = {}) {
        const fallbackElement = element.parentElement;
        const daily = options.daily === true;
        return {
            width: element.clientWidth || fallbackElement?.clientWidth || 640,
            height: element.clientHeight || fallbackElement?.clientHeight || 360,
            layout: { background: { color: '#ffffff' }, textColor: '#334155' },
            grid: { vertLines: { color: '#eef3f9' }, horzLines: { color: '#eef3f9' } },
            crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
            rightPriceScale: { borderColor: '#cbd8e7' },
            timeScale: {
                borderColor: '#cbd8e7',
                timeVisible: true,
                secondsVisible: false,
                rightOffset: 2,
                tickMarkFormatter: time => chartTimeLabel(time, daily, true),
            },
            localization: {
                locale: 'zh-TW',
                timeFormatter: time => chartTimeLabel(time, daily, false),
            },
        };
    }

    function destroyIntradayCharts() {
        if (trendChart) trendChart.remove();
        if (klineChart) klineChart.remove();
        trendChart = null;
        klineChart = null;
        document.querySelector('[data-chart-panel="trend"]').innerHTML = '';
        document.querySelector('[data-chart-panel="kline"]').innerHTML = '';
    }

    function normalizeIntradayPoints(points, expectedDate) {
        const byMinute = new Map();
        (Array.isArray(points) ? [...points] : [])
            .sort((left, right) => Number(left?.time) - Number(right?.time))
            .forEach(point => {
                const time = finiteNumber(point?.time);
                const price = finiteNumber(point?.price);
                if (time === null || price === null) return;
                if (expectedDate && taipeiDateKey(time * 1000) !== expectedDate) return;

                const minute = Math.floor(time / 60) * 60;
                const open = finiteNumber(point?.open) ?? price;
                const high = finiteNumber(point?.high) ?? price;
                const low = finiteNumber(point?.low) ?? price;
                const volume = finiteNumber(point?.volume);
                const existing = byMinute.get(minute);
                byMinute.set(minute, {
                    time: minute,
                    price,
                    open: existing?.open ?? open,
                    high: existing ? Math.max(existing.high, high, price) : Math.max(high, price),
                    low: existing ? Math.min(existing.low, low, price) : Math.min(low, price),
                    volume,
                });
            });

        return [...byMinute.values()].sort((left, right) => left.time - right.time).slice(-500);
    }

    function renderChartMeta(points, previousClose) {
        const lows = points.map(point => finiteNumber(point.low) ?? finiteNumber(point.price)).filter(value => value !== null);
        const highs = points.map(point => finiteNumber(point.high) ?? finiteNumber(point.price)).filter(value => value !== null);
        const latest = finiteNumber(points.at(-1)?.price);
        const items = [
            ['低', lows.length ? Math.min(...lows) : null],
            ['高', highs.length ? Math.max(...highs) : null],
            ['最新', latest],
            ['昨收', previousClose],
        ];
        chartMeta.innerHTML = items
            .filter(([, value]) => value !== null)
            .map(([label, value]) => `<span class="chart-meta__item">${label} ${formatNumber(value, 2)}</span>`)
            .join('');
    }

    function renderIntradayCharts(points, context = {}) {
        destroyIntradayCharts();
        const trendElement = document.querySelector('[data-chart-panel="trend"]');
        const klineElement = document.querySelector('[data-chart-panel="kline"]');
        const cleanPoints = normalizeIntradayPoints(points, context.date || '');
        const previousClose = finiteNumber(context.previousClose);
        if (cleanPoints.length < 2) {
            chartMeta.innerHTML = '';
            chartMessage.hidden = false;
            chartMessage.textContent = '今天目前沒有足夠的盤中走勢資料。';
            return;
        }

        const last = finiteNumber(cleanPoints.at(-1).price);
        const baseline = previousClose ?? finiteNumber(cleanPoints[0].price);
        const lineColor = last > baseline ? '#dc2626' : (last < baseline ? '#16a34a' : '#0ea5e9');
        const lineFill = last > baseline
            ? 'rgba(220, 38, 38, 0.24)'
            : (last < baseline ? 'rgba(22, 163, 74, 0.24)' : 'rgba(14, 165, 233, 0.22)');
        renderChartMeta(cleanPoints, previousClose);
        trendChart = LightweightCharts.createChart(trendElement, chartOptions(trendElement));
        const area = trendChart.addAreaSeries({
            lineColor,
            topColor: lineFill,
            bottomColor: 'rgba(255, 255, 255, 0.02)',
            lineWidth: 3,
            priceLineVisible: false,
        });
        area.setData(cleanPoints.map(point => ({ time: Number(point.time), value: Number(point.price) })));
        if (previousClose !== null) {
            area.createPriceLine({
                price: previousClose,
                color: 'rgba(100, 116, 139, 0.58)',
                lineWidth: 1,
                lineStyle: LightweightCharts.LineStyle.Dashed,
                axisLabelVisible: true,
                title: '昨收',
            });
        }
        trendChart.timeScale().fitContent();

        const candles = cleanPoints.map(point => ({
            time: Number(point.time),
            open: Number(point.open),
            high: Number(point.high),
            low: Number(point.low),
            close: Number(point.price),
            volume: finiteNumber(point.volume),
        }));
        klineChart = LightweightCharts.createChart(klineElement, chartOptions(klineElement));
        if (candles.length) {
            const candleSeries = klineChart.addCandlestickSeries({
                upColor: '#dc2626',
                downColor: '#15803d',
                borderUpColor: '#dc2626',
                borderDownColor: '#15803d',
                wickUpColor: '#dc2626',
                wickDownColor: '#15803d',
                priceLineVisible: false,
            });
            candleSeries.setData(candles);
            if (previousClose !== null) {
                candleSeries.createPriceLine({
                    price: previousClose,
                    color: 'rgba(100, 116, 139, 0.58)',
                    lineWidth: 1,
                    lineStyle: LightweightCharts.LineStyle.Dashed,
                    axisLabelVisible: true,
                    title: '昨收',
                });
            }
            const volumeRows = candles.filter(row => row.volume !== null);
            if (volumeRows.length) {
                const volumeSeries = klineChart.addHistogramSeries({
                    priceFormat: { type: 'volume' },
                    priceScaleId: 'volume',
                });
                volumeSeries.setData(volumeRows.map(row => ({
                    time: row.time,
                    value: row.volume,
                    color: row.close >= row.open ? 'rgba(220, 38, 38, 0.34)' : 'rgba(21, 128, 61, 0.34)',
                })));
                klineChart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.8, bottom: 0 } });
                klineChart.priceScale('right').applyOptions({ scaleMargins: { top: 0.05, bottom: 0.24 } });
            }
            klineChart.timeScale().fitContent();
        } else {
            klineElement.innerHTML = '<div class="chart-message">目前來源只有成交走勢，尚無完整分鐘 OHLC 可畫即時 K 線。</div>';
        }
        chartMessage.hidden = true;
    }

    async function openIntraday(button) {
        const code = button.dataset.stockCode;
        const name = button.dataset.stockName;
        const previousClose = finiteNumber(button.dataset.previousClose);
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        activateChartTab('trend');
        document.querySelector('[data-chart-title]').textContent = `${code} ${name} · 盤中即時圖`;
        document.querySelector('[data-chart-subtitle]').textContent = '讀取盤中資料中…';
        chartMeta.innerHTML = '';
        chartMessage.hidden = false;
        chartMessage.textContent = '讀取盤中資料中…';
        destroyIntradayCharts();

        try {
            const url = intradayUrlTemplate.replace('__CODE__', encodeURIComponent(code));
            const response = await fetch(url, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            const points = payload.series?.[code] || [];
            document.querySelector('[data-chart-subtitle]').textContent =
                `${payload.date || '--'} · ${payload.source?.label || '台股分時'} · ${payload.market?.label || ''}`;
            renderIntradayCharts(points, {
                date: payload.date || '',
                previousClose,
            });
        } catch (error) {
            chartMessage.hidden = false;
            chartMessage.textContent = '即時圖暫時讀取失敗，請稍後再試。';
        }
    }

    function closeIntraday() {
        modal.hidden = true;
        document.body.style.overflow = '';
        chartMeta.innerHTML = '';
        destroyIntradayCharts();
    }

    function activateChartTab(tab) {
        document.querySelectorAll('[data-chart-tab]').forEach(button => {
            button.classList.toggle('active', button.dataset.chartTab === tab);
        });
        document.querySelectorAll('[data-chart-panel]').forEach(panel => {
            panel.hidden = panel.dataset.chartPanel !== tab;
        });
        requestAnimationFrame(() => {
            const chart = tab === 'trend' ? trendChart : klineChart;
            const panel = document.querySelector(`[data-chart-panel="${tab}"]`);
            if (chart && panel) chart.applyOptions({ width: panel.clientWidth, height: panel.clientHeight });
        });
    }

    function positionPreview(trigger) {
        const rect = trigger.getBoundingClientRect();
        const width = Math.min(410, window.innerWidth - 24);
        const height = 290;
        let left = Math.min(rect.left, window.innerWidth - width - 12);
        left = Math.max(12, left);
        let top = rect.bottom + 8;
        if (top + height > window.innerHeight - 12) top = Math.max(12, rect.top - height - 8);
        previewPopover.style.left = `${left}px`;
        previewPopover.style.top = `${top}px`;
    }

    function renderPreview(payload) {
        if (previewChart) previewChart.remove();
        const element = document.querySelector('[data-preview-chart]');
        element.innerHTML = '';
        document.querySelector('[data-preview-title]').textContent = `${payload.stockCode} ${payload.stockName} · 近 10 日 K 線`;
        const rows = Array.isArray(payload.rows) ? payload.rows : [];
        document.querySelector('[data-preview-period]').textContent = rows.length
            ? `${rows[0].time} ～ ${rows.at(-1).time}`
            : '暫無資料';
        if (!rows.length) {
            element.innerHTML = '<div class="chart-message">目前沒有日 K 資料。</div>';
            return;
        }
        const previewOptions = chartOptions(element, { daily: true });
        previewChart = LightweightCharts.createChart(element, {
            ...previewOptions,
            timeScale: {
                ...previewOptions.timeScale,
                timeVisible: false,
                rightOffset: 0,
                barSpacing: 24,
                minBarSpacing: 16,
            },
        });
        const candles = previewChart.addCandlestickSeries({
            upColor: '#dc2626',
            downColor: '#15803d',
            borderUpColor: '#dc2626',
            borderDownColor: '#15803d',
            wickUpColor: '#dc2626',
            wickDownColor: '#15803d',
        });
        candles.setData(rows.map(row => ({
            time: row.time,
            open: Number(row.open),
            high: Number(row.high),
            low: Number(row.low),
            close: Number(row.close),
        })));
        previewChart.timeScale().fitContent();
    }

    async function showPreview(trigger) {
        clearTimeout(previewHideTimer);
        previewTrigger = trigger;
        positionPreview(trigger);
        previewPopover.hidden = false;
        document.querySelector('[data-preview-title]').textContent = `${trigger.dataset.stockCode} ${trigger.dataset.stockName} · 近 10 日 K 線`;
        document.querySelector('[data-preview-period]').textContent = '讀取中';
        document.querySelector('[data-preview-chart]').innerHTML = '<div class="chart-message">讀取近 10 日 K 線中…</div>';
        if (previewChart) {
            previewChart.remove();
            previewChart = null;
        }

        const key = `${trigger.dataset.exchange}|${trigger.dataset.stockCode}`;
        try {
            let payload = previewCache.get(key);
            if (!payload) {
                const url = new URL(previewUrlTemplate.replace('__CODE__', encodeURIComponent(trigger.dataset.stockCode)), window.location.origin);
                url.searchParams.set('exchange', trigger.dataset.exchange);
                const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
                if (!response.ok) throw new Error(`HTTP ${response.status}`);
                payload = await response.json();
                previewCache.set(key, payload);
            }
            if (previewTrigger === trigger && !previewPopover.hidden) renderPreview(payload);
        } catch (error) {
            document.querySelector('[data-preview-chart]').innerHTML = '<div class="chart-message">近 10 日 K 線暫時讀取失敗。</div>';
        }
    }

    function schedulePreview(trigger) {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(() => showPreview(trigger), 180);
    }

    function hidePreviewSoon() {
        clearTimeout(previewTimer);
        previewHideTimer = setTimeout(() => {
            previewPopover.hidden = true;
            previewTrigger = null;
            if (previewChart) {
                previewChart.remove();
                previewChart = null;
            }
        }, 140);
    }

    document.addEventListener('click', event => {
        const intradayButton = event.target.closest('[data-open-intraday]');
        if (intradayButton) openIntraday(intradayButton);
    });

    document.addEventListener('mouseover', event => {
        const trigger = event.target.closest('[data-preview-stock]');
        if (trigger && !trigger.contains(event.relatedTarget)) schedulePreview(trigger);
    });

    document.addEventListener('mouseout', event => {
        const trigger = event.target.closest('[data-preview-stock]');
        if (trigger && !trigger.contains(event.relatedTarget)) hidePreviewSoon();
    });

    document.addEventListener('focusin', event => {
        const trigger = event.target.closest('[data-preview-stock]');
        if (trigger) schedulePreview(trigger);
    });

    document.addEventListener('focusout', event => {
        const trigger = event.target.closest('[data-preview-stock]');
        if (trigger && !trigger.contains(event.relatedTarget)) hidePreviewSoon();
    });

    previewPopover.addEventListener('mouseenter', () => clearTimeout(previewHideTimer));
    previewPopover.addEventListener('mouseleave', hidePreviewSoon);
    document.querySelector('[data-chart-close]').addEventListener('click', closeIntraday);
    modal.addEventListener('click', event => {
        if (event.target === modal) closeIntraday();
    });
    document.querySelectorAll('[data-chart-tab]').forEach(button => {
        button.addEventListener('click', () => activateChartTab(button.dataset.chartTab));
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !modal.hidden) closeIntraday();
    });
    window.addEventListener('resize', () => {
        if (previewTrigger && !previewPopover.hidden) positionPreview(previewTrigger);
        const trendPanel = document.querySelector('[data-chart-panel="trend"]');
        const klinePanel = document.querySelector('[data-chart-panel="kline"]');
        if (trendChart) trendChart.applyOptions({ width: trendPanel.clientWidth, height: trendPanel.clientHeight });
        if (klineChart) klineChart.applyOptions({ width: klinePanel.clientWidth, height: klinePanel.clientHeight });
        if (previewChart) {
            const previewPanel = document.querySelector('[data-preview-chart]');
            previewChart.applyOptions({ width: previewPanel.clientWidth, height: previewPanel.clientHeight });
        }
    });

    scheduleRealtime(initialMarket);
</script>
</body>
</html>
