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
        }

        public function handle(Request $request)
        {
            $update = $request->all();

            // 只處理有文字的訊息
            if (empty($update['message']['text'])) {
                return response('ok', 200);
            }

            $chatId = $update['message']['chat']['id'];
            $text   = trim($update['message']['text']);
            $msgId  = $update['message']['message_id'];

            // 1. /start：列出最近 20 筆「代碼」歷史
            if ($text === '/start') {
                $rows = DB::table('dialogues')
                    ->where('chat_id', $chatId)
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get(['text', 'created_at']);

                if ($rows->isEmpty()) {
                    $reply = "目前還沒有任何歷史代碼。";
                } else {
                    $items = $rows->reverse()->map(function($r, $i){
                        $time = date('H:i', strtotime($r->created_at));
                        return sprintf("%02d. [%s] %s", $i+1, $time, $r->text);
                    })->join("\n");
                    $reply = "📜 歷史代碼（最近 ".count($rows)." 筆）：\n" . $items;
                }

                $this->sendMessage($chatId, $reply);
                return response('ok', 200);
            }

            // 2. 去除所有中文，並根據指定正則提取代碼
            $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);
            $pattern = '/
            (?:                                    # 第一大群：有前綴
                @?filepan_bot:                     #   @filepan_bot: 或 filepan_bot:
              | link:\s*                           #   link:
              | (?:vi_|pk_|p_|d_|showfilesbot_|    #   vi_、pk_、p_、d_、showfilesbot_
                   [vVpPdD]_|                     #   V_、P_、D_ 前綴
                   [vVpPdD]_datapanbot_)
            )
            [A-Za-z0-9_+\-]+                       # 主體：英數、底線、+、-
            (?:=_grp|=_mda)?                       # 可選後綴
          |
            \b                                     # 第二大群：無前綴
            [A-Za-z0-9_+\-]+                       # 主體：英數、底線、+、-
            (?:=_grp|=_mda)                        # 必須有 =_grp 或 =_mda
            \b
        /xu';
            preg_match_all($pattern, $cleanText, $matches);
            $codes = array_unique($matches[0] ?? []);

            // 3. 如果沒有任何符合的代碼就直接忽略
            if (empty($codes)) {
                return response('ok', 200);
            }

            // 4. 逐一檢查並處理每個代碼
            foreach ($codes as $code) {
                $existing = DB::table('dialogues')
                    ->where('chat_id', $chatId)
                    ->where('text', $code)
                    ->first();

                if ($existing) {
                    $firstTime = date('Y-m-d H:i:s', strtotime($existing->created_at));
                    $reply = "❗️ 重複代碼偵測：您在 {$firstTime} 已經提供過：\n“{$code}”";
                    $this->sendMessage($chatId, $reply);
                } else {
                    // 回送代碼並存入資料庫
                    $this->sendMessage($chatId, $code);
                    DB::table('dialogues')->insert([
                        'chat_id'    => $chatId,
                        'message_id' => $msgId,
                        'text'       => $code,
                        'created_at' => now(),
                    ]);
                }
            }

            return response('ok', 200);
        }

        /**
         * 封裝發送 Telegram 訊息
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
