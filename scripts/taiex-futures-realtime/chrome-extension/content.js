const PAGE_BRIDGE_SOURCE = 'star-taiex-futures-page-bridge';

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
