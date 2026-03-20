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
     * php artisan video:extract-features --failed-only=0 --limit=100
     */
    protected $signature = 'video:extract-features
        {--video-id= : 只處理指定的 video_master.id}
        {--video-type= : 只處理指定 video_type}
        {--limit=0 : 最多處理幾筆，0 表示不限制}
        {--failed-only=1 : 1=只重跑 video_features.last_error 有值的失敗資料；0=處理全部待補資料}
        {--refresh : 已有特徵時也強制重建}';

    protected $description = '預設只重跑先前失敗的影片特徵擷取；設 --failed-only=0 可補跑全部待補資料。';

    public function handle(VideoFeatureExtractionService $service): int
    {
        $videoId = $this->option('video-id');
        $videoType = $this->option('video-type');
        $limit = max(0, (int) $this->option('limit'));
        $failedOnly = (int) $this->option('failed-only') !== 0;
        $applyFailedOnlyScope = $failedOnly && !is_numeric($videoId);
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

        if ($applyFailedOnlyScope) {
            $query->whereHas('feature', function ($featureQuery): void {
                $featureQuery->whereNotNull('last_error');
            });
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
            $lastError = trim((string) ($video->feature?->last_error ?? ''));

            if ($applyFailedOnlyScope && $lastError === '') {
                $skipped++;
                $this->line(sprintf('[%d] 非失敗資料，跳過 %s', $video->id, $video->video_name));
                continue;
            }

            $expectedCount = count($service->buildCapturePlan((float) ($video->duration ?? 0)));
            $existingCount = (int) ($video->feature?->frames?->count() ?? 0);

            if (!$refresh && $lastError === '' && $existingCount >= $expectedCount && $expectedCount > 0) {
                $skipped++;
                $this->line(sprintf('[%d] 已有 %d/%d 張特徵截圖，跳過 %s', $video->id, $existingCount, $expectedCount, $video->video_name));
                continue;
            }

            try {
                $feature = $service->extractForVideo($video, $refresh || ($lastError !== '' && $existingCount > 0));
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
