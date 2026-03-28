<?php

namespace App\Services;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TelegramFilestoreTokenBridgeService
{
    private const FILES_TIMEOUT_SECONDS = 60;
    private const FORWARD_TIMEOUT_SECONDS = 120;
    private const DELETE_TIMEOUT_SECONDS = 60;
    private const WAIT_TIMEOUT_SECONDS = 45;
    private const WAIT_INTERVAL_MICROSECONDS = 500000;

    /**
     * @return array<string, mixed>
     */
    public function sync(
        string $sourceToken,
        string $baseUri,
        string $sourceBotUsername,
        int $minMessageId = 0,
        bool $deleteSourceMessages = false
    ): array
    {
        $token = trim($sourceToken);
        $normalizedBaseUri = rtrim(trim($baseUri), '/');
        $normalizedSourceBotUsername = ltrim(trim($sourceBotUsername), '@');
        $normalizedMinMessageId = max($minMessageId, 0);

        if ($token === '' || $normalizedBaseUri === '' || $normalizedSourceBotUsername === '') {
            return [
                'ok' => false,
                'status' => 'invalid_input',
                'summary' => 'filestore sync skipped: token/base_uri/bot missing',
            ];
        }

        $existing = TelegramFilestoreSession::query()
            ->where('source_token', $token)
            ->where('status', 'closed')
            ->first();

        if ($existing) {
            return [
                'ok' => true,
                'status' => 'existing',
                'session_id' => (int) $existing->id,
                'public_token' => (string) ($existing->public_token ?? ''),
                'stored_files' => (int) ($existing->total_files ?? 0),
                'skipped_files' => 0,
                'summary' => sprintf(
                    'filestore existing session_id=%d public_token=%s files=%d',
                    (int) $existing->id,
                    (string) ($existing->public_token ?? '-'),
                    (int) ($existing->total_files ?? 0)
                ),
            ];
        }

        $filesResponse = $this->fetchBotFiles(
            $normalizedBaseUri,
            $normalizedSourceBotUsername,
            $normalizedMinMessageId
        );
        if (($filesResponse['ok'] ?? false) !== true) {
            return $filesResponse;
        }

        $files = $this->extractFiles((array) ($filesResponse['json'] ?? []));
        $observedFiles = (int) ($filesResponse['observed_files'] ?? count($files));
        $observedTotalBytes = (int) ($filesResponse['observed_total_bytes'] ?? 0);

        if ($files === []) {
            return [
                'ok' => true,
                'status' => 'no_files',
                'observed_files' => 0,
                'observed_total_bytes' => 0,
                'summary' => sprintf(
                    'filestore sync skipped: no files observed for @%s after message_id=%d',
                    $normalizedSourceBotUsername,
                    $normalizedMinMessageId
                ),
            ];
        }

        $sourceChatId = $this->resolveSourceChatId($files);
        if ($sourceChatId <= 0) {
            return [
                'ok' => false,
                'status' => 'invalid_source_chat',
                'observed_files' => $observedFiles,
                'observed_total_bytes' => $observedTotalBytes,
                'summary' => 'filestore sync skipped: source chat id missing',
            ];
        }

        $sourceMessageIds = $this->collectSourceMessageIds(
            $files,
            $sourceChatId,
            $normalizedMinMessageId
        );

        [$forwardableMessageIds, $skippedByPrecheck] = $this->splitForwardableMessageIds($files, $sourceChatId);
        if ($forwardableMessageIds === []) {
            return [
                'ok' => true,
                'status' => 'no_forwardable_files',
                'observed_files' => $observedFiles,
                'observed_total_bytes' => $observedTotalBytes,
                'summary' => $this->buildSkipSummary('filestore sync skipped: no forwardable files', $skippedByPrecheck),
                'skipped_files' => count($skippedByPrecheck),
            ];
        }

        $session = $this->getOrCreateUploadingSession($token);
        if (!$session) {
            return [
                'ok' => false,
                'status' => 'bridge_busy',
                'observed_files' => $observedFiles,
                'observed_total_bytes' => $observedTotalBytes,
                'summary' => 'filestore sync skipped: bridge uploader chat already has another uploading session',
            ];
        }

        $beforeCount = (int) TelegramFilestoreFile::query()
            ->where('session_id', $session->id)
            ->count();

        $forwardResult = $this->forwardMessages($normalizedBaseUri, $sourceChatId, $forwardableMessageIds);
        if (($forwardResult['ok'] ?? false) !== true) {
            $this->cleanupEmptyUploadingSession((int) $session->id);

            return [
                'ok' => false,
                'status' => (string) ($forwardResult['status'] ?? 'forward_failed'),
                'observed_files' => $observedFiles,
                'observed_total_bytes' => $observedTotalBytes,
                'summary' => (string) ($forwardResult['summary'] ?? 'filestore sync skipped: forward api failed'),
            ];
        }

        $forwardedMessageIds = array_values(array_unique(array_map(
            'intval',
            (array) ($forwardResult['forwarded_message_ids'] ?? [])
        )));
        $runtimeSkipped = array_values(array_unique(array_map(
            'intval',
            array_merge(
                $skippedByPrecheck,
                array_map('intval', (array) ($forwardResult['missing_message_ids'] ?? [])),
                array_map('intval', (array) ($forwardResult['unforwardable_message_ids'] ?? []))
            )
        )));

        if ($forwardedMessageIds === []) {
            $this->cleanupEmptyUploadingSession((int) $session->id);

            return [
                'ok' => true,
                'status' => 'no_forwardable_files',
                'observed_files' => $observedFiles,
                'observed_total_bytes' => $observedTotalBytes,
                'summary' => $this->buildSkipSummary('filestore sync skipped: no messages were forwarded', $runtimeSkipped),
                'skipped_files' => count($runtimeSkipped),
            ];
        }

        $afterCount = $this->waitForFileCount((int) $session->id, $beforeCount + count($forwardedMessageIds));
        if ($afterCount <= $beforeCount) {
            return [
                'ok' => false,
                'status' => 'wait_timeout',
                'observed_files' => $observedFiles,
                'observed_total_bytes' => $observedTotalBytes,
                'summary' => 'filestore sync skipped: webhook write did not finish before timeout',
            ];
        }

        $closed = $this->closeUploadingSession((int) $session->id, $token);
        $deleteSummary = $this->deleteForwardedMessages(
            $normalizedBaseUri,
            (string) config('telegram.filestore_sync_bot_username', 'filestoebot'),
            $forwardedMessageIds
        );
        $deleteSourceSummary = [
            'enabled' => $deleteSourceMessages,
            'ok' => true,
            'summary' => 'source delete disabled',
        ];

        if ($deleteSourceMessages) {
            $deleteSourceSummary = $this->deleteForwardedMessages(
                $normalizedBaseUri,
                $normalizedSourceBotUsername,
                $sourceMessageIds
            );
            $deleteSourceSummary['enabled'] = true;
        }

        $storedFiles = (int) ($closed->total_files ?? 0);
        $summary = sprintf(
            'filestore synced session_id=%d public_token=%s stored=%d skipped=%d deleted_forwarded=%s',
            (int) $closed->id,
            (string) ($closed->public_token ?? '-'),
            $storedFiles,
            count($runtimeSkipped),
            ($deleteSummary['ok'] ?? false) === true ? 'yes' : 'no'
        );

        if (!empty($runtimeSkipped)) {
            $summary .= ' skipped_message_ids=' . implode(',', array_slice($runtimeSkipped, 0, 20));
        }

        if (($deleteSummary['ok'] ?? false) !== true && !empty($deleteSummary['summary'])) {
            $summary .= ' delete_note=' . (string) $deleteSummary['summary'];
        }

        if ($deleteSourceMessages) {
            $summary .= ' deleted_source=' . ((($deleteSourceSummary['ok'] ?? false) === true) ? 'yes' : 'no');

            if (($deleteSourceSummary['ok'] ?? false) !== true && !empty($deleteSourceSummary['summary'])) {
                $summary .= ' delete_source_note=' . (string) $deleteSourceSummary['summary'];
            }
        }

        return [
            'ok' => true,
            'status' => 'synced',
            'session_id' => (int) $closed->id,
            'public_token' => (string) ($closed->public_token ?? ''),
            'observed_files' => $observedFiles,
            'observed_total_bytes' => $observedTotalBytes,
            'stored_files' => $storedFiles,
            'skipped_files' => count($runtimeSkipped),
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $responseJson
     * @return array<int, array<string, mixed>>
     */
    private function extractFiles(array $responseJson): array
    {
        $files = $responseJson['files'] ?? null;
        if (!is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, static fn ($file): bool => is_array($file)));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchBotFiles(string $baseUri, string $sourceBotUsername, int $minMessageId): array
    {
        try {
            $response = Http::timeout(self::FILES_TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($baseUri, '/') . '/bots/files', [
                    'bot_username' => $sourceBotUsername,
                    'min_message_id' => max($minMessageId, 0),
                    'max_return_files' => 1000,
                    'max_raw_payload_bytes' => 0,
                    'force_backfill' => true,
                ]);

            if (!$response->ok()) {
                return [
                    'ok' => false,
                    'status' => 'files_http_error',
                    'summary' => 'filestore sync skipped: files api HTTP ' . $response->status(),
                ];
            }

            $json = $response->json();
            if (!is_array($json)) {
                return [
                    'ok' => false,
                    'status' => 'files_invalid_json',
                    'summary' => 'filestore sync skipped: files api returned invalid json',
                ];
            }

            if ((string) ($json['status'] ?? '') !== 'ok') {
                return [
                    'ok' => false,
                    'status' => (string) ($json['reason'] ?? 'files_failed'),
                    'summary' => 'filestore sync skipped: ' . (string) ($json['reason'] ?? 'files fetch failed'),
                ];
            }

            return [
                'ok' => true,
                'status' => 'ok',
                'json' => $json,
                'observed_files' => (int) ($json['files_unique_count'] ?? $json['files_count'] ?? 0),
                'observed_total_bytes' => (int) ($json['files_total_bytes'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 'files_exception',
                'summary' => 'filestore sync skipped: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $files
     */
    private function resolveSourceChatId(array $files): int
    {
        foreach ($files as $file) {
            $chatId = (int) ($file['chat_id'] ?? 0);
            if ($chatId > 0) {
                return $chatId;
            }
        }

        return 0;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array<int, int>
     */
    private function collectSourceMessageIds(array $files, int $sourceChatId, int $sentMessageId = 0): array
    {
        $messageIds = [];
        $seen = [];

        if ($sentMessageId > 0) {
            $seen[$sentMessageId] = true;
            $messageIds[] = $sentMessageId;
        }

        foreach ($files as $file) {
            $chatId = (int) ($file['chat_id'] ?? 0);
            $messageId = (int) ($file['message_id'] ?? 0);

            if ($chatId !== $sourceChatId || $messageId <= 0 || isset($seen[$messageId])) {
                continue;
            }

            $seen[$messageId] = true;
            $messageIds[] = $messageId;
        }

        return $messageIds;
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function splitForwardableMessageIds(array $files, int $sourceChatId): array
    {
        $forwardable = [];
        $skipped = [];
        $seen = [];

        foreach ($files as $file) {
            $chatId = (int) ($file['chat_id'] ?? 0);
            $messageId = (int) ($file['message_id'] ?? 0);
            $rawPayload = $file['raw_payload'] ?? null;
            $noForwards = is_array($rawPayload) ? (bool) ($rawPayload['noforwards'] ?? false) : false;

            if ($chatId !== $sourceChatId || $messageId <= 0 || $noForwards) {
                if ($messageId > 0) {
                    $skipped[] = $messageId;
                }
                continue;
            }

            if (isset($seen[$messageId])) {
                continue;
            }

            $seen[$messageId] = true;
            $forwardable[] = $messageId;
        }

        return [$forwardable, array_values(array_unique($skipped))];
    }

    private function getOrCreateUploadingSession(string $sourceToken): ?TelegramFilestoreSession
    {
        $syncChatId = $this->syncChatId();

        $existing = TelegramFilestoreSession::query()
            ->where('source_token', $sourceToken)
            ->where('status', 'uploading')
            ->orderByDesc('id')
            ->first();

        if ($existing) {
            return $existing;
        }

        $conflict = TelegramFilestoreSession::query()
            ->where('chat_id', $syncChatId)
            ->where('status', 'uploading')
            ->exists();

        if ($conflict) {
            return null;
        }

        return TelegramFilestoreSession::query()->create([
            'chat_id' => $syncChatId,
            'username' => null,
            'encrypt_token' => null,
            'public_token' => null,
            'source_token' => $sourceToken,
            'status' => 'uploading',
            'total_files' => 0,
            'total_size' => 0,
            'share_count' => 0,
            'last_shared_at' => null,
            'close_upload_prompted_at' => null,
            'is_sending' => 0,
            'sending_started_at' => null,
            'sending_finished_at' => null,
            'created_at' => now(),
            'closed_at' => null,
        ]);
    }

    private function cleanupEmptyUploadingSession(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }

        $count = (int) TelegramFilestoreFile::query()
            ->where('session_id', $sessionId)
            ->count();

        if ($count > 0) {
            return;
        }

        TelegramFilestoreSession::query()
            ->where('id', $sessionId)
            ->where('status', 'uploading')
            ->delete();
    }

    /**
     * @param array<int, int> $messageIds
     * @return array<string, mixed>
     */
    private function forwardMessages(string $baseUri, int $sourceChatId, array $messageIds): array
    {
        try {
            $response = Http::timeout(self::FORWARD_TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($baseUri, '/') . '/bots/forward-messages', [
                    'source_chat_id' => $sourceChatId,
                    'message_ids' => array_values($messageIds),
                    'target_bot_username' => ltrim((string) config('telegram.filestore_sync_bot_username', 'filestoebot'), '@'),
                ]);

            if (!$response->ok()) {
                return [
                    'ok' => false,
                    'status' => 'forward_http_error',
                    'summary' => 'filestore sync skipped: forward api HTTP ' . $response->status(),
                ];
            }

            $json = $response->json();
            if (!is_array($json)) {
                return [
                    'ok' => false,
                    'status' => 'forward_invalid_json',
                    'summary' => 'filestore sync skipped: forward api returned invalid json',
                ];
            }

            if ((string) ($json['status'] ?? '') !== 'ok') {
                return [
                    'ok' => false,
                    'status' => (string) ($json['reason'] ?? 'forward_failed'),
                    'summary' => 'filestore sync skipped: ' . (string) ($json['reason'] ?? 'forward failed'),
                ];
            }

            $json['ok'] = true;

            return $json;
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'status' => 'forward_exception',
                'summary' => 'filestore sync skipped: ' . $e->getMessage(),
            ];
        }
    }

    private function waitForFileCount(int $sessionId, int $expectedCount): int
    {
        $deadline = microtime(true) + self::WAIT_TIMEOUT_SECONDS;
        $latestCount = 0;

        while (microtime(true) < $deadline) {
            $latestCount = (int) TelegramFilestoreFile::query()
                ->where('session_id', $sessionId)
                ->count();

            if ($latestCount >= $expectedCount) {
                return $latestCount;
            }

            usleep(self::WAIT_INTERVAL_MICROSECONDS);
        }

        return (int) TelegramFilestoreFile::query()
            ->where('session_id', $sessionId)
            ->count();
    }

    private function closeUploadingSession(int $sessionId, string $sourceToken): TelegramFilestoreSession
    {
        return DB::transaction(function () use ($sessionId, $sourceToken): TelegramFilestoreSession {
            $session = TelegramFilestoreSession::query()
                ->where('id', $sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            $files = TelegramFilestoreFile::query()
                ->where('session_id', $sessionId)
                ->get(['file_type', 'file_size']);

            $videoCount = 0;
            $photoCount = 0;
            $documentCount = 0;
            $totalSize = 0;

            foreach ($files as $file) {
                $type = (string) ($file->file_type ?? 'other');
                $size = (int) ($file->file_size ?? 0);
                $totalSize += $size;

                if ($type === 'video') {
                    $videoCount++;
                    continue;
                }

                if ($type === 'photo') {
                    $photoCount++;
                    continue;
                }

                $documentCount++;
            }

            if ((string) ($session->public_token ?? '') === '') {
                $session->public_token = $this->generateUniquePublicTokenWithCounts(
                    $videoCount,
                    $photoCount,
                    $documentCount
                );
            }

            $session->encrypt_token = $this->hashForDb((string) $session->public_token);
            $session->source_token = $sourceToken;
            $session->status = 'closed';
            $session->total_files = $files->count();
            $session->total_size = $totalSize;
            $session->closed_at = now();
            $session->close_upload_prompted_at = null;
            $session->save();

            TelegramFilestoreFile::query()
                ->where('session_id', $sessionId)
                ->whereNull('source_token')
                ->update(['source_token' => $sourceToken]);

            return $session->fresh();
        });
    }

    /**
     * @param array<int, int> $messageIds
     * @return array<string, mixed>
     */
    private function deleteForwardedMessages(string $baseUri, string $chatPeer, array $messageIds): array
    {
        if ($messageIds === []) {
            return [
                'ok' => true,
                'summary' => 'no forwarded messages to delete',
            ];
        }

        try {
            $response = Http::timeout(self::DELETE_TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($baseUri, '/') . '/bots/delete-messages', [
                    'chat_peer' => ltrim($chatPeer, '@'),
                    'message_ids' => array_values($messageIds),
                ]);

            if (!$response->ok()) {
                return [
                    'ok' => false,
                    'summary' => 'delete api HTTP ' . $response->status(),
                ];
            }

            $json = $response->json();
            if (!is_array($json) || (string) ($json['status'] ?? '') !== 'ok') {
                return [
                    'ok' => false,
                    'summary' => 'delete api returned invalid response',
                ];
            }

            return [
                'ok' => true,
                'summary' => 'deleted_count=' . (int) ($json['deleted_count'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'summary' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<int, int> $skippedMessageIds
     */
    private function buildSkipSummary(string $prefix, array $skippedMessageIds): string
    {
        if ($skippedMessageIds === []) {
            return $prefix;
        }

        return $prefix . ' skipped_message_ids=' . implode(',', array_slice($skippedMessageIds, 0, 20));
    }

    private function syncChatId(): int
    {
        return max(1, (int) config('telegram.filestore_sync_chat_id', 7702694790));
    }

    private function generateUniquePublicTokenWithCounts(int $videoCount, int $photoCount, int $documentCount): string
    {
        $segments = [];

        if ($videoCount > 0) {
            $segments[] = $videoCount . 'V';
        }

        if ($photoCount > 0) {
            $segments[] = $photoCount . 'P';
        }

        if ($documentCount > 0) {
            $segments[] = $documentCount . 'D';
        }

        if ($segments === []) {
            $segments[] = '0D';
        }

        do {
            $candidate = 'filestoebot_' . implode('_', $segments) . '_' . Str::lower(Str::random(18));
            $exists = TelegramFilestoreSession::query()
                ->where('public_token', $candidate)
                ->exists();
        } while ($exists);

        return $candidate;
    }

    private function hashForDb(string $publicToken): string
    {
        return hash('sha256', $publicToken);
    }
}
