import http from 'node:http';
import net from 'node:net';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    DEFAULT_REDIS_KEY,
    DEFAULT_SYMBOL,
    isFreshOpenQuote,
    marketSession,
    quoteFromBrowserBridgePayload,
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
    latestQuote: null,
    redisConnected: false,
    lastRedisWriteAt: null,
    lastBrowserQuoteAt: null,
    lastError: null,
    browserQuoteCount: 0,
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

async function writeLatestQuote() {
    const now = new Date();
    if (marketSession(now) === null) {
        return;
    }

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

function healthPayload() {
    return {
        ok: true,
        market_session: marketSession(),
        input_mode: 'tradingview_browser_page',
        browser_quote_count: state.browserQuoteCount,
        last_browser_quote_at: state.lastBrowserQuoteAt,
        latest_quote_at: state.latestQuote?.quoteAt?.toISOString() || null,
        redis_connected: state.redisConnected,
        last_redis_write_at: state.lastRedisWriteAt,
        last_error: state.lastError,
        symbol,
        redis_key: redisKey,
    };
}

const server = http.createServer(async (request, response) => {
    const requestOrigin = String(request.headers.origin || '');
    const extensionOrigin = requestOrigin.startsWith('chrome-extension://') ? requestOrigin : null;
    if (extensionOrigin) {
        response.setHeader('Access-Control-Allow-Origin', extensionOrigin);
        response.setHeader('Vary', 'Origin');
    }
    response.setHeader('Access-Control-Allow-Headers', 'content-type');
    if (request.method === 'OPTIONS') {
        response.writeHead(extensionOrigin ? 204 : 403);
        response.end();
        return;
    }

    if (request.method === 'GET' && request.url === '/health') {
        response.setHeader('Content-Type', 'application/json; charset=utf-8');
        response.end(JSON.stringify(healthPayload()));
        return;
    }

    if (request.method === 'POST' && request.url === '/tradingview-quote') {
        if (!extensionOrigin) {
            response.writeHead(403);
            response.end('extension origin required');
            return;
        }

        try {
            const body = await readRequestJson(request);
            const receivedAt = new Date();
            const quote = quoteFromBrowserBridgePayload(body, symbol, receivedAt);
            if (quote === null) {
                response.writeHead(422);
                response.end('invalid quote');
                return;
            }

            state.latestQuote = quote;
            state.lastBrowserQuoteAt = receivedAt.toISOString();
            state.browserQuoteCount += 1;
            state.lastError = null;
            response.writeHead(204);
            response.end();
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

for (const signal of ['SIGINT', 'SIGTERM']) {
    process.on(signal, () => {
        server.close(() => process.exit(0));
    });
}
