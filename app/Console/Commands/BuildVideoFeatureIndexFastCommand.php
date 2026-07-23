<?php

namespace App\Console\Commands;

use App\Services\ReferenceVideoFeatureIndexService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Symfony\Component\Process\Process;
use Throwable;

class BuildVideoFeatureIndexFastCommand extends Command
{
    private const VIDEO_EXTENSIONS = [
        'mp4', 'avi', 'mov', 'mkv', 'wmv', 'flv', 'webm',
        'm4v', 'mpeg', 'mpg', '3gp', 'ts', 'mts', 'm2ts',
    ];

    protected $signature = 'video:build-feature-index-fast
        {path : 要建立 video-feature-index.json 的影片資料夾}
        {--workers=8 : 平行 FFmpeg worker 數量}
        {--worker-manifest= : 內部 worker 使用的檔案清單}
        {--worker-output= : 內部 worker 使用的輸出 JSON}';

    protected $description = '平行擷取資料夾影片的 10/20/30/40 秒 dHash，並一次寫入可重用的快速比對索引。';

    public function handle(
        VideoFeatureExtractionService $featureExtractionService,
        ReferenceVideoFeatureIndexService $referenceIndexService
    ): int {
        $rootPath = $this->normalizeAbsolutePath((string) $this->argument('path'));
        if ($rootPath === '' || !is_dir($rootPath)) {
            $this->error('path 不是有效資料夾：' . $this->argument('path'));
            return self::FAILURE;
        }

        $workerManifest = trim((string) $this->option('worker-manifest'));
        $workerOutput = trim((string) $this->option('worker-output'));
        if ($workerManifest !== '' || $workerOutput !== '') {
            return $this->runWorker(
                $workerManifest,
                $workerOutput,
                $featureExtractionService
            );
        }

        $files = $this->collectVideoFiles($rootPath);
        $freshIndex = $referenceIndexService->loadFreshSnapshots($rootPath);
        $freshByPathHash = (array) ($freshIndex['snapshots_by_path_hash'] ?? []);
        $freshSnapshots = (array) ($freshIndex['snapshots'] ?? []);
        $filesToExtract = array_values(array_filter(
            $files,
            fn (string $filePath): bool => !isset($freshByPathHash[$referenceIndexService->hashPath($filePath)])
        ));

        $this->line(sprintf(
            '快速特徵預建：files=%d cached=%d extract=%d',
            count($files),
            count($freshSnapshots),
            count($filesToExtract)
        ));

        if ($filesToExtract === []) {
            $this->info('特徵快取已是最新，無需執行 FFmpeg。');
            return self::SUCCESS;
        }

        $workerCount = max(1, min(16, (int) $this->option('workers')));
        $workerCount = min($workerCount, count($filesToExtract));
        $jobDir = storage_path('app/video_features/index_jobs/' . uniqid('job_', true));
        File::ensureDirectoryExists($jobDir);
        $partitions = array_fill(0, $workerCount, []);

        foreach ($filesToExtract as $index => $filePath) {
            $partitions[$index % $workerCount][] = $filePath;
        }

        $jobs = [];

        try {
            foreach ($partitions as $workerIndex => $partition) {
                $manifestPath = $jobDir . DIRECTORY_SEPARATOR . sprintf('worker_%02d_manifest.json', $workerIndex + 1);
                $outputPath = $jobDir . DIRECTORY_SEPARATOR . sprintf('worker_%02d_output.json', $workerIndex + 1);
                File::put(
                    $manifestPath,
                    json_encode($partition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );

                $process = new Process([
                    PHP_BINARY,
                    base_path('artisan'),
                    'video:build-feature-index-fast',
                    $rootPath,
                    '--worker-manifest=' . $manifestPath,
                    '--worker-output=' . $outputPath,
                    '--no-interaction',
                ]);
                $process->setTimeout(7200);
                $process->start();
                $jobs[] = [
                    'process' => $process,
                    'output_path' => $outputPath,
                    'worker' => $workerIndex + 1,
                ];
            }

            $this->line(sprintf('已啟動 %d 個平行 FFmpeg workers。', count($jobs)));
            $extractedPayloads = [];
            $failedFiles = [];

            foreach ($jobs as $job) {
                /** @var Process $process */
                $process = $job['process'];
                $exitCode = $process->wait();
                $outputPath = (string) $job['output_path'];

                if ($exitCode !== 0 || !is_file($outputPath)) {
                    $failedFiles[] = [
                        'absolute_path' => '(worker ' . $job['worker'] . ')',
                        'message' => trim($process->getErrorOutput() . PHP_EOL . $process->getOutput()),
                    ];
                    continue;
                }

                $result = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
                $extractedPayloads = array_merge($extractedPayloads, (array) ($result['payloads'] ?? []));
                $failedFiles = array_merge($failedFiles, (array) ($result['failed_files'] ?? []));
                $this->line(sprintf(
                    '  worker %d 完成：extracted=%d failed=%d',
                    $job['worker'],
                    count((array) ($result['payloads'] ?? [])),
                    count((array) ($result['failed_files'] ?? []))
                ));
            }

            $index = $referenceIndexService->replacePayloadSnapshots(
                $rootPath,
                array_merge($freshSnapshots, $extractedPayloads),
                $failedFiles
            );

            $this->info(sprintf(
                '快速特徵快取完成：total=%d reused=%d extracted=%d failed=%d',
                (int) ($index['total_files'] ?? 0),
                count($freshSnapshots),
                count($extractedPayloads),
                count($failedFiles)
            ));

            foreach ($failedFiles as $failedFile) {
                $this->warn(sprintf(
                    '  失敗：%s -> %s',
                    (string) ($failedFile['absolute_path'] ?? ''),
                    (string) ($failedFile['message'] ?? '')
                ));
            }

            return $failedFiles === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            foreach ($jobs as $job) {
                $process = $job['process'] ?? null;
                if ($process instanceof Process && $process->isRunning()) {
                    $process->stop(1);
                }
            }

            $this->error('快速特徵快取失敗：' . $e->getMessage());
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
            $files = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($files)) {
                return self::FAILURE;
            }

            $payloads = [];
            $failedFiles = [];

            foreach ($files as $filePath) {
                $payload = null;

                try {
                    $payload = $featureExtractionService->inspectFile((string) $filePath);
                    $payloads[] = $payload;
                } catch (Throwable $e) {
                    $failedFiles[] = [
                        'absolute_path' => (string) $filePath,
                        'message' => $e->getMessage(),
                    ];
                } finally {
                    if (is_array($payload)) {
                        $featureExtractionService->cleanupPayload($payload);
                    }
                }
            }

            File::put(
                $outputPath,
                json_encode([
                    'payloads' => $payloads,
                    'failed_files' => $failedFiles,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            return self::SUCCESS;
        } catch (Throwable) {
            return self::FAILURE;
        }
    }

    private function collectVideoFiles(string $rootPath): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }

            $filePath = $this->normalizeAbsolutePath($fileInfo->getPathname());
            $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
            if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                $files[] = $filePath;
            }
        }

        usort($files, fn (string $left, string $right): int => strcmp(
            mb_strtolower($left),
            mb_strtolower($right)
        ));

        return $files;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $realPath = @realpath($path);

        return is_string($realPath) && $realPath !== '' ? $realPath : $path;
    }
}
