<?php
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
            $token       = '7921552608:AAGsjaUR6huZaCpH9SBARpi5_cQ0LiUwEiQ';
            $this->apiUrl = "https://api.telegram.org/bot{$token}/";
            $this->http   = new Client(['base_uri' => $this->apiUrl]);

            // （選擇性）每次啟動時也可在這裡確保指令已註冊
            // $this->http->post('setMyCommands', ['json'=>[ 'commands'=>[['command'=>'start','description'=>'列出本聊天的歷史對話'],],],]);
        }

        public function handle(Request $request)
        {
            $update = $request->all();

            // 只處理文字
            if (empty($update['message']['text'])) {
                return response('ok', 200);
            }

            $chatId = $update['message']['chat']['id'];
            $text   = trim($update['message']['text']);
            $msgId  = $update['message']['message_id'];

            // 1. /start：列出歷史對話
            if ($text === '/start') {
                // 拿最近 20 筆歷史
                $rows = DB::table('dialogues')
                    ->where('chat_id', $chatId)
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get(['text', 'created_at']);

                if ($rows->isEmpty()) {
                    $reply = "目前還沒有任何歷史對話。";
                } else {
                    // 倒序排列：最早在上面
                    $items = $rows->reverse()->map(function($r, $i){
                        $time = date('H:i', strtotime($r->created_at));
                        return sprintf("%02d. [%s] %s", $i+1, $time, $r->text);
                    })->join("\n");
                    $reply = "📜 歷史對話（最近 ".count($rows)." 筆）：\n" . $items;
                }
                $this->sendMessage($chatId, $reply);
                return response('ok', 200);
            }

            // 2. 其他文字：原本的去重邏輯
            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->where('text', $text)
                ->first();

            if ($existing) {
                $firstTime = date('Y-m-d H:i:s', strtotime($existing->created_at));
                $reply = "❗️ 重複訊息偵測：您在 {$firstTime} 已經說過：\n“{$text}”";
                $this->sendMessage($chatId, $reply);

            } else {
                $this->sendMessage($chatId, $text);
                DB::table('dialogues')->insert([
                    'chat_id'    => $chatId,
                    'message_id' => $msgId,
                    'text'       => $text,
                    'created_at' => now(),
                ]);
            }

            return response('ok', 200);
        }

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
