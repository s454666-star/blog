<?php

namespace App\Console\Commands;

use App\Models\VideoMaster;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Console\Command;
use Throwable;

class ExtractVideoFeaturesCommand extends Command
{
    /**
     * 範例:
     * php artisan video:extract-features --limit=100
     * php artisan video:extract-features --limit=1 --video-type=1
     */
    protected $signature = 'video:extract-features
        {--video-id= : 只處理指定的 video_master.id}
        {--video-type= : 只處理指定 video_type}
        {--limit=0 : 最多處理幾筆，0 表示不限制}
        {--refresh : 已有特徵時也強制重建}';

    protected $description = '補齊舊資料的影片截圖與特徵，不做重複比對。';

    public function handle(VideoFeatureExtractionService $service): int
    {
        $videoId = $this->option('video-id');
        $videoType = $this->option('video-type');
        $limit = max(0, (int) $this->option('limit'));
        $refresh = (bool) $this->option('refresh');

        $query = VideoMaster::query()
            ->with('feature.frames')
            ->orderBy('id');

        if (is_numeric($videoId)) {
            $query->whereKey((int) $videoId);
        }

        if (is_string($videoType) && $videoType !== '') {
            $query->where('video_type', $videoType);
        }

        if ($limit > 0) {
            $query->limit($limit);
        }

        $videos = $query->get();
        if ($videos->isEmpty()) {
            $this->warn('找不到符合條件的影片。');
            return 0;
        }

        $processed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($videos as $video) {
            $expectedCount = count($service->buildCapturePlan((float) ($video->duration ?? 0)));
            $existingCount = (int) ($video->feature?->frames?->count() ?? 0);

            if (!$refresh && $existingCount >= $expectedCount && $expectedCount > 0) {
                $skipped++;
                $this->line(sprintf('[%d] 已有 %d/%d 張特徵截圖，跳過 %s', $video->id, $existingCount, $expectedCount, $video->video_name));
                continue;
            }

            try {
                $feature = $service->extractForVideo($video, $refresh || $existingCount > 0);
                $processed++;

                $this->info(sprintf(
                    '[%d] 完成 %s，建立 %d 張特徵截圖',
                    $video->id,
                    $video->video_name,
                    $feature->frames()->count()
                ));
            } catch (Throwable $e) {
                $failed++;
                $this->error(sprintf('[%d] 失敗 %s：%s', $video->id, $video->video_name, $e->getMessage()));
            }
        }

        $this->newLine();
        $this->info(sprintf('完成，processed=%d skipped=%d failed=%d', $processed, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
