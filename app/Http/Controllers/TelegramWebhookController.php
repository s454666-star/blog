<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Http;

    class TelegramWebhookController extends Controller
    {
        public function handle(Request $request)
        {
            $update = $request->all();

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
            $token = env('MYSTAR_TELEGRAM_SECRET');

            Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML'
            ]);
        }
    }
