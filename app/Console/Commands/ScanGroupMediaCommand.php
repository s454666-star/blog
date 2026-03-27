<?php

namespace App\Console\Commands;

use App\Models\GroupMediaScanState;
use App\Models\TokenScanHeader;
use App\Models\TokenScanItem;
use App\Services\TelegramCodeTokenService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ScanGroupMediaCommand extends Command
{
    protected $signature = 'tg:scan-group-media {--base-uri= : 覆蓋 API base_uri（預設使用程式內設定）} {--until-empty : 持續掃到沒有新媒體為止} {--next-limit=1000 : 每次從 groups API 取幾筆訊息} {--exit-code-when-empty=0 : 本次完全沒下載到新媒體時回傳的 exit code}';
    protected $description = '逐筆掃描 Telegram 群組媒體訊息，每次只下載一筆到本地，並記錄游標供下次續跑';

    private const GROUP_FETCH_MAX_RETRIES = 3;
    private const BOT_DISPATCH_MAX_RETRIES = 2;
    private const MEDIA_DOWNLOAD_MAX_RETRIES = 1;
    private const TARGET_TIMEZONE = 'Asia/Taipei';
    private const DIALOGUES_CHAT_ID = 7702694790;
    private const FAST_REQUEST_TIMEOUT_SECONDS = 30;
    private const BOT_REQUEST_TIMEOUT_SECONDS = 600;
    private const MEDIA_DOWNLOAD_TIMEOUT_SECONDS = 600;
    private const BOT_VIPFILES = [
        'api' => 'vipfiles2bot',
        'display' => '@vipfiles2bot',
    ];
    private const BOT_MTFXQ = [
        'api' => 'mtfxqbot',
        'display' => '@mtfxqbot',
    ];
    private const NOT_FOUND_MARKERS = [
        '💔抱歉，未找到可解析内容。',
        '抱歉，未找到可解析内容。',
        '抱歉，未找到可解析内容',
        '未找到可解析内容。已加入缓存列表，稍后进行请求。',
        '未找到可解析内容',
        '已加入缓存列表，稍后进行请求。',
    ];

    private array $scanTargetsByBaseUri = [
        'http://127.0.0.1:8000/' => [
            2755698006,
            3406828124,
            2457763530,
        ],
    ];

    private TelegramCodeTokenService $tokenService;
    private ?int $lastFloodWaitSeconds = null;
    private string $lastFailureSummary = '';

    public function __construct(TelegramCodeTokenService $tokenService)
    {
        parent::__construct();
        $this->tokenService = $tokenService;
    }

    public function handle(): int
    {
        $this->lastFloodWaitSeconds = null;
        $this->lastFailureSummary = '';

        $overrideBaseUri = trim((string) $this->option('base-uri'));
        $untilEmpty = (bool) $this->option('until-empty');
        $nextLimit = max(1, (int) $this->option('next-limit'));
        $emptyExitCode = max(0, (int) $this->option('exit-code-when-empty'));
        $targetsByBaseUri = $this->resolveTargetsByBaseUri($overrideBaseUri);

        $round = 0;
        $processedAtLeastOnce = false;
        $preparedTargets = [];

        foreach ($targetsByBaseUri as $baseUri => $peerIds) {
            $baseUri = trim((string) $baseUri);
            if ($baseUri === '' || empty($peerIds)) {
                continue;
            }

            $preparedTargets[] = [
                'base_uri' => $baseUri,
                'peer_ids' => array_values((array) $peerIds),
                'http' => $this->makeHttpClient($baseUri),
            ];
        }

        while (true) {
            $round++;
            $downloadedThisRound = false;

            foreach ($preparedTargets as $target) {
                $baseUri = (string) $target['base_uri'];
                $http = $target['http'];
                $peerIds = (array) $target['peer_ids'];

                foreach ($peerIds as $peerId) {
                    $peerId = (int) $peerId;
                    if ($peerId <= 0) {
                        continue;
                    }

                    $downloaded = $this->scanOnePeer($http, $baseUri, $peerId, $nextLimit);
                    if ($downloaded) {
                        $downloadedThisRound = true;
                        $processedAtLeastOnce = true;
                        if (!$untilEmpty) {
                            return self::SUCCESS;
                        }

                        $this->line("第 {$round} 輪已下載 1 筆媒體，繼續下一輪。");
                        break 2;
                    }
                }
            }

            if (!$untilEmpty || !$downloadedThisRound) {
                break;
            }
        }

        if ($this->lastFailureSummary !== '') {
            return self::FAILURE;
        }

        $this->line('本次沒有可下載的新媒體檔案。');

        if (!$processedAtLeastOnce && $emptyExitCode > 0) {
            return $emptyExitCode;
        }

        return self::SUCCESS;
    }

    private function resolveTargetsByBaseUri(string $overrideBaseUri): array
    {
        if ($overrideBaseUri === '') {
            return $this->scanTargetsByBaseUri;
        }

        $peerIds = [];
        foreach ($this->scanTargetsByBaseUri as $configuredPeerIds) {
            foreach ((array) $configuredPeerIds as $peerId) {
                $peerId = (int) $peerId;
                if ($peerId > 0 && !in_array($peerId, $peerIds, true)) {
                    $peerIds[] = $peerId;
                }
            }
        }

        return [
            rtrim($overrideBaseUri, '/') . '/' => $peerIds,
        ];
    }

    private function makeHttpClient(string $baseUri): Client
    {
        return new Client([
            'base_uri' => $baseUri,
            'connect_timeout' => 10,
        ]);
    }

    private function scanOnePeer(Client $http, string $baseUri, int $peerId, int $nextLimit): bool
    {
        $state = GroupMediaScanState::query()->firstOrCreate(
            [
                'base_uri' => $baseUri,
                'peer_id' => $peerId,
            ],
            [
                'chat_title' => null,
                'max_message_id' => 1,
                'last_group_message_id' => 0,
                'last_downloaded_message_id' => 0,
                'last_batch_count' => 0,
                'last_message_datetime' => null,
                'last_saved_path' => null,
                'last_saved_name' => null,
            ]
        );

        $cursor = max(1, (int) ($state->max_message_id ?? 1));
        $groupInfo = $this->resolveGroupInfo($http, $state, $peerId, $cursor);
        $chatTitle = trim((string) ($groupInfo['title'] ?? $state->chat_title ?? ''));
        if ($chatTitle !== '' && $state->chat_title !== $chatTitle) {
            $state->chat_title = $chatTitle;
        }

        $latestGroupMessageId = (int) ($groupInfo['last_message_id'] ?? $state->last_group_message_id ?? 0);
        if ($latestGroupMessageId > 0) {
            $state->last_group_message_id = $latestGroupMessageId;
        }
        $state->save();

        $this->line(str_repeat('-', 80));
        $this->line("base_uri={$baseUri} peer_id={$peerId} cursor={$cursor} 開始掃描媒體");

        while (true) {
            if ($latestGroupMessageId > 0 && $cursor >= $latestGroupMessageId) {
                $this->line("base_uri={$baseUri} peer_id={$peerId} 已掃到最新 last_message_id={$latestGroupMessageId}");
                return false;
            }

            $payload = $this->getCachedOrFetchGroupPage($http, $baseUri, $peerId, $cursor, $nextLimit);
            if (!$payload || (string) ($payload['status'] ?? '') !== 'ok') {
                $this->line("base_uri={$baseUri} peer_id={$peerId} cursor={$cursor} 取回失敗");
                return false;
            }

            $items = $payload['items'] ?? [];
            if (!is_array($items) || count($items) === 0) {
                $this->line("base_uri={$baseUri} peer_id={$peerId} cursor={$cursor} 沒有新訊息");
                return false;
            }

            $batchMaxId = $this->getMaxMessageId($items);
            if ($batchMaxId <= $cursor) {
                $this->line("base_uri={$baseUri} peer_id={$peerId} cursor={$cursor} 沒有前進，停止");
                return false;
            }

            $processedCursor = $cursor;
            $state->last_batch_count = count($items);

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $messageId = (int) ($item['id'] ?? 0);
                if ($messageId <= $processedCursor) {
                    continue;
                }

                if (!$this->messageHasDownloadableVideo($item)) {
                    $token = $this->extractDispatchableToken($item);
                    if ($token !== null) {
                        $queued = $this->queueTokenIfNeeded($peerId, $chatTitle, $token, $messageId);

                        $processedCursor = $messageId;
                        $state->max_message_id = $processedCursor;
                        $state->last_message_datetime = $this->convertMessageTimeToTaipei(
                            $this->parseMessageDate($item['date'] ?? null)
                        );
                        $state->save();

                        $this->line(sprintf(
                            'base_uri=%s peer_id=%d message_id=%d token=%s %s',
                            $baseUri,
                            $peerId,
                            $messageId,
                            $token,
                            $queued ? '已寫入 token_scan_items' : '已存在 dialogues / token_scan_items，略過'
                        ));

                        if ($queued) {
                            return true;
                        }
                    }

                    $processedCursor = $messageId;
                    continue;
                }

                $this->line(sprintf(
                    'base_uri=%s peer_id=%d message_id=%d 開始下載媒體',
                    $baseUri,
                    $peerId,
                    $messageId
                ));

                $download = $this->downloadGroupMessageMedia($http, $peerId, $messageId, $chatTitle);
                if (!$download || (string) ($download['status'] ?? '') !== 'ok' || !($download['downloaded'] ?? false)) {
                    $state->max_message_id = $processedCursor;
                    $state->save();
                    $reason = trim((string) ($download['reason'] ?? ''));
                    $error = trim((string) ($download['error'] ?? ''));
                    $summary = "base_uri={$baseUri} peer_id={$peerId} message_id={$messageId} 下載失敗";
                    if ($reason !== '') {
                        $summary .= " reason={$reason}";
                    }
                    if ($error !== '') {
                        $summary .= " error={$error}";
                    }
                    $this->line($summary);

                    $waitSeconds = $this->extractFloodWaitSeconds($error);
                    if ($waitSeconds !== null) {
                        $this->lastFloodWaitSeconds = $waitSeconds;
                        $this->line("FLOOD_WAIT_SECONDS={$waitSeconds}");
                        $this->line("還需等待 {$waitSeconds} 秒後才能再試下載。");
                        $this->lastFailureSummary = $summary;
                        return false;
                    }

                    if ($this->shouldSkipFailedMediaDownload($download, $error)) {
                        $processedCursor = $messageId;
                        $state->max_message_id = $processedCursor;
                        $state->last_message_datetime = $this->convertMessageTimeToTaipei(
                            $this->parseMessageDate($item['date'] ?? null)
                        );
                        $state->save();

                        $this->line(sprintf(
                            'base_uri=%s peer_id=%d message_id=%d 略過卡住媒體，繼續掃描。',
                            $baseUri,
                            $peerId,
                            $messageId
                        ));
                        continue;
                    }

                    $this->lastFailureSummary = $summary;
                    return false;
                }

                $processedCursor = $messageId;
                $state->max_message_id = $processedCursor;
                $state->last_downloaded_message_id = $messageId;
                $state->last_saved_path = (string) ($download['saved_path'] ?? '');
                $state->last_saved_name = (string) ($download['saved_name'] ?? '');
                $state->last_message_datetime = $this->convertMessageTimeToTaipei(
                    $this->parseMessageDate($item['date'] ?? null)
                );
                $state->save();

                $this->table(
                    [
                        'base_uri',
                        'peer_id',
                        'chat_title',
                        'message_id',
                        'saved_name',
                        'saved_path',
                        'cursor',
                    ],
                    [[
                        'base_uri' => $baseUri,
                        'peer_id' => $peerId,
                        'chat_title' => $state->chat_title ?? '',
                        'message_id' => $messageId,
                        'saved_name' => $state->last_saved_name ?? '',
                        'saved_path' => $state->last_saved_path ?? '',
                        'cursor' => $state->max_message_id,
                    ]]
                );

                return true;
            }

            $state->max_message_id = $batchMaxId;
            $state->last_message_datetime = $this->convertMessageTimeToTaipei(
                $this->getDateTimeCarbonByMessageId($items, $batchMaxId)
            );
            $state->save();
            $this->clearBatchCache($baseUri, $peerId);

            $this->line("base_uri={$baseUri} peer_id={$peerId} cursor 前進到 {$batchMaxId}，本批沒有影片媒體");
            $cursor = $batchMaxId;
        }
    }

    private function resolveGroupInfo(Client $http, GroupMediaScanState $state, int $peerId, int $cursor): array
    {
        $lastKnownMessageId = (int) ($state->last_group_message_id ?? 0);
        $chatTitle = trim((string) ($state->chat_title ?? ''));

        if ($lastKnownMessageId > 0 && $cursor < $lastKnownMessageId) {
            return [
                'title' => $chatTitle,
                'last_message_id' => $lastKnownMessageId,
            ];
        }

        $groupsIndex = $this->fetchGroupsIndex($http);
        $groupInfo = $groupsIndex[$peerId] ?? [];

        return [
            'title' => trim((string) ($groupInfo['title'] ?? $chatTitle)),
            'last_message_id' => (int) ($groupInfo['last_message_id'] ?? $lastKnownMessageId),
        ];
    }

    private function getCachedOrFetchGroupPage(Client $http, string $baseUri, int $peerId, int $cursor, int $nextLimit): ?array
    {
        $cache = $this->loadBatchCache($baseUri, $peerId);
        if ($this->isReusableBatchCache($cache, $cursor, $nextLimit)) {
            $batchMaxId = (int) ($cache['batch_max_message_id'] ?? 0);
            $count = is_array($cache['items'] ?? null) ? count($cache['items']) : 0;
            $this->line("base_uri={$baseUri} peer_id={$peerId} 使用本地快取 batch_max_id={$batchMaxId} count={$count}");

            return [
                'status' => 'ok',
                'items' => (array) ($cache['items'] ?? []),
                'count' => $count,
            ];
        }

        $payload = $this->fetchGroupPage($http, $peerId, $cursor, $nextLimit);
        if (!is_array($payload) || (string) ($payload['status'] ?? '') !== 'ok') {
            return $payload;
        }

        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $batchMaxId = $this->getMaxMessageId($items);
        $this->saveBatchCache($baseUri, $peerId, [
            'cursor' => $cursor,
            'next_limit' => $nextLimit,
            'batch_max_message_id' => $batchMaxId,
            'count' => count($items),
            'items' => $items,
            'fetched_at' => now()->toIso8601String(),
        ]);

        $this->line("base_uri={$baseUri} peer_id={$peerId} 重新抓取批次 cursor={$cursor} batch_max_id={$batchMaxId} count=" . count($items));

        return $payload;
    }

    private function fetchGroupsIndex(Client $http): array
    {
        $tries = 0;

        while (true) {
            $tries++;

            try {
                $res = $http->get('groups', [
                    'timeout' => self::FAST_REQUEST_TIMEOUT_SECONDS,
                    'connect_timeout' => 10,
                ]);
                $json = json_decode((string) $res->getBody(), true);
                if (!is_array($json)) {
                    return [];
                }

                $items = $json['items'] ?? [];
                if (!is_array($items)) {
                    return [];
                }

                $index = [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }

                    $id = (int) ($item['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    $index[$id] = [
                        'title' => (string) ($item['title'] ?? ''),
                        'last_message_id' => (int) ($item['last_message_id'] ?? 0),
                    ];
                }

                return $index;
            } catch (GuzzleException $e) {
                if ($tries >= self::GROUP_FETCH_MAX_RETRIES) {
                    $this->line('HTTP 失敗：GET /groups err=' . $e->getMessage());
                    return [];
                }

                usleep(250000);
            }
        }
    }

    private function fetchGroupPage(Client $http, int $peerId, int $startMessageId, int $nextLimit): ?array
    {
        $path = "groups/{$peerId}/{$startMessageId}?next_limit={$nextLimit}&include_raw=true";
        $tries = 0;

        while (true) {
            $tries++;

            try {
                $res = $http->get($path, [
                    'timeout' => self::FAST_REQUEST_TIMEOUT_SECONDS,
                    'connect_timeout' => 10,
                ]);
                $json = json_decode((string) $res->getBody(), true);
                return is_array($json) ? $json : null;
            } catch (GuzzleException $e) {
                if ($tries >= self::GROUP_FETCH_MAX_RETRIES) {
                    $this->line("HTTP 失敗：GET {$path} err=" . $e->getMessage());
                    return null;
                }

                usleep(250000);
            }
        }
    }

    private function downloadGroupMessageMedia(Client $http, int $peerId, int $messageId, string $chatTitle): ?array
    {
        $tries = 0;
        $folderLabel = trim($chatTitle) !== '' ? $chatTitle : "group_{$peerId}";

        while (true) {
            $tries++;

            try {
                $res = $http->post('groups/download-message-media', [
                    'json' => [
                        'peer_id' => $peerId,
                        'message_id' => $messageId,
                        'folder_label' => "group_{$peerId}_{$folderLabel}",
                    ],
                    'timeout' => self::MEDIA_DOWNLOAD_TIMEOUT_SECONDS,
                    'connect_timeout' => 10,
                ]);

                $json = json_decode((string) $res->getBody(), true);
                return is_array($json) ? $json : null;
            } catch (GuzzleException $e) {
                if ($tries >= self::MEDIA_DOWNLOAD_MAX_RETRIES) {
                    return [
                        'status' => 'fail',
                        'reason' => 'request_error',
                        'peer_id' => $peerId,
                        'message_id' => $messageId,
                        'error' => $e->getMessage(),
                    ];
                }

                usleep(250000);
            }
        }
    }

    private function extractFloodWaitSeconds(string $message): ?int
    {
        $message = trim($message);
        if ($message === '') {
            return null;
        }

        if (preg_match('/A wait of\s+(\d+)\s+seconds\s+is required/i', $message, $matches) === 1) {
            $seconds = (int) ($matches[1] ?? 0);
            return $seconds > 0 ? $seconds : null;
        }

        return null;
    }

    private function shouldSkipFailedMediaDownload(?array $download, string $error): bool
    {
        if (!is_array($download)) {
            return false;
        }

        if ($this->extractFloodWaitSeconds($error) !== null) {
            return false;
        }

        $reason = trim((string) ($download['reason'] ?? ''));
        if ($reason === '') {
            return false;
        }

        return in_array($reason, [
            'telethon_download_timeout',
            'telethon_download_error',
            'message_not_found',
            'entity_not_found',
            'no_media',
        ], true);
    }

    private function queueTokenIfNeeded(int $peerId, string $chatTitle, string $token, int $messageId): bool
    {
        $token = trim($token);
        if ($token === '') {
            return false;
        }

        if (TokenScanItem::query()->where('token', $token)->exists()) {
            return false;
        }

        if (DB::table('dialogues')
            ->where('chat_id', self::DIALOGUES_CHAT_ID)
            ->where('text', $token)
            ->exists()) {
            return false;
        }

        $header = TokenScanHeader::query()->firstOrCreate(
            ['peer_id' => $peerId],
            [
                'chat_title' => trim($chatTitle) !== '' ? $chatTitle : null,
                'last_start_message_id' => 1,
                'max_message_id' => 0,
                'last_batch_count' => 0,
            ]
        );

        if (trim((string) ($header->chat_title ?? '')) === '' && trim($chatTitle) !== '') {
            $header->chat_title = $chatTitle;
            $header->save();
        }

        $createdAt = now();

        DB::transaction(function () use ($header, $token, $createdAt) {
            $alreadyQueued = TokenScanItem::query()->where('token', $token)->exists();
            $alreadyInDialogues = DB::table('dialogues')
                ->where('chat_id', self::DIALOGUES_CHAT_ID)
                ->where('text', $token)
                ->exists();

            if ($alreadyQueued || $alreadyInDialogues) {
                return;
            }

            TokenScanItem::query()->create([
                'header_id' => (int) $header->id,
                'token' => $token,
                'created_at' => $createdAt,
            ]);

            $dialoguesMessageId = (int) (DB::table('dialogues')
                ->where('chat_id', self::DIALOGUES_CHAT_ID)
                ->max('message_id') ?? 0);

            DB::table('dialogues')->insert([
                'chat_id' => self::DIALOGUES_CHAT_ID,
                'message_id' => $dialoguesMessageId + 1,
                'text' => $token,
                'is_read' => 1,
                'created_at' => $createdAt,
            ]);
        });

        return TokenScanItem::query()
            ->where('header_id', (int) $header->id)
            ->where('token', $token)
            ->exists();
    }

    private function messageHasDownloadableVideo(array $message): bool
    {
        $media = $message['media'] ?? null;
        if (!is_array($media)) {
            return false;
        }

        $mediaType = strtolower((string) ($media['_'] ?? ''));
        if ($mediaType === 'messagemediaphoto') {
            return false;
        }

        $document = is_array($media['document'] ?? null) ? $media['document'] : [];
        $mimeType = strtolower((string) ($document['mime_type'] ?? ''));
        if (str_starts_with($mimeType, 'video/')) {
            return true;
        }

        $attributes = $document['attributes'] ?? [];
        if (is_array($attributes)) {
            foreach ($attributes as $attribute) {
                if (!is_array($attribute)) {
                    continue;
                }

                if (strtolower((string) ($attribute['_'] ?? '')) === 'documentattributevideo') {
                    return true;
                }
            }
        }

        return false;
    }

    private function extractDispatchableToken(array $message): ?string
    {
        $texts = [];

        $messageText = trim((string) ($message['message'] ?? $message['text'] ?? ''));
        if ($messageText !== '') {
            $texts[] = $messageText;
        }

        $webpage = is_array($message['media']['webpage'] ?? null) ? $message['media']['webpage'] : [];
        $title = trim((string) ($webpage['title'] ?? ''));
        if ($title !== '') {
            $texts[] = $title;
        }

        foreach ($texts as $text) {
            $tokens = $this->tokenService->extractTokens($text);
            foreach ($tokens as $token) {
                $token = trim((string) $token);
                if ($token === '') {
                    continue;
                }

                if (
                    Str::startsWith($token, 'newjmqbot_') ||
                    Str::startsWith($token, 'showfilesbot_') ||
                    Str::startsWith($token, 'showfiles3bot_') ||
                    Str::startsWith(Str::lower($token), 'mtfxqbot_') ||
                    Str::startsWith(Str::lower($token), 'qqfile_bot:') ||
                    Str::startsWith(Str::lower($token), 'yzfile_bot:') ||
                    Str::startsWith(Str::lower($token), 'atfileslinksbot_') ||
                    Str::startsWith(Str::lower($token), 'lddeebot_')
                ) {
                    return $token;
                }
            }
        }

        return null;
    }

    private function resolveBotByToken(string $token): ?array
    {
        if (
            Str::startsWith($token, 'newjmqbot_') ||
            Str::startsWith($token, 'showfilesbot_') ||
            Str::startsWith($token, 'showfiles3bot_')
        ) {
            return self::BOT_VIPFILES;
        }

        if (Str::startsWith(Str::lower($token), 'mtfxqbot_')) {
            return self::BOT_MTFXQ;
        }

        return null;
    }

    private function dispatchTokenToBot(Client $http, string $token): array
    {
        $bot = $this->resolveBotByToken($token);
        if ($bot === null) {
            return [
                'classification' => 'failed',
                'summary' => 'unsupported token prefix',
                'bot_display' => '-',
            ];
        }

        $payload = $this->buildBotApiPayload($token, (string) $bot['api']);
        $tries = 0;

        while (true) {
            $tries++;

            try {
                $res = $http->post('bots/send-and-run-all-pages', [
                    'json' => $payload,
                    'timeout' => self::BOT_REQUEST_TIMEOUT_SECONDS,
                    'connect_timeout' => 10,
                ]);

                $json = json_decode((string) $res->getBody(), true);
                if (!is_array($json)) {
                    return [
                        'classification' => 'failed',
                        'summary' => 'invalid json response',
                        'bot_display' => (string) $bot['display'],
                    ];
                }

                $latestMessage = is_array($json['latest_message'] ?? null) ? $json['latest_message'] : [];
                $pageState = is_array($json['page_state'] ?? null) ? $json['page_state'] : [];
                $filesUniqueCount = (int) ($json['files_unique_count'] ?? 0);
                $latestTextPreview = trim((string) ($latestMessage['text_preview'] ?? ''));

                if ($this->isVipfilesRunCompleted($json, $latestMessage, $pageState, $filesUniqueCount)) {
                    return [
                        'classification' => 'success',
                        'summary' => 'bot completed',
                        'bot_display' => (string) $bot['display'],
                    ];
                }

                if ($this->responseContainsNotFound($json, $latestTextPreview)) {
                    return [
                        'classification' => 'not_found',
                        'summary' => 'bot returned not found',
                        'bot_display' => (string) $bot['display'],
                    ];
                }

                return [
                    'classification' => 'failed',
                    'summary' => trim((string) ($json['reason'] ?? 'run not completed')) ?: 'run not completed',
                    'bot_display' => (string) $bot['display'],
                ];
            } catch (GuzzleException $e) {
                if ($tries >= self::BOT_DISPATCH_MAX_RETRIES) {
                    return [
                        'classification' => 'failed',
                        'summary' => $e->getMessage(),
                        'bot_display' => (string) $bot['display'],
                    ];
                }

                usleep(250000);
            }
        }
    }

    private function buildBotApiPayload(string $token, string $botApi): array
    {
        return [
            'bot_username' => $botApi,
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

        foreach ($texts as $text) {
            foreach (self::NOT_FOUND_MARKERS as $marker) {
                if ($marker !== '' && Str::contains($text, $marker)) {
                    return true;
                }
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

    private function getMaxMessageId(array $items): int
    {
        $max = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $id = (int) ($item['id'] ?? 0);
            if ($id > $max) {
                $max = $id;
            }
        }

        return $max;
    }

    private function parseMessageDate(mixed $raw): ?Carbon
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function getDateTimeCarbonByMessageId(array $items, int $messageId): ?Carbon
    {
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ((int) ($item['id'] ?? 0) !== $messageId) {
                continue;
            }

            return $this->parseMessageDate($item['date'] ?? null);
        }

        return null;
    }

    private function convertMessageTimeToTaipei(?Carbon $carbon): ?Carbon
    {
        if ($carbon === null) {
            return null;
        }

        try {
            return $carbon->copy()->setTimezone(self::TARGET_TIMEZONE);
        } catch (\Throwable) {
            return null;
        }
    }

    private function batchCachePath(string $baseUri, int $peerId): string
    {
        $baseKey = preg_replace('/[^A-Za-z0-9]+/', '_', strtolower($baseUri));
        $baseKey = trim((string) $baseKey, '_');
        if ($baseKey === '') {
            $baseKey = 'default';
        }

        $dir = storage_path('app/group_media_batches');
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        return $dir . DIRECTORY_SEPARATOR . $baseKey . '_peer_' . $peerId . '.json';
    }

    private function loadBatchCache(string $baseUri, int $peerId): ?array
    {
        $path = $this->batchCachePath($baseUri, $peerId);
        if (!is_file($path)) {
            return null;
        }

        try {
            $json = file_get_contents($path);
            if ($json === false || trim($json) === '') {
                return null;
            }

            $data = json_decode($json, true);
            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function saveBatchCache(string $baseUri, int $peerId, array $payload): void
    {
        $path = $this->batchCachePath($baseUri, $peerId);

        try {
            file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
        }
    }

    private function clearBatchCache(string $baseUri, int $peerId): void
    {
        $path = $this->batchCachePath($baseUri, $peerId);

        try {
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (\Throwable) {
        }
    }

    private function isReusableBatchCache(?array $cache, int $cursor, int $nextLimit): bool
    {
        if (!is_array($cache)) {
            return false;
        }

        $items = $cache['items'] ?? null;
        if (!is_array($items) || empty($items)) {
            return false;
        }

        $cachedLimit = (int) ($cache['next_limit'] ?? 0);
        $batchMaxId = (int) ($cache['batch_max_message_id'] ?? 0);
        $batchCursor = (int) ($cache['cursor'] ?? 0);

        if ($cachedLimit !== $nextLimit) {
            return false;
        }

        if ($batchMaxId <= 0 || $cursor >= $batchMaxId) {
            return false;
        }

        if ($cursor < $batchCursor) {
            return false;
        }

        return true;
    }
}
