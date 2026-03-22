<?php

namespace App\Services;

use App\Models\FolderVideoDuplicateBatch;
use App\Models\FolderVideoDuplicateFeature;
use App\Models\FolderVideoDuplicateFrame;
use App\Models\FolderVideoDuplicateMatch;
use Illuminate\Support\Facades\DB;

class FolderVideoDuplicateService
{
    public function __construct(
        private readonly VideoFeatureExtractionService $featureExtractionService
    ) {
    }

    public function createBatch(array $attributes): FolderVideoDuplicateBatch
    {
        return FolderVideoDuplicateBatch::query()->create($attributes);
    }

    public function analyzeBatchMatch(
        FolderVideoDuplicateBatch $batch,
        array $payload,
        int $thresholdPercent = 80,
        int $minMatch = 2,
        int $windowSeconds = 3,
        int $maxCandidates = 250
    ): array {
        $payloadContext = $this->buildPayloadContext($payload);

        if ($payloadContext['frame_count'] <= 0 || $payloadContext['prefixes'] === []) {
            return [
                'best_result' => null,
                'duplicate_match' => null,
                'candidate_count' => 0,
                'payload_frame_count' => $payloadContext['frame_count'],
                'requested_min_match' => max(1, $minMatch),
            ];
        }

        $candidateIds = $this->collectCandidateIds(
            $batch,
            $payloadContext,
            $windowSeconds,
            $maxCandidates
        );

        if ($candidateIds === []) {
            return [
                'best_result' => null,
                'duplicate_match' => null,
                'candidate_count' => 0,
                'payload_frame_count' => $payloadContext['frame_count'],
                'requested_min_match' => max(1, $minMatch),
            ];
        }

        $bestResult = null;
        $bestQualifiedResult = null;

        $candidates = FolderVideoDuplicateFeature::query()
            ->with('frames')
            ->whereIn('id', $candidateIds)
            ->get();

        foreach ($candidates as $candidate) {
            $candidateResult = $this->comparePayloadToFeature(
                $payloadContext['frames_by_order'],
                $candidate,
                $payloadContext['duration_seconds'],
                $payloadContext['file_size_bytes'],
                $thresholdPercent,
                $minMatch
            );

            if ($candidateResult === null) {
                continue;
            }

            if ($bestResult === null || $candidateResult['score'] > $bestResult['score']) {
                $bestResult = $candidateResult;
            }

            if (
                ($candidateResult['passes_threshold'] ?? false) &&
                ($bestQualifiedResult === null || $candidateResult['score'] > $bestQualifiedResult['score'])
            ) {
                $bestQualifiedResult = $candidateResult;
            }
        }

        return [
            'best_result' => $bestResult,
            'duplicate_match' => $bestQualifiedResult,
            'candidate_count' => count($candidateIds),
            'payload_frame_count' => $payloadContext['frame_count'],
            'requested_min_match' => max(1, $minMatch),
        ];
    }

    public function persistCanonicalFeature(
        FolderVideoDuplicateBatch $batch,
        array $payload
    ): FolderVideoDuplicateFeature {
        return DB::transaction(function () use ($batch, $payload): FolderVideoDuplicateFeature {
            return $this->createFeatureRecord($batch, $payload, true, null);
        });
    }

    public function persistDuplicateMatch(
        FolderVideoDuplicateBatch $batch,
        FolderVideoDuplicateFeature $keptFeature,
        array $payload,
        array $match,
        array $options = []
    ): FolderVideoDuplicateMatch {
        return DB::transaction(function () use ($batch, $keptFeature, $payload, $match, $options): FolderVideoDuplicateMatch {
            $duplicateFeature = $this->createFeatureRecord(
                $batch,
                $payload,
                false,
                $this->normalizeAbsolutePath((string) ($options['moved_to_path'] ?? ''))
            );

            return FolderVideoDuplicateMatch::query()->create([
                'folder_video_duplicate_batch_id' => $batch->id,
                'kept_feature_id' => $keptFeature->id,
                'duplicate_feature_id' => $duplicateFeature->id,
                'kept_file_path' => (string) $keptFeature->absolute_path,
                'duplicate_file_path' => $this->normalizeAbsolutePath((string) ($payload['absolute_path'] ?? '')),
                'duplicate_path_sha1' => $this->hashPath((string) ($payload['absolute_path'] ?? '')),
                'moved_to_path' => $this->normalizeAbsolutePath((string) ($options['moved_to_path'] ?? '')) ?: null,
                'similarity_percent' => $match['similarity_percent'] ?? 0,
                'matched_frames' => (int) ($match['matched_frames'] ?? 0),
                'compared_frames' => (int) ($match['compared_frames'] ?? 0),
                'required_matches' => (int) ($match['required_matches'] ?? 0),
                'duration_delta_seconds' => $match['duration_delta_seconds'] ?? null,
                'file_size_delta_bytes' => $match['file_size_delta_bytes'] ?? null,
                'frame_comparisons_json' => $this->buildFrameComparisons($payload, $match, $keptFeature),
                'operation_status' => (string) ($options['operation_status'] ?? 'match_moved'),
                'operation_message' => $options['operation_message'] ?? null,
            ]);
        });
    }

    public function cleanupBatch(FolderVideoDuplicateBatch $batch): void
    {
        DB::transaction(function () use ($batch): void {
            $featureIds = FolderVideoDuplicateFeature::query()
                ->where('folder_video_duplicate_batch_id', $batch->id)
                ->pluck('id')
                ->all();

            FolderVideoDuplicateMatch::query()
                ->where('folder_video_duplicate_batch_id', $batch->id)
                ->delete();

            if ($featureIds !== []) {
                FolderVideoDuplicateFrame::query()
                    ->whereIn('folder_video_duplicate_feature_id', $featureIds)
                    ->delete();
            }

            FolderVideoDuplicateFeature::query()
                ->where('folder_video_duplicate_batch_id', $batch->id)
                ->delete();

            $batch->delete();
        });
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

    private function createFeatureRecord(
        FolderVideoDuplicateBatch $batch,
        array $payload,
        bool $isCanonical,
        ?string $movedToDuplicatePath
    ): FolderVideoDuplicateFeature {
        $absolutePath = $this->normalizeAbsolutePath((string) ($payload['absolute_path'] ?? ''));
        $feature = FolderVideoDuplicateFeature::query()->create([
            'folder_video_duplicate_batch_id' => $batch->id,
            'absolute_path' => $absolutePath,
            'path_sha1' => $this->hashPath($absolutePath),
            'directory_path' => $absolutePath !== '' ? dirname($absolutePath) : null,
            'file_name' => (string) ($payload['file_name'] ?? basename($absolutePath)),
            'file_size_bytes' => $payload['file_size_bytes'] ?? null,
            'duration_seconds' => $payload['duration_seconds'] ?? 0,
            'file_created_at' => $payload['file_created_at'] ?? null,
            'file_modified_at' => $payload['file_modified_at'] ?? null,
            'screenshot_count' => count((array) ($payload['frames'] ?? [])),
            'feature_version' => (string) ($payload['feature_version'] ?? 'v1'),
            'capture_rule' => (string) ($payload['capture_rule'] ?? '10s_x4'),
            'is_canonical' => $isCanonical,
            'moved_to_duplicate_path' => $movedToDuplicatePath !== '' ? $movedToDuplicatePath : null,
            'extraction_status' => 'ready',
            'last_error' => null,
        ]);

        foreach ((array) ($payload['frames'] ?? []) as $frame) {
            FolderVideoDuplicateFrame::query()->create([
                'folder_video_duplicate_feature_id' => $feature->id,
                'capture_order' => (int) ($frame['capture_order'] ?? 0),
                'capture_second' => $frame['capture_second'] ?? 0,
                'dhash_hex' => (string) ($frame['dhash_hex'] ?? ''),
                'dhash_prefix' => (string) ($frame['dhash_prefix'] ?? ''),
                'frame_sha1' => $frame['frame_sha1'] ?? null,
                'image_width' => $frame['image_width'] ?? null,
                'image_height' => $frame['image_height'] ?? null,
            ]);
        }

        return $feature->fresh('frames');
    }

    private function buildPayloadContext(array $payload): array
    {
        $frames = $payload['frames'] ?? [];
        if (!is_array($frames)) {
            $frames = [];
        }

        $payloadFramesByOrder = [];
        $prefixes = [];

        foreach ($frames as $frame) {
            $captureOrder = (int) ($frame['capture_order'] ?? 0);
            if ($captureOrder > 0) {
                $payloadFramesByOrder[$captureOrder] = $frame;
            }

            $prefix = trim((string) ($frame['dhash_prefix'] ?? ''));
            if ($prefix !== '') {
                $prefixes[] = $prefix;
            }
        }

        return [
            'frames_by_order' => $payloadFramesByOrder,
            'frame_count' => count($payloadFramesByOrder),
            'prefixes' => array_values(array_unique($prefixes)),
            'duration_seconds' => (float) ($payload['duration_seconds'] ?? 0),
            'file_size_bytes' => (int) ($payload['file_size_bytes'] ?? 0),
        ];
    }

    private function collectCandidateIds(
        FolderVideoDuplicateBatch $batch,
        array $payloadContext,
        int $windowSeconds,
        int $maxCandidates
    ): array {
        $durationMin = max(0, $payloadContext['duration_seconds'] - max(0, $windowSeconds));
        $durationMax = $payloadContext['duration_seconds'] + max(0, $windowSeconds);

        if ($this->shouldBypassPrefixGate($payloadContext)) {
            return FolderVideoDuplicateFeature::query()
                ->where('folder_video_duplicate_batch_id', $batch->id)
                ->where('is_canonical', true)
                ->whereBetween('duration_seconds', [$durationMin, $durationMax])
                ->orderByRaw(
                    'ABS(CAST(folder_video_duplicate_features.duration_seconds AS DECIMAL(10,3)) - ?) ASC',
                    [$payloadContext['duration_seconds']]
                )
                ->limit(max(1, $maxCandidates))
                ->pluck('id')
                ->all();
        }

        return FolderVideoDuplicateFrame::query()
            ->select('folder_video_duplicate_frames.folder_video_duplicate_feature_id')
            ->join(
                'folder_video_duplicate_features',
                'folder_video_duplicate_features.id',
                '=',
                'folder_video_duplicate_frames.folder_video_duplicate_feature_id'
            )
            ->where('folder_video_duplicate_features.folder_video_duplicate_batch_id', $batch->id)
            ->where('folder_video_duplicate_features.is_canonical', true)
            ->whereIn('folder_video_duplicate_frames.dhash_prefix', $payloadContext['prefixes'])
            ->whereBetween('folder_video_duplicate_features.duration_seconds', [$durationMin, $durationMax])
            ->groupBy('folder_video_duplicate_frames.folder_video_duplicate_feature_id')
            ->orderByRaw('COUNT(*) DESC')
            ->orderByRaw(
                'ABS(CAST(folder_video_duplicate_features.duration_seconds AS DECIMAL(10,3)) - ?) ASC',
                [$payloadContext['duration_seconds']]
            )
            ->limit(max(1, $maxCandidates))
            ->pluck('folder_video_duplicate_frames.folder_video_duplicate_feature_id')
            ->all();
    }

    private function comparePayloadToFeature(
        array $payloadFramesByOrder,
        FolderVideoDuplicateFeature $feature,
        float $payloadDuration,
        int $payloadFileSize,
        int $thresholdPercent,
        int $minMatch
    ): ?array {
        $comparedFrames = 0;
        $matchedFrames = 0;
        $similarities = [];
        $frameMatches = [];

        foreach ($feature->frames as $candidateFrame) {
            $captureOrder = (int) $candidateFrame->capture_order;
            if (!isset($payloadFramesByOrder[$captureOrder])) {
                continue;
            }

            $payloadFrame = $payloadFramesByOrder[$captureOrder];
            $payloadHash = (string) ($payloadFrame['dhash_hex'] ?? '');
            $candidateHash = (string) ($candidateFrame->dhash_hex ?? '');

            if (
                !$this->featureExtractionService->isValidDhash($payloadHash) ||
                !$this->featureExtractionService->isValidDhash($candidateHash)
            ) {
                continue;
            }

            $similarity = $this->featureExtractionService->hashSimilarityPercent($payloadHash, $candidateHash);

            $comparedFrames++;
            $similarities[] = $similarity;

            $frameMatches[] = [
                'capture_order' => $captureOrder,
                'capture_second' => (float) ($payloadFrame['capture_second'] ?? 0),
                'matched_folder_video_duplicate_frame_id' => $candidateFrame->id,
                'matched_capture_second' => (float) ($candidateFrame->capture_second ?? 0),
                'payload_dhash_hex' => $payloadHash,
                'matched_dhash_hex' => $candidateHash,
                'payload_frame_sha1' => $payloadFrame['frame_sha1'] ?? null,
                'matched_frame_sha1' => $candidateFrame->frame_sha1,
                'similarity_percent' => $similarity,
                'is_threshold_match' => $similarity >= $thresholdPercent,
            ];

            if ($similarity >= $thresholdPercent) {
                $matchedFrames++;
            }
        }

        if ($comparedFrames <= 0) {
            return null;
        }

        $requiredMatches = min(max(1, $minMatch), $comparedFrames);
        $avgSimilarity = array_sum($similarities) / max(1, count($similarities));
        $durationDelta = abs((float) $feature->duration_seconds - $payloadDuration);
        $fileSizeDelta = $payloadFileSize > 0 && $feature->file_size_bytes !== null
            ? abs((int) $feature->file_size_bytes - $payloadFileSize)
            : null;

        return [
            'feature' => $feature,
            'similarity_percent' => round($avgSimilarity, 2),
            'matched_frames' => $matchedFrames,
            'compared_frames' => $comparedFrames,
            'required_matches' => $requiredMatches,
            'passes_threshold' => $matchedFrames >= $requiredMatches,
            'frame_matches' => $frameMatches,
            'score' => ($matchedFrames * 1000) + $avgSimilarity,
            'duration_delta_seconds' => $durationDelta,
            'file_size_delta_bytes' => $fileSizeDelta,
        ];
    }

    private function shouldBypassPrefixGate(array $payloadContext): bool
    {
        return (int) ($payloadContext['frame_count'] ?? 0) === 1
            && !empty($payloadContext['prefixes']);
    }

    private function buildFrameComparisons(
        array $payload,
        array $match,
        FolderVideoDuplicateFeature $keptFeature
    ): array {
        $frameMatchesByOrder = [];
        foreach ((array) ($match['frame_matches'] ?? []) as $frameMatch) {
            $captureOrder = (int) ($frameMatch['capture_order'] ?? 0);
            if ($captureOrder > 0) {
                $frameMatchesByOrder[$captureOrder] = $frameMatch;
            }
        }

        $featureFramesByOrder = [];
        foreach ($keptFeature->frames as $featureFrame) {
            $featureFramesByOrder[(int) $featureFrame->capture_order] = $featureFrame;
        }

        $comparisons = [];
        foreach ((array) ($payload['frames'] ?? []) as $frame) {
            $captureOrder = (int) ($frame['capture_order'] ?? 0);
            if ($captureOrder <= 0) {
                continue;
            }

            $frameMatch = $frameMatchesByOrder[$captureOrder] ?? [];
            $matchedFrame = $featureFramesByOrder[$captureOrder] ?? null;

            $comparisons[] = [
                'capture_order' => $captureOrder,
                'source' => [
                    'capture_second' => isset($frame['capture_second']) ? (float) $frame['capture_second'] : null,
                    'dhash_hex' => (string) ($frame['dhash_hex'] ?? ''),
                    'frame_sha1' => $frame['frame_sha1'] ?? null,
                    'image_width' => $frame['image_width'] ?? null,
                    'image_height' => $frame['image_height'] ?? null,
                ],
                'matched' => $matchedFrame instanceof FolderVideoDuplicateFrame
                    ? [
                        'capture_second' => $matchedFrame->capture_second !== null ? (float) $matchedFrame->capture_second : null,
                        'dhash_hex' => (string) ($matchedFrame->dhash_hex ?? ''),
                        'frame_sha1' => $matchedFrame->frame_sha1,
                        'image_width' => $matchedFrame->image_width !== null ? (int) $matchedFrame->image_width : null,
                        'image_height' => $matchedFrame->image_height !== null ? (int) $matchedFrame->image_height : null,
                    ]
                    : null,
                'similarity_percent' => isset($frameMatch['similarity_percent'])
                    ? (float) $frameMatch['similarity_percent']
                    : null,
                'is_threshold_match' => (bool) ($frameMatch['is_threshold_match'] ?? false),
            ];
        }

        return $comparisons;
    }
}
