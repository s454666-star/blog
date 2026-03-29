<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DialogueTokenStatsDashboardService
{
    private const CACHE_TTL_SECONDS = 3600;

    private const PREFIX_LABELS = [
        'mtfxqbot_' => '@mtfxqbot',
        'showfilesbot_' => '@showfiles12bot',
        'showfiles3bot_' => '@vipfiles2bot',
        'filestoebot_' => '@filestoebot',
        'QQfile_bot:' => '@showfiles12bot',
        'yzfile_bot:' => '@yzfile_bot',
        'ntmjmqbot_' => '@showfiles12bot',
        'newjmqbot_' => '@newjmqbot',
        'Save2BoxBot' => '@Save2BoxBot',
        'Messengercode_' => '@Messengercode',
        'atfileslinksbot_' => '@atfileslinksbot',
        'lddeebot_' => '@lddeebot',
        'datapanbot_' => '@datapanbot',
        'vi_' => '@showfiles12bot',
        'iv_' => '@showfiles12bot',
        'pk_' => 'pk_',
        'LH_' => 'LH_',
        'link:' => 'link:',
        '@filepan_bot:' => '@filepan_bot',
        'filepan_bot:' => '@filepan_bot',
    ];

    public function __construct(
        private readonly TelegramCodeTokenService $tokenService,
    ) {
    }

    public function getDashboard(): array
    {
        $dialogueCount = (int) DB::table('dialogues')->count('id');
        $syncedDialogueCount = (int) DB::table('dialogues')->where('is_sync', 1)->count('id');
        $maxDialogueId = (int) (DB::table('dialogues')->max('id') ?? 0);
        $cacheKey = sprintf(
            'dialogues:token-stats:v2:%d:%d:%d',
            $dialogueCount,
            $syncedDialogueCount,
            $maxDialogueId
        );

        return Cache::remember($cacheKey, now()->addSeconds(self::CACHE_TTL_SECONDS), function () use (
            $dialogueCount,
            $syncedDialogueCount,
            $maxDialogueId
        ) {
            return $this->buildSnapshot($dialogueCount, $syncedDialogueCount, $maxDialogueId);
        });
    }

    private function buildSnapshot(int $dialogueCount, int $syncedDialogueCount, int $maxDialogueId): array
    {
        $prefixStats = [];
        $rowsWithTokens = 0;
        $totalTokenCount = 0;
        $syncedTokenCount = 0;

        DB::table('dialogues')
            ->select('id', 'text', 'is_sync')
            ->orderBy('id')
            ->chunkById(4000, function ($rows) use (&$prefixStats, &$rowsWithTokens, &$totalTokenCount, &$syncedTokenCount) {
                foreach ($rows as $row) {
                    $tokens = $this->tokenService->extractTokens((string) $row->text);
                    if ($tokens === []) {
                        continue;
                    }

                    $rowsWithTokens++;
                    $isSynced = (bool) $row->is_sync;

                    foreach ($tokens as $token) {
                        $prefix = $this->resolvePrefix($token);
                        if ($prefix === null) {
                            continue;
                        }

                        if (!isset($prefixStats[$prefix])) {
                            $prefixStats[$prefix] = $this->makeEmptyPrefixStat($prefix);
                        }

                        $prefixStats[$prefix]['total_count']++;
                        $totalTokenCount++;

                        if ($isSynced) {
                            $prefixStats[$prefix]['synced_count']++;
                            $syncedTokenCount++;
                        } else {
                            $prefixStats[$prefix]['pending_count']++;
                        }

                        if ((int) $row->id > $prefixStats[$prefix]['latest_dialogue_id']) {
                            $prefixStats[$prefix]['latest_dialogue_id'] = (int) $row->id;
                            $prefixStats[$prefix]['latest_token'] = $token;
                        }

                        if ($isSynced && (int) $row->id > $prefixStats[$prefix]['latest_synced_dialogue_id']) {
                            $prefixStats[$prefix]['latest_synced_dialogue_id'] = (int) $row->id;
                            $prefixStats[$prefix]['latest_synced_token'] = $token;
                        }

                        if (!$isSynced && (int) $row->id > $prefixStats[$prefix]['latest_pending_dialogue_id']) {
                            $prefixStats[$prefix]['latest_pending_dialogue_id'] = (int) $row->id;
                            $prefixStats[$prefix]['latest_pending_token'] = $token;
                        }
                    }
                }
            }, 'id');

        foreach ($prefixStats as &$stat) {
            $stat['completion_percent'] = $stat['total_count'] > 0
                ? round(($stat['synced_count'] / $stat['total_count']) * 100, 1)
                : 0.0;
        }
        unset($stat);

        uasort($prefixStats, function (array $left, array $right): int {
            return [
                $right['completion_percent'],
                $right['total_count'],
                $right['synced_count'],
                $left['prefix'],
            ] <=> [
                $left['completion_percent'],
                $left['total_count'],
                $left['synced_count'],
                $right['prefix'],
            ];
        });

        return [
            'summary' => [
                'dialogue_count' => $dialogueCount,
                'synced_dialogue_count' => $syncedDialogueCount,
                'pending_dialogue_count' => max($dialogueCount - $syncedDialogueCount, 0),
                'rows_with_tokens' => $rowsWithTokens,
                'prefix_count' => count($prefixStats),
                'total_token_count' => $totalTokenCount,
                'synced_token_count' => $syncedTokenCount,
                'pending_token_count' => max($totalTokenCount - $syncedTokenCount, 0),
                'completion_percent' => $totalTokenCount > 0
                    ? round(($syncedTokenCount / $totalTokenCount) * 100, 1)
                    : 0.0,
                'max_dialogue_id' => $maxDialogueId,
            ],
            'prefixStats' => array_values($prefixStats),
            'generatedAt' => now(),
        ];
    }

    private function makeEmptyPrefixStat(string $prefix): array
    {
        return [
            'prefix' => $prefix,
            'label' => self::PREFIX_LABELS[$prefix] ?? $prefix,
            'total_count' => 0,
            'synced_count' => 0,
            'pending_count' => 0,
            'completion_percent' => 0.0,
            'latest_dialogue_id' => 0,
            'latest_token' => null,
            'latest_synced_dialogue_id' => 0,
            'latest_synced_token' => null,
            'latest_pending_dialogue_id' => 0,
            'latest_pending_token' => null,
        ];
    }

    private function resolvePrefix(string $token): ?string
    {
        foreach (array_keys(self::PREFIX_LABELS) as $prefix) {
            if (str_starts_with($token, $prefix)) {
                return $prefix;
            }
        }

        return null;
    }
}
