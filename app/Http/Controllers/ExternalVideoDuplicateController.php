<?php

namespace App\Http\Controllers;

use App\Models\ExternalVideoDuplicateLog;
use App\Models\ExternalVideoDuplicateMatch;
use App\Models\VideoFeatureFrame;
use App\Services\ExternalVideoDuplicateService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ExternalVideoDuplicateController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $matchQuery = ExternalVideoDuplicateMatch::query();
        $this->applyMatchSearch($matchQuery, $q);

        $logQuery = ExternalVideoDuplicateLog::query();
        $this->applyLogSearch($logQuery, $q);

        $duplicateCount = (clone $matchQuery)->count();
        $duplicateAverageSimilarity = round((float) ((clone $matchQuery)->avg('similarity_percent') ?? 0), 2);

        $logCount = (clone $logQuery)->count();
        $logAverageSimilarity = round((float) ((clone $logQuery)->avg('similarity_percent') ?? 0), 2);

        $duplicateMatches = $matchQuery
            ->with([
                'latestComparisonLog',
                'frames.matchedVideoFeatureFrame',
                'matchedFeature.frames',
                'videoMaster',
            ])
            ->orderByDesc('created_at')
            ->paginate(4, ['*'], 'duplicates_page')
            ->withQueryString();

        $duplicateMatches->setCollection(
            $duplicateMatches->getCollection()->map(fn (ExternalVideoDuplicateMatch $match): array => $this->presentMatch($match))
        );

        $logs = $logQuery
            ->with([
                'match',
                'matchedFeature.frames',
                'videoMaster',
            ])
            ->orderByDesc('created_at')
            ->paginate(4, ['*'], 'logs_page')
            ->withQueryString();

        $logs->setCollection(
            $logs->getCollection()->map(fn (ExternalVideoDuplicateLog $log): array => $this->presentLog($log))
        );

        $existingDuplicateCountOnPage = collect($duplicateMatches->items())
            ->filter(fn (array $item): bool => (bool) ($item['duplicate_file_exists'] ?? false))
            ->count();

        return view('videos.external-duplicates.index', [
            'duplicateMatches' => $duplicateMatches,
            'comparisonLogs' => $logs,
            'q' => $q,
            'stats' => [
                'duplicate_count' => $duplicateCount,
                'duplicate_average_similarity' => $duplicateAverageSimilarity,
                'duplicates_existing_on_page' => $existingDuplicateCountOnPage,
                'log_count' => $logCount,
                'log_average_similarity' => $logAverageSimilarity,
            ],
        ]);
    }

    public function stream(ExternalVideoDuplicateMatch $match, ExternalVideoDuplicateService $service): BinaryFileResponse
    {
        abort_unless($this->isPathInsideDuplicateDirectory($match, $service), 403);

        $path = $service->normalizeAbsolutePath((string) $match->duplicate_file_path);
        abort_unless($path !== '' && is_file($path), 404);

        $mimeType = @mime_content_type($path) ?: 'application/octet-stream';

        return response()->file($path, [
            'Content-Type' => $mimeType,
        ]);
    }

    public function batchDelete(Request $request, ExternalVideoDuplicateService $service): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $ids = array_values(array_unique(array_map('intval', (array) $validated['ids'])));

        $records = ExternalVideoDuplicateMatch::query()
            ->with('frames')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $deletedIds = [];
        $failed = [];

        foreach ($ids as $id) {
            $record = $records->get($id);
            if (!$record instanceof ExternalVideoDuplicateMatch) {
                $failed[] = [
                    'id' => $id,
                    'message' => '找不到指定資料列。',
                ];
                continue;
            }

            if (!$this->isPathInsideDuplicateDirectory($record, $service)) {
                $failed[] = [
                    'id' => $id,
                    'message' => '只允許刪除「疑似重複檔案」資料夾內的影片。',
                ];
                continue;
            }

            $result = $service->deleteRecord($record);
            if (!($result['ok'] ?? false)) {
                $failed[] = [
                    'id' => $id,
                    'message' => (string) ($result['message'] ?? '刪除失敗。'),
                ];
                continue;
            }

            $deletedIds[] = $id;
        }

        return response()->json([
            'ok' => count($deletedIds) > 0 && count($failed) === 0,
            'deleted_ids' => $deletedIds,
            'failed' => $failed,
            'message' => sprintf('已刪除 %d 筆，失敗 %d 筆。', count($deletedIds), count($failed)),
        ]);
    }

    private function applyMatchSearch(Builder $query, string $q): void
    {
        if ($q === '') {
            return;
        }

        $query->where(function (Builder $inner) use ($q): void {
            $inner->where('file_name', 'like', '%' . $q . '%')
                ->orWhere('source_file_path', 'like', '%' . $q . '%')
                ->orWhere('duplicate_file_path', 'like', '%' . $q . '%')
                ->orWhereHas('videoMaster', function (Builder $videoQuery) use ($q): void {
                    $videoQuery->where('video_name', 'like', '%' . $q . '%')
                        ->orWhere('video_path', 'like', '%' . $q . '%');
                });
        });
    }

    private function applyLogSearch(Builder $query, string $q): void
    {
        if ($q === '') {
            return;
        }

        $query->where(function (Builder $inner) use ($q): void {
            $inner->where('file_name', 'like', '%' . $q . '%')
                ->orWhere('source_file_path', 'like', '%' . $q . '%')
                ->orWhere('duplicate_file_path', 'like', '%' . $q . '%')
                ->orWhere('operation_status', 'like', '%' . $q . '%')
                ->orWhereHas('videoMaster', function (Builder $videoQuery) use ($q): void {
                    $videoQuery->where('video_name', 'like', '%' . $q . '%')
                        ->orWhere('video_path', 'like', '%' . $q . '%');
                });
        });
    }

    private function presentMatch(ExternalVideoDuplicateMatch $match): array
    {
        $feature = $match->matchedFeature;
        $log = $match->latestComparisonLog;
        $matchedSnapshot = is_array($log?->matched_feature_json) ? $log->matched_feature_json : [];

        $frames = $log instanceof ExternalVideoDuplicateLog
            ? $this->presentFrameComparisons((array) $log->frame_comparisons_json)
            : $this->presentLegacyFrames($match);

        $dbDurationSeconds = $feature?->duration_seconds !== null
            ? (float) $feature->duration_seconds
            : (float) ($matchedSnapshot['duration_seconds'] ?? 0);
        $dbFileSize = $feature?->file_size_bytes !== null
            ? (int) $feature->file_size_bytes
            : (isset($matchedSnapshot['file_size_bytes']) ? (int) $matchedSnapshot['file_size_bytes'] : null);

        $status = $this->describeOperationStatus($log?->operation_status ?? 'match_moved');

        return [
            'type' => 'duplicate',
            'id' => (int) $match->id,
            'selectable' => true,
            'file_name' => (string) $match->file_name,
            'source_file_path' => (string) $match->source_file_path,
            'duplicate_file_path' => (string) $match->duplicate_file_path,
            'duplicate_directory_path' => (string) $match->duplicate_directory_path,
            'duplicate_file_exists' => (bool) $match->duplicate_file_exists,
            'external_stream_url' => route('videos.external-duplicates.stream', $match),
            'db_video_url' => $match->videoMaster !== null
                ? $this->buildVideoAssetUrl((string) $match->videoMaster->video_path)
                : null,
            'db_video_page_url' => $match->videoMaster !== null
                ? route('video.index', ['focus_id' => $match->videoMaster->id])
                : null,
            'db_video_name' => $match->videoMaster?->video_name ?? ($matchedSnapshot['video_name'] ?? '-'),
            'db_video_id' => $match->videoMaster?->id ?? ($matchedSnapshot['video_master_id'] ?? null),
            'db_video_path' => $match->videoMaster?->video_path ?? ($matchedSnapshot['video_path'] ?? '-'),
            'duration_hms' => $match->duration_hms,
            'file_size_human' => $match->file_size_human,
            'file_created_at_human' => $match->file_created_at_human,
            'file_modified_at_human' => $match->file_modified_at_human,
            'threshold_percent' => (int) $match->threshold_percent,
            'requested_min_match' => $log?->requested_min_match ?? (int) $match->min_match_required,
            'required_matches' => $log?->required_matches,
            'similarity_percent' => (float) $match->similarity_percent,
            'matched_frames' => (int) $match->matched_frames,
            'compared_frames' => (int) $match->compared_frames,
            'duration_delta_seconds' => $match->duration_delta_seconds !== null ? (float) $match->duration_delta_seconds : null,
            'file_size_delta_bytes' => $match->file_size_delta_bytes !== null ? (int) $match->file_size_delta_bytes : null,
            'db_duration_hms' => $dbDurationSeconds > 0 ? $this->formatDuration($dbDurationSeconds) : '-',
            'db_file_size_human' => $dbFileSize !== null ? $this->formatBytes($dbFileSize) : '-',
            'frames' => $frames,
            'status_label' => $match->duplicate_file_exists ? '已搬移待審核' : '檔案已不存在',
            'status_tone' => $match->duplicate_file_exists ? 'good' : 'bad',
            'operation_status' => $log?->operation_status ?? 'match_moved',
            'operation_label' => $status['label'],
            'operation_tone' => $status['tone'],
            'operation_message' => $log?->operation_message,
            'created_at_human' => optional($match->created_at)?->format('Y-m-d H:i:s') ?? '-',
            'candidate_count' => $log?->candidate_count,
        ];
    }

    private function presentLog(ExternalVideoDuplicateLog $log): array
    {
        $feature = $log->matchedFeature;
        $matchedSnapshot = is_array($log->matched_feature_json) ? $log->matched_feature_json : [];
        $match = $log->match;
        $status = $this->describeOperationStatus((string) $log->operation_status);

        $dbDurationSeconds = $feature?->duration_seconds !== null
            ? (float) $feature->duration_seconds
            : (float) ($matchedSnapshot['duration_seconds'] ?? 0);
        $dbFileSize = $feature?->file_size_bytes !== null
            ? (int) $feature->file_size_bytes
            : (isset($matchedSnapshot['file_size_bytes']) ? (int) $matchedSnapshot['file_size_bytes'] : null);

        $duplicateFilePath = (string) ($log->duplicate_file_path ?? '');
        $duplicateFileExists = $duplicateFilePath !== '' && is_file($duplicateFilePath);

        return [
            'type' => 'log',
            'id' => (int) $log->id,
            'selectable' => false,
            'file_name' => (string) $log->file_name,
            'source_file_path' => (string) $log->source_file_path,
            'duplicate_file_path' => $duplicateFilePath,
            'duplicate_directory_path' => $duplicateFilePath !== '' ? dirname($duplicateFilePath) : '-',
            'duplicate_file_exists' => $duplicateFileExists,
            'external_stream_url' => $match instanceof ExternalVideoDuplicateMatch ? route('videos.external-duplicates.stream', $match) : null,
            'db_video_url' => $log->videoMaster !== null
                ? $this->buildVideoAssetUrl((string) $log->videoMaster->video_path)
                : null,
            'db_video_page_url' => $log->videoMaster !== null
                ? route('video.index', ['focus_id' => $log->videoMaster->id])
                : null,
            'db_video_name' => $log->videoMaster?->video_name ?? ($matchedSnapshot['video_name'] ?? '-'),
            'db_video_id' => $log->videoMaster?->id ?? ($matchedSnapshot['video_master_id'] ?? null),
            'db_video_path' => $log->videoMaster?->video_path ?? ($matchedSnapshot['video_path'] ?? '-'),
            'duration_hms' => $log->duration_hms,
            'file_size_human' => $log->file_size_human,
            'file_created_at_human' => $log->file_created_at_human,
            'file_modified_at_human' => $log->file_modified_at_human,
            'threshold_percent' => (int) $log->threshold_percent,
            'requested_min_match' => (int) $log->requested_min_match,
            'required_matches' => $log->required_matches,
            'similarity_percent' => $log->similarity_percent !== null ? (float) $log->similarity_percent : null,
            'matched_frames' => (int) $log->matched_frames,
            'compared_frames' => (int) $log->compared_frames,
            'duration_delta_seconds' => $log->duration_delta_seconds !== null ? (float) $log->duration_delta_seconds : null,
            'file_size_delta_bytes' => $log->file_size_delta_bytes !== null ? (int) $log->file_size_delta_bytes : null,
            'db_duration_hms' => $dbDurationSeconds > 0 ? $this->formatDuration($dbDurationSeconds) : '-',
            'db_file_size_human' => $dbFileSize !== null ? $this->formatBytes($dbFileSize) : '-',
            'frames' => $this->presentFrameComparisons((array) $log->frame_comparisons_json),
            'status_label' => $status['label'],
            'status_tone' => $status['tone'],
            'operation_status' => (string) $log->operation_status,
            'operation_label' => $status['label'],
            'operation_tone' => $status['tone'],
            'operation_message' => $log->operation_message,
            'created_at_human' => optional($log->created_at)?->format('Y-m-d H:i:s') ?? '-',
            'candidate_count' => (int) $log->candidate_count,
            'is_duplicate_detected' => (bool) $log->is_duplicate_detected,
        ];
    }

    private function presentFrameComparisons(array $frameComparisons): array
    {
        $items = [];

        foreach ($frameComparisons as $frameComparison) {
            if (!is_array($frameComparison)) {
                continue;
            }

            $source = is_array($frameComparison['source'] ?? null) ? $frameComparison['source'] : [];
            $matched = is_array($frameComparison['matched'] ?? null) ? $frameComparison['matched'] : [];
            $similarity = isset($frameComparison['similarity_percent']) ? (float) $frameComparison['similarity_percent'] : null;

            $items[] = [
                'capture_order' => (int) ($frameComparison['capture_order'] ?? 0),
                'source_image_src' => $this->buildDataImageUrl(
                    $source['image_mime'] ?? null,
                    $source['image_base64'] ?? null
                ),
                'db_image_src' => $this->buildDataImageUrl(
                    $matched['image_mime'] ?? null,
                    $matched['image_base64'] ?? null
                ),
                'source_capture_second' => isset($source['capture_second']) ? (float) $source['capture_second'] : null,
                'db_capture_second' => isset($matched['capture_second']) ? (float) $matched['capture_second'] : null,
                'source_dhash_hex' => (string) ($source['dhash_hex'] ?? ''),
                'db_dhash_hex' => (string) ($matched['dhash_hex'] ?? ''),
                'similarity_percent' => $similarity,
                'is_threshold_match' => (bool) ($frameComparison['is_threshold_match'] ?? false),
                'tone' => $this->resolveSimilarityTone($similarity),
            ];
        }

        return $items;
    }

    private function presentLegacyFrames(ExternalVideoDuplicateMatch $match): array
    {
        /** @var Collection<int, VideoFeatureFrame> $featureFramesByOrder */
        $featureFramesByOrder = $match->matchedFeature !== null
            ? $match->matchedFeature->frames->keyBy('capture_order')
            : collect();

        $items = [];

        foreach ($match->frames as $frame) {
            $comparisonFrame = $frame->matchedVideoFeatureFrame;
            if (!$comparisonFrame instanceof VideoFeatureFrame) {
                $comparisonFrame = $featureFramesByOrder->get((int) $frame->capture_order);
            }

            $similarity = $frame->similarity_percent !== null ? (float) $frame->similarity_percent : null;

            $items[] = [
                'capture_order' => (int) $frame->capture_order,
                'source_image_src' => $this->buildPublicAssetUrl((string) $frame->screenshot_path),
                'db_image_src' => $comparisonFrame instanceof VideoFeatureFrame
                    ? $this->buildVideoAssetUrl((string) $comparisonFrame->screenshot_path)
                    : null,
                'source_capture_second' => $frame->capture_second !== null ? (float) $frame->capture_second : null,
                'db_capture_second' => $comparisonFrame instanceof VideoFeatureFrame && $comparisonFrame->capture_second !== null
                    ? (float) $comparisonFrame->capture_second
                    : null,
                'source_dhash_hex' => (string) ($frame->dhash_hex ?? ''),
                'db_dhash_hex' => $comparisonFrame instanceof VideoFeatureFrame ? (string) ($comparisonFrame->dhash_hex ?? '') : '',
                'similarity_percent' => $similarity,
                'is_threshold_match' => (bool) $frame->is_threshold_match,
                'tone' => $this->resolveSimilarityTone($similarity),
            ];
        }

        return $items;
    }

    private function buildVideoAssetUrl(string $relativePath): ?string
    {
        $baseUrl = trim((string) config('app.video_base_url', ''));
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');

        if ($baseUrl === '' || $relativePath === '') {
            return null;
        }

        $segments = array_map('rawurlencode', explode('/', $relativePath));

        return rtrim($baseUrl, '/') . '/' . implode('/', $segments);
    }

    private function buildPublicAssetUrl(string $relativePath): ?string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($relativePath === '') {
            return null;
        }

        return Storage::disk('public')->url($relativePath);
    }

    private function buildDataImageUrl(?string $mime, ?string $base64): ?string
    {
        $mime = trim((string) $mime);
        $base64 = trim((string) $base64);

        if ($mime === '' || $base64 === '') {
            return null;
        }

        return 'data:' . $mime . ';base64,' . $base64;
    }

    private function resolveSimilarityTone(?float $similarityPercent): string
    {
        $similarityPercent = (float) ($similarityPercent ?? 0);

        if ($similarityPercent >= 95) {
            return 'excellent';
        }

        if ($similarityPercent >= 90) {
            return 'good';
        }

        if ($similarityPercent >= 80) {
            return 'warn';
        }

        if ($similarityPercent > 0) {
            return 'soft';
        }

        return 'bad';
    }

    private function describeOperationStatus(string $status): array
    {
        return match ($status) {
            'match_moved' => ['label' => '命中後已搬移', 'tone' => 'good'],
            'same_path_skipped' => ['label' => '同一路徑略過', 'tone' => 'soft'],
            'dry_run_match' => ['label' => 'dry-run 命中', 'tone' => 'warn'],
            'manual_same_path' => ['label' => '手動分析: 同一路徑', 'tone' => 'soft'],
            'manual_gate_pass' => ['label' => '手動分析: 正式可命中', 'tone' => 'good'],
            'manual_size_block' => ['label' => '手動分析: size gate 擋掉', 'tone' => 'warn'],
            'manual_gate_block' => ['label' => '手動分析: 候選 gate 擋掉', 'tone' => 'warn'],
            'manual_compare_fail' => ['label' => '手動分析: 比對未過', 'tone' => 'soft'],
            'manual_gate_and_compare_fail' => ['label' => '手動分析: gate+比對未過', 'tone' => 'soft'],
            'manual_no_frames' => ['label' => '手動分析: 無可比 frame', 'tone' => 'soft'],
            'manual_feature_debug' => ['label' => '手動分析', 'tone' => 'soft'],
            'error' => ['label' => '執行錯誤', 'tone' => 'bad'],
            'no_match' => ['label' => '未達門檻', 'tone' => 'soft'],
            default => ['label' => $status !== '' ? $status : '未知狀態', 'tone' => 'soft'],
        };
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
    }

    private function formatBytes(?int $bytes): string
    {
        $bytes = max(0, (int) ($bytes ?? 0));
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $size = (float) $bytes;

        while ($size >= 1024.0 && $i < count($units) - 1) {
            $size /= 1024.0;
            $i++;
        }

        return $i === 0
            ? $bytes . ' ' . $units[$i]
            : number_format($size, 2) . ' ' . $units[$i];
    }

    private function isPathInsideDuplicateDirectory(
        ExternalVideoDuplicateMatch $match,
        ExternalVideoDuplicateService $service
    ): bool {
        $directoryPath = $service->normalizeAbsolutePath((string) $match->duplicate_directory_path);
        $filePath = $service->normalizeAbsolutePath((string) $match->duplicate_file_path);

        if ($directoryPath === '' || $filePath === '') {
            return false;
        }

        $directoryName = basename(str_replace('\\', '/', $directoryPath));
        if ($directoryName !== '疑似重複檔案') {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $normalizedDir = rtrim(strtolower(str_replace('/', '\\', $directoryPath)), '\\');
            $normalizedFile = strtolower(str_replace('/', '\\', $filePath));

            return str_starts_with($normalizedFile, $normalizedDir . '\\');
        }

        $normalizedDir = rtrim(str_replace('\\', '/', $directoryPath), '/');
        $normalizedFile = str_replace('\\', '/', $filePath);

        return str_starts_with($normalizedFile, $normalizedDir . '/');
    }
}
