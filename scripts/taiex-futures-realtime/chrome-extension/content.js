const PAGE_BRIDGE_SOURCE = 'star-taiex-futures-page-bridge';
const SESSION_INTERRUPTED_TEXT = '會話中斷';
const SESSION_INTERRUPTED_DETAIL = '您的會話已結束';
const RECONNECT_BUTTON_TEXT = '連接';
const RECONNECT_COOLDOWN_MS = 30_000;
let lastReconnectAt = 0;
let reconnectCheckTimer = null;

window.addEventListener('message', (event) => {
    if (
        event.source !== window
        || event.data?.source !== PAGE_BRIDGE_SOURCE
        || event.data?.type !== 'tradingview-quote'
        || typeof event.data?.quote !== 'object'
    ) {
        return;
    }

    chrome.runtime.sendMessage({
        type: 'tradingview-page-quote',
        quote: event.data.quote,
    }, () => void chrome.runtime.lastError);
});

function visibleExactTextButtons(text) {
    return Array.from(document.querySelectorAll('button')).filter((button) => (
        button.textContent?.trim() === text
        && !button.disabled
        && button.getClientRects().length > 0
    ));
}

function hasSessionInterruptedDialog(button) {
    let container = button;
    for (let depth = 0; container && depth < 10; depth += 1) {
        const text = container.textContent || '';
        if (text.includes(SESSION_INTERRUPTED_TEXT) && text.includes(SESSION_INTERRUPTED_DETAIL)) {
            return true;
        }
        container = container.parentElement;
    }

    return false;
}

function reconnectInterruptedSession() {
    reconnectCheckTimer = null;
    const now = Date.now();
    if (now - lastReconnectAt < RECONNECT_COOLDOWN_MS) {
        return false;
    }

    const button = visibleExactTextButtons(RECONNECT_BUTTON_TEXT)
        .find(hasSessionInterruptedDialog);
    if (!button) {
        return false;
    }

    lastReconnectAt = now;
    button.click();
    return true;
}

function scheduleReconnectCheck() {
    if (reconnectCheckTimer !== null) {
        return;
    }

    reconnectCheckTimer = setTimeout(reconnectInterruptedSession, 500);
}

new MutationObserver(scheduleReconnectCheck).observe(document.documentElement, {
    childList: true,
    subtree: true,
    characterData: true,
});
scheduleReconnectCheck();
setInterval(scheduleReconnectCheck, 5_000);
