<?php

    namespace App\Console\Commands;

    use App\Models\TokenScanHeader;
    use App\Models\TokenScanItem;
    use App\Services\TelegramCodeTokenService;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;

    class ScanGroupTokensCommand extends Command
    {
        protected $signature = 'tg:scan-group-tokens {times? : 打 API 次數（不填=全跑）}';
        protected $description = '掃描 Telegram 群組聊天資料並抽取 token，去重後寫入 token_scan_items（可限制 API 次數）';

        private Client $http;
        private TelegramCodeTokenService $tokenService;

        /**
         * 你要掃的聊天室 id 放這裡，可自由加減
         */
        private array $peerIds = [
            2607630227,
            2562367214,
            2605076496,
            3690371890,
            3318624691,
        ];

        /**
         * dialogues 固定檢查的 chat_id（照你原先規格寫死）
         */
        private const DIALOGUES_CHAT_ID = 7702694790;

        /**
         * 從 /groups 取得的聊天室資訊快取：peer_id => ['title' => ..., 'last_message_id' => ...]
         */
        private array $groupsIndex = [];

        /**
         * HTTP retry 次數
         */
        private const HTTP_MAX_RETRIES = 3;

        /**
         * 每次 bulk insert 的 chunk 大小
         */
        private const INSERT_CHUNK_SIZE = 500;

        public function __construct(TelegramCodeTokenService $tokenService)
        {
            parent::__construct();

            $this->http = new Client([
                'base_uri' => 'http://127.0.0.1:8000/',
                'timeout' => 30,
                'connect_timeout' => 10,
            ]);

            $this->tokenService = $tokenService;
        }

        public function handle(): int
        {
            $timesArg = $this->argument('times');
            $limitTimes = null;

            if ($timesArg !== null && trim((string)$timesArg) !== '') {
                $limitTimes = (int)$timesArg;
                if ($limitTimes <= 0) {
                    $limitTimes = null;
                }
            }

            $this->groupsIndex = $this->fetchGroupsIndex();

            foreach ($this->peerIds as $peerId) {
                $peerId = (int)$peerId;
                $this->scanOnePeer($peerId, $limitTimes);
            }

            return self::SUCCESS;
        }

        private function scanOnePeer(int $peerId, ?int $limitTimes): void
        {
            $header = TokenScanHeader::query()->where('peer_id', $peerId)->first();

            if (!$header) {
                $header = TokenScanHeader::query()->create([
                    'peer_id' => $peerId,
                    'chat_title' => null,
                    'last_start_message_id' => 1,
                    'max_message_id' => 0,
                    'last_batch_count' => 0,
                ]);
            }

            $groupInfo = $this->groupsIndex[$peerId] ?? null;
            if (is_array($groupInfo)) {
                $title = (string)($groupInfo['title'] ?? '');
                if ($title !== '' && $header->chat_title !== $title) {
                    $header->chat_title = $title;
                    $header->save();
                }
            }

            $totalInserted = 0;
            $loopCount = 0;

            while (true) {
                $loopCount = $loopCount + 1;

                if ($limitTimes !== null && $loopCount > $limitTimes) {
                    $this->line("peer_id={$peerId} 已達限制 API 次數 {$limitTimes}，停止。");
                    break;
                }

                $startMessageId = ((int)$header->max_message_id) > 0 ? ((int)$header->max_message_id + 1) : 1;

                $payload = $this->fetchGroupPage($peerId, $startMessageId);

                if (!$payload) {
                    $this->line("peer_id={$peerId} start={$startMessageId} 取回失敗，停止。");
                    break;
                }

                $status = (string)($payload['status'] ?? '');
                if ($status !== 'ok') {
                    $this->line("peer_id={$peerId} start={$startMessageId} status={$status}，停止。");
                    break;
                }

                $items = $payload['items'] ?? [];
                if (!is_array($items) || count($items) === 0) {
                    $this->printHeaderTable($header, $peerId, $startMessageId, 0, 0, $totalInserted);
                    $this->line("peer_id={$peerId} start={$startMessageId} items=0，沒有新訊息，停止。");
                    break;
                }

                $maxIdInBatch = $this->getMaxMessageId($items);
                if ($maxIdInBatch <= 0) {
                    $this->printHeaderTable($header, $peerId, $startMessageId, 0, count($items), $totalInserted);
                    $this->line("peer_id={$peerId} start={$startMessageId} 無法取得 max message id，停止。");
                    break;
                }

                if ($maxIdInBatch < $startMessageId) {
                    $this->printHeaderTable($header, $peerId, $startMessageId, $maxIdInBatch, count($items), $totalInserted);
                    $this->line("peer_id={$peerId} start={$startMessageId} maxIdInBatch={$maxIdInBatch} 小於 start，視為沒有新訊息，停止。");
                    break;
                }

                $insertedThisBatch = $this->extractAndInsertTokensFromItems((int)$header->id, $items);

                $header->last_start_message_id = $startMessageId;
                $header->max_message_id = $maxIdInBatch;
                $header->last_batch_count = count($items);
                $header->save();

                $totalInserted = $totalInserted + $insertedThisBatch;

                $this->printHeaderTable($header, $peerId, $startMessageId, $maxIdInBatch, count($items), $totalInserted);

                if ($insertedThisBatch > 0) {
                    $this->line("peer_id={$peerId} 本批新增 token：{$insertedThisBatch}");
                } else {
                    $this->line("peer_id={$peerId} 本批沒有新增 token");
                }

                $lastMessageIdFromGroups = 0;
                $groupInfo = $this->groupsIndex[$peerId] ?? null;
                if (is_array($groupInfo)) {
                    $lastMessageIdFromGroups = (int)($groupInfo['last_message_id'] ?? 0);
                }

                if ($lastMessageIdFromGroups > 0 && (int)$header->max_message_id >= $lastMessageIdFromGroups) {
                    $this->line("peer_id={$peerId} 已掃到群組最新 last_message_id={$lastMessageIdFromGroups}，停止。");
                    break;
                }
            }

            $this->line("peer_id={$peerId} 完成，總新增 token：{$totalInserted}");
            $this->line(str_repeat('-', 80));
        }

        /**
         * 打 /groups 建立 peer_id -> title/last_message_id 的索引
         */
        private function fetchGroupsIndex(): array
        {
            $tries = 0;

            while (true) {
                $tries = $tries + 1;

                try {
                    $res = $this->http->get('groups');
                    $body = (string)$res->getBody();
                    $json = json_decode($body, true);

                    if (!is_array($json)) {
                        return [];
                    }

                    $items = $json['items'] ?? [];
                    if (!is_array($items)) {
                        return [];
                    }

                    $index = [];
                    foreach ($items as $it) {
                        if (!is_array($it)) {
                            continue;
                        }

                        $id = (int)($it['id'] ?? 0);
                        if ($id <= 0) {
                            continue;
                        }

                        $index[$id] = [
                            'title' => (string)($it['title'] ?? ''),
                            'last_message_id' => (int)($it['last_message_id'] ?? 0),
                        ];
                    }

                    return $index;
                } catch (GuzzleException $e) {
                    if ($tries >= self::HTTP_MAX_RETRIES) {
                        $this->line('HTTP 失敗：GET /groups err=' . $e->getMessage());
                        return [];
                    }

                    usleep(250000);
                }
            }
        }

        private function fetchGroupPage(int $peerId, int $startMessageId): ?array
        {
            $path = "groups/{$peerId}/{$startMessageId}";
            $tries = 0;

            while (true) {
                $tries = $tries + 1;

                try {
                    $res = $this->http->get($path);
                    $body = (string)$res->getBody();
                    $json = json_decode($body, true);

                    if (!is_array($json)) {
                        return null;
                    }

                    return $json;
                } catch (GuzzleException $e) {
                    if ($tries >= self::HTTP_MAX_RETRIES) {
                        $this->line("HTTP 失敗：peer_id={$peerId} start={$startMessageId} err=" . $e->getMessage());
                        return null;
                    }

                    usleep(250000);
                }
            }
        }

        private function getMaxMessageId(array $items): int
        {
            $max = 0;
            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $id = (int)($it['id'] ?? 0);
                if ($id > $max) {
                    $max = $id;
                }
            }
            return $max;
        }

        private function getWebPageTitle(array $message): ?string
        {
            $media = $message['media'] ?? null;
            if (!is_array($media)) {
                return null;
            }

            $webpage = $media['webpage'] ?? null;
            if (!is_array($webpage)) {
                return null;
            }

            $title = $webpage['title'] ?? null;
            if (!is_string($title)) {
                return null;
            }

            return $title;
        }

        private function getMessageText(array $message): ?string
        {
            $text = $message['message'] ?? null;
            if (!is_string($text)) {
                return null;
            }

            $text = trim($text);
            if ($text === '') {
                return null;
            }

            return $text;
        }

        /**
         * 去重規則：
         * 1) token_scan_items.token 全表唯一（不管 header_id）
         * 2) dialogues 是否已有（chat_id 固定 7702694790）
         * 兩者都沒有才 insert token_scan_items（仍會記錄 header_id 方便追溯來源聊天室）
         */
        private function extractAndInsertTokensFromItems(int $headerId, array $items): int
        {
            $candidateTokens = [];

            foreach ($items as $it) {
                if (!is_array($it)) {
                    continue;
                }

                $textsToParse = [];

                $title = $this->getWebPageTitle($it);
                if ($title !== null && trim($title) !== '') {
                    $textsToParse[] = $title;
                }

                $messageText = $this->getMessageText($it);
                if ($messageText !== null && trim($messageText) !== '') {
                    $textsToParse[] = $messageText;
                }

                if (empty($textsToParse)) {
                    continue;
                }

                foreach ($textsToParse as $txt) {
                    $tokens = $this->tokenService->extractTokens($txt);
                    if (empty($tokens)) {
                        continue;
                    }

                    foreach ($tokens as $t) {
                        $t = trim((string)$t);
                        if ($t === '') {
                            continue;
                        }
                        $candidateTokens[] = $t;
                    }
                }
            }

            if (empty($candidateTokens)) {
                return 0;
            }

            $candidateTokens = array_values(array_unique($candidateTokens));

            $existingTokensGlobal = TokenScanItem::query()
                ->whereIn('token', $candidateTokens)
                ->pluck('token')
                ->all();

            $existingGlobalSet = [];
            foreach ($existingTokensGlobal as $t) {
                $existingGlobalSet[(string)$t] = true;
            }

            $tokensNeedDialoguesCheck = [];
            foreach ($candidateTokens as $token) {
                if (!isset($existingGlobalSet[$token])) {
                    $tokensNeedDialoguesCheck[] = $token;
                }
            }

            $dialoguesExistingSet = [];
            if (!empty($tokensNeedDialoguesCheck)) {
                $dialoguesExisting = DB::table('dialogues')
                    ->where('chat_id', self::DIALOGUES_CHAT_ID)
                    ->whereIn('text', $tokensNeedDialoguesCheck)
                    ->pluck('text')
                    ->all();

                foreach ($dialoguesExisting as $t) {
                    $dialoguesExistingSet[(string)$t] = true;
                }
            }

            $rowsToInsert = [];
            $insertedTokensForPrint = [];

            foreach ($candidateTokens as $token) {
                if (isset($existingGlobalSet[$token])) {
                    continue;
                }

                if (isset($dialoguesExistingSet[$token])) {
                    continue;
                }

                $rowsToInsert[] = [
                    'header_id' => $headerId,
                    'token' => $token,
                ];

                $insertedTokensForPrint[] = [
                    '表頭id' => $headerId,
                    'token' => $token,
                ];
            }

            if (empty($rowsToInsert)) {
                return 0;
            }

            $insertedCount = 0;

            DB::transaction(function () use ($rowsToInsert, &$insertedCount) {
                $tokens = [];
                foreach ($rowsToInsert as $row) {
                    $tokens[] = (string)$row['token'];
                }
                $tokens = array_values(array_unique($tokens));

                if (empty($tokens)) {
                    return;
                }

                $existingTokens = TokenScanItem::query()
                    ->whereIn('token', $tokens)
                    ->pluck('token')
                    ->all();

                $existingSet = [];
                foreach ($existingTokens as $t) {
                    $existingSet[(string)$t] = true;
                }

                $dialoguesExisting = DB::table('dialogues')
                    ->where('chat_id', self::DIALOGUES_CHAT_ID)
                    ->whereIn('text', $tokens)
                    ->pluck('text')
                    ->all();

                $dialoguesSet = [];
                foreach ($dialoguesExisting as $t) {
                    $dialoguesSet[(string)$t] = true;
                }

                $finalRows = [];
                foreach ($rowsToInsert as $row) {
                    $token = (string)$row['token'];
                    if (isset($existingSet[$token])) {
                        continue;
                    }
                    if (isset($dialoguesSet[$token])) {
                        continue;
                    }
                    $finalRows[] = [
                        'header_id' => (int)$row['header_id'],
                        'token' => $token,
                    ];
                }

                if (empty($finalRows)) {
                    return;
                }

                $chunks = array_chunk($finalRows, self::INSERT_CHUNK_SIZE);
                foreach ($chunks as $chunk) {
                    $affected = DB::table('token_scan_items')->insertOrIgnore($chunk);
                    $insertedCount = $insertedCount + (int)$affected;
                }
            });

            if (!empty($insertedTokensForPrint)) {
                $this->table(['表頭id', 'token'], $insertedTokensForPrint);
            }

            return $insertedCount;
        }

        private function printHeaderTable(TokenScanHeader $header, int $peerId, int $startMessageId, int $maxIdInBatch, int $batchCount, int $totalInserted): void
        {
            $title = $header->chat_title ?? '';
            $title = (string)$title;

            $lastMessageIdFromGroups = 0;
            $groupInfo = $this->groupsIndex[$peerId] ?? null;
            if (is_array($groupInfo)) {
                $lastMessageIdFromGroups = (int)($groupInfo['last_message_id'] ?? 0);
            }

            $rows = [[
                         '聊天室id' => $peerId,
                         '聊天室名稱' => $title,
                         '目前位置(start_message_id)' => $startMessageId,
                         '目前抓到最大message_id' => (int)$header->max_message_id,
                         '本批最大message_id' => $maxIdInBatch,
                         '本批筆數' => $batchCount,
                         '群組最新last_message_id' => $lastMessageIdFromGroups,
                         '累計新增token' => $totalInserted,
                     ]];

            $this->table(
                ['聊天室id', '聊天室名稱', '目前位置(start_message_id)', '目前抓到最大message_id', '本批最大message_id', '本批筆數', '群組最新last_message_id', '累計新增token'],
                $rows
            );
        }
    }
