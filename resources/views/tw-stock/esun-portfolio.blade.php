<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>玉山庫存即時看板</title>
    <style>
        :root {
            --bg: #181818;
            --panel: #262626;
            --panel-soft: #303030;
            --panel-hard: #111111;
            --line: #3c3c3c;
            --text: #f3f4f6;
            --muted: #9ca3af;
            --muted-2: #737373;
            --red: #ff3b5c;
            --green: #22c55e;
            --amber: #f59e0b;
            --cyan: #38bdf8;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            background: var(--bg);
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        .shell {
            width: min(1680px, calc(100vw - 28px));
            margin: 0 auto;
            padding: 18px 0 42px;
        }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 12px 0 14px;
            border-bottom: 1px solid var(--line);
        }

        .title-block {
            min-width: 0;
        }

        h1 {
            margin: 0;
            font-size: 28px;
            line-height: 1.18;
            font-weight: 900;
        }

        .subtitle {
            margin-top: 7px;
            color: var(--muted);
            font-size: 13px;
        }

        .status-bar {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 34px;
            padding: 0 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            white-space: nowrap;
        }

        .pill.live {
            color: var(--green);
            border-color: rgba(34, 197, 94, 0.45);
            background: rgba(34, 197, 94, 0.1);
        }

        .pill.error {
            color: var(--red);
            border-color: rgba(255, 59, 92, 0.45);
            background: rgba(255, 59, 92, 0.1);
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(6, minmax(0, 1fr));
            gap: 10px;
            margin: 16px 0 12px;
        }

        .summary-card {
            min-height: 116px;
            padding: 16px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
        }

        .label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .value {
            margin-top: 9px;
            font-size: 30px;
            line-height: 1.08;
            font-weight: 900;
            font-variant-numeric: tabular-nums;
        }

        .sub {
            margin-top: 9px;
            color: var(--muted-2);
            font-size: 12px;
            line-height: 1.45;
            font-variant-numeric: tabular-nums;
        }

        .positive { color: var(--red); }
        .negative { color: var(--green); }
        .neutral { color: var(--text); }
        .amber { color: var(--amber); }
        .cyan { color: var(--cyan); }
        .muted { color: var(--muted); }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 12px;
            padding: 12px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel-hard);
        }

        .tabs,
        .controls {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
        }

        .tab,
        .button,
        input {
            min-height: 36px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            color: var(--text);
            padding: 0 12px;
            font: inherit;
            font-size: 13px;
            font-weight: 800;
        }

        .tab.active {
            border-color: #d4d4d4;
            background: #62615c;
        }

        .button {
            cursor: pointer;
        }

        .button:hover {
            border-color: #737373;
        }

        input {
            width: 210px;
            outline: none;
        }

        .table-wrap {
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
        }

        table {
            width: 100%;
            min-width: 1280px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 12px 10px;
            border-bottom: 1px solid #363636;
            text-align: right;
            white-space: nowrap;
            font-size: 14px;
            font-variant-numeric: tabular-nums;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            color: #c7c7c7;
            background: #333333;
            font-size: 13px;
            font-weight: 900;
        }

        td:first-child,
        th:first-child {
            position: sticky;
            left: 0;
            z-index: 2;
            text-align: left;
            background: #242424;
            box-shadow: 1px 0 0 #111;
        }

        th:first-child {
            z-index: 3;
            background: #333333;
        }

        tr:hover td {
            background: #2e2e2e;
        }

        tr:hover td:first-child {
            background: #292929;
        }

        .stock-cell {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px 10px;
            align-items: center;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            border-radius: 8px;
            color: #ffb020;
            background: rgba(245, 158, 11, 0.13);
            font-size: 12px;
            font-weight: 900;
        }

        .stock-name {
            font-size: 17px;
            font-weight: 900;
            color: #f5f5f5;
        }

        .stock-code {
            margin-top: 2px;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
        }

        .section-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 20px 0 10px;
        }

        .section-title h2 {
            margin: 0;
            font-size: 20px;
        }

        .empty,
        .error-box {
            padding: 24px;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: var(--panel);
            color: var(--muted);
            text-align: center;
        }

        .error-box {
            color: #fecdd3;
            border-color: rgba(255, 59, 92, 0.45);
            background: rgba(255, 59, 92, 0.1);
        }

        .mini {
            font-size: 12px;
            color: var(--muted);
        }

        @media (max-width: 1180px) {
            .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }

        @media (max-width: 760px) {
            .shell {
                width: min(100vw - 16px, 760px);
                padding-top: 10px;
            }

            .topbar,
            .toolbar {
                align-items: stretch;
                flex-direction: column;
            }

            .status-bar,
            .controls {
                justify-content: flex-start;
            }

            h1 { font-size: 24px; }
            .summary-grid { grid-template-columns: 1fr; }
            .value { font-size: 28px; }
            input { width: 100%; }
        }
    </style>
</head>
<body>
<div class="shell" data-dashboard>
    <header class="topbar">
        <div class="title-block">
            <h1>玉山庫存即時看板</h1>
            <div class="subtitle">正式 API · 開盤每 2 秒刷新畫面 · 後端依玉山限制快取查詢</div>
        </div>
        <div class="status-bar">
            <span class="pill" data-market-status>{{ $initialMarket['label'] }}</span>
            <span class="pill" data-refresh-status>等待更新</span>
            <span class="pill" data-last-updated>--</span>
        </div>
    </header>

    <section class="summary-grid">
        <div class="summary-card">
            <div class="label">今日損益</div>
            <div class="value" data-summary="todayPnl">--</div>
            <div class="sub" data-summary="todayPnlRate">--</div>
        </div>
        <div class="summary-card">
            <div class="label">累積損益</div>
            <div class="value" data-summary="unrealizedPnl">--</div>
            <div class="sub" data-summary="unrealizedPnlRate">--</div>
        </div>
        <div class="summary-card">
            <div class="label">股票市值</div>
            <div class="value neutral" data-summary="marketValue">--</div>
            <div class="sub" data-summary="costBasis">成本 --</div>
        </div>
        <div class="summary-card">
            <div class="label">庫存檔數</div>
            <div class="value neutral" data-summary="stockCount">--</div>
            <div class="sub" data-summary="lotCount">明細 --</div>
        </div>
        <div class="summary-card">
            <div class="label">總股數</div>
            <div class="value neutral" data-summary="shareCount">--</div>
            <div class="sub">依玉山庫存回傳股數</div>
        </div>
        <div class="summary-card">
            <div class="label">資料來源</div>
            <div class="value cyan" data-summary="sourceAge">--</div>
            <div class="sub" data-summary="servedAt">--</div>
        </div>
    </section>

    <section class="toolbar">
        <div class="tabs">
            <span class="tab active">即時損益</span>
            <span class="tab">庫存明細</span>
        </div>
        <div class="controls">
            <input type="search" placeholder="搜尋代號或名稱" data-filter>
            <button class="button" type="button" data-force-refresh>立即刷新</button>
        </div>
    </section>

    <div data-error class="error-box" style="display: none;"></div>

    <section class="table-wrap" data-position-wrap>
        <table>
            <thead>
            <tr>
                <th>庫存股</th>
                <th>股價<br>漲跌幅</th>
                <th>今日<br>即時損益</th>
                <th>總損益<br>報酬率</th>
                <th>股數</th>
                <th>均價<br>總成本</th>
                <th>市值<br>占比</th>
                <th>損益<br>平衡價</th>
                <th>近5日<br>漲幅</th>
                <th>近20日<br>漲幅</th>
                <th>今年以來<br>漲幅</th>
                <th>明細</th>
            </tr>
            </thead>
            <tbody data-positions>
            <tr><td colspan="12" class="empty">讀取中</td></tr>
            </tbody>
        </table>
    </section>

    <div class="section-title">
        <h2>庫存批次明細</h2>
        <span class="mini" data-lot-count>--</span>
    </div>

    <section class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>庫存股</th>
                <th>成交日</th>
                <th>買賣</th>
                <th>股數</th>
                <th>成交價</th>
                <th>損益平衡價</th>
                <th>手續費</th>
                <th>稅</th>
                <th>淨收付</th>
                <th>市值</th>
                <th>未實現損益</th>
                <th>報酬率</th>
            </tr>
            </thead>
            <tbody data-lots>
            <tr><td colspan="12" class="empty">讀取中</td></tr>
            </tbody>
        </table>
    </section>
</div>

<script>
const apiUrl = @json($apiUrl);
const dashboardToken = @json($token);
const state = {
    rows: [],
    lots: [],
    timer: null,
    lastPayload: null,
};

const els = {
    marketStatus: document.querySelector('[data-market-status]'),
    refreshStatus: document.querySelector('[data-refresh-status]'),
    lastUpdated: document.querySelector('[data-last-updated]'),
    positions: document.querySelector('[data-positions]'),
    lots: document.querySelector('[data-lots]'),
    error: document.querySelector('[data-error]'),
    filter: document.querySelector('[data-filter]'),
    lotCount: document.querySelector('[data-lot-count]'),
    forceRefresh: document.querySelector('[data-force-refresh]'),
};

function number(value) {
    const numeric = Number(value);
    return Number.isFinite(numeric) ? numeric : 0;
}

function formatInteger(value) {
    return Math.round(number(value)).toLocaleString('zh-TW');
}

function formatMoney(value) {
    const numeric = number(value);
    const prefix = numeric > 0 ? '+' : '';
    return prefix + Math.round(numeric).toLocaleString('zh-TW');
}

function formatPrice(value) {
    if (value === null || value === undefined || value === '') return '--';
    return number(value).toLocaleString('zh-TW', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatPercent(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '--';
    const numeric = Number(value);
    const prefix = numeric > 0 ? '+' : '';
    return `${prefix}${numeric.toFixed(2)}%`;
}

function toneClass(value) {
    const numeric = Number(value);
    if (numeric > 0) return 'positive';
    if (numeric < 0) return 'negative';
    return 'neutral';
}

function setTone(element, value) {
    element.classList.remove('positive', 'negative', 'neutral');
    element.classList.add(toneClass(value));
}

function updateSummary(payload) {
    const summary = payload.summary || {};
    const today = document.querySelector('[data-summary="todayPnl"]');
    today.textContent = formatMoney(summary.todayPnl);
    setTone(today, summary.todayPnl);

    const todayRate = document.querySelector('[data-summary="todayPnlRate"]');
    todayRate.textContent = formatPercent(summary.todayPnlRate);
    todayRate.className = `sub ${toneClass(summary.todayPnlRate)}`;

    const unrealized = document.querySelector('[data-summary="unrealizedPnl"]');
    unrealized.textContent = formatMoney(summary.unrealizedPnl);
    setTone(unrealized, summary.unrealizedPnl);

    const unrealizedRate = document.querySelector('[data-summary="unrealizedPnlRate"]');
    unrealizedRate.textContent = formatPercent(summary.unrealizedPnlRate);
    unrealizedRate.className = `sub ${toneClass(summary.unrealizedPnlRate)}`;

    document.querySelector('[data-summary="marketValue"]').textContent = formatInteger(summary.marketValue);
    document.querySelector('[data-summary="costBasis"]').textContent = `成本 ${formatInteger(summary.costBasis)}`;
    document.querySelector('[data-summary="stockCount"]').textContent = formatInteger(summary.stockCount);
    document.querySelector('[data-summary="lotCount"]').textContent = `明細 ${formatInteger(summary.lotCount)} 筆`;
    document.querySelector('[data-summary="shareCount"]').textContent = formatInteger(summary.shareCount);
    document.querySelector('[data-summary="sourceAge"]').textContent = payload.market?.isOpen ? 'LIVE' : 'ONCE';
    document.querySelector('[data-summary="servedAt"]').textContent = `後端快取 ${payload.cacheSeconds || 0}s`;
}

function stockCell(row) {
    return `
        <div class="stock-cell">
            <span class="badge">融<br>資</span>
            <div>
                <div class="stock-name">${escapeHtml(row.stockName || '')}</div>
                <div class="stock-code">${escapeHtml(row.stockNo || '')}</div>
            </div>
        </div>
    `;
}

function renderPositions() {
    const keyword = (els.filter.value || '').trim().toLowerCase();
    const rows = state.rows.filter(row => {
        if (!keyword) return true;
        return String(row.stockNo).toLowerCase().includes(keyword)
            || String(row.stockName).toLowerCase().includes(keyword);
    });

    if (!rows.length) {
        els.positions.innerHTML = '<tr><td colspan="12" class="empty">沒有符合條件的庫存</td></tr>';
        return;
    }

    els.positions.innerHTML = rows.map(row => `
        <tr>
            <td>${stockCell(row)}</td>
            <td class="${toneClass(row.dayChangeRate)}">
                <strong>${formatPrice(row.currentPrice)}</strong><br>${formatPercent(row.dayChangeRate)}
            </td>
            <td class="${toneClass(row.todayPnl)}">
                <strong>${formatMoney(row.todayPnl)}</strong><br>${formatPercent(row.dayChangeRate)}
            </td>
            <td class="${toneClass(row.unrealizedPnl)}">
                <strong>${formatMoney(row.unrealizedPnl)}</strong><br>${formatPercent(row.unrealizedPnlRate)}
            </td>
            <td>${formatInteger(row.quantity)}</td>
            <td>${formatPrice(row.averagePrice)}<br><span class="muted">${formatInteger(row.costBasis)}</span></td>
            <td>${formatInteger(row.marketValue)}<br><span class="muted">${formatPercent(row.marketWeight)}</span></td>
            <td>${formatPrice(row.breakevenPrice)}</td>
            <td class="${toneClass(row.fiveDayReturn)}">${formatPercent(row.fiveDayReturn)}</td>
            <td class="${toneClass(row.twentyDayReturn)}">${formatPercent(row.twentyDayReturn)}</td>
            <td class="${toneClass(row.yearToDateReturn)}">${formatPercent(row.yearToDateReturn)}</td>
            <td>${formatInteger(row.lotCount)} 筆</td>
        </tr>
    `).join('');
}

function renderLots() {
    const keyword = (els.filter.value || '').trim().toLowerCase();
    const lots = state.lots.filter(lot => {
        if (!keyword) return true;
        return String(lot.stockNo).toLowerCase().includes(keyword)
            || String(lot.stockName).toLowerCase().includes(keyword);
    });
    els.lotCount.textContent = `${formatInteger(lots.length)} 筆`;

    if (!lots.length) {
        els.lots.innerHTML = '<tr><td colspan="12" class="empty">沒有符合條件的明細</td></tr>';
        return;
    }

    els.lots.innerHTML = lots.map(lot => `
        <tr>
            <td>${stockCell(lot)}</td>
            <td>${formatDate(lot.date)}<br><span class="muted">${formatTime(lot.time)}</span></td>
            <td>${lot.side === 'S' ? '賣' : '買'}</td>
            <td>${formatInteger(lot.quantity)}</td>
            <td>${formatPrice(lot.price)}</td>
            <td>${formatPrice(lot.breakevenPrice)}</td>
            <td>${formatInteger(lot.fee)}</td>
            <td>${formatInteger(lot.tax)}</td>
            <td>${formatMoney(lot.payAmount)}</td>
            <td>${formatInteger(lot.marketValue)}</td>
            <td class="${toneClass(lot.unrealizedPnl)}">${formatMoney(lot.unrealizedPnl)}</td>
            <td class="${toneClass(lot.unrealizedPnlRate)}">${formatPercent(lot.unrealizedPnlRate)}</td>
        </tr>
    `).join('');
}

function applyPayload(payload) {
    state.lastPayload = payload;
    state.rows = payload.rows || [];
    state.lots = state.rows.flatMap(row => row.lots || []);
    updateSummary(payload);
    renderPositions();
    renderLots();

    const market = payload.market || {};
    els.marketStatus.textContent = market.label || '--';
    els.marketStatus.classList.toggle('live', Boolean(market.isOpen));
    els.refreshStatus.textContent = market.isOpen ? `開盤輪詢 ${market.pollSeconds || 2}s` : '非開盤已暫停輪詢';
    els.refreshStatus.classList.toggle('live', Boolean(market.isOpen));
    els.lastUpdated.textContent = `更新 ${formatDateTime(payload.queriedAt || payload.servedAt)}`;
    schedulePolling(market);
}

function schedulePolling(market) {
    if (state.timer) {
        clearInterval(state.timer);
        state.timer = null;
    }

    if (market?.isOpen) {
        state.timer = setInterval(() => fetchData(false), Math.max(2, Number(market.pollSeconds || 2)) * 1000);
    }
}

async function fetchData(force) {
    const url = new URL(apiUrl, window.location.origin);
    url.searchParams.set('token', dashboardToken);
    if (force) url.searchParams.set('force', '1');

    els.refreshStatus.textContent = force ? '強制刷新中' : '更新中';
    els.refreshStatus.classList.remove('error');
    els.error.style.display = 'none';

    try {
        const response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        applyPayload(await response.json());
    } catch (error) {
        els.refreshStatus.textContent = '更新失敗';
        els.refreshStatus.classList.add('error');
        els.error.style.display = 'block';
        els.error.textContent = `讀取玉山庫存失敗：${error.message}`;
    }
}

function formatDate(value) {
    const raw = String(value || '');
    if (raw.length !== 8) return raw || '--';
    return `${raw.slice(0, 4)}/${raw.slice(4, 6)}/${raw.slice(6, 8)}`;
}

function formatTime(value) {
    const raw = String(value || '');
    if (raw.length < 6) return raw || '--';
    return `${raw.slice(0, 2)}:${raw.slice(2, 4)}:${raw.slice(4, 6)}`;
}

function formatDateTime(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString('zh-TW', {
        timeZone: 'Asia/Taipei',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
}

function escapeHtml(value) {
    return String(value)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

els.filter.addEventListener('input', () => {
    renderPositions();
    renderLots();
});
els.forceRefresh.addEventListener('click', () => fetchData(true));

fetchData(false);
</script>
</body>
</html>
