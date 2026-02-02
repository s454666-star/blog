<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TelegramBotController extends Controller
{
    private function botToken(): string
    {
        return config('telegram.filestore_bot_token');
    }

    public function webhook(Request $request)
    {
        $update = $request->all();
        Log::info('telegram_filestore_update', $update);

        if (!isset($update['message'])) {
            return response()->json(['ok' => true]);
        }

        $message = $update['message'];
        $chatId  = $message['chat']['id'];

        if (isset($message['text'])) {
            $this->sendMessage($chatId, '收到文字');
        }

        if (isset($message['photo'])) {
            $photo = end($message['photo']);
            $this->handleFile($photo['file_id'], $chatId, 'photo');
        }

        if (isset($message['video'])) {
            $this->handleFile($message['video']['file_id'], $chatId, 'video');
        }

        if (isset($message['document'])) {
            $this->handleFile($message['document']['file_id'], $chatId, 'document');
        }

        return response()->json(['ok' => true]);
    }

    private function handleFile(string $fileId, int $chatId, string $type): void
    {
        // 1. 取得檔案路徑
        $fileInfo = Http::get(
            'https://api.telegram.org/bot' . $this->botToken() . '/getFile',
            ['file_id' => $fileId]
        )->json();

        $filePath = $fileInfo['result']['file_path'];
        $filename = basename($filePath);

        // 2. 下載檔案
        $fileContent = Http::get(
            'https://api.telegram.org/file/bot' . $this->botToken() . '/' . $filePath
        )->body();

        // 3. 存檔
        Storage::put('telegram/filestore/' . $filename, $fileContent);

        $this->sendMessage($chatId, "已收到 {$type}，檔案已儲存：{$filename}");
    }

    private function sendMessage(int $chatId, string $text): void
    {
        Http::post(
            'https://api.telegram.org/bot' . $this->botToken() . '/sendMessage',
            [
                'chat_id' => $chatId,
                'text'    => $text,
            ]
        );
    }
}
