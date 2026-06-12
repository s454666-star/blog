<?php

namespace App\Services;

use App\Models\TwFuturesHourlyPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class TwFuturesHourlyPriceFetcher
{
    private const TRADINGVIEW_SOCKET_HOST = 'data.tradingview.com';

    private const TRADINGVIEW_SOCKET_PORT = 443;

    private const SOURCE_NAME = 'TradingView chart websocket';

    private const DEFAULT_EXCHANGE = 'TAIFEX';

    private const DEFAULT_SYMBOL = 'TXF1!';

    private const DEFAULT_SYMBOL_NAME = '台指期近月連續';

    private const DEFAULT_TRADINGVIEW_SYMBOL = 'TAIFEX:TXF1!';

    private const DEFAULT_INTERVAL = '60';

    private const SUPPORTED_INTERVALS = ['5', '15', '30', '60'];

    private const SOCKET_TIMEOUT_SECONDS = 30;

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchRows(
        ?string $from = null,
        ?string $to = null,
        string $symbol = self::DEFAULT_SYMBOL,
        string $tradingViewSymbol = self::DEFAULT_TRADINGVIEW_SYMBOL,
        int $bars = 2200,
        string $interval = self::DEFAULT_INTERVAL,
    ): array {
        $interval = $this->normalizeInterval($interval);
        $fromDate = $from !== null && $from !== ''
            ? CarbonImmutable::parse($from, 'Asia/Taipei')->startOfDay()
            : null;
        $toDate = $to !== null && $to !== ''
            ? CarbonImmutable::parse($to, 'Asia/Taipei')->endOfDay()
            : null;

        $payload = $this->fetchTradingViewTimescale($tradingViewSymbol, $interval, $bars);
        $series = $payload['p'][1]['s1']['s'] ?? null;
        if (!is_array($series)) {
            return [];
        }

        $rows = [];
        foreach ($series as $index => $item) {
            if (!is_array($item) || !is_array($item['v'] ?? null) || count($item['v']) < 5) {
                continue;
            }

            $values = $item['v'];
            $startedAtUnix = (int) floor((float) $values[0]);
            $startedAt = CarbonImmutable::createFromTimestamp($startedAtUnix, 'UTC')->setTimezone('Asia/Taipei');
            if ($fromDate !== null && $startedAt->lessThan($fromDate)) {
                continue;
            }

            if ($toDate !== null && $startedAt->greaterThan($toDate)) {
                continue;
            }

            $close = $this->floatValue($values[4] ?? null);
            if ($close === null) {
                continue;
            }

            $rows[] = [
                'exchange' => self::DEFAULT_EXCHANGE,
                'symbol' => $symbol,
                'symbol_name' => self::DEFAULT_SYMBOL_NAME,
                'interval' => $interval,
                'started_at' => $startedAt->format('Y-m-d H:i:s'),
                'started_at_unix' => $startedAtUnix,
                'trade_date' => $this->tradeDate($startedAt),
                'session_type' => $this->sessionType($startedAt),
                'open_price' => $this->decimal($this->floatValue($values[1] ?? null) ?? $close),
                'high_price' => $this->decimal($this->floatValue($values[2] ?? null) ?? $close),
                'low_price' => $this->decimal($this->floatValue($values[3] ?? null) ?? $close),
                'close_price' => $this->decimal($close),
                'volume_contracts' => (int) round((float) ($values[5] ?? 0)),
                'source' => self::SOURCE_NAME,
                'source_payload' => [
                    'tradingview_symbol' => $tradingViewSymbol,
                    'interval' => $interval,
                    'series_index' => (int) ($item['i'] ?? $index),
                ],
                'fetched_at' => now(),
            ];
        }

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function upsertRows(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $now = now();
        $payloads = array_map(function (array $row) use ($now): array {
            $row['source_payload'] = json_encode($row['source_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $rows);

        TwFuturesHourlyPrice::query()->upsert(
            $payloads,
            ['exchange', 'symbol', 'interval', 'started_at'],
            [
                'symbol_name',
                'started_at_unix',
                'trade_date',
                'session_type',
                'open_price',
                'high_price',
                'low_price',
                'close_price',
                'volume_contracts',
                'source',
                'source_payload',
                'fetched_at',
                'updated_at',
            ],
        );

        return count($rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTradingViewTimescale(string $tradingViewSymbol, string $interval, int $bars): array
    {
        $cacheKey = 'tw-futures:prices:tradingview:v2:' . sha1(serialize([
            $tradingViewSymbol,
            $interval,
            $bars,
        ]));

        return Cache::remember(
            $cacheKey,
            $interval === '60' ? now()->addMinutes(8) : now()->addSeconds(30),
            fn (): array => $this->fetchTradingViewTimescaleUncached($tradingViewSymbol, $interval, $bars),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchTradingViewTimescaleUncached(string $tradingViewSymbol, string $interval, int $bars): array
    {
        $socket = $this->openTradingViewSocket();

        try {
            $chartSession = $this->sessionId('cs');
            $symbolPayload = '=' . json_encode([
                'symbol' => $tradingViewSymbol,
                'adjustment' => 'splits',
                'session' => 'extended',
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $this->sendTradingViewMessage($socket, 'set_auth_token', ['unauthorized_user_token']);
            $this->sendTradingViewMessage($socket, 'chart_create_session', [$chartSession, '']);
            $this->sendTradingViewMessage($socket, 'resolve_symbol', [$chartSession, 'symbol_1', $symbolPayload]);
            $this->sendTradingViewMessage($socket, 'create_series', [$chartSession, 's1', 's1', 'symbol_1', $interval, max(1, $bars)]);

            $buffer = '';
            $deadline = microtime(true) + self::SOCKET_TIMEOUT_SECONDS;
            while (microtime(true) < $deadline) {
                $chunk = fread($socket, 65536);
                if ($chunk === false) {
                    throw new RuntimeException('讀取 TradingView websocket 失敗。');
                }

                if ($chunk === '') {
                    usleep(50_000);
                    continue;
                }

                $buffer .= $chunk;
                while (($frame = $this->shiftWebSocketFrame($buffer)) !== null) {
                    if ($frame['opcode'] === 0x9) {
                        fwrite($socket, $this->webSocketFrame($frame['payload'], 0xA));
                        continue;
                    }

                    if ($frame['opcode'] !== 0x1) {
                        continue;
                    }

                    $payload = $frame['payload'];
                    if (str_starts_with($payload, '~h~')) {
                        fwrite($socket, $this->webSocketFrame($payload));
                        continue;
                    }

                    foreach ($this->tradingViewMessages($payload) as $message) {
                        $method = (string) ($message['m'] ?? '');
                        if (in_array($method, ['critical_error', 'symbol_error'], true)) {
                            throw new RuntimeException('TradingView 回應錯誤：' . json_encode($message['p'] ?? [], JSON_UNESCAPED_UNICODE));
                        }

                        if ($method === 'timescale_update') {
                            return $message;
                        }
                    }
                }
            }
        } finally {
            fclose($socket);
        }

        throw new RuntimeException(sprintf('等待 TradingView %sK 資料逾時。', $interval));
    }

    private function normalizeInterval(string $interval): string
    {
        $interval = trim($interval);
        if (! in_array($interval, self::SUPPORTED_INTERVALS, true)) {
            throw new RuntimeException(sprintf('不支援的 TradingView K 線週期：%s。', $interval));
        }

        return $interval;
    }

    /**
     * @return resource
     */
    private function openTradingViewSocket()
    {
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => self::TRADINGVIEW_SOCKET_HOST,
                'SNI_enabled' => true,
            ],
        ]);

        $socket = stream_socket_client(
            'ssl://' . self::TRADINGVIEW_SOCKET_HOST . ':' . self::TRADINGVIEW_SOCKET_PORT,
            $errorCode,
            $errorMessage,
            self::SOCKET_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if ($socket === false) {
            throw new RuntimeException(sprintf('連線 TradingView 失敗：%s (%d)', $errorMessage, $errorCode));
        }

        stream_set_timeout($socket, self::SOCKET_TIMEOUT_SECONDS);

        $key = base64_encode(random_bytes(16));
        $path = '/socket.io/websocket?from=chart%2F&date=' . (int) floor(microtime(true) * 1000);
        $headers = [
            'GET ' . $path . ' HTTP/1.1',
            'Host: ' . self::TRADINGVIEW_SOCKET_HOST,
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: ' . $key,
            'Sec-WebSocket-Version: 13',
            'Origin: https://www.tradingview.com',
            'User-Agent: Mozilla/5.0',
            '',
            '',
        ];
        fwrite($socket, implode("\r\n", $headers));

        $header = '';
        while (!str_contains($header, "\r\n\r\n")) {
            $chunk = fread($socket, 4096);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('TradingView websocket 握手沒有回應。');
            }

            $header .= $chunk;
            if (strlen($header) > 16384) {
                throw new RuntimeException('TradingView websocket 握手回應過長。');
            }
        }

        if (!str_starts_with($header, 'HTTP/1.1 101')) {
            throw new RuntimeException('TradingView websocket 握手失敗：' . trim(strtok($header, "\r\n")));
        }

        return $socket;
    }

    /**
     * @param resource $socket
     * @param list<mixed> $params
     */
    private function sendTradingViewMessage($socket, string $method, array $params): void
    {
        $message = json_encode(['m' => $method, 'p' => $params], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        fwrite($socket, $this->webSocketFrame('~m~' . strlen($message) . '~m~' . $message));
    }

    private function webSocketFrame(string $payload, int $opcode = 0x1): string
    {
        $length = strlen($payload);
        $firstByte = 0x80 | ($opcode & 0x0F);
        if ($length < 126) {
            $header = pack('CC', $firstByte, 0x80 | $length);
        } elseif ($length < 65536) {
            $header = pack('CCn', $firstByte, 0x80 | 126, $length);
        } else {
            $header = pack('CCNN', $firstByte, 0x80 | 127, intdiv($length, 4294967296), $length % 4294967296);
        }

        $mask = random_bytes(4);
        $masked = '';
        for ($index = 0; $index < $length; $index++) {
            $masked .= $payload[$index] ^ $mask[$index % 4];
        }

        return $header . $mask . $masked;
    }

    /**
     * @return array{opcode: int, payload: string}|null
     */
    private function shiftWebSocketFrame(string &$buffer): ?array
    {
        if (strlen($buffer) < 2) {
            return null;
        }

        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);
        $opcode = $firstByte & 0x0F;
        $length = $secondByte & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if (strlen($buffer) < 4) {
                return null;
            }

            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (strlen($buffer) < 10) {
                return null;
            }

            $parts = unpack('Nhigh/Nlow', substr($buffer, 2, 8));
            $length = ((int) $parts['high'] * 4294967296) + (int) $parts['low'];
            $offset = 10;
        }

        $isMasked = ($secondByte & 0x80) === 0x80;
        $mask = '';
        if ($isMasked) {
            if (strlen($buffer) < $offset + 4) {
                return null;
            }

            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if (strlen($buffer) < $offset + $length) {
            return null;
        }

        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);

        if ($isMasked) {
            $unmasked = '';
            for ($index = 0; $index < $length; $index++) {
                $unmasked .= $payload[$index] ^ $mask[$index % 4];
            }
            $payload = $unmasked;
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tradingViewMessages(string $payload): array
    {
        $messages = [];
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $prefix = strpos($payload, '~m~', $offset);
            if ($prefix === false) {
                break;
            }

            $lengthStart = $prefix + 3;
            $lengthEnd = strpos($payload, '~m~', $lengthStart);
            if ($lengthEnd === false) {
                break;
            }

            $messageLength = (int) substr($payload, $lengthStart, $lengthEnd - $lengthStart);
            $messageStart = $lengthEnd + 3;
            $json = substr($payload, $messageStart, $messageLength);
            $offset = $messageStart + $messageLength;

            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                $messages[] = $decoded;
            }
        }

        return $messages;
    }

    private function sessionId(string $prefix): string
    {
        return $prefix . '_' . bin2hex(random_bytes(6));
    }

    private function tradeDate(CarbonImmutable $startedAt): string
    {
        $candidate = (int) $startedAt->format('H') >= 15
            ? $startedAt->addDay()
            : $startedAt;

        while ($candidate->isWeekend()) {
            $candidate = $candidate->addDay();
        }

        return $candidate->toDateString();
    }

    private function sessionType(CarbonImmutable $startedAt): string
    {
        $time = $startedAt->format('H:i');
        if ($time >= '08:45' && $time < '14:30') {
            return 'day';
        }

        if ($time >= '15:00' || $time < '05:30') {
            return 'night';
        }

        return 'break';
    }

    private function floatValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function decimal(float $value): string
    {
        return number_format($value, 4, '.', '');
    }
}
