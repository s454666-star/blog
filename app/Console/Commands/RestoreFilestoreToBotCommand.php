<?php

namespace App\Console\Commands;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreRestoreFile;
use App\Models\TelegramFilestoreRestoreSession;
use App\Models\TelegramFilestoreSession;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RestoreFilestoreToBotCommand extends Command
{
    private const DEFAULT_BASE_URI = 'http://127.0.0.1:8001';
    private const DEFAULT_POLL_SECONDS = 15;
    private const POLL_INTERVAL_MICROSECONDS = 750000;
    private const DEFAULT_LOCAL_WORKER_ENV_PATH = 'storage/app/telegram-filestore-local-workers/worker.env';
    private const CA_BUNDLE_CANDIDATES = [
        'C:\\Program Files\\Git\\usr\\ssl\\cert.pem',
        'C:\\Program Files\\Git\\mingw64\\ssl\\certs\\ca-bundle.crt',
        'C:\\Users\\User\\AppData\\Local\\Packages\\PythonSoftwareFoundation.Python.3.11_qbz5n2kfra8p0\\LocalCache\\local-packages\\Python311\\site-packages\\certifi\\cacert.pem',
    ];

    protected $signature = 'filestore:restore-to-bot
        {--session-id= : 只處理單一 telegram_filestore_sessions.id}
        {--public-token= : 只處理單一 telegram_filestore_sessions.public_token}
        {--source-token= : 只處理單一 telegram_filestore_sessions.source_token}
        {--all : 處理 telegram_filestore_sessions 內所有 sessions}
        {--limit=0 : 本次最多轉幾筆 source files；0 代表不限}
        {--base-uri=http://127.0.0.1:8001 : 本機 Telegram FastAPI base uri}
        {--target-bot-username= : 新 bot username，可帶或不帶 @}
        {--target-bot-token= : 新 bot token；留空時讀 config(telegram.backup_restore_bot_token)}
        {--source-bot-token= : 舊 filestore bot token；留空時讀 config(telegram.filestore_bot_token)}
        {--worker-env= : 本機 worker.env 路徑；預設 storage/app/telegram-filestore-local-workers/worker.env}
        {--target-chat-id=0 : 新 bot 的 private chat id；0 代表從 getUpdates 自動抓最新 private chat}
        {--poll-seconds=15 : 每筆 forward 後輪詢新 bot getUpdates 的秒數}
        {--dry-run : 只列出將處理的 sessions，不真的 forward}';

    protected $description = '逐筆將 telegram_filestore_files 對應訊息 forward 到新 bot，並記錄新 bot 自己的 file_id/file_unique_id。';

    public function handle(): int
    {
        $sessionId = max((int) $this->option('session-id'), 0);
        $publicToken = trim((string) $this->option('public-token'));
        $sourceToken = trim((string) $this->option('source-token'));
        $all = (bool) $this->option('all');
        $limit = max((int) $this->option('limit'), 0);
        $baseUri = rtrim(trim((string) ($this->option('base-uri') ?: self::DEFAULT_BASE_URI)), '/');
        $targetBotUsername = ltrim(trim((string) ($this->option('target-bot-username') ?: config('telegram.backup_restore_bot_username', 'file_backup_restore_bot'))), '@');
        $workerEnvPath = trim((string) ($this->option('worker-env') ?: base_path(self::DEFAULT_LOCAL_WORKER_ENV_PATH)));
        $localWorkerEnv = $this->readKeyValueEnvFile($workerEnvPath);
        $targetBotToken = trim((string) ($this->option('target-bot-token') ?: ($localWorkerEnv['TELEGRAM_BACKUP_RESTORE_BOT_TOKEN'] ?? config('telegram.backup_restore_bot_token'))));
        $sourceBotToken = trim((string) ($this->option('source-bot-token') ?: ($localWorkerEnv['TELEGRAM_FILESTORE_BOT_TOKEN'] ?? config('telegram.filestore_bot_token'))));
        $targetChatId = max((int) $this->option('target-chat-id'), 0);
        $pollSeconds = max((int) $this->option('poll-seconds'), 1);
        $dryRun = (bool) $this->option('dry-run');

        if ($baseUri === '') {
            $this->error('base-uri 不可為空。');
            return self::FAILURE;
        }

        if ($targetBotUsername === '') {
            $this->error('target-bot-username 不可為空。');
            return self::FAILURE;
        }

        if ($targetBotToken === '') {
            $this->error('target-bot-token 不可為空；請帶 --target-bot-token 或設定 TELEGRAM_BACKUP_RESTORE_BOT_TOKEN。');
            return self::FAILURE;
        }

        if (!$all && $sessionId <= 0 && $publicToken === '' && $sourceToken === '') {
            $this->error('請至少指定 --session-id / --public-token / --source-token 其中一個，或加上 --all。');
            return self::FAILURE;
        }

        $sourceSessionsQuery = $this->buildSourceSessionQuery($sessionId, $publicToken, $sourceToken, $all);
        $sourceSessionCount = (clone $sourceSessionsQuery)->count();
        if ($sourceSessionCount <= 0) {
            $this->warn('找不到符合條件的 source sessions。');
            return self::SUCCESS;
        }

        $this->line(sprintf(
            'target_bot=@%s base_uri=%s source_sessions=%d limit=%s',
            $targetBotUsername,
            $baseUri,
            $sourceSessionCount,
            $limit > 0 ? (string) $limit : 'unlimited'
        ));
        if ($workerEnvPath !== '') {
            $this->line('worker_env=' . $workerEnvPath);
        }
        $caBundlePath = $this->resolveCaBundlePath();
        if ($caBundlePath !== null) {
            $this->line('telegram_ca_bundle=' . $caBundlePath);
        }

        if ($dryRun) {
            foreach ($sourceSessionsQuery->cursor() as $sourceSession) {
                $fileCount = (int) TelegramFilestoreFile::query()
                    ->where('session_id', $sourceSession->id)
                    ->count();

                $this->line(sprintf(
                    'source_session=%d public_token=%s source_token=%s files=%d',
                    (int) $sourceSession->id,
                    (string) ($sourceSession->public_token ?? '-'),
                    (string) ($sourceSession->source_token ?? '-'),
                    $fileCount
                ));
            }

            $this->info('dry-run 結束，未實際 forward。');
            return self::SUCCESS;
        }

        try {
            $targetContext = $this->resolveTargetChatContext($targetBotToken, $targetChatId);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $remainingLimit = $limit > 0 ? $limit : PHP_INT_MAX;
        $stats = [
            'attempted' => 0,
            'synced' => 0,
            'failed' => 0,
            'skipped_existing' => 0,
        ];

        foreach ($sourceSessionsQuery->cursor() as $sourceSession) {
            if ($remainingLimit <= 0) {
                break;
            }

            $fileCount = (int) TelegramFilestoreFile::query()
                ->where('session_id', $sourceSession->id)
                ->count();

            $this->line(sprintf(
                'source_session=%d public_token=%s source_token=%s files=%d',
                (int) $sourceSession->id,
                (string) ($sourceSession->public_token ?? '-'),
                (string) ($sourceSession->source_token ?? '-'),
                $fileCount
            ));

            $restoreSession = $this->getOrCreateRestoreSession(
                $sourceSession,
                $targetBotUsername,
                (int) $targetContext['chat_id']
            );

            $existingSyncedCount = (int) TelegramFilestoreRestoreFile::query()
                ->where('restore_session_id', $restoreSession->id)
                ->where('status', 'synced')
                ->count();

            if ($fileCount > 0 && $existingSyncedCount >= $fileCount) {
                $stats['skipped_existing'] += $fileCount;
                $this->line('  skip: all files already restored');
                continue;
            }

            $restoreSession->status = 'running';
            $restoreSession->last_error = null;
            $restoreSession->target_chat_id = (int) $targetContext['chat_id'];
            $restoreSession->started_at = $restoreSession->started_at ?: now();
            $restoreSession->total_files = $fileCount;
            $restoreSession->save();

            foreach (
                TelegramFilestoreFile::query()
                    ->where('session_id', $sourceSession->id)
                    ->orderBy('id')
                    ->cursor() as $sourceFile
            ) {
                if ($remainingLimit <= 0) {
                    break 2;
                }

                $restoreFile = $this->loadRestoreFileRow($restoreSession, $sourceSession, $sourceFile);

                if ((string) $restoreFile->status === 'synced') {
                    $stats['skipped_existing']++;
                    continue;
                }

                $stats['attempted']++;
                $remainingLimit--;

                $this->line(sprintf(
                    '[session:%d file:%d] forwarding message_id=%d type=%s name=%s',
                    (int) $sourceSession->id,
                    (int) $sourceFile->id,
                    (int) ($sourceFile->message_id ?? 0),
                    (string) ($sourceFile->file_type ?? 'unknown'),
                    (string) ($sourceFile->file_name ?? '(null)')
                ));

                if ((int) ($sourceFile->chat_id ?? 0) <= 0 || (int) ($sourceFile->message_id ?? 0) <= 0) {
                    $this->markRestoreFileFailed($restoreFile, 'source chat_id/message_id 缺失，無法 forward');
                    $stats['failed']++;
                    $this->line('  failed: source chat_id/message_id missing');
                    $this->refreshRestoreSessionStats($restoreSession);
                    continue;
                }

                $forwardResult = $this->forwardSourceMessage(
                    $baseUri,
                    (int) $sourceFile->chat_id,
                    (int) $sourceFile->message_id,
                    $targetBotUsername
                );

                if (!($forwardResult['ok'] ?? false)) {
                    if ($sourceBotToken !== '' && $this->shouldFallbackToSourceBotApi($forwardResult)) {
                        $this->line('  forward missing source message, fallback=getFile+upload');
                        $fallbackResult = $this->copySourceFileViaBotApi(
                            $sourceBotToken,
                            $targetBotToken,
                            (int) $targetContext['chat_id'],
                            $sourceFile
                        );

                        if ($fallbackResult['ok'] ?? false) {
                            $restoreFile->status = 'synced';
                            $restoreFile->target_chat_id = (int) ($fallbackResult['target_chat_id'] ?? 0);
                            $restoreFile->target_message_id = (int) ($fallbackResult['target_message_id'] ?? 0);
                            $restoreFile->target_file_id = (string) ($fallbackResult['target_file_id'] ?? '');
                            $restoreFile->target_file_unique_id = (string) ($fallbackResult['target_file_unique_id'] ?? '');
                            $restoreFile->file_name = (string) (($fallbackResult['file_name'] ?? '') !== '' ? $fallbackResult['file_name'] : ($sourceFile->file_name ?? ''));
                            $restoreFile->mime_type = (string) (($fallbackResult['mime_type'] ?? '') !== '' ? $fallbackResult['mime_type'] : ($sourceFile->mime_type ?? ''));
                            $restoreFile->file_size = (int) (($fallbackResult['file_size'] ?? 0) > 0 ? $fallbackResult['file_size'] : ($sourceFile->file_size ?? 0));
                            $restoreFile->file_type = (string) (($fallbackResult['file_type'] ?? '') !== '' ? $fallbackResult['file_type'] : ($sourceFile->file_type ?? 'document'));
                            $restoreFile->raw_payload = $fallbackResult['raw_payload'] ?? null;
                            $restoreFile->synced_at = now();
                            $restoreFile->last_error = null;
                            $restoreFile->save();

                            $stats['synced']++;
                            $this->line(sprintf(
                                '  synced via fallback: target_message_id=%d target_file_id=%s',
                                (int) $restoreFile->target_message_id,
                                $this->shorten((string) $restoreFile->target_file_id)
                            ));

                            $this->refreshRestoreSessionStats($restoreSession, (int) $sourceFile->id);
                            continue;
                        }

                        $forwardResult = [
                            'ok' => false,
                            'error' => (string) ($fallbackResult['error'] ?? 'fallback upload failed'),
                        ];
                    }

                    $this->markRestoreFileFailed($restoreFile, (string) ($forwardResult['error'] ?? 'forward failed'));
                    $stats['failed']++;
                    $this->line('  failed: ' . (string) ($forwardResult['error'] ?? 'forward failed'));
                    $this->refreshRestoreSessionStats($restoreSession);
                    continue;
                }

                $restoreFile->status = 'forwarded';
                $restoreFile->forwarded_message_id = (int) ($forwardResult['forwarded_message_id'] ?? 0);
                $restoreFile->forwarded_at = now();
                $restoreFile->last_error = null;
                $restoreFile->save();

                $captureResult = $this->pollTargetBotForForwardedFile(
                    $targetBotToken,
                    (int) $targetContext['chat_id'],
                    (int) $targetContext['last_update_id'],
                    $pollSeconds
                );
                $targetContext['last_update_id'] = (int) ($captureResult['last_update_id'] ?? $targetContext['last_update_id']);

                if (!($captureResult['ok'] ?? false)) {
                    $this->markRestoreFileFailed($restoreFile, (string) ($captureResult['error'] ?? 'target bot polling failed'));
                    $stats['failed']++;
                    $this->line('  failed: ' . (string) ($captureResult['error'] ?? 'target bot polling failed'));
                    $this->refreshRestoreSessionStats($restoreSession);
                    continue;
                }

                $restoreFile->status = 'synced';
                $restoreFile->target_chat_id = (int) ($captureResult['target_chat_id'] ?? 0);
                $restoreFile->target_message_id = (int) ($captureResult['target_message_id'] ?? 0);
                $restoreFile->target_file_id = (string) ($captureResult['target_file_id'] ?? '');
                $restoreFile->target_file_unique_id = (string) ($captureResult['target_file_unique_id'] ?? '');
                $restoreFile->file_name = (string) (($captureResult['file_name'] ?? '') !== '' ? $captureResult['file_name'] : ($sourceFile->file_name ?? ''));
                $restoreFile->mime_type = (string) (($captureResult['mime_type'] ?? '') !== '' ? $captureResult['mime_type'] : ($sourceFile->mime_type ?? ''));
                $restoreFile->file_size = (int) (($captureResult['file_size'] ?? 0) > 0 ? $captureResult['file_size'] : ($sourceFile->file_size ?? 0));
                $restoreFile->file_type = (string) (($captureResult['file_type'] ?? '') !== '' ? $captureResult['file_type'] : ($sourceFile->file_type ?? 'document'));
                $restoreFile->raw_payload = $captureResult['raw_payload'] ?? null;
                $restoreFile->synced_at = now();
                $restoreFile->last_error = null;
                $restoreFile->save();

                $stats['synced']++;
                $this->line(sprintf(
                    '  synced: target_message_id=%d target_file_id=%s',
                    (int) $restoreFile->target_message_id,
                    $this->shorten((string) $restoreFile->target_file_id)
                ));

                $this->refreshRestoreSessionStats($restoreSession, (int) $sourceFile->id);
            }

            $this->finalizeRestoreSession($restoreSession);
        }

        $this->newLine();
        $this->line(sprintf(
            'attempted=%d synced=%d failed=%d skipped_existing=%d',
            $stats['attempted'],
            $stats['synced'],
            $stats['failed'],
            $stats['skipped_existing']
        ));

        if ($stats['failed'] > 0) {
            $this->warn('restore finished with failures');
            return self::FAILURE;
        }

        $this->info('restore finished successfully');
        return self::SUCCESS;
    }

    private function buildSourceSessionQuery(
        int $sessionId,
        string $publicToken,
        string $sourceToken,
        bool $all
    ) {
        $query = TelegramFilestoreSession::query()
            ->orderBy('id');

        if ($sessionId > 0) {
            $query->where('id', $sessionId);
        }

        if ($publicToken !== '') {
            $query->where('public_token', $publicToken);
        }

        if ($sourceToken !== '') {
            $query->where('source_token', $sourceToken);
        }

        if (!$all && $sessionId <= 0 && $publicToken === '' && $sourceToken === '') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function getOrCreateRestoreSession(
        TelegramFilestoreSession $sourceSession,
        string $targetBotUsername,
        int $targetChatId
    ): TelegramFilestoreRestoreSession {
        $restoreSession = TelegramFilestoreRestoreSession::query()
            ->where('source_session_id', $sourceSession->id)
            ->where('target_bot_username', $targetBotUsername)
            ->orderByDesc('id')
            ->first();

        if ($restoreSession) {
            return tap($restoreSession, function (TelegramFilestoreRestoreSession $session) use ($sourceSession, $targetChatId): void {
                $session->source_chat_id = (int) ($sourceSession->chat_id ?? 0) ?: null;
                $session->source_token = $sourceSession->source_token;
                $session->source_public_token = $sourceSession->public_token;
                $session->target_chat_id = $targetChatId;
                $session->save();
            });
        }

        return TelegramFilestoreRestoreSession::query()->create([
            'source_session_id' => (int) $sourceSession->id,
            'source_chat_id' => (int) ($sourceSession->chat_id ?? 0) ?: null,
            'source_token' => $sourceSession->source_token,
            'source_public_token' => $sourceSession->public_token,
            'target_bot_username' => $targetBotUsername,
            'target_chat_id' => $targetChatId,
            'status' => 'pending',
            'total_files' => 0,
            'processed_files' => 0,
            'success_files' => 0,
            'failed_files' => 0,
        ]);
    }

    private function loadRestoreFileRow(
        TelegramFilestoreRestoreSession $restoreSession,
        TelegramFilestoreSession $sourceSession,
        TelegramFilestoreFile $sourceFile
    ): TelegramFilestoreRestoreFile {
        $restoreFile = TelegramFilestoreRestoreFile::query()
            ->where('restore_session_id', (int) $restoreSession->id)
            ->where('source_file_row_id', (int) $sourceFile->id)
            ->first();

        if ($restoreFile && (string) $restoreFile->status === 'synced') {
            return $restoreFile;
        }

        if (!$restoreFile) {
            $restoreFile = new TelegramFilestoreRestoreFile([
                'restore_session_id' => (int) $restoreSession->id,
                'source_file_row_id' => (int) $sourceFile->id,
            ]);
        }

        $restoreFile->source_session_id = (int) $sourceSession->id;
        $restoreFile->source_chat_id = (int) ($sourceFile->chat_id ?? 0) ?: null;
        $restoreFile->source_message_id = (int) ($sourceFile->message_id ?? 0) ?: null;
        $restoreFile->source_file_id = $sourceFile->file_id;
        $restoreFile->source_file_unique_id = $sourceFile->file_unique_id;
        $restoreFile->source_token = $sourceSession->source_token;
        $restoreFile->source_public_token = $sourceSession->public_token;
        $restoreFile->file_name = $sourceFile->file_name;
        $restoreFile->mime_type = $sourceFile->mime_type;
        $restoreFile->file_size = (int) ($sourceFile->file_size ?? 0);
        $restoreFile->file_type = (string) ($sourceFile->file_type ?? 'document');
        $restoreFile->attempt_count = max((int) $restoreFile->attempt_count, 0) + 1;
        $restoreFile->save();

        return $restoreFile;
    }

    private function markRestoreFileFailed(TelegramFilestoreRestoreFile $restoreFile, string $error): void
    {
        $restoreFile->status = 'failed';
        $restoreFile->last_error = Str::limit(trim($error), 4000, '...');
        $restoreFile->save();
    }

    private function refreshRestoreSessionStats(TelegramFilestoreRestoreSession $restoreSession, ?int $lastSourceFileId = null): void
    {
        $successCount = (int) TelegramFilestoreRestoreFile::query()
            ->where('restore_session_id', $restoreSession->id)
            ->where('status', 'synced')
            ->count();

        $failedCount = (int) TelegramFilestoreRestoreFile::query()
            ->where('restore_session_id', $restoreSession->id)
            ->where('status', 'failed')
            ->count();

        $restoreSession->processed_files = $successCount + $failedCount;
        $restoreSession->success_files = $successCount;
        $restoreSession->failed_files = $failedCount;
        if ($lastSourceFileId !== null) {
            $restoreSession->last_source_file_id = $lastSourceFileId;
        }
        $restoreSession->save();
    }

    private function finalizeRestoreSession(TelegramFilestoreRestoreSession $restoreSession): void
    {
        $this->refreshRestoreSessionStats($restoreSession);

        $processedAll = (int) $restoreSession->processed_files >= (int) $restoreSession->total_files;

        if (!$processedAll) {
            $restoreSession->status = 'partial';
        } elseif ((int) $restoreSession->failed_files > 0 && (int) $restoreSession->success_files === 0) {
            $restoreSession->status = 'failed';
        } elseif ((int) $restoreSession->failed_files > 0) {
            $restoreSession->status = 'completed_with_failures';
        } else {
            $restoreSession->status = 'completed';
        }

        $restoreSession->finished_at = now();
        $restoreSession->save();
    }

    /**
     * @return array{chat_id:int,last_update_id:int}
     */
    private function resolveTargetChatContext(string $targetBotToken, int $targetChatId): array
    {
        $updatesResult = $this->fetchTelegramUpdates($targetBotToken, 0);
        if (!($updatesResult['ok'] ?? false)) {
            throw new \RuntimeException((string) ($updatesResult['error'] ?? '無法讀取新 bot getUpdates'));
        }

        $lastUpdateId = (int) ($updatesResult['last_update_id'] ?? 0);
        if ($targetChatId > 0) {
            return [
                'chat_id' => $targetChatId,
                'last_update_id' => $lastUpdateId,
            ];
        }

        $latestPrivateChatId = 0;
        foreach (array_reverse((array) ($updatesResult['updates'] ?? [])) as $update) {
            $message = $this->extractUpdateMessage($update);
            if ($message === null) {
                continue;
            }

            $chat = $message['chat'] ?? null;
            if (!is_array($chat)) {
                continue;
            }

            if ((string) ($chat['type'] ?? '') !== 'private') {
                continue;
            }

            $latestPrivateChatId = (int) ($chat['id'] ?? 0);
            if ($latestPrivateChatId > 0) {
                break;
            }
        }

        if ($latestPrivateChatId <= 0) {
            throw new \RuntimeException('新 bot 還沒有 private chat update，請先對 bot 發 /start，或用 --target-chat-id 指定。');
        }

        return [
            'chat_id' => $latestPrivateChatId,
            'last_update_id' => $lastUpdateId,
        ];
    }

    /**
     * @return array{ok:bool, forwarded_message_id?:int, error?:string}
     */
    private function forwardSourceMessage(string $baseUri, int $sourceChatId, int $sourceMessageId, string $targetBotUsername): array
    {
        try {
            $response = Http::timeout(60)
                ->acceptJson()
                ->asJson()
                ->post($baseUri . '/bots/forward-messages', [
                    'source_chat_id' => $sourceChatId,
                    'message_ids' => [$sourceMessageId],
                    'target_bot_username' => $targetBotUsername,
                ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'forward request exception: ' . $e->getMessage(),
            ];
        }

        $json = $response->json();
        if (!$response->successful()) {
            $reason = is_array($json) ? (string) ($json['reason'] ?? '') : '';
            return [
                'ok' => false,
                'reason' => $reason,
                'error' => 'forward HTTP ' . $response->status() . ' body=' . $this->shorten($response->body(), 300),
            ];
        }

        if (!is_array($json) || (string) ($json['status'] ?? '') !== 'ok') {
            return [
                'ok' => false,
                'reason' => is_array($json) ? (string) ($json['reason'] ?? '') : '',
                'error' => 'forward api returned invalid payload: ' . $this->shorten($response->body(), 300),
            ];
        }

        $forwardedMessageIds = array_values(array_filter(array_map(
            'intval',
            (array) ($json['forwarded_message_ids'] ?? [])
        )));

        if ($forwardedMessageIds === []) {
            return [
                'ok' => false,
                'error' => 'forward api returned no forwarded_message_ids',
            ];
        }

        return [
            'ok' => true,
            'forwarded_message_id' => $forwardedMessageIds[0],
        ];
    }

    private function shouldFallbackToSourceBotApi(array $forwardResult): bool
    {
        $reason = trim((string) ($forwardResult['reason'] ?? ''));
        $error = trim((string) ($forwardResult['error'] ?? ''));

        return $reason === 'source_messages_not_found'
            || str_contains($error, 'source_messages_not_found')
            || str_contains($error, 'wrong file identifier');
    }

    /**
     * @return array<string, mixed>
     */
    private function copySourceFileViaBotApi(
        string $sourceBotToken,
        string $targetBotToken,
        int $targetChatId,
        TelegramFilestoreFile $sourceFile
    ): array {
        $getFileResponse = $this->telegramPendingRequest(60)
            ->get("https://api.telegram.org/bot{$sourceBotToken}/getFile", [
                'file_id' => (string) $sourceFile->file_id,
            ]);

        if (!$getFileResponse->successful()) {
            return [
                'ok' => false,
                'error' => 'source getFile HTTP ' . $getFileResponse->status() . ' body=' . $this->shorten($getFileResponse->body(), 300),
            ];
        }

        $getFileJson = $getFileResponse->json();
        $filePath = is_array($getFileJson) ? trim((string) data_get($getFileJson, 'result.file_path', '')) : '';
        if ($filePath === '') {
            return [
                'ok' => false,
                'error' => 'source getFile 缺少 file_path',
            ];
        }

        $downloadResponse = $this->telegramPendingRequest(180)
            ->get("https://api.telegram.org/file/bot{$sourceBotToken}/{$filePath}");

        if (!$downloadResponse->successful()) {
            return [
                'ok' => false,
                'error' => 'source file download HTTP ' . $downloadResponse->status() . ' body=' . $this->shorten($downloadResponse->body(), 300),
            ];
        }

        $field = $this->resolveUploadField((string) ($sourceFile->file_type ?? 'document'));
        $endpoint = $this->resolveUploadEndpoint((string) ($sourceFile->file_type ?? 'document'));
        $uploadName = $this->resolveUploadFilename($sourceFile, $filePath);
        $payload = [
            'chat_id' => $targetChatId,
        ];

        if ($field !== 'photo' && trim((string) ($sourceFile->file_name ?? '')) !== '') {
            $payload['caption'] = (string) $sourceFile->file_name;
        }

        if ($field === 'video') {
            $payload['supports_streaming'] = true;
        }

        $uploadResponse = $this->telegramPendingRequest(300)
            ->attach($field, $downloadResponse->body(), $uploadName)
            ->post("https://api.telegram.org/bot{$targetBotToken}/{$endpoint}", $payload);

        if (!$uploadResponse->successful()) {
            return [
                'ok' => false,
                'error' => 'target upload HTTP ' . $uploadResponse->status() . ' body=' . $this->shorten($uploadResponse->body(), 300),
            ];
        }

        $uploadJson = $uploadResponse->json();
        if (!is_array($uploadJson) || !($uploadJson['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'target upload invalid payload: ' . $this->shorten($uploadResponse->body(), 300),
            ];
        }

        $message = data_get($uploadJson, 'result');
        if (!is_array($message)) {
            return [
                'ok' => false,
                'error' => 'target upload result 缺少 message payload',
            ];
        }

        $filePayload = $this->extractFilePayloadFromMessage($message);
        if ($filePayload === null) {
            return [
                'ok' => false,
                'error' => 'target upload result 缺少 file payload',
            ];
        }

        return [
            'ok' => true,
            'target_chat_id' => (int) data_get($message, 'chat.id', 0),
            'target_message_id' => (int) data_get($message, 'message_id', 0),
            'target_file_id' => $filePayload['file_id'],
            'target_file_unique_id' => $filePayload['file_unique_id'],
            'file_name' => $filePayload['file_name'],
            'mime_type' => $filePayload['mime_type'],
            'file_size' => $filePayload['file_size'],
            'file_type' => $filePayload['file_type'],
            'raw_payload' => $message,
        ];
    }

    private function resolveUploadEndpoint(string $fileType): string
    {
        return match ($fileType) {
            'photo' => 'sendPhoto',
            'video' => 'sendVideo',
            default => 'sendDocument',
        };
    }

    private function resolveUploadField(string $fileType): string
    {
        return match ($fileType) {
            'photo' => 'photo',
            'video' => 'video',
            default => 'document',
        };
    }

    private function resolveUploadFilename(TelegramFilestoreFile $sourceFile, string $filePath): string
    {
        $fileName = trim((string) ($sourceFile->file_name ?? ''));
        if ($fileName !== '') {
            return $fileName;
        }

        $basename = basename($filePath);
        if ($basename !== '' && $basename !== '.' && $basename !== DIRECTORY_SEPARATOR) {
            return $basename;
        }

        return match ((string) ($sourceFile->file_type ?? 'document')) {
            'photo' => 'photo.jpg',
            'video' => 'video.mp4',
            default => 'document.bin',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function pollTargetBotForForwardedFile(
        string $targetBotToken,
        int $targetChatId,
        int $lastKnownUpdateId,
        int $pollSeconds
    ): array {
        $deadline = microtime(true) + $pollSeconds;
        $latestSeenUpdateId = $lastKnownUpdateId;
        $offset = max($lastKnownUpdateId + 1, 0);

        while (microtime(true) <= $deadline) {
            $updatesResult = $this->fetchTelegramUpdates($targetBotToken, $offset);
            if (!($updatesResult['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => (string) ($updatesResult['error'] ?? 'getUpdates failed'),
                    'last_update_id' => $latestSeenUpdateId,
                ];
            }

            $latestSeenUpdateId = max($latestSeenUpdateId, (int) ($updatesResult['last_update_id'] ?? 0));
            $offset = max($latestSeenUpdateId + 1, $offset);

            foreach ((array) ($updatesResult['updates'] ?? []) as $update) {
                $message = $this->extractUpdateMessage($update);
                if ($message === null) {
                    continue;
                }

                $chat = $message['chat'] ?? null;
                if (!is_array($chat) || (int) ($chat['id'] ?? 0) !== $targetChatId) {
                    continue;
                }

                $filePayload = $this->extractFilePayloadFromMessage($message);
                if ($filePayload === null) {
                    continue;
                }

                return [
                    'ok' => true,
                    'last_update_id' => $latestSeenUpdateId,
                    'target_chat_id' => $targetChatId,
                    'target_message_id' => (int) ($message['message_id'] ?? 0),
                    'target_file_id' => $filePayload['file_id'],
                    'target_file_unique_id' => $filePayload['file_unique_id'],
                    'file_name' => $filePayload['file_name'],
                    'mime_type' => $filePayload['mime_type'],
                    'file_size' => $filePayload['file_size'],
                    'file_type' => $filePayload['file_type'],
                    'raw_payload' => $message,
                ];
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }

        return [
            'ok' => false,
            'error' => '等待新 bot getUpdates 收到 forwarded media 逾時',
            'last_update_id' => $latestSeenUpdateId,
        ];
    }

    /**
     * @return array{ok:bool, updates?:array<int, array<string, mixed>>, last_update_id?:int, error?:string}
     */
    private function fetchTelegramUpdates(string $targetBotToken, int $offset): array
    {
        try {
            $response = $this->telegramPendingRequest(30)
                ->get("https://api.telegram.org/bot{$targetBotToken}/getUpdates", [
                    'offset' => $offset > 0 ? $offset : null,
                ]);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'getUpdates exception: ' . $e->getMessage(),
            ];
        }

        if (!$response->successful()) {
            return [
                'ok' => false,
                'error' => 'getUpdates HTTP ' . $response->status() . ' body=' . $this->shorten($response->body(), 300),
            ];
        }

        $json = $response->json();
        if (!is_array($json) || !($json['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'getUpdates invalid payload: ' . $this->shorten($response->body(), 300),
            ];
        }

        $updates = array_values(array_filter(
            (array) ($json['result'] ?? []),
            static fn ($update): bool => is_array($update)
        ));
        $lastUpdateId = 0;
        foreach ($updates as $update) {
            $lastUpdateId = max($lastUpdateId, (int) ($update['update_id'] ?? 0));
        }

        return [
            'ok' => true,
            'updates' => $updates,
            'last_update_id' => $lastUpdateId,
        ];
    }

    private function telegramPendingRequest(int $timeoutSeconds)
    {
        $request = Http::timeout($timeoutSeconds)
            ->acceptJson();

        $caBundlePath = $this->resolveCaBundlePath();
        if ($caBundlePath !== null) {
            $request = $request->withOptions(['verify' => $caBundlePath]);
        }

        return $request;
    }

    private function resolveCaBundlePath(): ?string
    {
        $workerEnvPath = trim((string) ($this->option('worker-env') ?: base_path(self::DEFAULT_LOCAL_WORKER_ENV_PATH)));
        $localWorkerEnv = $this->readKeyValueEnvFile($workerEnvPath);
        $envCandidates = array_filter([
            trim((string) ($localWorkerEnv['CURL_CA_BUNDLE'] ?? '')),
            trim((string) ($localWorkerEnv['SSL_CERT_FILE'] ?? '')),
            trim((string) getenv('CURL_CA_BUNDLE')),
            trim((string) getenv('SSL_CERT_FILE')),
            trim((string) getenv('REQUESTS_CA_BUNDLE')),
        ]);

        foreach (array_merge($envCandidates, self::CA_BUNDLE_CANDIDATES) as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function readKeyValueEnvFile(string $path): array
    {
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return [];
        }

        $values = [];
        foreach (@file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (!is_string($line)) {
                continue;
            }

            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name = trim((string) $parts[0]);
            if ($name === '') {
                continue;
            }

            $values[$name] = (string) $parts[1];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $update
     * @return array<string, mixed>|null
     */
    private function extractUpdateMessage(array $update): ?array
    {
        foreach (['message', 'edited_message', 'channel_post', 'edited_channel_post'] as $key) {
            $message = $update[$key] ?? null;
            if (is_array($message)) {
                return $message;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $message
     * @return array{file_type:string, file_id:string, file_unique_id:string, file_name:?string, mime_type:?string, file_size:int}|null
     */
    private function extractFilePayloadFromMessage(array $message): ?array
    {
        if (isset($message['photo']) && is_array($message['photo']) && count($message['photo']) > 0) {
            $photo = end($message['photo']);
            if (is_array($photo) && isset($photo['file_id'], $photo['file_unique_id'])) {
                return [
                    'file_type' => 'photo',
                    'file_id' => (string) $photo['file_id'],
                    'file_unique_id' => (string) $photo['file_unique_id'],
                    'file_name' => null,
                    'mime_type' => null,
                    'file_size' => (int) ($photo['file_size'] ?? 0),
                ];
            }
        }

        if (isset($message['video']) && is_array($message['video'])) {
            $video = $message['video'];
            if (isset($video['file_id'], $video['file_unique_id'])) {
                return [
                    'file_type' => 'video',
                    'file_id' => (string) $video['file_id'],
                    'file_unique_id' => (string) $video['file_unique_id'],
                    'file_name' => $video['file_name'] ?? null,
                    'mime_type' => $video['mime_type'] ?? null,
                    'file_size' => (int) ($video['file_size'] ?? 0),
                ];
            }
        }

        if (isset($message['document']) && is_array($message['document'])) {
            $document = $message['document'];
            if (isset($document['file_id'], $document['file_unique_id'])) {
                return [
                    'file_type' => 'document',
                    'file_id' => (string) $document['file_id'],
                    'file_unique_id' => (string) $document['file_unique_id'],
                    'file_name' => $document['file_name'] ?? null,
                    'mime_type' => $document['mime_type'] ?? null,
                    'file_size' => (int) ($document['file_size'] ?? 0),
                ];
            }
        }

        return null;
    }

    private function shorten(string $text, int $limit = 80): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        return Str::limit($text, $limit, '...');
    }
}
