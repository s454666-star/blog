<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class TelegramNotificationService
{
    private const MAX_MESSAGE_LENGTH = 4096;

    public function isEnabled(): bool
    {
        return filter_var(config('telegram.line_mirror.enabled', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * @return list<int>
     */
    public function sendText(string $route, string $message): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        $configuration = config('telegram.line_mirror.routes.' . $route);
        if (! is_array($configuration)) {
            throw new RuntimeException('Telegram notification route is not configured: ' . $route . '.');
        }

        $botToken = trim((string) ($configuration['bot_token'] ?? ''));
        $chatId = trim((string) ($configuration['chat_id'] ?? ''));
        if ($botToken === '' || $chatId === '') {
            throw new RuntimeException('Telegram bot token or chat id is missing for route: ' . $route . '.');
        }

        $this->validateChatId($route, $chatId);
        $messageIds = [];

        foreach ($this->splitMessage($message) as $part) {
            $response = Http::asJson()
                ->timeout(15)
                ->post('https://api.telegram.org/bot' . $botToken . '/sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $part,
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful() || ! $response->json('ok', false)) {
                throw new RuntimeException(sprintf(
                    'Telegram notification failed for route %s: HTTP %s.',
                    $route,
                    $response->status(),
                ));
            }

            $messageIds[] = (int) $response->json('result.message_id', 0);
        }

        return $messageIds;
    }

    private function validateChatId(string $route, string $chatId): void
    {
        if (preg_match('/^-?\d+$/', $chatId) !== 1) {
            throw new RuntimeException('Telegram chat id must be numeric for route: ' . $route . '.');
        }

        if ($route === 'personal' && str_starts_with($chatId, '-')) {
            throw new RuntimeException('Telegram personal route refuses group and channel chat ids.');
        }

        if ($route !== 'personal' && ! str_starts_with($chatId, '-')) {
            throw new RuntimeException('Telegram group route requires a negative chat id: ' . $route . '.');
        }
    }

    /**
     * @return list<string>
     */
    private function splitMessage(string $message): array
    {
        if (mb_strlen($message) <= self::MAX_MESSAGE_LENGTH) {
            return [$message];
        }

        $parts = [];
        while ($message !== '') {
            $parts[] = mb_substr($message, 0, self::MAX_MESSAGE_LENGTH);
            $message = mb_substr($message, self::MAX_MESSAGE_LENGTH);
        }

        return $parts;
    }
}
