<?php

namespace App\Console\Commands;

use App\Models\TokenScanItem;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class DispatchTokenScanItemsCommand extends Command
{
    protected $signature = 'tg:dispatch-token-scan-items
        {tokens?* : Tokens to process. If omitted, read from token_scan_items}
        {--done-action=delete : Action after success: delete or touch}
        {--limit=0 : Max rows to read from token_scan_items. 0 means unlimited}
        {--port=8000 : Telegram FastAPI service port. Default 8000}
        {--base-uri=* : Explicit Telegram API base URI(s). Overrides --port}
        {--fallback-newjmqbot : Deprecated and ignored. @newjmqbot is disabled.}
        {--include-processed : Include rows with updated_at already set}';

    protected $description = 'Dispatch token_scan_items tokens to Telegram bots and delete or touch rows after success.';

    private const BOT_MODE_PAGINATE = 'paginate';
    private const BOT_MODE_CLICK_BUTTON = 'click_button';

    private const BOT_MESSENGER = [
        'api' => 'MessengerCode_bot',
        'display' => '@MessengerCode_bot',
        'mode' => self::BOT_MODE_PAGINATE,
    ];

    private const BOT_VIPFILES = [
        'api' => 'vipfiles2bot',
        'display' => '@vipfiles2bot',
        'mode' => self::BOT_MODE_PAGINATE,
    ];

    private const BOT_ATFILESLINKS = [
        'api' => 'atfileslinksbot',
        'display' => '@atfileslinksbot',
        'mode' => self::BOT_MODE_PAGINATE,
    ];

    private const BOT_LDDEE = [
        'api' => 'lddeebot',
        'display' => '@lddeebot',
        'mode' => self::BOT_MODE_PAGINATE,
    ];

    private const BOT_QQFILE = [
        'api' => 'QQfile_bot',
        'display' => '@QQfile_bot',
        'mode' => self::BOT_MODE_CLICK_BUTTON,
    ];

    private const BOT_YZFILE = [
        'api' => 'yzfile_bot',
        'display' => '@yzfile_bot',
        'mode' => self::BOT_MODE_CLICK_BUTTON,
    ];

    private const DEFAULT_API_HOST = 'http://127.0.0.1';
    private const DEFAULT_API_PORT = 8000;
    private const BOT_REPLIES_FETCH_LIMIT = 80;
    private const QQ_YZ_MAX_ATTEMPTS = 20;
    private const QQ_YZ_INITIAL_OBSERVE_TIMEOUT_SECONDS = 30;
    private const QQ_YZ_COMPLETION_TIMEOUT_SECONDS = 900;
    private const QQ_YZ_COMPLETION_POLL_MICROSECONDS = 5000000;
    private const QQ_YZ_RETRY_BUDGET_SECONDS = 300;
    private const QQ_YZ_MAX_CONTINUE_PUSH_CLICKS = 20;
    private const QQ_YZ_NEXT_TOKEN_DELAY_MICROSECONDS = 8000000;
    private const INITIAL_API_TIMEOUT_SECONDS = 60;
    private const FOLLOWUP_API_TIMEOUT_SECONDS = 900;
    private const QQ_YZ_SYNC_MARKER = '当前解码器未完成同步';
    private const PUSH_ALL_BUTTON_KEYWORDS = ['推送全部'];
    private const QQ_YZ_ACCEPTED_MARKERS = [
        '添加成功，任务处理中',
        '添加成功,任务处理中',
        '添加成功，任务处理',
    ];
    private const QQ_YZ_QUEUE_BUSY_MARKERS = [
        '您当前仍有推送队列正在处理',
        '当前仍有推送队列正在处理',
    ];
    private const QQ_YZ_RETRY_LATER_MARKERS = [
        '当前资源已经获取，请24小时后重试',
        '当前资源已经获取,请24小时后重试',
    ];
    private const QQ_YZ_ALREADY_PARSED_MARKERS = [
        '解析过此资源',
        '解析過此資源',
    ];
    private const QQ_YZ_COMPLETION_MARKERS = [
        '文件获取完毕',
    ];
    private const QQ_YZ_CONTINUE_PUSH_STOP_MARKERS = [
        '推送已停止',
        '最大推送页数',
    ];
    private const QQ_YZ_TOTAL_MARKERS = [
        '文件总数',
        '文件總數',
    ];
    private const QQ_YZ_CONTINUE_PUSH_BUTTON_KEYWORDS = [
        '继续推送',
    ];
    private const QQ_YZ_FALLBACK_BUTTON_KEYWORDS = [
        '查看全部文件',
    ];
    private const QQ_YZ_CALLBACK_RETRY_MARKERS = [
        'invalid_callback',
        'did not answer to the callback query in time',
    ];

    private const NOT_FOUND_MARKERS = [
        '💔抱歉，未找到可解析内容。',
        '抱歉，未找到可解析内容。',
        '抱歉，未找到可解析内容',
        '未找到可解析内容。已加入缓存列表，稍后进行请求。',
        '未找到可解析内容',
        '已加入缓存列表，稍后进行请求。',
    ];

    public function handle(): int
    {
        $doneAction = $this->normalizeDoneAction((string) $this->option('done-action'));
        if ($doneAction === null) {
            $this->error('done-action only supports touch or delete');
            return self::INVALID;
        }

        $manualTokens = $this->normalizeTokenInputs((array) $this->argument('tokens'));
        $limit = max((int) $this->option('limit'), 0);
        $includeProcessed = (bool) $this->option('include-processed');
        $jobs = $this->buildJobs($manualTokens, $limit, $includeProcessed);

        if (empty($jobs)) {
            $this->info('No token jobs found.');
            return self::SUCCESS;
        }

        $stats = [
            'total' => count($jobs),
            'success' => 0,
            'skipped_size_limit' => 0,
            'not_found' => 0,
            'failed' => 0,
            'touched' => 0,
            'deleted' => 0,
            'manual_only' => 0,
        ];

        $totalJobs = count($jobs);
        $remainingUnprocessed = 0;
        $stoppedEarly = false;

        $this->line('done_action=' . $doneAction . ' jobs=' . $totalJobs);

        foreach ($jobs as $index => $job) {
            $stats['manual_only'] += $job['item'] instanceof TokenScanItem ? 0 : 1;

            $this->line(str_repeat('-', 100));
            $this->line(sprintf(
                '[%d/%d] id=%s token=%s',
                $index + 1,
                $totalJobs,
                $job['item'] instanceof TokenScanItem ? (string) $job['item']->id : 'manual',
                $job['token']
            ));

            $result = $this->processOneToken($job, $doneAction);
            $classification = (string) $result['classification'];

            if ($classification === 'success') {
                $stats['success']++;
            } elseif ($classification === 'skipped_size_limit') {
                $stats['skipped_size_limit']++;
            } elseif ($classification === 'not_found') {
                $stats['not_found']++;
            } else {
                $stats['failed']++;
            }

            if (($result['db_action'] ?? '') === 'touch') {
                $stats['touched']++;
            }
            if (($result['db_action'] ?? '') === 'delete') {
                $stats['deleted']++;
            }

            $this->line(sprintf(
                'bot=%s api=%s status=%s files=%d total_size=%s latest_kind=%s db_action=%s',
                $result['bot_display'],
                $result['base_uri'] ?: '-',
                $result['api_status'] ?: 'fail',
                (int) $result['files_unique_count'],
                $this->formatBytes((int) ($result['files_total_bytes'] ?? 0)),
                $result['latest_kind'] ?: '-',
                $result['db_action'] ?: 'keep'
            ));

            if (!empty($result['summary'])) {
                $this->line((string) $result['summary']);
            }

            if (!empty($result['latest_text_preview'])) {
                $this->line('latest=' . $result['latest_text_preview']);
            }

            $this->printTimelineTail((array) ($result['timeline'] ?? []));
            $this->printDebugTail((array) ($result['debug'] ?? []));

            if (($result['stop_processing'] ?? false) === true) {
                $remainingUnprocessed = max(0, $totalJobs - ($index + 1));
                $stoppedEarly = true;
                $this->warn('Stopping dispatch after unresolved QQ/yz run so the next token does not start early.');
                break;
            }

            if ($index < ($totalJobs - 1)) {
                $nextJob = $jobs[$index + 1] ?? null;
                $this->sleepBeforeNextJobIfNeeded($result, is_array($nextJob) ? $nextJob : null);
            }
        }

        $this->line(str_repeat('=', 100));
        $this->line('total=' . $stats['total']);
        $this->line('success=' . $stats['success']);
        $this->line('skipped_size_limit=' . $stats['skipped_size_limit']);
        $this->line('not_found=' . $stats['not_found']);
        $this->line('failed=' . $stats['failed']);
        $this->line('touched=' . $stats['touched']);
        $this->line('deleted=' . $stats['deleted']);
        $this->line('manual_only=' . $stats['manual_only']);
        if ($stoppedEarly) {
            $this->line('stopped_early=1');
            $this->line('remaining_unprocessed=' . $remainingUnprocessed);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{token:string,item:TokenScanItem|null}>
     */
    private function buildJobs(array $manualTokens, int $limit, bool $includeProcessed): array
    {
        if (!empty($manualTokens)) {
            return $this->buildManualJobs($manualTokens, $includeProcessed);
        }

        $query = TokenScanItem::query()->orderByDesc('id');

        if (!$includeProcessed) {
            $query->whereNull('updated_at');
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $jobs = [];
        foreach ($query->get() as $row) {
            $jobs[] = [
                'token' => (string) $row->token,
                'item' => $row,
            ];
        }

        return $jobs;
    }

    /**
     * @return array<int, array{token:string,item:TokenScanItem|null}>
     */
    private function buildManualJobs(array $manualTokens, bool $includeProcessed): array
    {
        $query = TokenScanItem::query()
            ->whereIn('token', $manualTokens)
            ->orderByDesc('id');

        if (!$includeProcessed) {
            $query->whereNull('updated_at');
        }

        $rowsByToken = [];
        foreach ($query->get() as $row) {
            $token = (string) $row->token;
            if (!isset($rowsByToken[$token])) {
                $rowsByToken[$token] = [];
            }
            $rowsByToken[$token][] = $row;
        }

        $jobs = [];
        foreach ($manualTokens as $token) {
            $matchedRows = $rowsByToken[$token] ?? [];
            if (!empty($matchedRows)) {
                foreach ($matchedRows as $row) {
                    $jobs[] = [
                        'token' => $token,
                        'item' => $row,
                    ];
                }
                continue;
            }

            $jobs[] = [
                'token' => $token,
                'item' => null,
            ];
        }

        return $jobs;
    }

    /**
     * @param array{token:string,item:TokenScanItem|null} $job
     * @return array<string, mixed>
     */
    private function processOneToken(array $job, string $doneAction): array
    {
        $token = (string) $job['token'];
        $item = $job['item'];
        $primaryBot = $this->resolveBotByToken($token);
        $bot = $primaryBot;
        $dispatchText = null;
        $attempt = 0;
        $retryDeadline = microtime(true) + self::QQ_YZ_RETRY_BUDGET_SECONDS;

        while (true) {
            $attempt++;
            $result = $this->runBotAttempt($token, $bot, $dispatchText);

            if (
                ($result['retry_after_rate_limit'] ?? false) === true
                && ($result['bot_api'] ?? '') === self::BOT_MESSENGER['api']
                && $attempt < self::QQ_YZ_MAX_ATTEMPTS
                && microtime(true) < $retryDeadline
            ) {
                $waitSeconds = max(1, (int) ($result['retry_after_seconds'] ?? 0));
                $this->line('messenger_rate_limit_wait=' . $waitSeconds . ' retry_current_token=1');
                sleep($waitSeconds);
                continue;
            }

            if ($this->shouldFallbackToYzfile($bot, $result)) {
                $bot = self::BOT_YZFILE;
                $dispatchText = (string) ($result['yz_start_command'] ?? '');
                $fallback = $this->runBotAttempt($token, $bot, $dispatchText);
                $fallbackSummary = 'Fallback after @QQfile_bot requested @yzfile_bot';
                $fallback['summary'] = trim($fallbackSummary . '. ' . ($fallback['summary'] ?? ''));
                $result = $fallback;
            }

            if ($this->isQqOrYzBotApi((string) ($result['bot_api'] ?? ''))) {
                $result = $this->waitForQqYzCompletion($result);

                if (
                    ($result['retry_after_rate_limit'] ?? false) === true
                    && $attempt < self::QQ_YZ_MAX_ATTEMPTS
                    && microtime(true) < $retryDeadline
                ) {
                    $waitSeconds = max(1, (int) ($result['retry_after_seconds'] ?? 0));
                    $this->line('qq_yz_rate_limit_wait=' . $waitSeconds . ' retry_current_token=1');
                    sleep($waitSeconds);
                    continue;
                }

                if (
                    ($result['retry_after_callback_error'] ?? false) === true
                    && $attempt < self::QQ_YZ_MAX_ATTEMPTS
                    && microtime(true) < $retryDeadline
                ) {
                    $this->line('qq_yz_callback_retry=1 retry_current_token=1');
                    sleep(2);
                    continue;
                }

                if (
                    ($result['retry_after_queue_clear'] ?? false) === true
                    && $attempt < self::QQ_YZ_MAX_ATTEMPTS
                    && microtime(true) < $retryDeadline
                ) {
                    $this->line('qq_yz_queue_cleared=1 retry_current_token=1');
                    sleep(3);
                    continue;
                }
            }

            break;
        }

        if ($result['classification'] === 'success' && $item instanceof TokenScanItem) {
            $result['db_action'] = $this->applyDoneAction($item, $doneAction);
        }

        if ($result['classification'] === 'success' && !($item instanceof TokenScanItem)) {
            $result['db_action'] = 'manual';
        }

        return $result;
    }

    /**
     * @param array{api:string,display:string} $bot
     * @return array<string, mixed>
     */
    private function runBotAttempt(string $token, array $bot, ?string $sendText = null): array
    {
        $textToSend = trim((string) ($sendText ?? '')) !== ''
            ? trim((string) $sendText)
            : $token;
        $apiCall = $this->callTelegramApi($bot, $textToSend);
        $baseUri = (string) ($apiCall['base_uri'] ?? '');
        $apiStatus = '';
        $responseJson = [];

        if (($apiCall['ok'] ?? false) === true) {
            $apiStatus = (string) ($apiCall['http_status'] ?? '');
            $responseJson = is_array($apiCall['json'] ?? null) ? $apiCall['json'] : [];
        }

        $classification = 'failed';
        $dbAction = '';
        $summary = '';
        $timeline = is_array($responseJson['timeline'] ?? null) ? $responseJson['timeline'] : [];
        $debug = is_array($responseJson['debug'] ?? null) ? $responseJson['debug'] : [];

        $latestMessage = is_array($responseJson['latest_message'] ?? null) ? $responseJson['latest_message'] : [];
        $latestKind = (string) ($latestMessage['kind'] ?? '');
        $latestTextPreview = trim((string) ($latestMessage['text_preview'] ?? ''));
        $latestHasButtons = (bool) ($latestMessage['has_buttons'] ?? false);
        $buttonClicked = (bool) ($responseJson['button_clicked'] ?? false);
        $clickedButtonText = trim((string) ($responseJson['clicked_button_text'] ?? ''));
        $yzStartCommand = $this->extractYzStartCommand($responseJson, $latestTextPreview);
        $sentMessageId = (int) ($apiCall['sent_message_id'] ?? 0);

        $filesMeta = is_array($responseJson['files'] ?? null) ? $responseJson['files'] : [];
        $filesUniqueCount = (int) ($responseJson['files_unique_count'] ?? $filesMeta['files_unique_count'] ?? $filesMeta['files_count'] ?? 0);
        $filesTotalBytes = (int) ($responseJson['files_total_bytes'] ?? 0);
        $pageState = is_array($responseJson['page_state'] ?? null) ? $responseJson['page_state'] : [];

        $notFound = $this->responseContainsNotFound($responseJson, $latestTextPreview);
        $apiJsonStatus = (string) ($responseJson['status'] ?? '');
        $apiReason = trim((string) ($responseJson['reason'] ?? ''));
        $fullyCompleted = $this->isVipfilesBot($bot['api'])
            ? $this->isVipfilesRunCompleted($responseJson, $latestMessage, $pageState, $filesUniqueCount)
            : $this->isMessengerRunCompleted(
                $responseJson,
                $apiJsonStatus,
                $apiReason,
                $latestKind,
                $latestTextPreview,
                $latestHasButtons,
                $filesUniqueCount
            );

        if (($apiCall['ok'] ?? false) !== true) {
            $summary = 'api_error=' . ($apiCall['error'] ?? 'unknown');
        } elseif ($notFound) {
            $classification = 'not_found';
            $summary = 'Bot returned not found. Keep token_scan_items row untouched.';
        } elseif (($bot['api'] ?? '') === self::BOT_MESSENGER['api'] && ($retryAfterSeconds = $this->extractMessengerRetryAfterSeconds($latestTextPreview)) !== null) {
            $summary = 'Messenger bot rate limited. Wait ' . $retryAfterSeconds . ' seconds and retry the current token.';
        } elseif (($bot['api'] ?? '') === self::BOT_QQFILE['api'] && $this->responseRequestsYzFallback($latestTextPreview, $apiReason) && $yzStartCommand !== null) {
            $summary = 'QQ bot requested yzfile_bot redirect.';
        } elseif (($bot['mode'] ?? self::BOT_MODE_PAGINATE) === self::BOT_MODE_CLICK_BUTTON && $buttonClicked) {
            $summary = 'Clicked button ' . ($clickedButtonText !== '' ? $clickedButtonText : '推送全部') . '. Waiting for completion marker.';
        } elseif ($fullyCompleted) {
            $classification = 'success';
            $summary = 'Completed without local download. files_unique_count=' . $filesUniqueCount;
        } elseif ($bot['api'] === self::BOT_MESSENGER['api'] && $latestKind === 'completion') {
            $classification = 'success';
            $summary = 'Messenger bot returned completion message without local download.';
        } else {
            if ($filesUniqueCount > 0 && $this->isVipfilesBot($bot['api'])) {
                $summary = 'Files were observed, but completion was not confirmed. Keep token_scan_items row untouched.';
            } else {
                $summary = $apiReason !== ''
                    ? ((($bot['mode'] ?? self::BOT_MODE_PAGINATE) === self::BOT_MODE_CLICK_BUTTON ? 'Button click not completed. reason=' : 'Run not completed. reason=') . $apiReason)
                    : (($bot['mode'] ?? self::BOT_MODE_PAGINATE) === self::BOT_MODE_CLICK_BUTTON
                        ? 'Button click not completed. Keep token_scan_items row untouched.'
                        : 'Run not completed. Keep token_scan_items row untouched.');
            }
        }

        return [
            'classification' => $classification,
            'db_action' => $dbAction,
            'bot_api' => $bot['api'],
            'bot_display' => $bot['display'],
            'base_uri' => $baseUri,
            'api_status' => $apiStatus,
            'api_reason' => $apiReason,
            'files_unique_count' => $filesUniqueCount,
            'files_total_bytes' => $filesTotalBytes,
            'latest_kind' => $latestKind,
            'latest_text_preview' => $latestTextPreview,
            'summary' => $summary,
            'timeline' => $timeline,
            'debug' => $debug,
            'button_clicked' => $buttonClicked,
            'clicked_button_text' => $clickedButtonText,
            'sent_message_id' => $sentMessageId,
            'dispatch_text' => $textToSend,
            'stop_processing' => false,
            'retry_after_queue_clear' => false,
            'retry_after_rate_limit' => isset($retryAfterSeconds) && $retryAfterSeconds !== null,
            'retry_after_seconds' => $retryAfterSeconds ?? 0,
            'retry_after_callback_error' => false,
            'yz_start_command' => $yzStartCommand,
        ];
    }

    /**
     * @param array{api:string,display:string} $primaryBot
     * @param array<string, mixed> $result
     */
    private function shouldFallbackToYzfile(array $primaryBot, array $result): bool
    {
        if (($primaryBot['api'] ?? '') !== self::BOT_QQFILE['api']) {
            return false;
        }

        if ((bool) ($result['button_clicked'] ?? false)) {
            return false;
        }

        $yzStartCommand = trim((string) ($result['yz_start_command'] ?? ''));
        if ($yzStartCommand === '') {
            return false;
        }

        return $this->responseRequestsYzFallback(
            (string) ($result['latest_text_preview'] ?? ''),
            (string) ($result['api_reason'] ?? '')
        );
    }

    /**
     * @return array{api:string,display:string}
     */
    private function resolveBotByToken(string $token): array
    {
        if (Str::startsWith($token, 'Messengercode_')) {
            return self::BOT_MESSENGER;
        }

        if (Str::startsWith(Str::lower($token), 'qqfile_bot:')) {
            return self::BOT_QQFILE;
        }

        if (Str::startsWith(Str::lower($token), 'yzfile_bot:')) {
            return self::BOT_YZFILE;
        }

        if (Str::startsWith(Str::lower($token), 'atfileslinksbot_')) {
            return self::BOT_ATFILESLINKS;
        }

        if (Str::startsWith(Str::lower($token), 'lddeebot_')) {
            return self::BOT_LDDEE;
        }

        return self::BOT_VIPFILES;
    }

    /**
     * @return array<string, mixed>
     */
    private function callTelegramApi(array $bot, string $text): array
    {
        $lastError = 'unknown';
        $sendPayload = $this->buildSendPayload($text, (string) $bot['api']);
        $followupPayload = (($bot['mode'] ?? self::BOT_MODE_PAGINATE) === self::BOT_MODE_CLICK_BUTTON)
            ? $this->buildButtonClickPayload((string) $bot['api'])
            : $this->buildPaginationPayload((string) $bot['api']);
        $followupPath = (($bot['mode'] ?? self::BOT_MODE_PAGINATE) === self::BOT_MODE_CLICK_BUTTON)
            ? '/bots/click-matching-button'
            : '/bots/run-all-pages-by-bot';

        foreach ($this->getApiBaseUris() as $baseUri) {
            $sendUrl = rtrim($baseUri, '/') . '/bots/send';
            $followupUrl = rtrim($baseUri, '/') . $followupPath;

            try {
                $sendResponse = Http::timeout(self::INITIAL_API_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->asJson()
                    ->post($sendUrl, $sendPayload);

                if (!$sendResponse->ok()) {
                    $lastError = 'send HTTP ' . $sendResponse->status() . ' ' . Str::limit((string) $sendResponse->body(), 300);
                    continue;
                }

                $sendJson = $sendResponse->json();
                if (!is_array($sendJson) || (string) ($sendJson['status'] ?? '') !== 'ok') {
                    $lastError = 'send invalid json response';
                    continue;
                }

                $followupPayloadWithContext = $followupPayload;
                $sentMessageId = (int) ($sendJson['sent_message_id'] ?? 0);
                if ($sentMessageId > 0) {
                    $followupPayloadWithContext['sent_message_id'] = $sentMessageId;
                }

                $paginationResponse = Http::timeout(self::FOLLOWUP_API_TIMEOUT_SECONDS)
                    ->acceptJson()
                    ->asJson()
                    ->post($followupUrl, $followupPayloadWithContext);

                if (!$paginationResponse->ok()) {
                    $lastError = 'followup HTTP ' . $paginationResponse->status() . ' ' . Str::limit((string) $paginationResponse->body(), 300);
                    continue;
                }

                $json = $paginationResponse->json();
                if (!is_array($json)) {
                    $lastError = 'followup invalid json response';
                    continue;
                }

                if (
                    (($bot['mode'] ?? self::BOT_MODE_PAGINATE) === self::BOT_MODE_CLICK_BUTTON)
                    && $this->isQqOrYzBotApi((string) $bot['api'])
                    && $this->shouldRetryQqYzWithFallbackButtons($json)
                ) {
                    $fallbackPayload = $this->buildButtonClickPayload(
                        (string) $bot['api'],
                        self::QQ_YZ_FALLBACK_BUTTON_KEYWORDS
                    );
                    if ($sentMessageId > 0) {
                        $fallbackPayload['sent_message_id'] = $sentMessageId;
                    }

                    $fallbackResponse = Http::timeout(self::FOLLOWUP_API_TIMEOUT_SECONDS)
                        ->acceptJson()
                        ->asJson()
                        ->post($followupUrl, $fallbackPayload);

                    if ($fallbackResponse->ok()) {
                        $fallbackJson = $fallbackResponse->json();
                        if (is_array($fallbackJson)) {
                            $json = $fallbackJson;
                        }
                    }
                }

                return [
                    'ok' => true,
                    'base_uri' => $baseUri,
                    'http_status' => $paginationResponse->status(),
                    'sent_message_id' => $sentMessageId,
                    'json' => $json,
                ];
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return [
            'ok' => false,
            'error' => $lastError,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function getApiBaseUris(): array
    {
        $inputs = $this->option('base-uri');
        if (is_array($inputs) && !empty($inputs)) {
            $normalized = [];
            $seen = [];

            foreach ($inputs as $input) {
                $baseUri = rtrim(trim((string) $input), '/');
                if ($baseUri === '' || isset($seen[$baseUri])) {
                    continue;
                }

                $seen[$baseUri] = true;
                $normalized[] = $baseUri;
            }

            if (!empty($normalized)) {
                return $normalized;
            }
        }

        $port = (int) $this->option('port');
        if ($port <= 0) {
            $port = self::DEFAULT_API_PORT;
        }

        return [rtrim(self::DEFAULT_API_HOST, '/') . ':' . $port];
    }

    private function buildSendPayload(string $token, string $botUsername): array
    {
        return [
            'bot_username' => $botUsername,
            'text' => $token,
            'clear_previous_replies' => true,
        ];
    }

    private function buildPaginationPayload(string $botUsername): array
    {
        $isMessenger = $botUsername === self::BOT_MESSENGER['api'];
        $isAtfileslinks = $botUsername === self::BOT_ATFILESLINKS['api'];

        $payload = [
            'bot_username' => $botUsername,
            'clear_previous_replies' => false,
            'delay_seconds' => 1,
            'debug' => true,
            'debug_max_logs' => 2000,
            'include_files_in_response' => true,
            'max_return_files' => 1000,
            'max_raw_payload_bytes' => 0,
            'cleanup_after_done' => true,
            'cleanup_scope' => 'run',
            'cleanup_limit' => 500,
            'wait_each_page_timeout_seconds' => 25,
            'callback_message_max_age_seconds' => 30,
            'callback_candidate_scan_limit' => 40,
        ];

        if ($isMessenger) {
            return array_merge($payload, [
                'max_steps' => 8,
                'stop_when_no_new_files_rounds' => 1,
                'stop_when_reached_total_items' => false,
                'observe_send_get_all_when_no_controls' => false,
                'observe_send_next_when_no_controls' => false,
            ]);
        }

        if ($isAtfileslinks) {
            return array_merge($payload, [
                'max_steps' => 120,
                'stop_when_no_new_files_rounds' => 4,
                'stop_when_reached_total_items' => true,
                'observe_send_get_all_when_no_controls' => false,
                'observe_send_next_when_no_controls' => false,
                'next_text_keywords' => [
                    '加载下一组',
                    '繼續加載',
                    '继续加载',
                    '下一组',
                    '下一頁',
                    '下一页',
                    '下頁',
                    '下页',
                    'Next',
                    'next',
                    '➡',
                    '▶',
                ],
            ]);
        }

        return array_merge($payload, [
            'max_steps' => 120,
            'stop_when_no_new_files_rounds' => 4,
            'stop_when_reached_total_items' => true,
            'observe_send_get_all_when_no_controls' => true,
            'observe_get_all_command' => '獲取全部',
            'observe_send_next_when_no_controls' => false,
        ]);
    }

    private function buildButtonClickPayload(string $botUsername, array $buttonKeywords = self::PUSH_ALL_BUTTON_KEYWORDS): array
    {
        return [
            'bot_username' => $botUsername,
            'clear_previous_replies' => false,
            'delay_seconds' => 1,
            'button_keywords' => $buttonKeywords,
            'debug' => true,
            'debug_max_logs' => 2000,
            'include_files_in_response' => true,
            'max_return_files' => 1000,
            'max_raw_payload_bytes' => 0,
            'wait_after_click_timeout_seconds' => 12,
            // Preserve the detail message so follow-up completion/progress can still be traced.
            'cleanup_after_done' => false,
            'cleanup_scope' => 'run',
            'cleanup_limit' => 500,
            'callback_message_max_age_seconds' => 60,
            'callback_candidate_scan_limit' => 60,
        ];
    }

    /**
     * @param array<string, mixed> $currentResult
     * @param array{token:string,item:TokenScanItem|null}|null $nextJob
     */
    private function sleepBeforeNextJobIfNeeded(array $currentResult, ?array $nextJob): void
    {
        $delayMicroseconds = $this->determineDelayBeforeNextJob($currentResult, $nextJob);
        if ($delayMicroseconds <= 0) {
            return;
        }

        $this->line(sprintf(
            'sleep=%.0fs before next QQ/yz token',
            $delayMicroseconds / 1000000
        ));

        usleep($delayMicroseconds);
    }

    /**
     * @param array<string, mixed> $currentResult
     * @param array{token:string,item:TokenScanItem|null}|null $nextJob
     */
    private function determineDelayBeforeNextJob(array $currentResult, ?array $nextJob): int
    {
        if ($nextJob === null) {
            return 0;
        }

        $currentBotApi = trim((string) ($currentResult['bot_api'] ?? ''));
        if (!$this->isQqOrYzBotApi($currentBotApi)) {
            return 0;
        }

        $nextToken = trim((string) ($nextJob['token'] ?? ''));
        if ($nextToken === '') {
            return 0;
        }

        $nextBot = $this->resolveBotByToken($nextToken);
        $nextBotApi = trim((string) ($nextBot['api'] ?? ''));

        if (!$this->isQqOrYzBotApi($nextBotApi)) {
            return 0;
        }

        return self::QQ_YZ_NEXT_TOKEN_DELAY_MICROSECONDS;
    }

    private function shouldRetryQqYzWithFallbackButtons(array $responseJson): bool
    {
        if (($responseJson['button_clicked'] ?? false) === true) {
            return false;
        }

        $reason = strtolower(trim((string) ($responseJson['reason'] ?? '')));
        if ($reason === '') {
            return false;
        }

        return Str::contains($reason, 'no matching button found');
    }

    /**
     * @param array<string, mixed> $result
     */
    private function shouldRetryQqYzAfterCallbackError(array $result): bool
    {
        $reason = strtolower(trim((string) ($result['api_reason'] ?? '')));
        if ($reason === '') {
            return false;
        }

        foreach (self::QQ_YZ_CALLBACK_RETRY_MARKERS as $marker) {
            if ($marker !== '' && Str::contains($reason, strtolower($marker))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function waitForQqYzCompletion(array $result): array
    {
        $baseUri = trim((string) ($result['base_uri'] ?? ''));
        $botApi = trim((string) ($result['bot_api'] ?? ''));
        $sentMessageId = (int) ($result['sent_message_id'] ?? 0);

        if ($baseUri === '' || !$this->isQqOrYzBotApi($botApi) || $sentMessageId <= 0) {
            $result['stop_processing'] = ($result['classification'] ?? '') !== 'success';
            return $result;
        }

        $deadline = microtime(true) + self::QQ_YZ_INITIAL_OBSERVE_TIMEOUT_SECONDS;
        $extendedDeadline = false;
        $continuePushClicks = 0;
        $lastState = $this->inspectQqYzReplyState([], $botApi, $sentMessageId);

        while (true) {
            $replies = $this->fetchRecentBotReplies($baseUri);
            if (!empty($replies)) {
                $lastState = $this->inspectQqYzReplyState($replies, $botApi, $sentMessageId);
            }

            if (
                ($lastState['continue_push_required'] ?? false) === true
                && $continuePushClicks < self::QQ_YZ_MAX_CONTINUE_PUSH_CLICKS
            ) {
                $continuePushClicks++;
                $continueResult = $this->clickQqYzButton(
                    $baseUri,
                    $botApi,
                    $sentMessageId,
                    self::QQ_YZ_CONTINUE_PUSH_BUTTON_KEYWORDS
                );

                if (($continueResult['button_clicked'] ?? false) === true) {
                    $deadline = microtime(true) + self::QQ_YZ_COMPLETION_TIMEOUT_SECONDS;
                    $extendedDeadline = true;
                    usleep(2000000);
                    continue;
                }
            }

            if (!$extendedDeadline && (
                ($lastState['accepted_observed'] ?? false)
                || ($lastState['progress_observed'] ?? false)
                || ($lastState['queue_busy_observed'] ?? false)
            )) {
                $deadline = microtime(true) + self::QQ_YZ_COMPLETION_TIMEOUT_SECONDS;
                $extendedDeadline = true;
            }

            if (($lastState['completion_found'] ?? false) === true) {
                $result['qq_yz_completion_confirmed'] = true;
                $result['qq_yz_queue_busy'] = (bool) ($lastState['queue_busy_observed'] ?? false);
                $result['qq_yz_accepted_observed'] = (bool) ($lastState['accepted_observed'] ?? false);
                $result['qq_yz_progress_observed'] = (bool) ($lastState['progress_observed'] ?? false);
                $result['qq_yz_continue_push_clicks'] = $continuePushClicks;
                $result['latest_text_preview'] = (string) ($lastState['completion_preview'] ?: $lastState['latest_text_preview'] ?: $result['latest_text_preview']);

                if (
                    ($lastState['queue_busy_observed'] ?? false) === true
                    && ($lastState['accepted_observed'] ?? false) === false
                    && ($lastState['progress_observed'] ?? false) === false
                ) {
                    $result['classification'] = 'failed';
                    $result['summary'] = 'Observed an existing QQ/yz queue finish, but the current token was not accepted yet. Retry current token.';
                    $result['stop_processing'] = true;
                    $result['retry_after_queue_clear'] = true;

                    return $result;
                }

                $result['classification'] = 'success';
                $result['summary'] = 'Completion confirmed: ' . (string) ($lastState['completion_preview'] ?: '文件获取完毕');
                $result['stop_processing'] = false;

                return $result;
            }

            if (($lastState['rate_limit_observed'] ?? false) === true) {
                $retryAfterSeconds = max(1, (int) ($lastState['retry_after_seconds'] ?? 0));
                $result['classification'] = 'failed';
                $result['summary'] = 'Decode rate limited. Wait ' . $retryAfterSeconds . ' seconds and retry the current token.';
                $result['latest_text_preview'] = (string) ($lastState['rate_limit_preview'] ?: $lastState['latest_text_preview'] ?: $result['latest_text_preview']);
                $result['stop_processing'] = true;
                $result['retry_after_rate_limit'] = true;
                $result['retry_after_seconds'] = $retryAfterSeconds;
                $result['retry_after_queue_clear'] = false;

                return $result;
            }

            if (($lastState['retry_later_observed'] ?? false) === true) {
                $result['classification'] = 'failed';
                $result['summary'] = 'Current resource already fetched. Retry this token after 24 hours and keep token_scan_items row untouched.';
                $result['latest_text_preview'] = (string) ($lastState['retry_later_preview'] ?: $lastState['latest_text_preview'] ?: $result['latest_text_preview']);
                $result['stop_processing'] = false;
                $result['retry_after_queue_clear'] = false;
                $result['retry_after_rate_limit'] = false;
                $result['retry_after_callback_error'] = false;

                return $result;
            }

            if (
                ($lastState['already_parsed_observed'] ?? false) === true
                && ($lastState['accepted_observed'] ?? false) === false
                && ($lastState['progress_observed'] ?? false) === false
                && ($lastState['completion_found'] ?? false) === false
            ) {
                $result['classification'] = 'failed';
                $result['summary'] = 'Resource was already parsed recently. Keep token_scan_items row untouched and continue to the next token.';
                $result['latest_text_preview'] = (string) ($lastState['already_parsed_preview'] ?: $lastState['latest_text_preview'] ?: $result['latest_text_preview']);
                $result['stop_processing'] = false;
                $result['retry_after_queue_clear'] = false;
                $result['retry_after_rate_limit'] = false;
                $result['retry_after_callback_error'] = false;

                return $result;
            }

            if (
                $this->shouldRetryQqYzAfterCallbackError($result)
                && ($lastState['accepted_observed'] ?? false) === false
                && ($lastState['progress_observed'] ?? false) === false
                && ($lastState['completion_found'] ?? false) === false
                && !empty($lastState['latest_buttons'])
            ) {
                $result['classification'] = 'failed';
                $result['summary'] = 'QQ/yz callback was stale or timed out. Refresh and retry the current token.';
                $result['latest_text_preview'] = (string) ($lastState['latest_text_preview'] ?: $result['latest_text_preview']);
                $result['stop_processing'] = true;
                $result['retry_after_callback_error'] = true;
                $result['retry_after_queue_clear'] = false;
                $result['retry_after_rate_limit'] = false;

                return $result;
            }

            if (microtime(true) >= $deadline) {
                $result['qq_yz_completion_confirmed'] = false;
                $result['qq_yz_queue_busy'] = (bool) ($lastState['queue_busy_observed'] ?? false);
                $result['qq_yz_accepted_observed'] = (bool) ($lastState['accepted_observed'] ?? false);
                $result['qq_yz_progress_observed'] = (bool) ($lastState['progress_observed'] ?? false);
                $result['qq_yz_continue_push_clicks'] = $continuePushClicks;
                $result['latest_text_preview'] = (string) ($lastState['latest_text_preview'] ?: $result['latest_text_preview']);
                $result['classification'] = ($result['classification'] ?? '') === 'not_found' ? 'not_found' : 'failed';
                $result['summary'] = $this->buildQqYzIncompleteSummary($lastState);
                $result['stop_processing'] = true;

                return $result;
            }

            usleep(self::QQ_YZ_COMPLETION_POLL_MICROSECONDS);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRecentBotReplies(string $baseUri): array
    {
        try {
            $response = Http::timeout(self::INITIAL_API_TIMEOUT_SECONDS)
                ->acceptJson()
                ->get(rtrim($baseUri, '/') . '/bots/replies', [
                    'limit' => self::BOT_REPLIES_FETCH_LIMIT,
                ]);

            if (!$response->ok()) {
                return [];
            }

            $json = $response->json();

            if (is_array($json)) {
                return $json;
            }
        } catch (Throwable) {
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $replies
     * @return array<string, mixed>
     */
    private function inspectQqYzReplyState(array $replies, string $botApi, int $minMessageId): array
    {
        $filtered = [];

        foreach ($replies as $reply) {
            if (!is_array($reply)) {
                continue;
            }

            if ((string) ($reply['bot_username'] ?? '') !== $botApi) {
                continue;
            }

            $messageId = (int) ($reply['message_id'] ?? 0);
            if ($messageId > 0 && $messageId < $minMessageId) {
                continue;
            }

            $filtered[] = $reply;
        }

        usort($filtered, static function (array $left, array $right): int {
            return ((int) ($left['message_id'] ?? 0)) <=> ((int) ($right['message_id'] ?? 0));
        });

        $latestTextPreview = '';
        $completionPreview = '';
        $progressPreview = '';
        $acceptedPreview = '';
        $queueBusyPreview = '';
        $retryLaterPreview = '';
        $alreadyParsedPreview = '';
        $rateLimitPreview = '';
        $continuePushPreview = '';
        $continuePushRequired = false;
        $retryAfterSeconds = 0;
        $rateLimitObserved = false;
        $latestButtons = [];

        foreach ($filtered as $reply) {
            $text = $this->normalizePreviewText((string) ($reply['text'] ?? ''));
            if ($text !== '') {
                $latestTextPreview = Str::limit($text, 240);
            }

            $messageButtons = $this->extractReplyButtonTexts($reply);
            if (!empty($messageButtons)) {
                $latestButtons = $messageButtons;
            }

            if ($text !== '' && $this->isQqYzCompletionText($text)) {
                $completionPreview = Str::limit($text, 240);
            }

            if ($text !== '' && $this->isQqYzProgressText($text)) {
                $progressPreview = Str::limit($text, 240);
            }

            if ($text !== '' && $this->isQqYzAcceptedText($text)) {
                $acceptedPreview = Str::limit($text, 240);
            }

            if ($text !== '' && $this->isQqYzQueueBusyText($text)) {
                $queueBusyPreview = Str::limit($text, 240);
            }

            if ($text !== '' && $this->isQqYzRetryLaterText($text)) {
                $retryLaterPreview = Str::limit($text, 240);
            }

            if ($text !== '' && $this->isQqYzAlreadyParsedText($text)) {
                $alreadyParsedPreview = Str::limit($text, 240);
            }

            if ($text !== '') {
                $continuePushRequired = $this->isQqYzContinuePushText($text)
                    && $this->buttonTextsContainKeyword($messageButtons, self::QQ_YZ_CONTINUE_PUSH_BUTTON_KEYWORDS);
                if ($continuePushRequired) {
                    $continuePushPreview = Str::limit($text, 240);
                }
            }

            if ($text !== '') {
                $seconds = $this->extractQqYzRetryAfterSeconds($text);
                if ($seconds !== null) {
                    $rateLimitObserved = true;
                    $retryAfterSeconds = $seconds;
                    $rateLimitPreview = Str::limit($text, 240);
                }
            }
        }

        return [
            'latest_text_preview' => $latestTextPreview,
            'completion_found' => $completionPreview !== '',
            'completion_preview' => $completionPreview,
            'progress_observed' => $progressPreview !== '',
            'progress_preview' => $progressPreview,
            'accepted_observed' => $acceptedPreview !== '',
            'accepted_preview' => $acceptedPreview,
            'queue_busy_observed' => $queueBusyPreview !== '',
            'queue_busy_preview' => $queueBusyPreview,
            'retry_later_observed' => $retryLaterPreview !== '',
            'retry_later_preview' => $retryLaterPreview,
            'already_parsed_observed' => $alreadyParsedPreview !== '',
            'already_parsed_preview' => $alreadyParsedPreview,
            'continue_push_required' => $continuePushRequired,
            'continue_push_preview' => $continuePushPreview,
            'rate_limit_observed' => $rateLimitObserved,
            'retry_after_seconds' => $retryAfterSeconds,
            'rate_limit_preview' => $rateLimitPreview,
            'push_all_available' => $this->buttonTextsContainKeyword($latestButtons, self::PUSH_ALL_BUTTON_KEYWORDS),
            'latest_buttons' => $latestButtons,
        ];
    }

    /**
     * @param array<int, string> $buttonKeywords
     * @return array<string, mixed>
     */
    private function clickQqYzButton(string $baseUri, string $botApi, int $sentMessageId, array $buttonKeywords): array
    {
        try {
            $payload = $this->buildButtonClickPayload($botApi, $buttonKeywords);
            if ($sentMessageId > 0) {
                $payload['sent_message_id'] = $sentMessageId;
            }

            $response = Http::timeout(self::INITIAL_API_TIMEOUT_SECONDS)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($baseUri, '/') . '/bots/click-matching-button', $payload);

            if (!$response->ok()) {
                return [
                    'ok' => false,
                    'reason' => 'followup HTTP ' . $response->status() . ' ' . Str::limit((string) $response->body(), 300),
                ];
            }

            $json = $response->json();
            if (!is_array($json)) {
                return [
                    'ok' => false,
                    'reason' => 'followup invalid json response',
                ];
            }

            return $json;
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $reply
     * @return array<int, string>
     */
    private function extractReplyButtonTexts(array $reply): array
    {
        $texts = [];

        foreach ((array) ($reply['buttons'] ?? []) as $button) {
            if (!is_array($button)) {
                continue;
            }

            $text = $this->normalizePreviewText((string) ($button['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $texts[] = $text;
        }

        return $texts;
    }

    private function buttonTextsContainKeyword(array $buttonTexts, array $keywords): bool
    {
        foreach ($buttonTexts as $buttonText) {
            foreach ($keywords as $keyword) {
                if ($keyword !== '' && Str::contains($buttonText, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizePreviewText(string $text): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($text));

        return $normalized === null ? trim($text) : trim($normalized);
    }

    private function isQqYzCompletionText(string $text): bool
    {
        $normalized = $this->normalizePreviewText($text);
        if ($normalized === '') {
            return false;
        }

        if (!Str::contains($normalized, self::QQ_YZ_COMPLETION_MARKERS)) {
            return false;
        }

        return Str::contains($normalized, self::QQ_YZ_TOTAL_MARKERS);
    }

    private function isQqYzContinuePushText(string $text): bool
    {
        $normalized = $this->normalizePreviewText($text);
        if ($normalized === '') {
            return false;
        }

        foreach (self::QQ_YZ_CONTINUE_PUSH_STOP_MARKERS as $marker) {
            if ($marker !== '' && !Str::contains($normalized, $marker)) {
                return false;
            }
        }

        return true;
    }

    private function isQqYzProgressText(string $text): bool
    {
        return preg_match('/第\s*\d+\s*\/\s*\d+\s*[页頁].*(文件总数|文件總數)/u', $text) === 1;
    }

    private function isQqYzAcceptedText(string $text): bool
    {
        foreach (self::QQ_YZ_ACCEPTED_MARKERS as $marker) {
            if ($marker !== '' && Str::contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isQqYzQueueBusyText(string $text): bool
    {
        foreach (self::QQ_YZ_QUEUE_BUSY_MARKERS as $marker) {
            if ($marker !== '' && Str::contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isQqYzRetryLaterText(string $text): bool
    {
        foreach (self::QQ_YZ_RETRY_LATER_MARKERS as $marker) {
            if ($marker !== '' && Str::contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isQqYzAlreadyParsedText(string $text): bool
    {
        foreach (self::QQ_YZ_ALREADY_PARSED_MARKERS as $marker) {
            if ($marker !== '' && Str::contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function extractQqYzRetryAfterSeconds(string $text): ?int
    {
        if (preg_match('/解码频繁，请\s*(\d+)\s*秒后重试/u', $text, $matches) !== 1) {
            return null;
        }

        return max((int) ($matches[1] ?? 0), 0);
    }

    private function extractMessengerRetryAfterSeconds(string $text): ?int
    {
        if (preg_match('/取件太快了，\s*(\d+)\s*秒后再试/u', $text, $matches) !== 1) {
            return null;
        }

        return max((int) ($matches[1] ?? 0), 0);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function buildQqYzIncompleteSummary(array $state): string
    {
        $summary = 'QQ/yz completion marker not observed. Keep token_scan_items row untouched.';
        $details = [];

        if (($state['queue_busy_observed'] ?? false) === true) {
            $details[] = 'queue still busy';
        }
        if (($state['accepted_observed'] ?? false) === true) {
            $details[] = 'token accepted';
        }
        if (($state['progress_observed'] ?? false) === true && !empty($state['progress_preview'])) {
            $details[] = 'last_progress=' . $state['progress_preview'];
        }
        if (!empty($state['latest_text_preview'])) {
            $details[] = 'latest=' . $state['latest_text_preview'];
        }

        if (!empty($details)) {
            $summary .= ' ' . implode('; ', $details);
        }

        return $summary;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < (count($units) - 1)) {
            $size /= 1024;
            $unitIndex++;
        }

        return number_format($size, $unitIndex === 0 ? 0 : 2) . ' ' . $units[$unitIndex];
    }

    private function isVipfilesBot(string $botApi): bool
    {
        return in_array($botApi, [
            self::BOT_VIPFILES['api'],
            self::BOT_ATFILESLINKS['api'],
            self::BOT_LDDEE['api'],
        ], true);
    }

    private function isQqOrYzBotApi(string $botApi): bool
    {
        return in_array($botApi, [
            self::BOT_QQFILE['api'],
            self::BOT_YZFILE['api'],
        ], true);
    }

    private function responseContainsNotFound(array $responseJson, string $latestTextPreview): bool
    {
        $outcome = is_array($responseJson['outcome'] ?? null) ? $responseJson['outcome'] : [];
        if (($outcome['not_found_message_detected'] ?? false) === true) {
            return true;
        }

        $texts = [];
        if ($latestTextPreview !== '') {
            $texts[] = $latestTextPreview;
        }

        $reason = trim((string) ($responseJson['reason'] ?? ''));
        if ($reason !== '') {
            $texts[] = $reason;
        }

        $latestMessage = is_array($responseJson['latest_message'] ?? null) ? $responseJson['latest_message'] : [];
        $latestPreview = trim((string) ($latestMessage['text_preview'] ?? ''));
        if ($latestPreview !== '') {
            $texts[] = $latestPreview;
        }

        foreach ($texts as $text) {
            foreach (self::NOT_FOUND_MARKERS as $marker) {
                if ($marker !== '' && Str::contains($text, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function responseRequestsYzFallback(string $latestTextPreview, string $apiReason): bool
    {
        $texts = [
            $latestTextPreview,
            $apiReason,
        ];

        foreach ($texts as $text) {
            $normalized = trim($text);
            if ($normalized === '') {
                continue;
            }

            if (Str::contains($normalized, self::QQ_YZ_SYNC_MARKER) && Str::contains(Str::lower($normalized), 'yzfile_bot')) {
                return true;
            }
        }

        return false;
    }

    private function extractYzStartCommand(array $responseJson, string $latestTextPreview): ?string
    {
        $texts = [
            $latestTextPreview,
            trim((string) ($responseJson['reason'] ?? '')),
        ];

        foreach ($texts as $text) {
            if ($text === '') {
                continue;
            }

            if (preg_match('~https?://t\.me/yzfile_bot\?start=([A-Za-z0-9_\-]+)~iu', $text, $matches) === 1) {
                return '/start ' . $matches[1];
            }

            if (preg_match('~yzfile_bot\?start=([A-Za-z0-9_\-]+)~iu', $text, $matches) === 1) {
                return '/start ' . $matches[1];
            }
        }

        return null;
    }

    private function isMessengerRunCompleted(
        array $responseJson,
        string $apiJsonStatus,
        string $apiReason,
        string $latestKind,
        string $latestTextPreview,
        bool $latestHasButtons,
        int $filesUniqueCount
    ): bool
    {
        if (($responseJson['completed'] ?? false) === true) {
            return true;
        }

        $outcome = is_array($responseJson['outcome'] ?? null) ? $responseJson['outcome'] : [];
        if (($outcome['run_completed'] ?? false) === true) {
            return true;
        }

        if ($latestKind === 'completion') {
            return true;
        }

        if ($apiJsonStatus !== 'ok') {
            return false;
        }

        if ($latestHasButtons) {
            return false;
        }

        if ($latestTextPreview !== '') {
            return true;
        }

        return $filesUniqueCount > 0 && $this->messengerReasonIndicatesCompletion($apiReason);
    }

    private function messengerReasonIndicatesCompletion(string $apiReason): bool
    {
        $normalized = strtolower(trim($apiReason));
        if ($normalized === '') {
            return false;
        }

        foreach ([
            'completion message detected',
            'no page_info and no buttons; observed files collected',
            'not pagination-like; observed files collected',
            'no buttons and no next; observed files collected',
        ] as $marker) {
            if (Str::contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function isVipfilesRunCompleted(
        array $responseJson,
        array $latestMessage,
        array $pageState,
        int $filesUniqueCount
    ): bool {
        $reason = strtolower(trim((string) ($responseJson['reason'] ?? '')));
        $latestKind = strtolower(trim((string) ($latestMessage['kind'] ?? '')));
        $latestHasButtons = (bool) ($latestMessage['has_buttons'] ?? false);
        $pageInfo = is_array($latestMessage['page_info'] ?? null) ? $latestMessage['page_info'] : [];

        if ($latestKind === 'completion') {
            return true;
        }

        if (Str::contains($reason, 'completion message detected')) {
            return true;
        }

        if ($filesUniqueCount <= 0) {
            return false;
        }

        if ($this->pageInfoReachedLastPage($pageInfo)) {
            return true;
        }

        foreach ([
            'last page confirmed',
            'all pages visited',
            'reached total items + last page confirmed',
            'reached total items + all pages visited',
            'reached total items after final page click',
            'completion message detected',
        ] as $successMarker) {
            if ($successMarker !== '' && Str::contains($reason, $successMarker)) {
                return true;
            }
        }

        $didAnyPaginationClick = (bool) ($pageState['did_any_pagination_click'] ?? false);
        $didBootstrapClick = (bool) ($pageState['did_bootstrap_click'] ?? false);
        $lastClickedPage = (int) ($pageState['last_clicked_page'] ?? 0);

        if (($didAnyPaginationClick || $didBootstrapClick) && $latestHasButtons === false && empty($pageInfo)) {
            return true;
        }

        if ($lastClickedPage > 0 && Str::contains($reason, 'final page click')) {
            return true;
        }

        return $latestHasButtons === false && empty($pageInfo);
    }

    private function pageInfoReachedLastPage(array $pageInfo): bool
    {
        if (empty($pageInfo)) {
            return false;
        }

        $currentPage = $pageInfo['current_page'] ?? null;
        $totalPages = $pageInfo['total_pages'] ?? null;

        if ($currentPage === null || $totalPages === null) {
            return false;
        }

        return (int) $currentPage > 0 && (int) $currentPage >= (int) $totalPages;
    }

    private function applyDoneAction(TokenScanItem $item, string $doneAction): string
    {
        if ($doneAction === 'delete') {
            TokenScanItem::query()->whereKey($item->id)->delete();
            return 'delete';
        }

        TokenScanItem::query()
            ->whereKey($item->id)
            ->update(['updated_at' => now()]);

        return 'touch';
    }

    /**
     * @param array<int, mixed> $inputs
     * @return array<int, string>
     */
    private function normalizeTokenInputs(array $inputs): array
    {
        $seen = [];
        $tokens = [];

        foreach ($inputs as $input) {
            $token = trim((string) $input);
            if ($token === '' || isset($seen[$token])) {
                continue;
            }

            $seen[$token] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }

    private function normalizeDoneAction(string $doneAction): ?string
    {
        $normalized = strtolower(trim($doneAction));

        if ($normalized === 'touch' || $normalized === 'update') {
            return 'touch';
        }

        if ($normalized === 'delete' || $normalized === 'del') {
            return 'delete';
        }

        return null;
    }

    /**
     * @param array<int, array<string, mixed>> $timeline
     */
    private function printTimelineTail(array $timeline): void
    {
        if (empty($timeline)) {
            return;
        }

        $tail = array_slice($timeline, -5);
        $parts = [];

        foreach ($tail as $row) {
            if (!is_array($row)) {
                continue;
            }

            $step = isset($row['step']) ? (string) $row['step'] : '?';
            $status = trim((string) ($row['status'] ?? ''));
            $reason = trim((string) ($row['reason'] ?? $row['clicked'] ?? ''));

            $part = 'step=' . $step;
            if ($status !== '') {
                $part .= ':' . $status;
            }
            if ($reason !== '') {
                $part .= '(' . Str::limit($reason, 50) . ')';
            }

            $parts[] = $part;
        }

        if (!empty($parts)) {
            $this->line('timeline=' . implode(' | ', $parts));
        }
    }

    /**
     * @param array<int, array<string, mixed>> $debug
     */
    private function printDebugTail(array $debug): void
    {
        if (empty($debug)) {
            return;
        }

        $tail = array_slice($debug, -6);
        $parts = [];

        foreach ($tail as $row) {
            if (!is_array($row)) {
                continue;
            }

            $stage = trim((string) ($row['stage'] ?? ''));
            $result = trim((string) ($row['result'] ?? ''));
            if ($stage === '' && $result === '') {
                continue;
            }

            $desc = $stage !== '' ? $stage : 'log';
            if ($result !== '') {
                $desc .= ':' . $result;
            }

            $parts[] = $desc;
        }

        if (!empty($parts)) {
            $this->line('debug=' . implode(' | ', $parts));
        }
    }
}
