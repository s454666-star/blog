<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class TelegramFilestoreBridgeContextService
{
    private const TTL_SECONDS = 1800;
    private const FILE_KEY_PREFIX = 'telegram_filestore_bridge_file:';
    private const SESSION_KEY_PREFIX = 'telegram_filestore_bridge_session:';

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

        Cache::put(
            $this->sessionKey($sessionId),
            $normalizedIds,
            now()->addSeconds(self::TTL_SECONDS)
        );
    }

    public function resolvePendingSessionId(string $fileUniqueId): int
    {
        $value = Cache::get($this->fileKey($fileUniqueId));

        return max(0, (int) $value);
    }

    public function forgetPendingSession(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }

        $fileUniqueIds = Cache::get($this->sessionKey($sessionId));
        if (is_array($fileUniqueIds)) {
            foreach ($fileUniqueIds as $fileUniqueId) {
                $normalized = trim((string) $fileUniqueId);
                if ($normalized === '') {
                    continue;
                }

                Cache::forget($this->fileKey($normalized));
            }
        }

        Cache::forget($this->sessionKey($sessionId));
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
