<?php

namespace App\Console\Commands;

use App\Models\FolderVideoDuplicateBatch;
use App\Models\FolderVideoDuplicateFeature;
use App\Services\FolderVideoDuplicateService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;

class MoveFolderDuplicateVideosCommand extends Command
{
    /**
     * 範例:
     * php artisan video:move-folder-duplicates "C:\Users\User\Pictures\train\downloads\group_3406828124_xsmyyds会员群\videos\tmp"
     * video:move-folder-duplicates
 */
    protected $signature = 'video:move-folder-duplicates
        {path : 必填資料夾路徑}
        {--recursive=1 : 1=掃子資料夾，0=只掃一層}
        {--threshold=80 : dHash 相似度門檻}
        {--min-match=2 : 至少幾張截圖達標}
        {--window-seconds=3 : 時長容許秒數}
        {--max-candidates=250 : 每支影片最多拉多少同批候選}
        {--limit=0 : 最多處理幾支影片，0=不限制}
        {--cleanup-db=1 : 1=掃描完刪除這批暫存資料，0=保留}
        {--dry-run : 只顯示結果不搬移}';

    protected $description = '只比對指定資料夾內彼此重複的影片；先掃到的保留，後掃到的重複檔搬到「疑似重複檔案」資料夾，掃描暫存資料預設在批次完成後清除。';

    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'm4v', 'mpeg', 'mpg'];

    public function handle(
        VideoFeatureExtractionService $featureExtractionService,
        FolderVideoDuplicateService $folderVideoDuplicateService
    ): int {
        $path = $this->normalizeAbsolutePath((string) $this->argument('path'));
        if ($path === '' || !is_dir($path)) {
            $this->error('path 不是有效資料夾：' . $this->argument('path'));
            return self::FAILURE;
        }

        $recursive = (int) $this->option('recursive') === 1;
        $threshold = max(1, min(100, (int) $this->option('threshold')));
        $minMatch = max(1, min(4, (int) $this->option('min-match')));
        $windowSeconds = max(0, (int) $this->option('window-seconds'));
        $maxCandidates = max(1, (int) $this->option('max-candidates'));
        $limit = max(0, (int) $this->option('limit'));
        $cleanupDb = (int) $this->option('cleanup-db') === 1;
        $dryRun = (bool) $this->option('dry-run');

        $duplicateDir = $path . DIRECTORY_SEPARATOR . '疑似重複檔案';
        $files = $this->collectVideoFiles($path, $recursive, $duplicateDir);
        if ($limit > 0) {
            $files = array_slice($files, 0, $limit);
        }

        if ($files === []) {
            $this->warn('找不到影片檔。');
            return self::SUCCESS;
        }

        $batch = $folderVideoDuplicateService->createBatch([
            'scan_root_path' => $path,
            'duplicate_directory_path' => $duplicateDir,
            'is_recursive' => $recursive,
            'threshold_percent' => $threshold,
            'min_match_required' => $minMatch,
            'window_seconds' => $windowSeconds,
            'max_candidates' => $maxCandidates,
            'limit_count' => $limit > 0 ? $limit : null,
            'is_dry_run' => $dryRun,
            'cleanup_requested' => $cleanupDb,
            'status' => 'running',
            'total_files' => count($files),
            'processed_files' => 0,
            'kept_files' => 0,
            'moved_files' => 0,
            'failed_files' => 0,
            'started_at' => now(),
        ]);

        $processed = 0;
        $kept = 0;
        $moved = 0;
        $failed = 0;

        $this->info('Batch #' . $batch->id . ' Root=' . $path);
        $this->line(sprintf(
            'threshold=%d min-match=%d window=%d max-candidates=%d limit=%d cleanup-db=%d dry-run=%d',
            $threshold,
            $minMatch,
            $windowSeconds,
            $maxCandidates,
            $limit,
            $cleanupDb ? 1 : 0,
            $dryRun ? 1 : 0
        ));

        try {
            foreach ($files as $filePath) {
                $this->line($filePath);

                $payload = null;

                try {
                    if (!is_file($filePath)) {
                        throw new \RuntimeException('檔案已不存在或不是有效影片。');
                    }

                    $payload = $featureExtractionService->inspectFile($filePath);
                    $analysis = $folderVideoDuplicateService->analyzeBatchMatch(
                        $batch,
                        $payload,
                        $threshold,
                        $minMatch,
                        $windowSeconds,
                        $maxCandidates
                    );

                    $processed++;
                    $match = $analysis['duplicate_match'] ?? null;

                    if (!is_array($match)) {
                        $folderVideoDuplicateService->persistCanonicalFeature($batch, $payload);
                        $kept++;
                        $this->line('  無重複，保留原位');
                        $this->syncBatchCounters($batch, $processed, $kept, $moved, $failed);
                        continue;
                    }

                    /** @var FolderVideoDuplicateFeature $keptFeature */
                    $keptFeature = $match['feature'];
                    $destinationPath = $this->buildUniqueDestination($duplicateDir, basename($filePath));

                    $this->warn(sprintf(
                        '  命中同批重複，相似度=%s%%，matched=%d/%d，保留=%s',
                        number_format((float) $match['similarity_percent'], 2),
                        (int) $match['matched_frames'],
                        (int) $match['compared_frames'],
                        (string) $keptFeature->absolute_path
                    ));

                    if (!$dryRun) {
                        File::ensureDirectoryExists($duplicateDir);
                        if (!@rename($filePath, $destinationPath)) {
                            throw new \RuntimeException('搬移檔案失敗：' . $destinationPath);
                        }
                    }

                    $folderVideoDuplicateService->persistDuplicateMatch(
                        $batch,
                        $keptFeature,
                        $payload,
                        $match,
                        [
                            'moved_to_path' => $dryRun ? null : $destinationPath,
                            'operation_status' => $dryRun ? 'dry_run_match' : 'match_moved',
                            'operation_message' => $dryRun
                                ? 'dry-run 模式，未搬移檔案。'
                                : '命中同資料夾重複，已搬移到疑似重複檔案資料夾。',
                        ]
                    );

                    if ($dryRun) {
                        $this->line('  dry-run 模式，未搬移');
                    } else {
                        $this->info('  已搬移到：' . $destinationPath);
                        $moved++;
                    }

                    $this->syncBatchCounters($batch, $processed, $kept, $moved, $failed);
                } catch (Throwable $e) {
                    $processed++;
                    $failed++;
                    $this->error('  失敗：' . $e->getMessage());
                    $this->syncBatchCounters($batch, $processed, $kept, $moved, $failed, $e->getMessage());
                } finally {
                    if (is_array($payload)) {
                        $featureExtractionService->cleanupPayload($payload);
                    }
                }
            }

            $batch->forceFill([
                'status' => $failed > 0 ? 'completed_with_errors' : 'completed',
                'finished_at' => now(),
                'last_error' => $failed > 0 ? $batch->last_error : null,
            ])->save();
        } finally {
            $this->newLine();
            $this->info(sprintf(
                '完成，processed=%d kept=%d moved=%d failed=%d',
                $processed,
                $kept,
                $moved,
                $failed
            ));

            if ($cleanupDb) {
                $folderVideoDuplicateService->cleanupBatch($batch);
                $this->line('已清除本批次暫存資料。');
            } else {
                $this->line('保留本批次暫存資料，batch_id=' . $batch->id);
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function syncBatchCounters(
        FolderVideoDuplicateBatch $batch,
        int $processed,
        int $kept,
        int $moved,
        int $failed,
        ?string $lastError = null
    ): void {
        $batch->forceFill([
            'processed_files' => $processed,
            'kept_files' => $kept,
            'moved_files' => $moved,
            'failed_files' => $failed,
            'last_error' => $lastError,
        ])->save();
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
                if ($duplicateDirLower !== '' && str_starts_with(mb_strtolower($path), $duplicateDirLower)) {
                    continue;
                }

                if ($this->isVideoFile($path)) {
                    $result[] = $path;
                }
            }
        } else {
            foreach (File::files($rootPath) as $fileInfo) {
                $path = $this->normalizeAbsolutePath($fileInfo->getPathname());
                if ($this->isVideoFile($path)) {
                    $result[] = $path;
                }
            }
        }

        usort($result, function (string $left, string $right): int {
            $leftMtime = (int) @filemtime($left);
            $rightMtime = (int) @filemtime($right);

            if ($leftMtime !== $rightMtime) {
                return $leftMtime <=> $rightMtime;
            }

            return strcmp(mb_strtolower($left), mb_strtolower($right));
        });

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
            $candidate = $duplicateDir . DIRECTORY_SEPARATOR . $name . '_' . $counter;
            if ($extension !== '') {
                $candidate .= '.' . $extension;
            }
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
