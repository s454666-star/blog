<?php

namespace App\Http\Controllers;

use App\Models\TelegramFilestoreFile;
use App\Models\TelegramFilestoreSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TelegramFilestoreBotController extends Controller
{
    private const TOKEN_PREFIX = 'filestoebot_';

    public function webhook(Request $request)
    {
        $update = $request->all();

        Log::info('telegram_filestore_update', $update);

        // 1) 處理 callback_query（按鈕）
        if (!empty($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return response()->json(['ok' => true]);
        }

        // 2) 僅處理 message
        if (!isset($update['message'])) {
            return response()->json(['ok' => true]);
        }

        $message = $update['message'];
        $chatId = (int)($message['chat']['id'] ?? 0);
        $username = $message['chat']['username'] ?? null;
        $messageId = (int)($message['message_id'] ?? 0);

        if ($chatId <= 0 || $messageId <= 0) {
            return response()->json(['ok' => true]);
        }

        // 3) 文字訊息：/start 或 token
        if (isset($message['text'])) {
            $text = trim((string)$message['text']);

            if ($text === '/start') {
                $this->sendMessage(
                    $chatId,
                    "Filestore Bot 已啟動\n\n請直接傳送圖片、影片或檔案。\n上傳完成後按「結束上傳」即可產生分享代碼。"
                );
                return response()->json(['ok' => true]);
            }

            if ($this->isPublicToken($text)) {
                $this->sendSessionFilesByToken($chatId, $text);
                return response()->json(['ok' => true]);
            }

            // 其他文字不回覆（避免干擾）
            return response()->json(['ok' => true]);
        }

        // 4) 檔案類訊息：photo/document/video
        $filePayload = $this->extractTelegramFilePayload($message);

        if ($filePayload === null) {
            return response()->json(['ok' => true]);
        }

        DB::transaction(function () use ($chatId, $username, $messageId, $filePayload, $message) {
            $session = $this->getOrCreateUploadingSession($chatId, $username);

            $exists = TelegramFilestoreFile::query()
                                           ->where('session_id', $session->id)
                                           ->where('file_unique_id', $filePayload['file_unique_id'])
                                           ->exists();

            if (!$exists) {
                TelegramFilestoreFile::query()->create([
                                                           'session_id' => $session->id,
                                                           'chat_id' => $chatId,
                                                           'message_id' => $messageId,
                                                           'file_id' => $filePayload['file_id'],
                                                           'file_unique_id' => $filePayload['file_unique_id'],
                                                           'file_name' => $filePayload['file_name'],
                                                           'mime_type' => $filePayload['mime_type'],
                                                           'file_size' => (int)($filePayload['file_size'] ?? 0),
                                                           'file_type' => $filePayload['file_type'],
                                                           'raw_payload' => $message,
                                                           'created_at' => now(),
                                                       ]);

                $session->total_files = (int)$session->total_files + 1;
                $session->total_size = (int)$session->total_size + (int)($filePayload['file_size'] ?? 0);
                $session->save();
            }
        });

        // 不回「已收到圖片/影片」等文字，只顯示結束按鈕
        $this->askCloseUpload($chatId);

        return response()->json(['ok' => true]);
    }

    private function handleCallback(array $callbackQuery): void
    {
        $data = (string)($callbackQuery['data'] ?? '');
        $chatId = (int)($callbackQuery['message']['chat']['id'] ?? 0);
        $callbackQueryId = (string)($callbackQuery['id'] ?? '');

        if ($chatId <= 0) {
            return;
        }

        if ($callbackQueryId !== '') {
            $this->answerCallbackQuery($callbackQueryId);
        }

        if ($data === 'filestore_close_upload') {
            $this->closeSessionAndReturnToken($chatId);
            return;
        }

        if ($data === 'filestore_continue_upload') {
            // 使用者選擇繼續，不多回話（避免噪音）
            return;
        }
    }

    private function getOrCreateUploadingSession(int $chatId, ?string $username): TelegramFilestoreSession
    {
        $session = TelegramFilestoreSession::query()
                                           ->where('chat_id', $chatId)
                                           ->where('status', 'uploading')
                                           ->orderByDesc('id')
                                           ->first();

        if ($session) {
            if ($username !== null && $session->username !== $username) {
                $session->username = $username;
                $session->save();
            }
            return $session;
        }

        return TelegramFilestoreSession::query()->create([
                                                             'chat_id' => $chatId,
                                                             'username' => $username,
                                                             'encrypt_token' => null,
                                                             'public_token' => null,
                                                             'status' => 'uploading',
                                                             'total_files' => 0,
                                                             'total_size' => 0,
                                                             'created_at' => now(),
                                                             'closed_at' => null,
                                                         ]);
    }

    private function closeSessionAndReturnToken(int $chatId): void
    {
        $session = TelegramFilestoreSession::query()
                                           ->where('chat_id', $chatId)
                                           ->where('status', 'uploading')
                                           ->orderByDesc('id')
                                           ->first();

        if (!$session) {
            $this->sendMessage($chatId, "目前沒有進行中的上傳。");
            return;
        }

        $fileCount = TelegramFilestoreFile::query()
                                          ->where('session_id', $session->id)
                                          ->count();

        if ($fileCount <= 0) {
            $this->sendMessage($chatId, "這個上傳尚未收到任何檔案。");
            return;
        }

        DB::transaction(function () use ($session) {
            $token = $this->generateUniquePublicToken();

            $session->public_token = $token;
            $session->encrypt_token = $this->hashForDb($token);
            $session->status = 'closed';
            $session->closed_at = now();
            $session->save();
        });

        $this->sendMessage(
            $chatId,
            "已結束上傳 ✅\n\n分享代碼：\n{$session->public_token}\n\n任何人把這段代碼貼給我，就可以取得你上傳的檔案。"
        );
    }

    private function sendSessionFilesByToken(int $chatId, string $publicToken): void
    {
        $session = TelegramFilestoreSession::query()
                                           ->where('public_token', $publicToken)
                                           ->where('status', 'closed')
                                           ->first();

        if (!$session) {
            $this->sendMessage($chatId, "找不到這個代碼對應的檔案。");
            return;
        }

        $files = TelegramFilestoreFile::query()
                                      ->where('session_id', $session->id)
                                      ->orderBy('id')
                                      ->get();

        if ($files->isEmpty()) {
            $this->sendMessage($chatId, "這個代碼沒有任何檔案。");
            return;
        }

        $this->sendMessage(
            $chatId,
            "正在傳送檔案（共 {$files->count()} 個）…"
        );

        foreach ($files as $file) {
            $this->sendFileByType($chatId, $file->file_type, $file->file_id, $file->file_name);
        }

        $this->sendMessage($chatId, "已全部傳送完成 ✅");
    }

    private function askCloseUpload(int $chatId): void
    {
        $this->sendMessage(
            $chatId,
            "是否結束上傳？",
            [
                'inline_keyboard' => [
                    [
                        ['text' => '結束上傳', 'callback_data' => 'filestore_close_upload'],
                        ['text' => '繼續上傳', 'callback_data' => 'filestore_continue_upload'],
                    ],
                ],
            ]
        );
    }

    private function extractTelegramFilePayload(array $message): ?array
    {
        if (isset($message['photo']) && is_array($message['photo']) && count($message['photo']) > 0) {
            $photo = end($message['photo']);

            if (!isset($photo['file_id'], $photo['file_unique_id'])) {
                return null;
            }

            return [
                'file_type' => 'photo',
                'file_id' => (string)$photo['file_id'],
                'file_unique_id' => (string)$photo['file_unique_id'],
                'file_name' => null,
                'mime_type' => null,
                'file_size' => (int)($photo['file_size'] ?? 0),
            ];
        }

        if (isset($message['video']) && is_array($message['video'])) {
            $video = $message['video'];

            if (!isset($video['file_id'], $video['file_unique_id'])) {
                return null;
            }

            return [
                'file_type' => 'video',
                'file_id' => (string)$video['file_id'],
                'file_unique_id' => (string)$video['file_unique_id'],
                'file_name' => $video['file_name'] ?? null,
                'mime_type' => $video['mime_type'] ?? null,
                'file_size' => (int)($video['file_size'] ?? 0),
            ];
        }

        if (isset($message['document']) && is_array($message['document'])) {
            $doc = $message['document'];

            if (!isset($doc['file_id'], $doc['file_unique_id'])) {
                return null;
            }

            return [
                'file_type' => 'document',
                'file_id' => (string)$doc['file_id'],
                'file_unique_id' => (string)$doc['file_unique_id'],
                'file_name' => $doc['file_name'] ?? null,
                'mime_type' => $doc['mime_type'] ?? null,
                'file_size' => (int)($doc['file_size'] ?? 0),
            ];
        }

        return null;
    }

    private function sendFileByType(int $chatId, string $fileType, string $fileId, ?string $fileName): void
    {
        $token = (string)config('telegram.filestore_bot_token');
        if ($token === '') {
            return;
        }

        if ($fileType === 'photo') {
            Http::post("https://api.telegram.org/bot{$token}/sendPhoto", [
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

            Http::post("https://api.telegram.org/bot{$token}/sendVideo", $payload);
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

            Http::post("https://api.telegram.org/bot{$token}/sendDocument", $payload);
            return;
        }

        Http::post("https://api.telegram.org/bot{$token}/sendDocument", [
            'chat_id' => $chatId,
            'document' => $fileId,
        ]);
    }

    private function isPublicToken(string $text): bool
    {
        return Str::startsWith($text, self::TOKEN_PREFIX) && strlen($text) > strlen(self::TOKEN_PREFIX);
    }

    private function generateUniquePublicToken(): string
    {
        do {
            $random = Str::lower(Str::random(24));
            $token = self::TOKEN_PREFIX . $random;

            $exists = TelegramFilestoreSession::query()
                                              ->where('public_token', $token)
                                              ->exists();
        } while ($exists);

        return $token;
    }

    private function hashForDb(string $publicToken): string
    {
        return hash('sha256', $publicToken);
    }

    private function answerCallbackQuery(string $callbackQueryId): void
    {
        $token = (string)config('telegram.filestore_bot_token');
        if ($token === '') {
            return;
        }

        Http::post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
            'callback_query_id' => $callbackQueryId,
        ]);
    }

    private function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
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

        Http::post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
    }
}
