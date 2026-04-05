<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class EnsureBackupRestoreWebhookCommand extends Command
{
    protected $signature = 'telegram:ensure-backup-restore-webhook {--force : 強制重新 setWebhook，即使目前 URL 已一致}';

    protected $description = 'Ensure @new_files_star_bot webhook points at the dedicated Cloudflare hostname.';

    public function handle(): int
    {
        $token = trim((string) config('telegram.backup_restore_bot_token'));
        $username = ltrim(trim((string) config('telegram.backup_restore_bot_username', 'new_files_star_bot')), '@');
        $webhookUrl = trim((string) config('telegram.backup_restore_webhook_url'));
        $force = (bool) $this->option('force');

        if ($token === '') {
            $this->error('telegram.backup_restore_bot_token 未設定。');
            return self::FAILURE;
        }

        if ($webhookUrl === '') {
            $this->error('telegram.backup_restore_webhook_url 未設定。');
            return self::FAILURE;
        }

        $apiBase = "https://api.telegram.org/bot{$token}/";
        $webhookInfo = Http::timeout(30)->get($apiBase . 'getWebhookInfo');

        if (!$webhookInfo->successful() || (($webhookInfo->json('ok') ?? false) !== true)) {
            $this->error('getWebhookInfo 失敗：' . $webhookInfo->body());
            return self::FAILURE;
        }

        $currentUrl = trim((string) $webhookInfo->json('result.url', ''));

        if (!$force && $currentUrl === $webhookUrl) {
            $this->info("@{$username} webhook 已正確指向 {$webhookUrl}");
            return self::SUCCESS;
        }

        $setWebhook = Http::timeout(30)->asForm()->post($apiBase . 'setWebhook', [
            'url' => $webhookUrl,
        ]);

        if (!$setWebhook->successful() || (($setWebhook->json('ok') ?? false) !== true)) {
            $this->error('setWebhook 失敗：' . $setWebhook->body());
            return self::FAILURE;
        }

        $this->info("@{$username} webhook 已更新為 {$webhookUrl}");

        return self::SUCCESS;
    }
}
