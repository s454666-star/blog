<?php

namespace App\Services;

use App\Models\FolderVideoPlay;
use Illuminate\Support\Collection;
use Throwable;

class FolderVideoPlayService
{
    private const CLIENTS = ['web', 'android', 'tv'];

    public function record(string $videoId, string $filename, string $source, ?string $client): int
    {
        FolderVideoPlay::query()->create([
            'video_key' => FolderVideoPlay::keyFor($videoId),
            'video_id' => $videoId,
            'source' => $source,
            'filename' => $filename,
            'client' => in_array($client, self::CLIENTS, true) ? $client : null,
            'played_at' => now(),
        ]);

        return $this->countsFor([$videoId])[$videoId] ?? 1;
    }

    /**
     * @param  iterable<string>  $videoIds
     * @return array<string, int>
     */
    public function countsFor(iterable $videoIds): array
    {
        $keys = collect($videoIds)
            ->filter(fn ($id) => is_string($id) && $id !== '')
            ->unique()
            ->mapWithKeys(fn (string $id) => [FolderVideoPlay::keyFor($id) => $id]);

        if ($keys->isEmpty()) {
            return [];
        }

        try {
            $rows = FolderVideoPlay::query()
                ->selectRaw('video_key, COUNT(*) as play_count')
                ->whereIn('video_key', $keys->keys()->all())
                ->groupBy('video_key')
                ->pluck('play_count', 'video_key');
        } catch (Throwable) {
            // Play counts are decoration: never let a missing table or a down
            // database break the video list.
            return [];
        }

        return $rows->mapWithKeys(fn ($count, string $key) => [$keys[$key] => (int) $count])->all();
    }

    public function withCounts(Collection $videos): Collection
    {
        $counts = $this->countsFor($videos->pluck('id'));

        return $videos->map(fn (array $video) => $video + [
            'play_count' => $counts[$video['id'] ?? ''] ?? 0,
        ]);
    }
}
