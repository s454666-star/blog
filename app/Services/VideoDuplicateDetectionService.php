<?php

namespace App\Services;

use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;

class VideoDuplicateDetectionService
{
    public function __construct(
        private readonly VideoFeatureExtractionService $featureExtractionService
    ) {
    }

    public function analyzeDatabaseMatch(
        array $payload,
        int $thresholdPercent = 90,
        int $minMatch = 2,
        int $windowSeconds = 3,
        int $sizePercent = 15,
        int $maxCandidates = 250
    ): array {
        $frames = $payload['frames'] ?? [];
        if (!is_array($frames) || $frames === []) {
            return [
                'best_result' => null,
                'duplicate_match' => null,
                'candidate_count' => 0,
                'payload_frame_count' => 0,
                'requested_min_match' => max(1, $minMatch),
            ];
        }

        $durationSeconds = (float) ($payload['duration_seconds'] ?? 0);
        $fileSizeBytes = (int) ($payload['file_size_bytes'] ?? 0);

        $prefixes = [];
        foreach ($frames as $frame) {
            $prefix = (string) ($frame['dhash_prefix'] ?? '');
            if ($prefix !== '') {
                $prefixes[] = $prefix;
            }
        }
        $prefixes = array_values(array_unique($prefixes));

        $payloadFramesByOrder = [];
        foreach ($frames as $frame) {
            $payloadFramesByOrder[(int) $frame['capture_order']] = $frame;
        }

        if ($prefixes === []) {
            return [
                'best_result' => null,
                'duplicate_match' => null,
                'candidate_count' => 0,
                'payload_frame_count' => count($payloadFramesByOrder),
                'requested_min_match' => max(1, $minMatch),
            ];
        }

        $candidateIds = VideoFeatureFrame::query()
            ->select('video_feature_frames.video_feature_id')
            ->join('video_features', 'video_features.id', '=', 'video_feature_frames.video_feature_id')
            ->whereIn('video_feature_frames.dhash_prefix', $prefixes)
            ->whereBetween('video_features.duration_seconds', [
                max(0, $durationSeconds - max(0, $windowSeconds)),
                $durationSeconds + max(0, $windowSeconds),
            ])
            ->when($fileSizeBytes > 0, function ($query) use ($fileSizeBytes, $sizePercent) {
                $ratio = max(0, min(90, $sizePercent)) / 100;
                $minSize = (int) floor($fileSizeBytes * (1 - $ratio));
                $maxSize = (int) ceil($fileSizeBytes * (1 + $ratio));

                $query->whereBetween('video_features.file_size_bytes', [$minSize, $maxSize]);
            })
            ->groupBy('video_feature_frames.video_feature_id')
            ->orderByRaw('COUNT(*) DESC')
            ->orderByRaw('ABS(CAST(video_features.duration_seconds AS DECIMAL(10,3)) - ?) ASC', [$durationSeconds])
            ->orderByRaw('ABS(CAST(video_features.file_size_bytes AS SIGNED) - ?) ASC', [$fileSizeBytes])
            ->limit(max(1, $maxCandidates))
            ->pluck('video_feature_frames.video_feature_id')
            ->all();

        if ($candidateIds === []) {
            return [
                'best_result' => null,
                'duplicate_match' => null,
                'candidate_count' => 0,
                'payload_frame_count' => count($payloadFramesByOrder),
                'requested_min_match' => max(1, $minMatch),
            ];
        }

        $bestResult = null;
        $bestQualifiedResult = null;

        $candidates = VideoFeature::query()
            ->with(['frames', 'videoMaster'])
            ->whereIn('id', $candidateIds)
            ->get();

        foreach ($candidates as $candidate) {
            $candidateResult = $this->comparePayloadToFeature(
                $payloadFramesByOrder,
                $candidate,
                $durationSeconds,
                $fileSizeBytes,
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
            'payload_frame_count' => count($payloadFramesByOrder),
            'requested_min_match' => max(1, $minMatch),
        ];
    }

    public function findBestDatabaseMatch(
        array $payload,
        int $thresholdPercent = 90,
        int $minMatch = 2,
        int $windowSeconds = 3,
        int $sizePercent = 15,
        int $maxCandidates = 250
    ): ?array {
        return $this->analyzeDatabaseMatch(
            $payload,
            $thresholdPercent,
            $minMatch,
            $windowSeconds,
            $sizePercent,
            $maxCandidates
        )['duplicate_match'];
    }

    private function comparePayloadToFeature(
        array $payloadFramesByOrder,
        VideoFeature $feature,
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
                'matched_video_feature_frame_id' => $candidateFrame->id,
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
        $passesThreshold = $matchedFrames >= $requiredMatches;

        return [
            'feature' => $feature,
            'similarity_percent' => round($avgSimilarity, 2),
            'matched_frames' => $matchedFrames,
            'compared_frames' => $comparedFrames,
            'required_matches' => $requiredMatches,
            'passes_threshold' => $passesThreshold,
            'frame_matches' => $frameMatches,
            'score' => ($matchedFrames * 1000) + $avgSimilarity,
            'duration_delta_seconds' => $durationDelta,
            'file_size_delta_bytes' => $fileSizeDelta,
        ];
    }
}
