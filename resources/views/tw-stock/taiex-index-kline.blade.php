<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>加權指數 K 線分析</title>
    <script src="https://cdn.jsdelivr.net/npm/lightweight-charts@4.2.2/dist/lightweight-charts.standalone.production.js"></script>
    <style>
        :root {
            --bg: #0d1117;
            --panel: #141a23;
            --panel-soft: #19212d;
            --line: #2a3443;
            --text: #edf2f7;
            --muted: #94a3b8;
            --red: #ef5350;
            --green: #26a69a;
            --orange: #f59e0b;
            --violet: #a78bfa;
            --blue: #60a5fa;
            --cyan: #22d3ee;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background:
                linear-gradient(rgba(255, 255, 255, 0.028) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.028) 1px, transparent 1px),
                var(--bg);
            background-size: 40px 40px;
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
        }

        button,
        a { font: inherit; }

        .shell {
            width: min(1960px, calc(100vw - 24px));
            margin: 0 auto;
            padding: 16px 0 28px;
        }

        .topbar,
        .title-row,
        .nav-actions,
        .toolbar,
        .intervals,
        .analysis-legend,
        .chart-footer {
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .topbar {
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 12px;
        }

        .title-row {
            align-items: baseline;
            flex-wrap: wrap;
        }

        h1 {
            margin: 0;
            font-size: 24px;
            line-height: 1.2;
            font-weight: 900;
        }

        .symbol {
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .nav-actions {
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .nav-actions a,
        .interval-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 0 13px;
            border: 1px solid var(--line);
            border-radius: 8px;
            color: #dbe3ee;
            background: rgba(20, 26, 35, 0.94);
            text-decoration: none;
            font-size: 13px;
            font-weight: 850;
            cursor: pointer;
        }

        .nav-actions a.active,
        .interval-button.active {
            border-color: rgba(96, 165, 250, 0.72);
            color: #eff6ff;
            background: rgba(37, 99, 235, 0.34);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: minmax(210px, 1.25fr) repeat(6, minmax(125px, 0.7fr));
            gap: 9px;
            margin-bottom: 10px;
        }

        .summary-card,
        .chart-panel,
        .analysis-note {
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(20, 26, 35, 0.96);
            box-shadow: 0 14px 38px rgba(0, 0, 0, 0.2);
        }

        .summary-card {
            min-height: 98px;
            padding: 14px;
        }

        .summary-label {
            color: var(--muted);
            font-size: 12px;
            font-weight: 750;
        }

        .summary-value {
            margin-top: 8px;
            font-size: 22px;
            line-height: 1.1;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
        }

        .summary-sub {
            margin-top: 7px;
            color: var(--muted);
            font-size: 11px;
            line-height: 1.35;
        }

        .positive { color: var(--red); }
        .negative { color: var(--green); }

        .chart-panel { overflow: hidden; }

        .toolbar {
            justify-content: space-between;
            gap: 14px;
            padding: 12px 14px;
            border-bottom: 1px solid var(--line);
            background: rgba(25, 33, 45, 0.92);
        }

        .intervals { flex-wrap: wrap; }

        .analysis-legend {
            justify-content: flex-end;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 12px;
            font-weight: 750;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .legend-line {
            width: 28px;
            height: 3px;
            border-radius: 999px;
            background: var(--cyan);
        }

        .legend-line.low-trend { background: var(--orange); }

        .legend-line.neckline {
            height: 2px;
            background: repeating-linear-gradient(90deg, #fb7185 0 6px, transparent 6px 10px);
        }

        .hover-strip {
            display: grid;
            grid-template-columns: minmax(170px, 1.35fr) repeat(6, minmax(90px, 0.7fr));
            gap: 1px;
            border-bottom: 1px solid var(--line);
            background: var(--line);
        }

        .hover-cell {
            min-height: 50px;
            padding: 9px 11px;
            background: #111720;
            font-variant-numeric: tabular-nums;
        }

        .hover-cell span {
            display: block;
            color: var(--muted);
            font-size: 10px;
            font-weight: 700;
        }

        .hover-cell strong {
            display: block;
            overflow: hidden;
            margin-top: 5px;
            color: var(--text);
            font-size: 13px;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .chart-wrap {
            position: relative;
            height: min(680px, calc(100vh - 310px));
            min-height: 460px;
            background: #0f141c;
        }

        #taiexChart {
            position: absolute;
            inset: 0;
            cursor: crosshair;
        }

        .chart-message {
            position: absolute;
            z-index: 5;
            top: 16px;
            left: 50%;
            max-width: calc(100% - 32px);
            padding: 9px 13px;
            border: 1px solid rgba(96, 165, 250, 0.42);
            border-radius: 8px;
            color: #dbeafe;
            background: rgba(15, 23, 42, 0.9);
            font-size: 12px;
            font-weight: 800;
            transform: translateX(-50%);
            pointer-events: none;
        }

        .chart-message.error {
            border-color: rgba(248, 113, 113, 0.52);
            color: #fecaca;
            background: rgba(69, 10, 10, 0.9);
        }

        .chart-message[hidden] { display: none; }

        .chart-footer {
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            padding: 10px 14px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 11px;
            line-height: 1.55;
        }

        .refresh-status {
            flex: 0 0 auto;
            color: #bfdbfe;
            font-weight: 800;
            white-space: nowrap;
        }

        .analysis-note {
            margin-top: 10px;
            padding: 12px 14px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .analysis-note strong { color: var(--text); }

        @media (max-width: 1180px) {
            .topbar,
            .toolbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .nav-actions,
            .analysis-legend { justify-content: flex-start; }

            .summary-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
            .hover-strip { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }

        @media (max-width: 720px) {
            .shell { width: min(100vw - 12px, 1960px); padding-top: 9px; }
            h1 { font-size: 20px; }
            .summary-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .summary-card:first-child { grid-column: 1 / -1; }
            .hover-strip { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .hover-cell:first-child { grid-column: 1 / -1; }
            .chart-wrap { height: 520px; min-height: 520px; }
            .chart-footer { flex-direction: column; }
        }

        @include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
<main class="shell">
    <header class="topbar">
        <div class="title-row">
            <h1>加權指數 K 線分析</h1>
            <span class="symbol">TAIEX · 現貨指數 · TWSE</span>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.monthly-revenues.index') }}">月營收</a>
            <a href="{{ route('tw-stock.active-etf-operations.index') }}">主動ETF</a>
            <a class="active" href="{{ route('tw-stock.taiex-index.kline') }}">加權指數K線</a>
            <a href="{{ route('tw-stock.taiex-futures.kline') }}">台指期K線</a>
        </nav>
    </header>

    <section class="summary-grid" aria-label="加權指數摘要">
        <article class="summary-card">
            <div class="summary-label">加權指數</div>
            <div class="summary-value" data-summary="latest">--</div>
            <div class="summary-sub" data-summary="change">漲跌 --</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">市場狀態</div>
            <div class="summary-value" data-summary="market">--</div>
            <div class="summary-sub" data-summary="quotedAt">指數時間 --</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">開盤</div>
            <div class="summary-value" data-summary="open">--</div>
            <div class="summary-sub">當日指數</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">最高</div>
            <div class="summary-value" data-summary="high">--</div>
            <div class="summary-sub">當日指數</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">最低</div>
            <div class="summary-value" data-summary="low">--</div>
            <div class="summary-sub">當日指數</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">昨收</div>
            <div class="summary-value" data-summary="previousClose">--</div>
            <div class="summary-sub">比較基準</div>
        </article>
        <article class="summary-card">
            <div class="summary-label">目前週期</div>
            <div class="summary-value" data-summary="interval">1 分 K</div>
            <div class="summary-sub">15 秒更新一次</div>
        </article>
    </section>

    <section class="chart-panel">
        <div class="toolbar">
            <div class="intervals" role="group" aria-label="K 線週期">
                <button class="interval-button active" type="button" data-interval="1m">1分K線</button>
                <button class="interval-button" type="button" data-interval="5m">5分K線</button>
                <button class="interval-button" type="button" data-interval="15m">15分K線</button>
                <button class="interval-button" type="button" data-interval="1d">日K線</button>
            </div>
            <div class="analysis-legend" aria-label="技術線圖例">
                <span class="legend-item"><i class="legend-line"></i><span data-high-trend-label>高點趨勢線分析中</span></span>
                <span class="legend-item"><i class="legend-line low-trend"></i><span data-low-trend-label>低點趨勢線分析中</span></span>
                <span class="legend-item"><i class="legend-line neckline"></i><span data-neckline-label>水平頸線分析中</span></span>
            </div>
        </div>

        <div class="hover-strip" aria-live="polite">
            <div class="hover-cell"><span>位置時間</span><strong data-hover="time">--</strong></div>
            <div class="hover-cell"><span>開</span><strong data-hover="open">--</strong></div>
            <div class="hover-cell"><span>高</span><strong data-hover="high">--</strong></div>
            <div class="hover-cell"><span>低</span><strong data-hover="low">--</strong></div>
            <div class="hover-cell"><span>收</span><strong data-hover="close">--</strong></div>
            <div class="hover-cell"><span>K 棒漲跌</span><strong data-hover="change">--</strong></div>
            <div class="hover-cell"><span>量</span><strong data-hover="volume">--</strong></div>
        </div>

        <div class="chart-wrap">
            <div id="taiexChart" aria-label="加權指數 K 線圖"></div>
            <div class="chart-message" data-chart-message>讀取 TWSE 指數資料中…</div>
        </div>

        <footer class="chart-footer">
            <div data-source-note>資料來源：臺灣證券交易所（TWSE）。</div>
            <div class="refresh-status" data-refresh-status>準備更新</div>
        </footer>
    </section>

    <aside class="analysis-note">
        <strong>自動連線說明：</strong>
        藍線連接最近兩個波段高點，橘線連接最近兩個波段低點，依斜率標示上升或下降趨勢；紅色虛線以最近有效的型態轉折位繪製水平頸線。線旁數字是延伸到最新 K 棒的參考點位，全部都會隨 15 秒行情刷新重新計算。滑鼠移入圖表時會顯示水平價格虛線與垂直時間虛線，方便快速定位點位。
    </aside>
</main>

<script>
(() => {
    const dataUrl = @json(route('tw-stock.taiex-index.kline.data'));
    const refreshEveryMs = 15000;
    const chartElement = document.getElementById('taiexChart');
    const chartMessage = document.querySelector('[data-chart-message]');
    const refreshStatus = document.querySelector('[data-refresh-status]');
    const highTrendLabel = document.querySelector('[data-high-trend-label]');
    const lowTrendLabel = document.querySelector('[data-low-trend-label]');
    const necklineLabel = document.querySelector('[data-neckline-label]');
    const sourceNote = document.querySelector('[data-source-note]');
    const intervalButtons = [...document.querySelectorAll('[data-interval]')];
    const taipeiAxisFormatter = new Intl.DateTimeFormat('zh-TW', {
        timeZone: 'Asia/Taipei',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
    const taipeiDateFormatter = new Intl.DateTimeFormat('zh-TW', {
        timeZone: 'Asia/Taipei',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    });
    const taipeiDateTimeFormatter = new Intl.DateTimeFormat('zh-TW', {
        timeZone: 'Asia/Taipei',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });

    let activeInterval = '1m';
    let currentBars = [];
    let barsByTime = new Map();
    let requestSequence = 0;

    function numeric(value) {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function formatIndex(value) {
        const parsed = numeric(value);
        return parsed === null ? '--' : parsed.toLocaleString('zh-TW', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function formatSigned(value, suffix = '') {
        const parsed = numeric(value);
        if (parsed === null) return '--';
        const sign = parsed > 0 ? '+' : '';
        return `${sign}${formatIndex(parsed)}${suffix}`;
    }

    function formatVolume(value) {
        const parsed = numeric(value);
        return parsed === null ? '--' : Math.round(parsed).toLocaleString('zh-TW');
    }

    function formatTime(timestamp) {
        const parsed = numeric(timestamp);
        if (parsed === null) return '--';
        const date = new Date(parsed * 1000);
        return activeInterval === '1d'
            ? taipeiDateFormatter.format(date)
            : taipeiDateTimeFormatter.format(date);
    }

    function axisTimeFormatter(time) {
        const timestamp = numeric(time);
        if (timestamp === null) return '';
        const date = new Date(timestamp * 1000);
        return activeInterval === '1d'
            ? taipeiDateFormatter.format(date).replace(/^\d{4}[\/-]/, '')
            : taipeiAxisFormatter.format(date);
    }

    const chart = LightweightCharts.createChart(chartElement, {
        layout: {
            background: { color: '#0f141c' },
            textColor: '#cbd5e1',
        },
        grid: {
            vertLines: { color: 'rgba(148, 163, 184, 0.12)' },
            horzLines: { color: 'rgba(148, 163, 184, 0.12)' },
        },
        crosshair: {
            mode: LightweightCharts.CrosshairMode.Normal,
            vertLine: {
                color: 'rgba(226, 232, 240, 0.78)',
                width: 1,
                style: LightweightCharts.LineStyle.LargeDashed,
                visible: true,
                labelVisible: true,
                labelBackgroundColor: '#334155',
            },
            horzLine: {
                color: 'rgba(250, 204, 21, 0.88)',
                width: 1,
                style: LightweightCharts.LineStyle.LargeDashed,
                visible: true,
                labelVisible: true,
                labelBackgroundColor: '#a16207',
            },
        },
        localization: {
            priceFormatter: formatIndex,
            timeFormatter: formatTime,
        },
        rightPriceScale: {
            borderColor: '#2a3443',
            scaleMargins: { top: 0.08, bottom: 0.22 },
        },
        timeScale: {
            borderColor: '#2a3443',
            timeVisible: true,
            secondsVisible: false,
            rightOffset: 4,
            barSpacing: 8,
            minBarSpacing: 2,
            fixLeftEdge: true,
            tickMarkFormatter: axisTimeFormatter,
        },
        handleScroll: {
            mouseWheel: true,
            pressedMouseMove: true,
            horzTouchDrag: true,
            vertTouchDrag: false,
        },
        handleScale: {
            axisPressedMouseMove: true,
            mouseWheel: true,
            pinch: true,
        },
    });

    const candleSeries = chart.addCandlestickSeries({
        upColor: '#ef5350',
        downColor: '#26a69a',
        borderUpColor: '#ef5350',
        borderDownColor: '#26a69a',
        wickUpColor: '#ef5350',
        wickDownColor: '#26a69a',
        priceFormat: { type: 'price', precision: 2, minMove: 0.01 },
    });
    const volumeSeries = chart.addHistogramSeries({
        priceScaleId: 'volume',
        priceFormat: { type: 'volume' },
        priceLineVisible: false,
        lastValueVisible: false,
    });
    const highTrendSeries = chart.addLineSeries({
        color: '#22d3ee',
        lineWidth: 3,
        priceLineVisible: false,
        lastValueVisible: true,
        crosshairMarkerVisible: false,
        title: '高點趨勢線',
    });
    const lowTrendSeries = chart.addLineSeries({
        color: '#f59e0b',
        lineWidth: 3,
        priceLineVisible: false,
        lastValueVisible: true,
        crosshairMarkerVisible: false,
        title: '低點趨勢線',
    });
    const necklineSeries = chart.addLineSeries({
        color: '#fb7185',
        lineWidth: 2,
        lineStyle: LightweightCharts.LineStyle.Dashed,
        priceLineVisible: false,
        lastValueVisible: true,
        crosshairMarkerVisible: false,
        title: '水平頸線',
    });
    chart.priceScale('volume').applyOptions({
        scaleMargins: { top: 0.84, bottom: 0 },
    });

    function findPivots(bars, key, kind, radius = 2) {
        const pivots = [];
        for (let index = radius; index < bars.length - radius; index += 1) {
            const value = numeric(bars[index][key]);
            if (value === null) continue;
            let pivot = true;
            for (let offset = -radius; offset <= radius; offset += 1) {
                if (offset === 0) continue;
                const compared = numeric(bars[index + offset][key]);
                if (compared === null) continue;
                if ((kind === 'high' && compared > value) || (kind === 'low' && compared < value)) {
                    pivot = false;
                    break;
                }
            }
            if (pivot) pivots.push(index);
        }
        return pivots;
    }

    function extremeIndex(bars, key, kind, start, end) {
        let selected = null;
        for (let index = Math.max(0, start); index <= Math.min(end, bars.length - 1); index += 1) {
            const value = numeric(bars[index][key]);
            if (value === null) continue;
            if (selected === null) {
                selected = index;
                continue;
            }
            const selectedValue = Number(bars[selected][key]);
            if ((kind === 'high' && value > selectedValue) || (kind === 'low' && value < selectedValue)) {
                selected = index;
            }
        }
        return selected;
    }

    function fallbackAnchorPair(bars, key, kind, start) {
        const end = bars.length - 1;
        if (end - start < 1) return null;
        const middle = Math.floor((start + end) / 2);
        const first = extremeIndex(bars, key, kind, start, middle);
        const second = extremeIndex(bars, key, kind, middle + 1, end);
        return first === null || second === null || first === second ? null : [first, second];
    }

    function projectedLine(bars, firstIndex, secondIndex, key, label) {
        if (firstIndex === null || secondIndex === null || firstIndex >= secondIndex) return null;
        const firstValue = Number(bars[firstIndex][key]);
        const secondValue = Number(bars[secondIndex][key]);
        const slope = (secondValue - firstValue) / (secondIndex - firstIndex);
        const endIndex = bars.length - 1;
        const projectedValue = secondValue + slope * (endIndex - secondIndex);
        const data = [
            { time: Number(bars[firstIndex].time), value: Number(firstValue.toFixed(2)) },
            { time: Number(bars[secondIndex].time), value: Number(secondValue.toFixed(2)) },
        ];
        if (endIndex > secondIndex) {
            data.push({ time: Number(bars[endIndex].time), value: Number(projectedValue.toFixed(2)) });
        }
        return {
            label,
            slope,
            data,
            anchors: [firstIndex, secondIndex],
            currentValue: Number(projectedValue.toFixed(2)),
        };
    }

    function computeTrendLine(bars, key, kind, labelPrefix) {
        if (bars.length < 2) return null;
        const start = Math.max(0, bars.length - (activeInterval === '1d' ? 100 : 120));
        const pivots = findPivots(bars, key, kind).filter(index => index >= start);
        const anchors = pivots.length >= 2
            ? pivots.slice(-2)
            : fallbackAnchorPair(bars, key, kind, start);
        if (!anchors) return null;
        const slope = (Number(bars[anchors[1]][key]) - Number(bars[anchors[0]][key])) / (anchors[1] - anchors[0]);
        return projectedLine(
            bars,
            anchors[0],
            anchors[1],
            key,
            `${labelPrefix}${slope >= 0 ? '上升' : '下降'}趨勢線`
        );
    }

    function nearestBefore(indices, target) {
        return [...indices].reverse().find(index => index < target) ?? null;
    }

    function nearestAfter(indices, target) {
        return indices.find(index => index > target) ?? null;
    }

    function horizontalLine(bars, startIndex, level, label) {
        if (!Number.isFinite(level) || bars.length < 2) return null;
        const firstIndex = Math.max(0, Math.min(startIndex, bars.length - 2));
        const lastIndex = bars.length - 1;
        const value = Number(level.toFixed(2));
        return {
            label,
            currentValue: value,
            data: [
                { time: Number(bars[firstIndex].time), value },
                { time: Number(bars[lastIndex].time), value },
            ],
        };
    }

    function computeHorizontalNeckline(bars) {
        if (bars.length < 2) return null;
        const start = Math.max(0, bars.length - (activeInterval === '1d' ? 120 : 140));
        const highs = findPivots(bars, 'high', 'high').filter(index => index >= start);
        const lows = findPivots(bars, 'low', 'low').filter(index => index >= start);
        const topHead = extremeIndex(bars, 'high', 'high', start + 1, bars.length - 2);
        const bottomHead = extremeIndex(bars, 'low', 'low', start + 1, bars.length - 2);
        const candidates = [];

        if (topHead !== null) {
            const before = nearestBefore(lows, topHead);
            const after = nearestAfter(lows, topHead);
            if (before !== null && after !== null) {
                candidates.push({ anchors: [before, after], key: 'low', label: '頭肩頂水平頸線' });
            }
        }

        if (bottomHead !== null) {
            const before = nearestBefore(highs, bottomHead);
            const after = nearestAfter(highs, bottomHead);
            if (before !== null && after !== null) {
                candidates.push({ anchors: [before, after], key: 'high', label: '頭肩底水平頸線' });
            }
        }

        let selected = candidates.sort((a, b) => b.anchors[1] - a.anchors[1])[0] ?? null;
        if (selected === null) {
            const direction = Number(bars.at(-1).close) - Number(bars[start].close);
            const key = direction >= 0 ? 'high' : 'low';
            const kind = direction >= 0 ? 'high' : 'low';
            const pivots = (kind === 'high' ? highs : lows).slice(-2);
            const anchors = pivots.length >= 2 ? pivots : fallbackAnchorPair(bars, key, kind, start);
            if (!anchors) return null;
            selected = {
                anchors,
                key,
                label: direction >= 0 ? '近期壓力水平頸線' : '近期支撐水平頸線',
            };
        }

        const firstValue = Number(bars[selected.anchors[0]][selected.key]);
        const secondValue = Number(bars[selected.anchors[1]][selected.key]);
        return horizontalLine(
            bars,
            Math.min(selected.anchors[0], selected.anchors[1]),
            (firstValue + secondValue) / 2,
            selected.label
        );
    }

    function updateAnalysisLines(bars) {
        const highTrend = computeTrendLine(bars, 'high', 'high', '高點');
        const lowTrend = computeTrendLine(bars, 'low', 'low', '低點');
        const neckline = computeHorizontalNeckline(bars);
        highTrendSeries.setData(highTrend?.data ?? []);
        lowTrendSeries.setData(lowTrend?.data ?? []);
        necklineSeries.setData(neckline?.data ?? []);
        highTrendSeries.applyOptions({ title: highTrend?.label ?? '高點趨勢線' });
        lowTrendSeries.applyOptions({ title: lowTrend?.label ?? '低點趨勢線' });
        necklineSeries.applyOptions({ title: neckline?.label ?? '水平頸線' });
        highTrendLabel.textContent = highTrend
            ? `${highTrend.label} ${formatIndex(highTrend.currentValue)}`
            : '高點趨勢線資料不足';
        lowTrendLabel.textContent = lowTrend
            ? `${lowTrend.label} ${formatIndex(lowTrend.currentValue)}`
            : '低點趨勢線資料不足';
        necklineLabel.textContent = neckline
            ? `${neckline.label} ${formatIndex(neckline.currentValue)}`
            : '水平頸線資料不足';
    }

    function setTone(element, value) {
        element.classList.remove('positive', 'negative');
        const parsed = numeric(value);
        if (parsed > 0) element.classList.add('positive');
        if (parsed < 0) element.classList.add('negative');
    }

    function updateSummary(payload) {
        const quote = payload.quote ?? {};
        const latest = document.querySelector('[data-summary="latest"]');
        const change = document.querySelector('[data-summary="change"]');
        latest.textContent = formatIndex(quote.latest);
        change.textContent = `漲跌 ${formatSigned(quote.change)}（${formatSigned(quote.changeRate, '%')}）`;
        setTone(latest, quote.change);
        setTone(change, quote.change);
        document.querySelector('[data-summary="market"]').textContent = payload.market?.label ?? '--';
        document.querySelector('[data-summary="quotedAt"]').textContent = `指數時間 ${quote.quotedAt ?? '--'}`;
        document.querySelector('[data-summary="open"]').textContent = formatIndex(quote.open);
        document.querySelector('[data-summary="high"]').textContent = formatIndex(quote.high);
        document.querySelector('[data-summary="low"]').textContent = formatIndex(quote.low);
        document.querySelector('[data-summary="previousClose"]').textContent = formatIndex(quote.previousClose);
        document.querySelector('[data-summary="interval"]').textContent = payload.intervalLabel ?? '--';
        sourceNote.textContent = `資料來源：${payload.source ?? 'TWSE'}。${payload.sourceNote ?? ''}`;
    }

    function renderHover(bar) {
        if (!bar) return;
        const change = Number(bar.close) - Number(bar.open);
        const values = {
            time: formatTime(bar.time),
            open: formatIndex(bar.open),
            high: formatIndex(bar.high),
            low: formatIndex(bar.low),
            close: formatIndex(bar.close),
            change: formatSigned(change),
            volume: formatVolume(bar.volume),
        };
        for (const [key, value] of Object.entries(values)) {
            const element = document.querySelector(`[data-hover="${key}"]`);
            if (!element) continue;
            element.textContent = value;
            if (key === 'change') setTone(element, change);
        }
    }

    chart.subscribeCrosshairMove(param => {
        if (!param.time || !param.point || param.point.x < 0 || param.point.y < 0) {
            renderHover(currentBars.at(-1));
            return;
        }
        const candle = param.seriesData.get(candleSeries);
        if (!candle) return;
        const original = barsByTime.get(String(param.time)) ?? {};
        renderHover({ ...original, ...candle, time: Number(param.time) });
    });

    function normalizeBars(rows) {
        return (Array.isArray(rows) ? rows : [])
            .map(row => ({
                time: Number(row.time),
                open: Number(row.open),
                high: Number(row.high),
                low: Number(row.low),
                close: Number(row.close),
                volume: Number(row.volume ?? 0),
                localTime: row.localTime ?? null,
            }))
            .filter(row => [row.time, row.open, row.high, row.low, row.close].every(Number.isFinite))
            .sort((a, b) => a.time - b.time);
    }

    function showMessage(message, error = false) {
        chartMessage.textContent = message;
        chartMessage.classList.toggle('error', error);
        chartMessage.hidden = false;
    }

    function hideMessage() {
        chartMessage.hidden = true;
        chartMessage.classList.remove('error');
    }

    async function loadData({ fit = false } = {}) {
        const sequence = ++requestSequence;
        refreshStatus.textContent = '更新中…';
        if (currentBars.length === 0) showMessage('讀取 TWSE 指數資料中…');

        try {
            const response = await fetch(`${dataUrl}?interval=${encodeURIComponent(activeInterval)}`, {
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const payload = await response.json();
            if (sequence !== requestSequence) return;

            const bars = normalizeBars(payload.bars);
            if (bars.length === 0) throw new Error('目前沒有可顯示的 K 線資料');
            currentBars = bars;
            barsByTime = new Map(bars.map(bar => [String(bar.time), bar]));
            candleSeries.setData(bars.map(({ time, open, high, low, close }) => ({ time, open, high, low, close })));
            volumeSeries.setData(bars.map(bar => ({
                time: bar.time,
                value: bar.volume,
                color: bar.close >= bar.open ? 'rgba(239, 83, 80, 0.42)' : 'rgba(38, 166, 154, 0.42)',
            })));
            updateAnalysisLines(bars);
            updateSummary(payload);
            renderHover(bars.at(-1));
            hideMessage();
            if (fit) chart.timeScale().fitContent();
            refreshStatus.textContent = `已更新 ${payload.refreshedAt ?? ''} · 每 15 秒刷新`;
        } catch (error) {
            if (sequence !== requestSequence) return;
            showMessage(`指數資料更新失敗：${error instanceof Error ? error.message : '未知錯誤'}`, true);
            refreshStatus.textContent = '更新失敗，15 秒後重試';
        }
    }

    intervalButtons.forEach(button => {
        button.addEventListener('click', () => {
            const interval = button.dataset.interval;
            if (!interval || interval === activeInterval) return;
            activeInterval = interval;
            intervalButtons.forEach(item => item.classList.toggle('active', item === button));
            currentBars = [];
            barsByTime = new Map();
            loadData({ fit: true });
        });
    });

    new ResizeObserver(entries => {
        const entry = entries[0];
        if (!entry) return;
        chart.applyOptions({
            width: Math.floor(entry.contentRect.width),
            height: Math.floor(entry.contentRect.height),
        });
    }).observe(chartElement);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') loadData();
    });
    window.addEventListener('focus', () => loadData());

    loadData({ fit: true });
    window.setInterval(() => loadData(), refreshEveryMs);
})();
</script>
</body>
</html>
