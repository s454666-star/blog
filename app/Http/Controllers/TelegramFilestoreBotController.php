<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class TelegramFilestoreBotController extends Controller
{
    public function webhook(Request $request)
    {
        $update = $request->all();

        Log::info('telegram_filestore_update', $update);

        if (!isset($update['message'])) {
            return response()->json(['ok' => true]);
        }

        $chatId = $update['message']['chat']['id'];

        Http::post(
            'https://api.telegram.org/bot' . config('telegram.filestore_bot_token') . '/sendMessage',
            [
                'chat_id' => $chatId,
                'text'    => 'Webhook OK，你的 chat_id = ' . $chatId,
            ]
        );

        return response()->json(['ok' => true]);
    }
}
