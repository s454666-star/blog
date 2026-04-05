<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnsureBackupRestoreWebhookCommandTest extends TestCase
{
    public function test_command_sets_backup_restore_webhook_when_url_differs(): void
    {
        config()->set('telegram.backup_restore_bot_token', 'backup-restore-test-token');
        config()->set('telegram.backup_restore_bot_username', 'new_files_star_bot');
        config()->set('telegram.backup_restore_webhook_url', 'https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star');

        Http::fake([
            'https://api.telegram.org/botbackup-restore-test-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://blog.mystar.monster/api/telegram/filestore/webhook/new-files-star',
                ],
            ], 200),
            'https://api.telegram.org/botbackup-restore-test-token/setWebhook' => Http::response([
                'ok' => true,
                'result' => true,
            ], 200),
        ]);

        $this->artisan('telegram:ensure-backup-restore-webhook')
            ->expectsOutput('@new_files_star_bot webhook 已更新為 https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star')
            ->assertExitCode(0);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/botbackup-restore-test-token/setWebhook'
                && (string) ($request['url'] ?? '') === 'https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star';
        });
    }

    public function test_command_skips_set_webhook_when_url_already_matches(): void
    {
        config()->set('telegram.backup_restore_bot_token', 'backup-restore-test-token');
        config()->set('telegram.backup_restore_bot_username', 'new_files_star_bot');
        config()->set('telegram.backup_restore_webhook_url', 'https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star');

        Http::fake([
            'https://api.telegram.org/botbackup-restore-test-token/getWebhookInfo' => Http::response([
                'ok' => true,
                'result' => [
                    'url' => 'https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star',
                ],
            ], 200),
        ]);

        $this->artisan('telegram:ensure-backup-restore-webhook')
            ->expectsOutput('@new_files_star_bot webhook 已正確指向 https://new-files-star.mystar.monster/api/telegram/filestore/webhook/new-files-star')
            ->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.telegram.org/botbackup-restore-test-token/getWebhookInfo';
        });
    }
}
