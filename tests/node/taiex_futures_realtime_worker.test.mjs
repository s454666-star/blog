import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';
import {
    frameTradingViewMessage,
    isFreshOpenQuote,
    isUsableTradingViewToken,
    marketSession,
    parseTradingViewMessages,
    quoteFromBrowserBridgePayload,
    quoteFromTradingViewMessage,
    realtimeRedisPayload,
    redisCommand,
} from '../../scripts/taiex-futures-realtime/realtime-worker-lib.mjs';

function taipeiDate(dateTime) {
    return new Date(`${dateTime}+08:00`);
}

function jwt(payload) {
    return [
        Buffer.from('{}').toString('base64url'),
        Buffer.from(JSON.stringify(payload)).toString('base64url'),
        'signature',
    ].join('.');
}

test('marketSession follows the weekday day and night sessions', () => {
    assert.equal(marketSession(taipeiDate('2026-07-30T08:44:59')), null);
    assert.equal(marketSession(taipeiDate('2026-07-30T08:45:00')), 'day');
    assert.equal(marketSession(taipeiDate('2026-07-30T13:44:59')), 'day');
    assert.equal(marketSession(taipeiDate('2026-07-30T13:45:00')), null);
    assert.equal(marketSession(taipeiDate('2026-07-30T15:00:00')), 'night');
    assert.equal(marketSession(taipeiDate('2026-07-31T04:59:59')), 'night');
    assert.equal(marketSession(taipeiDate('2026-07-31T05:00:00')), null);
    assert.equal(marketSession(taipeiDate('2026-08-01T04:59:59')), 'night');
    assert.equal(marketSession(taipeiDate('2026-08-01T05:00:00')), null);
});

test('authenticated token must identify a user and remain valid beyond one minute', () => {
    const now = taipeiDate('2026-07-30T10:00:00');
    const nowSeconds = Math.floor(now.getTime() / 1000);

    assert.equal(isUsableTradingViewToken(jwt({ user_id: 123, exp: nowSeconds + 3600 }), now), true);
    assert.equal(isUsableTradingViewToken(jwt({ exp: nowSeconds + 3600 }), now), false);
    assert.equal(isUsableTradingViewToken(jwt({ user_id: 123, exp: nowSeconds + 30 }), now), false);
    assert.equal(isUsableTradingViewToken('unauthorized_user_token', now), false);
});

test('TradingView protocol frames and parses quote updates', () => {
    const message = {
        m: 'qsd',
        p: [
            'qs_test',
            {
                n: 'TAIFEX:TXF1!',
                s: 'ok',
                v: {
                    lp: 40663,
                    lp_time: 1785386400,
                    volume: 12345,
                    current_session: 'regular',
                    market_status: 'open',
                    is_tradable: true,
                },
            },
        ],
    };
    const framed = frameTradingViewMessage(message.m, message.p);
    const parsed = parseTradingViewMessages(framed);
    const receivedAt = taipeiDate('2026-07-30T11:20:01');
    const quote = quoteFromTradingViewMessage(parsed[0], 'TAIFEX:TXF1!', receivedAt);

    assert.equal(quote.price, 40663);
    assert.equal(quote.volume, 12345);
    assert.equal(quote.marketStatus, 'open');
    assert.equal(quote.isTradable, true);
    assert.equal(quote.quoteAt.toISOString(), receivedAt.toISOString());
    assert.equal(quote.sourceQuoteAt.toISOString(), new Date(1785386400 * 1000).toISOString());
});

test('browser page bridge payload becomes an authenticated realtime quote', () => {
    const receivedAt = taipeiDate('2026-07-30T11:20:01');
    const quote = quoteFromBrowserBridgePayload({
        schema_version: 1,
        symbol: 'TAIFEX:TXF1!',
        price: 40663,
        volume: 12345,
        source_quote_at: 1785386400,
        current_session: 'regular',
        market_status: 'open',
        is_tradable: true,
    }, 'TAIFEX:TXF1!', receivedAt);

    assert.equal(quote.price, 40663);
    assert.equal(quote.quoteAt.toISOString(), receivedAt.toISOString());
    assert.equal(quote.source, 'TradingView authenticated browser session');
    assert.equal(quoteFromBrowserBridgePayload({ schema_version: 1, symbol: 'OTHER' }), null);
});

test('page bridge reuses the TradingView page websocket and publishes TXF1 quotes', () => {
    class FakeWebSocket {
        constructor(url) {
            this.url = url;
            this.listeners = {};
        }

        addEventListener(type, listener) {
            this.listeners[type] = listener;
        }

        emit(type, event) {
            this.listeners[type]?.(event);
        }
    }

    const posts = [];
    const pageWindow = {
        WebSocket: FakeWebSocket,
        location: { origin: 'https://tw.tradingview.com' },
        postMessage(message, targetOrigin) {
            posts.push({ message, targetOrigin });
        },
    };
    const script = readFileSync(
        new URL('../../scripts/taiex-futures-realtime/chrome-extension/page-quote-bridge.js', import.meta.url),
        'utf8',
    );
    vm.runInNewContext(script, { window: pageWindow, TextEncoder });

    const socket = new pageWindow.WebSocket('wss://data.tradingview.com/socket.io/websocket');
    const message = {
        m: 'qsd',
        p: ['session', {
            n: 'TAIFEX:TXF1!',
            s: 'ok',
            v: {
                lp: 40663,
                lp_time: 1785386400,
                volume: 12345,
                current_session: 'regular',
                is_tradable: true,
            },
        }],
    };
    socket.emit('message', { data: frameTradingViewMessage(message.m, message.p) });

    assert.equal(posts.length, 1);
    assert.equal(posts[0].targetOrigin, 'https://tw.tradingview.com');
    assert.equal(posts[0].message.quote.price, 40663);
    assert.equal(posts[0].message.quote.symbol, 'TAIFEX:TXF1!');
});

test('content bridge reconnects only the visible TradingView session-interrupted dialog', () => {
    let clicked = 0;
    const dialog = {
        textContent: '會話中斷 您的會話已結束，因為您的帳戶已從其他瀏覽器或裝置訪問。',
        parentElement: null,
    };
    const reconnectButton = {
        textContent: '連接',
        disabled: false,
        parentElement: dialog,
        getClientRects: () => [{}],
        click: () => { clicked += 1; },
    };
    const unrelatedButton = {
        textContent: '連接',
        disabled: false,
        parentElement: { textContent: '一般連線設定', parentElement: null },
        getClientRects: () => [{}],
        click: () => { clicked += 100; },
    };
    class FakeMutationObserver {
        constructor(callback) {
            this.callback = callback;
        }

        observe() {}
    }
    const listeners = {};
    const contentWindow = {
        addEventListener(type, listener) {
            listeners[type] = listener;
        },
    };
    const contentDocument = {
        documentElement: {},
        querySelectorAll: () => [unrelatedButton, reconnectButton],
    };
    const script = readFileSync(
        new URL('../../scripts/taiex-futures-realtime/chrome-extension/content.js', import.meta.url),
        'utf8',
    );
    vm.runInNewContext(script, {
        window: contentWindow,
        document: contentDocument,
        MutationObserver: FakeMutationObserver,
        chrome: { runtime: { sendMessage() {}, lastError: null } },
        setTimeout(callback) { callback(); return 1; },
        setInterval() { return 1; },
        Date,
    });

    assert.equal(clicked, 1);
});

test('only fresh open-session quotes qualify for a one-second Redis refresh', () => {
    const now = taipeiDate('2026-07-30T11:20:00');
    const quote = {
        symbol: 'TAIFEX:TXF1!',
        price: 40663,
        quoteAt: new Date(now.getTime() - 4_000),
        receivedAt: now,
        currentSession: 'regular',
        marketStatus: 'open',
        isTradable: true,
        volume: 12345,
    };

    assert.equal(isFreshOpenQuote(quote, now, 15), true);
    assert.equal(isFreshOpenQuote({ ...quote, quoteAt: new Date(now.getTime() - 16_000) }, now, 15), false);
    assert.equal(isFreshOpenQuote({ ...quote, marketStatus: 'closed' }, now, 15), false);
    assert.equal(isFreshOpenQuote(quote, taipeiDate('2026-07-30T14:00:00'), 15), false);

    const payload = realtimeRedisPayload(quote, now);
    assert.equal(payload.source, 'TradingView authenticated websocket');
    assert.equal(payload.session, 'day');
    assert.equal(payload.auth_mode, 'authenticated');
});

test('Redis command uses RESP and supports expiring SET', () => {
    assert.equal(
        redisCommand(['SET', 'quote:key', '{"price":40663}', 'EX', 5]),
        '*5\r\n$3\r\nSET\r\n$9\r\nquote:key\r\n$15\r\n{"price":40663}\r\n$2\r\nEX\r\n$1\r\n5\r\n',
    );
});
