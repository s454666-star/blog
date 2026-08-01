(() => {
    const PAGE_BRIDGE_SOURCE = 'star-taiex-futures-page-bridge';
    const TARGET_SYMBOL = 'TAIFEX:TXF1!';
    const NativeWebSocket = window.WebSocket;
    const quoteValues = {};

    function parseMessages(raw) {
        const text = String(raw);
        const messages = [];
        let offset = 0;

        while (offset < text.length) {
            const marker = text.indexOf('~m~', offset);
            if (marker < 0) break;
            const lengthEnd = text.indexOf('~m~', marker + 3);
            if (lengthEnd < 0) break;
            const length = Number(text.slice(marker + 3, lengthEnd));
            if (!Number.isInteger(length) || length < 0) {
                offset = lengthEnd + 3;
                continue;
            }

            const payloadStart = lengthEnd + 3;
            const payload = text.slice(payloadStart, payloadStart + length);
            if (new TextEncoder().encode(payload).length < length) break;
            try {
                messages.push(JSON.parse(payload));
            } catch {
                // Ignore heartbeat and non-JSON frames.
            }
            offset = payloadStart + payload.length;
        }

        return messages;
    }

    function publishQuote(message) {
        if (message?.m !== 'qsd' || !Array.isArray(message.p)) return;
        const update = message.p.find((item) => item && typeof item === 'object' && item.v);
        if (!update || update.n !== TARGET_SYMBOL || update.s !== 'ok') return;

        Object.assign(quoteValues, update.v);
        const price = Number(quoteValues.lp);
        const sourceQuoteAt = Number(quoteValues.lp_time);
        if (!Number.isFinite(price) || price <= 0 || !Number.isFinite(sourceQuoteAt) || sourceQuoteAt <= 0) {
            return;
        }

        window.postMessage({
            source: PAGE_BRIDGE_SOURCE,
            type: 'tradingview-quote',
            quote: {
                schema_version: 1,
                symbol: TARGET_SYMBOL,
                price,
                volume: Number.isFinite(Number(quoteValues.volume)) ? Number(quoteValues.volume) : null,
                source_quote_at: sourceQuoteAt,
                current_session: typeof quoteValues.current_session === 'string' ? quoteValues.current_session : null,
                market_status: typeof quoteValues.market_status === 'string' ? quoteValues.market_status : null,
                is_tradable: quoteValues.is_tradable === true,
            },
        }, window.location.origin);
    }

    const BridgedWebSocket = new Proxy(NativeWebSocket, {
        construct(target, args) {
            const socket = Reflect.construct(target, args, target);
            const url = String(args[0] || '');
            if (url.includes('data.tradingview.com')) {
                socket.addEventListener('message', (event) => {
                    if (typeof event.data !== 'string') return;
                    for (const message of parseMessages(event.data)) publishQuote(message);
                });
            }
            return socket;
        },
    });

    Object.defineProperty(window, 'WebSocket', {
        configurable: true,
        writable: true,
        value: BridgedWebSocket,
    });
})();
