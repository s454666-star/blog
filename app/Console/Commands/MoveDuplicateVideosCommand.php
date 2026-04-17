<?php

namespace App\Console\Commands;

use App\Models\VideoFeature;
use App\Services\ExternalVideoDuplicateService;
use App\Services\ReferenceVideoFeatureIndexService;
use App\Services\VideoDuplicateDetectionService;
use App\Services\VideoFeatureExtractionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Throwable;

class MoveDuplicateVideosCommand extends Command
{
    private const LOG_CHANNEL = 'video_duplicate_scan';

    /**
     * 範例:
     * php artisan video:move-duplicates "C:\Users\User\Pictures\train\downloads\group_2755698006_小荷才露尖尖角\videos\tmp" --video-feature-id=3323
     * php artisan video:move-duplicates "C:\Users\User\Videos\暫"
     * php artisan video:move-duplicates "C:\Users\User\Downloads\Video"
     * php artisan video:move-duplicates "C:\Users\User\Downloads\Telegram Desktop"
     * php artisan video:move-duplicates "C:\incoming\a.mp4" --video-feature-id=123
     * php artisan video:move-duplicates "C:\incoming\a.mp4" --video-feature-id=123 --skip-log
     * php artisan video:move-duplicates "D:\incoming" --recursive=0 --threshold=92
     */
    protected $signature = 'video:move-duplicates
        {path : 必填資料夾路徑；搭配 --video-feature-id 時也可直接給單一影片檔}
        {--recursive=1 : 1=掃子資料夾，0=只掃一層}
        {--threshold=80 : dHash 相似度門檻}
        {--min-match=2 : 至少幾張截圖達標}
        {--window-seconds=3 : 時長容許秒數}
        {--size-percent=15 : 相容舊參數；正式比對已不使用檔案大小 gate}
        {--max-candidates=250 : 每支影片最多拉多少 DB 候選}
        {--reference-dir=C:\Users\User\Videos\暫 : 先同步並比對這個資料夾底下的影片特徵 JSON}
        {--video-feature-id= : 指定單一 video_features.id 進入手動分析模式；若命中重複仍會直接刪除}
        {--write-log : 相容舊參數；手動分析模式現在預設就會寫 log}
        {--skip-log : 手動 debug 模式不要寫入 external_video_duplicate_logs}
        {--dry-run : 只顯示結果不刪檔、不搬移}';

    protected $description = '掃描指定資料夾內影片，先比對 DB，再比對暫存參考資料夾的影片特徵 JSON；命中重複就直接刪除，未命中則搬到暫存參考資料夾並更新 JSON。指定 --video-feature-id 時則維持手動分析模式。';

    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'mkv', 'wmv', 'm4v', 'mpeg', 'mpg'];

    public function handle(
        VideoFeatureExtractionService $featureExtractionService,
        VideoDuplicateDetectionService $duplicateDetectionService,
        ExternalVideoDuplicateService $externalVideoDuplicateService,
        ReferenceVideoFeatureIndexService $referenceVideoFeatureIndexService
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
        $writeLog = !(bool) $this->option('skip-log');
        if ((bool) $this->option('write-log')) {
            $writeLog = true;
        }

        $commandLogContext = [
            'scan_root_path' => $path,
            'recursive' => $recursive,
            'threshold_percent' => $threshold,
            'min_match_required' => $minMatch,
            'window_seconds' => $windowSeconds,
            'size_percent' => $sizePercent,
            'max_candidates' => $maxCandidates,
            'dry_run' => $dryRun,
            'write_log' => $writeLog,
            'manual_feature_id' => $manualFeatureId,
            'pid' => getmypid(),
        ];
        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates started', $commandLogContext);

        if ($manualFeatureId !== null) {
            Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates entering manual feature mode', $commandLogContext);
            return $this->handleManualFeatureMode(
                $path,
                $manualFeatureId,
                $recursive,
                $threshold,
                $minMatch,
                $windowSeconds,
                $sizePercent,
                $writeLog,
                $dryRun,
                $featureExtractionService,
                $duplicateDetectionService,
                $externalVideoDuplicateService
            );
        }

        if ($path === '' || !is_dir($path)) {
            Log::channel(self::LOG_CHANNEL)->warning('video:move-duplicates invalid scan directory', $commandLogContext);
            $this->error('path 不是有效資料夾：' . $this->argument('path'));
            return self::FAILURE;
        }

        $referenceDir = $this->normalizeAbsolutePath((string) $this->option('reference-dir'));
        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates syncing reference index', $commandLogContext + [
            'reference_dir' => $referenceDir,
        ]);
        try {
            $referenceIndex = $referenceVideoFeatureIndexService->syncDirectory($referenceDir);
        } catch (Throwable $e) {
            Log::channel(self::LOG_CHANNEL)->error('video:move-duplicates failed to sync reference index', $commandLogContext + [
                'reference_dir' => $referenceDir,
                'error_message' => $e->getMessage(),
            ]);
            $this->error('同步暫存參考索引失敗：' . $e->getMessage());
            return self::FAILURE;
        }

        $referenceDir = (string) $referenceIndex['directory_path'];
        $shouldCompareReferenceIndex = $this->shouldCompareReferenceIndex($path, $referenceDir);
        $referenceSnapshots = is_array($referenceIndex['snapshots'] ?? null) ? $referenceIndex['snapshots'] : [];
        $referenceComparisonEnabled = $shouldCompareReferenceIndex && $referenceSnapshots !== [];
        $preparedReferenceSnapshotIndex = $referenceComparisonEnabled
            ? $duplicateDetectionService->prepareReferenceSnapshotIndex($referenceSnapshots)
            : ['snapshots' => []];
        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates reference index synced', $commandLogContext + [
            'reference_dir' => $referenceDir,
            'reference_index_path' => (string) ($referenceIndex['index_path'] ?? ''),
            'reference_total_files' => (int) ($referenceIndex['total_files'] ?? 0),
            'reference_reused_count' => (int) ($referenceIndex['reused_count'] ?? 0),
            'reference_extracted_count' => (int) ($referenceIndex['extracted_count'] ?? 0),
            'reference_removed_count' => (int) ($referenceIndex['removed_count'] ?? 0),
            'reference_failed_count' => (int) ($referenceIndex['failed_count'] ?? 0),
            'reference_compare_enabled' => $referenceComparisonEnabled,
        ]);

        $this->line(sprintf(
            '暫存參考索引：%s（total=%d reused=%d extracted=%d removed=%d failed=%d）',
            (string) $referenceIndex['index_path'],
            (int) ($referenceIndex['total_files'] ?? 0),
            (int) ($referenceIndex['reused_count'] ?? 0),
            (int) ($referenceIndex['extracted_count'] ?? 0),
            (int) ($referenceIndex['removed_count'] ?? 0),
            (int) ($referenceIndex['failed_count'] ?? 0)
        ));

        if (!$shouldCompareReferenceIndex) {
            $this->line('  指定掃描資料夾就是暫存參考資料夾本身，這次只同步 JSON，只比對 DB。');
        } elseif ($referenceSnapshots === []) {
            $this->line('  暫存參考資料夾目前沒有可比對的影片特徵。');
        }

        foreach ((array) ($referenceIndex['failed_files'] ?? []) as $failedReferenceFile) {
            $failedPath = (string) ($failedReferenceFile['absolute_path'] ?? '');
            $failedMessage = (string) ($failedReferenceFile['message'] ?? '未知錯誤');

            if ($failedPath !== '') {
                Log::channel(self::LOG_CHANNEL)->warning('video:move-duplicates reference index skipped file', $commandLogContext + [
                    'reference_dir' => $referenceDir,
                    'file_path' => $failedPath,
                    'error_message' => $failedMessage,
                ]);
                $this->warn('  暫存索引略過：' . $failedPath . ' -> ' . $failedMessage);
            }
        }

        $duplicateDir = $path . DIRECTORY_SEPARATOR . '疑似重複檔案';
        $files = $this->collectVideoFiles($path, $recursive, $duplicateDir);
        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates collected files', $commandLogContext + [
            'duplicate_directory_path' => $duplicateDir,
            'file_count' => count($files),
        ]);

        if ($files === []) {
            Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates found no files to scan', $commandLogContext + [
                'duplicate_directory_path' => $duplicateDir,
            ]);
            $this->warn('找不到影片檔。');
            return self::SUCCESS;
        }

        $deleted = 0;
        $staged = 0;
        $kept = 0;
        $failed = 0;

        foreach ($files as $filePath) {
            $this->line($filePath);
            $fileLogContext = $commandLogContext + [
                'file_path' => $filePath,
                'duplicate_directory_path' => $duplicateDir,
            ];
            Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates processing file', $fileLogContext);

            $payload = null;
            $databaseAnalysis = null;
            $referenceAnalysis = null;
            $combinedAnalysis = null;
            $destinationPath = null;
            $deletedFilePath = null;
            $deletedSourceFile = false;
            $movedToReferenceDir = false;
            $comparisonLogged = false;

            try {
                $payload = $featureExtractionService->inspectFile($filePath);
                Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates extracted file payload', $fileLogContext + [
                    'duration_seconds' => $payload['duration_seconds'] ?? null,
                    'file_size_bytes' => $payload['file_size_bytes'] ?? null,
                    'frame_count' => count((array) ($payload['frames'] ?? [])),
                    'capture_rule' => $payload['capture_rule'] ?? null,
                ]);
                $databaseAnalysis = $duplicateDetectionService->analyzeDatabaseMatch(
                    $payload,
                    $threshold,
                    $minMatch,
                    $windowSeconds,
                    $sizePercent,
                    $maxCandidates
                );
                $combinedAnalysis = $databaseAnalysis;
                $match = $databaseAnalysis['duplicate_match'] ?? null;
                Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates database analysis completed', $fileLogContext + [
                    'db_candidate_count' => (int) ($databaseAnalysis['candidate_count'] ?? 0),
                    'db_has_duplicate_match' => is_array($match),
                    'db_best_similarity_percent' => is_array($databaseAnalysis['best_result'] ?? null)
                        ? ($databaseAnalysis['best_result']['similarity_percent'] ?? null)
                        : null,
                    'db_best_matched_frames' => is_array($databaseAnalysis['best_result'] ?? null)
                        ? ($databaseAnalysis['best_result']['matched_frames'] ?? null)
                        : null,
                    'db_best_compared_frames' => is_array($databaseAnalysis['best_result'] ?? null)
                        ? ($databaseAnalysis['best_result']['compared_frames'] ?? null)
                        : null,
                ]);
                $baseLogOptions = [
                    'scan_root_path' => $path,
                    'threshold_percent' => $threshold,
                    'min_match_required' => $minMatch,
                    'window_seconds' => $windowSeconds,
                    'size_percent' => $sizePercent,
                    'max_candidates' => $maxCandidates,
                ];

                if (!is_array($match)) {
                    if ($referenceComparisonEnabled) {
                        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates starting reference index comparison', $fileLogContext + [
                            'reference_dir' => $referenceDir,
                            'reference_snapshot_count' => count((array) ($preparedReferenceSnapshotIndex['snapshots'] ?? [])),
                        ]);
                        $referenceAnalysis = $duplicateDetectionService->analyzePreparedReferenceSnapshotsMatch(
                            $payload,
                            $preparedReferenceSnapshotIndex,
                            $threshold,
                            $minMatch,
                            $windowSeconds,
                            $sizePercent,
                            $maxCandidates
                        );
                        $combinedAnalysis = $this->mergeAnalyses($databaseAnalysis, $referenceAnalysis);
                        $referenceMatch = $referenceAnalysis['duplicate_match'] ?? null;
                        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates reference index analysis completed', $fileLogContext + [
                            'reference_candidate_count' => (int) ($referenceAnalysis['candidate_count'] ?? 0),
                            'reference_has_duplicate_match' => is_array($referenceMatch),
                            'reference_best_similarity_percent' => is_array($referenceAnalysis['best_result'] ?? null)
                                ? ($referenceAnalysis['best_result']['similarity_percent'] ?? null)
                                : null,
                            'reference_best_matched_frames' => is_array($referenceAnalysis['best_result'] ?? null)
                                ? ($referenceAnalysis['best_result']['matched_frames'] ?? null)
                                : null,
                            'reference_best_compared_frames' => is_array($referenceAnalysis['best_result'] ?? null)
                                ? ($referenceAnalysis['best_result']['compared_frames'] ?? null)
                                : null,
                        ]);

                        if (is_array($referenceMatch)) {
                            $referenceSnapshot = is_array($referenceMatch['feature_snapshot'] ?? null)
                                ? $referenceMatch['feature_snapshot']
                                : [];
                            $referencePath = $this->normalizeAbsolutePath((string) ($referenceSnapshot['absolute_path'] ?? ''));
                            Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates reference index match found', $fileLogContext + [
                                'reference_match_path' => $referencePath,
                                'similarity_percent' => $referenceMatch['similarity_percent'] ?? null,
                                'matched_frames' => $referenceMatch['matched_frames'] ?? null,
                                'compared_frames' => $referenceMatch['compared_frames'] ?? null,
                            ]);

                            $this->warn(sprintf(
                                '  命中暫存參考影片=%s，相似度=%s%%，matched=%d/%d',
                                $referencePath !== '' ? $referencePath : '(unknown)',
                                number_format((float) $referenceMatch['similarity_percent'], 2),
                                (int) $referenceMatch['matched_frames'],
                                (int) $referenceMatch['compared_frames']
                            ));

                            if ($dryRun) {
                                $externalVideoDuplicateService->persistComparisonLog(
                                    $payload,
                                    $combinedAnalysis,
                                    $filePath,
                                    $baseLogOptions + [
                                        'is_duplicate_detected' => true,
                                        'operation_status' => 'reference_index_dry_run_match',
                                        'operation_message' => '命中暫存參考索引，dry-run 模式未刪除檔案。',
                                    ]
                                );
                                $comparisonLogged = true;
                                Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates reference index dry-run match logged', $fileLogContext + [
                                    'reference_match_path' => $referencePath,
                                ]);
                                continue;
                            }

                            $deletedFilePath = $filePath;
                            if (!@unlink($filePath)) {
                                throw new \RuntimeException('刪除重複檔案失敗：' . $filePath);
                            }

                            $deletedSourceFile = true;

                            $externalVideoDuplicateService->persistComparisonLog(
                                $payload,
                                $combinedAnalysis,
                                $filePath,
                                $baseLogOptions + [
                                    'duplicate_file_path' => $deletedFilePath,
                                    'is_duplicate_detected' => true,
                                    'operation_status' => 'reference_index_match_deleted',
                                    'operation_message' => $referencePath !== ''
                                        ? '命中暫存參考索引影片（' . $referencePath . '），已直接刪除檔案。'
                                        : '命中暫存參考索引影片，已直接刪除檔案。',
                                    ]
                                );
                            $comparisonLogged = true;

                            $deleted++;
                            Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates deleted file by reference index match', $fileLogContext + [
                                'reference_match_path' => $referencePath,
                                'deleted_file_path' => $deletedFilePath,
                            ]);
                            $this->info('  已直接刪除');
                            continue;
                        }
                    }

                    if ($dryRun) {
                        $externalVideoDuplicateService->persistComparisonLog(
                            $payload,
                            $combinedAnalysis,
                            $filePath,
                            $baseLogOptions + [
                                'is_duplicate_detected' => false,
                                'operation_status' => 'no_match',
                                'operation_message' => $this->buildAutomaticNoMatchMessage($shouldCompareReferenceIndex, false, true),
                            ]
                        );
                        $comparisonLogged = true;
                        $kept++;
                        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates kept file after no-match dry-run', $fileLogContext + [
                            'reference_compare_enabled' => $referenceComparisonEnabled,
                            'reference_dir' => $referenceDir,
                        ]);
                        $this->line('  無重複，dry-run 模式未異動');
                        continue;
                    }

                    if ($shouldCompareReferenceIndex) {
                        $destinationPath = $this->buildUniqueDestination($referenceDir, basename($filePath));
                        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates moving file to reference dir after no match', $fileLogContext + [
                            'reference_dir' => $referenceDir,
                            'destination_path' => $destinationPath,
                        ]);

                        File::ensureDirectoryExists($referenceDir);
                        if (!@rename($filePath, $destinationPath)) {
                            throw new \RuntimeException('搬移到暫存參考資料夾失敗：' . $destinationPath);
                        }

                        $movedToReferenceDir = true;
                        $referenceIndex = $referenceVideoFeatureIndexService->upsertPayloadSnapshot(
                            $referenceDir,
                            $referenceSnapshots,
                            $this->buildMovedPayload($payload, $destinationPath),
                            (array) ($referenceIndex['failed_files'] ?? [])
                        );
                        $referenceSnapshots = is_array($referenceIndex['snapshots'] ?? null) ? $referenceIndex['snapshots'] : [];
                        $preparedReferenceSnapshotIndex = $duplicateDetectionService->appendPreparedReferenceSnapshot(
                            $preparedReferenceSnapshotIndex,
                            $this->buildMovedPayload($payload, $destinationPath)
                        );
                        $referenceComparisonEnabled = $shouldCompareReferenceIndex && $referenceSnapshots !== [];

                        $externalVideoDuplicateService->persistComparisonLog(
                            $payload,
                            $combinedAnalysis,
                            $filePath,
                            $baseLogOptions + [
                                'duplicate_file_path' => $destinationPath,
                                'is_duplicate_detected' => false,
                                'operation_status' => 'moved_to_reference_dir',
                                'operation_message' => $this->buildAutomaticNoMatchMessage($shouldCompareReferenceIndex, true),
                            ]
                        );
                        $comparisonLogged = true;
                        $staged++;
                        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates moved file to reference dir after no match', $fileLogContext + [
                            'reference_compare_enabled' => $referenceComparisonEnabled,
                            'reference_dir' => $referenceDir,
                            'destination_path' => $destinationPath,
                            'reference_snapshot_count' => count((array) ($preparedReferenceSnapshotIndex['snapshots'] ?? [])),
                        ]);
                        $this->line('  無重複，已搬到暫存參考資料夾：' . $destinationPath);
                        continue;
                    }

                    $externalVideoDuplicateService->persistComparisonLog(
                        $payload,
                        $combinedAnalysis,
                        $filePath,
                        $baseLogOptions + [
                            'is_duplicate_detected' => false,
                            'operation_status' => 'no_match',
                            'operation_message' => $this->buildAutomaticNoMatchMessage(false, false),
                        ]
                    );
                    $comparisonLogged = true;
                    $kept++;
                    Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates kept file after no match', $fileLogContext + [
                        'reference_compare_enabled' => $referenceComparisonEnabled,
                        'reference_dir' => $referenceDir,
                    ]);
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
                        $databaseAnalysis,
                        $filePath,
                        $baseLogOptions + [
                            'is_duplicate_detected' => true,
                            'operation_status' => 'same_path_skipped',
                            'operation_message' => '與 DB 原檔為同一路徑，未刪除。',
                        ]
                    );
                    $comparisonLogged = true;
                    $kept++;
                    Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates skipped same-path database match', $fileLogContext + [
                        'matched_video_master_id' => $feature->video_master_id ?? null,
                        'matched_video_feature_id' => $feature->id ?? null,
                        'stored_video_path' => $storedVideoPath,
                    ]);
                    $this->line('  與 DB 原檔為同一路徑，跳過');
                    continue;
                }

                Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates database match found', $fileLogContext + [
                    'matched_video_master_id' => $feature->video_master_id ?? null,
                    'matched_video_feature_id' => $feature->id ?? null,
                    'similarity_percent' => $match['similarity_percent'] ?? null,
                    'matched_frames' => $match['matched_frames'] ?? null,
                    'compared_frames' => $match['compared_frames'] ?? null,
                ]);

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
                        $databaseAnalysis,
                        $filePath,
                        $baseLogOptions + [
                            'is_duplicate_detected' => true,
                            'operation_status' => 'dry_run_match',
                            'operation_message' => 'dry-run 模式，未刪除檔案。',
                        ]
                    );
                    $comparisonLogged = true;
                    Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates database dry-run match logged', $fileLogContext + [
                        'matched_video_master_id' => $feature->video_master_id ?? null,
                        'matched_video_feature_id' => $feature->id ?? null,
                    ]);
                    continue;
                }

                $deletedFilePath = $filePath;
                if (!@unlink($filePath)) {
                    throw new \RuntimeException('刪除重複檔案失敗：' . $filePath);
                }

                $deletedSourceFile = true;
                $externalVideoDuplicateService->persistComparisonLog(
                    $payload,
                    $databaseAnalysis,
                    $filePath,
                    $baseLogOptions + [
                        'duplicate_file_path' => $deletedFilePath,
                        'is_duplicate_detected' => true,
                        'operation_status' => 'match_deleted',
                        'operation_message' => '命中重複並已直接刪除檔案。',
                    ]
                );
                $comparisonLogged = true;

                $deleted++;
                Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates deleted file by database match', $fileLogContext + [
                    'matched_video_master_id' => $feature->video_master_id ?? null,
                    'matched_video_feature_id' => $feature->id ?? null,
                    'deleted_file_path' => $deletedFilePath,
                ]);
                $this->info('  已直接刪除');
            } catch (Throwable $e) {
                if (
                    $movedToReferenceDir &&
                    is_string($destinationPath) &&
                    $destinationPath !== '' &&
                    File::exists($destinationPath) &&
                    !File::exists($filePath)
                ) {
                    @rename($destinationPath, $filePath);
                }

                if ($movedToReferenceDir && $referenceDir !== '') {
                    try {
                        $referenceIndex = $referenceVideoFeatureIndexService->syncDirectory($referenceDir);
                        $referenceSnapshots = is_array($referenceIndex['snapshots'] ?? null) ? $referenceIndex['snapshots'] : [];
                        $referenceComparisonEnabled = $shouldCompareReferenceIndex && $referenceSnapshots !== [];
                        $preparedReferenceSnapshotIndex = $referenceComparisonEnabled
                            ? $duplicateDetectionService->prepareReferenceSnapshotIndex($referenceSnapshots)
                            : ['snapshots' => []];
                        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates repaired reference index after failure', $fileLogContext + [
                            'reference_dir' => $referenceDir,
                            'reference_snapshot_count' => count((array) ($preparedReferenceSnapshotIndex['snapshots'] ?? [])),
                        ]);
                    } catch (Throwable $syncException) {
                        Log::channel(self::LOG_CHANNEL)->warning('video:move-duplicates failed to repair reference index after failure', $fileLogContext + [
                            'reference_dir' => $referenceDir,
                            'repair_error_message' => $syncException->getMessage(),
                        ]);
                    }
                }

                if (!$comparisonLogged && is_array($payload)) {
                    try {
                        $externalVideoDuplicateService->persistComparisonLog(
                            $payload,
                            is_array($combinedAnalysis) ? $combinedAnalysis : null,
                            $filePath,
                            [
                                'scan_root_path' => $path,
                                'duplicate_file_path' => $deletedSourceFile
                                    ? $deletedFilePath
                                    : ($movedToReferenceDir && is_string($destinationPath) ? $destinationPath : null),
                                'threshold_percent' => $threshold,
                                'min_match_required' => $minMatch,
                                'window_seconds' => $windowSeconds,
                                'size_percent' => $sizePercent,
                                'max_candidates' => $maxCandidates,
                                'is_duplicate_detected' => is_array($combinedAnalysis) && is_array($combinedAnalysis['duplicate_match'] ?? null),
                                'operation_status' => 'error',
                                'operation_message' => $e->getMessage(),
                            ]
                        );
                    } catch (Throwable) {
                    }
                }

                $failed++;
                Log::channel(self::LOG_CHANNEL)->error('video:move-duplicates failed while processing file', $fileLogContext + [
                    'destination_path' => $destinationPath,
                    'deleted_source_file' => $deletedSourceFile,
                    'deleted_file_path' => $deletedFilePath,
                    'moved_to_reference_dir' => $movedToReferenceDir,
                    'comparison_logged' => $comparisonLogged,
                    'error_message' => $e->getMessage(),
                ]);
                $this->error('  失敗：' . $e->getMessage());
            } finally {
                if (is_array($payload)) {
                    $featureExtractionService->cleanupPayload($payload);
                    Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates cleaned temporary payload', $fileLogContext);
                }
            }
        }

        $this->newLine();
        $this->info(sprintf('完成，deleted=%d staged=%d kept=%d failed=%d', $deleted, $staged, $kept, $failed));
        Log::channel(self::LOG_CHANNEL)->info('video:move-duplicates finished', $commandLogContext + [
            'deleted_count' => $deleted,
            'staged_count' => $staged,
            'kept_count' => $kept,
            'failed_count' => $failed,
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function buildAutomaticNoMatchMessage(
        bool $shouldCompareReferenceIndex,
        bool $movedToReferenceDir,
        bool $dryRun = false
    ): string
    {
        if ($movedToReferenceDir) {
            return '未命中 DB 或暫存參考索引重複門檻，已搬移到暫存參考資料夾並更新 JSON。';
        }

        if ($dryRun) {
            return $shouldCompareReferenceIndex
                ? '未命中 DB 或暫存參考索引重複門檻，dry-run 模式未異動檔案。'
                : '未命中 DB 重複門檻，dry-run 模式未異動檔案。';
        }

        return $shouldCompareReferenceIndex
            ? '未命中 DB 或暫存參考索引重複門檻，保留原位。'
            : '未命中 DB 重複門檻，保留原位。';
    }

    private function shouldCompareReferenceIndex(string $scanRootPath, string $referenceDir): bool
    {
        $normalizedPath = $this->normalizeComparisonPath($scanRootPath);
        $normalizedReferenceDir = $this->normalizeComparisonPath($referenceDir);

        if ($normalizedPath === '' || $normalizedReferenceDir === '') {
            return true;
        }

        return $normalizedPath !== $normalizedReferenceDir;
    }

    private function normalizeComparisonPath(string $path): string
    {
        $normalizedPath = str_replace('/', '\\', $this->normalizeAbsolutePath($path));

        return mb_strtolower(rtrim($normalizedPath, '\\'));
    }

    private function mergeAnalyses(?array ...$analyses): ?array
    {
        $hasAnyAnalysis = false;
        $bestResult = null;
        $duplicateMatch = null;
        $candidateCount = 0;
        $payloadFrameCount = null;
        $requestedMinMatch = null;

        foreach ($analyses as $analysis) {
            if (!is_array($analysis)) {
                continue;
            }

            $hasAnyAnalysis = true;
            $candidateCount += (int) ($analysis['candidate_count'] ?? 0);

            if ($payloadFrameCount === null && array_key_exists('payload_frame_count', $analysis)) {
                $payloadFrameCount = (int) $analysis['payload_frame_count'];
            }

            if ($requestedMinMatch === null && array_key_exists('requested_min_match', $analysis)) {
                $requestedMinMatch = (int) $analysis['requested_min_match'];
            }

            $candidateBestResult = $analysis['best_result'] ?? null;
            if (
                is_array($candidateBestResult) &&
                ($bestResult === null || (float) ($candidateBestResult['score'] ?? 0) > (float) ($bestResult['score'] ?? 0))
            ) {
                $bestResult = $candidateBestResult;
            }

            $candidateDuplicateMatch = $analysis['duplicate_match'] ?? null;
            if ($duplicateMatch === null && is_array($candidateDuplicateMatch)) {
                $duplicateMatch = $candidateDuplicateMatch;
            }
        }

        if (!$hasAnyAnalysis) {
            return null;
        }

        if ($bestResult === null && is_array($duplicateMatch)) {
            $bestResult = $duplicateMatch;
        }

        return [
            'best_result' => $bestResult,
            'duplicate_match' => $duplicateMatch,
            'candidate_count' => $candidateCount,
            'payload_frame_count' => $payloadFrameCount ?? 0,
            'requested_min_match' => $requestedMinMatch ?? 0,
        ];
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
        bool $dryRun,
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
            $analysis = null;
            $isSamePath = false;
            $deletedFilePath = null;
            $deletedSourceFile = false;
            $comparisonLogged = false;

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

                $dbVideoPath = $this->resolveFeatureVideoPath($feature, $featureExtractionService);
                $isSamePath = $dbVideoPath !== '' && mb_strtolower($dbVideoPath) === mb_strtolower($filePath);
                $operationStatus = $this->buildManualOperationStatus($analysis, $isSamePath);
                $operationMessage = $this->buildConclusionMessage($analysis, $isSamePath);
                $baseLogOptions = [
                    'scan_root_path' => is_dir($path) ? $path : dirname($filePath),
                    'threshold_percent' => $threshold,
                    'min_match_required' => $minMatch,
                    'window_seconds' => $windowSeconds,
                    'size_percent' => $sizePercent,
                    'max_candidates' => 1,
                ];

                $this->renderOverview(
                    $filePath,
                    $feature,
                    $analysis,
                    $threshold,
                    $minMatch,
                    $windowSeconds,
                    $sizePercent,
                    $dbVideoPath,
                    $isSamePath
                );
                $this->renderCandidateGate($analysis['candidate_gate'] ?? []);
                $this->renderFrameComparisons($analysis['compare_result'] ?? null);
                $this->renderConclusion($analysis, $isSamePath);
                $this->renderDebugHints($analysis, $isSamePath);

                if ($this->isOfficialDuplicateMatch($analysis, $isSamePath)) {
                    $match = $analysis['duplicate_match'] ?? null;
                    if (!is_array($match)) {
                        throw new \RuntimeException('判定為重複，但 duplicate_match 資料不存在。');
                    }

                    if ($dryRun) {
                        if ($writeLog) {
                            $externalVideoDuplicateService->persistComparisonLog(
                                $payload,
                                $this->buildManualLogAnalysis($analysis, $feature, $minMatch),
                                $filePath,
                                $baseLogOptions + [
                                    'is_duplicate_detected' => true,
                                    'operation_status' => 'dry_run_match',
                                    'operation_message' => 'dry-run 模式，未刪除檔案。',
                                ]
                            );
                            $comparisonLogged = true;
                        }

                        $this->warn('dry-run 模式，未刪除檔案。');
                    } else {
                        $deletedFilePath = $filePath;
                        if (!@unlink($filePath)) {
                            throw new \RuntimeException('刪除重複檔案失敗：' . $filePath);
                        }

                        $deletedSourceFile = true;

                        if ($writeLog) {
                            $externalVideoDuplicateService->persistComparisonLog(
                                $payload,
                                $this->buildManualLogAnalysis($analysis, $feature, $minMatch),
                                $filePath,
                                $baseLogOptions + [
                                    'duplicate_file_path' => $deletedFilePath,
                                    'is_duplicate_detected' => true,
                                    'operation_status' => 'match_deleted',
                                    'operation_message' => '手動分析確認重複，已直接刪除檔案。',
                                ]
                            );
                            $comparisonLogged = true;
                        }

                        $this->info('已直接刪除');
                    }
                } elseif ($writeLog) {
                    $log = $externalVideoDuplicateService->persistComparisonLog(
                        $payload,
                        $this->buildManualLogAnalysis($analysis, $feature, $minMatch),
                        $filePath,
                        $baseLogOptions + [
                            'is_duplicate_detected' => $this->isOfficialDuplicateMatch($analysis, $isSamePath),
                            'operation_status' => $operationStatus,
                            'operation_message' => $operationMessage,
                        ]
                    );

                    $comparisonLogged = true;
                    $this->info('已寫入 debug log，ID=' . $log->id);
                } else {
                    $this->line('已略過寫 log（--skip-log）');
                }

                $processed++;
            } catch (Throwable $e) {
                if (!$comparisonLogged && is_array($payload)) {
                    try {
                        $externalVideoDuplicateService->persistComparisonLog(
                            $payload,
                            isset($analysis) && is_array($analysis)
                                ? $this->buildManualLogAnalysis($analysis, $feature, $minMatch)
                                : null,
                            $filePath,
                            [
                                'scan_root_path' => is_dir($path) ? $path : dirname($filePath),
                                'duplicate_file_path' => $deletedSourceFile ? $deletedFilePath : null,
                                'threshold_percent' => $threshold,
                                'min_match_required' => $minMatch,
                                'window_seconds' => $windowSeconds,
                                'size_percent' => $sizePercent,
                                'max_candidates' => 1,
                                'is_duplicate_detected' => isset($analysis) && is_array($analysis)
                                    ? $this->isOfficialDuplicateMatch($analysis, $isSamePath)
                                    : false,
                                'operation_status' => 'error',
                                'operation_message' => $e->getMessage(),
                            ]
                        );
                    } catch (Throwable) {
                    }
                }

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
        int $sizePercent,
        string $dbVideoPath,
        bool $isSamePath
    ): void {
        $candidateGate = is_array($analysis['candidate_gate'] ?? null) ? $analysis['candidate_gate'] : [];

        $this->info('來源影片：' . $filePath);
        $this->line('指定 feature：#' . $feature->id);
        $this->line('DB video_master：' . ($feature->video_master_id !== null ? '#' . $feature->video_master_id : '-'));
        $this->line('DB 影片名稱：' . ((string) ($feature->video_name ?? '-')));
        $this->line('DB 影片路徑：' . ($dbVideoPath !== '' ? $dbVideoPath : ((string) ($feature->video_path ?? '-'))));
        $this->line('來源/DB 同一路徑：' . ($isSamePath ? 'YES' : 'NO'));
        $this->line(sprintf(
            '分析參數：threshold=%d min-match=%d window=%d size=%d%% (ignored)',
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
                ['來源 duration_seconds', $this->formatSeconds($candidateGate['payload_duration_seconds'] ?? null)],
                ['DB capture_rule', (string) ($feature->capture_rule ?? '-')],
                ['DB duration_seconds', $feature->duration_seconds !== null ? (string) $feature->duration_seconds : '-'],
                ['來源 file_size_bytes', $this->formatBytes($candidateGate['payload_file_size_bytes'] ?? null)],
                ['DB file_size_bytes', $feature->file_size_bytes !== null ? (string) $feature->file_size_bytes : '-'],
                ['來源 dHash prefix 數', (string) ($candidateGate['payload_prefix_count'] ?? 0)],
                ['DB dHash prefix 數', (string) ($candidateGate['feature_prefix_count'] ?? 0)],
            ]
        );
    }

    private function renderCandidateGate(array $candidateGate): void
    {
        $this->newLine();
        $this->info('正式流程 Candidate Gate');

        $rows = [
            ['source prefixes', empty($candidateGate['payload_prefixes']) ? '-' : implode(', ', $candidateGate['payload_prefixes'])],
            ['feature prefixes', empty($candidateGate['feature_prefixes']) ? '-' : implode(', ', $candidateGate['feature_prefixes'])],
            ['shared dHash prefix', empty($candidateGate['shared_prefixes']) ? '-' : implode(', ', $candidateGate['shared_prefixes'])],
            ['source duration', $this->formatSeconds($candidateGate['payload_duration_seconds'] ?? null)],
            ['feature duration', $this->formatSeconds($candidateGate['feature_duration_seconds'] ?? null)],
            ['duration window', sprintf(
                '%s (%s ~ %s)',
                !empty($candidateGate['duration_within_window']) ? 'PASS' : 'BLOCK',
                $this->formatSeconds($candidateGate['duration_window_min'] ?? null, false),
                $this->formatSeconds($candidateGate['duration_window_max'] ?? null, false)
            )],
            ['duration delta', isset($candidateGate['duration_delta_seconds']) && $candidateGate['duration_delta_seconds'] !== null
                ? $this->formatSeconds($candidateGate['duration_delta_seconds'], false) . ' sec'
                : '-'],
            ['source size', $this->formatBytes($candidateGate['payload_file_size_bytes'] ?? null)],
            ['feature size', $this->formatBytes($candidateGate['feature_file_size_bytes'] ?? null)],
            ['size delta', isset($candidateGate['file_size_delta_bytes']) && $candidateGate['file_size_delta_bytes'] !== null
                ? number_format((int) $candidateGate['file_size_delta_bytes']) . ' bytes'
                : '-'],
            ['size gate', !empty($candidateGate['size_gate_ignored']) ? 'IGNORED' : 'OFF'],
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

    private function renderConclusion(array $analysis, bool $isSamePath): void
    {
        $this->newLine();
        $message = $this->buildConclusionMessage($analysis, $isSamePath);

        if ($this->isOfficialDuplicateMatch($analysis, $isSamePath)) {
            $this->info($message);
            return;
        }

        if (is_array($analysis['compare_result'] ?? null) && !empty($analysis['compare_result']['passes_threshold'])) {
            $this->warn($message);
            return;
        }

        $this->warn($message);
    }

    private function buildConclusionMessage(array $analysis, bool $isSamePath = false): string
    {
        $candidateGate = is_array($analysis['candidate_gate'] ?? null) ? $analysis['candidate_gate'] : [];
        $compareResult = is_array($analysis['compare_result'] ?? null) ? $analysis['compare_result'] : null;
        $reasonText = !empty($candidateGate['reasons']) && is_array($candidateGate['reasons'])
            ? rtrim(implode('、', $candidateGate['reasons']), '。. ')
            : '未提供';

        if ($isSamePath) {
            return '結論：來源檔案和 DB 原檔是同一路徑；正式掃描模式會走 same_path_skipped，只記 log，不會刪除。';
        }

        if (!$compareResult) {
            return '結論：這次強制比對沒有任何可比較的 frame，正式流程自然不會命中。';
        }

        if (!empty($candidateGate['eligible']) && !empty($compareResult['passes_threshold'])) {
            return sprintf(
                '結論：正式流程候選條件可通過，且強制比對也達門檻（similarity=%s%%, matched=%d/%d）；非 dry-run 時會直接刪除檔案，並寫入 log。',
                $this->formatPercent($compareResult['similarity_percent'] ?? null),
                (int) ($compareResult['matched_frames'] ?? 0),
                (int) ($compareResult['required_matches'] ?? 0)
            );
        }

        if (empty($candidateGate['eligible']) && !empty($compareResult['passes_threshold'])) {
            $message = sprintf(
                '結論：強制比對其實已達門檻（similarity=%s%%, matched=%d/%d），但正式流程在 candidate gate 就被擋掉了：%s。',
                $this->formatPercent($compareResult['similarity_percent'] ?? null),
                (int) ($compareResult['matched_frames'] ?? 0),
                (int) ($compareResult['required_matches'] ?? 0),
                $reasonText
            );

            if (($compareResult['similarity_percent'] ?? 0) >= 99) {
                $message .= ' 這是高度疑似同片或同內容重編碼版本。';
            }

            return $message;
        }

        if (!empty($candidateGate['eligible']) && empty($compareResult['passes_threshold'])) {
            return sprintf(
                '結論：正式流程會拿它來比，但 matched=%d / required=%d，overall similarity=%s%%，沒有達到門檻。',
                (int) ($compareResult['matched_frames'] ?? 0),
                (int) ($compareResult['required_matches'] ?? 0),
                $this->formatPercent($compareResult['similarity_percent'] ?? null)
            );
        }

        $message = '結論：正式流程前置條件沒過，而且強制比對本身也沒達門檻。';

        if ($reasonText !== '未提供') {
            $message .= ' Gate 原因：' . $reasonText . '。';
        }

        return $message;
    }

    private function renderDebugHints(array $analysis, bool $isSamePath): void
    {
        $hints = $this->buildDebugHints($analysis, $isSamePath);
        if ($hints === []) {
            return;
        }

        $this->newLine();
        $this->info('Debug 補充');

        foreach ($hints as $hint) {
            $tone = $hint['tone'] ?? 'line';
            $message = (string) ($hint['message'] ?? '');
            if ($message === '') {
                continue;
            }

            if ($tone === 'warn') {
                $this->warn($message);
                continue;
            }

            if ($tone === 'info') {
                $this->info($message);
                continue;
            }

            $this->line($message);
        }
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
                'candidate_gate' => $analysis['candidate_gate'] ?? null,
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
            'candidate_gate' => $analysis['candidate_gate'] ?? null,
        ];
    }

    private function buildManualOperationStatus(array $analysis, bool $isSamePath): string
    {
        $candidateGate = is_array($analysis['candidate_gate'] ?? null) ? $analysis['candidate_gate'] : [];
        $compareResult = is_array($analysis['compare_result'] ?? null) ? $analysis['compare_result'] : null;

        if ($isSamePath) {
            return 'manual_same_path';
        }

        if (!$compareResult) {
            return 'manual_no_frames';
        }

        if (!empty($candidateGate['eligible']) && !empty($compareResult['passes_threshold'])) {
            return 'manual_gate_pass';
        }

        if (empty($candidateGate['eligible']) && !empty($compareResult['passes_threshold'])) {
            return 'manual_gate_block';
        }

        if (!empty($candidateGate['eligible'])) {
            return 'manual_compare_fail';
        }

        return 'manual_gate_and_compare_fail';
    }

    private function isOfficialDuplicateMatch(array $analysis, bool $isSamePath): bool
    {
        if ($isSamePath) {
            return false;
        }

        $candidateGate = is_array($analysis['candidate_gate'] ?? null) ? $analysis['candidate_gate'] : [];
        $compareResult = is_array($analysis['compare_result'] ?? null) ? $analysis['compare_result'] : null;

        return !empty($candidateGate['eligible'])
            && is_array($compareResult)
            && !empty($compareResult['passes_threshold']);
    }

    private function buildDebugHints(array $analysis, bool $isSamePath): array
    {
        $candidateGate = is_array($analysis['candidate_gate'] ?? null) ? $analysis['candidate_gate'] : [];
        $compareResult = is_array($analysis['compare_result'] ?? null) ? $analysis['compare_result'] : null;
        $hints = [];

        if ($isSamePath) {
            $hints[] = [
                'tone' => 'info',
                'message' => '補充：這筆其實不是「外部重複檔」，而是同一路徑來源；正式掃描只會標成 same_path_skipped。',
            ];
        }

        if (($analysis['payload_frame_count'] ?? 0) <= 1) {
            $hints[] = [
                'tone' => 'warn',
                'message' => '補充：這次只有 1 張 frame 可比，判斷力有限；如果要降低誤判，應考慮補更多截圖點位。',
            ];
        }

        if (
            is_array($compareResult)
            && ($compareResult['matched_frames'] ?? 0) === 0
            && ($compareResult['compared_frames'] ?? 0) > 0
        ) {
            $hints[] = [
                'tone' => 'warn',
                'message' => '補充：frame 有對上 capture_order，但每張都沒過 threshold；這通常表示不是同片，或擷取點位雖然一致但畫面內容差太多。',
            ];
        }

        return $hints;
    }

    private function resolveFeatureVideoPath(
        VideoFeature $feature,
        VideoFeatureExtractionService $featureExtractionService
    ): string {
        if ($feature->videoMaster !== null) {
            try {
                return $this->normalizeAbsolutePath($featureExtractionService->resolveAbsoluteVideoPath($feature->videoMaster));
            } catch (Throwable) {
            }
        }

        return $this->normalizeAbsolutePath((string) ($feature->video_path ?? ''));
    }

    private function formatSeconds(mixed $value, bool $withUnit = true): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        $formatted = number_format((float) $value, 3);

        return $withUnit ? $formatted . ' sec' : $formatted;
    }

    private function formatBytes(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((int) $value) . ' bytes';
    }

    private function formatPercent(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return number_format((float) $value, 2);
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

    private function buildDuplicateDirectory(string $inputPath, string $filePath): string
    {
        if (is_dir($inputPath)) {
            return $this->normalizeAbsolutePath($inputPath . DIRECTORY_SEPARATOR . '疑似重複檔案');
        }

        return $this->normalizeAbsolutePath(dirname($filePath) . DIRECTORY_SEPARATOR . '疑似重複檔案');
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

    private function buildMovedPayload(array $payload, string $destinationPath): array
    {
        $destinationPath = $this->normalizeAbsolutePath($destinationPath);
        $payload['absolute_path'] = $destinationPath;
        $payload['directory_path'] = $destinationPath !== '' ? dirname($destinationPath) : null;
        $payload['file_name'] = basename($destinationPath);
        $payload['video_name'] = basename($destinationPath);

        $fileSize = @filesize($destinationPath);
        if ($fileSize !== false) {
            $payload['file_size_bytes'] = (int) $fileSize;
        }

        $fileCreatedAt = @filectime($destinationPath);
        if ($fileCreatedAt !== false) {
            $payload['file_created_at'] = (int) $fileCreatedAt;
        }

        $fileModifiedAt = @filemtime($destinationPath);
        if ($fileModifiedAt !== false) {
            $payload['file_modified_at'] = (int) $fileModifiedAt;
        }

        return $payload;
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
