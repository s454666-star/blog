<?php

    namespace App\Console\Commands;

    use App\Models\TelegramFilestoreFile;
    use App\Models\TelegramFilestoreSession;
    use Illuminate\Console\Command;
    use Illuminate\Support\Facades\DB;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Str;
    use Throwable;

    class RebuildFilestoreSessionsFromDialoguesCommand extends Command
    {
        protected $signature = 'filestore:rebuild-from-dialogues {text? : 指定要處理的 token/text（可含 link:），不填則從 dialogues 最新往前跑}';

        protected $description = '從 dialogues(新到舊) 取 text，若 public_token 不存在就打本地 API 建立 telegram_filestore_sessions / telegram_filestore_files（就算沒檔案也先建空 session 防止重複）';

        public function handle(): int
        {
            $apiUrl = 'http://127.0.0.1:8000/bots/send-and-run-all-pages';

            $manualText = $this->argument('text');
            $manualText = is_string($manualText) ? trim($manualText) : '';

            $this->info('開始處理 tokens...');

            $seenTokens = [];
            $totalRows = 0;
            $totalCandidates = 0;
            $totalSkippedEmpty = 0;
            $totalSkippedDupInRun = 0;
            $totalSkippedExists = 0;
            $totalCreatedSessions = 0;
            $totalCreatedFiles = 0;
            $totalApiFailed = 0;

            if ($manualText !== '') {
                $this->info('使用手動輸入 text 模式');

                $row = DB::table('dialogues')
                    ->select(['id', 'chat_id', 'message_id', 'text', 'created_at'])
                    ->where('text', $manualText)
                    ->orderByDesc('id')
                    ->first();

                if (!$row) {
                    $this->error('找不到 dialogues 中完全相同的 text，無法取得 chat_id，因此不執行。');
                    $this->printSummary(
                        $totalRows,
                        $totalCandidates,
                        $totalSkippedEmpty,
                        $totalSkippedDupInRun,
                        $totalSkippedExists,
                        $totalCreatedSessions,
                        $totalCreatedFiles,
                        $totalApiFailed
                    );
                    return self::SUCCESS;
                }

                $totalRows = 1;

                $chatId = (int)($row->chat_id ?? 0);
                if ($chatId <= 0) {
                    $this->error('該筆 dialogues 的 chat_id 無效，無法建立 session。');
                    $this->printSummary(
                        $totalRows,
                        $totalCandidates,
                        $totalSkippedEmpty,
                        $totalSkippedDupInRun,
                        $totalSkippedExists,
                        $totalCreatedSessions,
                        $totalCreatedFiles,
                        $totalApiFailed
                    );
                    return self::SUCCESS;
                }

                $rawText = trim((string)($row->text ?? ''));
                $token = $this->normalizeTokenFromDialogueText($rawText);

                if ($token === '') {
                    $totalSkippedEmpty++;
                    $this->error('輸入的 text 無法解析出 token。');
                    $this->printSummary(
                        $totalRows,
                        $totalCandidates,
                        $totalSkippedEmpty,
                        $totalSkippedDupInRun,
                        $totalSkippedExists,
                        $totalCreatedSessions,
                        $totalCreatedFiles,
                        $totalApiFailed
                    );
                    return self::SUCCESS;
                }

                $totalCandidates++;

                $this->processOneToken(
                    $apiUrl,
                    $chatId,
                    $token,
                    $seenTokens,
                    $totalSkippedDupInRun,
                    $totalSkippedExists,
                    $totalCreatedSessions,
                    $totalCreatedFiles,
                    $totalApiFailed
                );

                $this->info('處理結束（手動 text 模式）');
                $this->printSummary(
                    $totalRows,
                    $totalCandidates,
                    $totalSkippedEmpty,
                    $totalSkippedDupInRun,
                    $totalSkippedExists,
                    $totalCreatedSessions,
                    $totalCreatedFiles,
                    $totalApiFailed
                );

                return self::SUCCESS;
            }

            $this->info('使用 dialogues 最新往前模式（新到舊）');

            $cursorId = (int)(DB::table('dialogues')->max('id') ?? 0);
            if ($cursorId <= 0) {
                $this->info('dialogues 沒有資料，結束。');
                $this->printSummary(
                    $totalRows,
                    $totalCandidates,
                    $totalSkippedEmpty,
                    $totalSkippedDupInRun,
                    $totalSkippedExists,
                    $totalCreatedSessions,
                    $totalCreatedFiles,
                    $totalApiFailed
                );
                return self::SUCCESS;
            }

            while ($cursorId > 0) {
                $rows = DB::table('dialogues')
                    ->select(['id', 'chat_id', 'message_id', 'text', 'created_at'])
                    ->where('id', '<=', $cursorId)
                    ->orderByDesc('id')
                    ->limit(500)
                    ->get();

                if ($rows->isEmpty()) {
                    break;
                }

                $minIdInBatch = $cursorId;

                foreach ($rows as $row) {
                    $totalRows++;

                    $rowId = (int)($row->id ?? 0);
                    if ($rowId > 0 && $rowId < $minIdInBatch) {
                        $minIdInBatch = $rowId;
                    }

                    $chatId = (int)($row->chat_id ?? 0);
                    if ($chatId <= 0) {
                        continue;
                    }

                    $rawText = trim((string)($row->text ?? ''));
                    if ($rawText === '') {
                        $totalSkippedEmpty++;
                        continue;
                    }

                    $token = $this->normalizeTokenFromDialogueText($rawText);
                    if ($token === '') {
                        $totalSkippedEmpty++;
                        continue;
                    }

                    $totalCandidates++;

                    $this->processOneToken(
                        $apiUrl,
                        $chatId,
                        $token,
                        $seenTokens,
                        $totalSkippedDupInRun,
                        $totalSkippedExists,
                        $totalCreatedSessions,
                        $totalCreatedFiles,
                        $totalApiFailed
                    );
                }

                $cursorId = $minIdInBatch - 1;
            }

            $this->info('處理結束');
            $this->printSummary(
                $totalRows,
                $totalCandidates,
                $totalSkippedEmpty,
                $totalSkippedDupInRun,
                $totalSkippedExists,
                $totalCreatedSessions,
                $totalCreatedFiles,
                $totalApiFailed
            );

            return self::SUCCESS;
        }

        private function processOneToken(
            string $apiUrl,
            int $chatId,
            string $token,
            array &$seenTokens,
            int &$totalSkippedDupInRun,
            int &$totalSkippedExists,
            int &$totalCreatedSessions,
            int &$totalCreatedFiles,
            int &$totalApiFailed
        ): void {
            if (isset($seenTokens[$token])) {
                $totalSkippedDupInRun++;
                return;
            }
            $seenTokens[$token] = true;

            $exists = TelegramFilestoreSession::query()
                ->where('public_token', $token)
                ->exists();

            if ($exists) {
                $totalSkippedExists++;
                $this->line("跳過：已存在 token={$token}");
                return;
            }

            $this->info("處理：token={$token} chat_id={$chatId}");

            $apiPayload = [
                'bot_username' => 'ShowFilesBot',
                'text' => $token,
                'clear_previous_replies' => true,
                'delay_seconds' => 3,
                'max_steps' => 50,
                'wait_first_callback_timeout_seconds' => 25,
                'wait_each_page_timeout_seconds' => 25,
                'debug' => true,
                'debug_max_logs' => 2000,
            ];

            $apiJson = null;
            $filesFromApi = [];

            try {
                $resp = Http::timeout(120)
                    ->acceptJson()
                    ->asJson()
                    ->post($apiUrl, $apiPayload);

                if (!$resp->ok()) {
                    $totalApiFailed++;
                    $this->error("API 失敗：HTTP {$resp->status()} token={$token}");
                } else {
                    $apiJson = $resp->json();

                    if (is_array($apiJson)) {
                        $maybeFiles = $apiJson['files'] ?? null;
                        if (is_array($maybeFiles)) {
                            $filesFromApi = $maybeFiles;
                        }
                    }
                }
            } catch (Throwable $e) {
                $totalApiFailed++;
                $this->error("API 例外：token={$token} err={$e->getMessage()}");
            }

            sleep(1);

            try {
                DB::transaction(function () use (
                    $chatId,
                    $token,
                    $apiJson,
                    $filesFromApi,
                    &$totalCreatedSessions,
                    &$totalCreatedFiles
                ) {
                    $session = TelegramFilestoreSession::query()->create([
                        'chat_id' => $chatId,
                        'username' => null,
                        'encrypt_token' => $this->hashForDb($token),
                        'public_token' => $token,
                        'status' => 'closed',
                        'total_files' => 0,
                        'total_size' => 0,
                        'share_count' => 0,
                        'last_shared_at' => null,
                        'close_upload_prompted_at' => null,
                        'is_sending' => 0,
                        'sending_started_at' => null,
                        'sending_finished_at' => null,
                        'created_at' => now(),
                        'closed_at' => now(),
                    ]);

                    $totalCreatedSessions++;

                    $sumSize = 0;
                    $countFiles = 0;

                    foreach ($filesFromApi as $f) {
                        if (!is_array($f)) {
                            continue;
                        }

                        $fileId = (string)($f['file_id'] ?? '');
                        $fileUniqueId = (string)($f['file_unique_id'] ?? '');
                        if ($fileId === '' || $fileUniqueId === '') {
                            continue;
                        }

                        $messageId = (int)($f['message_id'] ?? 0);
                        if ($messageId <= 0) {
                            $messageId = 0;
                        }

                        $fileName = $f['file_name'] ?? null;
                        if ($fileName !== null) {
                            $fileName = (string)$fileName;
                            if ($fileName === '') {
                                $fileName = null;
                            }
                        }

                        $mimeType = $f['mime_type'] ?? null;
                        if ($mimeType !== null) {
                            $mimeType = (string)$mimeType;
                            if ($mimeType === '') {
                                $mimeType = null;
                            }
                        }

                        $fileSize = (int)($f['file_size'] ?? 0);
                        if ($fileSize < 0) {
                            $fileSize = 0;
                        }

                        $fileType = (string)($f['file_type'] ?? 'other');
                        $fileType = $this->normalizeFileType($fileType);

                        TelegramFilestoreFile::query()->create([
                            'session_id' => (int)$session->id,
                            'chat_id' => $chatId,
                            'message_id' => $messageId,
                            'file_id' => $fileId,
                            'file_unique_id' => $fileUniqueId,
                            'file_name' => $fileName,
                            'mime_type' => $mimeType,
                            'file_size' => $fileSize,
                            'file_type' => $fileType,
                            'raw_payload' => $this->safeJsonEncode($f),
                            'created_at' => now(),
                        ]);

                        $countFiles++;
                        $sumSize += $fileSize;
                    }

                    $session->total_files = $countFiles;
                    $session->total_size = $sumSize;

                    if ($apiJson !== null) {
                        $session->last_shared_at = null;
                    }

                    $session->save();

                    $totalCreatedFiles += $countFiles;
                });

                $this->line("完成：token={$token}（session 已建立；files=" . count($filesFromApi) . '）');
            } catch (Throwable $e) {
                $this->error("DB 寫入失敗：token={$token} err={$e->getMessage()}");
            }
        }

        private function printSummary(
            int $totalRows,
            int $totalCandidates,
            int $totalSkippedEmpty,
            int $totalSkippedDupInRun,
            int $totalSkippedExists,
            int $totalCreatedSessions,
            int $totalCreatedFiles,
            int $totalApiFailed
        ): void {
            $this->line("dialogues_rows={$totalRows}");
            $this->line("candidates={$totalCandidates}");
            $this->line("skipped_empty={$totalSkippedEmpty}");
            $this->line("skipped_dup_in_run={$totalSkippedDupInRun}");
            $this->line("skipped_exists={$totalSkippedExists}");
            $this->line("created_sessions={$totalCreatedSessions}");
            $this->line("created_files={$totalCreatedFiles}");
            $this->line("api_failed={$totalApiFailed}");
        }

        private function normalizeTokenFromDialogueText(string $text): string
        {
            $s = trim($text);
            if ($s === '') {
                return '';
            }

            if (Str::startsWith(Str::lower($s), 'link:')) {
                $s = trim((string)Str::after($s, ':'));
            }

            $s = trim($s);
            if ($s === '') {
                return '';
            }

            if (strlen($s) > 255) {
                $s = substr($s, 0, 255);
            }

            return $s;
        }

        private function hashForDb(string $publicToken): string
        {
            return hash('sha256', $publicToken);
        }

        private function normalizeFileType(string $fileType): string
        {
            $t = strtolower(trim($fileType));
            if ($t === 'photo') {
                return 'photo';
            }
            if ($t === 'video') {
                return 'video';
            }
            if ($t === 'document') {
                return 'document';
            }
            return 'other';
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
    }
