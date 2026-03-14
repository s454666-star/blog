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
        {--include-processed : Include rows with updated_at already set}';

    protected $description = 'Dispatch token_scan_items tokens to Telegram bots and delete or touch rows after success.';

    private const BOT_MESSENGER = [
        'api' => 'MessengerCode_bot',
        'display' => '@MessengerCode_bot',
    ];

    private const BOT_VIPFILES = [
        'api' => 'vipfiles2bot',
        'display' => '@vipfiles2bot',
    ];

    private const DEFAULT_API_HOST = 'http://127.0.0.1';
    private const DEFAULT_API_PORT = 8000;
    private const NEXT_TOKEN_DELAY_MICROSECONDS = 5000000;

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
                'bot=%s api=%s status=%s files=%d latest_kind=%s db_action=%s',
                $result['bot_display'],
                $result['base_uri'] ?: '-',
                $result['api_status'] ?: 'fail',
                (int) $result['files_unique_count'],
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
                $this->line('sleep=2.5s before next token');
                usleep(self::NEXT_TOKEN_DELAY_MICROSECONDS);
            }
        }

        $this->line(str_repeat('=', 100));
        $this->line('total=' . $stats['total']);
        $this->line('success=' . $stats['success']);
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
        $bot = $this->resolveBotByToken($token);
        $payload = $this->buildApiPayload($token, $bot['api']);

        $apiCall = $this->callTelegramApi($payload);
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

        $filesMeta = is_array($responseJson['files'] ?? null) ? $responseJson['files'] : [];
        $filesUniqueCount = (int) ($responseJson['files_unique_count'] ?? $filesMeta['files_unique_count'] ?? $filesMeta['files_count'] ?? 0);
        $pageState = is_array($responseJson['page_state'] ?? null) ? $responseJson['page_state'] : [];

        $notFound = $this->responseContainsNotFound($responseJson, $latestTextPreview);
        $apiJsonStatus = (string) ($responseJson['status'] ?? '');
        $apiReason = trim((string) ($responseJson['reason'] ?? ''));
        $fullyCompleted = $bot['api'] === self::BOT_VIPFILES['api']
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
        } elseif ($fullyCompleted) {
            $classification = 'success';
            $summary = 'Completed. files_unique_count=' . $filesUniqueCount;
        } elseif ($notFound) {
            $classification = 'not_found';
            $summary = 'Bot returned not found. Keep token_scan_items row untouched.';
        } elseif ($bot['api'] === self::BOT_MESSENGER['api'] && $latestKind === 'completion') {
            $classification = 'success';
            $summary = 'Messenger bot returned completion message.';
        } else {
            if ($filesUniqueCount > 0 && $bot['api'] === self::BOT_VIPFILES['api']) {
                $summary = 'Files were observed, but completion was not confirmed. Keep token_scan_items row untouched.';
            } else {
                $summary = $apiReason !== ''
                    ? ('Run not completed. reason=' . $apiReason)
                    : 'Run not completed. Keep token_scan_items row untouched.';
            }
        }

        if ($classification === 'success' && $item instanceof TokenScanItem) {
            $dbAction = $this->applyDoneAction($item, $doneAction);
        }

        if ($classification === 'success' && !($item instanceof TokenScanItem)) {
            $dbAction = 'manual';
        }

        return [
            'classification' => $classification,
            'db_action' => $dbAction,
            'bot_display' => $bot['display'],
            'base_uri' => $baseUri,
            'api_status' => $apiStatus,
            'files_unique_count' => $filesUniqueCount,
            'latest_kind' => $latestKind,
            'latest_text_preview' => $latestTextPreview,
            'summary' => $summary,
            'timeline' => $timeline,
            'debug' => $debug,
        ];
    }

    /**
     * @return array{api:string,display:string}
     */
    private function resolveBotByToken(string $token): array
    {
        if (Str::startsWith($token, 'Messengercode_')) {
            return self::BOT_MESSENGER;
        }

        return self::BOT_VIPFILES;
    }

    /**
     * @return array<string, mixed>
     */
    private function callTelegramApi(array $payload): array
    {
        $lastError = 'unknown';

        foreach ($this->getApiBaseUris() as $baseUri) {
            $url = rtrim($baseUri, '/') . '/bots/send-and-run-all-pages';

            try {
                $response = Http::timeout(600)
                    ->acceptJson()
                    ->asJson()
                    ->post($url, $payload);

                if (!$response->ok()) {
                    $lastError = 'HTTP ' . $response->status() . ' ' . Str::limit((string) $response->body(), 300);
                    continue;
                }

                $json = $response->json();
                if (!is_array($json)) {
                    $lastError = 'invalid json response';
                    continue;
                }

                return [
                    'ok' => true,
                    'base_uri' => $baseUri,
                    'http_status' => $response->status(),
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

    private function buildApiPayload(string $token, string $botUsername): array
    {
        $isMessenger = $botUsername === self::BOT_MESSENGER['api'];

        $payload = [
            'bot_username' => $botUsername,
            'text' => $token,
            'clear_previous_replies' => true,
            'delay_seconds' => 1,
            'debug' => true,
            'debug_max_logs' => 2000,
            'include_files_in_response' => true,
            'max_return_files' => 1000,
            'max_raw_payload_bytes' => 0,
            'cleanup_after_done' => false,
            'cleanup_scope' => 'run',
            'cleanup_limit' => 500,
            'wait_first_callback_timeout_seconds' => 25,
            'wait_each_page_timeout_seconds' => 25,
            'callback_message_max_age_seconds' => 30,
            'callback_candidate_scan_limit' => 40,
            'max_invalid_callback_rounds' => 2,
        ];

        if ($isMessenger) {
            return $payload + [
                'max_steps' => 8,
                'bootstrap_click_get_all' => false,
                'allow_ok_when_no_buttons' => true,
                'text_next_fallback_enabled' => false,
                'stop_when_no_new_files_rounds' => 1,
                'stop_when_reached_total_items' => false,
                'normalize_to_first_page_when_no_buttons' => false,
                'initial_wait_for_controls_seconds' => 3,
                'observe_when_no_controls_seconds' => 6,
                'observe_send_get_all_when_no_controls' => false,
                'observe_send_next_when_no_controls' => false,
            ];
        }

        return $payload + [
            'max_steps' => 120,
            'bootstrap_click_get_all' => true,
            'allow_ok_when_no_buttons' => true,
            'text_next_fallback_enabled' => true,
            'text_next_command' => '下一頁',
            'stop_when_no_new_files_rounds' => 4,
            'stop_when_reached_total_items' => true,
            'normalize_to_first_page_when_no_buttons' => true,
            'normalize_prev_command' => '上一頁',
            'normalize_max_prev_steps' => 6,
            'initial_wait_for_controls_seconds' => 6,
            'observe_when_no_controls_seconds' => 10,
            'observe_send_get_all_when_no_controls' => true,
            'observe_get_all_command' => '獲取全部',
            'observe_send_next_when_no_controls' => false,
        ];
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
        if ($filesUniqueCount <= 0) {
            return false;
        }

        $reason = strtolower(trim((string) ($responseJson['reason'] ?? '')));
        $latestKind = strtolower(trim((string) ($latestMessage['kind'] ?? '')));
        $latestHasButtons = (bool) ($latestMessage['has_buttons'] ?? false);
        $pageInfo = is_array($latestMessage['page_info'] ?? null) ? $latestMessage['page_info'] : [];

        if ($latestKind === 'completion') {
            return true;
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
