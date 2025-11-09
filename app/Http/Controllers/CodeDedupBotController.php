<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;

    class CodeDedupBotController extends Controller
    {
        /** Telegram 文字上限（UTF-8 4096 byte） */
        private const MAX_MESSAGE_BYTES = 4096;

        protected string $apiUrl;
        protected Client $http;

        public function __construct()
        {
            $token        = config('telegram.bot_token');      // 請將 TELEGRAM_BOT_TOKEN 放在 .env
            $this->apiUrl = "https://api.telegram.org/bot{$token}/";
            $this->http   = new Client(['base_uri' => $this->apiUrl]);
        }

        public function handle(Request $request)
        {
            $update = $request->all();

            /* ---------- 1. callback_query ---------- */
            if (!empty($update['callback_query'])) {
                return $this->handleCallback($update['callback_query']);
            }

            /* ---------- 2. 僅處理文字訊息 ---------- */
            if (empty($update['message']['text'])) {
                return response('ok', 200);
            }

            $chatId = $update['message']['chat']['id'];
            $text   = trim($update['message']['text']);
            $msgId  = $update['message']['message_id'];

            /* ---------- 3. /start：顯示歷史 ---------- */
            if ($text === '/start') {
                return $this->showHistory($chatId);
            }

            /* ---------- 4. 一般輸入：擷取並去重 ---------- */
            $this->extractAndStoreCodes($chatId, $msgId, $text);

            return response('ok', 200);
        }

        /* ===== callback_query ===== */
        private function handleCallback(array $cb)
        {
            // 結束 loading
            $this->safeRequest('answerCallbackQuery', [
                'callback_query_id' => $cb['id'],
            ]);

            [$action, $page] = explode(':', $cb['data'] ?? 'history:1');
            if ($action !== 'history') {
                return response('ok', 200);
            }

            $chatId  = $cb['message']['chat']['id'];
            $pageNum = max(1, (int)$page);

            $allCodes = $this->getAllCodes($chatId);
            $pages    = $this->chunkByBytes($allCodes);
            $pageIdx  = min(count($pages), $pageNum) - 1;

            // 以新訊息方式送出，保留第一頁
            $this->safeRequest('sendMessage', [
                'chat_id'      => $chatId,
                'text'         => $pages[$pageIdx],
                'reply_markup' => $this->buildHistoryKeyboard(count($pages), $pageNum),
            ]);

            return response('ok', 200);
        }

        /* ===== /start ===== */
        private function showHistory(int $chatId)
        {
            $allCodes = $this->getAllCodes($chatId);
            if (empty($allCodes)) {
                $this->sendMessage($chatId, '目前還沒有任何歷史代碼。');
                return response('ok', 200);
            }

            $pages = $this->chunkByBytes($allCodes);
            $first = $pages[0];

            if (count($pages) === 1) {
                $this->sendMessage($chatId, $first);
            } else {
                $this->safeRequest('sendMessage', [
                    'chat_id'      => $chatId,
                    'text'         => $first,
                    'reply_markup' => $this->buildHistoryKeyboard(count($pages), 1),
                ]);
            }

            return response('ok', 200);
        }

        /* ===== 抽出並去重 ===== */
        private function extractAndStoreCodes(int $chatId, int $msgId, string $text): void
        {
            // 去中文（保留英文、日文假名等，方便混在文字中抓 code）
            $clean = preg_replace('/[\p{Han}]+/u', '', $text);

            // 擷取 code
            // 新增 ntmjmqbot_ 前綴的識別，像 ntmjmqbot_5p_28v_0d_s54xEbtm7ZKU4 這種格式也會被抓到
            $pattern = '/
            (?:@?filepan_bot:|link:\s*|(?:vi_|pk_|p_|d_|showfilesbot_|[vVpPdD]_?datapanbot_|[vVpPdD]_|ntmjmqbot_))
            [A-Za-z0-9_\+\-]+(?:=_grp|=_mda)? |
            \b[A-Za-z0-9_\+\-]+(?:=_grp|=_mda)\b
        /xu';

            preg_match_all($pattern, $clean, $m);
            $codes = array_unique($m[0] ?? []);

            if (!$codes) {
                return;
            }

            // 過濾舊碼
            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->whereIn('text', $codes)
                ->pluck('text')
                ->all();

            $new = array_values(array_diff($codes, $existing));
            if (!$new) {
                return;
            }

            // 寫入 DB
            foreach ($new as $code) {
                DB::table('dialogues')->insert([
                    'chat_id'    => $chatId,
                    'message_id' => $msgId,
                    'text'       => $code,
                    'created_at' => now(),
                ]);
            }

            // 回覆新碼
            $this->sendMessage($chatId, implode("\n", $new));
        }

        /* ===== 共用 ===== */
        private function getAllCodes(int $chatId): array
        {
            return DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->orderBy('created_at', 'desc')
                ->pluck('text')
                ->all();
        }

        /** 依 byte 分頁，確保 < 4096 bytes */
        private function chunkByBytes(array $codes): array
        {
            $pages  = [];
            $buffer = '';

            foreach ($codes as $code) {
                $line = $code . "\n";
                if (strlen($buffer) + strlen($line) > self::MAX_MESSAGE_BYTES) {
                    $pages[] = rtrim($buffer);
                    $buffer  = '';
                }
                $buffer .= $line;
            }

            if ($buffer !== '') {
                $pages[] = rtrim($buffer);
            }

            return $pages;
        }

        /** 發送純文字 */
        private function sendMessage(int $chatId, string $text): void
        {
            $this->safeRequest('sendMessage', [
                'chat_id' => $chatId,
                'text'    => $text,
            ]);
        }

        /** 建立分頁按鈕（當前頁以「🔘」標示） */
        private function buildHistoryKeyboard(int $totalPages, int $currentPage = 1): array
        {
            $btns = [];
            $i    = 1;

            while ($i <= $totalPages) {
                if ($i === $currentPage) {
                    $label = '🔘' . $i;
                } else {
                    $label = (string)$i;
                }

                $btns[] = [
                    'text'          => $label,
                    'callback_data' => 'history:' . $i,
                ];

                $i = $i + 1;
            }

            return ['inline_keyboard' => array_chunk($btns, 10)];
        }

        /** 封裝 Telegram API 呼叫 */
        private function safeRequest(string $method, array $payload): void
        {
            try {
                $this->http->post($method, ['json' => $payload]);
            } catch (GuzzleException $e) {
                Log::warning('Telegram ' . $method . ' 失敗：' . $e->getMessage(), compact('payload'));
            }
        }
    }
