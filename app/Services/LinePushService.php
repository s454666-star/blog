<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LinePushService
{
    public function pushText(string $message, ?string $targetId = null): string
    {
        $response = Http::withToken($this->lineAccessToken())
            ->withHeaders(['X-Line-Retry-Key' => (string) Str::uuid()])
            ->post('https://api.line.me/v2/bot/message/push', [
                'to' => $this->targetId($targetId),
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $message,
                    ],
                ],
                'notificationDisabled' => false,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'LINE push failed: HTTP %s %s',
                $response->status(),
                $response->body(),
            ));
        }

        return (string) $response->header('x-line-request-id');
    }

    private function targetId(?string $targetId): string
    {
        $target = trim((string) ($targetId ?: config('line.taiex_futures_notify_target_id', '')));
        if ($target !== '') {
            return $target;
        }

        foreach ([
            'line/taiex-futures-notify-target-id.txt',
            'line/dashboard-notify-target-id.txt',
        ] as $path) {
            if (Storage::disk('local')->exists($path)) {
                $target = trim(Storage::disk('local')->get($path));
                if ($target !== '') {
                    return $target;
                }
            }
        }

        $fallback = trim((string) config('line.dashboard_notify_target_id', ''));
        if ($fallback !== '') {
            return $fallback;
        }

        throw new RuntimeException('LINE target id is missing. Configure LINE_TAIEX_FUTURES_NOTIFY_TARGET_ID or send a message to the LINE bot webhook target first.');
    }

    private function lineAccessToken(): string
    {
        $configuredToken = trim((string) config('line.channel_access_token', ''));
        if ($configuredToken !== '') {
            return $configuredToken;
        }

        $channelId = trim((string) config('line.channel_id', ''));
        $channelSecret = trim((string) config('line.channel_secret', ''));
        if ($channelId === '' || $channelSecret === '') {
            throw new RuntimeException('LINE_CHANNEL_ACCESS_TOKEN or LINE_CHANNEL_ID/LINE_CHANNEL_SECRET is required.');
        }

        $response = Http::asForm()->post('https://api.line.me/oauth2/v3/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $channelId,
            'client_secret' => $channelSecret,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'Failed to issue LINE access token: HTTP %s %s',
                $response->status(),
                $response->body(),
            ));
        }

        $token = trim((string) $response->json('access_token', ''));
        if ($token === '') {
            throw new RuntimeException('LINE token response did not include access_token.');
        }

        return $token;
    }
}
