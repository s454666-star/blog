<?php

namespace App\Http\Controllers;

use App\Models\ExternalVideoDuplicateMatch;
use App\Models\VideoFeatureFrame;
use App\Services\ExternalVideoDuplicateService;
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

        $baseQuery = ExternalVideoDuplicateMatch::query();

        if ($q !== '') {
            $baseQuery->where(function ($query) use ($q): void {
                $query->where('file_name', 'like', '%' . $q . '%')
                    ->orWhere('source_file_path', 'like', '%' . $q . '%')
                    ->orWhere('duplicate_file_path', 'like', '%' . $q . '%')
                    ->orWhereHas('videoMaster', function ($videoQuery) use ($q): void {
                        $videoQuery->where('video_name', 'like', '%' . $q . '%')
                            ->orWhere('video_path', 'like', '%' . $q . '%');
                    });
            });
        }

        $totalMatches = (clone $baseQuery)->count();
        $averageSimilarity = round((float) ((clone $baseQuery)->avg('similarity_percent') ?? 0), 2);

        $matches = $baseQuery
            ->with([
                'frames.matchedVideoFeatureFrame',
                'matchedFeature.frames',
                'videoMaster',
            ])
            ->orderByDesc('created_at')
            ->paginate(8)
            ->withQueryString();

        $matches->getCollection()->transform(function (ExternalVideoDuplicateMatch $match) {
            return $this->decorateMatch($match);
        });

        $existingCount = $matches->getCollection()
            ->filter(function (ExternalVideoDuplicateMatch $match): bool {
                return (bool) $match->duplicate_file_exists;
            })
            ->count();

        return view('videos.external-duplicates.index', [
            'matches' => $matches,
            'q' => $q,
            'stats' => [
                'total_matches' => $totalMatches,
                'average_similarity' => $averageSimilarity,
                'existing_on_page' => $existingCount,
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

    private function decorateMatch(ExternalVideoDuplicateMatch $match): ExternalVideoDuplicateMatch
    {
        $match->external_stream_url = route('videos.external-duplicates.stream', $match);
        $match->db_video_url = $match->videoMaster !== null
            ? $this->buildVideoAssetUrl((string) $match->videoMaster->video_path)
            : null;
        $match->db_video_page_url = $match->videoMaster !== null
            ? route('video.index', ['focus_id' => $match->videoMaster->id])
            : null;

        /** @var Collection<int, VideoFeatureFrame> $featureFramesByOrder */
        $featureFramesByOrder = $match->matchedFeature !== null
            ? $match->matchedFeature->frames->keyBy('capture_order')
            : collect();

        foreach ($match->frames as $frame) {
            $comparisonFrame = $frame->matchedVideoFeatureFrame;
            if (!$comparisonFrame instanceof VideoFeatureFrame) {
                $comparisonFrame = $featureFramesByOrder->get((int) $frame->capture_order);
            }

            $frame->external_image_url = $this->buildPublicAssetUrl((string) $frame->screenshot_path);
            $frame->comparison_frame = $comparisonFrame;
            $frame->db_image_url = $comparisonFrame instanceof VideoFeatureFrame
                ? $this->buildVideoAssetUrl((string) $comparisonFrame->screenshot_path)
                : null;
            $frame->similarity_tone = $this->resolveSimilarityTone($frame->similarity_percent);
        }

        return $match;
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

    private function resolveSimilarityTone(?int $similarityPercent): string
    {
        $similarityPercent = (int) ($similarityPercent ?? 0);

        if ($similarityPercent >= 95) {
            return 'excellent';
        }

        if ($similarityPercent >= 90) {
            return 'good';
        }

        if ($similarityPercent >= 80) {
            return 'warn';
        }

        return 'soft';
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
