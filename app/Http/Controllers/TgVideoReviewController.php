<?php

namespace App\Http\Controllers;

use App\Models\TgVideoReview;
use App\Services\TgVideoReviewActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TgVideoReviewController extends Controller
{
    public function index(Request $request): View
    {
        $options = [50, 100, 200, 500, 1000, 2000];
        $requested = (int) $request->query('per_page', 50);
        $perPage = in_array($requested, $options, true) ? $requested : 50;

        return view('tg-video-review.index', [
            'records' => TgVideoReview::query()->orderBy('id')->paginate($perPage)->withQueryString(),
            'perPage' => $perPage,
            'perPageOptions' => $options,
        ]);
    }

    public function image(TgVideoReview $record): BinaryFileResponse
    {
        $root = realpath((string) config('tg_video_review.root'));
        $image = realpath((string) $record->image_path);
        abort_unless(is_string($root) && is_string($image) && is_file($image), 404);
        abort_unless($this->pathKey(dirname($image)) === $this->pathKey($root), 403);

        return response()->file($image, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function batchAction(Request $request, TgVideoReviewActionService $service): JsonResponse
    {
        set_time_limit(0);
        ini_set('max_execution_time', '0');

        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:2000'],
            'items.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.action' => ['required', 'in:delete,ok,watermark'],
        ]);
        $items = array_map(static fn (array $item): array => [
            'id' => (int) $item['id'],
            'action' => (string) $item['action'],
        ], $validated['items']);
        $ids = array_column($items, 'id');
        $records = TgVideoReview::query()->whereIn('id', $ids)->get()->keyBy('id');
        $completed = [];
        $failed = [];

        foreach ($items as $item) {
            $id = $item['id'];
            $record = $records->get($id);
            if (!$record instanceof TgVideoReview) {
                $failed[] = ['id' => $id, 'message' => '找不到指定資料。'];
                continue;
            }

            $result = $service->handle($record, $item['action']);
            if ($result['ok']) {
                $completed[] = $id;
            } else {
                $failed[] = ['id' => $id, 'message' => $result['message']];
            }
        }

        return response()->json([
            'ok' => $failed === [],
            'completed_ids' => $completed,
            'failed' => $failed,
            'message' => sprintf('完成 %d 筆，失敗 %d 筆。', count($completed), count($failed)),
        ], 200, [], JSON_INVALID_UTF8_SUBSTITUTE);
    }

    private function pathKey(string $path): string
    {
        return mb_strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }
}
