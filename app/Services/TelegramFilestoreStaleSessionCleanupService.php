<?php

namespace App\Services;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreRestoreFile;
use App\Models\TelegramFilestoreRestoreSession;
use App\Models\TelegramFilestoreSession;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramFilestoreStaleSessionCleanupService
{
    public const DEFAULT_STALE_HOURS = 24;

    public function __construct(
        private TelegramFilestoreBridgeContextService $bridgeContextService
    ) {
    }

    /**
     * @return array{
     *     deleted_sessions:int,
     *     deleted_files:int,
     *     deleted_session_ids:array<int, int>,
     *     cutoff_at:Carbon
     * }
     */
    public function cleanupStaleUploadingSessions(
        int $hours = self::DEFAULT_STALE_HOURS,
        ?int $chatId = null,
        ?string $sourceToken = null
    ): array {
        $normalizedHours = max($hours, 1);
        $cutoffAt = now()->subHours($normalizedHours);
        $normalizedSourceToken = trim((string) $sourceToken);

        $query = TelegramFilestoreSession::query()
            ->where('status', 'uploading')
            ->when($chatId !== null && $chatId > 0, static function ($builder) use ($chatId): void {
                $builder->where('chat_id', $chatId);
            })
            ->when($normalizedSourceToken !== '', static function ($builder) use ($normalizedSourceToken): void {
                $builder->where('source_token', $normalizedSourceToken);
            })
            ->orderBy('id');

        $deletedSessionIds = [];
        $deletedFiles = 0;

        foreach ($query->get(['id']) as $row) {
            $deletedFileCount = null;
            $sessionId = (int) $row->id;

            DB::transaction(function () use ($sessionId, $cutoffAt, &$deletedFileCount): void {
                $session = TelegramFilestoreSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->first();

                if (!$session || (string) $session->status !== 'uploading') {
                    return;
                }

                $lastActivityAt = $this->resolveUploadingSessionLastActivityAt($session);
                if ($lastActivityAt === null || $lastActivityAt->gt($cutoffAt)) {
                    return;
                }

                $deletedFileCount = (int) TelegramFilestoreFile::query()
                    ->where('session_id', $sessionId)
                    ->count();

                TelegramFilestoreFile::query()
                    ->where('session_id', $sessionId)
                    ->delete();

                $session->delete();
            });

            if ($deletedFileCount === null) {
                continue;
            }

            $deletedSessionIds[] = $sessionId;
            $deletedFiles += $deletedFileCount;
        }

        foreach ($deletedSessionIds as $sessionId) {
            $this->bridgeContextService->forgetPendingSession($sessionId);
        }

        if ($deletedSessionIds !== []) {
            Log::warning('telegram_filestore_stale_uploading_sessions_deleted', [
                'cutoff_at' => $cutoffAt->toDateTimeString(),
                'deleted_sessions' => count($deletedSessionIds),
                'deleted_files' => $deletedFiles,
                'session_ids' => $deletedSessionIds,
            ]);
        }

        return [
            'deleted_sessions' => count($deletedSessionIds),
            'deleted_files' => $deletedFiles,
            'deleted_session_ids' => $deletedSessionIds,
            'cutoff_at' => $cutoffAt,
        ];
    }

    /**
     * @return array{
     *     finalized_sessions:int,
     *     newly_failed_files:int,
     *     finalized_session_ids:array<int, int>,
     *     cutoff_at:Carbon
     * }
     */
    public function cleanupStaleRestoreSessions(
        int $hours = self::DEFAULT_STALE_HOURS,
        ?string $targetBotUsername = null,
        ?int $sourceSessionId = null
    ): array {
        $normalizedHours = max($hours, 1);
        $cutoffAt = now()->subHours($normalizedHours);
        $normalizedTargetBotUsername = ltrim(trim((string) $targetBotUsername), '@');

        $query = TelegramFilestoreRestoreSession::query()
            ->whereIn('status', ['pending', 'running'])
            ->when($normalizedTargetBotUsername !== '', static function ($builder) use ($normalizedTargetBotUsername): void {
                $builder->where('target_bot_username', $normalizedTargetBotUsername);
            })
            ->when($sourceSessionId !== null && $sourceSessionId > 0, static function ($builder) use ($sourceSessionId): void {
                $builder->where('source_session_id', $sourceSessionId);
            })
            ->orderBy('id');

        $finalizedSessionIds = [];
        $newlyFailedFiles = 0;
        $staleMessage = Str::limit(
            sprintf('auto-cleaned after exceeding %d stale hours without activity', $normalizedHours),
            4000,
            '...'
        );

        foreach ($query->get(['id']) as $row) {
            $sessionId = (int) $row->id;
            $finalized = null;

            DB::transaction(function () use ($sessionId, $cutoffAt, $staleMessage, &$finalized): void {
                $session = TelegramFilestoreRestoreSession::query()
                    ->whereKey($sessionId)
                    ->lockForUpdate()
                    ->first();

                if (!$session || !in_array((string) $session->status, ['pending', 'running'], true)) {
                    return;
                }

                $lastActivityAt = $this->resolveRestoreSessionLastActivityAt($session);
                if ($lastActivityAt === null || $lastActivityAt->gt($cutoffAt)) {
                    return;
                }

                $markedAt = now();
                $newlyFailed = TelegramFilestoreRestoreFile::query()
                    ->where('restore_session_id', $sessionId)
                    ->whereNotIn('status', ['synced', 'failed'])
                    ->update([
                        'status' => 'failed',
                        'last_error' => $staleMessage,
                        'updated_at' => $markedAt,
                    ]);

                $successCount = (int) TelegramFilestoreRestoreFile::query()
                    ->where('restore_session_id', $sessionId)
                    ->where('status', 'synced')
                    ->count();

                $failedCount = (int) TelegramFilestoreRestoreFile::query()
                    ->where('restore_session_id', $sessionId)
                    ->where('status', 'failed')
                    ->count();

                $restoreFileCount = (int) TelegramFilestoreRestoreFile::query()
                    ->where('restore_session_id', $sessionId)
                    ->count();

                $session->total_files = max((int) $session->total_files, $restoreFileCount);
                $session->processed_files = $successCount + $failedCount;
                $session->success_files = $successCount;
                $session->failed_files = $failedCount;
                $session->status = $this->resolveRestoreTerminalStatus(
                    (int) $session->total_files,
                    (int) $session->processed_files,
                    $successCount,
                    $failedCount
                );
                $session->last_error = $session->status === 'completed' ? null : $staleMessage;
                $session->finished_at = $markedAt;
                $session->save();

                $finalized = [
                    'session_id' => $sessionId,
                    'newly_failed_files' => (int) $newlyFailed,
                ];
            });

            if ($finalized === null) {
                continue;
            }

            $finalizedSessionIds[] = (int) $finalized['session_id'];
            $newlyFailedFiles += (int) $finalized['newly_failed_files'];
        }

        if ($finalizedSessionIds !== []) {
            Log::warning('telegram_filestore_stale_restore_sessions_finalized', [
                'cutoff_at' => $cutoffAt->toDateTimeString(),
                'finalized_sessions' => count($finalizedSessionIds),
                'newly_failed_files' => $newlyFailedFiles,
                'session_ids' => $finalizedSessionIds,
            ]);
        }

        return [
            'finalized_sessions' => count($finalizedSessionIds),
            'newly_failed_files' => $newlyFailedFiles,
            'finalized_session_ids' => $finalizedSessionIds,
            'cutoff_at' => $cutoffAt,
        ];
    }

    private function resolveUploadingSessionLastActivityAt(TelegramFilestoreSession $session): ?Carbon
    {
        $lastFileCreatedAt = TelegramFilestoreFile::query()
            ->where('session_id', (int) $session->id)
            ->max('created_at');

        return $this->latestCarbon([
            $session->created_at,
            $session->close_upload_prompted_at,
            $lastFileCreatedAt,
        ]);
    }

    private function resolveRestoreSessionLastActivityAt(TelegramFilestoreRestoreSession $session): ?Carbon
    {
        $lastRestoreFileUpdatedAt = TelegramFilestoreRestoreFile::query()
            ->where('restore_session_id', (int) $session->id)
            ->max('updated_at');

        $lastRestoreFileCreatedAt = TelegramFilestoreRestoreFile::query()
            ->where('restore_session_id', (int) $session->id)
            ->max('created_at');

        return $this->latestCarbon([
            $session->updated_at,
            $session->started_at,
            $session->created_at,
            $lastRestoreFileUpdatedAt,
            $lastRestoreFileCreatedAt,
        ]);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    private function latestCarbon(array $values): ?Carbon
    {
        $latest = null;

        foreach ($values as $value) {
            $carbon = $this->toCarbon($value);
            if ($carbon === null) {
                continue;
            }

            if ($latest === null || $carbon->gt($latest)) {
                $latest = $carbon;
            }
        }

        return $latest;
    }

    private function toCarbon(mixed $value): ?Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy();
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value);
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveRestoreTerminalStatus(
        int $totalFiles,
        int $processedFiles,
        int $successFiles,
        int $failedFiles
    ): string {
        if ($processedFiles < $totalFiles) {
            return 'partial';
        }

        if ($failedFiles > 0 && $successFiles === 0) {
            return 'failed';
        }

        if ($failedFiles > 0) {
            return 'completed_with_failures';
        }

        return 'completed';
    }
}
