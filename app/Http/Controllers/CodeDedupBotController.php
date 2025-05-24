<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Cache;
    use GuzzleHttp\Client;

    class CodeDedupBotController extends Controller
    {
        protected string $apiUrl;
        protected Client $http;

        public function __construct()
        {
            $token       = config('telegram.bot_token', '7921552608:AAGsjaUR6huZaCpH9SBARpi5_cQ0LiUwEiQ');
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

            // 1. /start：顯示歷史代碼
            if ($text === '/start') {
                $rows = DB::table('dialogues')
                    ->where('chat_id', $chatId)
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get(['text', 'created_at']);

                if ($rows->isEmpty()) {
                    $this->sendMessage($chatId, "目前還沒有任何歷史代碼。");
                } else {
                    $items = $rows->reverse()->map(function($r, $i){
                        $time = date('H:i', strtotime($r->created_at));
                        return sprintf("%02d. [%s] %s", $i+1, $time, $r->text);
                    })->join("\n");
                    $this->sendMessage($chatId, "📜 歷史代碼（最近 ".count($rows)." 筆）：\n" . $items);
                }
                return response('ok', 200);
            }

            // 2. 去除中文並提取符合規則的代碼
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

            // 若無任何符合的代碼，直接結束
            if (empty($codes)) {
                return response('ok', 200);
            }

            // 3. 找出已存在資料庫中的代碼
            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->whereIn('text', $codes)
                ->pluck('text')
                ->all();

            // 4. 計算真正的新代碼
            $newCodes = array_values(array_diff($codes, $existing));
            if (empty($newCodes)) {
                // 全部重複，無新代碼時不回覆
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

            // 6. 將新代碼累積到快取
            $cacheKeyCodes = "pending_codes:{$chatId}";
            $pending       = Cache::get($cacheKeyCodes, []);
            $merged        = array_unique(array_merge($pending, $newCodes));
            // TTL 長一點，避免錯過合併期
            Cache::put($cacheKeyCodes, $merged, now()->addSeconds(10));

            // 7. 第一次進來的請求才會等待並一次性發送
            $cacheKeyLock = "pending_codes_lock:{$chatId}";
            // add 只在 key 不存在時回傳 true
            if (Cache::add($cacheKeyLock, true, 3)) {
                // 延遲 3 秒再取出快取並發送
                sleep(3);

                $toSend = Cache::pull($cacheKeyCodes, []);
                if (!empty($toSend)) {
                    $reply = "🔍 已擷取到以下新代碼：\n" . implode("\n", $toSend);
                    $this->sendMessage($chatId, $reply);
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
