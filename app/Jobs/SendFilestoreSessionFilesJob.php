<?php

namespace App\Jobs;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SendFilestoreSessionFilesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $sessionId;
    private int $targetChatId;

    public int $timeout = 900;
    public int $tries = 3;

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

        $totalCount = TelegramFilestoreFile::query()
                                           ->where('session_id', $session->id)
                                           ->count();

        if ($totalCount <= 0) {
            $this->sendMessage($this->targetChatId, "這個代碼沒有任何檔案。");
            return;
        }

        $this->sendMessage($this->targetChatId, "開始傳送檔案（共 {$totalCount} 個）…");

        $batchSize = 10;
        $sentCount = 0;

        TelegramFilestoreFile::query()
                             ->where('session_id', $session->id)
                             ->orderBy('id')
                             ->chunkById($batchSize, function ($files) use (&$sentCount, $totalCount) {
                                 foreach ($files as $file) {
                                     $this->sendFileByType($this->targetChatId, $file->file_type, $file->file_id, $file->file_name);
                                     $sentCount = $sentCount + 1;
                                 }

                                 if ($sentCount < $totalCount) {
                                     usleep(1500000);
                                 }
                             }, 'id');

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
