<?php

namespace App\Http\Controllers;

use App\Services\MzksrBotChatRecorder;
use App\Support\TelegramWebhookLogContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(private MzksrBotChatRecorder $chatRecorder)
    {
    }

    public function handle(Request $request)
    {
        $update = $request->all();

        Log::info('telegram_mystar_webhook_event', TelegramWebhookLogContext::fromUpdate($update, 'mystar_secure_bot'));

        try {
            $this->chatRecorder->recordFromUpdate($update);
        } catch (\Throwable $e) {
            Log::warning('mzksr_bot_chat_record_failed', [
                'error' => $e->getMessage(),
                'chat_id' => $update['message']['chat']['id'] ?? null,
            ]);
        }

        if (!isset($update['message']['text'])) {
            return response()->json(['status' => 'no text']);
        }

        $chatId = $update['message']['chat']['id'];
        $text = trim($update['message']['text']);

        if ($text === '夢之國') {
            $this->sendMessage($chatId, 'https://t.me/+wTLMiobPi6RiMjU1');
        }

        return response()->json(['status' => 'ok']);
    }

    private function sendMessage($chatId, $message)
    {
        $token = config('services.telegram.mystar_secret');

        if (empty($token)) {
            Log::error('Telegram bot token is empty. Check MYSTAR_TELEGRAM_SECRET in .env and config cache status.');
            return;
        }

        try {
            $response = Http::timeout(8)->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ]);

            if (!$response->successful()) {
                Log::error('Telegram sendMessage failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return;
            }

            Log::info('Telegram sendMessage success', [
                'body' => $response->json(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram sendMessage exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
