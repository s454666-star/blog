<?php

namespace App\Services;

use App\Models\VideoRerunSyncEntry;
use App\Models\VideoRerunSyncRun;
use App\Support\VideoRerunSyncSource;
use Illuminate\Support\Collection;

class VideoRerunDiffService
{
    public function latestRun(): ?VideoRerunSyncRun
    {
        return VideoRerunSyncRun::query()->latest('started_at')->first();
    }

    public function summary(): array
    {
        $diffs = $this->diffGroups();
        $issues = $this->issueEntries();

        return [
            'diff_groups' => $diffs->count(),
            'issues' => $issues->count(),
            'fillable_groups' => $diffs->where('can_fill_missing', true)->count(),
            'deletable_groups' => $diffs->where('can_delete_extras', true)->count(),
        ];
    }

    public function diffGroups(?string $search = null, string $mode = 'all'): Collection
    {
        $groups = VideoRerunSyncEntry::query()
            ->where('is_present', true)
            ->where('fingerprint_status', 'hashed')
            ->whereNotNull('content_sha1')
            ->orderBy('source_type')
            ->orderBy('display_name')
            ->get()
            ->groupBy('content_sha1')
            ->map(fn (Collection $entries, string $hash) => $this->buildGroup($hash, $entries))
            ->filter(fn (?array $group) => $group !== null)
            ->values();

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $groups = $groups->filter(function (array $group) use ($needle): bool {
                $haystacks = [
                    $group['title'],
                    $group['hash'],
                    ...$group['aliases'],
                ];

                foreach ($haystacks as $value) {
                    if (mb_stripos((string) $value, $needle) !== false) {
                        return true;
                    }
                }

                return false;
            })->values();
        }

        return $groups->filter(function (array $group) use ($mode): bool {
            return match ($mode) {
                'missing' => $group['has_missing'],
                'extra' => $group['has_extra'],
                default => true,
            };
        })->values();
    }

    public function issueEntries(): Collection
    {
        return VideoRerunSyncEntry::query()
            ->where('is_present', true)
            ->where('fingerprint_status', '!=', 'hashed')
            ->orderBy('source_type')
            ->orderBy('display_name')
            ->get()
            ->map(function (VideoRerunSyncEntry $entry): array {
                return [
                    'id' => $entry->id,
                    'source_label' => VideoRerunSyncSource::label($entry->source_type),
                    'title' => $entry->resource_key ?: $entry->display_name ?: $entry->source_key,
                    'display_name' => $entry->display_name,
                    'path' => $entry->absolute_path ?: $entry->relative_path,
                    'status' => $entry->fingerprint_status,
                    'message' => $entry->last_error ?: '無法比對',
                    'metadata' => $entry->metadata_json ?? [],
                ];
            });
    }

    public function findGroup(string $hash): ?array
    {
        return $this->diffGroups()->firstWhere('hash', $hash);
    }

    private function buildGroup(string $hash, Collection $entries): ?array
    {
        $bySource = [];
        foreach (VideoRerunSyncSource::all() as $source) {
            $bySource[$source] = $entries
                ->where('source_type', $source)
                ->values()
                ->map(fn (VideoRerunSyncEntry $entry) => $this->mapEntry($entry));
        }

        $dbCount = $bySource[VideoRerunSyncSource::DB]->count();
        $rerunCount = $bySource[VideoRerunSyncSource::RERUN_DISK]->count();
        $eagleCount = $bySource[VideoRerunSyncSource::EAGLE]->count();
        $hasDb = $dbCount > 0;
        $hasRerun = $rerunCount > 0;
        $hasEagle = $eagleCount > 0;
        $hasMissing = !$hasDb || !$hasRerun || !$hasEagle;
        $hasDuplicates = $dbCount > 1 || $rerunCount > 1 || $eagleCount > 1;
        $hasExtra = $hasDuplicates || ($hasRerun && (!$hasDb || !$hasEagle)) || ($hasEagle && (!$hasDb || !$hasRerun));

        if (!$hasMissing && !$hasDuplicates) {
            return null;
        }

        $preferredEntry = $this->pickPreferredEntry($bySource);
        $aliases = $entries
            ->map(fn (VideoRerunSyncEntry $entry) => (string) ($entry->resource_key ?: $entry->display_name))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $missingTargets = [];
        if (!$hasRerun && ($hasDb || $hasEagle)) {
            $missingTargets[] = VideoRerunSyncSource::RERUN_DISK;
        }
        if (!$hasEagle && ($hasDb || $hasRerun)) {
            $missingTargets[] = VideoRerunSyncSource::EAGLE;
        }

        $deleteTargets = [];
        if ($hasRerun && (!$hasDb || !$hasEagle || $rerunCount > 1)) {
            $deleteTargets[] = VideoRerunSyncSource::RERUN_DISK;
        }
        if ($hasEagle && (!$hasDb || !$hasRerun || $eagleCount > 1)) {
            $deleteTargets[] = VideoRerunSyncSource::EAGLE;
        }

        return [
            'hash' => $hash,
            'hash_short' => substr($hash, 0, 10),
            'title' => $preferredEntry['resource_key'] ?: $preferredEntry['display_name'] ?: substr($hash, 0, 12),
            'preferred_name' => $preferredEntry['resource_key'] ?: pathinfo((string) $preferredEntry['display_name'], PATHINFO_FILENAME),
            'preferred_extension' => $preferredEntry['file_extension'] ?: 'mp4',
            'size_bytes' => (int) ($preferredEntry['file_size_bytes'] ?? 0),
            'aliases' => $aliases,
            'sources' => [
                VideoRerunSyncSource::DB => [
                    'label' => VideoRerunSyncSource::label(VideoRerunSyncSource::DB),
                    'count' => $dbCount,
                    'present' => $hasDb,
                    'entries' => $bySource[VideoRerunSyncSource::DB]->all(),
                ],
                VideoRerunSyncSource::RERUN_DISK => [
                    'label' => VideoRerunSyncSource::label(VideoRerunSyncSource::RERUN_DISK),
                    'count' => $rerunCount,
                    'present' => $hasRerun,
                    'entries' => $bySource[VideoRerunSyncSource::RERUN_DISK]->all(),
                ],
                VideoRerunSyncSource::EAGLE => [
                    'label' => VideoRerunSyncSource::label(VideoRerunSyncSource::EAGLE),
                    'count' => $eagleCount,
                    'present' => $hasEagle,
                    'entries' => $bySource[VideoRerunSyncSource::EAGLE]->all(),
                ],
            ],
            'has_missing' => $hasMissing,
            'has_extra' => $hasExtra,
            'missing_targets' => $missingTargets,
            'delete_targets' => $deleteTargets,
            'can_fill_missing' => $missingTargets !== [],
            'can_delete_extras' => $deleteTargets !== [],
        ];
    }

    private function mapEntry(VideoRerunSyncEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'source_type' => $entry->source_type,
            'source_key' => $entry->source_key,
            'source_item_id' => $entry->source_item_id,
            'resource_key' => $entry->resource_key,
            'display_name' => $entry->display_name,
            'relative_path' => $entry->relative_path,
            'absolute_path' => $entry->absolute_path,
            'file_extension' => $entry->file_extension,
            'file_size_bytes' => $entry->file_size_bytes,
            'file_modified_at' => optional($entry->file_modified_at)?->format('Y-m-d H:i:s'),
            'metadata' => $entry->metadata_json ?? [],
        ];
    }

    private function pickPreferredEntry(array $bySource): array
    {
        foreach ([VideoRerunSyncSource::DB, VideoRerunSyncSource::RERUN_DISK, VideoRerunSyncSource::EAGLE] as $source) {
            $first = $bySource[$source]->first();
            if (is_array($first)) {
                return $first;
            }
        }

        return [
            'resource_key' => null,
            'display_name' => null,
            'file_extension' => null,
            'file_size_bytes' => 0,
        ];
    }
}
