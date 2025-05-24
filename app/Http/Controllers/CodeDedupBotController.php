<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use GuzzleHttp\Client;

    class CodeDedupBotController extends Controller
    {
        protected string $apiUrl;
        protected Client $http;
        protected int $perPage = 100;

        public function __construct()
        {
            $token       = '7921552608:AAGsjaUR6huZaCpH9SBARpi5_cQ0LiUwEiQ';
            $this->apiUrl = "https://api.telegram.org/bot{$token}/";
            $this->http   = new Client(['base_uri' => $this->apiUrl]);
        }

        public function handle(Request $request)
        {
            $update = $request->all();

            // 1. 處理 callback_query（分頁按鈕點擊）
            if (!empty($update['callback_query'])) {
                $cb       = $update['callback_query'];
                $data     = $cb['data'];                   // e.g. "dedup:12345:2"
                [$action, $origMsgId, $page] = explode(':', $data);
                if ($action === 'dedup') {
                    $chatId      = $cb['message']['chat']['id'];
                    $messageId   = $cb['message']['message_id'];
                    $allCodes    = DB::table('dialogues')
                        ->where('chat_id', $chatId)
                        ->where('message_id', $origMsgId)
                        ->pluck('text')
                        ->all();
                    $pages       = array_chunk($allCodes, $this->perPage);
                    $pageIndex   = max(1, min(count($pages), (int)$page)) - 1;
                    $pageCodes   = $pages[$pageIndex];
                    $text        = implode("\n", $pageCodes);
                    // 重新編輯訊息
                    $this->http->post('editMessageText', [
                        'json' => [
                            'chat_id'      => $chatId,
                            'message_id'   => $messageId,
                            'text'         => $text,
                            'reply_markup'=> $this->buildKeyboard($origMsgId, count($pages)),
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

            // /start：列出最近 20 筆「代碼」歷史
            if ($text === '/start') {
                $rows = DB::table('dialogues')
                    ->where('chat_id', $chatId)
                    ->orderBy('created_at', 'desc')
                    ->limit(20)
                    ->get(['text']);
                if ($rows->isEmpty()) {
                    $reply = "目前還沒有任何歷史代碼。";
                } else {
                    $items = $rows->reverse()
                        ->pluck('text')
                        ->join("\n");
                    $reply = "📜 歷史代碼（最近 ".count($rows)." 筆）：\n" . $items;
                }
                $this->sendMessage($chatId, $reply);
                return response('ok', 200);
            }

            // 3. 去除中文並擷取符合規則的 code
            $cleanText = preg_replace('/[\p{Han}]+/u', '', $text);
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

            // 4. 過濾已存在的 code
            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->whereIn('text', $codes)
                ->pluck('text')
                ->all();
            $newCodes = array_values(array_diff($codes, $existing));

            if (empty($newCodes)) {
                return response('ok', 200);
            }

            // 5. 存入資料庫
            foreach ($newCodes as $code) {
                DB::table('dialogues')->insert([
                    'chat_id'    => $chatId,
                    'message_id' => $msgId,
                    'text'       => $code,
                    'created_at' => now(),
                ]);
            }

            // 6. 回覆（含分頁）
            $total = count($newCodes);
            $pages = array_chunk($newCodes, $this->perPage);
            // 第一頁內容
            $firstPage = $pages[0];
            $replyText = implode("\n", $firstPage);

            // 如果只有一頁，直接回訊息
            if ($total <= $this->perPage) {
                $this->sendMessage($chatId, $replyText);
            } else {
                // 回傳第一頁並附上分頁按鈕
                $this->http->post('sendMessage', [
                    'json' => [
                        'chat_id'      => $chatId,
                        'text'         => $replyText,
                        'reply_markup'=> $this->buildKeyboard($msgId, count($pages)),
                    ],
                ]);
            }

            return response('ok', 200);
        }

        /**
         * 發送簡訊（無分頁按鈕）
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
         * 建立 inline keyboard（分頁按鈕）
         *
         * @param int $origMsgId 使用者訊息 ID（用來查詢該次插入的 codes）
         * @param int $totalPages 分頁總數
         * @return array
         */
        protected function buildKeyboard(int $origMsgId, int $totalPages): array
        {
            $buttons = [];
            for ($i = 1; $i <= $totalPages; $i++) {
                $buttons[] = [
                    'text'         => (string)$i,
                    'callback_data'=> "dedup:{$origMsgId}:{$i}"
                ];
            }
            // 每列最多 10 個按鈕
            $keyboard = array_chunk($buttons, 10);
            return ['inline_keyboard' => $keyboard];
        }
    }
