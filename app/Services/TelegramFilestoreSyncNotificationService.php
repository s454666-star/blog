<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramFilestoreSyncNotificationService
{
    private const GROUP_PEER_ID = 3772011392;
    private const DEFAULT_TARGET_BOT = 'filestoebot';

    /**
     * @return array{ok:bool,summary:string}
     */
    public function notifyTokenSynced(string $token, string $baseUri = '', ?string $targetBotUsername = null): array
    {
        $normalizedToken = trim($token);
        if ($normalizedToken === '') {
            return [
                'ok' => false,
                'summary' => 'group_notice=no(reason=empty_token)',
            ];
        }

        $targetBot = trim((string) ($targetBotUsername ?: config('telegram.filestore_sync_bot_username', self::DEFAULT_TARGET_BOT)));
        if ($targetBot === '') {
            $targetBot = self::DEFAULT_TARGET_BOT;
        }

        $messageText = sprintf('%s  已收錄至機器人 @%s', $normalizedToken, ltrim($targetBot, '@'));

        $filestoreBotToken = trim((string) config('telegram.filestore_bot_token'));
        if ($filestoreBotToken !== '') {
            $filestoreBotResult = $this->notifyViaBotApi(
                $normalizedToken,
                $messageText,
                $filestoreBotToken,
                'filestore_bot_api',
                $this->botApiChatIdCandidates()
            );

            if (($filestoreBotResult['ok'] ?? false) === true) {
                return $filestoreBotResult;
            }
        }

        $fastApiSummary = $this->notifyViaFastApi($normalizedToken, trim($baseUri), $messageText);
        if (($fastApiSummary['ok'] ?? false) === true) {
            return $fastApiSummary;
        }

        $botToken = trim((string) config('services.telegram.mystar_secret'));
        if ($botToken === '') {
            Log::warning('telegram_filestore_sync_notice_missing_bot_token', [
                'token' => $normalizedToken,
                'base_uri' => trim($baseUri),
                'fastapi_summary' => $fastApiSummary['summary'] ?? null,
            ]);

            return $fastApiSummary;
        }

        return $this->notifyViaBotApi(
            $normalizedToken,
            $messageText,
            $botToken,
            'mystar_bot_api',
            $this->botApiChatIdCandidates()
        );
    }

    /**
     * @return array{ok:bool,summary:string}
     */
    private function notifyViaFastApi(string $token, string $baseUri, string $messageText): array
    {
        $normalizedBaseUri = rtrim($baseUri, '/');
        if ($normalizedBaseUri === '') {
            return [
                'ok' => false,
                'summary' => 'group_notice=no(reason=missing_base_uri)',
            ];
        }

        try {
            $response = Http::timeout(15)->post($normalizedBaseUri . '/groups/send-message', [
                'peer_id' => self::GROUP_PEER_ID,
                'text' => $messageText,
            ]);

            $status = trim((string) ($response->json('status') ?? ''));
            if (!$response->successful() || ($status !== '' && $status !== 'ok')) {
                Log::warning('telegram_filestore_sync_notice_fastapi_failed', [
                    'token' => $token,
                    'base_uri' => $normalizedBaseUri,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [
                    'ok' => false,
                    'summary' => 'group_notice=no(reason=fastapi_send_failed)',
                ];
            }

            return [
                'ok' => true,
                'summary' => 'group_notice=yes(chat_id=' . self::GROUP_PEER_ID . ',via=fastapi)',
            ];
        } catch (\Throwable $e) {
            Log::warning('telegram_filestore_sync_notice_fastapi_exception', [
                'token' => $token,
                'base_uri' => $normalizedBaseUri,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'summary' => 'group_notice=no(reason=fastapi_exception)',
            ];
        }
    }

    /**
     * @param array<int, string> $chatIdCandidates
     * @return array{ok:bool,summary:string}
     */
    private function notifyViaBotApi(
        string $token,
        string $messageText,
        string $botToken,
        string $channelLabel,
        array $chatIdCandidates
    ): array {
        $lastFailureSummary = 'group_notice=no(reason=bot_api_send_failed)';

        foreach ($chatIdCandidates as $chatId) {
            try {
                $response = Http::withoutVerifying()->timeout(15)->post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => $messageText,
                    'disable_web_page_preview' => true,
                ]);

                if ($response->successful() && (($response->json('ok')) !== false)) {
                    return [
                        'ok' => true,
                        'summary' => 'group_notice=yes(chat_id=' . $chatId . ',via=' . $channelLabel . ')',
                    ];
                }

                Log::warning('telegram_filestore_sync_notice_bot_api_failed', [
                    'token' => $token,
                    'channel' => $channelLabel,
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                $lastFailureSummary = 'group_notice=no(reason=bot_api_send_failed)';
            } catch (\Throwable $e) {
                Log::warning('telegram_filestore_sync_notice_bot_api_exception', [
                    'token' => $token,
                    'channel' => $channelLabel,
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
                $lastFailureSummary = 'group_notice=no(reason=bot_api_exception)';
            }
        }

        return [
            'ok' => false,
            'summary' => $lastFailureSummary,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function botApiChatIdCandidates(): array
    {
        return [
            '-1003772011392',
            '-100' . (string) self::GROUP_PEER_ID,
            '-' . (string) self::GROUP_PEER_ID,
        ];
    }
}
