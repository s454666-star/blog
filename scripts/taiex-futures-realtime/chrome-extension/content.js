const PAGE_BRIDGE_SOURCE = 'star-taiex-futures-page-bridge';
const BRIDGE_VERSION = '1.5.0';
const SESSION_INTERRUPTED_TEXT = '會話中斷';
const SESSION_INTERRUPTED_DETAIL = '您的會話已結束';
const RECONNECT_BUTTON_TEXT = '連接';
const RECONNECT_COOLDOWN_MS = 30_000;
const DOM_QUOTE_POLL_MS = 1_000;
const DOM_QUOTE_HEARTBEAT_MS = 5_000;
const DOM_QUOTE_MAX_DISPLAY_AGE_MS = 75_000;
let lastReconnectAt = 0;
let reconnectCheckTimer = null;
let lastDomQuoteFingerprint = null;
let lastDomQuoteSentAt = 0;

document.documentElement?.setAttribute?.('data-star-taiex-bridge-version', BRIDGE_VERSION);

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

function taipeiDisplayedQuoteTimestamp(text, now = Date.now()) {
    const match = String(text || '').match(/今天\s*(\d{1,2}):(\d{2})/);
    if (!match) {
        return null;
    }

    const taipeiNow = new Date(now + (8 * 60 * 60 * 1_000));
    return Date.UTC(
        taipeiNow.getUTCFullYear(),
        taipeiNow.getUTCMonth(),
        taipeiNow.getUTCDate(),
        Number(match[1]) - 8,
        Number(match[2]),
    );
}

function domQuoteFromPage(now = Date.now()) {
    if (!window.location?.pathname?.includes('/symbols/TAIFEX-TXF1')) {
        return null;
    }

    const priceElement = document.querySelector('[data-qa-id="symbol-last-value"].js-symbol-last')
        || document.querySelector('main .js-symbol-last');
    const realtimeMode = priceElement?.parentElement?.querySelector('.tv-data-mode--realtime');
    const displayedAt = taipeiDisplayedQuoteTimestamp(
        document.querySelector('.js-symbol-lp-time')?.textContent,
        now,
    );
    const price = Number(String(priceElement?.textContent || '').replaceAll(',', '').trim());
    const age = displayedAt === null ? Number.POSITIVE_INFINITY : now - displayedAt;
    if (
        !realtimeMode
        || !Number.isFinite(price)
        || price <= 0
        || age < -5_000
        || age > DOM_QUOTE_MAX_DISPLAY_AGE_MS
    ) {
        return null;
    }

    return {
        schema_version: 1,
        symbol: 'TAIFEX:TXF1!',
        price,
        volume: null,
        source_quote_at: Math.floor(displayedAt / 1_000),
        current_session: 'regular',
        market_status: 'open',
        is_tradable: true,
    };
}

function publishDomQuote() {
    const now = Date.now();
    const quote = domQuoteFromPage(now);
    if (!quote) {
        return false;
    }

    const fingerprint = `${quote.price}|${quote.source_quote_at}`;
    if (fingerprint === lastDomQuoteFingerprint && now - lastDomQuoteSentAt < DOM_QUOTE_HEARTBEAT_MS) {
        return false;
    }

    lastDomQuoteFingerprint = fingerprint;
    lastDomQuoteSentAt = now;
    chrome.runtime.sendMessage({
        type: 'tradingview-page-quote',
        quote,
    }, () => void chrome.runtime.lastError);

    return true;
}

new MutationObserver(scheduleReconnectCheck).observe(document.documentElement, {
    childList: true,
    subtree: true,
    characterData: true,
});
scheduleReconnectCheck();
setInterval(scheduleReconnectCheck, 5_000);
publishDomQuote();
setInterval(publishDomQuote, DOM_QUOTE_POLL_MS);
