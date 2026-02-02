<?php

namespace App\Http\Controllers;

use App\Jobs\SendFilestoreSessionFilesJob;
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

        if (!empty($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return response()->json(['ok' => true]);
        }

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

        if (isset($message['text'])) {
            $text = trim((string)$message['text']);

            if ($text === '/start') {
                $this->sendMessage(
                    $chatId,
                    "Filestore Bot 已啟動\n\n請直接傳送圖片、影片或檔案。\n上傳完成後按「結束上傳」即可產生分享代碼。\n\n指令：\n/myfiles 查詢我的檔案\n/delete 刪除我上傳的檔案"
                );
                return response()->json(['ok' => true]);
            }

            if ($text === '/myfiles') {
                $this->handleMyFilesCommand($chatId);
                return response()->json(['ok' => true]);
            }

            if (Str::startsWith($text, '/delete')) {
                $this->handleDeleteCommand($chatId, $text);
                return response()->json(['ok' => true]);
            }

            if ($this->isPublicToken($text)) {
                $this->sendSessionFilesByToken($chatId, $text);
                return response()->json(['ok' => true]);
            }

            return response()->json(['ok' => true]);
        }

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
            return;
        }

        if (Str::startsWith($data, 'filestore_delete_pick:')) {
            $sessionId = (int)Str::after($data, 'filestore_delete_pick:');
            $this->askDeleteConfirm($chatId, $sessionId);
            return;
        }

        if (Str::startsWith($data, 'filestore_delete_confirm:')) {
            $sessionId = (int)Str::after($data, 'filestore_delete_confirm:');
            $this->deleteSessionOwnedByChat($chatId, $sessionId);
            return;
        }

        if (Str::startsWith($data, 'filestore_delete_cancel:')) {
            $this->sendMessage($chatId, "已取消刪除。");
            return;
        }
    }

    private function handleMyFilesCommand(int $chatId): void
    {
        $sessions = TelegramFilestoreSession::query()
                                            ->where('chat_id', $chatId)
                                            ->where('status', 'closed')
                                            ->orderByDesc('id')
                                            ->limit(30)
                                            ->get();

        if ($sessions->isEmpty()) {
            $this->sendMessage($chatId, "你目前沒有已結束上傳的檔案。");
            return;
        }

        $lines = [];
        $lines[] = "你的檔案清單（最多顯示 30 筆）：";
        $lines[] = "";

        foreach ($sessions as $s) {
            $token = $s->public_token ?? '(無 token)';
            $files = (int)$s->total_files;
            $size = $this->formatBytes((int)$s->total_size);
            $shareCount = (int)$s->share_count;
            $lastShared = $s->last_shared_at ? (string)$s->last_shared_at : '無';

            $lines[] = "代碼：{$token}";
            $lines[] = "檔案數：{$files}　總大小：{$size}";
            $lines[] = "被分享：{$shareCount} 次　上次分享：{$lastShared}";
            $lines[] = "";
        }

        $this->sendLongMessage($chatId, implode("\n", $lines));
        $this->sendMessage($chatId, "你也可以用 /delete 來刪除你上傳的檔案。");
    }

    private function handleDeleteCommand(int $chatId, string $text): void
    {
        $parts = preg_split('/\s+/', trim($text));
        $token = $parts[1] ?? null;

        if ($token !== null && $token !== '') {
            if (!$this->isPublicToken($token)) {
                $this->sendMessage($chatId, "格式不正確，請輸入 /delete filestoebot_xxx");
                return;
            }

            $session = TelegramFilestoreSession::query()
                                               ->where('public_token', $token)
                                               ->where('chat_id', $chatId)
                                               ->where('status', 'closed')
                                               ->first();

            if (!$session) {
                $this->sendMessage($chatId, "找不到你可刪除的代碼（只能刪除你自己上傳的）。");
                return;
            }

            $this->askDeleteConfirm($chatId, (int)$session->id);
            return;
        }

        $sessions = TelegramFilestoreSession::query()
                                            ->where('chat_id', $chatId)
                                            ->where('status', 'closed')
                                            ->orderByDesc('id')
                                            ->limit(20)
                                            ->get();

        if ($sessions->isEmpty()) {
            $this->sendMessage($chatId, "你目前沒有可刪除的檔案。");
            return;
        }

        $keyboard = [];
        foreach ($sessions as $s) {
            $tokenText = $s->public_token ?? ('session_' . $s->id);
            $keyboard[] = [
                ['text' => '刪除 ' . $tokenText, 'callback_data' => 'filestore_delete_pick:' . $s->id],
            ];
        }

        $this->sendMessage(
            $chatId,
            "請選擇要刪除的分享代碼：",
            ['inline_keyboard' => $keyboard]
        );
    }

    private function askDeleteConfirm(int $chatId, int $sessionId): void
    {
        $session = TelegramFilestoreSession::query()
                                           ->where('id', $sessionId)
                                           ->first();

        if (!$session) {
            $this->sendMessage($chatId, "找不到該筆資料。");
            return;
        }

        if ((int)$session->chat_id !== $chatId) {
            $this->sendMessage($chatId, "你只能刪除你自己上傳的檔案。");
            return;
        }

        $token = $session->public_token ?? '(無 token)';
        $count = (int)$session->total_files;
        $size = $this->formatBytes((int)$session->total_size);

        $this->sendMessage(
            $chatId,
            "確定要刪除？\n\n代碼：{$token}\n檔案數：{$count}\n總大小：{$size}\n\n刪除後代碼將失效，其他人也無法再取檔。",
            [
                'inline_keyboard' => [
                    [
                        ['text' => '確認刪除', 'callback_data' => 'filestore_delete_confirm:' . $sessionId],
                        ['text' => '取消', 'callback_data' => 'filestore_delete_cancel:' . $sessionId],
                    ],
                ],
            ]
        );
    }

    private function deleteSessionOwnedByChat(int $chatId, int $sessionId): void
    {
        $session = TelegramFilestoreSession::query()
                                           ->where('id', $sessionId)
                                           ->first();

        if (!$session) {
            $this->sendMessage($chatId, "找不到該筆資料。");
            return;
        }

        if ((int)$session->chat_id !== $chatId) {
            $this->sendMessage($chatId, "你只能刪除你自己上傳的檔案。");
            return;
        }

        $token = $session->public_token ?? '(無 token)';

        DB::transaction(function () use ($session) {
            $session->delete();
        });

        $this->sendMessage($chatId, "已刪除 ✅\n代碼：{$token}\n此代碼已失效。");
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
                                                             'share_count' => 0,
                                                             'created_at' => now(),
                                                             'closed_at' => null,
                                                             'last_shared_at' => null,
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
            "已結束上傳 ✅\n\n分享代碼：\n{$session->public_token}\n\n任何人把這段代碼貼給我，就可以取得你上傳的檔案。\n\n你也可以用 /myfiles 查詢、用 /delete 刪除。"
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

        $locked = false;

        DB::transaction(function () use ($session, &$locked) {
            $fresh = TelegramFilestoreSession::query()
                                             ->where('id', $session->id)
                                             ->lockForUpdate()
                                             ->first();

            if (!$fresh) {
                $locked = false;
                return;
            }

            if ((int)$fresh->is_sending === 1) {
                $locked = false;
                return;
            }

            $fresh->is_sending = 1;
            $fresh->sending_started_at = now();
            $fresh->sending_finished_at = null;
            $fresh->share_count = (int)$fresh->share_count + 1;
            $fresh->last_shared_at = now();
            $fresh->save();

            $locked = true;
        });

        if (!$locked) {
            $this->sendMessage($chatId, "正在傳送中，請稍候…");
            return;
        }

        $this->sendMessage($chatId, "已加入傳送佇列，準備開始傳送…");

        SendFilestoreSessionFilesJob::dispatch((int)$session->id, $chatId);
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

    private function sendLongMessage(int $chatId, string $text): void
    {
        $chunks = $this->splitByUtf8Bytes($text, 3800);
        foreach ($chunks as $chunk) {
            $this->sendMessage($chatId, $chunk);
        }
    }

    private function splitByUtf8Bytes(string $text, int $maxBytes): array
    {
        $result = [];
        $current = '';

        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $candidate = $current . $char;

            if (strlen($candidate) > $maxBytes) {
                if ($current !== '') {
                    $result[] = $current;
                }
                $current = $char;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float)$bytes;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
    }
}
