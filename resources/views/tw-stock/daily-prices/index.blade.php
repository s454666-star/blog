<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台股每日漲幅排行</title>
    <style>
        :root {
            --bg: #eef4f8;
            --panel: #ffffff;
            --panel-2: #f8fbfd;
            --line: #d7e2ea;
            --text: #152033;
            --muted: #66758a;
            --dark: #152238;
            --red: #dc2626;
            --green: #15803d;
            --blue: #2563eb;
            --amber: #b45309;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(rgba(21, 34, 56, 0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(21, 34, 56, 0.045) 1px, transparent 1px),
                radial-gradient(circle at 18% 8%, rgba(37, 99, 235, 0.12), transparent 28%),
                #eef4f8;
            background-size: 34px 34px, 34px 34px, auto, auto;
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        .shell {
            width: min(1480px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 30px 0 46px;
        }

        .topbar {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 18px;
        }

        h1 {
            margin: 0;
            font-size: 32px;
            line-height: 1.18;
        }

        .meta {
            margin-top: 8px;
            color: var(--muted);
            font-size: 14px;
        }

        .nav-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .nav-actions a,
        .sort-link,
        .detail-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: #334155;
            background: rgba(255, 255, 255, 0.86);
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
            transition: transform 0.16s ease, border-color 0.16s ease, background 0.16s ease;
        }

        .nav-actions a:hover,
        .sort-link:hover,
        .detail-link:hover {
            transform: translateY(-1px);
            border-color: #9fb2c5;
        }

        .nav-actions a.active,
        .sort-link.active {
            color: #fff;
            border-color: var(--dark);
            background: var(--dark);
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
            border: 1px solid rgba(148, 163, 184, 0.42);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.9);
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(8px);
        }

        .summary-card {
            min-height: 108px;
            padding: 16px;
            border-top: 4px solid #d7e2ea;
        }

        .summary-card.hot { border-top-color: var(--red); }
        .summary-card.cool { border-top-color: var(--green); }
        .summary-card.info { border-top-color: var(--blue); }

        .label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
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
            padding: 14px;
            margin-bottom: 14px;
        }

        .controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
        }

        input,
        select {
            min-height: 38px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 0 12px;
            color: var(--text);
            font: inherit;
            font-size: 14px;
            font-weight: 700;
            outline: none;
        }

        input:focus,
        select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .sort-links {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .table-panel {
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: var(--panel);
        }

        th,
        td {
            padding: 13px 12px;
            border-bottom: 1px solid #e7edf3;
            text-align: right;
            white-space: nowrap;
            font-size: 14px;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            color: #475569;
            background: #f8fbfd;
            font-size: 12px;
            font-weight: 900;
        }

        td {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        tr:hover td {
            background: #f9fbff;
        }

        th:first-child,
        td:first-child,
        th:nth-child(2),
        td:nth-child(2) {
            text-align: left;
        }

        .rank {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 34px;
            min-height: 30px;
            border-radius: 8px;
            color: #fff;
            background: linear-gradient(135deg, #172554, #2563eb);
            font-weight: 900;
        }

        .stock-main {
            display: flex;
            flex-direction: column;
            gap: 3px;
            min-width: 132px;
        }

        .stock-main a {
            color: #0f172a;
            text-decoration: none;
            font-size: 16px;
            font-weight: 900;
        }

        .stock-main a:hover {
            color: var(--blue);
        }

        .stock-sub {
            color: var(--muted);
            font-size: 12px;
        }

        .pager {
            padding: 18px 14px;
            background: #f8fbfd;
        }

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
            .shell {
                width: min(100% - 20px, 1480px);
                padding-top: 18px;
            }

            h1 { font-size: 25px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .value { font-size: 22px; }
            .table-panel { overflow-x: auto; }
            th, td { padding: 11px 10px; }
        }
@include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
@php
    $fmt = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals);
    $pct = fn ($value): string => $value === null ? '--' : (($value > 0 ? '+' : '') . number_format((float) $value, 2) . '%');
    $pctClass = fn ($value): string => $value === null ? 'muted' : ((float) $value >= 0 ? 'positive' : 'negative');
    $sortUrl = fn (string $key): string => request()->fullUrlWithQuery([
        'sort' => $key,
        'direction' => $sort === $key && $direction === 'desc' ? 'asc' : 'desc',
        'page' => null,
    ]);
@endphp

<main class="shell">
    <section class="topbar">
        <div>
            <h1>台股每日漲幅排行</h1>
            <div class="meta">價格日：{{ $latestDate ?? '--' }}，漲紅跌綠，點股票可看 K 線明細。</div>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a class="active" href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
            <a href="{{ route('tw-stock.taiex-futures.kline') }}">台指期K線</a>
        </nav>
    </section>

    <section class="summary-grid">
        <div class="summary-card info">
            <div class="label">排行股票</div>
            <div class="value">{{ number_format($summary['total']) }}</div>
            <div class="sub">目前最新交易日有漲跌幅資料的股票數</div>
        </div>
        <div class="summary-card hot">
            <div class="label">上漲</div>
            <div class="value positive">{{ number_format($summary['up']) }}</div>
            <div class="sub">收盤價高於前一交易日</div>
        </div>
        <div class="summary-card cool">
            <div class="label">下跌</div>
            <div class="value negative">{{ number_format($summary['down']) }}</div>
            <div class="sub">收盤價低於前一交易日</div>
        </div>
        <div class="summary-card">
            <div class="label">平盤</div>
            <div class="value">{{ number_format($summary['flat']) }}</div>
            <div class="sub">漲跌幅等於 0</div>
        </div>
        <div class="summary-card hot">
            <div class="label">最大漲幅</div>
            <div class="value positive">{{ $pct($summary['maxChange']) }}</div>
            <div class="sub">當日漲幅第一名</div>
        </div>
        <div class="summary-card cool">
            <div class="label">最大跌幅</div>
            <div class="value negative">{{ $pct($summary['minChange']) }}</div>
            <div class="sub">當日跌幅最大</div>
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
        <table>
            <thead>
            <tr>
                <th>排名</th>
                <th>股票</th>
                <th>收盤</th>
                <th>漲跌</th>
                <th>漲幅</th>
                <th>開盤</th>
                <th>最高</th>
                <th>最低</th>
                <th>成交量(張)</th>
                <th>交易日</th>
                <th>明細</th>
            </tr>
            </thead>
            <tbody>
            @foreach($rows as $row)
                <tr>
                    <td><span class="rank">{{ $rows->firstItem() + $loop->index }}</span></td>
                    <td>
                        <div class="stock-main">
                            <a href="{{ route('tw-stock.daily-prices.show', ['stockCode' => $row->stock_code, 'exchange' => $row->exchange]) }}">{{ $row->stock_code }}</a>
                            <span class="stock-sub">{{ $row->stock_name }} · {{ $row->exchange }}</span>
                        </div>
                    </td>
                    <td>{{ $fmt($row->close_price, 2) }}</td>
                    <td class="{{ $pctClass($row->price_change_amount) }}">{{ $row->price_change_amount > 0 ? '+' : '' }}{{ $fmt($row->price_change_amount, 2) }}</td>
                    <td class="{{ $pctClass($row->price_change_percent) }}">{{ $pct($row->price_change_percent) }}</td>
                    <td>{{ $fmt($row->open_price, 2) }}</td>
                    <td>{{ $fmt($row->high_price, 2) }}</td>
                    <td>{{ $fmt($row->low_price, 2) }}</td>
                    <td>{{ number_format((int) $row->volume_lots) }}</td>
                    <td>{{ $row->trade_date?->toDateString() }}</td>
                    <td><a class="detail-link" href="{{ route('tw-stock.daily-prices.show', ['stockCode' => $row->stock_code, 'exchange' => $row->exchange]) }}">K 線</a></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <div class="pager">{{ $rows->links('tw-stock.partials.pagination') }}</div>
    </section>
</main>
</body>
</html>
