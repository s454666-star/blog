<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $latest->stock_code }} {{ $latest->stock_name }} K 線</title>
    <script src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.2/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        :root {
            --bg: #eef4f8;
            --panel: #ffffff;
            --line: #d7e2ea;
            --text: #152033;
            --muted: #66758a;
            --dark: #152238;
            --red: #dc2626;
            --green: #15803d;
            --blue: #2563eb;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(rgba(21, 34, 56, 0.045) 1px, transparent 1px),
                linear-gradient(90deg, rgba(21, 34, 56, 0.045) 1px, transparent 1px),
                #eef4f8;
            background-size: 34px 34px;
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        .shell {
            width: min(1480px, calc(100vw - 32px));
            margin: 0 auto;
            padding: 30px 0 46px;
        }

        .topbar,
        .stock-head {
            display: flex;
            justify-content: space-between;
            gap: 18px;
        }

        .topbar {
            align-items: flex-end;
            margin-bottom: 16px;
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
        .back-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: #334155;
            background: rgba(255, 255, 255, 0.9);
            text-decoration: none;
            font-size: 13px;
            font-weight: 800;
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
        }

        .nav-actions a.active,
        .back-link.primary {
            color: #fff;
            border-color: var(--dark);
            background: var(--dark);
        }

        .stock-head,
        .chart-panel,
        .table-panel,
        .stat-card {
            border: 1px solid rgba(148, 163, 184, 0.42);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.92);
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.08);
        }

        .stock-head {
            align-items: stretch;
            padding: 18px;
            margin-bottom: 14px;
        }

        .stock-title {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .code {
            font-size: 30px;
            line-height: 1;
            font-weight: 900;
        }

        .name {
            color: var(--muted);
            font-size: 15px;
            font-weight: 800;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            flex: 1;
            max-width: 960px;
        }

        .stat-card {
            padding: 13px;
            box-shadow: none;
            background: #fbfdff;
        }

        .label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .value {
            margin-top: 7px;
            font-size: 21px;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
        }

        .positive { color: var(--red); }
        .negative { color: var(--green); }
        .muted { color: var(--muted); }

        .chart-panel {
            padding: 16px;
            margin-bottom: 14px;
        }

        .chart-toolbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
        }

        .chart-tools {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 10px;
        }

        .chart-title {
            margin: 0;
            font-size: 18px;
        }

        .chart-note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
            text-align: right;
        }

        .overlay-switch {
            display: inline-flex;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fbfd;
        }

        .overlay-switch button {
            min-height: 36px;
            padding: 0 13px;
            border: 0;
            border-right: 1px solid var(--line);
            color: #334155;
            background: transparent;
            cursor: pointer;
            font: inherit;
            font-size: 13px;
            font-weight: 900;
        }

        .overlay-switch button:last-child {
            border-right: 0;
        }

        .overlay-switch button.active {
            color: #ffffff;
            background: var(--dark);
        }

        .overlay-legend {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px 12px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .legend-swatch {
            width: 18px;
            height: 3px;
            border-radius: 999px;
            background: var(--line);
        }

        #kline-chart {
            width: 100%;
            height: 640px;
            min-height: 640px;
        }

        .table-panel {
            overflow: hidden;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
        }

        th,
        td {
            padding: 12px;
            border-bottom: 1px solid #e7edf3;
            text-align: right;
            white-space: nowrap;
            font-size: 14px;
            font-variant-numeric: tabular-nums;
        }

        th {
            color: #475569;
            background: #f8fbfd;
            font-size: 12px;
            font-weight: 900;
        }

        th:first-child,
        td:first-child {
            text-align: left;
        }

        @media (max-width: 1100px) {
            .topbar,
            .stock-head {
                flex-direction: column;
                align-items: flex-start;
            }

            .stats {
                width: 100%;
                max-width: none;
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .shell { width: min(100% - 20px, 1480px); padding-top: 18px; }
            h1 { font-size: 25px; }
            .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .chart-toolbar,
            .chart-tools {
                align-items: flex-start;
                flex-direction: column;
            }
            #kline-chart { height: 520px; min-height: 520px; }
            .table-panel { overflow-x: auto; }
        }
    </style>
</head>
<body>
@php
    $fmt = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals);
    $pct = fn ($value): string => $value === null ? '--' : (($value > 0 ? '+' : '') . number_format((float) $value, 2) . '%');
    $class = fn ($value): string => $value === null ? 'muted' : ((float) $value >= 0 ? 'positive' : 'negative');
@endphp

<main class="shell">
    <section class="topbar">
        <div>
            <h1>{{ $latest->stock_code }} {{ $latest->stock_name }} K 線</h1>
            <div class="meta">{{ $stats['firstDate'] ?? '--' }} 到 {{ $stats['lastDate'] ?? '--' }}，共 {{ number_format($stats['rowCount']) }} 根日 K。</div>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a class="active" href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
        </nav>
    </section>

    <section class="stock-head">
        <div class="stock-title">
            <div class="code">{{ $latest->stock_code }}</div>
            <div class="name">{{ $latest->stock_name }} · {{ $latest->exchange }}</div>
            <a class="back-link primary" href="{{ route('tw-stock.daily-prices.index') }}">回每日漲幅排行</a>
        </div>
        <div class="stats">
            <div class="stat-card"><div class="label">最新收盤</div><div class="value">{{ $fmt($latest->close_price) }}</div></div>
            <div class="stat-card"><div class="label">當日漲幅</div><div class="value {{ $class($latest->price_change_percent) }}">{{ $pct($latest->price_change_percent) }}</div></div>
            <div class="stat-card"><div class="label">當日排名</div><div class="value">{{ $stats['rank'] ? '#' . number_format($stats['rank']) : '--' }}</div></div>
            <div class="stat-card"><div class="label">成交量</div><div class="value">{{ number_format((int) $latest->volume_lots) }}</div></div>
            <div class="stat-card"><div class="label">區間高點</div><div class="value">{{ $fmt($stats['high']) }}</div></div>
            <div class="stat-card"><div class="label">區間低點</div><div class="value">{{ $fmt($stats['low']) }}</div></div>
        </div>
    </section>

    <section class="chart-panel">
        <div class="chart-toolbar">
            <h2 class="chart-title">日 K 線與成交量</h2>
            <div class="chart-tools">
                <div class="overlay-switch" aria-label="技術線切換">
                    <button type="button" class="active" data-overlay-mode="ma">均線</button>
                    <button type="button" data-overlay-mode="bollinger">布林軌道</button>
                </div>
                <div class="overlay-legend" data-overlay-legend></div>
                <div class="chart-note">滑鼠滾輪縮放 K 線間距；按住拖曳可左右移動日期。紅 K 上漲、綠 K 下跌。</div>
            </div>
        </div>
        <div id="kline-chart"></div>
    </section>

    <section class="table-panel">
        <table>
            <thead>
            <tr>
                <th>日期</th>
                <th>開盤</th>
                <th>最高</th>
                <th>最低</th>
                <th>收盤</th>
                <th>漲跌</th>
                <th>漲幅</th>
                <th>成交量(張)</th>
            </tr>
            </thead>
            <tbody>
            @foreach($recentRows->reverse() as $row)
                <tr>
                    <td>{{ $row->trade_date?->toDateString() }}</td>
                    <td>{{ $fmt($row->open_price) }}</td>
                    <td>{{ $fmt($row->high_price) }}</td>
                    <td>{{ $fmt($row->low_price) }}</td>
                    <td>{{ $fmt($row->close_price) }}</td>
                    <td class="{{ $class($row->price_change_amount) }}">{{ $row->price_change_amount > 0 ? '+' : '' }}{{ $fmt($row->price_change_amount) }}</td>
                    <td class="{{ $class($row->price_change_percent) }}">{{ $pct($row->price_change_percent) }}</td>
                    <td>{{ number_format((int) $row->volume_lots) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
</main>

<script>
    const chartRows = @json($chartRows, JSON_UNESCAPED_UNICODE);
    const chartElement = document.getElementById('kline-chart');
    const chart = LightweightCharts.createChart(chartElement, {
        layout: { background: { color: '#ffffff' }, textColor: '#334155' },
        grid: { vertLines: { color: '#eef2f7' }, horzLines: { color: '#eef2f7' } },
        crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
        rightPriceScale: { borderColor: '#d7e2ea' },
        timeScale: {
            borderColor: '#d7e2ea',
            rightOffset: 8,
            barSpacing: 8,
            minBarSpacing: 2,
            fixLeftEdge: false,
            lockVisibleTimeRangeOnResize: false
        },
        handleScroll: {
            mouseWheel: true,
            pressedMouseMove: true,
            horzTouchDrag: true,
            vertTouchDrag: false
        },
        handleScale: {
            axisPressedMouseMove: true,
            mouseWheel: true,
            pinch: true
        }
    });

    const candleSeries = chart.addCandlestickSeries({
        upColor: '#dc2626',
        downColor: '#15803d',
        borderUpColor: '#dc2626',
        borderDownColor: '#15803d',
        wickUpColor: '#dc2626',
        wickDownColor: '#15803d'
    });

    const volumeSeries = chart.addHistogramSeries({
        priceFormat: { type: 'volume' },
        priceScaleId: 'volume',
        color: '#94a3b8'
    });
    chart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.82, bottom: 0 } });
    chart.priceScale('right').applyOptions({ scaleMargins: { top: 0.06, bottom: 0.24 } });

    candleSeries.setData(chartRows.map(row => ({
        time: row.time,
        open: Number(row.open),
        high: Number(row.high),
        low: Number(row.low),
        close: Number(row.close)
    })));

    volumeSeries.setData(chartRows.map(row => ({
        time: row.time,
        value: Number(row.volume),
        color: Number(row.changePercent || 0) >= 0 ? 'rgba(220, 38, 38, 0.38)' : 'rgba(21, 128, 61, 0.38)'
    })));

    const closeRows = chartRows.map(row => ({ time: row.time, close: Number(row.close) })).filter(row => Number.isFinite(row.close));

    function movingAverageData(rows, period) {
        const output = [];
        let sum = 0;
        rows.forEach((row, index) => {
            sum += row.close;
            if (index >= period) {
                sum -= rows[index - period].close;
            }
            if (index >= period - 1) {
                output.push({ time: row.time, value: Number((sum / period).toFixed(4)) });
            }
        });
        return output;
    }

    function bollingerData(rows, period = 20, multiplier = 2) {
        const upper = [];
        const middle = [];
        const lower = [];
        rows.forEach((row, index) => {
            if (index < period - 1) {
                return;
            }
            const windowRows = rows.slice(index - period + 1, index + 1);
            const mean = windowRows.reduce((sum, item) => sum + item.close, 0) / period;
            const variance = windowRows.reduce((sum, item) => sum + ((item.close - mean) ** 2), 0) / period;
            const deviation = Math.sqrt(variance);
            middle.push({ time: row.time, value: Number(mean.toFixed(4)) });
            upper.push({ time: row.time, value: Number((mean + deviation * multiplier).toFixed(4)) });
            lower.push({ time: row.time, value: Number((mean - deviation * multiplier).toFixed(4)) });
        });
        return { upper, middle, lower };
    }

    const overlaySeries = {
        ma: [
            { name: '5日', color: '#2563eb', series: chart.addLineSeries({ color: '#2563eb', lineWidth: 2, priceLineVisible: false, lastValueVisible: false }) },
            { name: '月均', color: '#b45309', series: chart.addLineSeries({ color: '#b45309', lineWidth: 2, priceLineVisible: false, lastValueVisible: false }) },
            { name: '季均', color: '#7c3aed', series: chart.addLineSeries({ color: '#7c3aed', lineWidth: 2, priceLineVisible: false, lastValueVisible: false }) },
            { name: '半年均', color: '#0f766e', series: chart.addLineSeries({ color: '#0f766e', lineWidth: 2, priceLineVisible: false, lastValueVisible: false }) }
        ],
        bollinger: [
            { name: '上軌', color: '#2563eb', series: chart.addLineSeries({ color: '#2563eb', lineWidth: 2, priceLineVisible: false, lastValueVisible: false }) },
            { name: '中線', color: '#334155', series: chart.addLineSeries({ color: '#334155', lineWidth: 2, priceLineVisible: false, lastValueVisible: false }) },
            { name: '下軌', color: '#b45309', series: chart.addLineSeries({ color: '#b45309', lineWidth: 2, priceLineVisible: false, lastValueVisible: false }) }
        ]
    };

    overlaySeries.ma[0].series.setData(movingAverageData(closeRows, 5));
    overlaySeries.ma[1].series.setData(movingAverageData(closeRows, 20));
    overlaySeries.ma[2].series.setData(movingAverageData(closeRows, 60));
    overlaySeries.ma[3].series.setData(movingAverageData(closeRows, 120));

    const bollinger = bollingerData(closeRows, 20, 2);
    overlaySeries.bollinger[0].series.setData(bollinger.upper);
    overlaySeries.bollinger[1].series.setData(bollinger.middle);
    overlaySeries.bollinger[2].series.setData(bollinger.lower);

    const legend = document.querySelector('[data-overlay-legend]');
    function setOverlay(mode) {
        Object.entries(overlaySeries).forEach(([key, items]) => {
            items.forEach(item => item.series.applyOptions({ visible: key === mode }));
        });

        document.querySelectorAll('[data-overlay-mode]').forEach(button => {
            button.classList.toggle('active', button.dataset.overlayMode === mode);
        });

        legend.innerHTML = overlaySeries[mode].map(item => (
            `<span class="legend-item"><span class="legend-swatch" style="background:${item.color}"></span>${item.name}</span>`
        )).join('');
    }

    document.querySelectorAll('[data-overlay-mode]').forEach(button => {
        button.addEventListener('click', () => setOverlay(button.dataset.overlayMode));
    });
    setOverlay('ma');

    const resize = () => chart.applyOptions({ width: chartElement.clientWidth, height: chartElement.clientHeight });
    window.addEventListener('resize', resize);
    resize();
    chart.timeScale().fitContent();
</script>
</body>
</html>
