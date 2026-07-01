<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>台指期 15K 差值 K 線</title>
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
            --violet: #a78bfa;
            --lime: #84cc16;
            --cyan: #22d3ee;
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
            grid-template-columns: minmax(220px, 1.25fr) repeat(8, minmax(112px, 0.6fr));
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

        .legend-toggle {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: inherit;
            cursor: pointer;
        }

        .legend-toggle input {
            width: 14px;
            height: 14px;
            margin: 0;
            accent-color: var(--blue);
            cursor: pointer;
            flex: 0 0 auto;
        }

        .legend-toggle input:disabled {
            cursor: default;
        }

        .legend-item.is-disabled {
            opacity: 0.52;
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

        .chart-hint {
            padding: 8px 12px;
            border-bottom: 1px solid var(--line);
            color: #cbd5e1;
            background: rgba(15, 17, 21, 0.86);
            font-size: 12px;
            font-weight: 800;
        }

        .chart-body {
            display: grid;
            grid-template-columns: 58px minmax(0, 1fr);
            width: 100%;
        }

        .gap-axis-layer {
            position: relative;
            height: min(74vh, 820px);
            min-height: 620px;
            border-right: 1px solid #2c323a;
            background: #0f1115;
            cursor: ns-resize;
            touch-action: none;
            user-select: none;
            pointer-events: auto;
        }

        .gap-axis-layer.dragging {
            background: #111827;
        }

        .gap-axis-tick {
            position: absolute;
            right: 9px;
            color: #9fb2c7;
            font-size: 10px;
            font-weight: 850;
            line-height: 1;
            white-space: nowrap;
            transform: translateY(-50%);
            font-variant-numeric: tabular-nums;
        }

        .gap-axis-tick::after {
            content: "";
            position: absolute;
            top: 50%;
            right: -9px;
            width: 5px;
            border-top: 1px solid rgba(203, 213, 225, 0.45);
            transform: translateY(-50%);
        }

        .gap-axis-tick.zero {
            color: #e5e7eb;
        }

        #futures-chart {
            position: relative;
            width: 100%;
            height: min(74vh, 820px);
            min-height: 620px;
            cursor: crosshair;
            user-select: none;
        }

        .marker-label-layer {
            position: absolute;
            inset: 0;
            z-index: 20;
            pointer-events: none;
        }

        .marker-label {
            position: absolute;
            font-size: 16px;
            font-weight: 950;
            line-height: 1;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
            text-shadow:
                0 1px 2px #0f1115,
                0 -1px 2px #0f1115,
                1px 0 2px #0f1115,
                -1px 0 2px #0f1115,
                0 0 5px #0f1115;
            transform: translate(-50%, -50%);
        }

        .threshold-dot {
            position: absolute;
            width: 11px;
            height: 11px;
            border: 2px solid rgba(15, 17, 21, 0.96);
            border-radius: 999px;
            background: var(--cyan);
            box-shadow:
                0 0 0 2px rgba(34, 211, 238, 0.35),
                0 0 10px rgba(34, 211, 238, 0.85);
            transform: translate(-50%, -50%);
        }

        .threshold-dot.gap {
            background: var(--orange);
            box-shadow:
                0 0 0 2px rgba(245, 158, 11, 0.35),
                0 0 10px rgba(245, 158, 11, 0.85);
        }

        .temporary-line-label {
            position: absolute;
            right: 58px;
            font-size: 13px;
            font-weight: 950;
            line-height: 1;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
            text-shadow:
                0 1px 2px #0f1115,
                0 -1px 2px #0f1115,
                1px 0 2px #0f1115,
                -1px 0 2px #0f1115,
                0 0 5px #0f1115;
            transform: translateY(-50%);
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
            .chart-body { grid-template-columns: 50px minmax(0, 1fr); }
            .gap-axis-layer,
            #futures-chart { height: 620px; min-height: 620px; }
        }
@include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
@php
    $fmt = fn ($value, int $decimals = 2): string => $value === null ? '--' : number_format((float) $value, $decimals);
    $signed = fn ($value, int $decimals = 2): string => $value === null ? '--' : (((float) $value >= 0 ? '+' : '') . number_format((float) $value, $decimals));
    $signedPercent = fn ($value, int $decimals = 2): string => $value === null ? '--' : (((float) $value >= 0 ? '+' : '') . number_format((float) $value * 100, $decimals) . '%');
    $tone = fn ($value): string => $value === null ? 'muted' : ((float) $value >= 0 ? 'positive' : 'negative');
@endphp

<main class="shell">
    <section class="topbar">
        <div class="title-row">
            <h1>台指期 15K 差值 K 線</h1>
            <span class="symbol">TAIFEX · TXF1! · 15K / 60K</span>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
            <a href="{{ route('tw-stock.active-etf-operations.index') }}">主動ETF</a>
            <a class="active" href="{{ route('tw-stock.taiex-futures.kline') }}">台指期K線</a>
        </nav>
    </section>

    <section class="summary" aria-label="台指期摘要">
        <div class="summary-cell">
            <div class="label">資料範圍</div>
            <div class="value small" data-summary-field="range">{{ $stats['firstDateTime'] ?? '--' }} 到 {{ $stats['lastDateTime'] ?? '--' }}<br>{{ number_format((int) $stats['rowCount']) }} 根 15K</div>
        </div>
        <div class="summary-cell">
            <div class="label">差值</div>
            <div class="value {{ $tone($stats['latestGap']) }}" data-summary-field="latestGap">{{ $signed($stats['latestGap'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">乖離</div>
            <div class="value {{ $tone($stats['latestBias']) }}" data-summary-field="latestBias">{{ $signed($stats['latestBias'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">乖離率</div>
            <div class="value {{ $tone($stats['latestBiasRate']) }}" data-summary-field="latestBiasRate">{{ $signedPercent($stats['latestBiasRate'], 2) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">最新收盤</div>
            <div class="value" data-summary-field="latestClose">{{ $latest ? $fmt($latest->close_price, 0) : '--' }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">日 MA5</div>
            <div class="value" data-summary-field="latestDailyMa5">{{ $fmt($stats['latestDailyMa5'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">15K</div>
            <div class="value" data-summary-field="latestMovingAverage">{{ $fmt($stats['latestMovingAverage'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">最大差值</div>
            <div class="value positive" data-summary-field="maxGap">{{ $signed($stats['maxGap'], 0) }}</div>
        </div>
        <div class="summary-cell">
            <div class="label">最小差值</div>
            <div class="value negative" data-summary-field="minGap">{{ $signed($stats['minGap'], 0) }}</div>
        </div>
    </section>

    <section class="chart-panel">
        <div class="chart-head">
            <div class="legend" data-legend>
                <span class="legend-item" data-series-control="candles"><label class="legend-toggle"><input type="checkbox" checked disabled data-toggle-series="candles" aria-label="K 線固定顯示"><span class="swatch" style="background: var(--blue)"></span>週期 <strong data-legend-timeframe>15分K</strong></label></span>
                <span class="legend-item" data-series-control="movingAverage"><label class="legend-toggle"><input type="checkbox" checked data-toggle-series="movingAverage" aria-label="顯示均線"><span class="swatch" style="background: var(--yellow)"></span><span data-legend-ma-label>15K</span> <strong data-legend-ma>--</strong></label></span>
                <span class="legend-item" data-series-control="dailyMa5"><label class="legend-toggle"><input type="checkbox" checked data-toggle-series="dailyMa5" aria-label="顯示日 MA5"><span class="swatch" style="background: var(--pink)"></span>日 MA5 <strong data-legend-daily-ma5>--</strong></label></span>
                <span class="legend-item" data-series-control="gap"><label class="legend-toggle"><input type="checkbox" checked data-toggle-series="gap" aria-label="顯示差值"><span class="swatch" style="background: var(--orange)"></span>差值 <strong data-legend-gap>--</strong></label></span>
                <span class="legend-item" data-series-control="bias"><label class="legend-toggle"><input type="checkbox" data-toggle-series="bias" aria-label="顯示乖離"><span class="swatch" style="background: var(--violet)"></span>乖離 <strong data-legend-bias>--</strong></label></span>
                <span class="legend-item" data-series-control="biasGapDiff"><label class="legend-toggle"><input type="checkbox" data-toggle-series="biasGapDiff" aria-label="顯示乖離-差值"><span class="swatch" style="background: var(--lime)"></span>乖離-差值 <strong data-legend-bias-gap-diff>--</strong></label></span>
                <span class="legend-item" data-series-control="biasRate"><label class="legend-toggle"><input type="checkbox" checked data-toggle-series="biasRate" aria-label="顯示乖離率"><span class="swatch" style="background: var(--cyan)"></span>乖離率 <strong data-legend-bias-rate>--</strong></label></span>
                <span class="legend-item"><span class="swatch" style="background: #e5e7eb"></span>標記 <strong data-marker-count>0</strong></span>
                <span class="legend-item">開 <strong data-legend-open>--</strong></span>
                <span class="legend-item">高 <strong data-legend-high>--</strong></span>
                <span class="legend-item">低 <strong data-legend-low>--</strong></span>
                <span class="legend-item">收 <strong data-legend-close>--</strong></span>
            </div>
            <div class="tools" aria-label="圖層切換">
                <button type="button" class="tool-button active" data-timeframe="fifteen-minute">15分K</button>
                <button type="button" class="tool-button" data-timeframe="hourly">60分K</button>
                <button type="button" class="tool-button" data-timeframe="daily">日線</button>
                <button type="button" class="tool-button" data-show-all>全部</button>
                <button type="button" class="tool-button active" data-show-latest>最新</button>
            </div>
        </div>

        @if (count($chartRows) === 0)
            <div class="empty">目前還沒有台指期 15K 資料。</div>
        @else
            <div class="chart-hint">點一下可標記差值，再點一下或右鍵可取消，重整後標記清空。</div>
            <div class="chart-body">
                <div class="gap-axis-layer" data-gap-axis aria-label="差值軸"></div>
                <div id="futures-chart"></div>
            </div>
            <div class="recent-gap-list" aria-label="最近開收盤差值" data-session-gap-list>
                @foreach ($sessionGapRows as $gapRow)
                    <div class="gap-chip">
                        <span class="time">{{ $gapRow['localTime'] }} · {{ $gapRow['label'] }} · {{ $gapRow['eventLabel'] }}</span>
                        <span class="{{ $gapRow['gap'] >= 0 ? 'positive' : 'negative' }}">{{ $gapRow['gapText'] }}</span>
                        <span class="muted">MA {{ number_format($gapRow['dailyMa5'], 0) }} / {{ number_format($gapRow['movingAverage'], 0) }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</main>

@if (count($chartRows) > 0)
<script>
    const dataUrl = @json($dataUrl);
    const chartRows = @json($chartRows, JSON_UNESCAPED_UNICODE);
    const dailyChartRows = @json($dailyChartRows, JSON_UNESCAPED_UNICODE);
    const gapMarkers = @json($gapMarkers, JSON_UNESCAPED_UNICODE);
    const dailyGapMarkers = @json($dailyGapMarkers, JSON_UNESCAPED_UNICODE);
    const hourlyChartRows = @json($hourlyChartRows, JSON_UNESCAPED_UNICODE);
    const hourlyGapMarkers = @json($hourlyGapMarkers, JSON_UNESCAPED_UNICODE);
    const chartElement = document.getElementById('futures-chart');
    const gapAxisLayer = document.querySelector('[data-gap-axis]');
    const markerLabelLayer = document.createElement('div');
    markerLabelLayer.className = 'marker-label-layer';
    let activeTimeframe = 'fifteen-minute';
    let currentRows = chartRows;
    let lastLogicalIndex = currentRows.length - 1;
    let legendMap = new Map(currentRows.map(row => [Number(row.time), row]));
    let gapAxisScale = 1;
    let gapAxisDragState = null;
    let futuresRefreshTimer = null;
    let futuresRefreshInFlight = false;
    let lastFuturesRefreshStartedAt = Date.now();
    let futuresDataRevision = @json($dataRevision);
    const FUTURES_REFRESH_INTERVAL_MS = 60000;
    const FUTURES_REFRESH_VISIBLE_GRACE_MS = 10000;
    const GAP_AXIS_MIN_SCALE = 0.35;
    const GAP_AXIS_MAX_SCALE = 4;
    const GAP_AXIS_DRAG_SENSITIVITY = 180;
    const GAP_AXIS_TICK_MIN_GAP = 8;
    const GAP_AXIS_TICK_MAX_GAP = 24;
    const GAP_AXIS_DEFAULT_TICK_STEP = 200;
    const GAP_AXIS_MIN_VISIBLE_MAX = 2000;
    const GAP_AXIS_MIN_NEGATIVE_VISIBLE = 1200;
    const GAP_AXIS_ZERO_RATIO = 0.68;
    const BIAS_RATE_HIGHLIGHT_THRESHOLD = 0.04;
    const GAP_HIGHLIGHT_THRESHOLD = 1000;
    const taipeiTimePartsFormatter = new Intl.DateTimeFormat('zh-TW', {
        timeZone: 'Asia/Taipei',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false
    });
    const timeframeDatasets = {
        'fifteen-minute': {
            rows: chartRows,
            markers: gapMarkers,
            label: '15分K',
            movingAverageLabel: '15K',
            defaultVisibleBars: 380
        },
        hourly: {
            rows: hourlyChartRows,
            markers: hourlyGapMarkers,
            label: '60分K',
            movingAverageLabel: '60K MA95',
            defaultVisibleBars: 180
        },
        daily: {
            rows: dailyChartRows,
            markers: dailyGapMarkers,
            label: '日線',
            movingAverageLabel: '15K',
            defaultVisibleBars: 90
        }
    };
    const seriesVisibility = {
        candles: true,
        movingAverage: true,
        dailyMa5: true,
        gap: true,
        bias: false,
        biasGapDiff: false,
        biasRate: true
    };

    function timestampValue(time) {
        if (typeof time === 'number') {
            return time;
        }

        if (time && typeof time === 'object' && Number.isFinite(Number(time.timestamp))) {
            return Number(time.timestamp);
        }

        return null;
    }

    function taipeiDateParts(time) {
        const timestamp = timestampValue(time);
        if (timestamp === null) {
            return null;
        }

        const parts = Object.fromEntries(
            taipeiTimePartsFormatter.formatToParts(new Date(timestamp * 1000))
                .map(part => [part.type, part.value])
        );

        return {
            year: parts.year,
            month: parts.month,
            day: parts.day,
            hour: parts.hour,
            minute: parts.minute
        };
    }

    function formatTaipeiAxisTime(time) {
        const parts = taipeiDateParts(time);
        if (parts === null) {
            return '';
        }

        return activeTimeframe === 'daily'
            ? `${parts.month}/${parts.day}`
            : `${parts.day}日 ${parts.hour}:${parts.minute}`;
    }

    function formatTaipeiCrosshairTime(time) {
        const parts = taipeiDateParts(time);
        if (parts === null) {
            return '';
        }

        return activeTimeframe === 'daily'
            ? `${parts.year}/${parts.month}/${parts.day}`
            : `${parts.year}/${parts.month}/${parts.day} ${parts.hour}:${parts.minute}`;
    }

    const chart = LightweightCharts.createChart(chartElement, {
        layout: { background: { color: '#0f1115' }, textColor: '#d4d4d8' },
        grid: { vertLines: { color: 'rgba(148, 163, 184, 0.13)' }, horzLines: { color: 'rgba(148, 163, 184, 0.13)' } },
        crosshair: { mode: LightweightCharts.CrosshairMode.Normal },
        localization: {
            timeFormatter: formatTaipeiCrosshairTime
        },
        rightPriceScale: { borderColor: '#2c323a' },
        timeScale: {
            borderColor: '#2c323a',
            rightOffset: 0,
            barSpacing: 8,
            minBarSpacing: 2,
            fixLeftEdge: true,
            fixRightEdge: false,
            lockVisibleTimeRangeOnResize: true,
            timeVisible: true,
            secondsVisible: false,
            tickMarkFormatter: formatTaipeiAxisTime
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
    chartElement.appendChild(markerLabelLayer);

    chart.priceScale('right').applyOptions({ scaleMargins: { top: 0.05, bottom: 0.34 } });

    const candleSeries = chart.addCandlestickSeries({
        upColor: '#ef5350',
        downColor: '#26a69a',
        borderUpColor: '#ef5350',
        borderDownColor: '#26a69a',
        wickUpColor: '#ef5350',
        wickDownColor: '#26a69a'
    });

    const volumeSeries = chart.addHistogramSeries({
        priceScaleId: 'volume',
        priceFormat: { type: 'volume' },
        color: 'rgba(148, 163, 184, 0.25)',
        lastValueVisible: false,
        priceLineVisible: false
    });

    const movingAverageSeries = chart.addLineSeries({
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
        priceFormat: { type: 'price', precision: 0, minMove: 1 },
        autoscaleInfoProvider: gapAutoscaleInfoProvider,
        color: 'rgba(229, 231, 235, 0.38)',
        lineWidth: 1,
        lineStyle: LightweightCharts.LineStyle.Dashed,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const gapSeries = chart.addBaselineSeries({
        priceScaleId: 'gap',
        priceFormat: { type: 'price', precision: 0, minMove: 1 },
        autoscaleInfoProvider: gapAutoscaleInfoProvider,
        lineWidth: 2,
        baseValue: { type: 'price', price: 0 },
        topLineColor: '#f59e0b',
        bottomLineColor: '#38bdf8',
        topFillColor1: 'rgba(245, 158, 11, 0)',
        topFillColor2: 'rgba(245, 158, 11, 0)',
        bottomFillColor1: 'rgba(56, 189, 248, 0)',
        bottomFillColor2: 'rgba(56, 189, 248, 0)',
        baseLineVisible: false,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const biasSeries = chart.addLineSeries({
        priceScaleId: 'gap',
        priceFormat: { type: 'price', precision: 0, minMove: 1 },
        autoscaleInfoProvider: gapAutoscaleInfoProvider,
        color: '#a78bfa',
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const biasGapDiffSeries = chart.addLineSeries({
        priceScaleId: 'gap',
        priceFormat: { type: 'price', precision: 0, minMove: 1 },
        autoscaleInfoProvider: gapAutoscaleInfoProvider,
        color: '#84cc16',
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const biasRateSeries = chart.addLineSeries({
        priceScaleId: 'biasRate',
        priceFormat: { type: 'price', precision: 4, minMove: 0.0001 },
        color: '#22d3ee',
        lineWidth: 2,
        priceLineVisible: false,
        lastValueVisible: false
    });

    const gapHistogramSeries = chart.addHistogramSeries({
        priceScaleId: 'gap',
        priceFormat: { type: 'price', precision: 0, minMove: 1 },
        autoscaleInfoProvider: gapAutoscaleInfoProvider,
        lastValueVisible: false,
        priceLineVisible: false
    });
    chart.priceScale('volume').applyOptions({ scaleMargins: { top: 0.88, bottom: 0 } });
    chart.priceScale('gap').applyOptions({ scaleMargins: { top: 0.14, bottom: 0.06 } });
    chart.priceScale('biasRate').applyOptions({ scaleMargins: { top: 0.14, bottom: 0.06 } });

    function candleData(rows) {
        return rows.map(row => ({
            time: Number(row.time),
            open: Number(row.open),
            high: Number(row.high),
            low: Number(row.low),
            close: Number(row.close)
        }));
    }

    function volumeData(rows) {
        return rows.map(row => ({
            time: Number(row.time),
            value: Number(row.volume || 0),
            color: Number(row.close) >= Number(row.open)
                ? 'rgba(239, 83, 80, 0.28)'
                : 'rgba(38, 166, 154, 0.28)'
        }));
    }

    function lineData(rows, key) {
        return rows
            .filter(row => row[key] !== null && row[key] !== undefined)
            .map(row => ({ time: Number(row.time), value: Number(row[key]) }));
    }

    function clampNumber(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function gapHistogramData(gapRows) {
        return gapRows.map(row => ({
            ...row,
            color: row.value >= 0 ? 'rgba(245, 158, 11, 0.22)' : 'rgba(56, 189, 248, 0.22)'
        }));
    }

    function gapZeroData(rows) {
        return rows
            .filter(row => row.gap != null || row.bias != null || row.biasGapDiff != null)
            .map(row => ({ time: Number(row.time), value: 0 }));
    }

    function chartMarkerData(markers) {
        return markers.map(marker => {
            const { text, ...chartMarker } = marker;
            return chartMarker;
        });
    }

    function activeGapMarkers() {
        return timeframeDatasets[activeTimeframe]?.markers ?? gapMarkers;
    }

    function visibleCurrentRows(paddingBars = 4) {
        const range = chart.timeScale().getVisibleLogicalRange();
        if (!range) {
            return currentRows;
        }

        const from = Math.max(0, Math.floor(range.from) - paddingBars);
        const to = Math.min(lastLogicalIndex, Math.ceil(range.to) + paddingBars);

        if (to < from) {
            return [];
        }

        return currentRows.slice(from, to + 1);
    }

    function appendThresholdDot(fragment, row, series, value, className, title) {
        const x = chart.timeScale().timeToCoordinate(Number(row.time));
        const y = series.priceToCoordinate(value);
        const width = chartElement.clientWidth;
        const height = chartElement.clientHeight;
        const dotViewportMargin = 12;

        if (
            !Number.isFinite(x)
            || !Number.isFinite(y)
            || x < -dotViewportMargin
            || x > width + dotViewportMargin
            || y < -dotViewportMargin
            || y > height + dotViewportMargin
        ) {
            return;
        }

        const dot = document.createElement('span');
        dot.className = className;
        dot.title = title;
        dot.setAttribute('aria-hidden', 'true');
        dot.style.left = `${x}px`;
        dot.style.top = `${y}px`;
        fragment.appendChild(dot);
    }

    function renderThresholdDots(fragment) {
        visibleCurrentRows().forEach(row => {
            if (seriesVisibility.biasRate && row.biasRate !== null && row.biasRate !== undefined) {
                const biasRate = Number(row.biasRate);
                if (Number.isFinite(biasRate) && Math.abs(biasRate) > BIAS_RATE_HIGHLIGHT_THRESHOLD) {
                    appendThresholdDot(
                        fragment,
                        row,
                        biasRateSeries,
                        biasRate,
                        'threshold-dot bias-rate',
                        `乖離率 ${formatPercent(biasRate, 2, true)}`
                    );
                }
            }

            if (seriesVisibility.gap && row.gap !== null && row.gap !== undefined) {
                const gap = Number(row.gap);
                if (Number.isFinite(gap) && Math.abs(gap) > GAP_HIGHLIGHT_THRESHOLD) {
                    appendThresholdDot(
                        fragment,
                        row,
                        gapSeries,
                        gap,
                        'threshold-dot gap',
                        `差值 ${formatSignedGapValue(gap)}`
                    );
                }
            }
        });
    }

    function renderMarkerLabels() {
        const width = chartElement.clientWidth;
        const height = chartElement.clientHeight;
        const fragment = document.createDocumentFragment();

        if (seriesVisibility.candles) {
            activeGapMarkers().forEach(marker => {
                if (!marker.text) {
                    return;
                }

                const time = Number(marker.time);
                const x = chart.timeScale().timeToCoordinate(time);
                const labelEdgePadding = 34;
                if (x === null || x < labelEdgePadding || x > width + labelEdgePadding) {
                    return;
                }

                const row = legendMap.get(time);
                if (!row) {
                    return;
                }

                const anchorPrice = marker.position === 'aboveBar'
                    ? Number(row.high)
                    : Number(row.low);
                const anchorY = candleSeries.priceToCoordinate(anchorPrice);
                if (!Number.isFinite(anchorY)) {
                    return;
                }

                const labelViewportMargin = 36;
                if (anchorY < -labelViewportMargin || anchorY > height + labelViewportMargin) {
                    return;
                }

                const yOffset = marker.position === 'aboveBar' ? -28 : 28;
                const y = Math.max(18, Math.min(height - 18, anchorY + yOffset));
                const label = document.createElement('span');
                label.className = 'marker-label';
                label.textContent = marker.text;
                label.style.color = marker.color;
                label.style.left = `${Math.min(width - labelEdgePadding, x)}px`;
                label.style.top = `${y}px`;
                fragment.appendChild(label);
            });
        }

        renderThresholdDots(fragment);

        temporaryPriceLines.forEach(item => {
            const y = gapSeries.priceToCoordinate(item.price);
            if (!Number.isFinite(y) || y < -18 || y > height + 18) {
                return;
            }

            const label = document.createElement('span');
            label.className = 'temporary-line-label';
            label.textContent = `差值 ${formatSignedGapValue(item.price)}`;
            label.style.color = item.color;
            label.style.top = `${clampNumber(y, 18, height - 18)}px`;
            fragment.appendChild(label);
        });

        markerLabelLayer.replaceChildren(fragment);
    }

    function niceGapStep(rawStep) {
        if (!Number.isFinite(rawStep) || rawStep <= 0) {
            return 100;
        }

        const power = 10 ** Math.floor(Math.log10(rawStep));
        const fraction = rawStep / power;
        const multiplier = fraction <= 1 ? 1
            : fraction <= 1.5 ? 1.5
            : fraction <= 2 ? 2
            : fraction <= 2.5 ? 2.5
            : fraction <= 5 ? 5
            : 10;
        return multiplier * power;
    }

    function visibleGapValues() {
        const range = chart.timeScale().getVisibleLogicalRange();
        const from = range ? Math.max(0, Math.floor(range.from)) : 0;
        const to = range ? Math.min(lastLogicalIndex, Math.ceil(range.to)) : lastLogicalIndex;
        const visibleGapKeys = ['gap', 'bias', 'biasGapDiff']
            .filter(key => seriesVisibility[key]);
        if (visibleGapKeys.length === 0) {
            return [];
        }

        return currentRows
            .slice(from, to + 1)
            .flatMap(row => visibleGapKeys.map(key => Number(row[key])))
            .filter(Number.isFinite);
    }

    function gapAxisVisibleRange() {
        const values = visibleGapValues();
        if (values.length === 0) {
            return null;
        }

        const rawMin = Math.min(0, ...values);
        const rawMax = Math.max(0, ...values);
        const rangePadding = Math.max((rawMax - rawMin) * 0.06, 20);
        const negativeRatio = (1 - GAP_AXIS_ZERO_RATIO) / GAP_AXIS_ZERO_RATIO;
        const baseMaxValue = Math.max(
            GAP_AXIS_MIN_VISIBLE_MAX,
            GAP_AXIS_MIN_NEGATIVE_VISIBLE / negativeRatio,
            rawMax + rangePadding,
            Math.abs(rawMin - rangePadding) / negativeRatio
        );
        const maxValue = Math.max(GAP_AXIS_MIN_VISIBLE_MAX, baseMaxValue / gapAxisScale);
        const minValue = -maxValue * negativeRatio;
        const step = gapAxisTickStep({ minValue, maxValue });
        const roundedMaxValue = Math.ceil(maxValue / step) * step;

        return {
            minValue: -roundedMaxValue * negativeRatio,
            maxValue: roundedMaxValue
        };
    }

    function gapAutoscaleInfoProvider() {
        const priceRange = gapAxisVisibleRange();
        if (priceRange === null) {
            return null;
        }

        return {
            priceRange,
            margins: {
                above: 4,
                below: 4
            }
        };
    }

    function gapAxisTicks(priceRange) {
        if (priceRange === null) {
            return [];
        }

        const step = gapAxisTickStep(priceRange);
        const first = Math.ceil(priceRange.minValue / step) * step;
        const last = Math.floor(priceRange.maxValue / step) * step;
        const ticks = [];

        for (let value = first; value <= last + step * 0.5; value += step) {
            ticks.push(Math.round(value));
        }

        if (!ticks.includes(0)) {
            ticks.push(0);
        }

        return ticks.sort((a, b) => b - a);
    }

    function gapAxisTickStep(priceRange) {
        const range = priceRange.maxValue - priceRange.minValue || 1;
        const targetTickCount = gapAxisTargetTickCount();
        const dynamicStep = niceGapStep(range / targetTickCount);

        if (gapAxisScale >= 1) {
            return Math.min(dynamicStep, GAP_AXIS_DEFAULT_TICK_STEP);
        }

        return dynamicStep;
    }

    function gapAxisTargetTickCount() {
        const defaultRange = GAP_AXIS_MIN_VISIBLE_MAX / GAP_AXIS_ZERO_RATIO;
        const defaultTickCount = Math.ceil(defaultRange / GAP_AXIS_DEFAULT_TICK_STEP);
        return clampNumber(Math.round(defaultTickCount * gapAxisScale), 8, 120);
    }

    function gapAxisTickMinGap() {
        return clampNumber(
            GAP_AXIS_TICK_MIN_GAP / Math.sqrt(gapAxisScale),
            5,
            GAP_AXIS_TICK_MAX_GAP
        );
    }

    function renderGapAxis() {
        if (!gapAxisLayer || typeof gapSeries.priceToCoordinate !== 'function') {
            return;
        }

        const height = chartElement.clientHeight;
        const fragment = document.createDocumentFragment();
        const renderedTickYs = [];
        const tickMinGap = gapAxisTickMinGap();
        gapAxisTicks(gapAxisVisibleRange()).forEach(value => {
            const rawY = gapSeries.priceToCoordinate(value);
            if (!Number.isFinite(rawY)) {
                return;
            }

            const y = clampNumber(rawY, 12, height - 12);
            if (renderedTickYs.some(renderedY => Math.abs(renderedY - y) < tickMinGap)) {
                return;
            }
            renderedTickYs.push(y);

            const tick = document.createElement('span');
            tick.className = value === 0 ? 'gap-axis-tick zero' : 'gap-axis-tick';
            tick.textContent = value.toLocaleString('zh-TW');
            tick.style.top = `${y}px`;
            fragment.appendChild(tick);
        });

        gapAxisLayer.replaceChildren(fragment);
    }

    function renderChartOverlays() {
        renderMarkerLabels();
        renderGapAxis();
    }

    function refreshGapAxisScale() {
        const autoscaleOptions = { autoscaleInfoProvider: gapAutoscaleInfoProvider };
        gapZeroSeries.applyOptions(autoscaleOptions);
        gapSeries.applyOptions(autoscaleOptions);
        biasSeries.applyOptions(autoscaleOptions);
        biasGapDiffSeries.applyOptions(autoscaleOptions);
        gapHistogramSeries.applyOptions(autoscaleOptions);
        scheduleMarkerLabelRender();
    }

    function setGapAxisScale(scale) {
        gapAxisScale = clampNumber(scale, GAP_AXIS_MIN_SCALE, GAP_AXIS_MAX_SCALE);
        refreshGapAxisScale();
    }

    function startGapAxisDrag(event) {
        if (!gapAxisLayer || (event.button !== undefined && event.button !== 0)) {
            return;
        }

        event.preventDefault();
        gapAxisDragState = {
            pointerId: event.pointerId,
            startY: event.clientY,
            startScale: gapAxisScale
        };
        gapAxisLayer.classList.add('dragging');
        gapAxisLayer.setPointerCapture?.(event.pointerId);
        startMarkerLabelRenderLoop();
    }

    function updateGapAxisDrag(event) {
        if (!gapAxisDragState || gapAxisDragState.pointerId !== event.pointerId) {
            return;
        }

        event.preventDefault();
        const deltaY = event.clientY - gapAxisDragState.startY;
        const nextScale = gapAxisDragState.startScale * Math.exp(-deltaY / GAP_AXIS_DRAG_SENSITIVITY);
        setGapAxisScale(nextScale);
    }

    function stopGapAxisDrag(event) {
        if (!gapAxisDragState || (event.pointerId !== undefined && gapAxisDragState.pointerId !== event.pointerId)) {
            return;
        }

        if (gapAxisLayer?.hasPointerCapture?.(gapAxisDragState.pointerId)) {
            gapAxisLayer.releasePointerCapture(gapAxisDragState.pointerId);
        }
        gapAxisDragState = null;
        gapAxisLayer?.classList.remove('dragging');
        stopMarkerLabelRenderLoop();
    }

    function resetGapAxisScale() {
        setGapAxisScale(1);
    }

    function scheduleMarkerLabelRender() {
        requestAnimationFrame(() => {
            requestAnimationFrame(renderChartOverlays);
        });
    }

    let markerLabelRenderLoop = null;
    function startMarkerLabelRenderLoop() {
        if (markerLabelRenderLoop !== null) {
            return;
        }

        const tick = () => {
            renderChartOverlays();
            markerLabelRenderLoop = requestAnimationFrame(tick);
        };
        markerLabelRenderLoop = requestAnimationFrame(tick);
    }

    function stopMarkerLabelRenderLoop() {
        if (markerLabelRenderLoop === null) {
            return;
        }

        cancelAnimationFrame(markerLabelRenderLoop);
        markerLabelRenderLoop = null;
        scheduleMarkerLabelRender();
    }

    const seriesByToggle = {
        candles: [candleSeries, volumeSeries],
        movingAverage: [movingAverageSeries],
        dailyMa5: [dailyMa5Series],
        gap: [gapSeries, gapHistogramSeries, gapZeroSeries],
        bias: [biasSeries],
        biasGapDiff: [biasGapDiffSeries],
        biasRate: [biasRateSeries]
    };

    function applySeriesVisibility() {
        Object.entries(seriesByToggle).forEach(([key, seriesList]) => {
            const visible = Boolean(seriesVisibility[key]);
            seriesList.forEach(series => series.applyOptions({ visible }));
            document
                .querySelectorAll(`[data-series-control="${key}"]`)
                .forEach(item => item.classList.toggle('is-disabled', !visible));
            document
                .querySelectorAll(`input[data-toggle-series="${key}"]`)
                .forEach(input => {
                    input.checked = visible;
                });
        });

        candleSeries.setMarkers(seriesVisibility.candles ? chartMarkerData(activeGapMarkers()) : []);
        refreshGapAxisScale();
    }

    document.querySelectorAll('input[data-toggle-series]').forEach(input => {
        const key = input.dataset.toggleSeries;
        input.checked = Boolean(seriesVisibility[key]);
        input.addEventListener('change', () => {
            seriesVisibility[key] = input.checked;
            applySeriesVisibility();
        });
    });

    const markerCount = document.querySelector('[data-marker-count]');
    const temporaryPriceLines = [];
    let markerClickStart = null;
    const TEMPORARY_LINE_HIT_RADIUS = 8;
    const TEMPORARY_LINE_CLICK_MOVE_LIMIT = 6;

    function updateMarkerCount() {
        markerCount.textContent = temporaryPriceLines.length.toLocaleString('zh-TW');
    }

    function pointerInfo(event) {
        const rect = chartElement.getBoundingClientRect();
        const y = event.clientY - rect.top;
        if (y < 0 || y > rect.height) {
            return null;
        }

        const gap = gapSeries.coordinateToPrice(y);
        return Number.isFinite(gap) ? { gap, y } : null;
    }

    function formatSignedGapValue(value) {
        const roundedValue = Math.round(value);
        const sign = roundedValue > 0 ? '+' : roundedValue < 0 ? '-' : '';
        return `${sign}${Math.abs(roundedValue).toLocaleString('zh-TW')}`;
    }

    function temporaryGapLineColor(gap) {
        return gap >= 0 ? '#f59e0b' : '#38bdf8';
    }

    function createTemporaryPriceLine(gap) {
        const roundedGap = Math.round(gap);
        const line = gapSeries.createPriceLine({
            price: roundedGap,
            color: temporaryGapLineColor(roundedGap),
            lineWidth: 2,
            lineStyle: LightweightCharts.LineStyle.Solid,
            axisLabelVisible: false,
            title: `差值 ${formatSignedGapValue(roundedGap)}`,
        });

        temporaryPriceLines.push({ line, price: roundedGap, color: temporaryGapLineColor(roundedGap) });
        updateMarkerCount();
        scheduleMarkerLabelRender();
    }

    function nearestTemporaryPriceLineIndex(price, y = null, maxDistance = null) {
        if (temporaryPriceLines.length === 0) {
            return -1;
        }

        const best = temporaryPriceLines.reduce((currentBest, item, index) => {
            const itemY = y === null ? null : gapSeries.priceToCoordinate(item.price);
            const distance = itemY === null || y === null
                ? Math.abs(item.price - price)
                : Math.abs(itemY - y);

            return distance < currentBest.distance
                ? { index, distance }
                : currentBest;
        }, { index: -1, distance: Number.POSITIVE_INFINITY });

        if (maxDistance !== null && best.distance > maxDistance) {
            return -1;
        }

        return best.index;
    }

    function removeTemporaryPriceLineAt(index) {
        if (index < 0 || index >= temporaryPriceLines.length) {
            return;
        }

        const [removed] = temporaryPriceLines.splice(index, 1);
        gapSeries.removePriceLine(removed.line);
        updateMarkerCount();
        scheduleMarkerLabelRender();
    }

    function toggleTemporaryPriceLine(price, y) {
        const removeIndex = nearestTemporaryPriceLineIndex(price, y, TEMPORARY_LINE_HIT_RADIUS);
        if (removeIndex >= 0) {
            removeTemporaryPriceLineAt(removeIndex);
            return;
        }

        createTemporaryPriceLine(price);
    }

    function removeTemporaryPriceLine(event) {
        if (temporaryPriceLines.length === 0) {
            return;
        }

        const info = pointerInfo(event);
        const removeIndex = info === null
            ? temporaryPriceLines.length - 1
            : nearestTemporaryPriceLineIndex(info.gap, info.y);

        removeTemporaryPriceLineAt(removeIndex);
    }

    function cancelMarkerClick() {
        markerClickStart = null;
    }

    chartElement.addEventListener('pointerdown', event => {
        if (event.button !== 0) {
            return;
        }

        const info = pointerInfo(event);
        if (info === null) {
            return;
        }

        markerClickStart = {
            pointerId: event.pointerId,
            x: event.clientX,
            y: event.clientY,
            chartY: info.y,
            gap: info.gap
        };
    });

    chartElement.addEventListener('pointermove', event => {
        if (markerClickStart === null || markerClickStart.pointerId !== event.pointerId) {
            return;
        }

        const moved = Math.hypot(event.clientX - markerClickStart.x, event.clientY - markerClickStart.y);
        if (moved > TEMPORARY_LINE_CLICK_MOVE_LIMIT) {
            cancelMarkerClick();
        }
    });

    chartElement.addEventListener('pointerup', event => {
        if (markerClickStart === null || markerClickStart.pointerId !== event.pointerId) {
            return;
        }

        const clickStart = markerClickStart;
        cancelMarkerClick();
        toggleTemporaryPriceLine(clickStart.gap, clickStart.chartY);
    });
    chartElement.addEventListener('pointerleave', cancelMarkerClick);
    chartElement.addEventListener('pointercancel', cancelMarkerClick);
    chartElement.addEventListener('contextmenu', event => {
        event.preventDefault();
        cancelMarkerClick();
        removeTemporaryPriceLine(event);
    });

    const MAX_FUTURE_EMPTY_TRADING_DAYS = 2;
    let isApplyingVisibleRange = false;

    function futureEmptyLogicalBars() {
        if (activeTimeframe === 'daily') {
            return MAX_FUTURE_EMPTY_TRADING_DAYS;
        }

        const barsByTradeDate = new Map();
        currentRows.forEach(row => {
            if (!row.tradeDate) {
                return;
            }

            barsByTradeDate.set(row.tradeDate, (barsByTradeDate.get(row.tradeDate) || 0) + 1);
        });

        const observedBarsPerDay = Math.max(1, ...barsByTradeDate.values());
        return observedBarsPerDay * MAX_FUTURE_EMPTY_TRADING_DAYS;
    }

    function maxRightLogicalIndex() {
        return lastLogicalIndex + futureEmptyLogicalBars();
    }

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
        const maxRight = maxRightLogicalIndex();
        if (nextTo > maxRight) {
            nextTo = maxRight;
            nextFrom = nextTo - span;
        }

        if (nextFrom < 0) {
            nextFrom = 0;
            nextTo = Math.min(maxRight, nextFrom + span);
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

        const visibleBars = Math.min(timeframeDatasets[activeTimeframe]?.defaultVisibleBars ?? 180, currentRows.length);
        chart.timeScale().setVisibleLogicalRange({
            from: Math.max(0, lastLogicalIndex - visibleBars + 1),
            to: lastLogicalIndex
        });
        scheduleMarkerLabelRender();
    }

    function showAll() {
        if (lastLogicalIndex < 0) {
            return;
        }

        chart.timeScale().setVisibleLogicalRange({ from: 0, to: lastLogicalIndex });
        scheduleMarkerLabelRender();
    }

    document.querySelector('[data-show-latest]').addEventListener('click', showLatest);
    document.querySelector('[data-show-all]').addEventListener('click', showAll);
    chart.timeScale().subscribeVisibleLogicalRangeChange(range => {
        clampVisibleLogicalRange(range);
        scheduleMarkerLabelRender();
    });
    chartElement.addEventListener('wheel', scheduleMarkerLabelRender, { passive: true });
    chartElement.addEventListener('pointerdown', startMarkerLabelRenderLoop);
    chartElement.addEventListener('pointermove', scheduleMarkerLabelRender);
    chartElement.addEventListener('pointerup', scheduleMarkerLabelRender);
    chartElement.addEventListener('mousedown', startMarkerLabelRenderLoop);
    chartElement.addEventListener('mousemove', scheduleMarkerLabelRender);
    window.addEventListener('pointerup', stopMarkerLabelRenderLoop);
    window.addEventListener('pointercancel', stopMarkerLabelRenderLoop);
    window.addEventListener('mouseup', stopMarkerLabelRenderLoop);
    if (gapAxisLayer) {
        gapAxisLayer.addEventListener('pointerdown', startGapAxisDrag);
        gapAxisLayer.addEventListener('pointermove', updateGapAxisDrag);
        gapAxisLayer.addEventListener('pointerup', stopGapAxisDrag);
        gapAxisLayer.addEventListener('pointercancel', stopGapAxisDrag);
        gapAxisLayer.addEventListener('dblclick', resetGapAxisScale);
    }

    const fields = {
        timeframe: document.querySelector('[data-legend-timeframe]'),
        open: document.querySelector('[data-legend-open]'),
        high: document.querySelector('[data-legend-high]'),
        low: document.querySelector('[data-legend-low]'),
        close: document.querySelector('[data-legend-close]'),
        movingAverageLabel: document.querySelector('[data-legend-ma-label]'),
        movingAverage: document.querySelector('[data-legend-ma]'),
        dailyMa5: document.querySelector('[data-legend-daily-ma5]'),
        gap: document.querySelector('[data-legend-gap]'),
        bias: document.querySelector('[data-legend-bias]'),
        biasGapDiff: document.querySelector('[data-legend-bias-gap-diff]'),
        biasRate: document.querySelector('[data-legend-bias-rate]')
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

    const formatPercent = (value, decimals = 2, signed = false) => {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return '--';
        }
        const number = Number(value) * 100;
        return `${signed && number >= 0 ? '+' : ''}${number.toLocaleString('zh-TW', {
            maximumFractionDigits: decimals,
            minimumFractionDigits: decimals
        })}%`;
    };

    const summaryFields = {
        range: document.querySelector('[data-summary-field="range"]'),
        latestGap: document.querySelector('[data-summary-field="latestGap"]'),
        latestBias: document.querySelector('[data-summary-field="latestBias"]'),
        latestBiasRate: document.querySelector('[data-summary-field="latestBiasRate"]'),
        latestClose: document.querySelector('[data-summary-field="latestClose"]'),
        latestDailyMa5: document.querySelector('[data-summary-field="latestDailyMa5"]'),
        latestMovingAverage: document.querySelector('[data-summary-field="latestMovingAverage"]'),
        maxGap: document.querySelector('[data-summary-field="maxGap"]'),
        minGap: document.querySelector('[data-summary-field="minGap"]')
    };
    const sessionGapList = document.querySelector('[data-session-gap-list]');

    function setArrayContents(target, values) {
        target.splice(0, target.length, ...(Array.isArray(values) ? values : []));
    }

    function toneClass(value) {
        if (value === null || value === undefined || Number.isNaN(Number(value))) {
            return 'muted';
        }

        return Number(value) >= 0 ? 'positive' : 'negative';
    }

    function setTone(element, tone) {
        if (!element) {
            return;
        }

        element.classList.remove('positive', 'negative', 'muted');
        element.classList.add(tone);
    }

    function updateRangeSummary(stats) {
        if (!summaryFields.range) {
            return;
        }

        const lineBreak = document.createElement('br');
        summaryFields.range.replaceChildren(
            document.createTextNode(`${stats.firstDateTime ?? '--'} 到 ${stats.lastDateTime ?? '--'}`),
            lineBreak,
            document.createTextNode(`${format(stats.rowCount, 0)} 根 15K`)
        );
    }

    function updateValueSummary(field, value, options = {}) {
        const element = summaryFields[field];
        if (!element) {
            return;
        }

        element.textContent = options.percent
            ? formatPercent(value, options.decimals ?? 2, options.signed ?? false)
            : format(value, options.decimals ?? 0, options.signed ?? false);

        if (options.tone === 'value') {
            setTone(element, toneClass(value));
        } else if (options.tone) {
            setTone(element, value === null || value === undefined ? 'muted' : options.tone);
        }
    }

    function updateSummary(stats = {}) {
        updateRangeSummary(stats);
        updateValueSummary('latestGap', stats.latestGap, { signed: true, tone: 'value' });
        updateValueSummary('latestBias', stats.latestBias, { signed: true, tone: 'value' });
        updateValueSummary('latestBiasRate', stats.latestBiasRate, { percent: true, signed: true, tone: 'value' });
        updateValueSummary('latestClose', stats.latestClose);
        updateValueSummary('latestDailyMa5', stats.latestDailyMa5);
        updateValueSummary('latestMovingAverage', stats.latestMovingAverage);
        updateValueSummary('maxGap', stats.maxGap, { signed: true, tone: 'positive' });
        updateValueSummary('minGap', stats.minGap, { signed: true, tone: 'negative' });
    }

    function updateSessionGapList(rows = []) {
        if (!sessionGapList) {
            return;
        }

        const fragment = document.createDocumentFragment();
        (Array.isArray(rows) ? rows : []).forEach(row => {
            const chip = document.createElement('div');
            chip.className = 'gap-chip';

            const time = document.createElement('span');
            time.className = 'time';
            time.textContent = `${row.localTime ?? '--'} · ${row.label ?? '--'} · ${row.eventLabel ?? '--'}`;

            const gap = document.createElement('span');
            gap.className = toneClass(row.gap);
            gap.textContent = row.gapText ?? `${format(row.gap, 0, true)}點`;

            const movingAverage = document.createElement('span');
            movingAverage.className = 'muted';
            movingAverage.textContent = `MA ${format(row.dailyMa5, 0)} / ${format(row.movingAverage, 0)}`;

            chip.append(time, gap, movingAverage);
            fragment.appendChild(chip);
        });

        sessionGapList.replaceChildren(fragment);
    }

    function applyFuturesPayload(payload) {
        if (!payload || !Array.isArray(payload.chartRows)) {
            return;
        }

        futuresDataRevision = payload.dataRevision ?? futuresDataRevision;
        setArrayContents(chartRows, payload.chartRows);
        setArrayContents(dailyChartRows, payload.dailyChartRows);
        setArrayContents(gapMarkers, payload.gapMarkers);
        setArrayContents(dailyGapMarkers, payload.dailyGapMarkers);
        setArrayContents(hourlyChartRows, payload.hourlyChartRows);
        setArrayContents(hourlyGapMarkers, payload.hourlyGapMarkers);
        updateSummary(payload.stats || {});
        updateSessionGapList(payload.sessionGapRows || []);
        applyTimeframe(activeTimeframe);
    }

    async function refreshFuturesData(force = false) {
        if (document.visibilityState === 'hidden' || futuresRefreshInFlight) {
            return;
        }

        const now = Date.now();
        if (!force && now - lastFuturesRefreshStartedAt < FUTURES_REFRESH_VISIBLE_GRACE_MS) {
            return;
        }

        futuresRefreshInFlight = true;
        lastFuturesRefreshStartedAt = now;

        try {
            const url = new URL(dataUrl, window.location.origin);
            url.searchParams.set('_', String(now));
            if (futuresDataRevision) {
                url.searchParams.set('revision', futuresDataRevision);
            }
            const response = await fetch(url.toString(), {
                headers: { 'Accept': 'application/json' },
                cache: 'no-store'
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            if (payload.unchanged) {
                futuresDataRevision = payload.dataRevision ?? futuresDataRevision;
                return;
            }

            applyFuturesPayload(payload);
        } catch (error) {
            console.warn('更新台指期 K 線資料失敗', error);
        } finally {
            futuresRefreshInFlight = false;
        }
    }

    function scheduleFuturesRefreshTimer() {
        if (futuresRefreshTimer !== null) {
            clearInterval(futuresRefreshTimer);
        }

        futuresRefreshTimer = setInterval(() => refreshFuturesData(true), FUTURES_REFRESH_INTERVAL_MS);
    }

    function updateLegend(row = currentRows[currentRows.length - 1] ?? {}) {
        const dataset = timeframeDatasets[activeTimeframe] ?? timeframeDatasets['fifteen-minute'];
        fields.timeframe.textContent = dataset.label;
        fields.movingAverageLabel.textContent = dataset.movingAverageLabel;
        fields.open.textContent = format(row?.open);
        fields.high.textContent = format(row?.high);
        fields.low.textContent = format(row?.low);
        fields.close.textContent = format(row?.close);
        fields.movingAverage.textContent = format(row?.movingAverage);
        fields.dailyMa5.textContent = format(row?.dailyMa5);
        fields.gap.textContent = format(row?.gap, 0, true);
        fields.gap.className = Number(row?.gap || 0) >= 0 ? 'positive' : 'negative';
        fields.bias.textContent = format(row?.bias, 0, true);
        fields.biasGapDiff.textContent = format(row?.biasGapDiff, 0, true);
        fields.biasRate.textContent = formatPercent(row?.biasRate, 2, true);
        const biasValue = row?.bias;
        const biasNumber = biasValue === null || biasValue === undefined ? NaN : Number(biasValue);
        const biasTone = Number.isFinite(biasNumber) ? (biasNumber >= 0 ? 'positive' : 'negative') : 'muted';
        fields.bias.className = biasTone;
        fields.biasRate.className = biasTone;
        const biasGapDiffValue = row?.biasGapDiff;
        const biasGapDiffNumber = biasGapDiffValue === null || biasGapDiffValue === undefined ? NaN : Number(biasGapDiffValue);
        fields.biasGapDiff.className = Number.isFinite(biasGapDiffNumber)
            ? (biasGapDiffNumber >= 0 ? 'positive' : 'negative')
            : 'muted';
    }

    chart.subscribeCrosshairMove(param => {
        if (!param.time) {
            updateLegend();
            return;
        }

        updateLegend(legendMap.get(Number(param.time)) || currentRows[currentRows.length - 1]);
    });

    function applyTimeframe(timeframe) {
        const dataset = timeframeDatasets[timeframe] ?? timeframeDatasets['fifteen-minute'];
        activeTimeframe = timeframeDatasets[timeframe] ? timeframe : 'fifteen-minute';
        currentRows = dataset.rows;
        lastLogicalIndex = currentRows.length - 1;
        legendMap = new Map(currentRows.map(row => [Number(row.time), row]));

        const movingAverageData = lineData(currentRows, 'movingAverage');
        const gapData = lineData(currentRows, 'gap');

        candleSeries.setData(candleData(currentRows));
        candleSeries.setMarkers(seriesVisibility.candles ? chartMarkerData(activeGapMarkers()) : []);
        volumeSeries.setData(volumeData(currentRows));
        movingAverageSeries.setData(movingAverageData);
        dailyMa5Series.setData(lineData(currentRows, 'dailyMa5'));
        gapSeries.setData(gapData);
        biasSeries.setData(lineData(currentRows, 'bias'));
        biasGapDiffSeries.setData(lineData(currentRows, 'biasGapDiff'));
        biasRateSeries.setData(lineData(currentRows, 'biasRate'));
        gapHistogramSeries.setData(gapHistogramData(gapData));
        gapZeroSeries.setData(gapZeroData(currentRows));
        chart.applyOptions({
            timeScale: {
                tickMarkFormatter: formatTaipeiAxisTime
            }
        });

        document.querySelectorAll('[data-timeframe]').forEach(button => {
            button.classList.toggle('active', button.dataset.timeframe === timeframe);
        });

        applySeriesVisibility();
        updateLegend();
        showLatest();
        scheduleMarkerLabelRender();
    }

    document.querySelectorAll('[data-timeframe]').forEach(button => {
        button.addEventListener('click', () => applyTimeframe(button.dataset.timeframe));
    });

    const resize = () => {
        chart.applyOptions({ width: chartElement.clientWidth, height: chartElement.clientHeight });
        scheduleMarkerLabelRender();
    };
    window.addEventListener('resize', resize);
    resize();
    applyTimeframe('fifteen-minute');
    scheduleFuturesRefreshTimer();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            refreshFuturesData();
        }
    });
    window.addEventListener('focus', () => refreshFuturesData());
    window.addEventListener('pageshow', event => refreshFuturesData(Boolean(event.persisted)));
</script>
@endif
</body>
</html>
