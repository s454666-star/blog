const TRADING_VIEW_HOME = 'https://tw.tradingview.com/';
const LOCAL_TOKEN_ENDPOINT = 'http://127.0.0.1:18765/tradingview-token';

async function ensureTradingViewTab() {
    const tabs = await chrome.tabs.query({ url: 'https://tw.tradingview.com/*' });
    if (tabs.length === 0) {
        await chrome.tabs.create({ url: TRADING_VIEW_HOME, active: false });
    }
}

chrome.runtime.onInstalled.addListener(() => {
    ensureTradingViewTab().catch(() => {});
});

chrome.runtime.onStartup.addListener(() => {
    ensureTradingViewTab().catch(() => {});
});

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (
        message?.type !== 'tradingview-auth-token'
        || typeof message.token !== 'string'
        || !sender.tab?.url?.startsWith('https://tw.tradingview.com/')
    ) {
        return false;
    }

    fetch(LOCAL_TOKEN_ENDPOINT, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ token: message.token }),
    })
        .then((response) => {
            sendResponse({ ok: response.ok, status: response.status });
        })
        .catch(() => {
            sendResponse({ ok: false, status: 0 });
        });

    return true;
});
