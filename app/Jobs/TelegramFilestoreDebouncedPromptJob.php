<?php

    namespace App\Jobs;

    use App\Models\TelegramFilestoreFile;
    use App\Models\TelegramFilestoreSession;
    use App\Services\TelegramFilestoreBotProfileResolver;
    use App\Services\TelegramFilestoreCloseUploadPromptService;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Carbon;
    use Throwable;

    class TelegramFilestoreDebouncedPromptJob implements ShouldQueue
    {
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        public const QUEUE_NAME = 'telegram_filestore_prompt';

        private int $sessionId;
        private int $chatId;
        private string $botProfile;

        private const DEBOUNCE_SECONDS = 5;
        private const CLOSE_UPLOAD_PROMPT_DEDUP_SECONDS = 3;
        private const CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES = 180;
        private const TRANSIENT_RETRY_SECONDS = 1;

        /**
         * 同一 session debounce job 的互斥鎖（秒）
         */
        private const SESSION_JOB_LOCK_SECONDS = 15;

        public int $timeout = 60;
        public int $tries = 3;

        public function __construct(
            int $sessionId,
            int $chatId,
            string $botProfile = TelegramFilestoreBotProfileResolver::FILESTORE
        )
        {
            $this->sessionId = $sessionId;
            $this->chatId = $chatId;
            $this->botProfile = app(TelegramFilestoreBotProfileResolver::class)->normalize($botProfile);
            $this->onQueue(self::QUEUE_NAME);
        }

        public function handle(): void
        {
            $lastKey = $this->getDebounceLastFileAtCacheKey($this->sessionId);
            $lastTs = Cache::get($lastKey);

            if ($lastTs === null) {
                return;
            }

            $lastTsInt = (int)$lastTs;
            if ($lastTsInt <= 0) {
                return;
            }

            $nowTs = now()->getTimestamp();
            $diff = $nowTs - $lastTsInt;

            if ($diff < self::DEBOUNCE_SECONDS) {
                $delay = self::DEBOUNCE_SECONDS - $diff;
                if ($delay < 1) {
                    $delay = 1;
                }

                self::dispatch($this->sessionId, $this->chatId, $this->botProfile)
                    ->delay(now()->addSeconds($delay));

                return;
            }

            $lockKey = $this->getSessionJobLockKey($this->sessionId);
            $locked = Cache::add($lockKey, 1, now()->addSeconds(self::SESSION_JOB_LOCK_SECONDS));
            if (!$locked) {
                $this->scheduleRetry(self::TRANSIENT_RETRY_SECONDS, 'session_lock_busy');
                return;
            }

            try {
                $session = TelegramFilestoreSession::query()
                    ->where('id', $this->sessionId)
                    ->first();

                if (!$session) {
                    return;
                }

                if ((string)$session->status !== 'uploading') {
                    return;
                }

                if (trim((string)($session->source_token ?? '')) !== '') {
                    return;
                }

                $fileCount = (int)TelegramFilestoreFile::query()
                    ->where('session_id', $this->sessionId)
                    ->count();

                if ($fileCount <= 0) {
                    return;
                }

                $latestFileAt = $this->getLatestFileCreatedAt($this->sessionId);
                if (!$this->shouldRefreshPrompt($session, $latestFileAt)) {
                    return;
                }

                $preferFreshMessage = app(TelegramFilestoreCloseUploadPromptService::class)
                    ->shouldPreferFreshPromptMessage($session);

                $allowedToSend = $this->markCloseUploadPromptIfAllowed($this->sessionId);
                if (!$allowedToSend) {
                    $this->scheduleRetry(self::TRANSIENT_RETRY_SECONDS, 'prompt_dedup_window');
                    return;
                }

                $result = app(TelegramFilestoreCloseUploadPromptService::class)->sendOrRefreshPrompt(
                    $this->sessionId,
                    $this->chatId,
                    $this->botProfile,
                    $preferFreshMessage
                );

                Log::info('telegram_filestore_close_prompt_dispatched', [
                    'session_id' => $this->sessionId,
                    'chat_id' => $this->chatId,
                    'action' => $result['action'],
                    'message_id' => $result['message_id'],
                    'prefer_fresh_message' => $preferFreshMessage,
                ]);
            } finally {
                Cache::forget($lockKey);
            }
        }

        private function getDebounceLastFileAtCacheKey(int $sessionId): string
        {
            return 'filestore_debounce_last_file_at_' . $sessionId;
        }

        private function getSessionJobLockKey(int $sessionId): string
        {
            return 'filestore_debounce_job_lock_' . $sessionId;
        }

        private function scheduleRetry(int $delaySeconds, string $reason): void
        {
            if ($delaySeconds < 1) {
                $delaySeconds = 1;
            }

            self::dispatch($this->sessionId, $this->chatId, $this->botProfile)
                ->delay(now()->addSeconds($delaySeconds));

            Log::info('telegram_filestore_close_prompt_retry_scheduled', [
                'session_id' => $this->sessionId,
                'chat_id' => $this->chatId,
                'reason' => $reason,
                'delay_seconds' => $delaySeconds,
            ]);
        }

        private function shouldRefreshPrompt(TelegramFilestoreSession $session, ?Carbon $latestFileAt): bool
        {
            if ($latestFileAt === null) {
                return false;
            }

            if ($this->getCloseUploadPromptMessageId($this->sessionId) === null) {
                return true;
            }

            $lastPromptedAt = $this->toCarbon($session->close_upload_prompted_at);
            if ($lastPromptedAt === null) {
                return true;
            }

            return $latestFileAt->greaterThan($lastPromptedAt);
        }

        private function getLatestFileCreatedAt(int $sessionId): ?Carbon
        {
            $value = TelegramFilestoreFile::query()
                ->where('session_id', $sessionId)
                ->max('created_at');

            return $this->toCarbon($value);
        }

        private function toCarbon(mixed $value): ?Carbon
        {
            if ($value instanceof Carbon) {
                return $value->copy();
            }

            $normalized = trim((string) $value);
            if ($normalized === '') {
                return null;
            }

            try {
                return Carbon::parse($normalized);
            } catch (Throwable) {
                return null;
            }
        }

        private function getCloseUploadPromptMessageCacheKey(int $sessionId): string
        {
            return 'filestore_close_upload_prompt_message_id_' . $sessionId;
        }

        private function rememberCloseUploadPromptMessageId(int $sessionId, int $messageId): void
        {
            $key = $this->getCloseUploadPromptMessageCacheKey($sessionId);
            Cache::put($key, $messageId, now()->addMinutes(self::CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES));
        }

        private function getCloseUploadPromptMessageId(int $sessionId): ?int
        {
            $key = $this->getCloseUploadPromptMessageCacheKey($sessionId);
            $value = Cache::get($key);

            if ($value === null) {
                return null;
            }

            return (int)$value;
        }

        private function forgetCloseUploadPromptMessageId(int $sessionId): void
        {
            $key = $this->getCloseUploadPromptMessageCacheKey($sessionId);
            Cache::forget($key);
        }

        private function markCloseUploadPromptIfAllowed(int $sessionId): bool
        {
            try {
                $ok = false;

                DB::transaction(function () use ($sessionId, &$ok) {
                    $fresh = TelegramFilestoreSession::query()
                        ->where('id', $sessionId)
                        ->lockForUpdate()
                        ->first();

                    if (!$fresh) {
                        $ok = false;
                        return;
                    }

                    if ((string)$fresh->status !== 'uploading') {
                        $ok = false;
                        return;
                    }

                    $lastAt = $fresh->close_upload_prompted_at;

                    if ($lastAt) {
                        $diffSeconds = now()->diffInSeconds($lastAt);
                        if ($diffSeconds < self::CLOSE_UPLOAD_PROMPT_DEDUP_SECONDS) {
                            $ok = false;
                            return;
                        }
                    }

                    $fresh->close_upload_prompted_at = now();
                    $fresh->save();

                    $ok = true;
                });

                return $ok;
            } catch (Throwable $e) {
                Log::error('telegram_filestore_mark_prompt_failed', [
                    'session_id' => $sessionId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                return false;
            }
        }

        private function countFilesByType(int $sessionId): array
        {
            $rows = TelegramFilestoreFile::query()
                ->select('file_type', DB::raw('COUNT(*) as total'))
                ->where('session_id', $sessionId)
                ->groupBy('file_type')
                ->get();

            $result = [
                'photo' => 0,
                'video' => 0,
                'document' => 0,
                'other' => 0,
            ];

            foreach ($rows as $row) {
                $type = (string)($row->file_type ?? '');
                $total = (int)($row->total ?? 0);

                if ($type === 'photo') {
                    $result['photo'] = $total;
                    continue;
                }

                if ($type === 'video') {
                    $result['video'] = $total;
                    continue;
                }

                if ($type === 'document') {
                    $result['document'] = $total;
                    continue;
                }

                if ($type === 'other') {
                    $result['other'] = $total;
                    continue;
                }
            }

            return $result;
        }

        private function buildCloseUploadPromptText(array $counts): string
        {
            $lines = [];
            $video = (int)($counts['video'] ?? 0);
            $photo = (int)($counts['photo'] ?? 0);
            $doc = (int)($counts['document'] ?? 0) + (int)($counts['other'] ?? 0);

            if ($video > 0) {
                $lines[] = "影片：{$video}";
            }
            if ($photo > 0) {
                $lines[] = "圖片：{$photo}";
            }
            if ($doc > 0) {
                $lines[] = "檔案：{$doc}";
            }

            if (!empty($lines)) {
                return implode('　', $lines) . "\n是否結束上傳？";
            }

            return "是否結束上傳？";
        }

        private function buildCloseUploadPromptKeyboard(): array
        {
            return [
                'inline_keyboard' => [
                    [
                        ['text' => '結束上傳', 'callback_data' => 'filestore_close_upload'],
                        ['text' => '繼續上傳', 'callback_data' => 'filestore_continue_upload'],
                    ],
                    [
                        ['text' => '取消本次上傳', 'callback_data' => 'filestore_cancel_upload'],
                    ],
                ],
            ];
        }

        private function sendMessageReturningMessageId(int $chatId, string $text, array $replyMarkup): ?int
        {
            $token = (string) (app(TelegramFilestoreBotProfileResolver::class)->resolve($this->botProfile)['token'] ?? '');
            if ($token === '') {
                return null;
            }

            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
            ];

            $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            if (!$resp->ok()) {
                return null;
            }

            $messageId = $resp->json('result.message_id');
            if ($messageId === null) {
                return null;
            }

            return (int)$messageId;
        }

        private function editMessageText(int $chatId, int $messageId, string $text, array $replyMarkup): bool
        {
            $token = (string) (app(TelegramFilestoreBotProfileResolver::class)->resolve($this->botProfile)['token'] ?? '');
            if ($token === '') {
                return false;
            }

            try {
                $payload = [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'text' => $text,
                    'reply_markup' => $replyMarkup,
                ];

                $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/editMessageText", $payload);

                return $resp->ok();
            } catch (Throwable $e) {
                Log::error('telegram_filestore_edit_message_failed', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                return false;
            }
        }
    }
