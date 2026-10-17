<?php

namespace App\Services;

use App\Models\FolderVideoDislike;
use Illuminate\Support\Collection;
use Throwable;

class FolderVideoDislikeService
{
    private const CLIENTS = ['web', 'android', 'tv'];

    public function dislike(string $videoId, string $filename, string $source, ?string $client): void
    {
        FolderVideoDislike::query()->updateOrCreate(
            ['video_key' => FolderVideoDislike::keyFor($videoId)],
            [
                'video_id' => $videoId,
                'source' => $source,
                'filename' => $filename,
                'client' => in_array($client, self::CLIENTS, true) ? $client : null,
                'disliked_at' => now(),
            ]
        );
    }

    public function undislike(string $videoId): void
    {
        FolderVideoDislike::query()->where('video_key', FolderVideoDislike::keyFor($videoId))->delete();
    }

    /**
     * @return array<int, string>
     */
    public function dislikedIds(): array
    {
        try {
            return FolderVideoDislike::query()->pluck('video_id')->all();
        } catch (Throwable) {
            // Never let a missing table or a down database break the video list.
            return [];
        }
    }

    public function withFlags(Collection $videos, array $dislikedIds): Collection
    {
        $disliked = array_flip($dislikedIds);

        return $videos->map(fn (array $video) => $video + [
            'disliked' => isset($disliked[$video['id'] ?? '']),
        ]);
    }
}
