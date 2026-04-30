<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台股法人進出</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #f5f7fb;
            --panel: #ffffff;
            --line: #d8dee9;
            --text: #172033;
            --muted: #667085;
            --foreign: #2563eb;
            --trust: #c47f17;
            --positive: #047857;
            --negative: #dc2626;
            --violet: #6d28d9;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background: var(--bg);
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        .shell {
            width: min(1480px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 28px 0 42px;
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
            font-size: 30px;
            line-height: 1.2;
            letter-spacing: 0;
        }

        .meta {
            margin-top: 8px;
            color: var(--muted);
            font-size: 14px;
        }

        .segments {
            display: inline-flex;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
        }

        .segments a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 68px;
            min-height: 38px;
            padding: 0 14px;
            border-right: 1px solid var(--line);
            color: #334155;
            text-decoration: none;
            font-weight: 700;
            font-size: 14px;
        }

        .segments a:last-child {
            border-right: 0;
        }

        .segments a.active {
            color: #ffffff;
            background: #1f2937;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }

        .summary-card,
        .chart-panel,
        .table-panel {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
        }

        .summary-card {
            padding: 16px;
            min-height: 118px;
        }

        .label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
        }

        .value {
            margin-top: 9px;
            font-size: 26px;
            line-height: 1.1;
            font-weight: 800;
            font-variant-numeric: tabular-nums;
        }

        .sub {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .positive {
            color: var(--positive);
        }

        .negative {
            color: var(--negative);
        }

        .charts {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(360px, 0.85fr);
            gap: 14px;
            margin-bottom: 14px;
        }

        .chart-panel {
            padding: 16px;
        }

        .panel-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 12px;
        }

        .panel-title {
            margin: 0;
            font-size: 18px;
            line-height: 1.3;
        }

        .legend-note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
            text-align: right;
        }

        .chart-box {
            position: relative;
            height: 430px;
            min-height: 430px;
        }

        .chart-box.compact {
            height: 430px;
            min-height: 430px;
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
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1120px;
            border-collapse: collapse;
            table-layout: fixed;
            font-variant-numeric: tabular-nums;
        }

        th,
        td {
            padding: 12px 14px;
            border-bottom: 1px solid #e7ebf2;
            text-align: right;
            vertical-align: middle;
            white-space: nowrap;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
            color: #475569;
            font-size: 12px;
            font-weight: 800;
        }

        th:first-child,
        td:first-child {
            text-align: left;
            width: 118px;
        }

        tbody tr:hover {
            background: #f8fafc;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 68px;
            min-height: 28px;
            padding: 0 10px;
            border-radius: 8px;
            border: 1px solid transparent;
            font-weight: 800;
            font-size: 13px;
        }

        .pill.buy {
            border-color: #a7f3d0;
            color: var(--positive);
            background: #ecfdf5;
        }

        .pill.sell {
            border-color: #fecaca;
            color: var(--negative);
            background: #fef2f2;
        }

        .pill.flat {
            border-color: #dbe3ef;
            color: #475569;
            background: #f8fafc;
        }

        .empty {
            padding: 34px;
            color: var(--muted);
            text-align: center;
        }

        @media (max-width: 1180px) {
            .topbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .summary-grid,
            .charts {
                grid-template-columns: 1fr 1fr;
            }

            .charts {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .shell {
                width: min(100vw - 16px, 1480px);
                padding-top: 18px;
            }

            h1 {
                font-size: 24px;
            }

            .summary-grid {
                grid-template-columns: 1fr;
            }

            .segments {
                width: 100%;
            }

            .segments a {
                flex: 1;
                min-width: 0;
            }

            .panel-head,
            .table-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .legend-note {
                text-align: left;
            }

            .chart-box,
            .chart-box.compact {
                height: 340px;
                min-height: 340px;
            }
        }
    </style>
</head>
<body>
@php
    $format100m = static function ($value): string {
        return $value === null ? 'N/A' : number_format((float) $value, 2);
    };

    $formatContracts = static function ($value): string {
        return $value === null ? 'N/A' : number_format((int) $value);
    };

    $directionClass = static function ($value): string {
        if ($value === null || (float) $value === 0.0) {
            return 'flat';
        }

        return (float) $value > 0 ? 'buy' : 'sell';
    };

    $directionLabel = static function ($value): string {
        if ($value === null) {
            return 'N/A';
        }

        if ((float) $value === 0.0) {
            return '持平';
        }

        return (float) $value > 0 ? '買超' : '賣超';
    };
@endphp
<main class="shell">
    <header class="topbar">
        <div>
            <h1>台股法人進出</h1>
            <div class="meta">
                資料範圍：{{ $firstStoredDate ?? 'N/A' }} ~ {{ $lastStoredDate ?? 'N/A' }}，
                共 {{ number_format($totalRows) }} 個交易日
            </div>
        </div>
        <nav class="segments" aria-label="天數切換">
            @foreach ($allowedDays as $allowedDay)
                <a href="{{ route('tw-stock.institutional-flows.index', ['days' => $allowedDay]) }}"
                   class="{{ $days === $allowedDay ? 'active' : '' }}">
                    {{ $allowedDay }}天
                </a>
            @endforeach
        </nav>
    </header>

    @if ($rows->isEmpty())
        <section class="table-panel">
            <div class="empty">目前沒有台股法人資料。</div>
        </section>
    @else
        <section class="summary-grid">
            <article class="summary-card">
                <div class="label">最新交易日</div>
                <div class="value">{{ $latest['date'] }}</div>
                <div class="sub">最後抓取：{{ $latest['fetched_at'] ?? 'N/A' }}</div>
            </article>
            <article class="summary-card">
                <div class="label">外資買賣超</div>
                <div class="value {{ ($latest['foreign_stock_net_100m'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                    {{ $format100m($latest['foreign_stock_net_100m']) }} 億
                </div>
                <div class="sub">累計現貨：{{ $format100m($latest['foreign_cumulative_100m']) }} 億</div>
            </article>
            <article class="summary-card">
                <div class="label">投信買賣超</div>
                <div class="value {{ ($latest['investment_trust_stock_net_100m'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                    {{ $format100m($latest['investment_trust_stock_net_100m']) }} 億
                </div>
                <div class="sub">累計現貨：{{ $format100m($latest['investment_trust_cumulative_100m']) }} 億</div>
            </article>
            <article class="summary-card">
                <div class="label">外資台指期淨未平倉</div>
                <div class="value {{ ($latest['foreign_txf_open_interest_net_contracts'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                    {{ $formatContracts($latest['foreign_txf_open_interest_net_contracts']) }}
                </div>
                <div class="sub">口數，正數為淨多、負數為淨空</div>
            </article>
            <article class="summary-card">
                <div class="label">投信台指期淨未平倉</div>
                <div class="value {{ ($latest['investment_trust_txf_open_interest_net_contracts'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                    {{ $formatContracts($latest['investment_trust_txf_open_interest_net_contracts']) }}
                </div>
                <div class="sub">口數，正數為淨多、負數為淨空</div>
            </article>
        </section>

        <section class="charts">
            <article class="chart-panel">
                <div class="panel-head">
                    <h2 class="panel-title">每日買賣超與累計現貨部位</h2>
                    <div class="legend-note">柱：每日買賣超；線：自 {{ $firstStoredDate }} 起累計</div>
                </div>
                <div class="chart-box">
                    <canvas id="stockFlowChart"></canvas>
                </div>
            </article>

            <article class="chart-panel">
                <div class="panel-head">
                    <h2 class="panel-title">臺股期貨淨未平倉</h2>
                    <div class="legend-note">外資 / 投信，單位：口</div>
                </div>
                <div class="chart-box compact">
                    <canvas id="openInterestChart"></canvas>
                </div>
            </article>
        </section>

        <section class="table-panel">
            <div class="table-head">
                <h2 class="panel-title">近 {{ $days }} 個交易日明細</h2>
                <div class="legend-note">買賣超與累計現貨單位：億元；淨未平倉單位：口</div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>日期</th>
                            <th>外資買賣超</th>
                            <th>外資方向</th>
                            <th>外資累計現貨</th>
                            <th>外資淨未平倉</th>
                            <th>投信買賣超</th>
                            <th>投信方向</th>
                            <th>投信累計現貨</th>
                            <th>投信淨未平倉</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows->reverse()->values() as $row)
                            <tr>
                                <td>{{ $row['date'] }}</td>
                                <td class="{{ ($row['foreign_stock_net_100m'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                                    {{ $format100m($row['foreign_stock_net_100m']) }}
                                </td>
                                <td>
                                    <span class="pill {{ $directionClass($row['foreign_stock_net_100m']) }}">
                                        {{ $directionLabel($row['foreign_stock_net_100m']) }}
                                    </span>
                                </td>
                                <td>{{ $format100m($row['foreign_cumulative_100m']) }}</td>
                                <td class="{{ ($row['foreign_txf_open_interest_net_contracts'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                                    {{ $formatContracts($row['foreign_txf_open_interest_net_contracts']) }}
                                </td>
                                <td class="{{ ($row['investment_trust_stock_net_100m'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                                    {{ $format100m($row['investment_trust_stock_net_100m']) }}
                                </td>
                                <td>
                                    <span class="pill {{ $directionClass($row['investment_trust_stock_net_100m']) }}">
                                        {{ $directionLabel($row['investment_trust_stock_net_100m']) }}
                                    </span>
                                </td>
                                <td>{{ $format100m($row['investment_trust_cumulative_100m']) }}</td>
                                <td class="{{ ($row['investment_trust_txf_open_interest_net_contracts'] ?? 0) >= 0 ? 'positive' : 'negative' }}">
                                    {{ $formatContracts($row['investment_trust_txf_open_interest_net_contracts']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</main>

@if ($rows->isNotEmpty())
<script>
    const chartData = @json($chartData);

    function signedBarColor(value, positiveColor, negativeColor) {
        if (value === null || Number(value) === 0) {
            return '#94a3b8';
        }

        return Number(value) > 0 ? positiveColor : negativeColor;
    }

    function formatNumber(value, digits = 2) {
        if (value === null || value === undefined) {
            return 'N/A';
        }

        return Number(value).toLocaleString('zh-TW', {
            maximumFractionDigits: digits,
            minimumFractionDigits: digits,
        });
    }

    const commonOptions = {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index',
            intersect: false,
        },
        plugins: {
            legend: {
                labels: {
                    usePointStyle: true,
                    boxWidth: 9,
                    color: '#334155',
                    font: {
                        weight: 700,
                    },
                },
            },
            tooltip: {
                callbacks: {
                    label(context) {
                        const suffix = context.dataset.unit || '';
                        return `${context.dataset.label}: ${formatNumber(context.parsed.y, context.dataset.digits ?? 2)}${suffix}`;
                    },
                },
            },
        },
        scales: {
            x: {
                grid: {
                    display: false,
                },
                ticks: {
                    maxRotation: 45,
                    minRotation: 0,
                    color: '#64748b',
                },
            },
        },
    };

    new Chart(document.getElementById('stockFlowChart'), {
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    type: 'bar',
                    label: '外資買賣超',
                    data: chartData.foreignNet,
                    unit: ' 億',
                    backgroundColor: chartData.foreignNet.map(value => signedBarColor(value, 'rgba(37, 99, 235, 0.78)', 'rgba(220, 38, 38, 0.72)')),
                    borderColor: chartData.foreignNet.map(value => signedBarColor(value, '#2563eb', '#dc2626')),
                    borderWidth: 1,
                    yAxisID: 'yDaily',
                },
                {
                    type: 'bar',
                    label: '投信買賣超',
                    data: chartData.investmentTrustNet,
                    unit: ' 億',
                    backgroundColor: chartData.investmentTrustNet.map(value => signedBarColor(value, 'rgba(196, 127, 23, 0.78)', 'rgba(248, 113, 113, 0.55)')),
                    borderColor: chartData.investmentTrustNet.map(value => signedBarColor(value, '#c47f17', '#ef4444')),
                    borderWidth: 1,
                    yAxisID: 'yDaily',
                },
                {
                    type: 'line',
                    label: '外資累計現貨',
                    data: chartData.foreignCumulative,
                    unit: ' 億',
                    borderColor: '#047857',
                    backgroundColor: '#047857',
                    tension: 0.24,
                    pointRadius: 2,
                    borderWidth: 2,
                    yAxisID: 'yCumulative',
                },
                {
                    type: 'line',
                    label: '投信累計現貨',
                    data: chartData.investmentTrustCumulative,
                    unit: ' 億',
                    borderColor: '#6d28d9',
                    backgroundColor: '#6d28d9',
                    tension: 0.24,
                    pointRadius: 2,
                    borderWidth: 2,
                    yAxisID: 'yCumulative',
                },
            ],
        },
        options: {
            ...commonOptions,
            scales: {
                ...commonOptions.scales,
                yDaily: {
                    position: 'left',
                    title: {
                        display: true,
                        text: '每日買賣超(億)',
                        color: '#475569',
                    },
                    grid: {
                        color: '#e2e8f0',
                    },
                    ticks: {
                        color: '#64748b',
                    },
                },
                yCumulative: {
                    position: 'right',
                    title: {
                        display: true,
                        text: '累計現貨(億)',
                        color: '#475569',
                    },
                    grid: {
                        drawOnChartArea: false,
                    },
                    ticks: {
                        color: '#64748b',
                    },
                },
            },
        },
    });

    new Chart(document.getElementById('openInterestChart'), {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: '外資淨未平倉',
                    data: chartData.foreignOpenInterest,
                    unit: ' 口',
                    digits: 0,
                    borderColor: '#2563eb',
                    backgroundColor: '#2563eb',
                    tension: 0.24,
                    pointRadius: 2,
                    borderWidth: 2,
                },
                {
                    label: '投信淨未平倉',
                    data: chartData.investmentTrustOpenInterest,
                    unit: ' 口',
                    digits: 0,
                    borderColor: '#c47f17',
                    backgroundColor: '#c47f17',
                    tension: 0.24,
                    pointRadius: 2,
                    borderWidth: 2,
                },
            ],
        },
        options: {
            ...commonOptions,
            scales: {
                ...commonOptions.scales,
                y: {
                    title: {
                        display: true,
                        text: '淨未平倉(口)',
                        color: '#475569',
                    },
                    grid: {
                        color: '#e2e8f0',
                    },
                    ticks: {
                        color: '#64748b',
                    },
                },
            },
        },
    });
</script>
@endif
</body>
</html>
