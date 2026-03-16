<?php

namespace App\Console\Commands;

use App\Models\VideoFeature;
use App\Services\ExternalVideoDuplicateService;
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
     * php artisan video:move-duplicates "C:\Users\User\Pictures\train\downloads\group_2755698006_小荷才露尖尖角\videos\tmp"
     * php artisan video:move-duplicates "C:\incoming\a.mp4" --video-feature-id=123
     * php artisan video:move-duplicates "D:\incoming" --recursive=0 --threshold=92
     */
    protected $signature = 'video:move-duplicates
        {path : 必填資料夾路徑；搭配 --video-feature-id 時也可直接給單一影片檔}
        {--recursive=1 : 1=掃子資料夾，0=只掃一層}
        {--threshold=90 : dHash 相似度門檻}
        {--min-match=2 : 至少幾張截圖達標}
        {--window-seconds=3 : 時長容許秒數}
        {--size-percent=15 : 檔案大小容許百分比}
        {--max-candidates=250 : 每支影片最多拉多少 DB 候選}
        {--video-feature-id= : 指定單一 video_features.id 進入手動 debug 模式}
        {--write-log : 手動 debug 模式也把分析寫入 external_video_duplicate_logs}
        {--dry-run : 只顯示結果不搬移}';

    protected $description = '掃描資料夾內影片，若特徵已存在於 DB，搬到「疑似重複檔案」資料夾並寫入外部重複檢視資料；若指定 --video-feature-id，則改為手動分析指定 feature。';

    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'm4v', 'mpeg', 'mpg'];

    public function handle(
        VideoFeatureExtractionService $featureExtractionService,
        VideoDuplicateDetectionService $duplicateDetectionService,
        ExternalVideoDuplicateService $externalVideoDuplicateService
    ): int {
        $path = $this->normalizeAbsolutePath((string) $this->argument('path'));
        try {
            $manualFeatureId = $this->resolveManualFeatureId($this->option('video-feature-id'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $recursive = (int) $this->option('recursive') === 1;
        $threshold = max(1, min(100, (int) $this->option('threshold')));
        $minMatch = max(1, min(4, (int) $this->option('min-match')));
        $windowSeconds = max(0, (int) $this->option('window-seconds'));
        $sizePercent = max(0, min(90, (int) $this->option('size-percent')));
        $maxCandidates = max(1, (int) $this->option('max-candidates'));
        $dryRun = (bool) $this->option('dry-run');
        $writeLog = (bool) $this->option('write-log');

        if ($manualFeatureId !== null) {
            return $this->handleManualFeatureMode(
                $path,
                $manualFeatureId,
                $recursive,
                $threshold,
                $minMatch,
                $windowSeconds,
                $sizePercent,
                $writeLog,
                $featureExtractionService,
                $duplicateDetectionService,
                $externalVideoDuplicateService
            );
        }

        if ($path === '' || !is_dir($path)) {
            $this->error('path 不是有效資料夾：' . $this->argument('path'));
            return self::FAILURE;
        }

        $duplicateDir = $path . DIRECTORY_SEPARATOR . '疑似重複檔案';
        $files = $this->collectVideoFiles($path, $recursive, $duplicateDir);

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
            $analysis = null;
            $destinationPath = null;
            $movedToDuplicateDir = false;
            $comparisonLogged = false;

            try {
                $payload = $featureExtractionService->inspectFile($filePath);
                $analysis = $duplicateDetectionService->analyzeDatabaseMatch(
                    $payload,
                    $threshold,
                    $minMatch,
                    $windowSeconds,
                    $sizePercent,
                    $maxCandidates
                );
                $match = $analysis['duplicate_match'] ?? null;
                $baseLogOptions = [
                    'scan_root_path' => $path,
                    'threshold_percent' => $threshold,
                    'min_match_required' => $minMatch,
                    'window_seconds' => $windowSeconds,
                    'size_percent' => $sizePercent,
                    'max_candidates' => $maxCandidates,
                ];

                if (!is_array($match)) {
                    $externalVideoDuplicateService->persistComparisonLog(
                        $payload,
                        $analysis,
                        $filePath,
                        $baseLogOptions + [
                            'is_duplicate_detected' => false,
                            'operation_status' => 'no_match',
                            'operation_message' => '未命中重複門檻，保留原位。',
                        ]
                    );
                    $comparisonLogged = true;
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
                    $externalVideoDuplicateService->persistComparisonLog(
                        $payload,
                        $analysis,
                        $filePath,
                        $baseLogOptions + [
                            'is_duplicate_detected' => true,
                            'operation_status' => 'same_path_skipped',
                            'operation_message' => '與 DB 原檔為同一路徑，未搬移。',
                        ]
                    );
                    $comparisonLogged = true;
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
                    $externalVideoDuplicateService->persistComparisonLog(
                        $payload,
                        $analysis,
                        $filePath,
                        $baseLogOptions + [
                            'is_duplicate_detected' => true,
                            'operation_status' => 'dry_run_match',
                            'operation_message' => 'dry-run 模式，未搬移檔案。',
                        ]
                    );
                    $comparisonLogged = true;
                    continue;
                }

                File::ensureDirectoryExists($duplicateDir);
                if (!@rename($filePath, $destinationPath)) {
                    throw new \RuntimeException('搬移檔案失敗：' . $destinationPath);
                }

                $movedToDuplicateDir = true;

                $matchRecord = $externalVideoDuplicateService->persistMatchResult(
                    $payload,
                    $match,
                    $filePath,
                    $destinationPath,
                    $baseLogOptions + [
                        'duplicate_directory_path' => $duplicateDir,
                    ]
                );
                $externalVideoDuplicateService->persistComparisonLog(
                    $payload,
                    $analysis,
                    $filePath,
                    $baseLogOptions + [
                        'external_video_duplicate_match_id' => $matchRecord->id,
                        'duplicate_file_path' => $destinationPath,
                        'is_duplicate_detected' => true,
                        'operation_status' => 'match_moved',
                        'operation_message' => '命中重複並已搬移到疑似重複檔案資料夾。',
                    ]
                );
                $comparisonLogged = true;

                $moved++;
                $this->info('  已搬移到：' . $destinationPath);
            } catch (Throwable $e) {
                if (
                    $movedToDuplicateDir &&
                    is_string($destinationPath) &&
                    $destinationPath !== '' &&
                    File::exists($destinationPath) &&
                    !File::exists($filePath)
                ) {
                    @rename($destinationPath, $filePath);
                }

                if (!$comparisonLogged && is_array($payload)) {
                    try {
                        $externalVideoDuplicateService->persistComparisonLog(
                            $payload,
                            is_array($analysis) ? $analysis : null,
                            $filePath,
                            [
                                'scan_root_path' => $path,
                                'duplicate_file_path' => $movedToDuplicateDir && is_string($destinationPath) ? $destinationPath : null,
                                'threshold_percent' => $threshold,
                                'min_match_required' => $minMatch,
                                'window_seconds' => $windowSeconds,
                                'size_percent' => $sizePercent,
                                'max_candidates' => $maxCandidates,
                                'is_duplicate_detected' => is_array($analysis) && is_array($analysis['duplicate_match'] ?? null),
                                'operation_status' => 'error',
                                'operation_message' => $e->getMessage(),
                            ]
                        );
                    } catch (Throwable) {
                    }
                }

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

    private function handleManualFeatureMode(
        string $path,
        int $manualFeatureId,
        bool $recursive,
        int $threshold,
        int $minMatch,
        int $windowSeconds,
        int $sizePercent,
        bool $writeLog,
        VideoFeatureExtractionService $featureExtractionService,
        VideoDuplicateDetectionService $duplicateDetectionService,
        ExternalVideoDuplicateService $externalVideoDuplicateService
    ): int {
        if ($path === '' || (!is_file($path) && !is_dir($path))) {
            $this->error('path 必須是有效的資料夾或影片檔：' . $this->argument('path'));
            return self::FAILURE;
        }

        $feature = VideoFeature::query()
            ->with(['frames', 'videoMaster'])
            ->find($manualFeatureId);

        if (!$feature instanceof VideoFeature) {
            $this->error('找不到指定的 video_features.id：' . $manualFeatureId);
            return self::FAILURE;
        }

        $files = is_file($path)
            ? [$path]
            : $this->collectVideoFiles($path, $recursive, $this->normalizeAbsolutePath($path . DIRECTORY_SEPARATOR . '疑似重複檔案'));

        if ($files === []) {
            $this->warn('找不到影片檔。');
            return self::SUCCESS;
        }

        $processed = 0;
        $failed = 0;

        foreach ($files as $filePath) {
            $this->newLine();
            $this->line(str_repeat('=', 80));
            $this->line($filePath);

            $payload = null;

            try {
                $payload = $featureExtractionService->inspectFile($filePath);
                $analysis = $duplicateDetectionService->analyzeSpecificFeatureMatch(
                    $payload,
                    $feature,
                    $threshold,
                    $minMatch,
                    $windowSeconds,
                    $sizePercent
                );

                $this->renderOverview($filePath, $feature, $analysis, $threshold, $minMatch, $windowSeconds, $sizePercent);
                $this->renderCandidateGate($analysis['candidate_gate'] ?? []);
                $this->renderFrameComparisons($analysis['compare_result'] ?? null);
                $this->renderConclusion($analysis);

                if ($writeLog) {
                    $log = $externalVideoDuplicateService->persistComparisonLog(
                        $payload,
                        $this->buildManualLogAnalysis($analysis, $feature, $minMatch),
                        $filePath,
                        [
                            'scan_root_path' => is_dir($path) ? $path : dirname($filePath),
                            'threshold_percent' => $threshold,
                            'min_match_required' => $minMatch,
                            'window_seconds' => $windowSeconds,
                            'size_percent' => $sizePercent,
                            'max_candidates' => 1,
                            'is_duplicate_detected' => is_array($analysis['duplicate_match'] ?? null),
                            'operation_status' => 'manual_feature_debug',
                            'operation_message' => $this->buildConclusionMessage($analysis),
                        ]
                    );

                    $this->info('已寫入 debug log，ID=' . $log->id);
                }

                $processed++;
            } catch (Throwable $e) {
                $failed++;
                $this->error('分析失敗：' . $e->getMessage());
            } finally {
                if (is_array($payload)) {
                    $featureExtractionService->cleanupPayload($payload);
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('手動分析完成，processed=%d failed=%d', $processed, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function renderOverview(
        string $filePath,
        VideoFeature $feature,
        array $analysis,
        int $threshold,
        int $minMatch,
        int $windowSeconds,
        int $sizePercent
    ): void {
        $this->info('來源影片：' . $filePath);
        $this->line('指定 feature：#' . $feature->id);
        $this->line('DB video_master：' . ($feature->video_master_id !== null ? '#' . $feature->video_master_id : '-'));
        $this->line('DB 影片名稱：' . ((string) ($feature->video_name ?? '-')));
        $this->line(sprintf(
            '分析參數：threshold=%d min-match=%d window=%d size=%d%%',
            $threshold,
            $minMatch,
            $windowSeconds,
            $sizePercent
        ));

        $this->newLine();
        $this->table(
            ['項目', '值'],
            [
                ['來源 frame 數', (string) ($analysis['payload_frame_count'] ?? 0)],
                ['DB feature frame 數', (string) $feature->frames->count()],
                ['DB capture_rule', (string) ($feature->capture_rule ?? '-')],
                ['DB duration_seconds', $feature->duration_seconds !== null ? (string) $feature->duration_seconds : '-'],
                ['DB file_size_bytes', $feature->file_size_bytes !== null ? (string) $feature->file_size_bytes : '-'],
            ]
        );
    }

    private function renderCandidateGate(array $candidateGate): void
    {
        $this->newLine();
        $this->info('正式流程 Candidate Gate');

        $rows = [
            ['shared dHash prefix', empty($candidateGate['shared_prefixes']) ? '-' : implode(', ', $candidateGate['shared_prefixes'])],
            ['duration window', sprintf(
                '%s (%s ~ %s)',
                !empty($candidateGate['duration_within_window']) ? 'PASS' : 'BLOCK',
                isset($candidateGate['duration_window_min']) ? (string) $candidateGate['duration_window_min'] : '-',
                isset($candidateGate['duration_window_max']) ? (string) $candidateGate['duration_window_max'] : '-'
            )],
            ['duration delta', isset($candidateGate['duration_delta_seconds']) && $candidateGate['duration_delta_seconds'] !== null
                ? (string) $candidateGate['duration_delta_seconds'] . ' sec'
                : '-'],
            ['size filter', !empty($candidateGate['size_filter_applied']) ? 'ON' : 'OFF'],
            ['size window', !empty($candidateGate['size_filter_applied'])
                ? sprintf(
                    '%s (%s ~ %s)',
                    !empty($candidateGate['size_within_window']) ? 'PASS' : 'BLOCK',
                    isset($candidateGate['size_window_min']) ? number_format((int) $candidateGate['size_window_min']) : '-',
                    isset($candidateGate['size_window_max']) ? number_format((int) $candidateGate['size_window_max']) : '-'
                )
                : 'SKIP'],
            ['size delta', isset($candidateGate['file_size_delta_bytes']) && $candidateGate['file_size_delta_bytes'] !== null
                ? number_format((int) $candidateGate['file_size_delta_bytes']) . ' bytes'
                : '-'],
            ['會進正式候選池', !empty($candidateGate['eligible']) ? 'YES' : 'NO'],
        ];

        $this->table(['條件', '結果'], $rows);

        if (!empty($candidateGate['reasons']) && is_array($candidateGate['reasons'])) {
            foreach ($candidateGate['reasons'] as $reason) {
                $this->warn('BLOCK 原因：' . $reason);
            }
        }
    }

    private function renderFrameComparisons(?array $compareResult): void
    {
        $this->newLine();
        $this->info('逐張 Frame 比對');

        if (!is_array($compareResult)) {
            $this->warn('沒有任何可比較的 frame。通常是 capture_order 對不上，或雙方 dHash 無效。');
            return;
        }

        $rows = [];
        foreach ((array) ($compareResult['frame_matches'] ?? []) as $frameMatch) {
            $rows[] = [
                '#' . (int) ($frameMatch['capture_order'] ?? 0),
                isset($frameMatch['capture_second']) ? number_format((float) $frameMatch['capture_second'], 3) . 's' : '-',
                isset($frameMatch['matched_capture_second']) ? number_format((float) $frameMatch['matched_capture_second'], 3) . 's' : '-',
                (string) ($frameMatch['payload_dhash_hex'] ?? '-'),
                (string) ($frameMatch['matched_dhash_hex'] ?? '-'),
                isset($frameMatch['similarity_percent']) ? (string) $frameMatch['similarity_percent'] . '%' : '-',
                !empty($frameMatch['is_threshold_match']) ? 'PASS' : 'FAIL',
            ];
        }

        $this->table(
            ['frame', 'source sec', 'target sec', 'source dhash', 'target dhash', 'similarity', 'threshold'],
            $rows
        );

        $this->table(
            ['摘要', '值'],
            [
                ['overall similarity', isset($compareResult['similarity_percent']) ? (string) $compareResult['similarity_percent'] . '%' : '-'],
                ['matched / compared', sprintf('%d / %d', (int) ($compareResult['matched_frames'] ?? 0), (int) ($compareResult['compared_frames'] ?? 0))],
                ['required matches', (string) ($compareResult['required_matches'] ?? '-')],
                ['passes threshold', !empty($compareResult['passes_threshold']) ? 'YES' : 'NO'],
                ['duration delta', isset($compareResult['duration_delta_seconds']) && $compareResult['duration_delta_seconds'] !== null
                    ? (string) $compareResult['duration_delta_seconds'] . ' sec'
                    : '-'],
                ['file size delta', isset($compareResult['file_size_delta_bytes']) && $compareResult['file_size_delta_bytes'] !== null
                    ? number_format((int) $compareResult['file_size_delta_bytes']) . ' bytes'
                    : '-'],
            ]
        );
    }

    private function renderConclusion(array $analysis): void
    {
        $this->newLine();
        $message = $this->buildConclusionMessage($analysis);

        if (is_array($analysis['duplicate_match'] ?? null)) {
            $this->info($message);
            return;
        }

        $this->warn($message);
    }

    private function buildConclusionMessage(array $analysis): string
    {
        $candidateGate = is_array($analysis['candidate_gate'] ?? null) ? $analysis['candidate_gate'] : [];
        $compareResult = is_array($analysis['compare_result'] ?? null) ? $analysis['compare_result'] : null;

        if (!$compareResult) {
            return '結論：這次強制比對沒有任何可比較的 frame，正式流程自然不會命中。';
        }

        if (!empty($candidateGate['eligible']) && !empty($compareResult['passes_threshold'])) {
            return '結論：正式流程候選條件可通過，且強制比對也達門檻；如果沒抓到，問題應該不在 compare 規則本身。';
        }

        if (empty($candidateGate['eligible']) && !empty($compareResult['passes_threshold'])) {
            return '結論：強制比對其實達門檻，但正式流程在 candidate gate 就被擋掉了。';
        }

        if (!empty($candidateGate['eligible']) && empty($compareResult['passes_threshold'])) {
            return sprintf(
                '結論：正式流程會拿它來比，但 matched=%d / required=%d，沒有達到門檻。',
                (int) ($compareResult['matched_frames'] ?? 0),
                (int) ($compareResult['required_matches'] ?? 0)
            );
        }

        return '結論：正式流程前置條件沒過，而且強制比對本身也沒達門檻。';
    }

    private function buildManualLogAnalysis(array $analysis, VideoFeature $feature, int $minMatch): array
    {
        $compareResult = $analysis['compare_result'] ?? null;
        if (is_array($compareResult)) {
            return [
                'best_result' => $compareResult,
                'duplicate_match' => $analysis['duplicate_match'] ?? null,
                'candidate_count' => !empty($analysis['candidate_gate']['eligible']) ? 1 : 0,
                'requested_min_match' => $analysis['requested_min_match'] ?? max(1, $minMatch),
            ];
        }

        return [
            'best_result' => [
                'feature' => $feature,
                'similarity_percent' => 0,
                'matched_frames' => 0,
                'compared_frames' => 0,
                'required_matches' => max(1, $minMatch),
                'passes_threshold' => false,
                'frame_matches' => [],
                'duration_delta_seconds' => $analysis['candidate_gate']['duration_delta_seconds'] ?? null,
                'file_size_delta_bytes' => $analysis['candidate_gate']['file_size_delta_bytes'] ?? null,
            ],
            'duplicate_match' => null,
            'candidate_count' => !empty($analysis['candidate_gate']['eligible']) ? 1 : 0,
            'requested_min_match' => $analysis['requested_min_match'] ?? max(1, $minMatch),
        ];
    }

    private function resolveManualFeatureId(mixed $option): ?int
    {
        if ($option === null || $option === '') {
            return null;
        }

        if (!is_numeric($option) || (int) $option <= 0) {
            throw new \InvalidArgumentException('--video-feature-id 必須是正整數。');
        }

        return (int) $option;
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
