<?php

    namespace App\Http\Controllers;

    use App\Services\TelegramCodeTokenService;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Log;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;

    class CodeDedupBotController extends Controller
    {
        /** Telegram 文字上限（UTF-8 4096 byte） */
        private const MAX_MESSAGE_BYTES = 4096;

        /** 每次回覆最多 5 行（只套用在「一般代碼 + LH_」區塊；filestoebot_ 不套用） */
        private const REPLY_LINES_PER_MESSAGE = 5;

        /** filestoebot_ 前綴 */
        private const FILESTOEBOT_PREFIX = 'filestoebot_';

        protected string $apiUrl;
        protected Client $http;

        private TelegramCodeTokenService $tokenService;

        public function __construct(TelegramCodeTokenService $tokenService)
        {
            $token        = config('telegram.bot_token');
            $this->apiUrl = "https://api.telegram.org/bot{$token}/";
            $this->http   = new Client(['base_uri' => $this->apiUrl]);

            $this->tokenService = $tokenService;
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

            $data = (string)($cb['data'] ?? 'history:1');
            $parts = explode(':', $data);

            $action = $parts[0] ?? 'history';
            $page = $parts[1] ?? '1';

            if ($action !== 'history') {
                return response('ok', 200);
            }

            $chatId  = $cb['message']['chat']['id'];
            $pageNum = max(1, (int)$page);

            $allCodes = $this->getAllCodes($chatId);
            $lines    = $this->buildDisplayLines($allCodes);
            $pages    = $this->chunkByBytes($lines);
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

            $lines = $this->buildDisplayLines($allCodes);
            $pages = $this->chunkByBytes($lines);
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
            $codes = $this->tokenService->extractTokens($text);

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
            $this->sendCodesInBatches($chatId, $new);
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
        private function chunkByBytes(array $lines): array
        {
            $pages  = [];
            $buffer = '';

            foreach ($lines as $lineText) {
                $line = $lineText . "\n";
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

        /** 判斷是否為 LH_ 開頭代碼 */
        private function isLhCode(string $code): bool
        {
            return str_starts_with($code, 'LH_');
        }

        /** 判斷是否為 filestoebot_ 開頭代碼 */
        private function isFilestoebotCode(string $code): bool
        {
            return str_starts_with($code, self::FILESTOEBOT_PREFIX);
        }

        /**
         * 將代碼依規則分組：
         * 1) 一般代碼（非 LH_、非 filestoebot_）
         * 2) LH_
         * 3) filestoebot_
         */
        private function splitCodesByGroups(array $codes): array
        {
            $normal = [];
            $lh = [];
            $filestoebot = [];

            foreach ($codes as $code) {
                if ($this->isFilestoebotCode($code)) {
                    $filestoebot[] = $code;
                    continue;
                }

                if ($this->isLhCode($code)) {
                    $lh[] = $code;
                    continue;
                }

                $normal[] = $code;
            }

            return [$normal, $lh, $filestoebot];
        }

        /**
         * 回覆文字格式：
         * 一般代碼在上
         * LH_ 在下（中間空一行）
         * filestoebot_ 最底（再空一行）
         */
        private function formatCodesForReply(array $codes): string
        {
            [$normal, $lh, $filestoebot] = $this->splitCodesByGroups($codes);

            $chunks = [];

            $normalText = implode("\n", $normal);
            if ($normalText !== '') {
                $chunks[] = $normalText;
            }

            $lhText = implode("\n", $lh);
            if ($lhText !== '') {
                $chunks[] = $lhText;
            }

            $filestoebotText = implode("\n", $filestoebot);
            if ($filestoebotText !== '') {
                $chunks[] = $filestoebotText;
            }

            return implode("\n\n", $chunks);
        }

        /**
         * 產生顯示用行列表（供歷史 / 分頁用）：
         * 一般代碼 -> 空行 -> LH_ -> 空行 -> filestoebot_
         */
        private function buildDisplayLines(array $codes): array
        {
            [$normal, $lh, $filestoebot] = $this->splitCodesByGroups($codes);

            $lines = [];

            if (!empty($normal)) {
                $lines = array_merge($lines, $normal);
            }

            if (!empty($lh)) {
                if (!empty($lines)) {
                    $lines[] = '';
                }
                $lines = array_merge($lines, $lh);
            }

            if (!empty($filestoebot)) {
                if (!empty($lines)) {
                    $lines[] = '';
                }
                $lines = array_merge($lines, $filestoebot);
            }

            return $lines;
        }

        /**
         * 回覆分批策略
         * 一般代碼 + LH_：每 5 行吐一次（同時保護 4096 bytes）
         * filestoebot_：集中放最下面一次整包提供（只依 bytes 分頁）
         */
        private function sendCodesInBatches(int $chatId, array $codes): void
        {
            [$normal, $lh, $filestoebot] = $this->splitCodesByGroups($codes);

            $topLines = $this->buildTopLinesForReply($normal, $lh);
            $this->sendTopLinesByLineCountAndBytes($chatId, $topLines);

            $this->sendFilestoebotAllAtOnceByBytes($chatId, $filestoebot);
        }

        /**
         * 建立「一般代碼 + LH_」回覆行（維持 LH_ 置底、空行分隔）
         */
        private function buildTopLinesForReply(array $normal, array $lh): array
        {
            $lines = [];

            if (!empty($normal)) {
                $lines = array_merge($lines, $normal);
            }

            if (!empty($lh)) {
                if (!empty($lines)) {
                    $lines[] = '';
                }
                $lines = array_merge($lines, $lh);
            }

            return $lines;
        }

        /**
         * 一般代碼 + LH_：依 5 行與 bytes 同時限制發送
         */
        private function sendTopLinesByLineCountAndBytes(int $chatId, array $lines): void
        {
            $lines = array_values($lines);
            if (empty($lines)) {
                return;
            }

            $bufferLines = [];
            foreach ($lines as $line) {
                $bufferLines[] = $line;

                $shouldSendByLineCount = count($bufferLines) >= self::REPLY_LINES_PER_MESSAGE;

                $candidateText = implode("\n", $bufferLines);
                $shouldSendByBytes = strlen($candidateText) >= (self::MAX_MESSAGE_BYTES - 32);

                if ($shouldSendByLineCount || $shouldSendByBytes) {
                    $textToSend = trim(implode("\n", $bufferLines));
                    if ($textToSend !== '') {
                        $this->sendMessage($chatId, $textToSend);
                    }
                    $bufferLines = [];
                }
            }

            if (!empty($bufferLines)) {
                $textToSend = trim(implode("\n", $bufferLines));
                if ($textToSend !== '') {
                    $this->sendMessage($chatId, $textToSend);
                }
            }
        }

        /**
         * filestoebot_：整包一次提供
         * 若超過 4096 bytes，則僅依 bytes 分頁
         */
        private function sendFilestoebotAllAtOnceByBytes(int $chatId, array $filestoebot): void
        {
            if (empty($filestoebot)) {
                return;
            }

            $lines = array_values($filestoebot);

            $pages = $this->chunkByBytes($lines);
            foreach ($pages as $pageText) {
                $pageText = trim($pageText);
                if ($pageText !== '') {
                    $this->sendMessage($chatId, $pageText);
                }
            }
        }
    }
