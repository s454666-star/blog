<?php

namespace App\Services;

use App\Models\TwFuturesRealtimeQuote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Throwable;

class TwFuturesRealtimeQuoteService
{
    public const REDIS_CONNECTION = 'taiex_realtime';

    public const REDIS_KEY = 'tw-futures:realtime:tradingview:TAIFEX:TXF1!';

    public const SYMBOL = 'TXF1!';

    public const MAX_SOURCE_AGE_SECONDS = 15;

    /**
     * @return array{status: string, quote: TwFuturesRealtimeQuote|null}
     */
    public function consumeLatest(): array
    {
        $payload = $this->redisPayload();
        if ($payload === null) {
            return ['status' => 'empty_or_invalid', 'quote' => null];
        }

        $existing = TwFuturesRealtimeQuote::query()
            ->where('symbol', self::SYMBOL)
            ->first();
        if (
            $existing !== null
            && $existing->written_at !== null
            && $existing->written_at->greaterThanOrEqualTo($payload['writtenAt'])
        ) {
            return ['status' => 'unchanged', 'quote' => $existing];
        }

        $barStartedAt = $payload['quotedAt']->setTime(
            (int) $payload['quotedAt']->format('H'),
            intdiv((int) $payload['quotedAt']->format('i'), 15) * 15,
            0,
        );
        $sameBar = $existing?->bar_started_at !== null
            && $existing->bar_started_at->equalTo($barStartedAt);
        $barOpen = $sameBar ? (float) $existing->bar_open : $payload['price'];
        $barHigh = $sameBar ? max((float) $existing->bar_high, $payload['price']) : $payload['price'];
        $barLow = $sameBar ? min((float) $existing->bar_low, $payload['price']) : $payload['price'];

        $quote = TwFuturesRealtimeQuote::query()->updateOrCreate(
            ['symbol' => self::SYMBOL],
            [
                'price' => $payload['price'],
                'volume' => $payload['volume'],
                'quote_at' => $payload['quotedAt'],
                'written_at' => $payload['writtenAt'],
                'bar_started_at' => $barStartedAt,
                'bar_open' => $barOpen,
                'bar_high' => $barHigh,
                'bar_low' => $barLow,
                'source' => $payload['source'],
                'auth_mode' => 'authenticated',
                'source_payload' => $payload['sourcePayload'],
            ],
        );

        return ['status' => 'stored', 'quote' => $quote];
    }

    /**
     * @return array{
     *     price: float,
     *     volume: int|null,
     *     quotedAt: CarbonImmutable,
     *     writtenAt: string,
     *     barStartedAt: CarbonImmutable,
     *     barOpen: float,
     *     barHigh: float,
     *     barLow: float,
     *     source: string,
     *     authMode: string
     * }|null
     */
    public function latestFresh(int $maxAgeSeconds = self::MAX_SOURCE_AGE_SECONDS): ?array
    {
        $quote = TwFuturesRealtimeQuote::query()
            ->where('symbol', self::SYMBOL)
            ->first();
        if (
            $quote === null
            || $quote->quote_at === null
            || $quote->written_at === null
            || $quote->bar_started_at === null
        ) {
            return null;
        }

        $now = CarbonImmutable::now('Asia/Taipei');
        $quotedAt = CarbonImmutable::instance($quote->quote_at)->setTimezone('Asia/Taipei');
        $writtenAt = CarbonImmutable::instance($quote->written_at)->setTimezone('Asia/Taipei');
        $quoteAge = $now->timestamp - $quotedAt->timestamp;
        $writeAge = $now->timestamp - $writtenAt->timestamp;
        if (
            $quoteAge < -5
            || $quoteAge > $maxAgeSeconds
            || $writeAge < -5
            || $writeAge > $maxAgeSeconds
            || $quote->auth_mode !== 'authenticated'
            || (float) $quote->price <= 0.0
        ) {
            return null;
        }

        return [
            'price' => (float) $quote->price,
            'volume' => $quote->volume === null ? null : (int) $quote->volume,
            'quotedAt' => $quotedAt,
            'writtenAt' => $writtenAt->toIso8601String(),
            'barStartedAt' => CarbonImmutable::instance($quote->bar_started_at)->setTimezone('Asia/Taipei'),
            'barOpen' => (float) $quote->bar_open,
            'barHigh' => (float) $quote->bar_high,
            'barLow' => (float) $quote->bar_low,
            'source' => (string) $quote->source,
            'authMode' => 'authenticated',
        ];
    }

    /**
     * @return array{
     *     price: float,
     *     volume: int|null,
     *     quotedAt: CarbonImmutable,
     *     writtenAt: CarbonImmutable,
     *     source: string,
     *     sourcePayload: array<string, mixed>
     * }|null
     */
    private function redisPayload(): ?array
    {
        try {
            $value = Redis::connection(self::REDIS_CONNECTION)->get(self::REDIS_KEY);
            if (! is_string($value) || $value === '') {
                return null;
            }

            $payload = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
            $price = $payload['price'] ?? null;
            $quoteAtValue = $payload['quote_at'] ?? null;
            $writtenAtValue = $payload['written_at'] ?? null;
            if (
                ($payload['schema_version'] ?? null) !== 1
                || ($payload['symbol'] ?? null) !== 'TAIFEX:TXF1!'
                || ($payload['auth_mode'] ?? null) !== 'authenticated'
                || ! is_numeric($price)
                || (float) $price <= 0.0
                || ! is_string($quoteAtValue)
                || ! is_string($writtenAtValue)
            ) {
                return null;
            }

            $quotedAt = CarbonImmutable::parse($quoteAtValue)->setTimezone('Asia/Taipei');
            $writtenAt = CarbonImmutable::parse($writtenAtValue)->setTimezone('Asia/Taipei');
            $now = CarbonImmutable::now('Asia/Taipei');
            $quoteAge = $now->timestamp - $quotedAt->timestamp;
            $writeAge = $now->timestamp - $writtenAt->timestamp;
            if (
                $quoteAge < -5
                || $quoteAge > self::MAX_SOURCE_AGE_SECONDS
                || $writeAge < -5
                || $writeAge > self::MAX_SOURCE_AGE_SECONDS
            ) {
                return null;
            }

            return [
                'price' => (float) $price,
                'volume' => is_numeric($payload['volume'] ?? null) ? (int) $payload['volume'] : null,
                'quotedAt' => $quotedAt,
                'writtenAt' => $writtenAt,
                'source' => (string) ($payload['source'] ?? 'TradingView authenticated websocket'),
                'sourcePayload' => $payload,
            ];
        } catch (Throwable) {
            return null;
        }
    }
}
