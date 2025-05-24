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

            // 只處理文字
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

            // 2. 去除中文並提取所有符合規則的代碼
            $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);
            $pattern = '/
            (?:                                    # 有前綴
                @?filepan_bot:
              | link:\s*
              | (?:vi_|pk_|p_|d_|showfilesbot_|
                   [vVpPdD]_|
                   [vVpPdD]_datapanbot_)
            )
            [A-Za-z0-9_+\-]+
            (?:=_grp|=_mda)?
          |
            \b
            [A-Za-z0-9_+\-]+
            (?:=_grp|=_mda)
            \b
        /xu';
            preg_match_all($pattern, $cleanText, $matches);
            $codes = array_unique($matches[0] ?? []);

            // 若抽取後沒有任何代碼，則不回覆
            if (empty($codes)) {
                return response('ok', 200);
            }

            // 3. 查出已存在的代碼
            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->whereIn('text', $codes)
                ->pluck('text')
                ->all();

            // 4. 計算新代碼
            $newCodes = array_values(array_diff($codes, $existing));

            // 若沒有新代碼，也不回覆
            if (empty($newCodes)) {
                return response('ok', 200);
            }

            // 5. 逐筆存入資料庫
            foreach ($newCodes as $code) {
                DB::table('dialogues')->insert([
                    'chat_id'    => $chatId,
                    'message_id' => $msgId,
                    'text'       => $code,
                    'created_at' => now(),
                ]);
            }

            // 6. 一次性回覆所有新代碼
            $reply = "🔍 已擷取到以下新代碼：\n" . implode("\n", $newCodes);
            $this->sendMessage($chatId, $reply);

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
