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
        private const BATCH_SLEEP_MICROSECONDS = 1500000;

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

            $mediaCount = (int)TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->whereIn('file_type', ['photo', 'video'])
                ->count();

            $documentCount = (int)TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->where('file_type', 'document')
                ->count();

            $infoLines = [];
            $infoLines[] = "開始傳送檔案（共 {$totalCount} 個）…";
            if ($mediaCount > 0) {
                $infoLines[] = "照片/影片：每 " . self::MEDIA_GROUP_BATCH_SIZE . " 個一組批次傳送（共 {$mediaCount} 個）";
            }
            if ($documentCount > 0) {
                $infoLines[] = "文件：因 Telegram 限制，會逐筆傳送（共 {$documentCount} 個）";
            }

            $this->sendMessage($this->targetChatId, implode("\n", $infoLines));

            $sentCount = 0;

            $mediaBuffer = [];

            TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->orderBy('id')
                ->chunkById(200, function ($files) use (&$sentCount, $totalCount, &$mediaBuffer) {
                    foreach ($files as $file) {
                        $type = (string)$file->file_type;

                        if ($type === 'photo' || $type === 'video') {
                            $mediaBuffer[] = $file;

                            if (count($mediaBuffer) >= self::MEDIA_GROUP_BATCH_SIZE) {
                                $this->sendMediaGroupBatch($this->targetChatId, $mediaBuffer);
                                $sentCount = $sentCount + count($mediaBuffer);
                                $mediaBuffer = [];

                                if ($sentCount < $totalCount) {
                                    usleep(self::BATCH_SLEEP_MICROSECONDS);
                                }
                            }

                            continue;
                        }

                        if ($type === 'document') {
                            $this->sendFileByType($this->targetChatId, $type, (string)$file->file_id, $file->file_name);
                            $sentCount = $sentCount + 1;

                            if ($sentCount < $totalCount) {
                                usleep(self::BATCH_SLEEP_MICROSECONDS);
                            }

                            continue;
                        }

                        $this->sendFileByType($this->targetChatId, $type, (string)$file->file_id, $file->file_name);
                        $sentCount = $sentCount + 1;

                        if ($sentCount < $totalCount) {
                            usleep(self::BATCH_SLEEP_MICROSECONDS);
                        }
                    }
                }, 'id');

            if (!empty($mediaBuffer)) {
                $this->sendMediaGroupBatch($this->targetChatId, $mediaBuffer);
                $sentCount = $sentCount + count($mediaBuffer);
                $mediaBuffer = [];
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
         * 用 sendMediaGroup 批次傳送照片/影片（最多 10 個）
         * 重要：這裡會檢查 Telegram 回應，失敗會寫 log，並 fallback 單張送（確保一定送得出去）
         */
        private function sendMediaGroupBatch(int $chatId, array $files): void
        {
            if (empty($files)) {
                return;
            }

            $token = (string)config('telegram.filestore_bot_token');
            if ($token === '') {
                return;
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
                return;
            }

            $response = $this->postSendMediaGroupAsJson($token, $chatId, $media);

            if (!$this->isTelegramOk($response)) {
                $responseForm = $this->postSendMediaGroupAsForm($token, $chatId, $media);

                if (!$this->isTelegramOk($responseForm)) {
                    $err1 = $this->safeResponseBody($response);
                    $err2 = $this->safeResponseBody($responseForm);

                    Log::error('telegram_send_media_group_failed', [
                        'chat_id' => $chatId,
                        'media_count' => count($media),
                        'json_status' => $response ? $response->status() : null,
                        'json_body' => $err1,
                        'form_status' => $responseForm ? $responseForm->status() : null,
                        'form_body' => $err2,
                    ]);

                    $this->sendMessage(
                        $chatId,
                        "相簿批次傳送失敗，改用逐筆傳送（請看 log 查原因）。"
                    );

                    foreach ($files as $file) {
                        $type = (string)$file->file_type;
                        if ($type === 'photo' || $type === 'video') {
                            $this->sendFileByType($chatId, $type, (string)$file->file_id, $file->file_name);
                            usleep(300000);
                        }
                    }
                }
            }
        }

        private function postSendMediaGroupAsJson(string $token, int $chatId, array $media): ?Response
        {
            try {
                return Http::timeout(60)
                    ->asJson()
                    ->post("https://api.telegram.org/bot{$token}/sendMediaGroup", [
                        'chat_id' => $chatId,
                        'media' => $media,
                    ]);
            } catch (\Throwable $e) {
                Log::error('telegram_send_media_group_json_exception', [
                    'chat_id' => $chatId,
                    'message' => $e->getMessage(),
                ]);
                return null;
            }
        }

        private function postSendMediaGroupAsForm(string $token, int $chatId, array $media): ?Response
        {
            try {
                return Http::timeout(60)
                    ->asForm()
                    ->post("https://api.telegram.org/bot{$token}/sendMediaGroup", [
                        'chat_id' => $chatId,
                        'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
                    ]);
            } catch (\Throwable $e) {
                Log::error('telegram_send_media_group_form_exception', [
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

        private function safeResponseBody(?Response $response): ?string
        {
            if (!$response) {
                return null;
            }

            try {
                return (string)$response->body();
            } catch (\Throwable $e) {
                return null;
            }
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
