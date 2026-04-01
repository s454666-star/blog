<?php

namespace App\Console\Commands;

use App\Services\TelegramFilestoreStaleSessionCleanupService;
use Illuminate\Console\Command;

class CleanupStaleFilestoreSessionsCommand extends Command
{
    protected $signature = 'filestore:cleanup-stale-sessions
        {--hours=24 : 超過幾小時沒有活動就視為 stale}';

    protected $description = '清理 telegram_filestore_sessions 的 stale uploading 與 telegram_filestore_restore_sessions 的 stale pending/running。';

    public function __construct(
        private TelegramFilestoreStaleSessionCleanupService $staleSessionCleanupService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $hours = max((int) $this->option('hours'), 1);

        $uploading = $this->staleSessionCleanupService->cleanupStaleUploadingSessions($hours);
        $restore = $this->staleSessionCleanupService->cleanupStaleRestoreSessions($hours);

        $this->line('stale_hours=' . $hours);
        $this->line('uploading_deleted_sessions=' . (int) $uploading['deleted_sessions']);
        $this->line('uploading_deleted_files=' . (int) $uploading['deleted_files']);
        $this->line('restore_finalized_sessions=' . (int) $restore['finalized_sessions']);
        $this->line('restore_newly_failed_files=' . (int) $restore['newly_failed_files']);

        return self::SUCCESS;
    }
}
