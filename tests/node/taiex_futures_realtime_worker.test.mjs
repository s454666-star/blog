import test from 'node:test';
import assert from 'node:assert/strict';
import {
    frameTradingViewMessage,
    isFreshOpenQuote,
    isUsableTradingViewToken,
    marketSession,
    parseTradingViewMessages,
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
