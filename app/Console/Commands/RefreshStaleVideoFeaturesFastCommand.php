<?php

namespace App\Console\Commands;

use App\Models\VideoFeature;
use App\Models\VideoMaster;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

class RefreshStaleVideoFeaturesFastCommand extends Command
{
    protected $signature = 'video:refresh-stale-features-fast
        {--workers=8 : 平行 refresh worker 數量}
        {--worker-manifest= : 內部 worker 使用的 video_master ID 清單}
        {--worker-output= : 內部 worker 使用的輸出 JSON}';

    protected $description = '平行把舊版影片特徵定向更新到目前版本，缺少來源影片者保留既有特徵並另列。';

    public function handle(VideoFeatureExtractionService $featureExtractionService): int
    {
        $workerManifest = trim((string) $this->option('worker-manifest'));
        $workerOutput = trim((string) $this->option('worker-output'));
        if ($workerManifest !== '' || $workerOutput !== '') {
            return $this->runWorker($workerManifest, $workerOutput, $featureExtractionService);
        }

        $currentVersion = $featureExtractionService->currentFeatureVersion();
        $features = VideoFeature::query()
            ->with('videoMaster')
            ->where(function ($query) use ($currentVersion): void {
                $query
                    ->whereNull('feature_version')
                    ->orWhere('feature_version', '!=', $currentVersion);
            })
            ->orderBy('video_master_id')
            ->get();

        $videoMasterIds = [];
        $missingSources = [];

        foreach ($features as $feature) {
            $video = $feature->videoMaster;
            if (!$video instanceof VideoMaster) {
                $missingSources[] = [
                    'video_master_id' => (int) $feature->video_master_id,
                    'message' => 'video_master 不存在。',
                ];
                continue;
            }

            try {
                $featureExtractionService->resolveAbsoluteVideoPath($video);
                $videoMasterIds[] = (int) $video->id;
            } catch (Throwable $e) {
                $missingSources[] = [
                    'video_master_id' => (int) $video->id,
                    'message' => $e->getMessage(),
                ];
            }
        }

        $this->line(sprintf(
            '舊版特徵定向回填：stale=%d refreshable=%d missing_source=%d target_version=%s',
            count($features),
            count($videoMasterIds),
            count($missingSources),
            $currentVersion
        ));

        if ($videoMasterIds === []) {
            foreach ($missingSources as $missingSource) {
                $this->warn(sprintf(
                    '  缺少來源：video_master_id=%d -> %s',
                    $missingSource['video_master_id'],
                    $missingSource['message']
                ));
            }

            return self::SUCCESS;
        }

        $workerCount = max(1, min(16, (int) $this->option('workers')));
        $workerCount = min($workerCount, count($videoMasterIds));
        $jobDir = storage_path('app/video_features/refresh_jobs/' . uniqid('job_', true));
        File::ensureDirectoryExists($jobDir);
        $partitions = array_fill(0, $workerCount, []);

        foreach ($videoMasterIds as $index => $videoMasterId) {
            $partitions[$index % $workerCount][] = $videoMasterId;
        }

        $jobs = [];

        try {
            foreach ($partitions as $workerIndex => $partition) {
                $manifestPath = $jobDir . DIRECTORY_SEPARATOR . sprintf('worker_%02d_manifest.json', $workerIndex + 1);
                $outputPath = $jobDir . DIRECTORY_SEPARATOR . sprintf('worker_%02d_output.json', $workerIndex + 1);
                File::put($manifestPath, json_encode($partition, JSON_THROW_ON_ERROR));

                $process = new Process([
                    PHP_BINARY,
                    base_path('artisan'),
                    'video:refresh-stale-features-fast',
                    '--worker-manifest=' . $manifestPath,
                    '--worker-output=' . $outputPath,
                    '--no-interaction',
                ]);
                $process->setTimeout(14400);
                $process->start();
                $jobs[] = [
                    'process' => $process,
                    'output_path' => $outputPath,
                    'worker' => $workerIndex + 1,
                ];
            }

            $this->line(sprintf('已啟動 %d 個平行特徵 refresh workers。', count($jobs)));
            $refreshedIds = [];
            $failedFiles = [];

            foreach ($jobs as $job) {
                /** @var Process $process */
                $process = $job['process'];
                $exitCode = $process->wait();
                $outputPath = (string) $job['output_path'];

                if ($exitCode !== 0 || !is_file($outputPath)) {
                    $failedFiles[] = [
                        'video_master_id' => 0,
                        'message' => sprintf(
                            'worker %d 失敗：%s',
                            $job['worker'],
                            trim($process->getErrorOutput() . PHP_EOL . $process->getOutput())
                        ),
                    ];
                    continue;
                }

                $result = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
                $refreshedIds = array_merge($refreshedIds, (array) ($result['refreshed_ids'] ?? []));
                $failedFiles = array_merge($failedFiles, (array) ($result['failed_files'] ?? []));
                $this->line(sprintf(
                    '  worker %d 完成：refreshed=%d failed=%d',
                    $job['worker'],
                    count((array) ($result['refreshed_ids'] ?? [])),
                    count((array) ($result['failed_files'] ?? []))
                ));
            }

            foreach ($missingSources as $missingSource) {
                $this->warn(sprintf(
                    '  缺少來源、保留既有特徵：video_master_id=%d -> %s',
                    $missingSource['video_master_id'],
                    $missingSource['message']
                ));
            }
            foreach ($failedFiles as $failedFile) {
                $this->error(sprintf(
                    '  refresh 失敗：video_master_id=%d -> %s',
                    (int) ($failedFile['video_master_id'] ?? 0),
                    (string) ($failedFile['message'] ?? '')
                ));
            }

            $this->info(sprintf(
                '舊版特徵回填完成：refreshed=%d missing_source=%d failed=%d',
                count($refreshedIds),
                count($missingSources),
                count($failedFiles)
            ));

            return $failedFiles === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            foreach ($jobs as $job) {
                $process = $job['process'] ?? null;
                if ($process instanceof Process && $process->isRunning()) {
                    $process->stop(1);
                }
            }

            $this->error('舊版特徵快速回填失敗：' . $e->getMessage());
            return self::FAILURE;
        } finally {
            if (File::isDirectory($jobDir)) {
                File::deleteDirectory($jobDir);
            }
        }
    }

    private function runWorker(
        string $manifestPath,
        string $outputPath,
        VideoFeatureExtractionService $featureExtractionService
    ): int {
        if ($manifestPath === '' || $outputPath === '' || !is_file($manifestPath)) {
            return self::FAILURE;
        }

        try {
            $videoMasterIds = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($videoMasterIds)) {
                return self::FAILURE;
            }

            $refreshedIds = [];
            $failedFiles = [];

            foreach ($videoMasterIds as $videoMasterId) {
                $videoMasterId = (int) $videoMasterId;

                try {
                    $video = VideoMaster::query()->findOrFail($videoMasterId);
                    $featureExtractionService->extractForVideo($video, true);
                    $refreshedIds[] = $videoMasterId;
                } catch (Throwable $e) {
                    $failedFiles[] = [
                        'video_master_id' => $videoMasterId,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            File::put(
                $outputPath,
                json_encode([
                    'refreshed_ids' => $refreshedIds,
                    'failed_files' => $failedFiles,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            return self::SUCCESS;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }
}
