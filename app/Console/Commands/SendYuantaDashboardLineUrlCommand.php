<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SendYuantaDashboardLineUrlCommand extends Command
{
    protected $signature = 'line:send-yuanta-dashboard-url
        {--target= : LINE group or room id}
        {--message= : Custom message text}
        {--dry-run : Show the resolved target and URL without sending}';

    protected $description = 'Send the Yuanta portfolio dashboard URL through the Yuanta LINE bot.';

    public function handle(): int
    {
        $target = $this->targetId();
        $url = $this->dashboardUrl();
        $message = (string) ($this->option('message') ?: "元大庫存即時看板\n{$url}");

        if ($this->option('dry-run')) {
            $this->line('target=' . $this->mask($target));
            $this->line('url=' . $url);
            $this->line('message=' . $message);

            return self::SUCCESS;
        }

        $response = Http::withToken($this->lineAccessToken())
            ->withHeaders(['X-Line-Retry-Key' => (string) Str::uuid()])
            ->post('https://api.line.me/v2/bot/message/push', [
                'to' => $target,
                'messages' => [
                    [
                        'type' => 'text',
                        'text' => $message,
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(sprintf(
                'LINE push failed: HTTP %s %s',
                $response->status(),
                $response->body(),
            ));
        }

        $this->info('Sent Yuanta dashboard URL via LINE. request_id=' . (string) $response->header('x-line-request-id'));

        return self::SUCCESS;
    }

    private function dashboardUrl(): string
    {
        $token = (string) config('yuanta.dashboard_token', '');
        if ($token === '') {
            throw new RuntimeException('YUANTA_PORTFOLIO_DASHBOARD_TOKEN is not configured.');
        }

        return rtrim((string) config('app.url'), '/') . '/tw-stock/yuanta-portfolio?token=' . $token;
    }

    private function targetId(): string
    {
        $target = (string) ($this->option('target') ?: config('line.yuanta_dashboard_notify_target_id', ''));
        if ($target !== '') {
            return $target;
        }

        if (Storage::disk('local')->exists('line/yuanta-dashboard-notify-target-id.txt')) {
            $target = trim(Storage::disk('local')->get('line/yuanta-dashboard-notify-target-id.txt'));
        }

        if ($target === '') {
            throw new RuntimeException('Yuanta LINE target id is missing. Add the bot to the group and send a message after configuring /api/line/yuanta/webhook.');
        }

        return $target;
    }

    private function lineAccessToken(): string
    {
        $configuredToken = (string) config('line.yuanta_channel_access_token', '');
        if ($configuredToken !== '') {
            return $configuredToken;
        }

        $channelId = (string) config('line.yuanta_channel_id', '');
        $channelSecret = (string) config('line.yuanta_channel_secret', '');
        if ($channelId === '' || $channelSecret === '') {
            throw new RuntimeException('YUANTA_LINE_CHANNEL_ACCESS_TOKEN or YUANTA_LINE_CHANNEL_ID/YUANTA_LINE_CHANNEL_SECRET is required.');
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

        return (string) $response->json('access_token');
    }

    private function mask(string $value): string
    {
        if (strlen($value) <= 10) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 6) . '...' . substr($value, -4);
    }
}
