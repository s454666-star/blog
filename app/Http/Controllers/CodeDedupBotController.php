<?php
// app/Http/Controllers/CodeDedupBotController.php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use GuzzleHttp\Client;

    class CodeDedupBotController extends Controller
    {
        protected string $apiUrl;
        protected Client $http;

        public function __construct()
        {
            // Telegram Bot Token
            $token       = '7921552608:AAGsjaUR6huZaCpH9SBARpi5_cQ0LiUwEiQ';
            $this->apiUrl = "https://api.telegram.org/bot{$token}/";
            $this->http   = new Client(['base_uri' => $this->apiUrl]);
        }

        /**
         * 接收 Telegram webhook 呼叫
         */
        public function handle(Request $request)
        {
            $update = $request->all();

            // 只處理來自 message 的文字訊息
            if (empty($update['message']['text'])) {
                return response('ok', 200);
            }

            $chatId    = $update['message']['chat']['id'];
            $messageId = $update['message']['message_id'];
            $text      = trim($update['message']['text']);

            // 檢查是否重複
            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->where('text', $text)
                ->first();

            if ($existing) {
                // 重複：回覆提醒
                $firstTime = date('Y-m-d H:i:s', strtotime($existing->created_at));
                $reply = "❗️ 重複訊息偵測：您在 {$firstTime} 已經說過：\n“{$text}”";
                $this->sendMessage($chatId, $reply);
            } else {
                // 不重複：回原文，並存資料庫
                $this->sendMessage($chatId, $text);
                DB::table('dialogues')->insert([
                    'chat_id'    => $chatId,
                    'message_id' => $messageId,
                    'text'       => $text,
                    'created_at' => now(),
                ]);
            }

            return response('ok', 200);
        }

        /**
         * 呼叫 Telegram sendMessage API
         */
        protected function sendMessage(int $chatId, string $text): void
        {
            $this->http->post('sendMessage', [
                'json' => [
                    'chat_id' => $chatId,
                    'text'    => $text,
                ],
            ]);
        }
    }
