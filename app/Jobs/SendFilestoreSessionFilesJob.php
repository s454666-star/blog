<?php

    namespace App\Jobs;

    use App\Models\TelegramFilestoreFile;
    use App\Models\TelegramFilestoreSession;
    use Illuminate\Bus\Queueable;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Http\Client\Response;
    use Illuminate\Queue\InteractsWithQueue;
    use Illuminate\Queue\SerializesModels;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;

    class SendFilestoreSessionFilesJob implements ShouldQueue
    {
        use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

        private int $sessionId;
        private int $targetChatId;

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

        public function __construct(int $sessionId, int $targetChatId)
        {
            $this->sessionId = $sessionId;
            $this->targetChatId = $targetChatId;
            $this->onQueue('telegram_filestore');
        }

        public function handle(): void
        {
            $session = TelegramFilestoreSession::query()
                ->where('id', $this->sessionId)
                ->where('status', 'closed')
                ->first();

            if (!$session) {
                $this->sendMessage($this->targetChatId, "找不到這個代碼對應的檔案。");
                return;
            }

            $totalCount = (int)TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->count();

            if ($totalCount <= 0) {
                $this->sendMessage($this->targetChatId, "這個代碼沒有任何檔案。");
                return;
            }

            $mediaFiles = TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->whereIn('file_type', ['photo', 'video'])
                ->orderBy('id')
                ->get();

            $documentFiles = TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->where('file_type', 'document')
                ->orderBy('id')
                ->get();

            $otherFiles = TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->whereNotIn('file_type', ['photo', 'video', 'document'])
                ->orderBy('id')
                ->get();

            $mediaCount = (int)$mediaFiles->count();
            $documentCount = (int)$documentFiles->count();
            $otherCount = (int)$otherFiles->count();

            $infoLines = [];
            $infoLines[] = "開始傳送檔案（共 {$totalCount} 個）（batch-album-v3）…";
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

            /**
             * 1) 先送 photo/video：用 sendMediaGroup 每 10 個一組
             */
            if ($mediaCount > 0) {
                $chunks = array_chunk($mediaFiles->all(), self::MEDIA_GROUP_BATCH_SIZE);

                foreach ($chunks as $index => $chunkFiles) {
                    $ok = $this->sendMediaGroupBatch($this->targetChatId, $chunkFiles);

                    if (!$ok) {
                        $this->sendMessage(
                            $this->targetChatId,
                            "相簿批次傳送失敗，改用逐筆傳送（請看 log 查原因）。"
                        );

                        foreach ($chunkFiles as $file) {
                            $this->sendFileByType(
                                $this->targetChatId,
                                (string)$file->file_type,
                                (string)$file->file_id,
                                $file->file_name
                            );
                            usleep(250000);
                        }
                    }

                    if ($index < count($chunks) - 1) {
                        usleep(self::BATCH_SLEEP_MICROSECONDS);
                    }
                }
            }

            /**
             * 2) 再送 document：逐筆
             */
            if ($documentCount > 0) {
                foreach ($documentFiles as $i => $file) {
                    $this->sendFileByType(
                        $this->targetChatId,
                        (string)$file->file_type,
                        (string)$file->file_id,
                        $file->file_name
                    );

                    if ($i < $documentCount - 1) {
                        usleep(self::BATCH_SLEEP_MICROSECONDS);
                    }
                }
            }

            /**
             * 3) 其他型別：逐筆（保底）
             */
            if ($otherCount > 0) {
                foreach ($otherFiles as $i => $file) {
                    $this->sendFileByType(
                        $this->targetChatId,
                        (string)$file->file_type,
                        (string)$file->file_id,
                        $file->file_name
                    );

                    if ($i < $otherCount - 1) {
                        usleep(self::BATCH_SLEEP_MICROSECONDS);
                    }
                }
            }

            $this->sendMessage($this->targetChatId, "已全部傳送完成 ✅");

            DB::transaction(function () use ($session) {
                $session->is_sending = 0;
                $session->sending_finished_at = now();
                $session->save();
            });
        }

        public function failed(\Throwable $e): void
        {
            $session = TelegramFilestoreSession::query()->where('id', $this->sessionId)->first();
            if ($session) {
                DB::transaction(function () use ($session) {
                    $session->is_sending = 0;
                    $session->save();
                });
            }

            $this->sendMessage($this->targetChatId, "傳送檔案時發生錯誤，請稍後再試。");
        }

        /**
         * sendMediaGroup 批次傳送照片/影片（最多 10 個）
         * 回傳 true 表示 Telegram ok=true
         */
        private function sendMediaGroupBatch(int $chatId, array $files): bool
        {
            $token = (string)config('telegram.filestore_bot_token');
            if ($token === '') {
                return false;
            }

            $media = [];
            foreach ($files as $file) {
                $type = (string)$file->file_type;
                $fileId = (string)$file->file_id;

                if ($type !== 'photo' && $type !== 'video') {
                    continue;
                }

                $item = [
                    'type' => $type,
                    'media' => $fileId,
                ];

                if ($type === 'video') {
                    $name = (string)($file->file_name ?? '');
                    if ($name !== '') {
                        $item['caption'] = $name;
                    }
                }

                $media[] = $item;
            }

            if (empty($media)) {
                return true;
            }

            $response = $this->postSendMediaGroup($token, $chatId, $media);

            if (!$this->isTelegramOk($response)) {
                Log::error('telegram_send_media_group_failed', [
                    'chat_id' => $chatId,
                    'media_count' => count($media),
                    'status' => $response ? $response->status() : null,
                    'body' => $response ? $response->body() : null,
                ]);
                return false;
            }

            return true;
        }

        private function postSendMediaGroup(string $token, int $chatId, array $media): ?Response
        {
            try {
                return Http::timeout(60)
                    ->asForm()
                    ->post("https://api.telegram.org/bot{$token}/sendMediaGroup", [
                        'chat_id' => $chatId,
                        'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
                    ]);
            } catch (\Throwable $e) {
                Log::error('telegram_send_media_group_exception', [
                    'chat_id' => $chatId,
                    'message' => $e->getMessage(),
                ]);
                return null;
            }
        }

        private function isTelegramOk(?Response $response): bool
        {
            if (!$response) {
                return false;
            }

            if (!$response->successful()) {
                return false;
            }

            $json = $response->json();
            if (!is_array($json)) {
                return false;
            }

            return (bool)($json['ok'] ?? false);
        }

        private function sendFileByType(int $chatId, string $fileType, string $fileId, ?string $fileName): void
        {
            $token = (string)config('telegram.filestore_bot_token');
            if ($token === '') {
                return;
            }

            $http = Http::timeout(60);

            if ($fileType === 'photo') {
                $http->post("https://api.telegram.org/bot{$token}/sendPhoto", [
                    'chat_id' => $chatId,
                    'photo' => $fileId,
                ]);
                return;
            }

            if ($fileType === 'video') {
                $payload = [
                    'chat_id' => $chatId,
                    'video' => $fileId,
                ];

                if ($fileName !== null && $fileName !== '') {
                    $payload['caption'] = $fileName;
                }

                $http->post("https://api.telegram.org/bot{$token}/sendVideo", $payload);
                return;
            }

            if ($fileType === 'document') {
                $payload = [
                    'chat_id' => $chatId,
                    'document' => $fileId,
                ];

                if ($fileName !== null && $fileName !== '') {
                    $payload['caption'] = $fileName;
                }

                $http->post("https://api.telegram.org/bot{$token}/sendDocument", $payload);
                return;
            }

            $http->post("https://api.telegram.org/bot{$token}/sendDocument", [
                'chat_id' => $chatId,
                'document' => $fileId,
            ]);
        }

        private function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): void
        {
            $token = (string)config('telegram.filestore_bot_token');
            if ($token === '') {
                return;
            }

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

            Http::timeout(60)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        }
    }
