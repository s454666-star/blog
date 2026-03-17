<?php

namespace App\Services;

use App\Models\ExternalVideoDuplicateLog;
use App\Models\ExternalVideoDuplicateMatch;
use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ExternalVideoDuplicateService
{
    public function persistComparisonLog(
        array $payload,
        ?array $analysis,
        string $sourceFilePath,
        array $options = []
    ): ExternalVideoDuplicateLog {
        $analysis = is_array($analysis) ? $analysis : [];
        $bestResult = $analysis['best_result'] ?? null;
        $duplicateMatch = $analysis['duplicate_match'] ?? null;

        if (!is_array($bestResult)) {
            $bestResult = null;
        }

        if (!is_array($duplicateMatch)) {
            $duplicateMatch = null;
        }

        $bestResultData = is_array($bestResult) ? $bestResult : [];

        /** @var VideoFeature|null $feature */
        $feature = $bestResultData['feature'] ?? null;
        if ($feature instanceof VideoFeature) {
            $feature->loadMissing('frames', 'videoMaster');
        } else {
            $feature = null;
        }

        $sourceFilePath = $this->normalizeAbsolutePath($sourceFilePath !== '' ? $sourceFilePath : (string) ($payload['absolute_path'] ?? ''));
        $duplicateFilePath = $this->normalizeAbsolutePath((string) ($options['duplicate_file_path'] ?? ''));

        return ExternalVideoDuplicateLog::query()->create([
            'external_video_duplicate_match_id' => $options['external_video_duplicate_match_id'] ?? null,
            'video_master_id' => $feature?->video_master_id,
            'matched_video_feature_id' => $feature?->id,
            'scan_root_path' => $this->normalizeAbsolutePath((string) ($options['scan_root_path'] ?? '')),
            'source_directory_path' => $sourceFilePath !== '' ? dirname($sourceFilePath) : null,
            'source_file_path' => $sourceFilePath,
            'source_path_sha1' => $sourceFilePath !== '' ? $this->hashPath($sourceFilePath) : null,
            'duplicate_file_path' => $duplicateFilePath !== '' ? $duplicateFilePath : null,
            'duplicate_path_sha1' => $duplicateFilePath !== '' ? $this->hashPath($duplicateFilePath) : null,
            'file_name' => (string) ($payload['file_name'] ?? basename($sourceFilePath)),
            'file_size_bytes' => $payload['file_size_bytes'] ?? null,
            'duration_seconds' => $payload['duration_seconds'] ?? 0,
            'file_created_at' => $payload['file_created_at'] ?? null,
            'file_modified_at' => $payload['file_modified_at'] ?? null,
            'screenshot_count' => count((array) ($payload['frames'] ?? [])),
            'feature_version' => (string) ($payload['feature_version'] ?? 'v1'),
            'capture_rule' => (string) ($payload['capture_rule'] ?? '10s_x4'),
            'threshold_percent' => (int) ($options['threshold_percent'] ?? 90),
            'requested_min_match' => (int) ($analysis['requested_min_match'] ?? $options['min_match_required'] ?? 2),
            'required_matches' => $bestResultData['required_matches'] ?? null,
            'window_seconds' => (int) ($options['window_seconds'] ?? 3),
            'size_percent' => (int) ($options['size_percent'] ?? 15),
            'max_candidates' => (int) ($options['max_candidates'] ?? 250),
            'candidate_count' => (int) ($analysis['candidate_count'] ?? 0),
            'similarity_percent' => $bestResultData['similarity_percent'] ?? null,
            'matched_frames' => (int) ($bestResultData['matched_frames'] ?? 0),
            'compared_frames' => (int) ($bestResultData['compared_frames'] ?? 0),
            'duration_delta_seconds' => $bestResultData['duration_delta_seconds'] ?? null,
            'file_size_delta_bytes' => $bestResultData['file_size_delta_bytes'] ?? null,
            'is_duplicate_detected' => (bool) ($options['is_duplicate_detected'] ?? ($duplicateMatch !== null)),
            'operation_status' => (string) ($options['operation_status'] ?? ($duplicateMatch !== null ? 'match_detected' : 'no_match')),
            'operation_message' => $options['operation_message'] ?? null,
            'source_feature_json' => $this->serializeSourceFeaturePayload($payload),
            'matched_feature_json' => $this->serializeMatchedFeature($feature),
            'frame_comparisons_json' => $this->buildFrameComparisons($payload, $bestResult),
        ]);
    }

    public function persistMatchResult(
        array $payload,
        array $match,
        string $sourceFilePath,
        string $duplicateFilePath,
        array $options = []
    ): ExternalVideoDuplicateMatch {
        /** @var VideoFeature|null $feature */
        $feature = $match['feature'] ?? null;
        if (!$feature instanceof VideoFeature) {
            throw new RuntimeException('match 缺少有效的 VideoFeature。');
        }

        $feature->loadMissing('frames', 'videoMaster');

        $duplicateFilePath = $this->normalizeAbsolutePath($duplicateFilePath);
        $sourceFilePath = $this->normalizeAbsolutePath($sourceFilePath);
        if ($duplicateFilePath === '') {
            throw new RuntimeException('duplicateFilePath 不可為空。');
        }

        $record = ExternalVideoDuplicateMatch::query()->firstOrNew([
            'duplicate_path_sha1' => $this->hashPath($duplicateFilePath),
        ]);

        $oldScreenshotPaths = $record->exists
            ? $record->frames()->pluck('screenshot_path')->all()
            : [];

        DB::transaction(function () use (
            $payload,
            $match,
            $feature,
            $sourceFilePath,
            $duplicateFilePath,
            $options,
            $record
        ): void {
            if ($record->exists) {
                $record->frames()->delete();
            }

            $record->fill([
                'video_master_id' => $feature->video_master_id,
                'matched_video_feature_id' => $feature->id,
                'scan_root_path' => $this->normalizeAbsolutePath((string) ($options['scan_root_path'] ?? '')),
                'duplicate_directory_path' => $this->normalizeAbsolutePath((string) ($options['duplicate_directory_path'] ?? dirname($duplicateFilePath))),
                'source_directory_path' => $sourceFilePath !== '' ? dirname($sourceFilePath) : null,
                'source_file_path' => $sourceFilePath,
                'source_path_sha1' => $sourceFilePath !== '' ? $this->hashPath($sourceFilePath) : null,
                'duplicate_file_path' => $duplicateFilePath,
                'duplicate_path_sha1' => $this->hashPath($duplicateFilePath),
                'file_name' => (string) ($payload['file_name'] ?? basename($duplicateFilePath)),
                'file_size_bytes' => $payload['file_size_bytes'] ?? null,
                'duration_seconds' => $payload['duration_seconds'] ?? 0,
                'file_created_at' => $payload['file_created_at'] ?? null,
                'file_modified_at' => $payload['file_modified_at'] ?? null,
                'screenshot_count' => count((array) ($payload['frames'] ?? [])),
                'feature_version' => (string) ($payload['feature_version'] ?? 'v1'),
                'capture_rule' => (string) ($payload['capture_rule'] ?? '10s_x4'),
                'threshold_percent' => (int) ($options['threshold_percent'] ?? 90),
                'min_match_required' => (int) ($options['min_match_required'] ?? 2),
                'window_seconds' => (int) ($options['window_seconds'] ?? 3),
                'size_percent' => (int) ($options['size_percent'] ?? 15),
                'similarity_percent' => $match['similarity_percent'] ?? 0,
                'matched_frames' => (int) ($match['matched_frames'] ?? 0),
                'compared_frames' => (int) ($match['compared_frames'] ?? 0),
                'duration_delta_seconds' => $match['duration_delta_seconds'] ?? null,
                'file_size_delta_bytes' => $match['file_size_delta_bytes'] ?? null,
            ]);
            $record->save();
        });

        foreach ($oldScreenshotPaths as $oldScreenshotPath) {
            if (is_string($oldScreenshotPath) && $oldScreenshotPath !== '') {
                $this->deletePublicScreenshot($oldScreenshotPath);
            }
        }

        return ExternalVideoDuplicateMatch::query()
            ->with([
                'latestComparisonLog',
                'matchedFeature.frames',
                'videoMaster',
            ])
            ->findOrFail($record->id);
    }

    public function deleteRecord(ExternalVideoDuplicateMatch $record): array
    {
        $duplicateFilePath = $this->normalizeAbsolutePath((string) $record->duplicate_file_path);
        $fileDeleted = false;
        $fileError = null;

        if ($duplicateFilePath === '') {
            $fileError = 'duplicate_file_path 為空，僅刪除資料列。';
        } elseif (is_file($duplicateFilePath)) {
            if (!@unlink($duplicateFilePath)) {
                return [
                    'ok' => false,
                    'file_deleted' => false,
                    'message' => '刪除外部重複影片失敗，檔案可能被占用：' . $duplicateFilePath,
                ];
            }

            $fileDeleted = true;
        } else {
            $fileError = '檔案不存在，改為清理資料列。';
        }

        $this->purgeRecord($record);

        return [
            'ok' => true,
            'file_deleted' => $fileDeleted,
            'message' => $fileError,
        ];
    }

    public function dismissRecord(ExternalVideoDuplicateMatch $record): array
    {
        $this->purgeRecord($record);

        return [
            'ok' => true,
            'file_deleted' => false,
            'message' => null,
        ];
    }

    public function normalizeAbsolutePath(string $path): string
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

    public function hashPath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        return sha1(mb_strtolower($normalized));
    }

    public function deletePublicScreenshot(string $relativePath): void
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($relativePath === '') {
            return;
        }

        $absolutePath = Storage::disk('public')->path($relativePath);
        if (File::exists($absolutePath)) {
            File::delete($absolutePath);
        }
    }

    private function purgeRecord(ExternalVideoDuplicateMatch $record): void
    {
        $screenshotPaths = $record->frames()->pluck('screenshot_path')->all();

        DB::transaction(function () use ($record): void {
            $record->comparisonLogs()->delete();
            $record->delete();
        });

        foreach ($screenshotPaths as $screenshotPath) {
            if (is_string($screenshotPath) && $screenshotPath !== '') {
                $this->deletePublicScreenshot($screenshotPath);
            }
        }
    }

    private function serializeSourceFeaturePayload(array $payload): array
    {
        $frames = [];

        foreach ((array) ($payload['frames'] ?? []) as $frame) {
            $frames[] = [
                'capture_order' => (int) ($frame['capture_order'] ?? 0),
                'label_second' => isset($frame['label_second']) ? (float) $frame['label_second'] : null,
                'capture_second' => isset($frame['capture_second']) ? (float) $frame['capture_second'] : null,
                'suggested_filename' => (string) ($frame['suggested_filename'] ?? ''),
                'dhash_hex' => (string) ($frame['dhash_hex'] ?? ''),
                'dhash_prefix' => (string) ($frame['dhash_prefix'] ?? ''),
                'frame_sha1' => $frame['frame_sha1'] ?? null,
                'image_width' => $frame['image_width'] ?? null,
                'image_height' => $frame['image_height'] ?? null,
            ];
        }

        return [
            'absolute_path' => $this->normalizeAbsolutePath((string) ($payload['absolute_path'] ?? '')),
            'video_name' => (string) ($payload['video_name'] ?? ''),
            'file_name' => (string) ($payload['file_name'] ?? ''),
            'file_size_bytes' => $payload['file_size_bytes'] ?? null,
            'duration_seconds' => isset($payload['duration_seconds']) ? (float) $payload['duration_seconds'] : null,
            'capture_rule' => (string) ($payload['capture_rule'] ?? ''),
            'feature_version' => (string) ($payload['feature_version'] ?? ''),
            'frames' => $frames,
        ];
    }

    private function serializeMatchedFeature(?VideoFeature $feature): ?array
    {
        if (!$feature instanceof VideoFeature) {
            return null;
        }

        $feature->loadMissing('frames', 'videoMaster');

        return [
            'video_feature_id' => (int) $feature->id,
            'video_master_id' => $feature->video_master_id !== null ? (int) $feature->video_master_id : null,
            'video_name' => (string) ($feature->video_name ?? ''),
            'video_path' => (string) ($feature->video_path ?? ''),
            'file_name' => (string) ($feature->file_name ?? ''),
            'file_size_bytes' => $feature->file_size_bytes !== null ? (int) $feature->file_size_bytes : null,
            'duration_seconds' => $feature->duration_seconds !== null ? (float) $feature->duration_seconds : null,
            'screenshot_count' => $feature->screenshot_count !== null ? (int) $feature->screenshot_count : null,
            'capture_rule' => (string) ($feature->capture_rule ?? ''),
            'feature_version' => (string) ($feature->feature_version ?? ''),
            'frames' => $feature->frames->map(function (VideoFeatureFrame $frame): array {
                return [
                    'id' => (int) $frame->id,
                    'capture_order' => (int) $frame->capture_order,
                    'capture_second' => $frame->capture_second !== null ? (float) $frame->capture_second : null,
                    'screenshot_path' => (string) ($frame->screenshot_path ?? ''),
                    'dhash_hex' => (string) ($frame->dhash_hex ?? ''),
                    'dhash_prefix' => (string) ($frame->dhash_prefix ?? ''),
                    'frame_sha1' => $frame->frame_sha1,
                    'image_width' => $frame->image_width !== null ? (int) $frame->image_width : null,
                    'image_height' => $frame->image_height !== null ? (int) $frame->image_height : null,
                ];
            })->values()->all(),
        ];
    }

    private function buildFrameComparisons(array $payload, ?array $bestResult): array
    {
        $bestResult = is_array($bestResult) ? $bestResult : [];
        $frameMatchesByOrder = [];
        foreach ((array) ($bestResult['frame_matches'] ?? []) as $frameMatch) {
            $captureOrder = (int) ($frameMatch['capture_order'] ?? 0);
            if ($captureOrder > 0) {
                $frameMatchesByOrder[$captureOrder] = $frameMatch;
            }
        }

        /** @var VideoFeature|null $feature */
        $feature = $bestResult['feature'] ?? null;
        if ($feature instanceof VideoFeature) {
            $feature->loadMissing('frames');
        } else {
            $feature = null;
        }

        $featureFramesByOrder = [];
        if ($feature instanceof VideoFeature) {
            foreach ($feature->frames as $featureFrame) {
                $featureFramesByOrder[(int) $featureFrame->capture_order] = $featureFrame;
            }
        }

        $comparisons = [];

        foreach ((array) ($payload['frames'] ?? []) as $frame) {
            $captureOrder = (int) ($frame['capture_order'] ?? 0);
            if ($captureOrder <= 0) {
                continue;
            }

            $frameMatch = $frameMatchesByOrder[$captureOrder] ?? [];
            $matchedFrame = null;

            if (isset($frameMatch['matched_video_feature_frame_id'])) {
                foreach ($featureFramesByOrder as $featureFrame) {
                    if ((int) $featureFrame->id === (int) $frameMatch['matched_video_feature_frame_id']) {
                        $matchedFrame = $featureFrame;
                        break;
                    }
                }
            }

            if (!$matchedFrame instanceof VideoFeatureFrame) {
                $matchedFrame = $featureFramesByOrder[$captureOrder] ?? null;
            }

            $sourceImage = $this->encodeImagePayload((string) ($frame['temp_path'] ?? ''));
            $matchedImage = $matchedFrame instanceof VideoFeatureFrame
                ? $this->encodeImagePayload($this->resolveVideoScreenshotAbsolutePath((string) $matchedFrame->screenshot_path))
                : null;

            $comparisons[] = [
                'capture_order' => $captureOrder,
                'similarity_percent' => $frameMatch['similarity_percent'] ?? null,
                'is_threshold_match' => (bool) ($frameMatch['is_threshold_match'] ?? false),
                'source' => [
                    'capture_second' => isset($frame['capture_second']) ? (float) $frame['capture_second'] : null,
                    'label_second' => isset($frame['label_second']) ? (float) $frame['label_second'] : null,
                    'dhash_hex' => (string) ($frame['dhash_hex'] ?? ''),
                    'dhash_prefix' => (string) ($frame['dhash_prefix'] ?? ''),
                    'frame_sha1' => $frame['frame_sha1'] ?? null,
                    'image_width' => $frame['image_width'] ?? null,
                    'image_height' => $frame['image_height'] ?? null,
                    'image_mime' => $sourceImage['mime'] ?? null,
                    'image_base64' => $sourceImage['base64'] ?? null,
                ],
                'matched' => $matchedFrame instanceof VideoFeatureFrame ? [
                    'video_feature_frame_id' => (int) $matchedFrame->id,
                    'capture_second' => $matchedFrame->capture_second !== null ? (float) $matchedFrame->capture_second : null,
                    'screenshot_path' => (string) ($matchedFrame->screenshot_path ?? ''),
                    'dhash_hex' => (string) ($matchedFrame->dhash_hex ?? ''),
                    'dhash_prefix' => (string) ($matchedFrame->dhash_prefix ?? ''),
                    'frame_sha1' => $matchedFrame->frame_sha1,
                    'image_width' => $matchedFrame->image_width !== null ? (int) $matchedFrame->image_width : null,
                    'image_height' => $matchedFrame->image_height !== null ? (int) $matchedFrame->image_height : null,
                    'image_mime' => $matchedImage['mime'] ?? null,
                    'image_base64' => $matchedImage['base64'] ?? null,
                ] : null,
            ];
        }

        return $comparisons;
    }

    private function resolveVideoScreenshotAbsolutePath(string $dbPath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', trim($dbPath)), '/');
        if ($relativePath === '') {
            return '';
        }

        return $this->normalizeAbsolutePath(Storage::disk('videos')->path($relativePath));
    }

    private function encodeImagePayload(string $absolutePath): ?array
    {
        $absolutePath = $this->normalizeAbsolutePath($absolutePath);
        if ($absolutePath === '' || !is_file($absolutePath)) {
            return null;
        }

        $binary = @file_get_contents($absolutePath);
        if (!is_string($binary) || $binary === '') {
            return null;
        }

        $mime = @mime_content_type($absolutePath) ?: 'image/jpeg';

        return [
            'mime' => $mime,
            'base64' => base64_encode($binary),
        ];
    }
}
