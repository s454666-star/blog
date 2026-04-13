<?php

    namespace App\Http\Controllers;

    use App\Jobs\SendFilestoreSessionFilesJob;
    use App\Jobs\TelegramFilestoreDebouncedPromptJob;
    use App\Models\TelegramFilestoreFile;
    use App\Models\TelegramFilestoreSession;
    use App\Support\TelegramWebhookLogContext;
    use App\Services\TelegramCodeTokenService;
    use App\Services\TelegramFilestoreBridgeContextService;
    use App\Services\TelegramFilestoreBotProfileResolver;
    use App\Services\TelegramFilestoreCloseUploadPromptService;
    use App\Services\TelegramFilestoreStaleSessionCleanupService;
    use Illuminate\Contracts\Bus\Dispatcher;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Str;
    use Throwable;

    class TelegramFilestoreBotController extends Controller
    {
        private string $activeBotProfileKey = TelegramFilestoreBotProfileResolver::FILESTORE;

        public function __construct(
            private TelegramFilestoreBridgeContextService $bridgeContextService,
            private TelegramCodeTokenService $telegramCodeTokenService,
            private TelegramFilestoreBotProfileResolver $botProfileResolver,
            private TelegramFilestoreStaleSessionCleanupService $staleSessionCleanupService
        ) {
        }

        /**
         * 只有「加密文件」才固定使用此前綴。
         * 但「解碼/貼代碼取檔」時，不應該強制要求使用者一定要帶此前綴。
         */
        private const TOKEN_PREFIX = 'filestoebot_';

        private const MYFILES_PAGE_SIZE = 30;
        private const MYFILES_MAX_PAGES_SHOWN = 7;
        private const BRIDGE_CONTROL_PREFIX = 'filestorebridge|';

        /**
         * 避免短時間內重複送出「是否結束上傳？」的去重視窗（秒）
         */
        private const CLOSE_UPLOAD_PROMPT_DEDUP_SECONDS = 30;

        /**
         * close upload 提示訊息 message_id 的 cache TTL（分鐘）
         */
        private const CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES = 180;

        /**
         * /delete 清單分頁設定
         */
        private const DELETE_PAGE_SIZE = 10;
        private const DELETE_MAX_PAGES_SHOWN = 7;

        /**
         * 同一個人同一個 token 的去重時間（秒）
         */
        private const TOKEN_DEDUP_SECONDS = 25;

        /**
         * 單則訊息最多只處理這麼多取檔 token，避免超長貼文把 webhook 拖到 timeout。
         */
        private const MAX_TOKEN_LOOKUPS_PER_MESSAGE = 8;

        /**
         * 同 chat 同 message_id 去重時間（秒）
         */
        private const MESSAGE_DEDUP_SECONDS = 600;

        /**
         * is_sending 維持太久時，視為卡住並允許重新排隊。
         */
        private const STALE_SENDING_LOCK_SECONDS = 1800;

        /**
         * debounce 秒數：N 秒內沒有新檔案才統計一次
         */
        private const DEBOUNCE_SECONDS = 5;

        public function webhook(Request $request)
        {
            return $this->handleWebhookForProfile($request, TelegramFilestoreBotProfileResolver::FILESTORE);
        }

        public function newFilesStarWebhook(Request $request)
        {
            return $this->handleWebhookForProfile($request, TelegramFilestoreBotProfileResolver::BACKUP_RESTORE);
        }

        private function handleWebhookForProfile(Request $request, string $botProfileKey)
        {
            $botProfile = $this->botProfileResolver->resolve($botProfileKey);
            $this->activeBotProfileKey = $botProfile['key'];

            $update = $request->all();
            Log::info('telegram_filestore_webhook_event', TelegramWebhookLogContext::fromUpdate($update, $botProfile['username']));

            if (!empty($update['callback_query'])) {
                $this->handleCallback($update['callback_query']);
                return response()->json(['ok' => true]);
            }

            if (!isset($update['message'])) {
                return response()->json(['ok' => true]);
            }

            $message = $update['message'];
            $chatId = (int)($message['chat']['id'] ?? 0);
            $username = $message['chat']['username'] ?? null;
            $messageId = (int)($message['message_id'] ?? 0);
            $isPrivateChat = $this->isPrivateChat($message);

            if ($chatId === 0 || $messageId <= 0) {
                return response()->json(['ok' => true]);
            }

            if (isset($message['text'])) {
                $text = trim((string)$message['text']);

                if ($isPrivateChat && $this->handleBridgeControlMessage($chatId, $username, $text)) {
                    return response()->json(['ok' => true]);
                }

                if (!$isPrivateChat) {
                    return response()->json(['ok' => true]);
                }

                if ($isPrivateChat && $text === '/start') {
                    $this->sendMessage(
                        $chatId,
                        "Filestore Bot 已啟動\n\n請直接傳送圖片、影片或檔案。\n上傳完成後按「結束上傳」即可產生分享代碼。\n\n指令：\n/myfiles 查詢我的檔案\n/delete 刪除我上傳的檔案"
                    );
                    return response()->json(['ok' => true]);
                }

                if ($isPrivateChat && $text === '/myfiles') {
                    $this->handleMyFilesCommand($chatId, 1);
                    return response()->json(['ok' => true]);
                }

                if ($isPrivateChat && Str::startsWith($text, '/delete')) {
                    $this->handleDeleteCommand($chatId, $text);
                    return response()->json(['ok' => true]);
                }

                /**
                 * 修正：解碼時不要管任何前綴，都可以嘗試解碼。
                 * 這裡把「貼代碼取檔」視為解碼行為：
                 * - 只要看起來像代碼（符合基本格式），就嘗試用原字串與補前綴兩種方式查詢
                 * - 若不存在資料庫就回「找不到檔案」
                 *
                 * 只有「加密文件」才固定要求 filestoebot_ 前綴（該邏輯若在別處使用 TOKEN_PREFIX 仍不變）
                 */
                $requestedTokens = $this->extractRequestedTokens($text);
                if (!empty($requestedTokens)) {
                    $ignoredTokens = array_values(array_filter(
                        $requestedTokens,
                        fn (string $token): bool => $this->shouldIgnoreRequestedToken($token)
                    ));

                    if (!empty($ignoredTokens)) {
                        Log::info('telegram_filestore_requested_tokens_ignored', [
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'ignored_token_count' => count($ignoredTokens),
                            'ignored_tokens_preview' => array_slice($ignoredTokens, 0, 5),
                        ]);
                    }

                    $requestedTokens = array_values(array_filter(
                        $requestedTokens,
                        fn (string $token): bool => !$this->shouldIgnoreRequestedToken($token)
                    ));

                    $requestedTokensWereTruncated = false;
                    if (count($requestedTokens) > self::MAX_TOKEN_LOOKUPS_PER_MESSAGE) {
                        Log::warning('telegram_filestore_requested_tokens_truncated', [
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'token_count' => count($requestedTokens),
                            'max_token_lookups_per_message' => self::MAX_TOKEN_LOOKUPS_PER_MESSAGE,
                        ]);

                        $requestedTokens = array_slice($requestedTokens, 0, self::MAX_TOKEN_LOOKUPS_PER_MESSAGE);
                        $requestedTokensWereTruncated = true;
                    }

                    $shouldAggregateTokenReply = count($requestedTokens) > 1;
                    $tokenResults = [];
                    $missingTokens = [];

                    foreach ($requestedTokens as $requestedToken) {
                        $normalizedForDedup = $this->normalizeTokenForDedup($requestedToken);

                        if (!$this->acquireTokenDedupLock($chatId, $normalizedForDedup)) {
                            if ($shouldAggregateTokenReply) {
                                $tokenResults[] = [
                                    'status' => 'recently_processed',
                                    'token' => $requestedToken,
                                ];
                            } else {
                                $this->sendMessage(
                                    $chatId,
                                    $this->formatTokenReply($requestedToken, '這個代碼剛剛已處理過，請稍候再試。')
                                );
                            }
                            continue;
                        }

                        if ($shouldAggregateTokenReply) {
                            $tokenResults[] = $this->queueSessionFilesByToken($chatId, $requestedToken, $requestedToken);
                            continue;
                        }

                        $handled = $this->sendSessionFilesByToken($chatId, $requestedToken, $requestedToken);
                        if (!$handled) {
                            $missingTokens[] = $requestedToken;
                        }
                    }

                    if ($shouldAggregateTokenReply) {
                        $reply = $this->formatBatchRequestedTokensReply(
                            $requestedTokens,
                            $tokenResults,
                            $requestedTokensWereTruncated
                        );

                        if ($reply !== null) {
                            $this->sendMessage($chatId, $reply);
                        }
                    } elseif (!empty($missingTokens)) {
                        $this->sendMessage($chatId, $this->formatMissingTokensReply($missingTokens));
                    }

                    return response()->json(['ok' => true]);
                }

                return response()->json(['ok' => true]);
            }

            if (!$isPrivateChat) {
                return response()->json(['ok' => true]);
            }

            $filePayload = $this->extractTelegramFilePayload($message);
            if ($filePayload === null) {
                return response()->json(['ok' => true]);
            }

            if (!$this->acquireMessageDedupLock($chatId, $messageId)) {
                Log::info('telegram_filestore_message_dedup_skip', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'file_id' => $filePayload['file_id'] ?? null,
                    'file_unique_id' => $filePayload['file_unique_id'] ?? null,
                    'file_name' => $filePayload['file_name'] ?? null,
                ]);
                return response()->json(['ok' => true]);
            }

            $sessionId = null;
            $shouldDispatchDebouncePrompt = false;
            $duplicateMessageIdToDelete = null;
            $duplicateExistingMessageId = null;

            try {
                DB::transaction(function () use (
                    $chatId,
                    $username,
                    $messageId,
                    $filePayload,
                    $message,
                    &$sessionId,
                    &$shouldDispatchDebouncePrompt,
                    &$duplicateMessageIdToDelete,
                    &$duplicateExistingMessageId
                ) {
                    $session = $this->resolveUploadingSession($chatId, $username, $filePayload);
                    $sessionId = (int)$session->id;
                    $shouldDispatchDebouncePrompt = !$this->shouldSkipDebouncePromptForSession($session);

                    $exists = TelegramFilestoreFile::query()
                        ->where('session_id', $session->id)
                        ->where('file_unique_id', $filePayload['file_unique_id'])
                        ->exists();

                    if ($exists) {
                        return;
                    }

                    $duplicate = $this->findEquivalentBridgeSessionFile($session, $filePayload);
                    if ($duplicate) {
                        $duplicateMessageIdToDelete = $messageId;
                        $duplicateExistingMessageId = (int) ($duplicate->message_id ?? 0);
                        $shouldDispatchDebouncePrompt = false;
                        return;
                    }

                    TelegramFilestoreFile::query()->create([
                        'session_id' => $session->id,
                        'chat_id' => $chatId,
                        'message_id' => $messageId,
                        'file_id' => $filePayload['file_id'],
                        'file_unique_id' => $filePayload['file_unique_id'],
                        'source_token' => $session->source_token,
                        'file_name' => $filePayload['file_name'],
                        'mime_type' => $filePayload['mime_type'],
                        'file_size' => (int)($filePayload['file_size'] ?? 0),
                        'file_type' => $filePayload['file_type'],
                        'raw_payload' => $this->safeJsonEncode($message),
                        'created_at' => now(),
                    ]);

                    $session->total_files = (int)$session->total_files + 1;
                    $session->total_size = (int)$session->total_size + (int)($filePayload['file_size'] ?? 0);
                    $session->save();
                });
            } catch (Throwable $e) {
                Log::error('telegram_filestore_transaction_failed', [
                    'chat_id' => $chatId,
                    'message_id' => $messageId,
                    'file_id' => $filePayload['file_id'] ?? null,
                    'file_unique_id' => $filePayload['file_unique_id'] ?? null,
                    'file_name' => $filePayload['file_name'] ?? null,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
                return response()->json(['ok' => true]);
            }

            if ($duplicateMessageIdToDelete !== null) {
                Log::info('telegram_filestore_equivalent_dedup_skip', [
                    'session_id' => $sessionId,
                    'message_id' => $duplicateMessageIdToDelete,
                    'existing_message_id' => $duplicateExistingMessageId,
                    'file_name' => $filePayload['file_name'] ?? null,
                    'mime_type' => $filePayload['mime_type'] ?? null,
                    'file_size' => (int) ($filePayload['file_size'] ?? 0),
                    'file_type' => $filePayload['file_type'] ?? null,
                ]);

                if (!$this->deleteMessage($chatId, (int) $duplicateMessageIdToDelete)) {
                    Log::warning('telegram_filestore_equivalent_dedup_delete_failed', [
                        'session_id' => $sessionId,
                        'message_id' => $duplicateMessageIdToDelete,
                    ]);
                }

                return response()->json(['ok' => true]);
            }

            if ($sessionId !== null && $shouldDispatchDebouncePrompt) {
                try {
                    $this->touchSessionLastFileAtAndDispatchDebounceJob($sessionId, $chatId);
                } catch (Throwable $e) {
                    Log::error('telegram_filestore_debounce_dispatch_failed', [
                        'chat_id' => $chatId,
                        'session_id' => $sessionId,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);
                }
            }

            return response()->json(['ok' => true]);
        }

        private function touchSessionLastFileAtAndDispatchDebounceJob(int $sessionId, int $chatId): void
        {
            $lastKey = $this->getDebounceLastFileAtCacheKey($sessionId);

            Cache::put(
                $lastKey,
                now()->getTimestamp(),
                now()->addMinutes(self::CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES)
            );

            TelegramFilestoreDebouncedPromptJob::dispatch($sessionId, $chatId, $this->activeBotProfileKey)
                ->delay(now()->addSeconds(self::DEBOUNCE_SECONDS));

            app(TelegramFilestoreCloseUploadPromptService::class)
                ->rescueMissingPromptIfNeeded($sessionId, $chatId, $this->activeBotProfileKey);
        }

        private function getDebounceLastFileAtCacheKey(int $sessionId): string
        {
            return 'filestore_debounce_last_file_at_' . $sessionId;
        }

        private function safeJsonEncode($value): string
        {
            try {
                $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false || $json === null) {
                    return '{}';
                }
                return $json;
            } catch (Throwable $e) {
                return '{}';
            }
        }

        private function acquireMessageDedupLock(int $chatId, int $messageId): bool
        {
            $key = 'filestore_message_dedup_' . $chatId . '_' . $messageId;
            return Cache::add($key, 1, now()->addSeconds(self::MESSAGE_DEDUP_SECONDS));
        }

        private function isPrivateChat(array $message): bool
        {
            return (string) ($message['chat']['type'] ?? '') === 'private';
        }

        private function handleCallback(array $callbackQuery): void
        {
            $data = (string)($callbackQuery['data'] ?? '');
            $chatId = (int)($callbackQuery['message']['chat']['id'] ?? 0);
            $callbackQueryId = (string)($callbackQuery['id'] ?? '');

            if ($chatId <= 0) {
                return;
            }

            if ($callbackQueryId !== '') {
                $this->answerCallbackQuery($callbackQueryId);
            }

            if ($data === 'filestore_close_upload') {
                $this->deletePromptMessageFromCallbackIfAny($callbackQuery);
                $this->closeSessionAndReturnToken($chatId);
                return;
            }

            if ($data === 'filestore_continue_upload') {
                return;
            }

            if ($data === 'filestore_cancel_upload') {
                $this->deletePromptMessageFromCallbackIfAny($callbackQuery);
                $this->cancelUploadingSession($chatId);
                return;
            }

            if ($data === 'filestore_delete_open') {
                $this->handleDeleteListPaged($chatId, 1);
                return;
            }

            if (Str::startsWith($data, 'filestore_delete_page:')) {
                $page = (int)Str::after($data, 'filestore_delete_page:');
                if ($page <= 0) {
                    $page = 1;
                }
                $this->handleDeleteListPaged($chatId, $page);
                return;
            }

            if (Str::startsWith($data, 'filestore_delete_pick:')) {
                $sessionId = (int)Str::after($data, 'filestore_delete_pick:');
                $this->askDeleteConfirm($chatId, $sessionId);
                return;
            }

            if (Str::startsWith($data, 'filestore_delete_confirm:')) {
                $sessionId = (int)Str::after($data, 'filestore_delete_confirm:');
                $this->deleteSessionOwnedByChat($chatId, $sessionId);
                return;
            }

            if (Str::startsWith($data, 'filestore_delete_cancel:')) {
                $this->sendMessage($chatId, "已取消刪除。");
                return;
            }

            if (Str::startsWith($data, 'filestore_myfiles_page:')) {
                $page = (int)Str::after($data, 'filestore_myfiles_page:');
                if ($page <= 0) {
                    $page = 1;
                }
                $this->handleMyFilesCommand($chatId, $page);
                return;
            }
        }

        private function deletePromptMessageFromCallbackIfAny(array $callbackQuery): void
        {
            $chatId = (int)($callbackQuery['message']['chat']['id'] ?? 0);
            $messageId = (int)($callbackQuery['message']['message_id'] ?? 0);

            if ($chatId <= 0 || $messageId <= 0) {
                return;
            }

            $this->deleteMessage($chatId, $messageId);
        }

        private function handleMyFilesCommand(int $chatId, int $page): void
        {
            if ($page <= 0) {
                $page = 1;
            }

            $baseQuery = TelegramFilestoreSession::query()
                ->where('chat_id', $chatId)
                ->where('status', 'closed');

            $total = (int)(clone $baseQuery)->count();

            if ($total <= 0) {
                $this->sendMessage($chatId, "你目前沒有已結束上傳的檔案。");
                return;
            }

            $pageSize = self::MYFILES_PAGE_SIZE;
            $totalPages = (int)max(1, (int)ceil($total / $pageSize));

            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $offset = ($page - 1) * $pageSize;

            $sessions = (clone $baseQuery)
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($pageSize)
                ->get();

            $lines = [];
            $lines[] = $this->escapeHtml("你的檔案清單（第 {$page} / {$totalPages} 頁，每頁 {$pageSize} 筆，共 {$total} 筆）：");
            $lines[] = "";

            foreach ($sessions as $s) {
                $token = (string)($s->public_token ?? '');
                $tokenHtml = $token !== '' ? '<code>' . $this->escapeHtml($token) . '</code>' : $this->escapeHtml('(無 token)');

                $files = (int)$s->total_files;
                $size = $this->formatBytes((int)$s->total_size);
                $shareCount = (int)$s->share_count;
                $lastShared = $s->last_shared_at ? (string)$s->last_shared_at : '無';

                $lines[] = "代碼：{$tokenHtml}";
                $lines[] = $this->escapeHtml("檔案數：{$files}　總大小：{$size}");
                $lines[] = $this->escapeHtml("被分享：{$shareCount} 次　上次分享：{$lastShared}");
                $lines[] = "";
            }

            $keyboard = $this->buildMyFilesPaginationKeyboard($page, $totalPages);

            $this->sendLongMessage($chatId, implode("\n", $lines), 'HTML');
            $this->sendMessage(
                $chatId,
                "請選擇頁次：",
                ['inline_keyboard' => $keyboard]
            );
        }

        private function buildMyFilesPaginationKeyboard(int $page, int $totalPages): array
        {
            $keyboard = [];

            $navRow = [];
            if ($page > 1) {
                $navRow[] = ['text' => '上一頁', 'callback_data' => 'filestore_myfiles_page:' . ($page - 1)];
            }
            if ($page < $totalPages) {
                $navRow[] = ['text' => '下一頁', 'callback_data' => 'filestore_myfiles_page:' . ($page + 1)];
            }
            if (!empty($navRow)) {
                $keyboard[] = $navRow;
            }

            $start = max(1, $page - (int)floor(self::MYFILES_MAX_PAGES_SHOWN / 2));
            $end = $start + self::MYFILES_MAX_PAGES_SHOWN - 1;
            if ($end > $totalPages) {
                $end = $totalPages;
                $start = max(1, $end - self::MYFILES_MAX_PAGES_SHOWN + 1);
            }

            $pageRow = [];
            for ($p = $start; $p <= $end; $p++) {
                $label = (string)$p;
                if ($p === $page) {
                    $label = '•' . $label . '•';
                }

                $pageRow[] = [
                    'text' => $label,
                    'callback_data' => 'filestore_myfiles_page:' . $p,
                ];

                if (count($pageRow) >= 5) {
                    $keyboard[] = $pageRow;
                    $pageRow = [];
                }
            }
            if (!empty($pageRow)) {
                $keyboard[] = $pageRow;
            }

            $utilRow = [];
            $utilRow[] = ['text' => '刪除檔案', 'callback_data' => 'filestore_delete_open'];
            $keyboard[] = $utilRow;

            return $keyboard;
        }

        private function handleDeleteCommand(int $chatId, string $text): void
        {
            $parts = preg_split('/\s+/', trim($text));
            $token = $parts[1] ?? null;

            if ($token !== null && $token !== '') {
                $token = trim((string)$token);

                /**
                 * 修正：/delete 也支援不帶前綴（因為你說解碼不要管任何前綴）
                 * 這裡仍維持「只能刪除你自己上傳的 closed session」的限制。
                 */
                if (!$this->looksLikeToken($token)) {
                    $this->sendMessage($chatId, "格式不正確，請輸入 /delete 代碼");
                    return;
                }

                $session = $this->findClosedSessionByTokenLoose($chatId, $token);

                if (!$session) {
                    $this->sendMessage($chatId, "找不到你可刪除的代碼（只能刪除你自己上傳的）。");
                    return;
                }

                $this->askDeleteConfirm($chatId, (int)$session->id);
                return;
            }

            $this->handleDeleteListPaged($chatId, 1);
        }

        private function handleDeleteListPaged(int $chatId, int $page): void
        {
            if ($page <= 0) {
                $page = 1;
            }

            $baseQuery = TelegramFilestoreSession::query()
                ->where('chat_id', $chatId)
                ->where('status', 'closed');

            $total = (int)(clone $baseQuery)->count();

            if ($total <= 0) {
                $this->sendMessage($chatId, "你目前沒有可刪除的檔案。");
                return;
            }

            $pageSize = self::DELETE_PAGE_SIZE;
            $totalPages = (int)max(1, (int)ceil($total / $pageSize));

            if ($page > $totalPages) {
                $page = $totalPages;
            }

            $offset = ($page - 1) * $pageSize;

            $sessions = (clone $baseQuery)
                ->orderByDesc('id')
                ->offset($offset)
                ->limit($pageSize)
                ->get();

            $lines = [];
            $lines[] = $this->escapeHtml("請選擇要刪除的分享代碼（第 {$page} / {$totalPages} 頁，每頁 {$pageSize} 筆，共 {$total} 筆）：");
            $lines[] = "";

            foreach ($sessions as $s) {
                $token = (string)($s->public_token ?? '');
                $tokenHtml = $token !== '' ? '<code>' . $this->escapeHtml($token) . '</code>' : $this->escapeHtml('(無 token)');
                $files = (int)$s->total_files;
                $size = $this->formatBytes((int)$s->total_size);

                $lines[] = "代碼：{$tokenHtml}";
                $lines[] = $this->escapeHtml("檔案數：{$files}　總大小：{$size}");
                $lines[] = "";
            }

            $keyboard = $this->buildDeletePaginationKeyboard($page, $totalPages, $sessions);

            $this->sendLongMessage($chatId, implode("\n", $lines), 'HTML');
            $this->sendMessage(
                $chatId,
                "請選擇：",
                ['inline_keyboard' => $keyboard]
            );
        }

        private function buildDeletePaginationKeyboard(int $page, int $totalPages, $sessions): array
        {
            $keyboard = [];

            $navRow = [];
            if ($page > 1) {
                $navRow[] = ['text' => '上一頁', 'callback_data' => 'filestore_delete_page:' . ($page - 1)];
            }
            if ($page < $totalPages) {
                $navRow[] = ['text' => '下一頁', 'callback_data' => 'filestore_delete_page:' . ($page + 1)];
            }
            if (!empty($navRow)) {
                $keyboard[] = $navRow;
            }

            $start = max(1, $page - (int)floor(self::DELETE_MAX_PAGES_SHOWN / 2));
            $end = $start + self::DELETE_MAX_PAGES_SHOWN - 1;
            if ($end > $totalPages) {
                $end = $totalPages;
                $start = max(1, $end - self::DELETE_MAX_PAGES_SHOWN + 1);
            }

            $pageRow = [];
            for ($p = $start; $p <= $end; $p++) {
                $label = (string)$p;
                if ($p === $page) {
                    $label = '•' . $label . '•';
                }

                $pageRow[] = [
                    'text' => $label,
                    'callback_data' => 'filestore_delete_page:' . $p,
                ];

                if (count($pageRow) >= 5) {
                    $keyboard[] = $pageRow;
                    $pageRow = [];
                }
            }
            if (!empty($pageRow)) {
                $keyboard[] = $pageRow;
            }

            foreach ($sessions as $s) {
                $tokenText = $s->public_token ?? ('session_' . $s->id);
                $keyboard[] = [
                    ['text' => '刪除 ' . $tokenText, 'callback_data' => 'filestore_delete_pick:' . $s->id],
                ];
            }

            return $keyboard;
        }

        private function askDeleteConfirm(int $chatId, int $sessionId): void
        {
            $session = TelegramFilestoreSession::query()
                ->where('id', $sessionId)
                ->first();

            if (!$session) {
                $this->sendMessage($chatId, "找不到該筆資料。");
                return;
            }

            if ((int)$session->chat_id !== $chatId) {
                $this->sendMessage($chatId, "你只能刪除你自己上傳的檔案。");
                return;
            }

            $token = $session->public_token ?? '(無 token)';
            $count = (int)$session->total_files;
            $size = $this->formatBytes((int)$session->total_size);

            $this->sendMessage(
                $chatId,
                "確定要刪除？\n\n代碼：{$token}\n檔案數：{$count}\n總大小：{$size}\n\n刪除後代碼將失效，其他人也無法再取檔。",
                [
                    'inline_keyboard' => [
                        [
                            ['text' => '確認刪除', 'callback_data' => 'filestore_delete_confirm:' . $sessionId],
                            ['text' => '取消', 'callback_data' => 'filestore_delete_cancel:' . $sessionId],
                        ],
                    ],
                ]
            );
        }

        private function deleteSessionOwnedByChat(int $chatId, int $sessionId): void
        {
            $session = TelegramFilestoreSession::query()
                ->where('id', $sessionId)
                ->first();

            if (!$session) {
                $this->sendMessage($chatId, "找不到該筆資料。");
                return;
            }

            if ((int)$session->chat_id !== $chatId) {
                $this->sendMessage($chatId, "你只能刪除你自己上傳的檔案。");
                return;
            }

            $token = $session->public_token ?? '(無 token)';

            DB::transaction(function () use ($session) {
                $session->delete();
            });

            $this->sendMessage($chatId, "已刪除 ✅\n代碼：{$token}\n此代碼已失效。");
        }

        private function getOrCreateUploadingSession(int $chatId, ?string $username): TelegramFilestoreSession
        {
            $this->staleSessionCleanupService->cleanupStaleUploadingSessions(chatId: $chatId);

            $session = TelegramFilestoreSession::query()
                ->where('chat_id', $chatId)
                ->where('status', 'uploading')
                ->whereNull('source_token')
                ->orderByDesc('id')
                ->first();

            if ($session) {
                if ($username !== null && $session->username !== $username) {
                    $session->username = $username;
                    $session->save();
                }
                return $session;
            }

            return TelegramFilestoreSession::query()->create([
                'chat_id' => $chatId,
                'username' => $username,
                'encrypt_token' => null,
                'public_token' => null,
                'source_token' => null,
                'status' => 'uploading',
                'total_files' => 0,
                'total_size' => 0,
                'share_count' => 0,
                'created_at' => now(),
                'closed_at' => null,
                'last_shared_at' => null,
            ]);
        }

        /**
         * @param array<string, mixed> $filePayload
         */
        private function resolveUploadingSession(int $chatId, ?string $username, array $filePayload): TelegramFilestoreSession
        {
            $bridgeSessionByChat = $this->findPendingBridgeUploadingSessionForChatId($chatId);
            if ($bridgeSessionByChat) {
                if ((int) $bridgeSessionByChat->chat_id !== $chatId || $bridgeSessionByChat->username !== $username) {
                    $bridgeSessionByChat->chat_id = $chatId;
                    $bridgeSessionByChat->username = $username;
                    $bridgeSessionByChat->save();
                }

                return $bridgeSessionByChat;
            }

            $bridgeSession = $this->findPendingBridgeUploadingSession($filePayload);
            if ($bridgeSession) {
                if ((int) $bridgeSession->chat_id !== $chatId || $bridgeSession->username !== $username) {
                    $bridgeSession->chat_id = $chatId;
                    $bridgeSession->username = $username;
                    $bridgeSession->save();
                }

                return $bridgeSession;
            }

            return $this->getOrCreateUploadingSession($chatId, $username);
        }

        private function shouldSkipDebouncePromptForSession(TelegramFilestoreSession $session): bool
        {
            return trim((string) ($session->source_token ?? '')) !== '';
        }

        /**
         * @param array<string, mixed> $filePayload
         */
        private function findEquivalentBridgeSessionFile(TelegramFilestoreSession $session, array $filePayload): ?TelegramFilestoreFile
        {
            if (!$this->shouldSkipDebouncePromptForSession($session)) {
                return null;
            }

            $fileType = trim((string) ($filePayload['file_type'] ?? ''));
            $fileSize = (int) ($filePayload['file_size'] ?? 0);
            if ($fileType === '' || $fileSize <= 0) {
                return null;
            }

            $query = TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->where('file_type', $fileType)
                ->where('file_size', $fileSize);

            $fileName = trim((string) ($filePayload['file_name'] ?? ''));
            if ($fileName !== '') {
                return $query
                    ->where('file_name', $fileName)
                    ->orderBy('id')
                    ->first();
            }

            $mimeType = trim((string) ($filePayload['mime_type'] ?? ''));
            if ($mimeType === '') {
                return null;
            }

            return $query
                ->whereNull('file_name')
                ->where('mime_type', $mimeType)
                ->orderBy('id')
                ->first();
        }

        /**
         * @param array<string, mixed> $filePayload
         */
        private function findPendingBridgeUploadingSession(array $filePayload): ?TelegramFilestoreSession
        {
            $messageId = (int) ($filePayload['message_id'] ?? 0);
            if ($messageId > 0) {
                $sessionId = $this->bridgeContextService->resolvePendingSessionIdForMessageId($messageId);
                if ($sessionId > 0) {
                    return TelegramFilestoreSession::query()
                        ->whereKey($sessionId)
                        ->where('status', 'uploading')
                        ->first();
                }
            }

            $fileUniqueId = trim((string) ($filePayload['file_unique_id'] ?? ''));
            if ($fileUniqueId === '') {
                return null;
            }

            $sessionId = $this->bridgeContextService->resolvePendingSessionId($fileUniqueId);
            if ($sessionId <= 0) {
                return null;
            }

            return TelegramFilestoreSession::query()
                ->whereKey($sessionId)
                ->where('status', 'uploading')
                ->first();
        }

        private function findPendingBridgeUploadingSessionForChatId(int $chatId): ?TelegramFilestoreSession
        {
            if ($chatId <= 0) {
                return null;
            }

            $sessionId = $this->bridgeContextService->resolvePendingSessionIdForChatId($chatId);
            if ($sessionId <= 0) {
                return null;
            }

            return TelegramFilestoreSession::query()
                ->whereKey($sessionId)
                ->where('status', 'uploading')
                ->first();
        }

        private function handleBridgeControlMessage(int $chatId, ?string $username, string $text): bool
        {
            if (!Str::startsWith($text, self::BRIDGE_CONTROL_PREFIX)) {
                return false;
            }

            $parts = explode('|', $text, 3);
            $sessionId = (int) ($parts[1] ?? 0);
            $sourceToken = trim((string) ($parts[2] ?? ''));

            if ($sessionId <= 0 || $sourceToken === '') {
                return true;
            }

            $session = TelegramFilestoreSession::query()
                ->whereKey($sessionId)
                ->where('status', 'uploading')
                ->first();

            if (!$session) {
                return true;
            }

            if (trim((string) ($session->source_token ?? '')) !== $sourceToken) {
                return true;
            }

            if ((int) $session->chat_id !== $chatId || $session->username !== $username) {
                $session->chat_id = $chatId;
                $session->username = $username;
                $session->save();
            }

            $this->bridgeContextService->rememberPendingChatId($sessionId, $chatId);

            return true;
        }

        private function closeSessionAndReturnToken(int $chatId): void
        {
            $session = TelegramFilestoreSession::query()
                ->where('chat_id', $chatId)
                ->where('status', 'uploading')
                ->whereNull('source_token')
                ->orderByDesc('id')
                ->first();

            if (!$session) {
                $this->sendMessage($chatId, "目前沒有進行中的上傳。");
                return;
            }

            $fileCount = TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->count();

            if ($fileCount <= 0) {
                $this->sendMessage($chatId, "這個上傳尚未收到任何檔案。");
                return;
            }

            $counts = $this->countFilesByType((int)$session->id);
            $videoCount = (int)($counts['video'] ?? 0);
            $photoCount = (int)($counts['photo'] ?? 0);
            $docCount = (int)($counts['document'] ?? 0) + (int)($counts['other'] ?? 0);

            DB::transaction(function () use ($session, $videoCount, $photoCount, $docCount) {
                $token = $this->generateUniquePublicTokenWithCounts($videoCount, $photoCount, $docCount);

                $session->public_token = $token;
                $session->encrypt_token = $this->hashForDb($token);
                $session->status = 'closed';
                $session->closed_at = now();
                $session->close_upload_prompted_at = null;
                $session->save();
            });

            $this->deleteCloseUploadPromptIfExists((int)$session->id, $chatId);

            $tokenText = (string)$session->public_token;

            $this->sendMessage(
                $chatId,
                "已結束上傳 ✅\n\n分享代碼：\n<code>{$tokenText}</code>\n\n任何人把這段代碼貼給我，就可以取得你上傳的檔案。\n\n你也可以用 /myfiles 查詢、用 /delete 刪除。",
                null,
                'HTML'
            );
        }

        private function cancelUploadingSession(int $chatId): void
        {
            $session = TelegramFilestoreSession::query()
                ->where('chat_id', $chatId)
                ->where('status', 'uploading')
                ->orderByDesc('id')
                ->first();

            if (!$session) {
                $this->sendMessage($chatId, "目前沒有進行中的上傳。");
                return;
            }

            $fileCount = (int)TelegramFilestoreFile::query()
                ->where('session_id', $session->id)
                ->count();

            DB::transaction(function () use ($session) {
                TelegramFilestoreFile::query()
                    ->where('session_id', $session->id)
                    ->delete();

                $session->delete();
            });

            $this->deleteCloseUploadPromptIfExists((int)$session->id, $chatId);

            $this->sendMessage($chatId, "已取消本次上傳 ✅\n已移除 {$fileCount} 個檔案。");
        }

        private function deleteCloseUploadPromptIfExists(int $sessionId, int $chatId): void
        {
            $promptMessageId = $this->getCloseUploadPromptMessageId($sessionId);
            if ($promptMessageId !== null) {
                $this->deleteMessage($chatId, $promptMessageId);
            }

            $this->forgetCloseUploadPromptMessageId($sessionId);
        }

        private function countFilesByType(int $sessionId): array
        {
            $rows = TelegramFilestoreFile::query()
                ->select('file_type', DB::raw('COUNT(*) as total'))
                ->where('session_id', $sessionId)
                ->groupBy('file_type')
                ->get();

            $result = [
                'photo' => 0,
                'video' => 0,
                'document' => 0,
                'other' => 0,
            ];

            foreach ($rows as $row) {
                $type = (string)($row->file_type ?? '');
                $total = (int)($row->total ?? 0);

                if ($type === 'photo') {
                    $result['photo'] = $total;
                    continue;
                }

                if ($type === 'video') {
                    $result['video'] = $total;
                    continue;
                }

                if ($type === 'document') {
                    $result['document'] = $total;
                    continue;
                }

                if ($type === 'other') {
                    $result['other'] = $total;
                    continue;
                }
            }

            return $result;
        }

        private function buildCloseUploadPromptText(int $sessionId): string
        {
            $counts = $this->countFilesByType($sessionId);

            $lines = [];
            $video = (int)($counts['video'] ?? 0);
            $photo = (int)($counts['photo'] ?? 0);
            $doc = (int)($counts['document'] ?? 0) + (int)($counts['other'] ?? 0);

            if ($video > 0) {
                $lines[] = "影片：{$video}";
            }
            if ($photo > 0) {
                $lines[] = "圖片：{$photo}";
            }
            if ($doc > 0) {
                $lines[] = "檔案：{$doc}";
            }

            if (!empty($lines)) {
                return implode('　', $lines) . "\n是否結束上傳？";
            }

            return "是否結束上傳？";
        }

        private function buildCloseUploadPromptKeyboard(): array
        {
            return [
                'inline_keyboard' => [
                    [
                        ['text' => '結束上傳', 'callback_data' => 'filestore_close_upload'],
                        ['text' => '繼續上傳', 'callback_data' => 'filestore_continue_upload'],
                    ],
                    [
                        ['text' => '取消本次上傳', 'callback_data' => 'filestore_cancel_upload'],
                    ],
                ],
            ];
        }

        private function updateCloseUploadPromptMessageIfExists(int $chatId, int $sessionId): void
        {
            $messageId = $this->getCloseUploadPromptMessageId($sessionId);
            if ($messageId === null) {
                return;
            }

            $text = $this->buildCloseUploadPromptText($sessionId);
            $keyboard = $this->buildCloseUploadPromptKeyboard();

            $ok = $this->editMessageText($chatId, $messageId, $text, $keyboard, null);
            if (!$ok) {
                $this->forgetCloseUploadPromptMessageId($sessionId);
            }
        }

        private function extractTelegramFilePayload(array $message): ?array
        {
            $messageId = (int) ($message['message_id'] ?? 0);

            if (isset($message['photo']) && is_array($message['photo']) && count($message['photo']) > 0) {
                $photo = end($message['photo']);

                if (!isset($photo['file_id'], $photo['file_unique_id'])) {
                    return null;
                }

                return [
                    'file_type' => 'photo',
                    'file_id' => (string)$photo['file_id'],
                    'file_unique_id' => (string)$photo['file_unique_id'],
                    'file_name' => null,
                    'mime_type' => null,
                    'file_size' => (int)($photo['file_size'] ?? 0),
                    'message_id' => $messageId,
                ];
            }

            if (isset($message['video']) && is_array($message['video'])) {
                $video = $message['video'];

                if (!isset($video['file_id'], $video['file_unique_id'])) {
                    return null;
                }

                return [
                    'file_type' => 'video',
                    'file_id' => (string)$video['file_id'],
                    'file_unique_id' => (string)$video['file_unique_id'],
                    'file_name' => $video['file_name'] ?? null,
                    'mime_type' => $video['mime_type'] ?? null,
                    'file_size' => (int)($video['file_size'] ?? 0),
                    'message_id' => $messageId,
                ];
            }

            if (isset($message['document']) && is_array($message['document'])) {
                $doc = $message['document'];

                if (!isset($doc['file_id'], $doc['file_unique_id'])) {
                    return null;
                }

                return [
                    'file_type' => 'document',
                    'file_id' => (string)$doc['file_id'],
                    'file_unique_id' => (string)$doc['file_unique_id'],
                    'file_name' => $doc['file_name'] ?? null,
                    'mime_type' => $doc['mime_type'] ?? null,
                    'file_size' => (int)($doc['file_size'] ?? 0),
                    'message_id' => $messageId,
                ];
            }

            return null;
        }

        /**
         * 解碼用：只要看起來像代碼就嘗試
         * - 不要求任何前綴
         * - 避免一般聊天文字一直回找不到檔案
         */
        private function looksLikeToken(string $text): bool
        {
            $t = trim($text);
            if ($t === '') {
                return false;
            }

            if (Str::startsWith($t, '/')) {
                return false;
            }

            if (mb_strlen($t, 'UTF-8') < 10) {
                return false;
            }

            return preg_match('/^[A-Za-z0-9_\-]+$/', $t) === 1;
        }

        private function extractRequestedTokens(string $text): array
        {
            $t = trim($text);
            if ($t === '') {
                return [];
            }

            $tokens = $this->telegramCodeTokenService->extractTokens($t);
            if (!empty($tokens)) {
                return array_values(array_unique(array_map(
                    static fn ($token): string => trim((string) $token),
                    $tokens
                )));
            }

            if ($this->looksLikeToken($t)) {
                return [$t];
            }

            return [];
        }

        private function formatTokenReply(string $token, string $message): string
        {
            $token = trim($token);
            if ($token === '') {
                return $message;
            }

            return $token . "\n" . $message;
        }

        /**
         * filepan_bot 不是 filestore 可解碼來源；忽略它避免大段誤貼把 webhook 拖到 timeout。
         */
        private function shouldIgnoreRequestedToken(string $token): bool
        {
            $token = trim($token);
            if ($token === '') {
                return false;
            }

            return Str::startsWith(Str::lower($token), ['@filepan_bot:', 'filepan_bot:']);
        }

        private function formatMissingTokensReply(array $tokens): string
        {
            $tokens = array_values(array_unique(array_filter(array_map(
                static fn ($token): string => trim((string) $token),
                $tokens
            ))));

            if (empty($tokens)) {
                return '找不到檔案';
            }

            if (count($tokens) === 1) {
                return $this->formatTokenReply($tokens[0], '找不到檔案');
            }

            $previewTokens = array_slice($tokens, 0, 3);
            $lines = $previewTokens;
            $remaining = count($tokens) - count($previewTokens);

            if ($remaining > 0) {
                $lines[] = '...還有 ' . $remaining . ' 個代碼';
            }

            $lines[] = '找不到檔案';

            return implode("\n", $lines);
        }

        /**
         * @param array<int, string> $requestedTokens
         * @param array<int, array{status: string, token: string}> $results
         */
        private function formatBatchRequestedTokensReply(array $requestedTokens, array $results, bool $wasTruncated): ?string
        {
            $requestedTokens = array_values(array_unique(array_filter(array_map(
                static fn ($token): string => trim((string) $token),
                $requestedTokens
            ))));

            $statusTokens = [];
            foreach ($results as $result) {
                $status = trim((string) ($result['status'] ?? ''));
                if ($status === '') {
                    continue;
                }

                $token = trim((string) ($result['token'] ?? ''));
                $statusTokens[$status] ??= [];
                $statusTokens[$status][] = $token;
            }

            if (!$wasTruncated && empty($statusTokens)) {
                return null;
            }

            $lines = [];

            if ($wasTruncated) {
                $lines[] = '這則訊息代碼太多，先只處理前 ' . self::MAX_TOKEN_LOOKUPS_PER_MESSAGE . ' 個。';
            }

            if (!empty($requestedTokens)) {
                $lines[] = '本次代碼處理結果（' . count($requestedTokens) . ' 個）：';
            } else {
                $lines[] = '本次代碼處理結果：';
            }

            $statusMap = [
                'queued' => '已加入傳送佇列',
                'requeued' => '已重新加入傳送佇列',
                'busy' => '正在傳送中',
                'queue_busy' => '佇列忙碌',
                'not_found' => '找不到檔案',
                'recently_processed' => '剛剛已處理過',
            ];

            foreach ($statusMap as $status => $label) {
                $tokens = array_values(array_unique(array_filter($statusTokens[$status] ?? [])));
                if (empty($tokens)) {
                    continue;
                }

                $lines[] = $label . '：' . count($tokens) . ' 個';

                if (in_array($status, ['busy', 'queue_busy', 'not_found', 'recently_processed'], true)) {
                    $previewTokens = array_slice($tokens, 0, 2);
                    if (!empty($previewTokens)) {
                        $lines[] = implode(' / ', $previewTokens);
                    }

                    $remaining = count($tokens) - count($previewTokens);
                    if ($remaining > 0) {
                        $lines[] = '...還有 ' . $remaining . ' 個';
                    }
                }
            }

            return implode("\n", $lines);
        }

        /**
         * 用於 token dedup：把「不帶前綴」的代碼也統一成同一把 key
         */
        private function normalizeTokenForDedup(string $text): string
        {
            $t = trim($text);
            if ($t === '') {
                return '';
            }

            $suffix = $this->stripSupportedTokenPrefix($t);
            if ($suffix === '') {
                return '';
            }

            $canonicalPrefix = $this->botProfileResolver->canonicalDecodePrefix();

            return ($canonicalPrefix !== '' ? $canonicalPrefix : self::TOKEN_PREFIX) . $suffix;
        }

        private function acquireTokenDedupLock(int $chatId, string $token): bool
        {
            $key = $this->buildTokenDedupCacheKey($chatId, $token);
            return Cache::add($key, 1, now()->addSeconds(self::TOKEN_DEDUP_SECONDS));
        }

        private function buildTokenDedupCacheKey(int $chatId, string $token): string
        {
            return 'filestore_token_dedup_' . $chatId . '_' . hash('sha256', $token);
        }

        private function generateUniquePublicTokenWithCounts(int $videoCount, int $photoCount, int $documentCount): string
        {
            $segments = [];

            if ($videoCount > 0) {
                $segments[] = (string)$videoCount . 'V';
            }

            if ($photoCount > 0) {
                $segments[] = (string)$photoCount . 'P';
            }

            if ($documentCount > 0) {
                $segments[] = (string)$documentCount . 'D';
            }

            if (empty($segments)) {
                $segments[] = '0D';
            }

            do {
                $random = Str::lower(Str::random(18));
                $token = self::TOKEN_PREFIX . implode('_', $segments) . '_' . $random;

                $exists = TelegramFilestoreSession::query()
                    ->where('public_token', $token)
                    ->exists();
            } while ($exists);

            return $token;
        }

        private function hashForDb(string $publicToken): string
        {
            return hash('sha256', $publicToken);
        }

        /**
         * @return array{key:string,username:string,token:string,prefix:string}
         */
        private function currentBotProfile(): array
        {
            return $this->botProfileResolver->resolve($this->activeBotProfileKey);
        }

        private function currentBotToken(): string
        {
            return (string) ($this->currentBotProfile()['token'] ?? '');
        }

        /**
         * @return array<int, string>
         */
        private function supportedDecodePrefixes(): array
        {
            return $this->botProfileResolver->supportedDecodePrefixes();
        }

        private function stripSupportedTokenPrefix(string $token): string
        {
            $trimmed = trim($token);
            if ($trimmed === '') {
                return '';
            }

            foreach ($this->supportedDecodePrefixes() as $prefix) {
                if ($prefix !== '' && Str::startsWith(Str::lower($trimmed), $prefix)) {
                    return substr($trimmed, strlen($prefix));
                }
            }

            return $trimmed;
        }

        private function answerCallbackQuery(string $callbackQueryId): void
        {
            $token = $this->currentBotToken();
            if ($token === '') {
                return;
            }

            Http::timeout(30)->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
            ]);
        }

        private function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): void
        {
            $token = $this->currentBotToken();
            if ($token === '') {
                return;
            }

            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }

            if ($parseMode !== null && $parseMode !== '') {
                $payload['parse_mode'] = $parseMode;
                $payload['disable_web_page_preview'] = true;
            }

            try {
                $response = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            } catch (Throwable $e) {
                Log::error('telegram_filestore_controller_send_message_exception', [
                    'chat_id' => $chatId,
                    'message_preview' => Str::limit($text, 120),
                    'message' => $e->getMessage(),
                ]);
                return;
            }

            $json = $response->json();
            $ok = $response->successful() && is_array($json) && (($json['ok'] ?? false) === true);

            if (!$ok) {
                Log::error('telegram_filestore_controller_send_message_failed', [
                    'chat_id' => $chatId,
                    'message_preview' => Str::limit($text, 120),
                    'status' => $response->status(),
                    'description' => is_array($json) ? ($json['description'] ?? '') : '',
                    'body' => $response->body(),
                ]);
            }
        }

        private function sendMessageReturningMessageId(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): ?int
        {
            $token = $this->currentBotToken();
            if ($token === '') {
                return null;
            }

            $payload = [
                'chat_id' => $chatId,
                'text' => $text,
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }

            if ($parseMode !== null && $parseMode !== '') {
                $payload['parse_mode'] = $parseMode;
                $payload['disable_web_page_preview'] = true;
            }

            try {
                $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            } catch (Throwable $e) {
                Log::error('telegram_filestore_controller_send_message_id_exception', [
                    'chat_id' => $chatId,
                    'message_preview' => Str::limit($text, 120),
                    'message' => $e->getMessage(),
                ]);
                return null;
            }

            if (!$resp->ok()) {
                Log::error('telegram_filestore_controller_send_message_id_failed', [
                    'chat_id' => $chatId,
                    'message_preview' => Str::limit($text, 120),
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                return null;
            }

            $messageId = $resp->json('result.message_id');
            if ($messageId === null) {
                return null;
            }

            return (int)$messageId;
        }

        private function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): bool
        {
            $token = $this->currentBotToken();
            if ($token === '') {
                return false;
            }

            $payload = [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => $text,
            ];

            if ($replyMarkup !== null) {
                $payload['reply_markup'] = $replyMarkup;
            }

            if ($parseMode !== null && $parseMode !== '') {
                $payload['parse_mode'] = $parseMode;
                $payload['disable_web_page_preview'] = true;
            }

            $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/editMessageText", $payload);

            return $resp->ok();
        }

        private function deleteMessage(int $chatId, int $messageId): bool
        {
            $token = $this->currentBotToken();
            if ($token === '') {
                return false;
            }

            $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/deleteMessage", [
                'chat_id' => $chatId,
                'message_id' => $messageId,
            ]);

            return $resp->ok();
        }

        private function sendLongMessage(int $chatId, string $text, ?string $parseMode = null): void
        {
            $maxBytes = 3800;
            if ($parseMode !== null && strtoupper($parseMode) === 'HTML') {
                $maxBytes = 3000;
            }

            $chunks = $this->splitByUtf8Bytes($text, $maxBytes);
            foreach ($chunks as $chunk) {
                $this->sendMessage($chatId, $chunk, null, $parseMode);
            }
        }

        private function splitByUtf8Bytes(string $text, int $maxBytes): array
        {
            $result = [];
            $current = '';

            $len = mb_strlen($text, 'UTF-8');
            for ($i = 0; $i < $len; $i++) {
                $char = mb_substr($text, $i, 1, 'UTF-8');
                $candidate = $current . $char;

                if (strlen($candidate) > $maxBytes) {
                    if ($current !== '') {
                        $result[] = $current;
                    }
                    $current = $char;
                    continue;
                }

                $current = $candidate;
            }

            if ($current !== '') {
                $result[] = $current;
            }

            return $result;
        }

        private function formatBytes(int $bytes): string
        {
            if ($bytes <= 0) {
                return '0 B';
            }

            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = 0;
            $value = (float)$bytes;

            while ($value >= 1024 && $i < count($units) - 1) {
                $value /= 1024;
                $i++;
            }

            return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') . ' ' . $units[$i];
        }

        private function escapeHtml(string $text): string
        {
            return str_replace(
                ['&', '<', '>'],
                ['&amp;', '&lt;', '&gt;'],
                $text
            );
        }

        private function getCloseUploadPromptMessageCacheKey(int $sessionId): string
        {
            return 'filestore_close_upload_prompt_message_id_' . $sessionId;
        }

        private function rememberCloseUploadPromptMessageId(int $sessionId, int $messageId): void
        {
            $key = $this->getCloseUploadPromptMessageCacheKey($sessionId);
            Cache::put($key, $messageId, now()->addMinutes(self::CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES));
        }

        private function getCloseUploadPromptMessageId(int $sessionId): ?int
        {
            $key = $this->getCloseUploadPromptMessageCacheKey($sessionId);
            $value = Cache::get($key);

            if ($value === null) {
                return null;
            }

            return (int)$value;
        }

        private function forgetCloseUploadPromptMessageId(int $sessionId): void
        {
            $key = $this->getCloseUploadPromptMessageCacheKey($sessionId);
            Cache::forget($key);
        }

        /**
         * 修正：解碼/取檔時，不限制前綴
         * - 先用原字串查
         * - 若原字串沒前綴，再補前綴查
         * - 找不到回 false（由上層回：找不到檔案）
         */
        private function sendSessionFilesByToken(int $chatId, string $publicToken, ?string $announceToken = null): bool
        {
            $result = $this->queueSessionFilesByToken($chatId, $publicToken, $announceToken);
            $announceToken = $result['token'];

            if ($result['status'] === 'not_found') {
                return false;
            }

            if ($result['status'] === 'busy') {
                $this->sendMessage(
                    $chatId,
                    $this->formatTokenReply($announceToken, "正在傳送中，請稍候…")
                );
                return true;
            }

            if ($result['status'] === 'queue_busy') {
                $this->sendMessage(
                    $chatId,
                    $this->formatTokenReply($announceToken, "目前佇列忙碌，暫時無法開始傳送，請稍後再試。")
                );
                return true;
            }

            if ($result['status'] === 'queued') {
                $this->sendMessage(
                    $chatId,
                    $this->formatTokenReply($announceToken, "已加入傳送佇列，開始處理中…")
                );
                return true;
            }

            $this->sendMessage(
                $chatId,
                $this->formatTokenReply($announceToken, "偵測到前一次傳送卡住，已重新加入傳送佇列…")
            );

            return true;
        }

        /**
         * @return array{status: string, token: string}
         */
        private function queueSessionFilesByToken(int $chatId, string $publicToken, ?string $announceToken = null): array
        {
            $publicToken = trim($publicToken);
            $announceToken = trim((string) ($announceToken ?? $publicToken));

            if ($publicToken === '') {
                return [
                    'status' => 'not_found',
                    'token' => $announceToken,
                ];
            }

            $session = $this->findClosedSessionByTokenLoose(null, $publicToken);

            if (!$session) {
                Log::info('telegram_filestore_send_token_not_found', [
                    'chat_id' => $chatId,
                    'requested_token' => $publicToken,
                ]);
                return [
                    'status' => 'not_found',
                    'token' => $announceToken,
                ];
            }

            $lockResult = $this->acquireSessionSendLock((int) $session->id, $chatId, $announceToken);

            if (($lockResult['status'] ?? null) === 'busy') {
                return [
                    'status' => 'busy',
                    'token' => $announceToken,
                ];
            }

            if (($lockResult['status'] ?? null) !== 'locked') {
                return [
                    'status' => 'queue_busy',
                    'token' => $announceToken,
                ];
            }

            $sendingStartedAt = (string) ($lockResult['sending_started_at'] ?? '');

            try {
                app(Dispatcher::class)->dispatch(new SendFilestoreSessionFilesJob(
                    (int) $session->id,
                    $chatId,
                    $this->activeBotProfileKey
                ));

                $updated = TelegramFilestoreSession::query()
                    ->where('id', $session->id)
                    ->where('is_sending', 1)
                    ->where('sending_started_at', $sendingStartedAt)
                    ->update([
                        'share_count' => DB::raw('share_count + 1'),
                        'last_shared_at' => now(),
                    ]);

                Log::info('telegram_filestore_send_dispatch_succeeded', [
                    'session_id' => (int) $session->id,
                    'chat_id' => $chatId,
                    'requested_token' => $announceToken,
                    'public_token' => $session->public_token,
                    'source_token' => $session->source_token,
                    'stale_lock_recovered' => (bool) ($lockResult['stale_lock_recovered'] ?? false),
                    'share_count_incremented' => $updated > 0,
                ]);
            } catch (Throwable $e) {
                $this->releaseSessionSendLockAfterDispatchFailure((int) $session->id, $sendingStartedAt);

                Log::error('telegram_filestore_send_dispatch_failed', [
                    'session_id' => (int) $session->id,
                    'chat_id' => $chatId,
                    'requested_token' => $announceToken,
                    'public_token' => $session->public_token,
                    'source_token' => $session->source_token,
                    'stale_lock_recovered' => (bool) ($lockResult['stale_lock_recovered'] ?? false),
                    'message' => $e->getMessage(),
                ]);

                return [
                    'status' => 'queue_busy',
                    'token' => $announceToken,
                ];
            }

            return [
                'status' => (bool) ($lockResult['stale_lock_recovered'] ?? false) ? 'requeued' : 'queued',
                'token' => $announceToken,
            ];
        }

        /**
         * @return array{status: string, sending_started_at?: string, stale_lock_recovered?: bool}
         */
        private function acquireSessionSendLock(int $sessionId, int $chatId, string $requestedToken): array
        {
            try {
                return DB::transaction(function () use ($sessionId, $chatId, $requestedToken) {
                    $fresh = TelegramFilestoreSession::query()
                        ->where('id', $sessionId)
                        ->lockForUpdate()
                        ->first();

                    if (!$fresh) {
                        return ['status' => 'missing'];
                    }

                    $staleLockRecovered = false;

                    if ((int) $fresh->is_sending === 1) {
                        if (!$this->shouldReleaseStaleSendLock($fresh)) {
                            Log::info('telegram_filestore_send_dispatch_skipped_busy', [
                                'session_id' => (int) $fresh->id,
                                'chat_id' => $chatId,
                                'requested_token' => $requestedToken,
                                'public_token' => $fresh->public_token,
                                'source_token' => $fresh->source_token,
                                'sending_started_at' => $fresh->sending_started_at,
                            ]);

                            return ['status' => 'busy'];
                        }

                        $staleLockRecovered = true;

                        Log::warning('telegram_filestore_send_stale_lock_recovered', [
                            'session_id' => (int) $fresh->id,
                            'chat_id' => $chatId,
                            'requested_token' => $requestedToken,
                            'public_token' => $fresh->public_token,
                            'source_token' => $fresh->source_token,
                            'previous_sending_started_at' => $fresh->sending_started_at,
                        ]);
                    }

                    $sendingStartedAt = now()->format('Y-m-d H:i:s');

                    $fresh->is_sending = 1;
                    $fresh->sending_started_at = $sendingStartedAt;
                    $fresh->sending_finished_at = null;
                    $fresh->save();

                    return [
                        'status' => 'locked',
                        'sending_started_at' => $sendingStartedAt,
                        'stale_lock_recovered' => $staleLockRecovered,
                    ];
                }, 3);
            } catch (Throwable $e) {
                Log::error('telegram_filestore_send_lock_failed', [
                    'session_id' => $sessionId,
                    'chat_id' => $chatId,
                    'requested_token' => $requestedToken,
                    'message' => $e->getMessage(),
                ]);

                return ['status' => 'lock_failed'];
            }
        }

        private function shouldReleaseStaleSendLock(TelegramFilestoreSession $session): bool
        {
            $startedAt = $session->sending_started_at;
            if ($startedAt === null) {
                return true;
            }

            $timestamp = strtotime((string) $startedAt);
            if ($timestamp === false) {
                return true;
            }

            return (time() - $timestamp) >= $this->getStaleSendingLockSeconds();
        }

        private function getStaleSendingLockSeconds(): int
        {
            return max(
                60,
                (int) config('telegram.filestore_sending_stale_seconds', self::STALE_SENDING_LOCK_SECONDS)
            );
        }

        private function releaseSessionSendLockAfterDispatchFailure(int $sessionId, string $sendingStartedAt): void
        {
            if ($sendingStartedAt === '') {
                return;
            }

            try {
                TelegramFilestoreSession::query()
                    ->where('id', $sessionId)
                    ->where('is_sending', 1)
                    ->where('sending_started_at', $sendingStartedAt)
                    ->update([
                        'is_sending' => 0,
                        'sending_finished_at' => now(),
                    ]);
            } catch (Throwable $e) {
                Log::error('telegram_filestore_send_lock_release_failed', [
                    'session_id' => $sessionId,
                    'sending_started_at' => $sendingStartedAt,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        /**
         * token 查詢（解碼不限制前綴）：
         * - 若有指定 chatId，代表限制只能找「該使用者」的 session（用在 /delete）
         * - chatId 為 null 代表任何人都可用代碼取檔（用在貼代碼取檔）
         */
        private function findClosedSessionByTokenLoose(?int $chatId, string $token): ?TelegramFilestoreSession
        {
            $t = trim($token);
            if ($t === '') {
                return null;
            }

            $suffix = $this->stripSupportedTokenPrefix($t);
            if ($suffix === '') {
                return null;
            }

            $candidates = [$t];
            foreach ($this->supportedDecodePrefixes() as $prefix) {
                if ($prefix === '') {
                    continue;
                }

                $candidates[] = $prefix . $suffix;
            }
            $candidates = array_values(array_unique($candidates));

            $query = TelegramFilestoreSession::query()
                ->where('status', 'closed')
                ->where(function ($builder) use ($candidates, $t) {
                    $builder->whereIn('public_token', $candidates);
                    $builder->orWhere('source_token', $t);
                });

            if ($chatId !== null) {
                $query->where('chat_id', $chatId);
            }

            return $query->first();
        }
    }
