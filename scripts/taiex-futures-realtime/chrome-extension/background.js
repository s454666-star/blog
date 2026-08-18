const TRADING_VIEW_SYMBOL_URL = 'https://tw.tradingview.com/symbols/TAIFEX-TXF1!/';
const TRADING_VIEW_TAB_MATCH = 'https://tw.tradingview.com/*';
const LOCAL_QUOTE_ENDPOINT = 'http://127.0.0.1:18765/tradingview-quote';
const LOCAL_HEALTH_ENDPOINT = 'http://127.0.0.1:18765/health';
const HEALTH_ALARM_NAME = 'taiex-futures-bridge-health';
const HEALTH_CHECK_PERIOD_MINUTES = 0.5;
const STALE_QUOTE_MS = 90_000;
const RELOAD_COOLDOWN_MS = 120_000;
const LAST_RELOAD_STORAGE_KEY = 'taiexFuturesLastWatchdogReloadAt';
let healthCheckRunning = false;

async function ensureTradingViewTab() {
    const tabs = await chrome.tabs.query({ url: TRADING_VIEW_TAB_MATCH });
    const tab = selectTradingViewBridgeTab(tabs);
    if (tab) {
        return protectBridgeTab(tab);
    }

    const createdTab = await chrome.tabs.create({ url: TRADING_VIEW_SYMBOL_URL, active: false });

    return protectBridgeTab(createdTab);
}

async function protectBridgeTab(tab) {
    if (!tab?.id) {
        return tab;
    }

    try {
        return await chrome.tabs.update(tab.id, { autoDiscardable: false });
    } catch {
        return tab;
    }
}

function bridgeHealthIsStale(health, now = Date.now()) {
    if (!health?.market_session) {
        return false;
    }

    const lastQuoteAt = Date.parse(health.last_browser_quote_at || '');
    return !Number.isFinite(lastQuoteAt) || now - lastQuoteAt > STALE_QUOTE_MS;
}

function selectTradingViewBridgeTab(tabs) {
    return tabs.find((tab) => String(tab.url || '').includes('/symbols/TAIFEX-TXF1'))
        || null;
}

async function reloadTradingViewBridgeTab() {
    const tab = await ensureTradingViewTab();
    if (!tab?.id) {
        return;
    }

    await chrome.tabs.reload(tab.id);
}

async function recoverStaleBridge() {
    if (healthCheckRunning) {
        return false;
    }

    healthCheckRunning = true;
    try {
        const response = await fetch(LOCAL_HEALTH_ENDPOINT, { cache: 'no-store' });
        if (!response.ok) {
            return false;
        }

        const health = await response.json();
        const now = Date.now();
        if (!bridgeHealthIsStale(health, now)) {
            return false;
        }

        const stored = await chrome.storage.local.get(LAST_RELOAD_STORAGE_KEY);
        const lastReloadAt = Number(stored[LAST_RELOAD_STORAGE_KEY] || 0);
        if (now - lastReloadAt < RELOAD_COOLDOWN_MS) {
            return false;
        }

        await reloadTradingViewBridgeTab();
        await chrome.storage.local.set({ [LAST_RELOAD_STORAGE_KEY]: now });
        return true;
    } finally {
        healthCheckRunning = false;
    }
}

async function startBridgeWatchdog() {
    await chrome.alarms.create(HEALTH_ALARM_NAME, {
        delayInMinutes: HEALTH_CHECK_PERIOD_MINUTES,
        periodInMinutes: HEALTH_CHECK_PERIOD_MINUTES,
    });

    await maintainBridge();
}

async function maintainBridge() {
    await ensureTradingViewTab();

    await recoverStaleBridge();
}

chrome.runtime.onInstalled.addListener(() => {
    startBridgeWatchdog().catch(() => {});
});

chrome.runtime.onStartup.addListener(() => {
    startBridgeWatchdog().catch(() => {});
});

chrome.alarms.onAlarm.addListener((alarm) => {
    if (alarm.name === HEALTH_ALARM_NAME) {
        maintainBridge().catch(() => {});
    }
});

chrome.tabs?.onRemoved?.addListener(() => {
    setTimeout(() => {
        ensureTradingViewTab().catch(() => {});
    }, 500);
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (
        message?.type !== 'tradingview-page-quote'
        || typeof message.quote !== 'object'
        || !sender.tab?.url?.startsWith('https://tw.tradingview.com/')
    ) {
        return false;
    }

    fetch(LOCAL_QUOTE_ENDPOINT, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(message.quote),
    })
        .then((response) => {
            sendResponse({ ok: response.ok, status: response.status });
        })
        .catch(() => {
            sendResponse({ ok: false, status: 0 });
        });

    return true;
});

startBridgeWatchdog().catch(() => {});
