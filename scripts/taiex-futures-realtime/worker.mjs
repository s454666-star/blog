import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { WebSocket } from 'ws';
import {
    DEFAULT_REDIS_KEY,
    DEFAULT_SYMBOL,
    decodeJwtMetadata,
    frameTradingViewMessage,
    isFreshOpenQuote,
    isUsableTradingViewToken,
    marketSession,
    parseTradingViewMessages,
    quoteFromTradingViewMessage,
    realtimeRedisPayload,
    redisCommand,
} from './realtime-worker-lib.mjs';

const workerDirectory = path.dirname(fileURLToPath(import.meta.url));
const repositoryRoot = path.resolve(workerDirectory, '..', '..');
const env = loadEnv(path.join(repositoryRoot, '.env'));
const symbol = env.TAIEX_FUTURES_REALTIME_SYMBOL || DEFAULT_SYMBOL;
const redisKey = env.TAIEX_FUTURES_REALTIME_REDIS_KEY || DEFAULT_REDIS_KEY;
const bridgePort = Number(env.TAIEX_FUTURES_REALTIME_BRIDGE_PORT || 18765);
const redisTtlSeconds = Number(env.TAIEX_FUTURES_REALTIME_REDIS_TTL_SECONDS || 5);
const quoteMaxAgeSeconds = Number(env.TAIEX_FUTURES_REALTIME_QUOTE_MAX_AGE_SECONDS || 15);

const state = {
    token: null,
    tokenExpiresAt: null,
    tradingViewSocket: null,
    reconnectTimer: null,
    latestQuote: null,
    redisConnected: false,
    lastRedisWriteAt: null,
    lastError: null,
    disconnectReason: null,
    protocolMessageCounts: {},
    quoteValues: {},
};

function log(message, details = {}) {
    process.stdout.write(`${JSON.stringify({
        at: new Date().toISOString(),
        message,
        ...details,
    })}\n`);
}

function loadEnv(envPath) {
    const values = {};
    const content = requireTextFile(envPath);

    for (const line of content.split(/\r?\n/)) {
        if (!line || line.trimStart().startsWith('#') || !line.includes('=')) {
            continue;
        }

        const separator = line.indexOf('=');
        const key = line.slice(0, separator).trim();
        let value = line.slice(separator + 1).trim();
        if (
            value.length >= 2
            && ((value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'")))
        ) {
            value = value.slice(1, -1);
        }
        values[key] = value;
    }

    return values;
}

function requireTextFile(filePath) {
    // Kept inside the worker so credentials remain local and are never printed.
    return process.getBuiltinModule('node:fs').readFileSync(filePath, 'utf8');
}

function scheduleReconnect(delayMs = 2_000) {
    if (state.reconnectTimer || !state.token || marketSession() === null) {
        return;
    }

    state.reconnectTimer = setTimeout(() => {
        state.reconnectTimer = null;
        connectTradingView();
    }, delayMs);
}

function disconnectTradingView(reason) {
    const hadSocket = Boolean(state.tradingViewSocket);
    if (state.tradingViewSocket) {
        const socket = state.tradingViewSocket;
        state.tradingViewSocket = null;
        socket.removeAllListeners();
        socket.close();
    }

    state.latestQuote = null;
    state.quoteValues = {};
    if (reason && (hadSocket || state.disconnectReason !== reason)) {
        log('tradingview_disconnected', { reason });
    }
    state.disconnectReason = reason || null;
}

function connectTradingView() {
    if (
        marketSession() === null
        || !isUsableTradingViewToken(state.token)
        || state.tradingViewSocket?.readyState === WebSocket.OPEN
        || state.tradingViewSocket?.readyState === WebSocket.CONNECTING
    ) {
        return;
    }

    const quoteSession = `qs_local_${randomId(12)}`;
    const socket = new WebSocket(
        `wss://data.tradingview.com/socket.io/websocket?from=chart%2F&date=${Date.now()}`,
        {
            headers: {
                Origin: 'https://www.tradingview.com',
                'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/138 Safari/537.36',
            },
            handshakeTimeout: 10_000,
        },
    );
    state.tradingViewSocket = socket;

    socket.on('open', () => {
        state.disconnectReason = null;
        log('tradingview_connected', { symbol });
        socket.send(frameTradingViewMessage('set_auth_token', [state.token]));
        socket.send(frameTradingViewMessage('quote_create_session', [quoteSession]));
        socket.send(frameTradingViewMessage('quote_set_fields', [
            quoteSession,
            'lp',
            'lp_time',
            'volume',
            'current_session',
            'is_tradable',
            'update_mode',
        ]));
        socket.send(frameTradingViewMessage('quote_add_symbols', [
            quoteSession,
            symbol,
        ]));
    });

    socket.on('message', (raw) => {
        for (const message of parseTradingViewMessages(raw.toString())) {
            if (message.heartbeat) {
                socket.send(`~m~${Buffer.byteLength(message.heartbeat, 'utf8')}~m~${message.heartbeat}`);
                continue;
            }

            const messageType = typeof message.m === 'string' ? message.m : 'unknown';
            state.protocolMessageCounts[messageType] = (state.protocolMessageCounts[messageType] || 0) + 1;

            if (['critical_error', 'protocol_error', 'quote_error'].includes(message.m)) {
                state.lastError = `TradingView ${message.m}`;
                log('tradingview_protocol_error', {
                    type: message.m,
                    details: sanitizedProtocolDetails(message.p),
                });
                continue;
            }

            const mergedMessage = mergeQuoteMessage(message);
            const quote = quoteFromTradingViewMessage(mergedMessage, symbol);
            if (quote) {
                state.latestQuote = quote;
            }
        }
    });

    socket.on('close', () => {
        if (state.tradingViewSocket === socket) {
            state.tradingViewSocket = null;
        }
        state.latestQuote = null;
        log('tradingview_socket_closed');
        scheduleReconnect();
    });

    socket.on('error', (error) => {
        state.lastError = error.message;
        log('tradingview_socket_error', { error: error.message });
    });
}

async function writeLatestQuote() {
    const now = new Date();
    if (marketSession(now) === null) {
        if (state.tradingViewSocket) {
            disconnectTradingView('market_closed');
        }
        return;
    }

    if (!isUsableTradingViewToken(state.token, now)) {
        disconnectTradingView('token_missing_or_expired');
        return;
    }

    connectTradingView();
    if (!isFreshOpenQuote(state.latestQuote, now, quoteMaxAgeSeconds)) {
        return;
    }

    const payload = JSON.stringify(realtimeRedisPayload(state.latestQuote, now));
    try {
        await redisSetWithExpiry(redisKey, payload, redisTtlSeconds);
        state.lastRedisWriteAt = now.toISOString();
        state.lastError = null;
    } catch (error) {
        state.lastError = error.message;
        log('redis_write_failed', { error: error.message });
    }
}

async function redisSetWithExpiry(key, value, ttlSeconds) {
    const socket = net.createConnection({
        host: env.REDIS_HOST || '127.0.0.1',
        port: Number(env.REDIS_PORT || 6379),
    });
    socket.setTimeout(5_000);

    try {
        await waitForSocket(socket, 'connect');
        state.redisConnected = true;
        if (env.REDIS_PASSWORD) {
            socket.write(redisCommand(['AUTH', env.REDIS_PASSWORD]));
            await readRedisSuccess(socket);
        }
        if (env.REDIS_DB && env.REDIS_DB !== '0') {
            socket.write(redisCommand(['SELECT', env.REDIS_DB]));
            await readRedisSuccess(socket);
        }
        socket.write(redisCommand(['SET', key, value, 'EX', ttlSeconds]));
        await readRedisSuccess(socket);
    } finally {
        state.redisConnected = false;
        socket.destroy();
    }
}

function waitForSocket(socket, event) {
    return new Promise((resolve, reject) => {
        socket.once(event, resolve);
        socket.once('error', reject);
        socket.once('timeout', () => reject(new Error('Redis connection timed out')));
    });
}

function readRedisSuccess(socket) {
    return new Promise((resolve, reject) => {
        const onData = (chunk) => {
            cleanup();
            const reply = chunk.toString('utf8');
            if (reply.startsWith('+OK')) {
                resolve();
            } else {
                reject(new Error(`Redis command failed: ${reply.split('\r\n')[0]}`));
            }
        };
        const onError = (error) => {
            cleanup();
            reject(error);
        };
        const cleanup = () => {
            socket.off('data', onData);
            socket.off('error', onError);
        };
        socket.once('data', onData);
        socket.once('error', onError);
    });
}

function randomId(length) {
    const alphabet = 'abcdefghijklmnopqrstuvwxyz';
    let value = '';
    for (let index = 0; index < length; index += 1) {
        value += alphabet[Math.floor(Math.random() * alphabet.length)];
    }
    return value;
}

function sanitizedProtocolDetails(details) {
    const text = JSON.stringify(details ?? null);

    return text
        .replace(/eyJ[A-Za-z0-9._-]+/g, '[REDACTED_TOKEN]')
        .slice(0, 500);
}

function mergeQuoteMessage(message) {
    if (message?.m !== 'qsd' || !Array.isArray(message.p)) {
        return message;
    }

    const update = message.p.find((item) => item && typeof item === 'object' && item.v);
    if (!update || update.n !== symbol || update.s !== 'ok') {
        return message;
    }

    state.quoteValues = {
        ...state.quoteValues,
        ...update.v,
    };

    return {
        ...message,
        p: [
            message.p[0],
            {
                ...update,
                v: state.quoteValues,
            },
        ],
    };
}

function healthPayload() {
    return {
        ok: true,
        market_session: marketSession(),
        token_present: Boolean(state.token),
        token_expires_at: state.tokenExpiresAt,
        tradingview_connected: state.tradingViewSocket?.readyState === WebSocket.OPEN,
        latest_quote_at: state.latestQuote?.quoteAt?.toISOString() || null,
        redis_connected: state.redisConnected,
        last_redis_write_at: state.lastRedisWriteAt,
        last_error: state.lastError,
        protocol_message_counts: state.protocolMessageCounts,
        symbol,
        redis_key: redisKey,
    };
}

const server = http.createServer(async (request, response) => {
    response.setHeader('Access-Control-Allow-Origin', '*');
    response.setHeader('Access-Control-Allow-Headers', 'content-type');
    if (request.method === 'OPTIONS') {
        response.writeHead(204);
        response.end();
        return;
    }

    if (request.method === 'GET' && request.url === '/health') {
        response.setHeader('Content-Type', 'application/json; charset=utf-8');
        response.end(JSON.stringify(healthPayload()));
        return;
    }

    if (request.method === 'POST' && request.url === '/tradingview-token') {
        try {
            const body = await readRequestJson(request);
            const token = String(body.token || '');
            if (!isUsableTradingViewToken(token)) {
                response.writeHead(422);
                response.end('invalid or expired token');
                return;
            }

            const metadata = decodeJwtMetadata(token);
            const changed = token !== state.token;
            state.token = token;
            state.tokenExpiresAt = new Date(metadata.expiresAt * 1000).toISOString();
            state.disconnectReason = null;
            response.writeHead(204);
            response.end();
            if (changed) {
                disconnectTradingView('authenticated_token_refreshed');
                connectTradingView();
                log('tradingview_token_received', { expires_at: state.tokenExpiresAt });
            }
        } catch (error) {
            response.writeHead(400);
            response.end('invalid request');
        }
        return;
    }

    response.writeHead(404);
    response.end('not found');
});

function readRequestJson(request) {
    return new Promise((resolve, reject) => {
        let body = '';
        request.setEncoding('utf8');
        request.on('data', (chunk) => {
            body += chunk;
            if (body.length > 32_768) {
                request.destroy();
                reject(new Error('request too large'));
            }
        });
        request.on('end', () => {
            try {
                resolve(JSON.parse(body));
            } catch (error) {
                reject(error);
            }
        });
        request.on('error', reject);
    });
}

server.listen(bridgePort, '127.0.0.1', () => {
    log('worker_started', {
        bridge: `127.0.0.1:${bridgePort}`,
        symbol,
        redis_key: redisKey,
        market_session: marketSession(),
    });
});

setInterval(() => {
    writeLatestQuote().catch((error) => {
        state.lastError = error.message;
        log('worker_tick_failed', { error: error.message });
    });
}, 1_000);

setInterval(() => {
    if (marketSession() !== null) {
        connectTradingView();
    }
}, 10_000);

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        disconnectTradingView('worker_stopping');
        server.close(() => process.exit(0));
    });
}
