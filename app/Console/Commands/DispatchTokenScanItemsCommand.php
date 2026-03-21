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
    private const QQ_YZ_NEXT_TOKEN_DELAY_MICROSECONDS = 8000000;
    private const INITIAL_API_TIMEOUT_SECONDS = 60;
    private const QQ_YZ_SYNC_MARKER = '当前解码器未完成同步';
    private const PUSH_ALL_BUTTON_KEYWORDS = ['推送全部'];

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
        $result = $this->runBotAttempt($token, $primaryBot);

        if ($this->shouldFallbackToYzfile($primaryBot, $result)) {
            $fallback = $this->runBotAttempt(
                $token,
                self::BOT_YZFILE,
                (string) ($result['yz_start_command'] ?? '')
            );
            $fallbackSummary = 'Fallback after @QQfile_bot requested @yzfile_bot';
            $fallback['summary'] = trim($fallbackSummary . '. ' . ($fallback['summary'] ?? ''));
            $result = $fallback;
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
        } elseif (($bot['mode'] ?? self::BOT_MODE_PAGINATE) === self::BOT_MODE_CLICK_BUTTON && $buttonClicked) {
            $classification = 'success';
            $summary = 'Clicked button ' . ($clickedButtonText !== '' ? $clickedButtonText : '推送全部') . '.';
        } elseif (($bot['api'] ?? '') === self::BOT_QQFILE['api'] && $this->responseRequestsYzFallback($latestTextPreview, $apiReason) && $yzStartCommand !== null) {
            $summary = 'QQ bot requested yzfile_bot redirect.';
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

                $paginationResponse = Http::timeout(self::INITIAL_API_TIMEOUT_SECONDS)
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

                return [
                    'ok' => true,
                    'base_uri' => $baseUri,
                    'http_status' => $paginationResponse->status(),
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

        return array_merge($payload, [
            'max_steps' => 120,
            'stop_when_no_new_files_rounds' => 4,
            'stop_when_reached_total_items' => true,
            'observe_send_get_all_when_no_controls' => true,
            'observe_get_all_command' => '獲取全部',
            'observe_send_next_when_no_controls' => false,
        ]);
    }

    private function buildButtonClickPayload(string $botUsername): array
    {
        return [
            'bot_username' => $botUsername,
            'clear_previous_replies' => false,
            'delay_seconds' => 1,
            'button_keywords' => self::PUSH_ALL_BUTTON_KEYWORDS,
            'debug' => true,
            'debug_max_logs' => 2000,
            'include_files_in_response' => true,
            'max_return_files' => 1000,
            'max_raw_payload_bytes' => 0,
            'wait_after_click_timeout_seconds' => 12,
            'cleanup_after_done' => true,
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
        return $botApi === self::BOT_VIPFILES['api'];
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
