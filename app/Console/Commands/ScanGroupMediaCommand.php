<?php

namespace App\Console\Commands;

use App\Models\GroupMediaScanState;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;

class ScanGroupMediaCommand extends Command
{
    protected $signature = 'tg:scan-group-media {--base-uri= : 覆蓋 API base_uri（預設使用程式內設定）} {--until-empty : 持續掃到沒有新媒體為止}';
    protected $description = '逐筆掃描 Telegram 群組媒體訊息，每次只下載一筆到本地，並記錄游標供下次續跑';

    private const HTTP_MAX_RETRIES = 3;
    private const TARGET_TIMEZONE = 'Asia/Taipei';

    private array $scanTargetsByBaseUri = [
        'http://127.0.0.1:8000/' => [
            2755698006,
        ],
    ];

    private array $groupsIndex = [];

    public function handle(): int
    {
        $overrideBaseUri = trim((string) $this->option('base-uri'));
        $untilEmpty = (bool) $this->option('until-empty');
        $targetsByBaseUri = $this->resolveTargetsByBaseUri($overrideBaseUri);

        $round = 0;

        while (true) {
            $round++;
            $downloadedThisRound = false;

            foreach ($targetsByBaseUri as $baseUri => $peerIds) {
                $baseUri = trim((string) $baseUri);
                if ($baseUri === '' || empty($peerIds)) {
                    continue;
                }

                $http = $this->makeHttpClient($baseUri);
                $this->groupsIndex = $this->fetchGroupsIndex($http);

                foreach ($peerIds as $peerId) {
                    $peerId = (int) $peerId;
                    if ($peerId <= 0) {
                        continue;
                    }

                    $downloaded = $this->scanOnePeer($http, $baseUri, $peerId);
                    if ($downloaded) {
                        $downloadedThisRound = true;
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

        $this->line('本次沒有可下載的新媒體檔案。');

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
            'timeout' => 60,
            'connect_timeout' => 10,
        ]);
    }

    private function scanOnePeer(Client $http, string $baseUri, int $peerId): bool
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

        $groupInfo = $this->groupsIndex[$peerId] ?? [];
        $chatTitle = trim((string) ($groupInfo['title'] ?? ''));
        if ($chatTitle !== '' && $state->chat_title !== $chatTitle) {
            $state->chat_title = $chatTitle;
        }

        $latestGroupMessageId = (int) ($groupInfo['last_message_id'] ?? 0);
        if ($latestGroupMessageId > 0) {
            $state->last_group_message_id = $latestGroupMessageId;
        }
        $state->save();

        $cursor = max(1, (int) ($state->max_message_id ?? 1));

        $this->line(str_repeat('-', 80));
        $this->line("base_uri={$baseUri} peer_id={$peerId} cursor={$cursor} 開始掃描媒體");

        while (true) {
            if ($latestGroupMessageId > 0 && $cursor >= $latestGroupMessageId) {
                $this->line("base_uri={$baseUri} peer_id={$peerId} 已掃到最新 last_message_id={$latestGroupMessageId}");
                return false;
            }

            $payload = $this->fetchGroupPage($http, $peerId, $cursor);
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

                if (!$this->messageHasMedia($item)) {
                    $processedCursor = $messageId;
                    continue;
                }

                $download = $this->downloadGroupMessageMedia($http, $peerId, $messageId, $chatTitle);
                if (!$download || (string) ($download['status'] ?? '') !== 'ok' || !($download['downloaded'] ?? false)) {
                    $state->max_message_id = $processedCursor;
                    $state->save();
                    $this->line("base_uri={$baseUri} peer_id={$peerId} message_id={$messageId} 下載失敗");
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

            $this->line("base_uri={$baseUri} peer_id={$peerId} cursor 前進到 {$batchMaxId}，本批沒有媒體");
            $cursor = $batchMaxId;
        }
    }

    private function fetchGroupsIndex(Client $http): array
    {
        $tries = 0;

        while (true) {
            $tries++;

            try {
                $res = $http->get('groups');
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
                if ($tries >= self::HTTP_MAX_RETRIES) {
                    $this->line('HTTP 失敗：GET /groups err=' . $e->getMessage());
                    return [];
                }

                usleep(250000);
            }
        }
    }

    private function fetchGroupPage(Client $http, int $peerId, int $startMessageId): ?array
    {
        $path = "groups/{$peerId}/{$startMessageId}";
        $tries = 0;

        while (true) {
            $tries++;

            try {
                $res = $http->get($path);
                $json = json_decode((string) $res->getBody(), true);
                return is_array($json) ? $json : null;
            } catch (GuzzleException $e) {
                if ($tries >= self::HTTP_MAX_RETRIES) {
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
                ]);

                $json = json_decode((string) $res->getBody(), true);
                return is_array($json) ? $json : null;
            } catch (GuzzleException $e) {
                if ($tries >= self::HTTP_MAX_RETRIES) {
                    $this->line("HTTP 失敗：POST groups/download-message-media message_id={$messageId} err=" . $e->getMessage());
                    return null;
                }

                usleep(250000);
            }
        }
    }

    private function messageHasMedia(array $message): bool
    {
        return array_key_exists('media', $message) && $message['media'] !== null;
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
}
