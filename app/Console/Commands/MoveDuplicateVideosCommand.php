<?php

namespace App\Console\Commands;

use App\Services\VideoDuplicateDetectionService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;

class MoveDuplicateVideosCommand extends Command
{
    /**
     * 範例:
     * php artisan video:move-duplicates "D:\incoming"
     * php artisan video:move-duplicates "D:\incoming" --recursive=0 --threshold=92
     */
    protected $signature = 'video:move-duplicates
        {path : 必填資料夾路徑}
        {--recursive=1 : 1=掃子資料夾，0=只掃一層}
        {--threshold=90 : dHash 相似度門檻}
        {--min-match=2 : 至少幾張截圖達標}
        {--window-seconds=3 : 時長容許秒數}
        {--size-percent=15 : 檔案大小容許百分比}
        {--max-candidates=250 : 每支影片最多拉多少 DB 候選}
        {--dry-run : 只顯示結果不搬移}';

    protected $description = '掃描資料夾內影片，若特徵已存在於 DB，搬到「疑似重複檔案」資料夾；不會寫入任何新特徵資料。';

    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'm4v', 'mpeg', 'mpg'];

    public function handle(
        VideoFeatureExtractionService $featureExtractionService,
        VideoDuplicateDetectionService $duplicateDetectionService
    ): int {
        $rootPath = $this->normalizeAbsolutePath((string) $this->argument('path'));
        if ($rootPath === '' || !is_dir($rootPath)) {
            $this->error('path 不是有效資料夾：' . $this->argument('path'));
            return self::FAILURE;
        }

        $recursive = (int) $this->option('recursive') === 1;
        $threshold = max(1, min(100, (int) $this->option('threshold')));
        $minMatch = max(1, min(4, (int) $this->option('min-match')));
        $windowSeconds = max(0, (int) $this->option('window-seconds'));
        $sizePercent = max(0, min(90, (int) $this->option('size-percent')));
        $maxCandidates = max(1, (int) $this->option('max-candidates'));
        $dryRun = (bool) $this->option('dry-run');

        $duplicateDir = $rootPath . DIRECTORY_SEPARATOR . '疑似重複檔案';
        $files = $this->collectVideoFiles($rootPath, $recursive, $duplicateDir);

        if ($files === []) {
            $this->warn('找不到影片檔。');
            return self::SUCCESS;
        }

        $moved = 0;
        $kept = 0;
        $failed = 0;

        foreach ($files as $filePath) {
            $this->line($filePath);

            $payload = null;

            try {
                $payload = $featureExtractionService->inspectFile($filePath);

                $match = $duplicateDetectionService->findBestDatabaseMatch(
                    $payload,
                    $threshold,
                    $minMatch,
                    $windowSeconds,
                    $sizePercent,
                    $maxCandidates
                );

                if ($match === null) {
                    $kept++;
                    $this->line('  無重複，保留原位');
                    continue;
                }

                $feature = $match['feature'];
                $storedVideoPath = '';
                if ($feature->videoMaster !== null) {
                    try {
                        $storedVideoPath = $this->normalizeAbsolutePath(
                            $featureExtractionService->resolveAbsoluteVideoPath($feature->videoMaster)
                        );
                    } catch (Throwable) {
                        $storedVideoPath = '';
                    }
                }

                if ($storedVideoPath !== '' && mb_strtolower($storedVideoPath) === mb_strtolower($filePath)) {
                    $kept++;
                    $this->line('  與 DB 原檔為同一路徑，跳過');
                    continue;
                }

                $destinationPath = $this->buildUniqueDestination($duplicateDir, basename($filePath));

                $this->warn(sprintf(
                    '  命中 DB 影片 ID=%d，相似度=%s%%，matched=%d/%d',
                    (int) $feature->video_master_id,
                    number_format((float) $match['similarity_percent'], 2),
                    (int) $match['matched_frames'],
                    (int) $match['compared_frames']
                ));

                if ($dryRun) {
                    continue;
                }

                File::ensureDirectoryExists($duplicateDir);
                if (!@rename($filePath, $destinationPath)) {
                    throw new \RuntimeException('搬移檔案失敗：' . $destinationPath);
                }

                $moved++;
                $this->info('  已搬移到：' . $destinationPath);
            } catch (Throwable $e) {
                $failed++;
                $this->error('  失敗：' . $e->getMessage());
            } finally {
                if (is_array($payload)) {
                    $featureExtractionService->cleanupPayload($payload);
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('完成，moved=%d kept=%d failed=%d', $moved, $kept, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function collectVideoFiles(string $rootPath, bool $recursive, string $duplicateDir): array
    {
        $result = [];
        $duplicateDirLower = mb_strtolower($this->normalizeAbsolutePath($duplicateDir));

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
                if (str_starts_with(mb_strtolower($path), $duplicateDirLower)) {
                    continue;
                }

                if ($this->isVideoFile($path)) {
                    $result[] = $path;
                }
            }

            return $result;
        }

        foreach (File::files($rootPath) as $fileInfo) {
            $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
            if ($this->isVideoFile($path)) {
                $result[] = $path;
            }
        }

        return $result;
    }

    private function isVideoFile(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::VIDEO_EXTENSIONS, true);
    }

    private function buildUniqueDestination(string $duplicateDir, string $basename): string
    {
        $name = (string) pathinfo($basename, PATHINFO_FILENAME);
        $extension = (string) pathinfo($basename, PATHINFO_EXTENSION);
        $candidate = $duplicateDir . DIRECTORY_SEPARATOR . $basename;
        $counter = 1;

        while (File::exists($candidate)) {
            $suffix = '_' . $counter;
            $candidate = $duplicateDir . DIRECTORY_SEPARATOR . $name . $suffix . ($extension !== '' ? '.' . $extension : '');
            $counter++;
        }

        return $candidate;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        $real = @realpath($path);
        if (is_string($real) && $real !== '') {
            return $real;
        }

        return $path;
    }
}
