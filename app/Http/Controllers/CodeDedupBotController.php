<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use GuzzleHttp\Client;

    class CodeDedupBotController extends Controller
    {
        // /start 分頁每頁筆數
        protected int $historyPerPage = 100;

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

            // 1. 處理 callback_query（/start 分頁按鈕）
            if (!empty($update['callback_query'])) {
                $cb   = $update['callback_query'];

                // --- 新增：回覆 callback_query，避免客戶端持續 loading ---
                $this->http->post('answerCallbackQuery', [
                    'json' => [
                        'callback_query_id' => $cb['id'],
                    ],
                ]);

                $data = $cb['data'];                // 例如 "history:2"
                [$action, $page] = explode(':', $data);

                if ($action === 'history') {
                    $chatId    = $cb['message']['chat']['id'];
                    $messageId = $cb['message']['message_id'];

                    // 取出所有歷史 code
                    $allCodes = DB::table('dialogues')
                        ->where('chat_id', $chatId)
                        ->orderBy('created_at', 'desc')
                        ->pluck('text')
                        ->all();

                    // 分頁
                    $pages = array_chunk($allCodes, $this->historyPerPage);
                    $pageIndex = max(1, min(count($pages), (int)$page)) - 1;
                    $pageCodes = $pages[$pageIndex];
                    $text      = implode("\n", $pageCodes);

                    // 編輯訊息，換成對應頁
                    $this->http->post('editMessageText', [
                        'json' => [
                            'chat_id'      => $chatId,
                            'message_id'   => $messageId,
                            'text'         => $text,
                            'reply_markup'=> $this->buildHistoryKeyboard(count($pages)),
                        ],
                    ]);
                }

                return response('ok', 200);
            }

            // 2. 只處理文字訊息
            if (empty($update['message']['text'])) {
                return response('ok', 200);
            }

            $chatId = $update['message']['chat']['id'];
            $text   = trim($update['message']['text']);
            $msgId  = $update['message']['message_id'];

            // 3. /start：顯示歷史 code（分頁）
            if ($text === '/start') {
                $allCodes = DB::table('dialogues')
                    ->where('chat_id', $chatId)
                    ->orderBy('created_at', 'desc')
                    ->pluck('text')
                    ->all();

                if (empty($allCodes)) {
                    $this->sendMessage($chatId, "目前還沒有任何歷史代碼。");
                } else {
                    $pages = array_chunk($allCodes, $this->historyPerPage);
                    $firstPage = $pages[0];
                    $replyText = implode("\n", $firstPage);

                    if (count($pages) === 1) {
                        $this->sendMessage($chatId, $replyText);
                    } else {
                        $this->http->post('sendMessage', [
                            'json' => [
                                'chat_id'      => $chatId,
                                'text'         => $replyText,
                                'reply_markup'=> $this->buildHistoryKeyboard(count($pages)),
                            ],
                        ]);
                    }
                }

                return response('ok', 200);
            }

            // 4. 一般輸入──抽 code 並去重
            // 移除中文
            $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);
            // 正則擷取
            $pattern = '/
            (?:                                    # 有前綴
                @?filepan_bot:
              | link:\s*
              | (?:vi_|pk_|p_|d_|showfilesbot_|
                   [vVpPdD]_|[vVpPdD]_datapanbot_)
            )
            [A-Za-z0-9_+\-]+
            (?:=_grp|=_mda)?
          |
            \b[A-Za-z0-9_+\-]+(?:=_grp|=_mda)\b
        /xu';
            preg_match_all($pattern, $cleanText, $matches);
            $codes = array_unique($matches[0] ?? []);

            if (empty($codes)) {
                return response('ok', 200);
            }

            // 過濾已存 code
            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->whereIn('text', $codes)
                ->pluck('text')
                ->all();
            $newCodes = array_values(array_diff($codes, $existing));

            if (empty($newCodes)) {
                return response('ok', 200);
            }

            // 存入 DB
            foreach ($newCodes as $code) {
                DB::table('dialogues')->insert([
                    'chat_id'    => $chatId,
                    'message_id' => $msgId,
                    'text'       => $code,
                    'created_at' => now(),
                ]);
            }

            // 回覆全部新 code（純 code，每行一筆）
            $reply = implode("\n", $newCodes);
            $this->sendMessage($chatId, $reply);

            return response('ok', 200);
        }

        /**
         * 發送純文字訊息
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

        /**
         * 建立 /start 歷史分頁按鈕
         *
         * @param int $totalPages
         * @return array
         */
        protected function buildHistoryKeyboard(int $totalPages): array
        {
            $buttons = [];
            for ($i = 1; $i <= $totalPages; $i++) {
                $buttons[] = [
                    'text'         => (string)$i,
                    'callback_data'=> "history:{$i}",
                ];
            }
            // 每列最多 10 個按鈕
            $keyboard = array_chunk($buttons, 10);
            return ['inline_keyboard' => $keyboard];
        }
    }
