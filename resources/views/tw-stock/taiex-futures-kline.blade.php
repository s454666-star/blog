<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台指期 60K 差值 K 線</title>
    <script src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.2/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        :root {
            --bg: #111316;
            --panel: #181b20;
            --panel-2: #20242b;
            --line: #2c323a;
            --text: #e5e7eb;
            --muted: #9ca3af;
            --green: #26a69a;
            --red: #ef5350;
            --yellow: #facc15;
            --pink: #f472b6;
            --blue: #60a5fa;
            --orange: #f59e0b;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                var(--bg);
            background-size: 40px 40px;
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        .shell {
            width: min(1960px, calc(100vw - 24px));
            margin: 0 auto;
            padding: 14px 0 28px;
        }

        .topbar,
        .metric-row,
        .chart-head,
        .legend,
        .recent-gap-list {
            display: flex;
            gap: 10px;
        }

        .topbar {
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }

        .title-row {
            display: flex;
            align-items: baseline;
            gap: 10px;
            min-width: 0;
        }

        h1 {
            margin: 0;
            font-size: 22px;
            line-height: 1.2;
            font-weight: 900;
        }

        .symbol {
            color: var(--muted);
            font-size: 14px;
            font-weight: 800;
        }

        .nav-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 7px;
        }

        .nav-actions a,
        .tool-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--text);
            background: rgba(24, 27, 32, 0.92);
            text-decoration: none;
            font: inherit;
            font-size: 13px;
            font-weight: 850;
            cursor: pointer;
        }

        .nav-actions a.active,
        .tool-button.active {
            border-color: rgba(96, 165, 250, 0.65);
            background: rgba(37, 99, 235, 0.28);
            color: #dbeafe;
        }

        .summary {
            display: grid;
            grid-template-columns: minmax(220px, 1.25fr) repeat(6, minmax(112px, 0.6fr));
            gap: 8px;
            margin-bottom: 10px;
        }

        .summary-cell {
            min-height: 62px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: rgba(24, 27, 32, 0.92);
        }

        .summary-cell .label,
        .recent-gap-list .label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .summary-cell .value {
            margin-top: 5px;
            font-size: 20px;
            line-height: 1.15;
            font-weight: 950;
            font-variant-numeric: tabular-nums;
        }

        .value.small {
            font-size: 13px;
            color: var(--muted);
            line-height: 1.45;
            font-weight: 800;
        }

        .positive { color: #fca5a5; }
        .negative { color: #67e8f9; }
        .muted { color: var(--muted); }

        .chart-panel {
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: rgba(15, 17, 21, 0.96);
        }

        .chart-head {
            align-items: center;
            justify-content: space-between;
            padding: 10px 12px;
            border-bottom: 1px solid var(--line);
            background: rgba(24, 27, 32, 0.92);
        }

        .legend {
            align-items: center;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .legend strong {
            color: var(--text);
            font-weight: 900;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .swatch {
            width: 20px;
            height: 3px;
            border-radius: 999px;
            background: var(--line);
        }

        .tools {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 7px;
        }

        #futures-chart {
            width: 100%;
            height: min(74vh, 820px);
            min-height: 620px;
        }

        .recent-gap-list {
            flex-wrap: wrap;
            padding: 10px 12px 12px;
            border-top: 1px solid var(--line);
            background: rgba(24, 27, 32, 0.72);
        }

        .gap-chip {
            display: grid;
            grid-template-columns: auto auto;
            gap: 3px 9px;
            min-width: 156px;
            padding: 8px 10px;
            border: 1px solid rgba(75, 85, 99, 0.78);
            border-radius: 8px;
            background: rgba(17, 19, 22, 0.78);
            font-size: 12px;
            font-weight: 800;
        }

        .gap-chip .time {
            grid-column: 1 / -1;
            color: var(--muted);
        }

        .empty {
            padding: 48px 16px;
            color: var(--muted);
            text-align: center;
            font-weight: 850;
        }

        @media (max-width: 1180px) {
            .topbar,
            .chart-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .summary {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .tools,
            .nav-actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 720px) {
            .shell { width: min(100% - 16px, 1960px); }
            .summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .summary-cell:first-child { grid-column: 1 / -1; }
            h1 { font-size: 19px; }
            #futures-chart { height: 620px; min-height: 620px; }
        }
@include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
@php
    $fmt = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals);
    $signed = fn ($value, int $decimals = 2): string => $value === null ? '--' : (((float) $value >= 0 ? '+' : '') . number_format((float) $value, $decimals));
    $tone = fn ($value): string => $value === null ? 'muted' : ((float) $value >= 0 ? 'positive' : 'negative');
@endphp

<main class="shell">
    <section class="topbar">
        <div class="title-row">
            <h1>台指期 60K 差值 K 線</h1>
            <span class="symbol">TAIFEX · TXF1! · 60K</span>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
            <a class="active" href="{{ route('tw-stock.taiex-futures.kline') }}">台指期K線</a>
        </nav>
    </section>

    <section class="summary" aria-label="台指期摘要">
        <div class="summary-cell">
            <div class="label">資料範圍</div>
            <div class="value small">{{ $stats['firstDateTime'] ?? '--' }} 到 {{ $stats['lastDateTime'] ?? '--' }}<br>{{ number_format((int) $stats['rowCount']) }} 根 60K</div>
        </div>
        <div class="summary-cell">
            <div class="label">最新收盤</div>
            <div class="value">{{ $latest ? $fmt($latest->close_price, 0) : '--' }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">差值</div>
            <div class="value {{ $tone($stats['latestGap']) }}">{{ $signed($stats['latestGap'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">日 MA5</div>
            <div class="value">{{ $fmt($stats['latestDailyMa5'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">60K MA95</div>
            <div class="value">{{ $fmt($stats['latestMa95'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">最大差值</div>
            <div class="value positive">{{ $signed($stats['maxGap'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">最小差值</div>
            <div class="value negative">{{ $signed($stats['minGap'], 0) }}</div>
        </div>
    </section>

    <section class="chart-panel">
        <div class="chart-head">
            <div class="legend" data-legend>
                <span class="legend-item"><span class="swatch" style="background: var(--yellow)"></span>60K MA95 <strong data-legend-ma95>--</strong></span>
                <span class="legend-item"><span class="swatch" style="background: var(--pink)"></span>日 MA5 <strong data-legend-daily-ma5>--</strong></span>
                <span class="legend-item"><span class="swatch" style="background: var(--orange)"></span>差值 <strong data-legend-gap>--</strong></span>
                <span class="legend-item">開 <strong data-legend-open>--</strong></span>
                <span class="legend-item">高 <strong data-legend-high>--</strong></span>
                <span class="legend-item">低 <strong data-legend-low>--</strong></span>
                <span class="legend-item">收 <strong data-legend-close>--</strong></span>
            </div>
            <div class="tools" aria-label="圖層切換">
                <button type="button" class="tool-button active" data-toggle-series="dailyMa5">日MA5</button>
                <button type="button" class="tool-button active" data-toggle-series="ma95">MA95</button>
                <button type="button" class="tool-button active" data-toggle-series="gap">差值</button>
                <button type="button" class="tool-button" data-show-all>全部</button>
                <button type="button" class="tool-button active" data-show-latest>最新</button>
            </div>
        </div>

        @if (count($chartRows) === 0)
            <div class="empty">目前還沒有台指期 60K 資料。</div>
        @else
            <div id="futures-chart"></div>
            <div class="recent-gap-list" aria-label="最近開盤差值">
                @foreach ($sessionGapRows as $gapRow)
                    <div class="gap-chip">
                        <span class="time">{{ $gapRow['localTime'] }} · {{ $gapRow['label'] }}</span>
                        <span class="{{ $gapRow['gap'] >= 0 ? 'positive' : 'negative' }}">{{ $gapRow['gapText'] }}</span>
                        <span class="muted">MA {{ number_format($gapRow['dailyMa5'], 0) }} / {{ number_format($gapRow['ma95'], 0) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</main>

@if (count($chartRows) > 0)
<script>
    const chartRows = @json($chartRows, JSON_UNESCAPED_UNICODE);
    const gapMarkers = @json($gapMarkers, JSON_UNESCAPED_UNICODE);
    const chartElement = document.getElementById('futures-chart');
    const chart = LightweightCharts.createChart(chartElement, {
        layout: { background: { color: '#0f1115' }, textColor: '#d4d4d8' },
        grid: { vertLines: { color: 'rgba(148, 163, 184, 0.13)' }, horzLines: { color: 'rgba(148, 163, 184, 0.13)' } },
        crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
        rightPriceScale: { borderColor: '#2c323a' },
        timeScale: {
            borderColor: '#2c323a',
            rightOffset: 0,
            barSpacing: 8,
            minBarSpacing: 2,
            fixLeftEdge: true,
            fixRightEdge: true,
            lockVisibleTimeRangeOnResize: true,
            timeVisible: true,
            secondsVisible: false
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

    chart.priceScale('right').applyOptions({ scaleMargins: { top: 0.05, bottom: 0.34 } });
    chart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.88, bottom: 0 } });
    chart.priceScale('gap').applyOptions({ scaleMargins: { top: 0.73, bottom: 0.06 } });

    const candleSeries = chart.addCandlestickSeries({
        upColor: '#26a69a',
        downColor: '#ef5350',
        borderUpColor: '#26a69a',
        borderDownColor: '#ef5350',
        wickUpColor: '#26a69a',
        wickDownColor: '#ef5350'
    });

    const volumeSeries = chart.addHistogramSeries({
        priceScaleId: 'volume',
        priceFormat: { type: 'volume' },
        color: 'rgba(148, 163, 184, 0.25)',
        lastValueVisible: false,
        priceLineVisible: false
    });

    const ma95Series = chart.addLineSeries({
        color: '#facc15',
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const dailyMa5Series = chart.addLineSeries({
        color: '#f472b6',
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const gapZeroSeries = chart.addLineSeries({
        priceScaleId: 'gap',
        color: 'rgba(229, 231, 235, 0.38)',
        lineWidth: 1,
        lineStyle: LightweightCharts.LineStyle.Dashed,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const gapSeries = chart.addLineSeries({
        priceScaleId: 'gap',
        color: '#f59e0b',
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const gapHistogramSeries = chart.addHistogramSeries({
        priceScaleId: 'gap',
        priceFormat: { type: 'price', precision: 0, minMove: 1 },
        lastValueVisible: false,
        priceLineVisible: false
    });

    const candles = chartRows.map(row => ({
        time: Number(row.time),
        open: Number(row.open),
        high: Number(row.high),
        low: Number(row.low),
        close: Number(row.close)
    }));

    const volumes = chartRows.map(row => ({
        time: Number(row.time),
        value: Number(row.volume || 0),
        color: Number(row.close) >= Number(row.open)
            ? 'rgba(38, 166, 154, 0.28)'
            : 'rgba(239, 83, 80, 0.28)'
    }));

    const ma95Data = chartRows
        .filter(row => row.ma95 !== null)
        .map(row => ({ time: Number(row.time), value: Number(row.ma95) }));

    const dailyMa5Data = chartRows
        .filter(row => row.dailyMa5 !== null)
        .map(row => ({ time: Number(row.time), value: Number(row.dailyMa5) }));

    const gapData = chartRows
        .filter(row => row.gap !== null)
        .map(row => ({ time: Number(row.time), value: Number(row.gap) }));

    const gapHistogramData = gapData.map(row => ({
        ...row,
        color: row.value >= 0 ? 'rgba(245, 158, 11, 0.24)' : 'rgba(56, 189, 248, 0.24)'
    }));

    candleSeries.setData(candles);
    candleSeries.setMarkers(gapMarkers);
    volumeSeries.setData(volumes);
    ma95Series.setData(ma95Data);
    dailyMa5Series.setData(dailyMa5Data);
    gapSeries.setData(gapData);
    gapHistogramSeries.setData(gapHistogramData);
    gapZeroSeries.setData(gapData.map(row => ({ time: row.time, value: 0 })));

    const seriesByToggle = {
        ma95: [ma95Series],
        dailyMa5: [dailyMa5Series],
        gap: [gapSeries, gapHistogramSeries, gapZeroSeries]
    };

    document.querySelectorAll('[data-toggle-series]').forEach(button => {
        button.addEventListener('click', () => {
            const key = button.dataset.toggleSeries;
            const active = !button.classList.contains('active');
            button.classList.toggle('active', active);
            seriesByToggle[key].forEach(series => series.applyOptions({ visible: active }));
        });
    });

    const lastLogicalIndex = chartRows.length - 1;
    const DEFAULT_VISIBLE_BARS = 180;
    let isApplyingVisibleRange = false;

    function clampVisibleLogicalRange(range) {
        if (isApplyingVisibleRange || range === null || lastLogicalIndex < 0) {
            return;
        }

        const span = range.to - range.from;
        if (!Number.isFinite(span) || span <= 0) {
            return;
        }

        let nextFrom = range.from;
        let nextTo = range.to;
        if (nextTo > lastLogicalIndex) {
            nextTo = lastLogicalIndex;
            nextFrom = nextTo - span;
        }

        if (nextFrom < 0) {
            nextFrom = 0;
            nextTo = Math.min(lastLogicalIndex, nextFrom + span);
        }

        if (Math.abs(nextFrom - range.from) <= 0.001 && Math.abs(nextTo - range.to) <= 0.001) {
            return;
        }

        isApplyingVisibleRange = true;
        chart.timeScale().setVisibleLogicalRange({ from: nextFrom, to: nextTo });
        requestAnimationFrame(() => {
            isApplyingVisibleRange = false;
        });
    }

    function showLatest() {
        if (lastLogicalIndex < 0) {
            return;
        }

        const visibleBars = Math.min(DEFAULT_VISIBLE_BARS, chartRows.length);
        chart.timeScale().setVisibleLogicalRange({
            from: Math.max(0, lastLogicalIndex - visibleBars + 1),
            to: lastLogicalIndex
        });
    }

    function showAll() {
        chart.timeScale().setVisibleLogicalRange({ from: 0, to: lastLogicalIndex });
    }

    document.querySelector('[data-show-latest]').addEventListener('click', showLatest);
    document.querySelector('[data-show-all]').addEventListener('click', showAll);
    chart.timeScale().subscribeVisibleLogicalRangeChange(clampVisibleLogicalRange);

    const legendMap = new Map(chartRows.map(row => [Number(row.time), row]));
    const fields = {
        open: document.querySelector('[data-legend-open]'),
        high: document.querySelector('[data-legend-high]'),
        low: document.querySelector('[data-legend-low]'),
        close: document.querySelector('[data-legend-close]'),
        ma95: document.querySelector('[data-legend-ma95]'),
        dailyMa5: document.querySelector('[data-legend-daily-ma5]'),
        gap: document.querySelector('[data-legend-gap]')
    };

    const format = (value, decimals = 0, signed = false) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '--';
        }
        const number = Number(value);
        return `${signed && number >= 0 ? '+' : ''}${number.toLocaleString('zh-TW', {
            maximumFractionDigits: decimals,
            minimumFractionDigits: decimals
        })}`;
    };

    function updateLegend(row = chartRows[chartRows.length - 1]) {
        fields.open.textContent = format(row.open);
        fields.high.textContent = format(row.high);
        fields.low.textContent = format(row.low);
        fields.close.textContent = format(row.close);
        fields.ma95.textContent = format(row.ma95);
        fields.dailyMa5.textContent = format(row.dailyMa5);
        fields.gap.textContent = format(row.gap, 0, true);
        fields.gap.className = Number(row.gap || 0) >= 0 ? 'positive' : 'negative';
    }

    chart.subscribeCrosshairMove(param => {
        if (!param.time) {
            updateLegend();
            return;
        }

        updateLegend(legendMap.get(Number(param.time)) || chartRows[chartRows.length - 1]);
    });

    const resize = () => chart.applyOptions({ width: chartElement.clientWidth, height: chartElement.clientHeight });
    window.addEventListener('resize', resize);
    resize();
    updateLegend();
    showLatest();
</script>
@endif
</body>
</html>
