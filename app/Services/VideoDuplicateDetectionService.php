<?php

namespace App\Services;

use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;

class VideoDuplicateDetectionService
{
    private const DURATION_FALLBACK_CANDIDATES = 25;

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
        $payloadContext = $this->buildPayloadContext($payload);

        if ($payloadContext['frame_count'] <= 0) {
            return [
                'best_result' => null,
                'duplicate_match' => null,
                'candidate_count' => 0,
                'payload_frame_count' => 0,
                'requested_min_match' => max(1, $minMatch),
            ];
        }

        if ($payloadContext['prefixes'] === []) {
            return [
                'best_result' => null,
                'duplicate_match' => null,
                'candidate_count' => 0,
                'payload_frame_count' => $payloadContext['frame_count'],
                'requested_min_match' => max(1, $minMatch),
            ];
        }

        $candidateIds = $this->collectCandidateIds(
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

        $candidates = VideoFeature::query()
            ->with(['frames', 'videoMaster'])
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

    public function analyzeSpecificFeatureMatch(
        array $payload,
        VideoFeature $feature,
        int $thresholdPercent = 90,
        int $minMatch = 2,
        int $windowSeconds = 3,
        int $sizePercent = 15
    ): array {
        $payloadContext = $this->buildPayloadContext($payload);

        $feature->loadMissing(['frames', 'videoMaster']);

        $candidateGate = $this->buildCandidateGateSummary(
            $payloadContext,
            $feature,
            $windowSeconds
        );

        $compareResult = null;
        if ($payloadContext['frame_count'] > 0) {
            $compareResult = $this->comparePayloadToFeature(
                $payloadContext['frames_by_order'],
                $feature,
                $payloadContext['duration_seconds'],
                $payloadContext['file_size_bytes'],
                $thresholdPercent,
                $minMatch
            );
        }

        return [
            'feature' => $feature,
            'candidate_gate' => $candidateGate,
            'compare_result' => $compareResult,
            'best_result' => $compareResult,
            'duplicate_match' => is_array($compareResult) && ($compareResult['passes_threshold'] ?? false)
                ? $compareResult
                : null,
            'payload_frame_count' => $payloadContext['frame_count'],
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

            $prefix = (string) ($frame['dhash_prefix'] ?? '');
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
        array $payloadContext,
        int $windowSeconds,
        int $maxCandidates
    ): array {
        $durationMin = max(0, $payloadContext['duration_seconds'] - max(0, $windowSeconds));
        $durationMax = $payloadContext['duration_seconds'] + max(0, $windowSeconds);
        $limit = max(1, $maxCandidates);

        if ($this->shouldBypassPrefixGate($payloadContext)) {
            return VideoFeature::query()
                ->whereBetween('duration_seconds', [$durationMin, $durationMax])
                ->orderByRaw(
                    'ABS(CAST(video_features.duration_seconds AS DECIMAL(10,3)) - ?) ASC',
                    [$payloadContext['duration_seconds']]
                )
                ->limit($limit)
                ->pluck('id')
                ->all();
        }

        $prefixMatchedIds = VideoFeatureFrame::query()
            ->select('video_feature_frames.video_feature_id')
            ->join('video_features', 'video_features.id', '=', 'video_feature_frames.video_feature_id')
            ->whereIn('video_feature_frames.dhash_prefix', $payloadContext['prefixes'])
            ->whereBetween('video_features.duration_seconds', [$durationMin, $durationMax])
            ->groupBy('video_feature_frames.video_feature_id')
            ->orderByRaw('COUNT(*) DESC')
            ->orderByRaw(
                'ABS(CAST(video_features.duration_seconds AS DECIMAL(10,3)) - ?) ASC',
                [$payloadContext['duration_seconds']]
            )
            ->limit($limit)
            ->pluck('video_feature_frames.video_feature_id')
            ->all();

        if (count($prefixMatchedIds) >= $limit) {
            return $prefixMatchedIds;
        }

        $fallbackLimit = min($limit, self::DURATION_FALLBACK_CANDIDATES);
        $durationFallbackIds = VideoFeature::query()
            ->whereBetween('duration_seconds', [$durationMin, $durationMax])
            ->when($prefixMatchedIds !== [], function ($query) use ($prefixMatchedIds) {
                $query->whereNotIn('id', $prefixMatchedIds);
            })
            ->orderByRaw(
                'ABS(CAST(video_features.duration_seconds AS DECIMAL(10,3)) - ?) ASC',
                [$payloadContext['duration_seconds']]
            )
            ->limit($fallbackLimit)
            ->pluck('id')
            ->all();

        return array_slice(
            array_values(array_unique(array_merge($prefixMatchedIds, $durationFallbackIds))),
            0,
            $limit
        );
    }

    private function buildCandidateGateSummary(
        array $payloadContext,
        VideoFeature $feature,
        int $windowSeconds
    ): array {
        $featurePrefixes = [];
        foreach ($feature->frames as $frame) {
            $prefix = trim((string) ($frame->dhash_prefix ?? ''));
            if ($prefix !== '') {
                $featurePrefixes[] = $prefix;
            }
        }

        $featurePrefixes = array_values(array_unique($featurePrefixes));
        $sharedPrefixes = array_values(array_intersect($payloadContext['prefixes'], $featurePrefixes));

        $durationMin = max(0, $payloadContext['duration_seconds'] - max(0, $windowSeconds));
        $durationMax = $payloadContext['duration_seconds'] + max(0, $windowSeconds);
        $featureDuration = $feature->duration_seconds !== null ? (float) $feature->duration_seconds : null;
        $durationWithinWindow = $featureDuration !== null
            ? $featureDuration >= $durationMin && $featureDuration <= $durationMax
            : false;

        $sizeWindowMin = null;
        $sizeWindowMax = null;
        $featureFileSize = $feature->file_size_bytes !== null ? (int) $feature->file_size_bytes : null;
        $fileSizeDelta = $featureFileSize !== null && $payloadContext['file_size_bytes'] > 0
            ? abs($featureFileSize - $payloadContext['file_size_bytes'])
            : null;
        $sizeFilterApplied = false;
        $sizeWithinWindow = null;

        $prefixEligible = $payloadContext['prefixes'] !== [] && $sharedPrefixes !== [];
        $prefixGateBypassed = !$prefixEligible && (
            $this->shouldBypassPrefixGate($payloadContext)
            || $this->shouldFallbackToDurationCandidates($payloadContext)
        );
        $eligible = ($prefixEligible || $prefixGateBypassed) && $durationWithinWindow;

        $reasons = [];
        if ($payloadContext['prefixes'] === []) {
            $reasons[] = '來源影片沒有可用的 dHash prefix。';
        } elseif ($sharedPrefixes === [] && !$prefixGateBypassed) {
            $reasons[] = '來源影片與指定 feature 沒有任何相同的 dHash prefix。';
        }

        if (!$durationWithinWindow) {
            $reasons[] = '時長不在正式流程的 window 範圍內。';
        }

        return [
            'eligible' => $eligible,
            'payload_duration_seconds' => round($payloadContext['duration_seconds'], 3),
            'feature_duration_seconds' => $featureDuration !== null ? round($featureDuration, 3) : null,
            'payload_file_size_bytes' => $payloadContext['file_size_bytes'] > 0 ? $payloadContext['file_size_bytes'] : null,
            'feature_file_size_bytes' => $featureFileSize,
            'payload_prefix_count' => count($payloadContext['prefixes']),
            'feature_prefix_count' => count($featurePrefixes),
            'payload_prefixes' => $payloadContext['prefixes'],
            'feature_prefixes' => $featurePrefixes,
            'shared_prefixes' => $sharedPrefixes,
            'prefix_gate_bypassed' => $prefixGateBypassed,
            'duration_within_window' => $durationWithinWindow,
            'duration_window_min' => round($durationMin, 3),
            'duration_window_max' => round($durationMax, 3),
            'duration_delta_seconds' => $featureDuration !== null
                ? round(abs($featureDuration - $payloadContext['duration_seconds']), 3)
                : null,
            'size_filter_applied' => $sizeFilterApplied,
            'size_within_window' => $sizeWithinWindow,
            'size_window_min' => $sizeWindowMin,
            'size_window_max' => $sizeWindowMax,
            'file_size_delta_bytes' => $fileSizeDelta,
            'required_size_percent_to_pass' => null,
            'size_gate_ignored' => true,
            'reasons' => $reasons,
        ];
    }

    private function shouldBypassPrefixGate(array $payloadContext): bool
    {
        return (int) ($payloadContext['frame_count'] ?? 0) === 1
            && !empty($payloadContext['prefixes']);
    }

    private function shouldFallbackToDurationCandidates(array $payloadContext): bool
    {
        return (int) ($payloadContext['frame_count'] ?? 0) >= 2
            && !empty($payloadContext['prefixes']);
    }
}
