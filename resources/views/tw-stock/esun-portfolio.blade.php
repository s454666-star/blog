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
            min-width: 1540px;
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

        .sort-button {
            display: inline-flex;
            align-items: center;
            justify-content: flex-end;
            gap: 6px;
            width: 100%;
            min-height: 26px;
            padding: 0;
            border: 0;
            background: transparent;
            color: inherit;
            cursor: pointer;
            font: inherit;
            font-weight: 900;
            text-align: right;
            white-space: nowrap;
        }

        th:first-child .sort-button {
            justify-content: flex-start;
            text-align: left;
        }

        .sort-icon {
            min-width: 14px;
            color: var(--muted-2);
            font-size: 11px;
        }

        th.sorted .sort-icon {
            color: var(--amber);
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
            <div class="subtitle">正式 API · 載入抓玉山庫存一次 · 開盤每秒更新持股報價</div>
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
            <span class="tab active">全欄排序</span>
        </div>
        <div class="controls">
            <input type="search" placeholder="搜尋代號或名稱" data-filter>
            <button class="button" type="button" data-force-refresh>刷新庫存</button>
        </div>
    </section>

    <div data-error class="error-box" style="display: none;"></div>

    <section class="table-wrap" data-position-wrap>
        <table>
            <thead>
            <tr>
                <th><button class="sort-button" type="button" data-sort-key="stock">庫存股 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="currentPrice">參考價<br>玉山價 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="dayChangeRate">漲跌幅 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="todayPnl">今日損益 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="unrealizedPnl">總損益 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="unrealizedPnlRate">總報酬率 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="quantity">股數 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="averagePrice">均價 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="costBasis">總成本 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="marketValue">市值 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="marketWeight">庫存占比 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="breakevenPrice">損益平衡價 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="fiveDayReturn">近5日漲幅 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="twentyDayReturn">近20日漲幅 <span class="sort-icon" data-sort-icon></span></button></th>
                <th><button class="sort-button" type="button" data-sort-key="yearToDateReturn">今年以來漲幅 <span class="sort-icon" data-sort-icon></span></button></th>
            </tr>
            </thead>
            <tbody data-positions>
            <tr><td colspan="15" class="empty">讀取中</td></tr>
            </tbody>
        </table>
    </section>
</div>

<script>
const apiUrl = @json($apiUrl);
const quoteUrl = @json($quoteUrl);
const dashboardToken = @json($token);
const state = {
    rows: [],
    quoteTimer: null,
    quoteLoading: false,
    lastPayload: null,
    sort: {
        key: 'unrealizedPnl',
        direction: 'desc',
    },
};

const els = {
    marketStatus: document.querySelector('[data-market-status]'),
    refreshStatus: document.querySelector('[data-refresh-status]'),
    lastUpdated: document.querySelector('[data-last-updated]'),
    positions: document.querySelector('[data-positions]'),
    error: document.querySelector('[data-error]'),
    filter: document.querySelector('[data-filter]'),
    forceRefresh: document.querySelector('[data-force-refresh]'),
    sortButtons: document.querySelectorAll('[data-sort-key]'),
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

function formatWeight(value) {
    if (value === null || value === undefined || !Number.isFinite(Number(value))) return '--';
    return `${Number(value).toFixed(2)}%`;
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
    const source = payload.source || {};

    updateSummaryCards(
        { ...summary, marketOpen: Boolean(payload.market?.isOpen) },
        `庫存 ${payload.cacheSeconds || 0}s · 玉山 ${formatAge(source.ageSeconds)}`,
    );
}

function updateSummaryCards(summary, sourceText) {
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
    document.querySelector('[data-summary="sourceAge"]').textContent = summary.marketOpen ? 'LIVE' : 'ONCE';
    document.querySelector('[data-summary="servedAt"]').textContent = sourceText;
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
    const rows = sortRows(state.rows.filter(row => {
        if (!keyword) return true;
        return String(row.stockNo).toLowerCase().includes(keyword)
            || String(row.stockName).toLowerCase().includes(keyword);
    }));

    if (!rows.length) {
        els.positions.innerHTML = '<tr><td colspan="15" class="empty">沒有符合條件的庫存</td></tr>';
        return;
    }

    els.positions.innerHTML = rows.map(row => `
        <tr>
            <td>${stockCell(row)}</td>
            <td class="${toneClass(row.realtimeDayChangeRate ?? row.dayChangeRate)}">
                <strong>${formatPrice(row.realtimePrice ?? row.currentPrice)}</strong><br>
                <span class="muted">玉山 ${formatPrice(row.currentPrice)}</span>
            </td>
            <td class="${toneClass(row.realtimeDayChangeRate ?? row.dayChangeRate)}">
                ${formatPercent(row.realtimeDayChangeRate ?? row.dayChangeRate)}<br>
                <span class="muted">玉山 ${formatPercent(row.dayChangeRate)}</span>
            </td>
            <td class="${toneClass(row.todayPnl)}"><strong>${formatMoney(row.todayPnl)}</strong></td>
            <td class="${toneClass(row.unrealizedPnl)}"><strong>${formatMoney(row.unrealizedPnl)}</strong></td>
            <td class="${toneClass(row.unrealizedPnlRate)}">${formatPercent(row.unrealizedPnlRate)}</td>
            <td>${formatInteger(row.quantity)}</td>
            <td>${formatPrice(row.averagePrice)}</td>
            <td>${formatInteger(row.costBasis)}</td>
            <td>${formatInteger(row.marketValue)}</td>
            <td>${formatWeight(row.marketWeight)}</td>
            <td>${formatPrice(row.breakevenPrice)}</td>
            <td class="${toneClass(row.fiveDayReturn)}">${formatPercent(row.fiveDayReturn)}</td>
            <td class="${toneClass(row.twentyDayReturn)}">${formatPercent(row.twentyDayReturn)}</td>
            <td class="${toneClass(row.yearToDateReturn)}">${formatPercent(row.yearToDateReturn)}</td>
        </tr>
    `).join('');
}

function applyPayload(payload) {
    state.lastPayload = payload;
    state.rows = payload.rows || [];
    updateSummary(payload);
    updateSortIndicators();
    renderPositions();

    const market = payload.market || {};
    const source = payload.source || {};
    els.marketStatus.textContent = market.label || '--';
    els.marketStatus.classList.toggle('live', Boolean(market.isOpen));
    els.refreshStatus.textContent = source.status === 'stale'
        ? '顯示最近成功資料'
        : (market.isOpen ? '每秒更新報價' : '非開盤已暫停輪詢');
    els.refreshStatus.classList.toggle('live', Boolean(market.isOpen));
    els.refreshStatus.classList.toggle('error', source.status === 'stale');
    els.lastUpdated.textContent = `更新 ${formatDateTime(payload.queriedAt || payload.servedAt)}`;
    fetchQuotes();
    scheduleQuotePolling(market);
}

function sortRows(rows) {
    const direction = state.sort.direction === 'asc' ? 1 : -1;
    const key = state.sort.key;

    return [...rows].sort((a, b) => {
        const av = sortValue(a, key);
        const bv = sortValue(b, key);
        if (av === null && bv === null) return stockFallback(a, b);
        if (av === null) return 1;
        if (bv === null) return -1;
        if (typeof av === 'string' || typeof bv === 'string') {
            const compared = String(av).localeCompare(String(bv), 'zh-Hant', { numeric: true });
            return compared === 0 ? stockFallback(a, b) : compared * direction;
        }
        const compared = av === bv ? 0 : av > bv ? 1 : -1;
        return compared === 0 ? stockFallback(a, b) : compared * direction;
    });
}

function sortValue(row, key) {
    if (key === 'stock') {
        return `${row.stockNo || ''} ${row.stockName || ''}`;
    }

    if (key === 'currentPrice') {
        const price = Number(row.realtimePrice ?? row.currentPrice);
        return Number.isFinite(price) ? price : null;
    }

    const numeric = Number(row[key]);
    return Number.isFinite(numeric) ? numeric : null;
}

function stockFallback(a, b) {
    return String(a.stockNo || '').localeCompare(String(b.stockNo || ''), 'zh-Hant', { numeric: true });
}

function setSort(key) {
    if (state.sort.key === key) {
        state.sort.direction = state.sort.direction === 'asc' ? 'desc' : 'asc';
    } else {
        state.sort.key = key;
        state.sort.direction = key === 'stock' ? 'asc' : 'desc';
    }

    updateSortIndicators();
    renderPositions();
}

function updateSortIndicators() {
    els.sortButtons.forEach(button => {
        const active = button.dataset.sortKey === state.sort.key;
        const th = button.closest('th');
        const icon = button.querySelector('[data-sort-icon]');
        th.classList.toggle('sorted', active);
        th.setAttribute('aria-sort', active ? (state.sort.direction === 'asc' ? 'ascending' : 'descending') : 'none');
        icon.textContent = active ? (state.sort.direction === 'asc' ? '▲' : '▼') : '↕';
    });
}

function scheduleQuotePolling(market) {
    if (state.quoteTimer) {
        clearInterval(state.quoteTimer);
        state.quoteTimer = null;
    }

    if (market?.isOpen) {
        state.quoteTimer = setInterval(() => fetchQuotes(), 1000);
    }
}

async function fetchData(force) {
    const url = new URL(apiUrl, window.location.origin);
    if (dashboardToken) {
        url.searchParams.set('token', dashboardToken);
    }
    if (force) url.searchParams.set('force', '1');

    els.refreshStatus.textContent = force ? '讀取玉山庫存中' : '更新中';
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

async function fetchQuotes() {
    if (state.quoteLoading || !state.rows.length) {
        return;
    }

    state.quoteLoading = true;
    const url = new URL(quoteUrl, window.location.origin);
    if (dashboardToken) {
        url.searchParams.set('token', dashboardToken);
    }
    url.searchParams.set('codes', state.rows.map(row => row.stockNo).join(','));

    try {
        const response = await fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            cache: 'no-store',
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        applyQuotes(await response.json());
    } catch (error) {
        els.refreshStatus.textContent = '報價暫時失敗';
        els.refreshStatus.classList.add('error');
    } finally {
        state.quoteLoading = false;
    }
}

function applyQuotes(payload) {
    const quotes = payload.quotes || {};
    let changed = false;

    state.rows = state.rows.map(row => {
        const quote = quotes[row.stockNo];
        if (!quote || !Number.isFinite(Number(quote.price))) {
            return row;
        }

        changed = true;
        return applyQuoteToRow(row, quote);
    });

    if (!changed) {
        updateQuoteStatus(payload);
        return;
    }

    updateSummaryCards(esunSummary(), quoteSourceText(payload));
    updateSortIndicators();
    renderPositions();
    updateQuoteStatus(payload);
}

function applyQuoteToRow(row, quote) {
    const price = number(quote.price);
    const previousClose = Number.isFinite(Number(quote.previousClose))
        ? Number(quote.previousClose)
        : row.previousClose;
    const dayChange = previousClose === null || previousClose === undefined ? null : price - number(previousClose);

    return {
        ...row,
        realtimePrice: price,
        realtimePreviousClose: previousClose,
        realtimeDayChange: dayChange,
        realtimeDayChangeRate: number(previousClose) > 0 ? dayChange / number(previousClose) * 100 : null,
        quoteSource: quote.sourceLabel || quote.source || '',
        quoteType: quote.priceType || 'last',
        quoteAt: quote.quotedAt || null,
        bestBid: quote.bestBid ?? null,
        bestAsk: quote.bestAsk ?? null,
    };
}

function esunSummary() {
    return {
        ...(state.lastPayload?.summary || {}),
        marketOpen: Boolean((state.lastPayload?.market || {}).isOpen),
    };
}

function updateQuoteStatus(payload) {
    const market = payload.market || state.lastPayload?.market || {};
    const source = payload.source || {};
    els.marketStatus.textContent = market.label || els.marketStatus.textContent;
    els.marketStatus.classList.toggle('live', Boolean(market.isOpen));

    const ok = ['live', 'partial'].includes(source.status);
    els.refreshStatus.textContent = ok
        ? `報價每秒更新 · ${source.label || '--'}`
        : (market.isOpen ? '報價來源暫時缺漏' : '非開盤已暫停輪詢');
    els.refreshStatus.classList.toggle('live', ok && Boolean(market.isOpen));
    els.refreshStatus.classList.toggle('error', !ok && Boolean(market.isOpen));
    els.lastUpdated.textContent = `報價 ${formatDateTime(payload.servedAt)}`;
}

function quoteSourceText(payload) {
    const source = payload.source || {};
    return `玉山庫存 · 參考報價 ${source.label || '--'} · 快取 ${payload.cacheSeconds || 1}s`;
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

function formatAge(value) {
    const seconds = Number(value);
    if (!Number.isFinite(seconds)) return '--';
    if (seconds < 60) return `${Math.round(seconds)} 秒前`;
    const minutes = Math.floor(seconds / 60);
    const rest = Math.round(seconds % 60);
    return rest > 0 ? `${minutes} 分 ${rest} 秒前` : `${minutes} 分前`;
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
});
els.forceRefresh.addEventListener('click', () => fetchData(true));
els.sortButtons.forEach(button => {
    button.addEventListener('click', () => setSort(button.dataset.sortKey));
});

updateSortIndicators();
fetchData(true);
</script>
</body>
</html>
