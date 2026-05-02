<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SyncTelegramGroupMessagesCommand extends Command
{
    protected $signature = 'tg:sync-group-messages
        {--base-uri=http://127.0.0.1:8001/ : Telegram FastAPI base URI}
        {--peer-id=3338106820 : Telegram group peer id}
        {--batch-size=1000 : Messages to fetch per API request}
        {--from-date= : Only store messages sent on or after this date/time, using Asia/Taipei timezone}
        {--start-message-id= : Override start message id, exclusive}
        {--stop-message-id= : Stop after this message id}
        {--sleep-ms=0 : Sleep between requests in milliseconds}';

    protected $description = '同步 Telegram 群組訊息到 telegram_group_messages';

    private const TABLE = 'telegram_group_messages';
    private const TARGET_TIMEZONE = 'Asia/Taipei';

    public function handle(): int
    {
        $baseUri = rtrim((string) $this->option('base-uri'), '/') . '/';
        $peerId = (int) $this->option('peer-id');
        $batchSize = max(1, min(5000, (int) $this->option('batch-size')));
        $sleepMs = max(0, (int) $this->option('sleep-ms'));
        $stopMessageId = $this->parseNullablePositiveInt($this->option('stop-message-id'));
        $startOverride = $this->parseNullablePositiveInt($this->option('start-message-id'));
        $fromDate = $this->parseFromDate($this->option('from-date'));

        if ($peerId <= 0) {
            $this->error('peer-id 必須是正整數。');
            return self::FAILURE;
        }

        if ($this->option('from-date') !== null && $fromDate === null) {
            $this->error('from-date 格式無法解析。');
            return self::FAILURE;
        }

        $cursor = $startOverride ?? $this->maxStoredMessageId($peerId);
        $groupInfo = $this->fetchGroupInfo($baseUri, $peerId);
        $lastGroupMessageId = (int) ($groupInfo['last_message_id'] ?? 0);

        if ($startOverride === null && $fromDate !== null && $lastGroupMessageId > 0) {
            $dateCursor = $this->findCursorBeforeDate($baseUri, $peerId, $fromDate, $lastGroupMessageId);
            $cursor = max($cursor, $dateCursor);
        }

        $this->line(sprintf(
            'base_uri=%s peer_id=%d title=%s from_date=%s start_after=%d last_message_id=%s',
            $baseUri,
            $peerId,
            (string) ($groupInfo['title'] ?? ''),
            $fromDate?->format('Y-m-d H:i:s') ?? '-',
            $cursor,
            $lastGroupMessageId > 0 ? (string) $lastGroupMessageId : '-'
        ));

        $totalFetched = 0;
        $totalInserted = 0;

        while (true) {
            if ($stopMessageId !== null && $cursor >= $stopMessageId) {
                $this->line("已達 stop-message-id={$stopMessageId}，停止。");
                break;
            }

            if ($lastGroupMessageId > 0 && $cursor >= $lastGroupMessageId) {
                $this->line("已掃到群組最新 last_message_id={$lastGroupMessageId}，停止。");
                break;
            }

            $payload = $this->fetchGroupPage($baseUri, $peerId, $cursor, $batchSize);
            if (!is_array($payload) || (string) ($payload['status'] ?? '') !== 'ok') {
                $this->error('Telegram API 取回失敗：' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                return self::FAILURE;
            }

            $items = $payload['items'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                $this->line("cursor={$cursor} 沒有新訊息，停止。");
                break;
            }

            $rows = [];
            $maxMessageId = $cursor;
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $messageId = (int) ($item['id'] ?? 0);
                if ($messageId <= $cursor) {
                    continue;
                }

                if ($stopMessageId !== null && $messageId > $stopMessageId) {
                    continue;
                }

                $maxMessageId = max($maxMessageId, $messageId);
                $sentAt = $this->parseSentAtCarbon($item['date'] ?? null);

                if ($fromDate !== null && ($sentAt === null || $sentAt->lt($fromDate))) {
                    continue;
                }

                $rows[] = [
                    'group_message' => $this->extractMessageText($item),
                    'sent_at' => $sentAt?->format('Y-m-d H:i:s'),
                    'message_code' => $this->messageCode($peerId, $messageId),
                ];
            }

            if ($maxMessageId <= $cursor) {
                $this->line("cursor={$cursor} 沒有前進，停止。");
                break;
            }

            $inserted = $this->insertRows($rows);
            $totalFetched += count($rows);
            $totalInserted += $inserted;

            $this->line(sprintf(
                'cursor=%d -> %d fetched=%d inserted=%d total_inserted=%d',
                $cursor,
                $maxMessageId,
                count($rows),
                $inserted,
                $totalInserted
            ));

            $cursor = $maxMessageId;

            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->table(
            ['peer_id', 'fetched', 'inserted', 'max_message_id'],
            [[
                'peer_id' => $peerId,
                'fetched' => $totalFetched,
                'inserted' => $totalInserted,
                'max_message_id' => $cursor,
            ]]
        );

        return self::SUCCESS;
    }

    private function fetchGroupInfo(string $baseUri, int $peerId): array
    {
        try {
            $response = Http::timeout(30)
                ->connectTimeout(10)
                ->get($baseUri . 'groups', [
                    'limit' => 500,
                    'include_channels' => true,
                ]);
        } catch (\Throwable $e) {
            $this->warn('GET /groups 失敗：' . $e->getMessage());
            return [];
        }

        if (!$response->ok()) {
            $this->warn('GET /groups HTTP ' . $response->status());
            return [];
        }

        $items = $response->json('items');
        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if (is_array($item) && (int) ($item['id'] ?? 0) === $peerId) {
                return $item;
            }
        }

        return [];
    }

    private function findCursorBeforeDate(string $baseUri, int $peerId, Carbon $fromDate, int $lastGroupMessageId): int
    {
        $low = 0;
        $high = max(1, $lastGroupMessageId);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $item = $this->fetchFirstItemAfter($baseUri, $peerId, $mid);

            if (!is_array($item)) {
                $high = $mid;
                continue;
            }

            $sentAt = $this->parseSentAtCarbon($item['date'] ?? null);
            if ($sentAt !== null && $sentAt->gte($fromDate)) {
                $high = $mid;
                continue;
            }

            $low = $mid + 1;
        }

        return max(0, $low);
    }

    private function fetchFirstItemAfter(string $baseUri, int $peerId, int $cursor): ?array
    {
        $payload = $this->fetchGroupPage($baseUri, $peerId, $cursor, 1);
        if (!is_array($payload) || (string) ($payload['status'] ?? '') !== 'ok') {
            return null;
        }

        $items = $payload['items'] ?? [];
        if (!is_array($items) || empty($items) || !is_array($items[0] ?? null)) {
            return null;
        }

        return $items[0];
    }

    private function fetchGroupPage(string $baseUri, int $peerId, int $cursor, int $batchSize): ?array
    {
        try {
            $response = Http::timeout(60)
                ->connectTimeout(10)
                ->get($baseUri . "groups/{$peerId}/{$cursor}", [
                    'next_limit' => $batchSize,
                    'include_raw' => false,
                ]);
        } catch (\Throwable $e) {
            return [
                'status' => 'fail',
                'reason' => 'request_error',
                'error' => $e->getMessage(),
            ];
        }

        if (!$response->ok()) {
            return [
                'status' => 'fail',
                'reason' => 'http_' . $response->status(),
                'body' => Str::limit($response->body(), 1000),
            ];
        }

        $json = $response->json();
        return is_array($json) ? $json : null;
    }

    private function insertRows(array $rows): int
    {
        $inserted = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            if (empty($chunk)) {
                continue;
            }

            $inserted += (int) DB::table(self::TABLE)->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    private function extractMessageText(array $item): ?string
    {
        if (!array_key_exists('text', $item)) {
            return null;
        }

        $text = $item['text'];
        return is_string($text) ? $text : null;
    }

    private function parseSentAtCarbon(mixed $raw): ?Carbon
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)
                ->setTimezone(self::TARGET_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseFromDate(mixed $raw): ?Carbon
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $raw, self::TARGET_TIMEZONE)
                ->setTimezone(self::TARGET_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    private function messageCode(int $peerId, int $messageId): string
    {
        return "https://t.me/c/{$peerId}/{$messageId}";
    }

    private function maxStoredMessageId(int $peerId): int
    {
        $prefix = "https://t.me/c/{$peerId}/";
        $max = 0;

        DB::table(self::TABLE)
            ->where('message_code', 'like', $prefix . '%')
            ->select('message_code')
            ->orderBy('message_code')
            ->chunk(5000, function ($rows) use ($prefix, &$max): void {
                foreach ($rows as $row) {
                    $messageCode = (string) ($row->message_code ?? '');
                    if (!str_starts_with($messageCode, $prefix)) {
                        continue;
                    }

                    $messageId = (int) substr($messageCode, strlen($prefix));
                    if ($messageId > $max) {
                        $max = $messageId;
                    }
                }
            });

        return $max;
    }

    private function parseNullablePositiveInt(mixed $raw): ?int
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }

        $value = (int) $raw;
        return $value > 0 ? $value : null;
    }
}
