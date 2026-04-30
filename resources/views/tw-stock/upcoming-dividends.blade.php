<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>近 30 天除息股票</title>
    <style>
        :root {
            --bg: #eef3f8;
            --panel: rgba(255, 255, 255, 0.9);
            --panel-strong: #ffffff;
            --line: #d7e0eb;
            --text: #0f172a;
            --muted: #64748b;
            --red: #dc2626;
            --green: #047857;
            --amber: #b45309;
            --blue: #2563eb;
            --ink: #111827;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(120deg, rgba(220, 38, 38, 0.08), transparent 34%),
                linear-gradient(240deg, rgba(37, 99, 235, 0.10), transparent 38%),
                linear-gradient(180deg, #f8fbff 0%, var(--bg) 100%);
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        body::before {
            position: fixed;
            inset: 0;
            z-index: -1;
            content: "";
            background-image:
                linear-gradient(rgba(15, 23, 42, 0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(15, 23, 42, 0.045) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: linear-gradient(180deg, rgba(0, 0, 0, 0.82), rgba(0, 0, 0, 0.12));
            animation: grid-drift 20s linear infinite;
        }

        @keyframes grid-drift {
            from {
                background-position: 0 0;
            }
            to {
                background-position: 68px 34px;
            }
        }

        .shell {
            width: min(1500px, calc(100vw - 32px));
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
            line-height: 1.45;
        }

        .nav-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .nav-actions a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: #334155;
            background: rgba(255, 255, 255, 0.84);
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 8px 20px rgba(15, 23, 42, 0.06);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .summary-card,
        .table-panel {
            border: 1px solid rgba(215, 224, 235, 0.92);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 14px 36px rgba(15, 23, 42, 0.08);
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
            background: linear-gradient(90deg, var(--red), var(--amber), var(--blue));
            opacity: 0.72;
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

        .table-panel {
            overflow: hidden;
        }

        .table-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px;
            border-bottom: 1px solid var(--line);
            background: rgba(248, 250, 252, 0.78);
        }

        .panel-title {
            margin: 0;
            font-size: 18px;
            line-height: 1.3;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1160px;
            border-collapse: collapse;
            table-layout: fixed;
            font-variant-numeric: tabular-nums;
        }

        th,
        td {
            padding: 13px 14px;
            border-bottom: 1px solid #e7edf5;
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            color: #475569;
            background: #f8fafc;
            font-size: 12px;
            font-weight: 900;
        }

        th:first-child,
        td:first-child {
            width: 210px;
            text-align: left;
        }

        tbody tr {
            background: rgba(255, 255, 255, 0.58);
            transition: background 160ms ease, transform 160ms ease;
        }

        tbody tr:hover {
            background: #ffffff;
            transform: translateY(-1px);
        }

        .stock {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 58px;
            min-height: 30px;
            padding: 0 10px;
            border: 1px solid #fecaca;
            border-radius: 8px;
            color: #991b1b;
            background: #fef2f2;
            font-weight: 900;
        }

        .name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            font-weight: 900;
        }

        .market {
            margin-top: 3px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
        }

        .countdown {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 62px;
            min-height: 30px;
            border-radius: 8px;
            color: #7c2d12;
            background: #ffedd5;
            border: 1px solid #fed7aa;
            font-weight: 900;
        }

        .countdown.today {
            color: #991b1b;
            background: #fee2e2;
            border-color: #fecaca;
        }

        .yield-cell {
            color: var(--red);
            font-weight: 900;
        }

        .yield-bar {
            width: 100%;
            height: 7px;
            margin-top: 8px;
            overflow: hidden;
            border-radius: 999px;
            background: #e2e8f0;
        }

        .yield-bar span {
            display: block;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, #f97316, #dc2626);
        }

        .fill-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 76px;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 900;
        }

        .fill-badge.filled {
            color: #065f46;
            background: #ecfdf5;
            border: 1px solid #a7f3d0;
        }

        .fill-badge.unfilled {
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
        }

        .fill-badge.no-history {
            color: #475569;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
        }

        .empty {
            padding: 42px 18px;
            color: var(--muted);
            text-align: center;
            font-weight: 800;
        }

        @media (max-width: 980px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .summary-grid {
                grid-template-columns: 1fr 1fr;
            }

            .nav-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 640px) {
            .shell {
                width: min(100vw - 16px, 1500px);
                padding-top: 18px;
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
    </style>
</head>
<body>
@php
    $formatPrice = static fn ($value): string => $value === null ? 'N/A' : number_format((float) $value, 2);
    $formatDividend = static fn ($value): string => $value === null ? 'N/A' : number_format((float) $value, 4);
    $formatYield = static fn ($value): string => $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
    $fillLabel = static function ($row): string {
        if ($row->last_fill_days !== null) {
            return number_format((int) $row->last_fill_days) . ' 天';
        }

        return match ($row->last_fill_status) {
            'unfilled' => '未填息',
            'no_history' => '無歷史',
            default => 'N/A',
        };
    };
    $fillClass = static fn ($row): string => str_replace('_', '-', (string) $row->last_fill_status);
@endphp
<main class="shell">
    <header class="topbar">
        <div>
            <h1>近 30 天除息股票</h1>
            <div class="meta">
                顯示區間：{{ $today->toDateString() }} ~ {{ $endDate->toDateString() }}，
                除息隔天自動不顯示；殖利率以本次現金股利 ÷ 最新收盤價估算
            </div>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人進出</a>
        </nav>
    </header>

    <section class="summary-grid">
        <article class="summary-card">
            <div class="label">符合條件</div>
            <div class="value">{{ number_format($totalRows) }}</div>
            <div class="sub">僅納入 4 碼一般股票且現金股利大於 0</div>
        </article>
        <article class="summary-card">
            <div class="label">最近除息日</div>
            <div class="value">{{ $nextExDate?->toDateString() ?? 'N/A' }}</div>
            <div class="sub">資料每日由排程更新</div>
        </article>
        <article class="summary-card">
            <div class="label">最高估算殖利率</div>
            <div class="value">{{ $formatYield($maxYieldRow?->dividend_yield_percent) }}</div>
            <div class="sub">{{ $maxYieldRow ? $maxYieldRow->stock_code . ' ' . $maxYieldRow->stock_name : 'N/A' }}</div>
        </article>
        <article class="summary-card">
            <div class="label">最後抓取</div>
            <div class="value">{{ $lastFetchedAt?->format('m-d H:i') ?? 'N/A' }}</div>
            <div class="sub">TWSE / TPEx / FinMind 歷史價格</div>
        </article>
    </section>

    <section class="table-panel">
        <div class="table-head">
            <h2 class="panel-title">除息清單</h2>
            <div class="meta">股價單位：元；股利單位：元/股；上次填息天數以交易日計</div>
        </div>
        @if ($rows->isEmpty())
            <div class="empty">目前沒有未來 30 天內符合條件的除息股票。</div>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>股票</th>
                            <th>股價</th>
                            <th>股利發放</th>
                            <th>殖利率</th>
                            <th>除息日</th>
                            <th>幾天後</th>
                            <th>上次填息天數</th>
                            <th>上次除息</th>
                            <th>價格日</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php
                                $yieldWidth = min(max((float) ($row->dividend_yield_percent ?? 0) * 10, 4), 100);
                            @endphp
                            <tr>
                                <td>
                                    <div class="stock">
                                        <span class="code">{{ $row->stock_code }}</span>
                                        <div>
                                            <div class="name">{{ $row->stock_name }}</div>
                                            <div class="market">{{ $row->exchange === 'TWSE' ? '上市' : '上櫃' }} · {{ $row->ex_dividend_type }}</div>
                                        </div>
                                    </div>
                                </td>
                                <td>{{ $formatPrice($row->latest_close_price) }}</td>
                                <td>{{ $formatDividend($row->cash_dividend) }}</td>
                                <td class="yield-cell">
                                    {{ $formatYield($row->dividend_yield_percent) }}
                                    <div class="yield-bar"><span style="width: {{ $yieldWidth }}%"></span></div>
                                </td>
                                <td>{{ $row->ex_dividend_date->toDateString() }}</td>
                                <td>
                                    <span class="countdown {{ (int) $row->days_until_ex_dividend === 0 ? 'today' : '' }}">
                                        {{ (int) $row->days_until_ex_dividend === 0 ? '今天' : number_format((int) $row->days_until_ex_dividend) . ' 天' }}
                                    </span>
                                </td>
                                <td>
                                    <span class="fill-badge {{ $fillClass($row) }}">
                                        {{ $fillLabel($row) }}
                                    </span>
                                </td>
                                <td>{{ $row->last_ex_dividend_date?->toDateString() ?? 'N/A' }}</td>
                                <td>{{ $row->latest_price_date?->toDateString() ?? 'N/A' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</main>
</body>
</html>
