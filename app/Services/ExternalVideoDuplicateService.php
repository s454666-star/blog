<?php

namespace App\Services;

use App\Models\ExternalVideoDuplicateFrame;
use App\Models\ExternalVideoDuplicateMatch;
use App\Models\VideoFeature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ExternalVideoDuplicateService
{
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

        $frameMatchesByOrder = [];
        foreach ((array) ($match['frame_matches'] ?? []) as $frameMatch) {
            $captureOrder = (int) ($frameMatch['capture_order'] ?? 0);
            if ($captureOrder > 0) {
                $frameMatchesByOrder[$captureOrder] = $frameMatch;
            }
        }

        $oldScreenshotPaths = $record->exists
            ? $record->frames()->pluck('screenshot_path')->all()
            : [];

        $createdScreenshotPaths = [];
        $runToken = substr(sha1($record->duplicate_path_sha1 . '|' . microtime(true) . '|' . uniqid('', true)), 0, 12);

        try {
            DB::transaction(function () use (
                $payload,
                $match,
                $feature,
                $sourceFilePath,
                $duplicateFilePath,
                $options,
                $record,
                $frameMatchesByOrder,
                $runToken,
                &$createdScreenshotPaths
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

                foreach ((array) ($payload['frames'] ?? []) as $frame) {
                    $captureOrder = (int) ($frame['capture_order'] ?? 0);
                    if ($captureOrder <= 0) {
                        continue;
                    }

                    $relativeScreenshotPath = $this->buildRelativeScreenshotPath(
                        $record->duplicate_path_sha1,
                        $runToken,
                        (string) ($frame['suggested_filename'] ?? ('frame_' . $captureOrder . '.jpg'))
                    );
                    $absoluteScreenshotPath = Storage::disk('public')->path($relativeScreenshotPath);

                    File::ensureDirectoryExists(dirname($absoluteScreenshotPath));
                    if (!@copy((string) ($frame['temp_path'] ?? ''), $absoluteScreenshotPath)) {
                        throw new RuntimeException('無法儲存外部重複截圖：' . $absoluteScreenshotPath);
                    }

                    $createdScreenshotPaths[] = $relativeScreenshotPath;

                    $frameMatch = $frameMatchesByOrder[$captureOrder] ?? [];

                    ExternalVideoDuplicateFrame::query()->create([
                        'external_video_duplicate_match_id' => $record->id,
                        'matched_video_feature_frame_id' => $frameMatch['matched_video_feature_frame_id'] ?? null,
                        'capture_order' => $captureOrder,
                        'capture_second' => $frame['capture_second'] ?? 0,
                        'screenshot_path' => $relativeScreenshotPath,
                        'dhash_hex' => (string) ($frame['dhash_hex'] ?? ''),
                        'dhash_prefix' => (string) ($frame['dhash_prefix'] ?? ''),
                        'frame_sha1' => $frame['frame_sha1'] ?? null,
                        'image_width' => $frame['image_width'] ?? null,
                        'image_height' => $frame['image_height'] ?? null,
                        'similarity_percent' => $frameMatch['similarity_percent'] ?? null,
                        'is_threshold_match' => (bool) ($frameMatch['is_threshold_match'] ?? false),
                    ]);
                }
            });
        } catch (Throwable $e) {
            foreach ($createdScreenshotPaths as $relativeScreenshotPath) {
                $this->deletePublicScreenshot($relativeScreenshotPath);
            }

            throw $e;
        }

        foreach ($oldScreenshotPaths as $oldScreenshotPath) {
            if (is_string($oldScreenshotPath) && $oldScreenshotPath !== '') {
                $this->deletePublicScreenshot($oldScreenshotPath);
            }
        }

        return ExternalVideoDuplicateMatch::query()
            ->with([
                'frames.matchedVideoFeatureFrame',
                'matchedFeature.frames',
                'videoMaster',
            ])
            ->findOrFail($record->id);
    }

    public function deleteRecord(ExternalVideoDuplicateMatch $record): array
    {
        $duplicateFilePath = $this->normalizeAbsolutePath((string) $record->duplicate_file_path);
        $screenshotPaths = $record->frames()->pluck('screenshot_path')->all();

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

        DB::transaction(function () use ($record): void {
            $record->delete();
        });

        foreach ($screenshotPaths as $screenshotPath) {
            if (is_string($screenshotPath) && $screenshotPath !== '') {
                $this->deletePublicScreenshot($screenshotPath);
            }
        }

        return [
            'ok' => true,
            'file_deleted' => $fileDeleted,
            'message' => $fileError,
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

    private function buildRelativeScreenshotPath(string $pathSha1, string $runToken, string $filename): string
    {
        $safeFilename = trim($filename);
        if ($safeFilename === '') {
            $safeFilename = 'frame.jpg';
        }

        $safeFilename = str_replace(['\\', '/'], '_', $safeFilename);

        return sprintf(
            'external-video-duplicates/%s/%s/%s/%s',
            date('Ymd'),
            substr($pathSha1, 0, 16),
            $runToken,
            $safeFilename
        );
    }
}
