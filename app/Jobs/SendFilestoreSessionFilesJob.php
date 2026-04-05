<?php

    namespace App\Jobs;

    use App\Models\TelegramFilestoreFile;
    use App\Models\TelegramFilestoreRestoreFile;
    use App\Models\TelegramFilestoreSession;
    use App\Services\TelegramFilestoreBotProfileResolver;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Http\Client\Response;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use Throwable;

    class SendFilestoreSessionFilesJob implements ShouldQueue
    {
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        private int $sessionId;
        private int $targetChatId;
        private string $botProfile = TelegramFilestoreBotProfileResolver::FILESTORE;

        /**
         * @var array<string, array<int, array{file_id:string,file_type:string,file_name:?string}>>
         */
        private array $restoreFileMapCache = [];

        public int $timeout = 900;
        public int $tries = 3;

        /**
         * Telegram sendMediaGroup 限制：最多 10 個
         */
        private const MEDIA_GROUP_BATCH_SIZE = 10;

        /**
         * 每批之間等待（避免 Telegram rate limit）
         */
        private const BATCH_SLEEP_MICROSECONDS = 5000000;

        public function __construct(
            int $sessionId,
            int $targetChatId,
            string $botProfile = TelegramFilestoreBotProfileResolver::FILESTORE
        )
        {
            $this->sessionId = $sessionId;
            $this->targetChatId = $targetChatId;
            $this->botProfile = app(TelegramFilestoreBotProfileResolver::class)->normalize($botProfile);
            $this->onQueue('telegram_filestore');
        }

        public function getBotProfile(): string
        {
            return $this->botProfile;
        }

        public function handle(): void
        {
            $session = TelegramFilestoreSession::query()
                ->where('id', $this->sessionId)
                ->where('status', 'closed')
                ->first();

            if (!$session) {
                Log::warning('telegram_filestore_send_session_missing', $this->buildLogContext());
                $this->sendMessage($this->targetChatId, "找不到這個代碼對應的檔案。");
                return;
            }

            $finalNotice = null;
            $finalState = 'completed';

            try {
                $sourceFiles = TelegramFilestoreFile::query()
                    ->where('session_id', $session->id)
                    ->orderBy('id')
                    ->get();

                $totalCount = (int) $sourceFiles->count();

                if ($totalCount <= 0) {
                    Log::warning('telegram_filestore_send_session_empty', $this->buildLogContext($session));
                    $finalNotice = '這個代碼沒有任何檔案。';
                    return;
                }

                $sendableFiles = [];
                $unavailableFiles = 0;

                foreach ($sourceFiles as $sourceFile) {
                    $resolvedFile = $this->resolveSendableFile($session, $sourceFile);
                    if ($resolvedFile === null) {
                        $unavailableFiles++;
                        continue;
                    }

                    $sendableFiles[] = $resolvedFile;
                }

                if (empty($sendableFiles)) {
                    Log::warning('telegram_filestore_send_session_unavailable', $this->buildLogContext($session, [
                        'total_files' => $totalCount,
                        'unavailable_files' => $unavailableFiles,
                    ]));

                    $finalNotice = $this->usesRestoreTargetFileIds()
                        ? '這個代碼尚未同步到 @' . $this->currentBotUsername() . '，暫時無法傳送。'
                        : '這個代碼目前沒有可用的檔案可傳送。';

                    return;
                }

                $mediaFiles = array_values(array_filter(
                    $sendableFiles,
                    static fn (array $file): bool => in_array($file['file_type'], ['photo', 'video'], true)
                ));

                $documentFiles = array_values(array_filter(
                    $sendableFiles,
                    static fn (array $file): bool => $file['file_type'] === 'document'
                ));

                $otherFiles = array_values(array_filter(
                    $sendableFiles,
                    static fn (array $file): bool => !in_array($file['file_type'], ['photo', 'video', 'document'], true)
                ));

                $mediaCount = count($mediaFiles);
                $documentCount = count($documentFiles);
                $otherCount = count($otherFiles);
                $mediaChunkCount = (int) ceil($mediaCount / self::MEDIA_GROUP_BATCH_SIZE);
                $failedMediaGroups = 0;
                $failedFileSends = 0;

                Log::info('telegram_filestore_send_job_started', $this->buildLogContext($session, [
                    'attempt' => $this->attempts(),
                    'total_files' => $totalCount,
                    'sendable_files' => count($sendableFiles),
                    'unavailable_files' => $unavailableFiles,
                    'uses_restore_target_file_ids' => $this->usesRestoreTargetFileIds(),
                    'media_files' => $mediaCount,
                    'document_files' => $documentCount,
                    'other_files' => $otherCount,
                    'media_chunk_count' => $mediaChunkCount,
                ]));

                $infoLines = [];
                $infoLines[] = "開始傳送檔案（共 {$totalCount} 個，可傳送 " . count($sendableFiles) . " 個）（batch-album-v3）…";
                if ($unavailableFiles > 0) {
                    $infoLines[] = $this->usesRestoreTargetFileIds()
                        ? "尚未同步到 @{$this->currentBotUsername()}：{$unavailableFiles} 個，會先略過"
                        : "缺少可用 file_id：{$unavailableFiles} 個，會先略過";
                }
                if ($mediaCount > 0) {
                    $infoLines[] = "照片/影片：每 " . self::MEDIA_GROUP_BATCH_SIZE . " 個一組相簿批次傳送（共 {$mediaCount} 個）";
                }
                if ($documentCount > 0) {
                    $infoLines[] = "文件：因 Telegram 限制，會逐筆傳送（共 {$documentCount} 個）";
                }
                if ($otherCount > 0) {
                    $infoLines[] = "其他：會逐筆傳送（共 {$otherCount} 個）";
                }

                $this->sendMessage($this->targetChatId, implode("\n", $infoLines));

                if ($mediaCount > 0) {
                    $chunks = array_chunk($mediaFiles, self::MEDIA_GROUP_BATCH_SIZE);

                    foreach ($chunks as $index => $chunkFiles) {
                        $batchResult = $this->sendMediaGroupBatch(
                            $this->targetChatId,
                            $chunkFiles,
                            $session,
                            $index + 1,
                            count($chunks)
                        );

                        if (!$batchResult['ok']) {
                            if ($batchResult['rate_limited']) {
                                $finalState = 'rate_limited';
                                $finalNotice = $this->formatRateLimitedNotice($batchResult['retry_after']);

                                Log::warning('telegram_filestore_send_job_aborted_rate_limit', $this->buildLogContext($session, [
                                    'phase' => 'media_group',
                                    'chunk_index' => $index + 1,
                                    'chunk_total' => count($chunks),
                                    'retry_after' => $batchResult['retry_after'],
                                    'description' => $batchResult['description'],
                                ]));

                                return;
                            }

                            $failedMediaGroups++;

                            Log::warning('telegram_filestore_send_media_group_fallback', $this->buildLogContext($session, [
                                'chunk_index' => $index + 1,
                                'chunk_total' => count($chunks),
                                'chunk_size' => count($chunkFiles),
                            ]));

                            $this->sendMessage(
                                $this->targetChatId,
                                '相簿批次傳送失敗，改用逐筆傳送（請看 log 查原因）。'
                            );

                            foreach ($chunkFiles as $file) {
                                $fileResult = $this->sendFileByType(
                                    $this->targetChatId,
                                    (string) ($file['file_type'] ?? ''),
                                    (string) ($file['file_id'] ?? ''),
                                    $file['file_name'] ?? null,
                                    $session
                                );

                                if (!$fileResult['ok']) {
                                    $failedFileSends++;

                                    if ($fileResult['rate_limited']) {
                                        $finalState = 'rate_limited';
                                        $finalNotice = $this->formatRateLimitedNotice($fileResult['retry_after']);

                                        Log::warning('telegram_filestore_send_job_aborted_rate_limit', $this->buildLogContext($session, [
                                            'phase' => 'media_group_fallback_single',
                                            'file_type' => (string) ($file['file_type'] ?? ''),
                                            'file_name' => $file['file_name'] ?? null,
                                            'retry_after' => $fileResult['retry_after'],
                                            'description' => $fileResult['description'],
                                        ]));

                                        return;
                                    }
                                }

                                usleep(250000);
                            }
                        }

                        if ($index < count($chunks) - 1) {
                            usleep(self::BATCH_SLEEP_MICROSECONDS);
                        }
                    }
                }

                if ($documentCount > 0) {
                    foreach ($documentFiles as $i => $file) {
                        $fileResult = $this->sendFileByType(
                            $this->targetChatId,
                            (string) ($file['file_type'] ?? ''),
                            (string) ($file['file_id'] ?? ''),
                            $file['file_name'] ?? null,
                            $session
                        );

                        if (!$fileResult['ok']) {
                            $failedFileSends++;

                            if ($fileResult['rate_limited']) {
                                $finalState = 'rate_limited';
                                $finalNotice = $this->formatRateLimitedNotice($fileResult['retry_after']);

                                Log::warning('telegram_filestore_send_job_aborted_rate_limit', $this->buildLogContext($session, [
                                    'phase' => 'document_single',
                                    'file_name' => $file['file_name'] ?? null,
                                    'retry_after' => $fileResult['retry_after'],
                                    'description' => $fileResult['description'],
                                ]));

                                return;
                            }
                        }

                        if ($i < $documentCount - 1) {
                            usleep(self::BATCH_SLEEP_MICROSECONDS);
                        }
                    }
                }

                if ($otherCount > 0) {
                    foreach ($otherFiles as $i => $file) {
                        $fileResult = $this->sendFileByType(
                            $this->targetChatId,
                            (string) ($file['file_type'] ?? ''),
                            (string) ($file['file_id'] ?? ''),
                            $file['file_name'] ?? null,
                            $session
                        );

                        if (!$fileResult['ok']) {
                            $failedFileSends++;

                            if ($fileResult['rate_limited']) {
                                $finalState = 'rate_limited';
                                $finalNotice = $this->formatRateLimitedNotice($fileResult['retry_after']);

                                Log::warning('telegram_filestore_send_job_aborted_rate_limit', $this->buildLogContext($session, [
                                    'phase' => 'other_single',
                                    'file_type' => (string) ($file['file_type'] ?? ''),
                                    'file_name' => $file['file_name'] ?? null,
                                    'retry_after' => $fileResult['retry_after'],
                                    'description' => $fileResult['description'],
                                ]));

                                return;
                            }
                        }

                        if ($i < $otherCount - 1) {
                            usleep(self::BATCH_SLEEP_MICROSECONDS);
                        }
                    }
                }

                Log::info('telegram_filestore_send_job_completed', $this->buildLogContext($session, [
                    'attempt' => $this->attempts(),
                    'total_files' => $totalCount,
                    'sendable_files' => count($sendableFiles),
                    'unavailable_files' => $unavailableFiles,
                    'media_files' => $mediaCount,
                    'document_files' => $documentCount,
                    'other_files' => $otherCount,
                    'failed_media_groups' => $failedMediaGroups,
                    'failed_file_sends' => $failedFileSends,
                ]));

                $finalNotice = '已全部傳送完成 ✅';
                if ($unavailableFiles > 0) {
                    $finalNotice .= "\n" . (
                        $this->usesRestoreTargetFileIds()
                            ? "另有 {$unavailableFiles} 個檔案尚未同步到 @{$this->currentBotUsername()}，已略過。"
                            : "另有 {$unavailableFiles} 個檔案缺少可用 file_id，已略過。"
                    );
                }
            } catch (Throwable $e) {
                Log::error('telegram_filestore_send_job_exception', $this->buildLogContext($session, [
                    'attempt' => $this->attempts(),
                    'message' => $e->getMessage(),
                ]));

                throw $e;
            } finally {
                $this->markSessionNotSending($session, $finalState);

                if ($finalNotice !== null) {
                    $this->sendMessage($this->targetChatId, $finalNotice);
                }
            }
        }

        public function failed(\Throwable $e): void
        {
            $session = TelegramFilestoreSession::query()->where('id', $this->sessionId)->first();
            $this->markSessionNotSending($session, 'failed');

            Log::error('telegram_filestore_send_job_failed', $this->buildLogContext($session, [
                'attempt' => $this->attempts(),
                'message' => $e->getMessage(),
            ]));

            $this->sendMessage($this->targetChatId, "傳送檔案時發生錯誤，請稍後再試。");
        }

        /**
         * sendMediaGroup 批次傳送照片/影片（最多 10 個）
         *
         * @param array<int, array{source_file_row_id:int,file_type:string,file_id:string,file_name:?string}> $files
         * @return array{ok: bool, status: ?int, error_code: ?int, description: ?string, retry_after: ?int, rate_limited: bool, body: ?string}
         */
        private function sendMediaGroupBatch(
            int $chatId,
            array $files,
            TelegramFilestoreSession $session,
            int $chunkIndex,
            int $chunkTotal
        ): array
        {
            $media = [];
            foreach ($files as $file) {
                $type = (string) ($file['file_type'] ?? '');
                $fileId = trim((string) ($file['file_id'] ?? ''));

                if (($type !== 'photo' && $type !== 'video') || $fileId === '') {
                    continue;
                }

                $item = [
                    'type' => $type,
                    'media' => $fileId,
                ];

                if ($type === 'video') {
                    $name = (string) ($file['file_name'] ?? '');
                    if ($name !== '') {
                        $item['caption'] = $name;
                    }
                }

                $media[] = $item;
            }

            if (empty($media)) {
                return $this->successfulTelegramResult();
            }

            return $this->postTelegramMethod(
                'sendMediaGroup',
                [
                    'chat_id' => $chatId,
                    'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
                ],
                $this->buildLogContext($session, [
                    'target_chat_id' => $chatId,
                    'chunk_index' => $chunkIndex,
                    'chunk_total' => $chunkTotal,
                    'media_count' => count($media),
                ]),
                true
            );
        }

        /**
         * @return array{ok: bool, status: ?int, error_code: ?int, description: ?string, retry_after: ?int, rate_limited: bool, body: ?string}
         */
        private function successfulTelegramResult(): array
        {
            return [
                'ok' => true,
                'status' => 200,
                'error_code' => null,
                'description' => null,
                'retry_after' => null,
                'rate_limited' => false,
                'body' => null,
            ];
        }

        /**
         * @return array{ok: bool, status: ?int, error_code: ?int, description: ?string, retry_after: ?int, rate_limited: bool, body: ?string}
         */
        private function normalizeTelegramResponse(?Response $response): array
        {
            if (!$response) {
                return [
                    'ok' => false,
                    'status' => null,
                    'error_code' => null,
                    'description' => null,
                    'retry_after' => null,
                    'rate_limited' => false,
                    'body' => null,
                ];
            }

            $json = $response->json();
            $status = $response->status();
            $errorCode = is_array($json) ? (int) ($json['error_code'] ?? 0) : 0;
            $retryAfter = is_array($json) ? (int) ($json['parameters']['retry_after'] ?? 0) : 0;
            $ok = $response->successful() && is_array($json) && (($json['ok'] ?? false) === true);

            return [
                'ok' => $ok,
                'status' => $status,
                'error_code' => $errorCode > 0 ? $errorCode : null,
                'description' => is_array($json) ? (string) ($json['description'] ?? '') : '',
                'retry_after' => $retryAfter > 0 ? $retryAfter : null,
                'rate_limited' => $status === 429 || $errorCode === 429,
                'body' => $response->body(),
            ];
        }

        /**
         * @return array{ok: bool, status: ?int, error_code: ?int, description: ?string, retry_after: ?int, rate_limited: bool, body: ?string}
         */
        private function postTelegramMethod(
            string $method,
            array $payload,
            array $context = [],
            bool $asForm = false
        ): array
        {
            $botProfile = app(TelegramFilestoreBotProfileResolver::class)->resolve($this->botProfile);
            $token = (string) ($botProfile['token'] ?? '');
            if ($token === '') {
                $result = [
                    'ok' => false,
                    'status' => null,
                    'error_code' => null,
                    'description' => 'Telegram filestore bot token missing.',
                    'retry_after' => null,
                    'rate_limited' => false,
                    'body' => null,
                ];

                Log::error('telegram_filestore_api_failed', array_merge($context, [
                    'bot_profile' => $botProfile['key'] ?? $this->botProfile,
                    'bot_username' => $botProfile['username'] ?? null,
                    'method' => $method,
                    'status' => null,
                    'error_code' => null,
                    'description' => $result['description'],
                    'retry_after' => null,
                    'body' => null,
                ]));

                return $result;
            }

            try {
                $request = Http::timeout(60);
                if ($asForm) {
                    $request = $request->asForm();
                }

                $response = $request->post("https://api.telegram.org/bot{$token}/{$method}", $payload);
            } catch (Throwable $e) {
                Log::error('telegram_filestore_api_exception', array_merge($context, [
                    'bot_profile' => $botProfile['key'] ?? $this->botProfile,
                    'bot_username' => $botProfile['username'] ?? null,
                    'method' => $method,
                    'message' => $e->getMessage(),
                ]));

                return [
                    'ok' => false,
                    'status' => null,
                    'error_code' => null,
                    'description' => $e->getMessage(),
                    'retry_after' => null,
                    'rate_limited' => false,
                    'body' => null,
                ];
            }

            $result = $this->normalizeTelegramResponse($response);

            if (!$result['ok']) {
                $event = $result['rate_limited']
                    ? 'telegram_filestore_api_rate_limited'
                    : 'telegram_filestore_api_failed';

                Log::log($result['rate_limited'] ? 'warning' : 'error', $event, array_merge($context, [
                    'bot_profile' => $botProfile['key'] ?? $this->botProfile,
                    'bot_username' => $botProfile['username'] ?? null,
                    'method' => $method,
                    'status' => $result['status'],
                    'error_code' => $result['error_code'],
                    'description' => $result['description'],
                    'retry_after' => $result['retry_after'],
                    'body' => $result['body'],
                ]));
            }

            return $result;
        }

        /**
         * @return array{ok: bool, status: ?int, error_code: ?int, description: ?string, retry_after: ?int, rate_limited: bool, body: ?string}
         */
        private function sendFileByType(
            int $chatId,
            string $fileType,
            string $fileId,
            ?string $fileName,
            TelegramFilestoreSession $session
        ): array
        {
            $method = 'sendDocument';
            $payload = [
                'chat_id' => $chatId,
                'document' => $fileId,
            ];

            if ($fileType === 'photo') {
                $method = 'sendPhoto';
                $payload = [
                    'chat_id' => $chatId,
                    'photo' => $fileId,
                ];
            } elseif ($fileType === 'video') {
                $method = 'sendVideo';
                $payload = [
                    'chat_id' => $chatId,
                    'video' => $fileId,
                ];
            } elseif ($fileType === 'document') {
                $method = 'sendDocument';
                $payload = [
                    'chat_id' => $chatId,
                    'document' => $fileId,
                ];
            }

            if ($fileName !== null && $fileName !== '') {
                $payload['caption'] = $fileName;
            }

            return $this->postTelegramMethod($method, $payload, $this->buildLogContext($session, [
                'target_chat_id' => $chatId,
                'file_type' => $fileType,
                'file_name' => $fileName,
            ]));
        }

        private function buildLogContext(?TelegramFilestoreSession $session = null, array $extra = []): array
        {
            $botProfile = app(TelegramFilestoreBotProfileResolver::class)->resolve($this->botProfile);
            $context = [
                'session_id' => $session ? (int) $session->id : $this->sessionId,
                'target_chat_id' => $this->targetChatId,
                'bot_profile' => $botProfile['key'] ?? $this->botProfile,
                'bot_username' => $botProfile['username'] ?? null,
            ];

            if ($session) {
                $context['public_token'] = $session->public_token;
                $context['source_token'] = $session->source_token;
                $context['owner_chat_id'] = $session->chat_id;
            }

            return array_merge($context, $extra);
        }

        private function markSessionNotSending(?TelegramFilestoreSession $session, string $result): void
        {
            if (!$session) {
                return;
            }

            try {
                TelegramFilestoreSession::query()
                    ->where('id', $session->id)
                    ->update([
                        'is_sending' => 0,
                        'sending_finished_at' => now(),
                    ]);

                Log::info('telegram_filestore_send_session_released', $this->buildLogContext($session, [
                    'result' => $result,
                ]));
            } catch (Throwable $e) {
                Log::error('telegram_filestore_send_session_release_failed', $this->buildLogContext($session, [
                    'result' => $result,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        private function formatRateLimitedNotice(?int $retryAfter): string
        {
            if ($retryAfter === null || $retryAfter <= 0) {
                return 'Telegram 目前限流，已暫停本次傳送，請稍後再試。';
            }

            if ($retryAfter < 60) {
                return 'Telegram 目前限流，已暫停本次傳送，請約 ' . $retryAfter . ' 秒後再試。';
            }

            $minutes = (int) ceil($retryAfter / 60);

            return 'Telegram 目前限流，已暫停本次傳送，請約 ' . $minutes . ' 分鐘後再試。';
        }

        private function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): bool
        {
            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }

            if ($parseMode !== null && $parseMode !== '') {
                $payload['parse_mode'] = $parseMode;
                $payload['disable_web_page_preview'] = true;
            }

            $result = $this->postTelegramMethod('sendMessage', $payload, [
                'target_chat_id' => $chatId,
                'message_kind' => 'filestore_notice',
            ]);

            return $result['ok'];
        }

        /**
         * @return array{source_file_row_id:int,file_type:string,file_id:string,file_name:?string}|null
         */
        private function resolveSendableFile(TelegramFilestoreSession $session, TelegramFilestoreFile $sourceFile): ?array
        {
            if ($this->usesRestoreTargetFileIds()) {
                $restoreFile = $this->getRestoreFileMapForSession($session)[(int) $sourceFile->id] ?? null;
                $targetFileId = trim((string) ($restoreFile['file_id'] ?? ''));

                if ($targetFileId === '') {
                    return null;
                }

                return [
                    'source_file_row_id' => (int) $sourceFile->id,
                    'file_type' => (string) ($restoreFile['file_type'] ?? $sourceFile->file_type ?? 'document'),
                    'file_id' => $targetFileId,
                    'file_name' => $restoreFile['file_name'] ?? $sourceFile->file_name,
                ];
            }

            $sourceFileId = trim((string) ($sourceFile->file_id ?? ''));
            if ($sourceFileId === '') {
                return null;
            }

            return [
                'source_file_row_id' => (int) $sourceFile->id,
                'file_type' => (string) ($sourceFile->file_type ?? 'document'),
                'file_id' => $sourceFileId,
                'file_name' => $sourceFile->file_name,
            ];
        }

        /**
         * @return array<int, array{file_id:string,file_type:string,file_name:?string}>
         */
        private function getRestoreFileMapForSession(TelegramFilestoreSession $session): array
        {
            $targetBotUsername = strtolower($this->currentBotUsername());
            $cacheKey = $session->id . '|' . $targetBotUsername;

            if (isset($this->restoreFileMapCache[$cacheKey])) {
                return $this->restoreFileMapCache[$cacheKey];
            }

            $rows = TelegramFilestoreRestoreFile::query()
                ->select([
                    'telegram_filestore_restore_files.source_file_row_id',
                    'telegram_filestore_restore_files.target_file_id',
                    'telegram_filestore_restore_files.file_type',
                    'telegram_filestore_restore_files.file_name',
                ])
                ->join(
                    'telegram_filestore_restore_sessions',
                    'telegram_filestore_restore_sessions.id',
                    '=',
                    'telegram_filestore_restore_files.restore_session_id'
                )
                ->where('telegram_filestore_restore_sessions.source_session_id', $session->id)
                ->whereRaw('LOWER(telegram_filestore_restore_sessions.target_bot_username) = ?', [$targetBotUsername])
                ->where('telegram_filestore_restore_files.status', 'synced')
                ->whereNotNull('telegram_filestore_restore_files.target_file_id')
                ->orderByDesc('telegram_filestore_restore_sessions.id')
                ->orderByDesc('telegram_filestore_restore_files.id')
                ->get();

            $map = [];
            foreach ($rows as $row) {
                $sourceFileRowId = (int) ($row->source_file_row_id ?? 0);
                $targetFileId = trim((string) ($row->target_file_id ?? ''));

                if ($sourceFileRowId <= 0 || $targetFileId === '' || isset($map[$sourceFileRowId])) {
                    continue;
                }

                $map[$sourceFileRowId] = [
                    'file_id' => $targetFileId,
                    'file_type' => (string) ($row->file_type ?? 'document'),
                    'file_name' => $row->file_name,
                ];
            }

            $this->restoreFileMapCache[$cacheKey] = $map;

            return $map;
        }

        private function usesRestoreTargetFileIds(): bool
        {
            return app(TelegramFilestoreBotProfileResolver::class)->normalize($this->botProfile)
                === TelegramFilestoreBotProfileResolver::BACKUP_RESTORE;
        }

        private function currentBotUsername(): string
        {
            return (string) (app(TelegramFilestoreBotProfileResolver::class)->resolve($this->botProfile)['username'] ?? '');
        }
    }
