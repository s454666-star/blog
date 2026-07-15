<?php

namespace App\Console\Commands;

use App\Models\TelegramResourceCode;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessTelegramResourceCodesCommand extends Command
{
    protected $signature = 'telegram:process-resource-codes
        {--once : Scan and process the available queue once, then exit}
        {--scan-only : Scan source groups without sending codes to the bot}
        {--process-limit=0 : Maximum completed codes in this run; 0 means unlimited}
        {--initial-scan-limit= : Messages read on the first scan of each source group}
        {--scan-batch-size= : Messages read per incremental scan request}
        {--loop-sleep-seconds= : Seconds between continuous worker cycles}
        {--request-timeout-seconds= : Per-code Telegram API timeout}
        {--base-uris= : Comma-separated Telegram FastAPI account endpoints}
        {--source-peer-ids= : Comma-separated source group peer ids}
        {--target-peer-id= : Resource group peer id}
        {--bot-username= : Decoder bot username}
        {--code-type= : Numeric code type stored with new codes}';

    protected $description = 'Scan Telegram groups for configured resource codes and serially forward decoded media to the resource group.';

    private const LOCK_NAME = 'blog:telegram-resource-code-worker';
    private const HEX_CODE_REGEX = '/(?<![0-9a-f])[0-9a-f]{40}(?![0-9a-f])/i';
    private const WENJIANJI_CODE_REGEX = '/(?<![A-Za-z0-9_])wenjianjibot_(?:[0-9]+[A-Za-z]_)+[A-Za-z0-9]{16}(?![A-Za-z0-9_])/i';
    private const STALE_PROCESSING_MINUTES = 30;
    private const MAX_PROCESSING_ATTEMPTS = 3;

    /** @var array<int, string> */
    private array $baseUris = [];

    /** @var array<int, int> */
    private array $sourcePeerIds = [];

    private int $targetPeerId;
    private string $botUsername;
    private int $codeType;
    private int $initialScanLimit;
    private int $scanBatchSize;
    private int $loopSleepSeconds;
    private int $requestTimeoutSeconds;
    private bool $mysqlLockAcquired = false;

    public function handle(): int
    {
        if (!extension_loaded('redis')) {
            config()->set('database.redis.client', 'predis');
            app()->forgetInstance('redis');
        }

        $this->loadOptions();

        if ($this->baseUris === [] || $this->sourcePeerIds === [] || $this->targetPeerId <= 0 || $this->botUsername === '') {
            $this->error('Telegram resource-code configuration is incomplete.');
            return self::FAILURE;
        }

        if (!$this->acquireSingletonLock()) {
            $this->warn('Another telegram resource-code worker already owns the database lock; exiting.');
            return self::SUCCESS;
        }

        try {
            $this->recoverStaleRows();
            $completedThisRun = 0;
            $processLimit = max(0, (int) $this->option('process-limit'));

            do {
                $this->scanSources();

                if (!$this->option('scan-only')) {
                    while ($processLimit === 0 || $completedThisRun < $processLimit) {
                        $result = $this->processNextCode();
                        if ($result === null) {
                            break;
                        }
                        if ($result) {
                            $completedThisRun++;
                        }
                    }
                }

                if ($this->option('once') || $this->option('scan-only')) {
                    break;
                }

                sleep($this->loopSleepSeconds);
            } while (true);

            $this->line("completed_this_run={$completedThisRun}");
            return self::SUCCESS;
        } finally {
            $this->releaseSingletonLock();
        }
    }

    private function loadOptions(): void
    {
        $config = (array) config('telegram.resource_codes', []);

        $this->baseUris = array_values(array_unique(array_map(
            static fn (string $uri): string => rtrim(trim($uri), '/'),
            $this->csv((string) ($this->option('base-uris') ?: ($config['base_uris'] ?? '')))
        )));
        $this->baseUris = array_values(array_filter($this->baseUris));

        $this->sourcePeerIds = array_values(array_unique(array_filter(array_map(
            static fn (string $value): int => (int) $value,
            $this->csv((string) ($this->option('source-peer-ids') ?: ($config['source_peer_ids'] ?? '')))
        ), static fn (int $value): bool => $value > 0)));

        $this->targetPeerId = (int) ($this->option('target-peer-id') ?: ($config['target_peer_id'] ?? 0));
        $this->botUsername = ltrim(trim((string) ($this->option('bot-username') ?: ($config['bot_username'] ?? ''))), '@');
        $this->codeType = max(1, min(255, (int) ($this->option('code-type') ?: ($config['code_type'] ?? 1))));
        $this->initialScanLimit = max(1, min(5000, (int) ($this->option('initial-scan-limit') ?: ($config['initial_scan_limit'] ?? 1000))));
        $this->scanBatchSize = max(1, min(1000, (int) ($this->option('scan-batch-size') ?: ($config['scan_batch_size'] ?? 500))));
        $this->loopSleepSeconds = max(1, (int) ($this->option('loop-sleep-seconds') ?: ($config['loop_sleep_seconds'] ?? 10)));
        $this->requestTimeoutSeconds = max(30, (int) ($this->option('request-timeout-seconds') ?: ($config['request_timeout_seconds'] ?? 240)));
    }

    /** @return array<int, string> */
    private function csv(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $item): bool => $item !== ''));
    }

    private function scanSources(): void
    {
        foreach ($this->sourcePeerIds as $peerId) {
            $this->scanSource($peerId);
        }
    }

    private function scanSource(int $peerId): void
    {
        $cursorKey = "telegram_resource_codes:scan_cursor:{$this->codeType}:{$peerId}";
        $cursor = max(0, (int) Cache::store('telegram_resource_codes')->get($cursorKey, 0));
        $isInitial = $cursor === 0;
        $pages = 0;

        do {
            $path = $isInitial
                ? "/groups/{$peerId}"
                : "/groups/{$peerId}/{$cursor}";
            $query = $isInitial
                ? ['limit' => $this->initialScanLimit, 'include_raw' => 'false']
                : ['next_limit' => $this->scanBatchSize, 'include_raw' => 'false'];

            $payload = $this->getFromAvailableAccount($path, $query);
            if ($payload === null || (string) ($payload['status'] ?? 'ok') !== 'ok') {
                $this->warn("source_peer_id={$peerId} scan unavailable");
                return;
            }

            $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
            $maxMessageId = $cursor;
            $found = 0;
            $inserted = 0;

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $messageId = max(0, (int) ($item['id'] ?? 0));
                $maxMessageId = max($maxMessageId, $messageId);
                $text = (string) ($item['text'] ?? '');
                preg_match_all($this->codeRegex(), $text, $matches);

                foreach (array_unique($matches[0] ?? []) as $rawCode) {
                    $code = $this->normalizeCode((string) $rawCode);
                    $found++;
                    $inserted += (int) DB::table('telegram_resource_codes')->insertOrIgnore([
                        'code' => $code,
                        'code_type' => $this->codeType,
                        'status' => TelegramResourceCode::STATUS_PENDING,
                        'source_peer_id' => $peerId,
                        'source_message_id' => $messageId > 0 ? $messageId : null,
                        'attempts' => 0,
                        'forwarded_message_count' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if ($maxMessageId > $cursor) {
                $cursor = $maxMessageId;
                Cache::store('telegram_resource_codes')->forever($cursorKey, $cursor);
            }

            $this->line("source_peer_id={$peerId} cursor={$cursor} messages=" . count($items) . " codes={$found} inserted={$inserted}");
            $pages++;
            $isInitial = false;
        } while (count($items) >= $this->scanBatchSize && $pages < 20);
    }

    private function processNextCode(): ?bool
    {
        $row = DB::table('telegram_resource_codes')
            ->where('status', TelegramResourceCode::STATUS_PENDING)
            ->where('code_type', $this->codeType)
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })
            ->orderBy('attempts')
            ->orderBy('id')
            ->first();

        if ($row === null) {
            return null;
        }

        $claimed = DB::table('telegram_resource_codes')
            ->where('id', $row->id)
            ->where('status', TelegramResourceCode::STATUS_PENDING)
            ->where('code_type', $this->codeType)
            ->update([
                'status' => TelegramResourceCode::STATUS_PROCESSING,
                'attempts' => DB::raw('attempts + 1'),
                'processing_started_at' => now(),
                'available_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $earliestCooldown = null;
        $transportFailures = 0;
        $processingFailureCountsTowardLimit = false;
        $processingFailureReason = 'unknown';

        foreach ($this->baseUris as $accountIndex => $baseUri) {
            $cooldownUntil = $this->cooldownUntil($baseUri);
            if ($cooldownUntil > time()) {
                $earliestCooldown = $earliestCooldown === null ? $cooldownUntil : min($earliestCooldown, $cooldownUntil);
                continue;
            }

            try {
                $response = Http::connectTimeout(8)
                    ->timeout(max(86400, $this->requestTimeoutSeconds + 30))
                    ->post($baseUri . '/resource-codes/process', [
                        'code' => (string) $row->code,
                        'bot_username' => $this->botUsername,
                        'target_peer_id' => $this->targetPeerId,
                        'wait_timeout_seconds' => $this->requestTimeoutSeconds,
                        'drop_media_captions' => false,
                    ]);
            } catch (\Throwable $e) {
                $transportFailures++;
                $this->markCooldown($baseUri, 30);
                Log::warning('Telegram resource-code account endpoint unavailable', [
                    'code_id' => (int) $row->id,
                    'account_index' => $accountIndex + 1,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            $payload = $response->json();
            $payload = is_array($payload) ? $payload : [];
            $forwardedCount = max(0, (int) ($payload['forwarded_count'] ?? 0));
            $decoderSentCount = max(0, (int) ($payload['expected_media_count'] ?? $forwardedCount));
            $decoderTotalCount = max(0, (int) ($payload['declared_file_count'] ?? $decoderSentCount));

            if ($response->status() === 429 || $this->isFloodWait($payload)) {
                $waitSeconds = $this->floodWaitSeconds($response, $payload);
                $until = $this->markCooldown($baseUri, $waitSeconds);
                $earliestCooldown = $earliestCooldown === null ? $until : min($earliestCooldown, $until);
                $this->warn("code_id={$row->id} account=" . ($accountIndex + 1) . " flood_wait={$waitSeconds}s; switching account");
                continue;
            }

            if ($this->isAccountScopedFailure($payload)) {
                $transportFailures++;
                $until = $this->markCooldown($baseUri, 30);
                $earliestCooldown = $earliestCooldown === null ? $until : min($earliestCooldown, $until);
                Log::warning('Telegram resource-code account could not send to decoder', [
                    'code_id' => (int) $row->id,
                    'account_index' => $accountIndex + 1,
                    'reason' => (string) ($payload['reason'] ?? $payload['status'] ?? 'unknown'),
                    'phase' => (string) ($payload['phase'] ?? ''),
                ]);
                continue;
            }

            if ($response->successful()
                && (string) ($payload['status'] ?? '') === 'ok'
                && (bool) ($payload['cleanup_complete'] ?? false)
                && $forwardedCount > 0
                && $forwardedCount === $decoderSentCount) {
                DB::table('telegram_resource_codes')->where('id', $row->id)->update([
                    'status' => TelegramResourceCode::STATUS_COMPLETED,
                    'processing_account' => $accountIndex + 1,
                    'forwarded_message_count' => $forwardedCount,
                    'decoder_sent_count' => $decoderSentCount,
                    'decoder_total_count' => $decoderTotalCount,
                    'processing_started_at' => null,
                    'available_at' => null,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->info("code_id={$row->id} completed account=" . ($accountIndex + 1) . " forwarded={$forwardedCount} decoder_sent={$decoderSentCount} decoder_total={$decoderTotalCount}");
                return true;
            }

            if ($response->successful()
                && (string) ($payload['status'] ?? '') === 'skip'
                && (string) ($payload['reason'] ?? '') === 'dormant'
                && (bool) ($payload['cleanup_complete'] ?? false)) {
                DB::table('telegram_resource_codes')->where('id', $row->id)->update([
                    'status' => TelegramResourceCode::STATUS_SKIPPED,
                    'skip_reason' => TelegramResourceCode::SKIP_REASON_DORMANT,
                    'processing_account' => $accountIndex + 1,
                    'forwarded_message_count' => 0,
                    'decoder_sent_count' => 0,
                    'decoder_total_count' => 0,
                    'processing_started_at' => null,
                    'available_at' => null,
                    'completed_at' => null,
                    'skipped_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->warn("code_id={$row->id} skipped reason=dormant account=" . ($accountIndex + 1));
                return true;
            }

            $failureReason = (string) ($payload['reason'] ?? $payload['status'] ?? 'unknown');
            if ($response->successful()
                && (string) ($payload['status'] ?? '') === 'ok'
                && $forwardedCount !== $decoderSentCount) {
                $failureReason = 'media_count_mismatch';
            }

            Log::warning('Telegram resource-code processing failed', [
                'code_id' => (int) $row->id,
                'account_index' => $accountIndex + 1,
                'http_status' => $response->status(),
                'reason' => $failureReason,
            ]);
            $processingFailureReason = $failureReason;
            $processingFailureCountsTowardLimit = $this->countsTowardRetryLimit($processingFailureReason);
            break;
        }

        $attemptNumber = (int) $row->attempts + 1;
        if ($processingFailureCountsTowardLimit && $attemptNumber >= self::MAX_PROCESSING_ATTEMPTS) {
            DB::table('telegram_resource_codes')->where('id', $row->id)->update([
                'status' => TelegramResourceCode::STATUS_SKIPPED,
                'skip_reason' => TelegramResourceCode::SKIP_REASON_RETRY_LIMIT,
                'processing_started_at' => null,
                'available_at' => null,
                'completed_at' => null,
                'skipped_at' => now(),
                'updated_at' => now(),
            ]);

            $this->warn("code_id={$row->id} skipped reason=retry_limit attempts={$attemptNumber} last_failure={$processingFailureReason}");
            return true;
        }

        $retryAt = $earliestCooldown !== null
            ? now()->setTimestamp(max(time() + 1, $earliestCooldown))
            : now()->addSeconds($transportFailures >= count($this->baseUris)
                ? 30
                : min(3600, 60 * (2 ** min(6, max(0, (int) $row->attempts)))));

        DB::table('telegram_resource_codes')->where('id', $row->id)->update([
            'status' => TelegramResourceCode::STATUS_PENDING,
            'attempts' => $processingFailureCountsTowardLimit
                ? DB::raw('attempts')
                : DB::raw('CASE WHEN attempts > 0 THEN attempts - 1 ELSE 0 END'),
            'processing_started_at' => null,
            'available_at' => $retryAt,
            'updated_at' => now(),
        ]);

        return false;
    }

    private function countsTowardRetryLimit(string $reason): bool
    {
        return in_array($reason, ['not_found', 'media_timeout', 'media_count_mismatch'], true);
    }

    private function codeRegex(): string
    {
        return $this->codeType === 2
            ? self::WENJIANJI_CODE_REGEX
            : self::HEX_CODE_REGEX;
    }

    private function normalizeCode(string $code): string
    {
        if ($this->codeType === 1) {
            return strtolower($code);
        }

        return (string) preg_replace('/^wenjianjibot_/i', 'wenjianjibot_', $code);
    }

    /** @param array<string, mixed> $payload */
    private function isAccountScopedFailure(array $payload): bool
    {
        $reason = (string) ($payload['reason'] ?? '');
        $phase = (string) ($payload['phase'] ?? '');

        return in_array($reason, ['client_not_connected', 'bot_not_found'], true)
            || ($reason === 'processing_failed' && $phase === 'send_code');
    }

    /** @return array<string, mixed>|null */
    private function getFromAvailableAccount(string $path, array $query): ?array
    {
        foreach ($this->baseUris as $baseUri) {
            if ($this->cooldownUntil($baseUri) > time()) {
                continue;
            }

            try {
                $response = Http::connectTimeout(8)->timeout(90)->get($baseUri . $path, $query);
            } catch (\Throwable) {
                $this->markCooldown($baseUri, 30);
                continue;
            }

            $payload = $response->json();
            if ($response->successful() && is_array($payload)) {
                return $payload;
            }

            if ($response->status() === 429 && is_array($payload)) {
                $this->markCooldown($baseUri, $this->floodWaitSeconds($response, $payload));
            }
        }

        return null;
    }

    private function isFloodWait(array $payload): bool
    {
        return (string) ($payload['status'] ?? '') === 'flood_wait'
            || (string) ($payload['reason'] ?? '') === 'flood_wait';
    }

    private function floodWaitSeconds(Response $response, array $payload): int
    {
        return max(1, (int) ($payload['wait_seconds'] ?? $response->header('Retry-After') ?? 60));
    }

    private function cooldownKey(string $baseUri): string
    {
        return 'telegram_resource_codes:account_cooldown:' . sha1($baseUri);
    }

    private function cooldownUntil(string $baseUri): int
    {
        return max(0, (int) Cache::store('telegram_resource_codes')->get($this->cooldownKey($baseUri), 0));
    }

    private function markCooldown(string $baseUri, int $seconds): int
    {
        $seconds = max(1, $seconds);
        $until = time() + $seconds;
        Cache::store('telegram_resource_codes')->put($this->cooldownKey($baseUri), $until, now()->addSeconds($seconds + 60));
        return $until;
    }

    private function recoverStaleRows(): void
    {
        DB::table('telegram_resource_codes')
            ->where('status', TelegramResourceCode::STATUS_PROCESSING)
            ->where('code_type', $this->codeType)
            ->where('processing_started_at', '<=', now()->subMinutes(self::STALE_PROCESSING_MINUTES))
            ->update([
                'status' => TelegramResourceCode::STATUS_PENDING,
                'processing_started_at' => null,
                'available_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function acquireSingletonLock(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        $row = DB::selectOne('SELECT GET_LOCK(?, 0) AS acquired', [self::LOCK_NAME]);
        $this->mysqlLockAcquired = (int) ($row->acquired ?? 0) === 1;
        return $this->mysqlLockAcquired;
    }

    private function releaseSingletonLock(): void
    {
        if (!$this->mysqlLockAcquired || DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::LOCK_NAME]);
        $this->mysqlLockAcquired = false;
    }
}
