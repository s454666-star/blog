const TAIPEI_TIME_ZONE = 'Asia/Taipei';

export const DEFAULT_SYMBOL = 'TAIFEX:TXF1!';
export const DEFAULT_REDIS_KEY = 'tw-futures:realtime:tradingview:TAIFEX:TXF1!';

export function taipeiClock(now = new Date()) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: TAIPEI_TIME_ZONE,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
        weekday: 'short',
    }).formatToParts(now);
    const value = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return {
        date: `${value.year}-${value.month}-${value.day}`,
        weekday: value.weekday,
        hour: Number(value.hour),
        minute: Number(value.minute),
        second: Number(value.second),
    };
}

export function marketSession(now = new Date()) {
    const clock = taipeiClock(now);
    const seconds = (clock.hour * 3600) + (clock.minute * 60) + clock.second;
    const weekday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'].includes(clock.weekday);

    if (weekday && seconds >= (8 * 3600 + 45 * 60) && seconds < (13 * 3600 + 45 * 60)) {
        return 'day';
    }

    if (weekday && seconds >= 15 * 3600) {
        return 'night';
    }

    if (seconds < 5 * 3600 && ['Tue', 'Wed', 'Thu', 'Fri', 'Sat'].includes(clock.weekday)) {
        return 'night';
    }

    return null;
}

export function decodeJwtMetadata(token) {
    const parts = String(token || '').split('.');
    if (parts.length !== 3) {
        return null;
    }

    try {
        const payload = JSON.parse(Buffer.from(parts[1], 'base64url').toString('utf8'));
        const expiresAt = Number(payload.exp);
        if (!Number.isFinite(expiresAt) || expiresAt <= 0) {
            return null;
        }

        return {
            expiresAt,
            hasUserId: payload.user_id !== undefined && payload.user_id !== null,
        };
    } catch {
        return null;
    }
}

export function isUsableTradingViewToken(token, now = new Date()) {
    const metadata = decodeJwtMetadata(token);

    return metadata !== null
        && metadata.hasUserId
        && metadata.expiresAt > Math.floor(now.getTime() / 1000) + 60;
}

export function frameTradingViewMessage(method, params) {
    const message = JSON.stringify({ m: method, p: params });

    return `~m~${Buffer.byteLength(message, 'utf8')}~m~${message}`;
}

export function parseTradingViewMessages(raw) {
    const text = String(raw);
    const messages = [];
    let offset = 0;

    while (offset < text.length) {
        const marker = text.indexOf('~m~', offset);
        if (marker < 0) {
            break;
        }

        const lengthEnd = text.indexOf('~m~', marker + 3);
        if (lengthEnd < 0) {
            break;
        }

        const length = Number(text.slice(marker + 3, lengthEnd));
        if (!Number.isInteger(length) || length < 0) {
            offset = lengthEnd + 3;
            continue;
        }

        const payloadStart = lengthEnd + 3;
        const payload = text.slice(payloadStart, payloadStart + length);
        if (Buffer.byteLength(payload, 'utf8') < length) {
            break;
        }

        if (payload.startsWith('~h~')) {
            messages.push({ heartbeat: payload });
        } else {
            try {
                messages.push(JSON.parse(payload));
            } catch {
                // Ignore non-JSON protocol frames.
            }
        }

        offset = payloadStart + payload.length;
    }

    return messages;
}

export function quoteFromTradingViewMessage(message, expectedSymbol = DEFAULT_SYMBOL, receivedAt = new Date()) {
    if (message?.m !== 'qsd' || !Array.isArray(message.p)) {
        return null;
    }

    const update = message.p.find((item) => item && typeof item === 'object' && item.v);
    if (!update || update.n !== expectedSymbol || update.s !== 'ok') {
        return null;
    }

    const values = update.v;
    const price = Number(values.lp);
    const quoteAtUnix = Number(values.lp_time);
    if (!Number.isFinite(price) || price <= 0 || !Number.isFinite(quoteAtUnix) || quoteAtUnix <= 0) {
        return null;
    }

    const sourceQuoteAt = new Date(quoteAtUnix > 10_000_000_000 ? quoteAtUnix : quoteAtUnix * 1000);

    return {
        symbol: expectedSymbol,
        price,
        quoteAt: receivedAt,
        sourceQuoteAt,
        receivedAt,
        currentSession: typeof values.current_session === 'string' ? values.current_session : null,
        marketStatus: typeof values.market_status === 'string' ? values.market_status : null,
        isTradable: values.is_tradable === true,
        volume: Number.isFinite(Number(values.volume)) ? Number(values.volume) : null,
    };
}

export function quoteFromBrowserBridgePayload(payload, expectedSymbol = DEFAULT_SYMBOL, receivedAt = new Date()) {
    if (
        payload?.schema_version !== 1
        || payload?.symbol !== expectedSymbol
    ) {
        return null;
    }

    const price = Number(payload.price);
    const quoteAtUnix = Number(payload.source_quote_at);
    if (!Number.isFinite(price) || price <= 0 || !Number.isFinite(quoteAtUnix) || quoteAtUnix <= 0) {
        return null;
    }

    const sourceQuoteAt = new Date(quoteAtUnix > 10_000_000_000 ? quoteAtUnix : quoteAtUnix * 1000);
    if (Number.isNaN(sourceQuoteAt.getTime())) {
        return null;
    }

    return {
        symbol: expectedSymbol,
        price,
        quoteAt: receivedAt,
        sourceQuoteAt,
        receivedAt,
        currentSession: typeof payload.current_session === 'string' ? payload.current_session : null,
        marketStatus: typeof payload.market_status === 'string' ? payload.market_status : null,
        isTradable: payload.is_tradable === true,
        volume: Number.isFinite(Number(payload.volume)) ? Number(payload.volume) : null,
        source: 'TradingView authenticated browser session',
    };
}

export function isFreshOpenQuote(quote, now = new Date(), maxAgeSeconds = 15) {
    const session = marketSession(now);
    if (!quote || session === null || quote.isTradable === false) {
        return false;
    }

    if (['closed', 'postmarket', 'premarket'].includes(String(quote.marketStatus || '').toLowerCase())) {
        return false;
    }

    const ageSeconds = (now.getTime() - quote.quoteAt.getTime()) / 1000;

    return ageSeconds >= -5 && ageSeconds <= maxAgeSeconds;
}

export function realtimeRedisPayload(quote, now = new Date()) {
    return {
        schema_version: 1,
        symbol: quote.symbol,
        price: quote.price,
        volume: quote.volume,
        quote_at: quote.quoteAt.toISOString(),
        source_quote_at: quote.sourceQuoteAt?.toISOString() || null,
        received_at: quote.receivedAt.toISOString(),
        written_at: now.toISOString(),
        session: marketSession(now),
        current_session: quote.currentSession,
        market_status: quote.marketStatus,
        source: quote.source || 'TradingView authenticated websocket',
        auth_mode: 'authenticated',
    };
}

export function redisCommand(parts) {
    let output = `*${parts.length}\r\n`;
    for (const part of parts) {
        const value = String(part);
        output += `$${Buffer.byteLength(value, 'utf8')}\r\n${value}\r\n`;
    }

    return output;
}
