<?php

namespace App\Services;

use App\Models\VideoFeature;
use App\Models\VideoFeatureFrame;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class VideoDuplicateDetectionService
{
    private const DURATION_FALLBACK_CANDIDATES = 25;
    private const DATABASE_CANDIDATE_CACHE_TTL_SECONDS = 600;
    private const REFERENCE_SNAPSHOT_INDEX_CACHE_TTL_SECONDS = 86400;

    private ?string $databaseCandidateCacheVersion = null;

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
            ->with(['frames'])
            ->whereIn('id', $candidateIds)
            ->get()
            ->keyBy('id');

        foreach ($candidateIds as $candidateId) {
            $candidate = $candidates->get($candidateId);
            if (!$candidate instanceof VideoFeature) {
                continue;
            }

            $candidateResult = $this->comparePayloadToFeature(
                $payloadContext,
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

            if ($this->hasReachedMaxPossibleScore($candidateResult, $payloadContext)) {
                break;
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
                $payloadContext,
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

    public function analyzeReferenceSnapshotsMatch(
        array $payload,
        array $featureSnapshots,
        int $thresholdPercent = 90,
        int $minMatch = 2,
        int $windowSeconds = 3,
        int $sizePercent = 15,
        int $maxCandidates = 250
    ): array {
        return $this->analyzePreparedReferenceSnapshotsMatch(
            $payload,
            $this->prepareReferenceSnapshotIndex($featureSnapshots),
            $thresholdPercent,
            $minMatch,
            $windowSeconds,
            $sizePercent,
            $maxCandidates
        );
    }

    public function prepareReferenceSnapshotIndex(array $featureSnapshots): array
    {
        if ($featureSnapshots === []) {
            return ['snapshots' => []];
        }

        $cacheKey = $this->buildPreparedReferenceSnapshotIndexCacheKey($featureSnapshots);
        if ($cacheKey === null) {
            return $this->prepareReferenceSnapshotIndexUncached($featureSnapshots);
        }

        $preparedIndex = $this->rememberCacheValue(
            $cacheKey,
            self::REFERENCE_SNAPSHOT_INDEX_CACHE_TTL_SECONDS,
            fn (): array => $this->prepareReferenceSnapshotIndexUncached($featureSnapshots)
        );

        return is_array($preparedIndex) ? $preparedIndex : ['snapshots' => []];
    }

    private function prepareReferenceSnapshotIndexUncached(array $featureSnapshots): array
    {
        $preparedSnapshots = [];

        foreach ($featureSnapshots as $featureSnapshot) {
            $normalizedSnapshot = $this->normalizeSnapshotCandidate($featureSnapshot);
            if ($normalizedSnapshot === null) {
                continue;
            }

            $preparedSnapshots[] = $normalizedSnapshot;
        }

        usort($preparedSnapshots, fn (array $left, array $right): int => $this->comparePreparedSnapshotSort($left, $right));

        return [
            'snapshots' => $preparedSnapshots,
        ];
    }

    public function appendPreparedReferenceSnapshot(array $preparedSnapshotIndex, array $featureSnapshot): array
    {
        $normalizedSnapshot = $this->normalizeSnapshotCandidate($featureSnapshot);
        if ($normalizedSnapshot === null) {
            return $preparedSnapshotIndex;
        }

        $preparedSnapshots = array_values(array_filter(
            (array) ($preparedSnapshotIndex['snapshots'] ?? []),
            fn (array $snapshot): bool => mb_strtolower((string) ($snapshot['absolute_path'] ?? ''))
                !== mb_strtolower((string) ($normalizedSnapshot['absolute_path'] ?? ''))
        ));

        $insertIndex = $this->findPreparedSnapshotInsertIndex($preparedSnapshots, $normalizedSnapshot);
        array_splice($preparedSnapshots, $insertIndex, 0, [$normalizedSnapshot]);

        return [
            'snapshots' => $preparedSnapshots,
        ];
    }

    public function analyzePreparedReferenceSnapshotsMatch(
        array $payload,
        array $preparedSnapshotIndex,
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

        $candidateSnapshots = $this->collectPreparedSnapshotCandidates(
            $payloadContext,
            (array) ($preparedSnapshotIndex['snapshots'] ?? []),
            $windowSeconds,
            $maxCandidates
        );

        if ($candidateSnapshots === []) {
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

        foreach ($candidateSnapshots as $snapshot) {
            $candidateResult = $this->comparePayloadToSnapshot(
                $payloadContext,
                $snapshot,
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

            if ($this->hasReachedMaxPossibleScore($candidateResult, $payloadContext)) {
                break;
            }
        }

        $bestResult = $this->hydrateSnapshotResultFeature($bestResult);
        $bestQualifiedResult = $this->hydrateSnapshotResultFeature($bestQualifiedResult);

        return [
            'best_result' => $bestResult,
            'duplicate_match' => $bestQualifiedResult,
            'candidate_count' => count($candidateSnapshots),
            'payload_frame_count' => $payloadContext['frame_count'],
            'requested_min_match' => max(1, $minMatch),
        ];
    }

    private function comparePayloadToFeature(
        array &$payloadContext,
        VideoFeature $feature,
        float $payloadDuration,
        int $payloadFileSize,
        int $thresholdPercent,
        int $minMatch
    ): ?array {
        $payloadFramesByOrder = $payloadContext['frames_by_order'] ?? [];
        $comparedFrames = 0;
        $matchedFrames = 0;
        $similarities = [];
        $frameMatches = [];

        foreach ($feature->frames as $candidateFrame) {
            $captureOrder = (int) $candidateFrame->capture_order;
            if (!isset($payloadFramesByOrder[$captureOrder])) {
                continue;
            }

            $candidateHash = (string) ($candidateFrame->dhash_hex ?? '');
            if (!$this->featureExtractionService->isValidDhash($candidateHash)) {
                continue;
            }

            $frameComparison = $this->resolveCandidateFrameComparison(
                $payloadContext,
                $feature->frames->count(),
                (float) ($candidateFrame->capture_second ?? 0),
                $payloadFramesByOrder[$captureOrder],
                $candidateHash
            );
            if ($frameComparison === null) {
                continue;
            }

            $payloadFrame = $frameComparison['payload_frame'];
            $payloadHash = $frameComparison['payload_hash'];
            $similarity = $frameComparison['similarity'];

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

    private function comparePayloadToSnapshot(
        array &$payloadContext,
        array $snapshot,
        float $payloadDuration,
        int $payloadFileSize,
        int $thresholdPercent,
        int $minMatch
    ): ?array {
        $payloadFramesByOrder = $payloadContext['frames_by_order'] ?? [];
        $candidateFrames = (array) ($snapshot['frames'] ?? []);
        $candidateFrameCount = count($candidateFrames);
        $comparedFrames = 0;
        $matchedFrames = 0;
        $similarities = [];
        $frameMatches = [];

        foreach ($candidateFrames as $candidateFrame) {
            $captureOrder = (int) ($candidateFrame['capture_order'] ?? 0);
            if ($captureOrder <= 0 || !isset($payloadFramesByOrder[$captureOrder])) {
                continue;
            }

            $candidateHash = (string) ($candidateFrame['dhash_hex'] ?? '');
            if (!$this->featureExtractionService->isValidDhash($candidateHash)) {
                continue;
            }

            $frameComparison = $this->resolveCandidateFrameComparison(
                $payloadContext,
                $candidateFrameCount,
                (float) ($candidateFrame['capture_second'] ?? 0),
                $payloadFramesByOrder[$captureOrder],
                $candidateHash
            );
            if ($frameComparison === null) {
                continue;
            }

            $payloadFrame = $frameComparison['payload_frame'];
            $payloadHash = $frameComparison['payload_hash'];
            $similarity = $frameComparison['similarity'];

            $comparedFrames++;
            $similarities[] = $similarity;

            $frameMatches[] = [
                'capture_order' => $captureOrder,
                'capture_second' => (float) ($payloadFrame['capture_second'] ?? 0),
                'matched_video_feature_frame_id' => null,
                'matched_capture_second' => (float) ($candidateFrame['capture_second'] ?? 0),
                'payload_dhash_hex' => $payloadHash,
                'matched_dhash_hex' => $candidateHash,
                'payload_frame_sha1' => $payloadFrame['frame_sha1'] ?? null,
                'matched_frame_sha1' => $candidateFrame['frame_sha1'] ?? null,
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
        $durationDelta = abs((float) ($snapshot['duration_seconds'] ?? 0) - $payloadDuration);
        $snapshotFileSize = $snapshot['file_size_bytes'] ?? null;
        $fileSizeDelta = $payloadFileSize > 0 && $snapshotFileSize !== null
            ? abs((int) $snapshotFileSize - $payloadFileSize)
            : null;
        $passesThreshold = $matchedFrames >= $requiredMatches;

        return [
            'feature' => null,
            'feature_snapshot' => $snapshot,
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

    private function resolveCandidateFrameComparison(
        array &$payloadContext,
        int $candidateFrameCount,
        float $candidateCaptureSecond,
        array $primaryPayloadFrame,
        string $candidateHash
    ): ?array {
        $bestPayloadFrame = null;
        $bestPayloadHash = '';
        $bestSimilarity = null;

        $candidatePayloadFrames = [$primaryPayloadFrame];
        $compatibilityPayloadFrame = $this->resolveCompatibilityPayloadFrame(
            $payloadContext,
            $candidateFrameCount,
            $candidateCaptureSecond,
            $primaryPayloadFrame
        );

        if (is_array($compatibilityPayloadFrame)) {
            $candidatePayloadFrames[] = $compatibilityPayloadFrame;
        }

        foreach ($candidatePayloadFrames as $payloadFrame) {
            $payloadHash = (string) ($payloadFrame['dhash_hex'] ?? '');
            if (!$this->featureExtractionService->isValidDhash($payloadHash)) {
                continue;
            }

            $similarity = $this->featureExtractionService->hashSimilarityPercent($payloadHash, $candidateHash);
            if ($bestSimilarity === null || $similarity > $bestSimilarity) {
                $bestPayloadFrame = $payloadFrame;
                $bestPayloadHash = $payloadHash;
                $bestSimilarity = $similarity;
            }
        }

        if ($bestPayloadFrame === null || $bestSimilarity === null) {
            return null;
        }

        return [
            'payload_frame' => $bestPayloadFrame,
            'payload_hash' => $bestPayloadHash,
            'similarity' => $bestSimilarity,
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
            'absolute_path' => (string) ($payload['absolute_path'] ?? ''),
            'tmp_dir' => (string) ($payload['tmp_dir'] ?? ''),
            'compatibility_frames_by_second' => [],
        ];
    }

    private function resolveCompatibilityPayloadFrame(
        array &$payloadContext,
        int $candidateFrameCount,
        float $candidateCaptureSecond,
        array $primaryPayloadFrame
    ): ?array {
        if (!$this->shouldInspectCompatibilityFrame($payloadContext, $candidateFrameCount, $candidateCaptureSecond, $primaryPayloadFrame)) {
            return null;
        }

        $cacheKey = number_format($candidateCaptureSecond, 3, '.', '');

        if (!array_key_exists($cacheKey, $payloadContext['compatibility_frames_by_second'])) {
            try {
                $payloadContext['compatibility_frames_by_second'][$cacheKey] = $this->featureExtractionService->inspectFrameAtSecond(
                    (string) $payloadContext['absolute_path'],
                    $candidateCaptureSecond,
                    (string) $payloadContext['tmp_dir'],
                    (int) ($primaryPayloadFrame['capture_order'] ?? 1)
                );
            } catch (\Throwable) {
                $payloadContext['compatibility_frames_by_second'][$cacheKey] = null;
            }
        }

        $compatibilityFrame = $payloadContext['compatibility_frames_by_second'][$cacheKey] ?? null;

        return is_array($compatibilityFrame) ? $compatibilityFrame : null;
    }

    private function shouldInspectCompatibilityFrame(
        array $payloadContext,
        int $candidateFrameCount,
        float $candidateCaptureSecond,
        array $primaryPayloadFrame
    ): bool {
        if (
            (int) ($payloadContext['frame_count'] ?? 0) !== 1 ||
            $candidateFrameCount !== 1
        ) {
            return false;
        }

        if (
            (string) ($payloadContext['absolute_path'] ?? '') === '' ||
            (string) ($payloadContext['tmp_dir'] ?? '') === ''
        ) {
            return false;
        }

        $payloadDuration = (float) ($payloadContext['duration_seconds'] ?? 0);
        if ($payloadDuration <= 0 || $payloadDuration >= 10.0) {
            return false;
        }

        return abs(
            (float) ($primaryPayloadFrame['capture_second'] ?? 0)
            - $candidateCaptureSecond
        ) >= 0.001;
    }

    private function collectCandidateIds(
        array $payloadContext,
        int $windowSeconds,
        int $maxCandidates
    ): array {
        $cacheKey = $this->buildDatabaseCandidateIdsCacheKey($payloadContext, $windowSeconds, $maxCandidates);
        if ($cacheKey === null) {
            return $this->collectCandidateIdsUncached($payloadContext, $windowSeconds, $maxCandidates);
        }

        $candidateIds = $this->rememberCacheValue(
            $cacheKey,
            self::DATABASE_CANDIDATE_CACHE_TTL_SECONDS,
            fn (): array => $this->collectCandidateIdsUncached($payloadContext, $windowSeconds, $maxCandidates)
        );

        if (!is_array($candidateIds)) {
            return [];
        }

        return array_values(array_map(static fn (mixed $candidateId): int => (int) $candidateId, $candidateIds));
    }

    private function collectCandidateIdsUncached(
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

    private function collectPreparedSnapshotCandidates(
        array $payloadContext,
        array $preparedSnapshots,
        int $windowSeconds,
        int $maxCandidates
    ): array {
        $durationMin = max(0, $payloadContext['duration_seconds'] - max(0, $windowSeconds));
        $durationMax = $payloadContext['duration_seconds'] + max(0, $windowSeconds);
        $limit = max(1, $maxCandidates);
        $normalizedSnapshots = [];
        $sourcePathLower = mb_strtolower(trim((string) ($payloadContext['absolute_path'] ?? '')));
        [$startIndex, $endIndex] = $this->findPreparedSnapshotDurationRange($preparedSnapshots, $durationMin, $durationMax);

        if ($startIndex === null || $endIndex === null) {
            return [];
        }

        for ($index = $startIndex; $index <= $endIndex; $index++) {
            $normalizedSnapshot = $preparedSnapshots[$index] ?? null;
            if (!is_array($normalizedSnapshot)) {
                continue;
            }

            if (
                $sourcePathLower !== ''
                && mb_strtolower((string) ($normalizedSnapshot['absolute_path'] ?? '')) === $sourcePathLower
            ) {
                continue;
            }

            $durationSeconds = (float) ($normalizedSnapshot['duration_seconds'] ?? 0);

            $normalizedSnapshot['shared_prefix_count'] = count(array_intersect(
                $payloadContext['prefixes'],
                $normalizedSnapshot['prefixes']
            ));
            $normalizedSnapshot['duration_delta_seconds'] = abs(
                $durationSeconds - (float) $payloadContext['duration_seconds']
            );
            $normalizedSnapshots[] = $normalizedSnapshot;
        }

        if ($normalizedSnapshots === []) {
            return [];
        }

        if ($this->shouldBypassPrefixGate($payloadContext)) {
            usort($normalizedSnapshots, function (array $left, array $right): int {
                return $this->compareSnapshotRanking($left, $right);
            });

            return array_slice($normalizedSnapshots, 0, $limit);
        }

        $prefixMatchedSnapshots = array_values(array_filter(
            $normalizedSnapshots,
            fn (array $snapshot): bool => (int) ($snapshot['shared_prefix_count'] ?? 0) > 0
        ));
        usort($prefixMatchedSnapshots, function (array $left, array $right): int {
            return $this->compareSnapshotRanking($left, $right, true);
        });

        if (count($prefixMatchedSnapshots) >= $limit) {
            return array_slice($prefixMatchedSnapshots, 0, $limit);
        }

        $durationFallbackSnapshots = array_values(array_filter(
            $normalizedSnapshots,
            fn (array $snapshot): bool => (int) ($snapshot['shared_prefix_count'] ?? 0) === 0
        ));
        usort($durationFallbackSnapshots, function (array $left, array $right): int {
            return $this->compareSnapshotRanking($left, $right);
        });

        $fallbackLimit = min($limit, self::DURATION_FALLBACK_CANDIDATES);

        return array_slice(
            array_merge($prefixMatchedSnapshots, array_slice($durationFallbackSnapshots, 0, $fallbackLimit)),
            0,
            $limit
        );
    }

    private function normalizeSnapshotCandidate(mixed $snapshot): ?array
    {
        if (!is_array($snapshot)) {
            return null;
        }

        $absolutePath = trim((string) ($snapshot['absolute_path'] ?? ''));
        if ($absolutePath === '') {
            return null;
        }

        $frames = [];
        $prefixes = [];

        foreach ((array) ($snapshot['frames'] ?? []) as $frame) {
            if (!is_array($frame)) {
                continue;
            }

            $captureOrder = (int) ($frame['capture_order'] ?? 0);
            if ($captureOrder <= 0) {
                continue;
            }

            $dhashHex = strtolower(trim((string) ($frame['dhash_hex'] ?? '')));
            if ($dhashHex === '') {
                continue;
            }

            $dhashPrefix = trim((string) ($frame['dhash_prefix'] ?? substr($dhashHex, 0, 2)));
            if ($dhashPrefix !== '') {
                $prefixes[] = $dhashPrefix;
            }

            $frames[] = [
                'capture_order' => $captureOrder,
                'capture_second' => isset($frame['capture_second']) ? (float) $frame['capture_second'] : null,
                'label_second' => isset($frame['label_second']) ? (float) $frame['label_second'] : null,
                'dhash_hex' => $dhashHex,
                'dhash_prefix' => $dhashPrefix,
                'frame_sha1' => $frame['frame_sha1'] ?? null,
                'image_width' => isset($frame['image_width']) ? (int) $frame['image_width'] : null,
                'image_height' => isset($frame['image_height']) ? (int) $frame['image_height'] : null,
            ];
        }

        if ($frames === []) {
            return null;
        }

        usort($frames, fn (array $left, array $right): int => $left['capture_order'] <=> $right['capture_order']);

        return [
            'absolute_path' => $absolutePath,
            'video_name' => (string) ($snapshot['video_name'] ?? basename($absolutePath)),
            'file_name' => (string) ($snapshot['file_name'] ?? basename($absolutePath)),
            'file_size_bytes' => isset($snapshot['file_size_bytes']) ? (int) $snapshot['file_size_bytes'] : null,
            'duration_seconds' => isset($snapshot['duration_seconds']) ? (float) $snapshot['duration_seconds'] : null,
            'screenshot_count' => isset($snapshot['screenshot_count']) ? (int) $snapshot['screenshot_count'] : count($frames),
            'feature_version' => (string) ($snapshot['feature_version'] ?? 'v1'),
            'capture_rule' => (string) ($snapshot['capture_rule'] ?? '10s_x4'),
            'frames' => $frames,
            'prefixes' => array_values(array_unique($prefixes)),
        ];
    }

    private function hydrateFeatureFromSnapshot(array $snapshot): VideoFeature
    {
        $feature = new VideoFeature();
        $feature->forceFill([
            'video_name' => (string) ($snapshot['video_name'] ?? ''),
            'video_path' => (string) ($snapshot['absolute_path'] ?? ''),
            'file_name' => (string) ($snapshot['file_name'] ?? ''),
            'file_size_bytes' => $snapshot['file_size_bytes'] ?? null,
            'duration_seconds' => $snapshot['duration_seconds'] ?? 0,
            'screenshot_count' => $snapshot['screenshot_count'] ?? count((array) ($snapshot['frames'] ?? [])),
            'feature_version' => (string) ($snapshot['feature_version'] ?? 'v1'),
            'capture_rule' => (string) ($snapshot['capture_rule'] ?? '10s_x4'),
        ]);

        $featureFrames = collect();

        foreach ((array) ($snapshot['frames'] ?? []) as $frameSnapshot) {
            $frame = new VideoFeatureFrame();
            $frame->forceFill([
                'capture_order' => (int) ($frameSnapshot['capture_order'] ?? 0),
                'capture_second' => $frameSnapshot['capture_second'] ?? 0,
                'screenshot_path' => null,
                'dhash_hex' => (string) ($frameSnapshot['dhash_hex'] ?? ''),
                'dhash_prefix' => (string) ($frameSnapshot['dhash_prefix'] ?? ''),
                'frame_sha1' => $frameSnapshot['frame_sha1'] ?? null,
                'image_width' => $frameSnapshot['image_width'] ?? null,
                'image_height' => $frameSnapshot['image_height'] ?? null,
            ]);
            $featureFrames->push($frame);
        }

        $feature->setRelation('frames', $featureFrames);
        $feature->setRelation('videoMaster', null);

        return $feature;
    }

    private function compareSnapshotRanking(array $left, array $right, bool $preferSharedPrefixes = false): int
    {
        if ($preferSharedPrefixes) {
            $leftSharedPrefixes = (int) ($left['shared_prefix_count'] ?? 0);
            $rightSharedPrefixes = (int) ($right['shared_prefix_count'] ?? 0);

            if ($leftSharedPrefixes !== $rightSharedPrefixes) {
                return $rightSharedPrefixes <=> $leftSharedPrefixes;
            }
        }

        $leftDurationDelta = (float) ($left['duration_delta_seconds'] ?? INF);
        $rightDurationDelta = (float) ($right['duration_delta_seconds'] ?? INF);

        if ($leftDurationDelta !== $rightDurationDelta) {
            return $leftDurationDelta <=> $rightDurationDelta;
        }

        return strcmp(
            mb_strtolower((string) ($left['absolute_path'] ?? '')),
            mb_strtolower((string) ($right['absolute_path'] ?? ''))
        );
    }

    private function comparePreparedSnapshotSort(array $left, array $right): int
    {
        $leftDuration = (float) ($left['duration_seconds'] ?? 0);
        $rightDuration = (float) ($right['duration_seconds'] ?? 0);

        if ($leftDuration !== $rightDuration) {
            return $leftDuration <=> $rightDuration;
        }

        return strcmp(
            mb_strtolower((string) ($left['absolute_path'] ?? '')),
            mb_strtolower((string) ($right['absolute_path'] ?? ''))
        );
    }

    private function findPreparedSnapshotInsertIndex(array $preparedSnapshots, array $snapshot): int
    {
        $low = 0;
        $high = count($preparedSnapshots);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $comparison = $this->comparePreparedSnapshotSort($preparedSnapshots[$mid], $snapshot);

            if ($comparison <= 0) {
                $low = $mid + 1;
                continue;
            }

            $high = $mid;
        }

        return $low;
    }

    private function findPreparedSnapshotDurationRange(array $preparedSnapshots, float $durationMin, float $durationMax): array
    {
        if ($preparedSnapshots === []) {
            return [null, null];
        }

        $startIndex = $this->findFirstPreparedSnapshotIndexByDuration($preparedSnapshots, $durationMin);
        $endIndex = $this->findLastPreparedSnapshotIndexByDuration($preparedSnapshots, $durationMax);

        if ($startIndex > $endIndex) {
            return [null, null];
        }

        return [$startIndex, $endIndex];
    }

    private function findFirstPreparedSnapshotIndexByDuration(array $preparedSnapshots, float $targetDuration): int
    {
        $low = 0;
        $high = count($preparedSnapshots);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $midDuration = (float) ($preparedSnapshots[$mid]['duration_seconds'] ?? 0);

            if ($midDuration < $targetDuration) {
                $low = $mid + 1;
                continue;
            }

            $high = $mid;
        }

        return $low;
    }

    private function findLastPreparedSnapshotIndexByDuration(array $preparedSnapshots, float $targetDuration): int
    {
        $low = 0;
        $high = count($preparedSnapshots);

        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $midDuration = (float) ($preparedSnapshots[$mid]['duration_seconds'] ?? 0);

            if ($midDuration <= $targetDuration) {
                $low = $mid + 1;
                continue;
            }

            $high = $mid;
        }

        return $low - 1;
    }

    private function hydrateSnapshotResultFeature(?array $result): ?array
    {
        if (!is_array($result)) {
            return null;
        }

        $snapshot = $result['feature_snapshot'] ?? null;
        if (is_array($snapshot) && !($result['feature'] ?? null) instanceof VideoFeature) {
            $result['feature'] = $this->hydrateFeatureFromSnapshot($snapshot);
        }

        return $result;
    }

    private function hasReachedMaxPossibleScore(?array $candidateResult, array $payloadContext): bool
    {
        if (!is_array($candidateResult)) {
            return false;
        }

        $frameCount = (int) ($payloadContext['frame_count'] ?? 0);
        if ($frameCount <= 0) {
            return false;
        }

        $maxPossibleScore = ($frameCount * 1000) + 100;

        return (float) ($candidateResult['score'] ?? -INF) >= $maxPossibleScore;
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

    private function buildPreparedReferenceSnapshotIndexCacheKey(array $featureSnapshots): ?string
    {
        $snapshotSignatures = [];

        foreach ($featureSnapshots as $snapshot) {
            if (!is_array($snapshot)) {
                continue;
            }

            $absolutePath = mb_strtolower(trim((string) ($snapshot['absolute_path'] ?? '')));
            if ($absolutePath === '') {
                continue;
            }

            $snapshotSignatures[] = implode('|', [
                $absolutePath,
                (string) ($snapshot['path_sha1'] ?? ''),
                (string) ($snapshot['file_modified_timestamp'] ?? ''),
                (string) ($snapshot['file_size_bytes'] ?? ''),
                (string) ($snapshot['duration_seconds'] ?? ''),
                (string) ($snapshot['screenshot_count'] ?? count((array) ($snapshot['frames'] ?? []))),
            ]);
        }

        if ($snapshotSignatures === []) {
            return null;
        }

        sort($snapshotSignatures, SORT_STRING);

        return 'video_duplicate:prepared_reference_snapshots:' . sha1(implode('||', $snapshotSignatures));
    }

    private function buildDatabaseCandidateIdsCacheKey(
        array $payloadContext,
        int $windowSeconds,
        int $maxCandidates
    ): ?string {
        $frameCount = (int) ($payloadContext['frame_count'] ?? 0);
        if ($frameCount <= 0) {
            return null;
        }

        $prefixes = array_values(array_unique(array_map(
            static fn (mixed $prefix): string => (string) $prefix,
            (array) ($payloadContext['prefixes'] ?? [])
        )));
        sort($prefixes, SORT_STRING);

        $keyPayload = [
            'version' => $this->resolveDatabaseCandidateCacheVersion(),
            'frame_count' => $frameCount,
            'duration_seconds' => number_format((float) ($payloadContext['duration_seconds'] ?? 0), 3, '.', ''),
            'window_seconds' => max(0, $windowSeconds),
            'max_candidates' => max(1, $maxCandidates),
            'bypass_prefix_gate' => $this->shouldBypassPrefixGate($payloadContext),
            'fallback_to_duration_candidates' => $this->shouldFallbackToDurationCandidates($payloadContext),
            'prefixes' => $prefixes,
        ];

        return 'video_duplicate:db_candidate_ids:' . sha1(json_encode(
            $keyPayload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) ?: serialize($keyPayload));
    }

    private function resolveDatabaseCandidateCacheVersion(): string
    {
        if ($this->databaseCandidateCacheVersion !== null) {
            return $this->databaseCandidateCacheVersion;
        }

        try {
            $featureMeta = DB::table('video_features')
                ->selectRaw('COUNT(*) as aggregate_count, COALESCE(MAX(id), 0) as aggregate_max_id, COALESCE(MAX(updated_at), "") as aggregate_updated_at')
                ->first();
            $frameMeta = DB::table('video_feature_frames')
                ->selectRaw('COUNT(*) as aggregate_count, COALESCE(MAX(id), 0) as aggregate_max_id, COALESCE(MAX(updated_at), "") as aggregate_updated_at')
                ->first();

            $versionPayload = [
                'video_features' => [
                    'count' => $featureMeta->aggregate_count ?? 0,
                    'max_id' => $featureMeta->aggregate_max_id ?? 0,
                    'max_updated_at' => (string) ($featureMeta->aggregate_updated_at ?? ''),
                ],
                'video_feature_frames' => [
                    'count' => $frameMeta->aggregate_count ?? 0,
                    'max_id' => $frameMeta->aggregate_max_id ?? 0,
                    'max_updated_at' => (string) ($frameMeta->aggregate_updated_at ?? ''),
                ],
            ];

            $encodedPayload = json_encode($versionPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->databaseCandidateCacheVersion = sha1($encodedPayload !== false ? $encodedPayload : serialize($versionPayload));
        } catch (Throwable) {
            $this->databaseCandidateCacheVersion = 'uncached';
        }

        return $this->databaseCandidateCacheVersion;
    }

    private function rememberCacheValue(string $cacheKey, int $ttlSeconds, callable $resolver): mixed
    {
        try {
            return Cache::remember(
                $cacheKey,
                now()->addSeconds(max(1, $ttlSeconds)),
                $resolver
            );
        } catch (Throwable) {
            return $resolver();
        }
    }
}
