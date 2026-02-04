<?php

    namespace App\Http\Controllers;

    use App\Jobs\SendFilestoreSessionFilesJob;
    use App\Models\TelegramFilestoreFile;
    use App\Models\TelegramFilestoreSession;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Log;
    use Illuminate\Support\Str;
    use Throwable;

    class TelegramFilestoreBotController extends Controller
    {
        private const TOKEN_PREFIX = 'filestoebot_';

        private const MYFILES_PAGE_SIZE = 10;
        private const MYFILES_MAX_PAGES_SHOWN = 7;

        /**
         * 避免短時間內重複送出「是否結束上傳？」的去重視窗（秒）
         * 例如：連續收到多個檔案訊息 / webhook 重送 / 併發，都只會在這段時間內送一次。
         */
        private const CLOSE_UPLOAD_PROMPT_DEDUP_SECONDS = 30;

        /**
         * close upload 提示訊息 message_id 的 cache TTL（分鐘）
         * 不用太精準，主要是讓 editMessageText 能找到同一則提示訊息
         */
        private const CLOSE_UPLOAD_PROMPT_MESSAGE_CACHE_MINUTES = 180;

        /**
         * /delete 清單分頁設定
         */
        private const DELETE_PAGE_SIZE = 10;
        private const DELETE_MAX_PAGES_SHOWN = 7;

        /**
         * 同一個人同一個 token 的去重時間（秒）
         * 25 秒內同 token 只解析一次，避免一直派發 job 讓機器人當機
         */
        private const TOKEN_DEDUP_SECONDS = 25;

        /**
         * 同 chat 同 message_id 去重時間（秒）
         * 避免 webhook 重送 / 併發導致重複處理
         */
        private const MESSAGE_DEDUP_SECONDS = 600;

        /**
         * 為了避免 Telegram editMessageText 被限流：
         * 同一個 session 的提示訊息，至少間隔 N 秒才更新一次
         */
        private const CLOSE_UPLOAD_PROMPT_EDIT_THROTTLE_SECONDS = 2;

        public function webhook(Request $request)
        {
            $update = $request->all();
            Log::info('telegram_filestore_update', $update);

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

            if ($chatId <= 0 || $messageId <= 0) {
                return response()->json(['ok' => true]);
            }

            if (isset($message['text'])) {
                $text = trim((string)$message['text']);

                if ($text === '/start') {
                    $this->sendMessage(
                        $chatId,
                        "Filestore Bot 已啟動\n\n請直接傳送圖片、影片或檔案。\n上傳完成後按「結束上傳」即可產生分享代碼。\n\n指令：\n/myfiles 查詢我的檔案\n/delete 刪除我上傳的檔案"
                    );
                    return response()->json(['ok' => true]);
                }

                if ($text === '/myfiles') {
                    $this->handleMyFilesCommand($chatId, 1);
                    return response()->json(['ok' => true]);
                }

                if (Str::startsWith($text, '/delete')) {
                    $this->handleDeleteCommand($chatId, $text);
                    return response()->json(['ok' => true]);
                }

                if ($this->isPublicToken($text)) {
                    if (!$this->acquireTokenDedupLock($chatId, $text)) {
                        $this->sendMessage($chatId, "這個代碼剛剛已處理過，請稍候再試。");
                        return response()->json(['ok' => true]);
                    }

                    $this->sendSessionFilesByToken($chatId, $text);
                    return response()->json(['ok' => true]);
                }

                return response()->json(['ok' => true]);
            }

            $filePayload = $this->extractTelegramFilePayload($message);
            if ($filePayload === null) {
                return response()->json(['ok' => true]);
            }

            // 同 message 去重（避免 webhook 重送/併發）
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
            $shouldAskCloseUpload = false;

            try {
                DB::transaction(function () use ($chatId, $username, $messageId, $filePayload, $message, &$sessionId, &$shouldAskCloseUpload) {
                    $session = $this->getOrCreateUploadingSession($chatId, $username);
                    $sessionId = (int)$session->id;

                    $exists = TelegramFilestoreFile::query()
                        ->where('session_id', $session->id)
                        ->where('file_unique_id', $filePayload['file_unique_id'])
                        ->exists();

                    if (!$exists) {
                        // 這裡保留你原本的寫法（你目前 DB 看到 raw_payload 已是 JSON 字串，OK）
                        TelegramFilestoreFile::query()->create([
                            'session_id' => $session->id,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'file_id' => $filePayload['file_id'],
                            'file_unique_id' => $filePayload['file_unique_id'],
                            'file_name' => $filePayload['file_name'],
                            'mime_type' => $filePayload['mime_type'],
                            'file_size' => (int)($filePayload['file_size'] ?? 0),
                            'file_type' => $filePayload['file_type'],
                            // 關鍵：存「字串」不要存 array
                            'raw_payload' => $this->safeJsonEncode($message),
                            'created_at' => now(),
                        ]);

                        $session->total_files = (int)$session->total_files + 1;
                        $session->total_size = (int)$session->total_size + (int)($filePayload['file_size'] ?? 0);
                        $session->save();
                    }

                    // 這裡只做 DB 狀態判斷，不要打 Telegram
                    $shouldAskCloseUpload = $this->markCloseUploadPromptIfAllowed($session);
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

            // ✅ commit 後再更新提示訊息（避免 Telegram API 失敗導致 DB rollback）
            if ($sessionId !== null) {
                try {
                    if ($shouldAskCloseUpload) {
                        $this->askCloseUploadWithCounts($chatId, $sessionId);
                    } else {
                        // 節流：避免每個檔案都 edit，狂撞 Telegram 限流
                        if ($this->acquirePromptEditThrottleLock($sessionId)) {
                            $this->updateCloseUploadPromptMessageIfExists($chatId, $sessionId);
                        }
                    }
                } catch (Throwable $e) {
                    Log::error('telegram_filestore_prompt_update_failed', [
                        'chat_id' => $chatId,
                        'session_id' => $sessionId,
                        'error' => $e->getMessage(),
                        'exception' => get_class($e),
                    ]);
                }
            }

            return response()->json(['ok' => true]);
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

        private function acquirePromptEditThrottleLock(int $sessionId): bool
        {
            $key = 'filestore_close_upload_prompt_edit_throttle_' . $sessionId;
            return Cache::add($key, 1, now()->addSeconds(self::CLOSE_UPLOAD_PROMPT_EDIT_THROTTLE_SECONDS));
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
                $this->closeSessionAndReturnToken($chatId);
                return;
            }

            if ($data === 'filestore_continue_upload') {
                return;
            }

            if ($data === 'filestore_cancel_upload') {
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
                if (!$this->isPublicToken($token)) {
                    $this->sendMessage($chatId, "格式不正確，請輸入 /delete filestoebot_xxx");
                    return;
                }

                $session = TelegramFilestoreSession::query()
                    ->where('public_token', $token)
                    ->where('chat_id', $chatId)
                    ->where('status', 'closed')
                    ->first();

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
            $session = TelegramFilestoreSession::query()
                ->where('chat_id', $chatId)
                ->where('status', 'uploading')
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
                'status' => 'uploading',
                'total_files' => 0,
                'total_size' => 0,
                'share_count' => 0,
                'created_at' => now(),
                'closed_at' => null,
                'last_shared_at' => null,
            ]);
        }

        private function closeSessionAndReturnToken(int $chatId): void
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

            $this->forgetCloseUploadPromptMessageId((int)$session->id);

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

            $this->forgetCloseUploadPromptMessageId((int)$session->id);

            $this->sendMessage($chatId, "已取消本次上傳 ✅\n已移除 {$fileCount} 個檔案。");
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

        private function askCloseUploadWithCounts(int $chatId, int $sessionId): void
        {
            $text = $this->buildCloseUploadPromptText($sessionId);
            $keyboard = $this->buildCloseUploadPromptKeyboard();

            $sentMessageId = $this->sendMessageReturningMessageId($chatId, $text, $keyboard, null);
            if ($sentMessageId !== null) {
                $this->rememberCloseUploadPromptMessageId($sessionId, $sentMessageId);
            }
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
                ];
            }

            return null;
        }

        private function isPublicToken(string $text): bool
        {
            return Str::startsWith($text, self::TOKEN_PREFIX) && strlen($text) > strlen(self::TOKEN_PREFIX);
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

        private function answerCallbackQuery(string $callbackQueryId): void
        {
            $token = (string)config('telegram.filestore_bot_token');
            if ($token === '') {
                return;
            }

            Http::timeout(30)->post("https://api.telegram.org/bot{$token}/answerCallbackQuery", [
                'callback_query_id' => $callbackQueryId,
            ]);
        }

        private function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): void
        {
            $token = (string)config('telegram.filestore_bot_token');
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

            Http::timeout(30)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
        }

        private function sendMessageReturningMessageId(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): ?int
        {
            $token = (string)config('telegram.filestore_bot_token');
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

            $resp = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/sendMessage", $payload);
            if (!$resp->ok()) {
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
            $token = (string)config('telegram.filestore_bot_token');
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

        private function markCloseUploadPromptIfAllowed(TelegramFilestoreSession $session): bool
        {
            $fresh = TelegramFilestoreSession::query()
                ->where('id', $session->id)
                ->lockForUpdate()
                ->first();

            if (!$fresh) {
                return false;
            }

            if ((string)$fresh->status !== 'uploading') {
                return false;
            }

            $lastAt = $fresh->close_upload_prompted_at;

            if ($lastAt) {
                $diffSeconds = now()->diffInSeconds($lastAt);
                if ($diffSeconds < self::CLOSE_UPLOAD_PROMPT_DEDUP_SECONDS) {
                    return false;
                }
            }

            $fresh->close_upload_prompted_at = now();
            $fresh->save();

            return true;
        }

        /**
         * 你原本就有 sendSessionFilesByToken / sendFileByType 等方法
         * 這段我不動你的功能，只保留你原本存在的呼叫入口
         */
        private function sendSessionFilesByToken(int $chatId, string $publicToken): void
        {
            $session = TelegramFilestoreSession::query()
                ->where('public_token', $publicToken)
                ->where('status', 'closed')
                ->first();

            if (!$session) {
                $this->sendMessage($chatId, "找不到這個代碼對應的檔案。");
                return;
            }

            $locked = false;

            DB::transaction(function () use ($session, &$locked) {
                $fresh = TelegramFilestoreSession::query()
                    ->where('id', $session->id)
                    ->lockForUpdate()
                    ->first();

                if (!$fresh) {
                    $locked = false;
                    return;
                }

                if ((int)$fresh->is_sending === 1) {
                    $locked = false;
                    return;
                }

                $fresh->is_sending = 1;
                $fresh->sending_started_at = now();
                $fresh->sending_finished_at = null;
                $fresh->share_count = (int)$fresh->share_count + 1;
                $fresh->last_shared_at = now();
                $fresh->save();

                $locked = true;
            });

            if (!$locked) {
                $this->sendMessage($chatId, "正在傳送中，請稍候…");
                return;
            }

            $this->sendMessage($chatId, "已加入傳送佇列，準備開始傳送…");

            SendFilestoreSessionFilesJob::dispatch((int)$session->id, $chatId);
        }
    }
