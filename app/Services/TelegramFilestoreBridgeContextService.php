<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TelegramFilestoreBridgeContextService
{
    private const TTL_SECONDS = 1800;
    private const TABLE_NAME = 'telegram_filestore_bridge_contexts';
    private const TYPE_FILE_UNIQUE_ID = 'file_unique_id';
    private const TYPE_MESSAGE_ID = 'message_id';
    private const TYPE_CHAT_ID = 'chat_id';
    private const FILE_KEY_PREFIX = 'telegram_filestore_bridge_file:';
    private const MESSAGE_KEY_PREFIX = 'telegram_filestore_bridge_message:';
    private const SESSION_KEY_PREFIX = 'telegram_filestore_bridge_session:';

    private ?bool $bridgeContextTableExists = null;

    /**
     * @param  array<int, string>  $fileUniqueIds
     */
    public function rememberPendingSession(int $sessionId, array $fileUniqueIds): void
    {
        if ($sessionId <= 0) {
            return;
        }

        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $fileUniqueIds
        ), static fn (string $value): bool => $value !== '')));

        if ($normalizedIds === []) {
            return;
        }

        foreach ($normalizedIds as $fileUniqueId) {
            Cache::put(
                $this->fileKey($fileUniqueId),
                $sessionId,
                now()->addSeconds(self::TTL_SECONDS)
            );
        }

        $this->upsertBridgeContexts(
            $sessionId,
            self::TYPE_FILE_UNIQUE_ID,
            $normalizedIds
        );

        $existingState = $this->loadSessionState($sessionId);
        $mergedFileUniqueIds = array_values(array_unique(array_merge(
            $existingState['file_unique_ids'],
            $normalizedIds
        )));

        Cache::put(
            $this->sessionKey($sessionId),
            [
                'file_unique_ids' => $mergedFileUniqueIds,
                'message_ids' => $existingState['message_ids'],
            ],
            now()->addSeconds(self::TTL_SECONDS)
        );
    }

    /**
     * @param  array<int, int>  $messageIds
     */
    public function rememberPendingForwardedMessageIds(int $sessionId, array $messageIds): void
    {
        if ($sessionId <= 0) {
            return;
        }

        $normalizedIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => (int) $value,
            $messageIds
        ), static fn (int $value): bool => $value > 0)));

        if ($normalizedIds === []) {
            return;
        }

        foreach ($normalizedIds as $messageId) {
            Cache::put(
                $this->messageKey($messageId),
                $sessionId,
                now()->addSeconds(self::TTL_SECONDS)
            );
        }

        $this->upsertBridgeContexts(
            $sessionId,
            self::TYPE_MESSAGE_ID,
            array_map(static fn (int $messageId): string => (string) $messageId, $normalizedIds)
        );

        $existingState = $this->loadSessionState($sessionId);
        $mergedMessageIds = array_values(array_unique(array_merge(
            $existingState['message_ids'],
            $normalizedIds
        )));

        Cache::put(
            $this->sessionKey($sessionId),
            [
                'file_unique_ids' => $existingState['file_unique_ids'],
                'message_ids' => $mergedMessageIds,
            ],
            now()->addSeconds(self::TTL_SECONDS)
        );
    }

    public function rememberPendingChatId(int $sessionId, int $chatId): void
    {
        if ($sessionId <= 0 || $chatId <= 0) {
            return;
        }

        $this->upsertBridgeContexts(
            $sessionId,
            self::TYPE_CHAT_ID,
            [(string) $chatId]
        );
    }

    public function resolvePendingSessionId(string $fileUniqueId): int
    {
        $normalized = trim($fileUniqueId);
        if ($normalized === '') {
            return 0;
        }

        $databaseValue = $this->resolveFromDatabase(self::TYPE_FILE_UNIQUE_ID, $normalized);
        if ($databaseValue > 0) {
            return $databaseValue;
        }

        $value = Cache::get($this->fileKey($normalized));

        return max(0, (int) $value);
    }

    public function resolvePendingSessionIdForMessageId(int $messageId): int
    {
        if ($messageId <= 0) {
            return 0;
        }

        $databaseValue = $this->resolveFromDatabase(self::TYPE_MESSAGE_ID, (string) $messageId);
        if ($databaseValue > 0) {
            return $databaseValue;
        }

        $value = Cache::get($this->messageKey($messageId));

        return max(0, (int) $value);
    }

    public function resolvePendingSessionIdForChatId(int $chatId): int
    {
        if ($chatId <= 0) {
            return 0;
        }

        return $this->resolveFromDatabase(self::TYPE_CHAT_ID, (string) $chatId);
    }

    public function forgetPendingSession(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }

        $this->deleteSessionContextsFromDatabase($sessionId);

        $state = $this->loadSessionState($sessionId);

        foreach ($state['file_unique_ids'] as $fileUniqueId) {
            $normalized = trim((string) $fileUniqueId);
            if ($normalized === '') {
                continue;
            }

            Cache::forget($this->fileKey($normalized));
        }

        foreach ($state['message_ids'] as $messageId) {
            $normalized = (int) $messageId;
            if ($normalized <= 0) {
                continue;
            }

            Cache::forget($this->messageKey($normalized));
        }

        Cache::forget($this->sessionKey($sessionId));
    }

    /**
     * @return array{file_unique_ids: array<int, string>, message_ids: array<int, int>}
     */
    private function loadSessionState(int $sessionId): array
    {
        $value = Cache::get($this->sessionKey($sessionId));
        if (!is_array($value)) {
            return [
                'file_unique_ids' => [],
                'message_ids' => [],
            ];
        }

        // Backward compatibility for legacy cache payloads that stored only file_unique_ids.
        if (!array_key_exists('file_unique_ids', $value) && !array_key_exists('message_ids', $value)) {
            return [
                'file_unique_ids' => array_values(array_unique(array_filter(array_map(
                    static fn ($item): string => trim((string) $item),
                    $value
                ), static fn (string $value): bool => $value !== ''))),
                'message_ids' => [],
            ];
        }

        return [
            'file_unique_ids' => array_values(array_unique(array_filter(array_map(
                static fn ($item): string => trim((string) $item),
                (array) ($value['file_unique_ids'] ?? [])
            ), static fn (string $value): bool => $value !== ''))),
            'message_ids' => array_values(array_unique(array_filter(array_map(
                static fn ($item): int => (int) $item,
                (array) ($value['message_ids'] ?? [])
            ), static fn (int $value): bool => $value > 0))),
        ];
    }

    private function messageKey(int $messageId): string
    {
        return self::MESSAGE_KEY_PREFIX . $messageId;
    }

    private function upsertBridgeContexts(int $sessionId, string $contextType, array $contextValues): void
    {
        if ($sessionId <= 0 || !$this->hasBridgeContextTable()) {
            return;
        }

        $this->deleteExpiredContextsFromDatabase();

        $rows = [];
        $now = now();
        $expiresAt = $now->copy()->addSeconds(self::TTL_SECONDS);

        foreach ($contextValues as $contextValue) {
            $normalized = trim((string) $contextValue);
            if ($normalized === '') {
                continue;
            }

            $rows[] = [
                'session_id' => $sessionId,
                'context_type' => $contextType,
                'context_hash' => $this->hashContextValue($normalized),
                'context_value' => $normalized,
                'expires_at' => $expiresAt,
                'created_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::table(self::TABLE_NAME)->upsert(
            $rows,
            ['context_type', 'context_hash'],
            ['session_id', 'context_value', 'expires_at']
        );
    }

    private function resolveFromDatabase(string $contextType, string $contextValue): int
    {
        if (!$this->hasBridgeContextTable()) {
            return 0;
        }

        $this->deleteExpiredContextsFromDatabase();

        $value = DB::table(self::TABLE_NAME)
            ->where('context_type', $contextType)
            ->where('context_hash', $this->hashContextValue($contextValue))
            ->where('expires_at', '>', now())
            ->value('session_id');

        return max(0, (int) $value);
    }

    private function deleteSessionContextsFromDatabase(int $sessionId): void
    {
        if ($sessionId <= 0 || !$this->hasBridgeContextTable()) {
            return;
        }

        DB::table(self::TABLE_NAME)
            ->where('session_id', $sessionId)
            ->delete();
    }

    private function deleteExpiredContextsFromDatabase(): void
    {
        if (!$this->hasBridgeContextTable()) {
            return;
        }

        DB::table(self::TABLE_NAME)
            ->where('expires_at', '<=', now())
            ->delete();
    }

    private function hasBridgeContextTable(): bool
    {
        if ($this->bridgeContextTableExists !== null) {
            return $this->bridgeContextTableExists;
        }

        $this->bridgeContextTableExists = Schema::hasTable(self::TABLE_NAME);

        return $this->bridgeContextTableExists;
    }

    private function hashContextValue(string $value): string
    {
        return hash('sha256', trim($value));
    }

    private function fileKey(string $fileUniqueId): string
    {
        return self::FILE_KEY_PREFIX . hash('sha256', trim($fileUniqueId));
    }

    private function sessionKey(int $sessionId): string
    {
        return self::SESSION_KEY_PREFIX . $sessionId;
    }
}
