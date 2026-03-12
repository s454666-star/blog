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

        /** filestoebot_ 前綴 */
        private const FILESTOEBOT_PREFIX = 'filestoebot_';

        /** newjmqbot_ 前綴 */
        private const NEWJMQBOT_PREFIX = 'newjmqbot_';

        /** Messengercode_ 前綴 */
        private const MESSENGERCODE_PREFIX = 'Messengercode_';

        protected string $apiUrl;
        protected Client $http;

        private TelegramCodeTokenService $tokenService;

        public function __construct(TelegramCodeTokenService $tokenService)
        {
            $token = config('telegram.bot_token');
            $this->apiUrl = "https://api.telegram.org/bot{$token}/";
            $this->http = new Client(['base_uri' => $this->apiUrl]);

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

            $chatId = (int) $update['message']['chat']['id'];
            $text = trim((string) $update['message']['text']);
            $msgId = (int) $update['message']['message_id'];

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
            $this->safeRequest('answerCallbackQuery', [
                'callback_query_id' => $cb['id'],
            ]);

            $data = (string) ($cb['data'] ?? 'history:1');
            $parts = explode(':', $data);

            $action = $parts[0] ?? 'history';
            $page = $parts[1] ?? '1';

            if ($action !== 'history') {
                return response('ok', 200);
            }

            $chatId = (int) $cb['message']['chat']['id'];
            $pageNum = max(1, (int) $page);

            $allCodes = $this->getAllCodes($chatId);
            $lines = $this->buildDisplayLines($allCodes);
            $pages = $this->chunkByBytes($lines);

            if (empty($pages)) {
                $this->sendMessage($chatId, '目前還沒有任何歷史代碼。');
                return response('ok', 200);
            }

            $pageIdx = min(count($pages), $pageNum) - 1;

            $this->safeRequest('sendMessage', [
                'chat_id' => $chatId,
                'text' => $pages[$pageIdx],
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

            if (empty($pages)) {
                $this->sendMessage($chatId, '目前還沒有任何歷史代碼。');
                return response('ok', 200);
            }

            $first = $pages[0];

            if (count($pages) === 1) {
                $this->sendMessage($chatId, $first);
            } else {
                $this->safeRequest('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => $first,
                    'reply_markup' => $this->buildHistoryKeyboard(count($pages), 1),
                ]);
            }

            return response('ok', 200);
        }

        /* ===== 抽出並去重 ===== */
        private function extractAndStoreCodes(int $chatId, int $msgId, string $text): void
        {
            $codes = $this->tokenService->extractTokens($text);

            if (empty($codes)) {
                return;
            }

            $existing = DB::table('dialogues')
                ->where('chat_id', $chatId)
                ->whereIn('text', $codes)
                ->pluck('text')
                ->all();

            $new = array_values(array_diff($codes, $existing));
            if (empty($new)) {
                return;
            }

            foreach ($new as $code) {
                DB::table('dialogues')->insert([
                    'chat_id' => $chatId,
                    'message_id' => $msgId,
                    'text' => $code,
                    'is_read' => 1,
                    'created_at' => now(),
                ]);
            }

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
            $pages = [];
            $buffer = '';

            foreach ($lines as $lineText) {
                $line = $lineText . "\n";
                if (strlen($buffer) + strlen($line) > self::MAX_MESSAGE_BYTES) {
                    $pages[] = rtrim($buffer);
                    $buffer = '';
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
                'text' => $text,
            ]);
        }

        /** 建立分頁按鈕（當前頁以「🔘」標示） */
        private function buildHistoryKeyboard(int $totalPages, int $currentPage = 1): array
        {
            $btns = [];
            $i = 1;

            while ($i <= $totalPages) {
                $label = ($i === $currentPage) ? ('🔘' . $i) : (string) $i;

                $btns[] = [
                    'text' => $label,
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

        /** 判斷是否為 newjmqbot_ 開頭代碼 */
        private function isNewJmqbotCode(string $code): bool
        {
            return str_starts_with($code, self::NEWJMQBOT_PREFIX);
        }

        /** 判斷是否為 Messengercode_ 開頭代碼 */
        private function isMessengercode(string $code): bool
        {
            return str_starts_with($code, self::MESSENGERCODE_PREFIX);
        }

        private function splitCodesByGroups(array $codes): array
        {
            $normal = [];
            $lh = [];
            $filestoebot = [];
            $newjmqbot = [];
            $messengercode = [];

            foreach ($codes as $code) {
                if ($this->isFilestoebotCode($code)) {
                    $filestoebot[] = $code;
                    continue;
                }

                if ($this->isNewJmqbotCode($code)) {
                    $newjmqbot[] = $code;
                    continue;
                }

                if ($this->isMessengercode($code)) {
                    $messengercode[] = $code;
                    continue;
                }

                if ($this->isLhCode($code)) {
                    $lh[] = $code;
                    continue;
                }

                $normal[] = $code;
            }

            return [$normal, $lh, $filestoebot, $newjmqbot, $messengercode];
        }

        private function buildDisplayLines(array $codes): array
        {
            [$normal, $lh, $filestoebot, $newjmqbot, $messengercode] = $this->splitCodesByGroups($codes);

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

            if (!empty($newjmqbot)) {
                if (!empty($lines)) {
                    $lines[] = '';
                }
                $lines = array_merge($lines, $newjmqbot);
            }

            if (!empty($messengercode)) {
                if (!empty($lines)) {
                    $lines[] = '';
                }
                $lines = array_merge($lines, $messengercode);
            }

            return $lines;
        }

        private function sendCodesInBatches(int $chatId, array $codes): void
        {
            [$normal, $lh, $filestoebot, $newjmqbot, $messengercode] = $this->splitCodesByGroups($codes);

            $topLines = $this->buildTopLinesForReply($normal, $lh);
            $this->sendAllAtOnceByBytes($chatId, $topLines);

            $this->sendFilestoebotAllAtOnceByBytes($chatId, $filestoebot);

            $this->sendNewJmqbotAllAtOnceByBytes($chatId, $newjmqbot);

            $this->sendMessengercodeAllAtOnceByBytes($chatId, $messengercode);
        }

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

        private function sendAllAtOnceByBytes(int $chatId, array $lines): void
        {
            $lines = array_values($lines);
            if (empty($lines)) {
                return;
            }

            $pages = $this->chunkByBytes($lines);
            foreach ($pages as $pageText) {
                $pageText = trim($pageText);
                if ($pageText !== '') {
                    $this->sendMessage($chatId, $pageText);
                }
            }
        }

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

        private function sendNewJmqbotAllAtOnceByBytes(int $chatId, array $newjmqbot): void
        {
            if (empty($newjmqbot)) {
                return;
            }

            $lines = array_values($newjmqbot);
            $pages = $this->chunkByBytes($lines);

            foreach ($pages as $pageText) {
                $pageText = trim($pageText);
                if ($pageText !== '') {
                    $this->sendMessage($chatId, $pageText);
                }
            }
        }

        private function sendMessengercodeAllAtOnceByBytes(int $chatId, array $messengercode): void
        {
            if (empty($messengercode)) {
                return;
            }

            $lines = array_values($messengercode);
            $pages = $this->chunkByBytes($lines);

            foreach ($pages as $pageText) {
                $pageText = trim($pageText);
                if ($pageText !== '') {
                    $this->sendMessage($chatId, $pageText);
                }
            }
        }
    }
