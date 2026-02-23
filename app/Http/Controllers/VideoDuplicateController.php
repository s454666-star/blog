<?php

    namespace App\Http\Controllers;

    use App\Models\VideoDuplicate;
    use Illuminate\Http\JsonResponse;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;
    use Illuminate\View\View;
    use Symfony\Component\Process\Process;
    use Throwable;

    class VideoDuplicateController extends Controller
    {
        public function index(Request $request): View
        {
            set_time_limit(6000);
            ini_set('max_execution_time', '6000');

            $q = trim((string)$request->query('q', ''));

            $seedRows = VideoDuplicate::query()
                ->select(['id', 'similar_video_ids'])
                ->whereNotNull('similar_video_ids')
                ->where('similar_video_ids', '!=', '')
                ->where('similar_video_ids', 'like', '%,%')
                ->get();

            $groupKeysToIds = [];
            foreach ($seedRows as $row) {
                $ids = $this->parseIds((string)$row->similar_video_ids);

                if (count($ids) < 2) {
                    continue;
                }

                $key = implode('-', $ids);
                $groupKeysToIds[$key] = $ids;
            }

            $allIds = [];
            foreach ($groupKeysToIds as $ids) {
                foreach ($ids as $id) {
                    $allIds[$id] = true;
                }
            }
            $allIds = array_keys($allIds);

            if (count($allIds) === 0) {
                return view('videos.duplicates.index', [
                    'groups' => [],
                    'q' => $q,
                    'stats' => [
                        'group_count' => 0,
                        'video_count' => 0,
                    ],
                ]);
            }

            $videosQuery = VideoDuplicate::query()->whereIn('id', $allIds);
            if ($q !== '') {
                $videosQuery->where(function ($w) use ($q) {
                    $w->where('filename', 'like', '%' . $q . '%')
                        ->orWhere('full_path', 'like', '%' . $q . '%')
                        ->orWhere('last_error', 'like', '%' . $q . '%');
                });
            }

            $videos = $videosQuery->get()->keyBy('id');

            $groups = [];
            $totalVideosShown = 0;

            foreach ($groupKeysToIds as $key => $ids) {
                $members = [];
                foreach ($ids as $id) {
                    if ($videos->has($id)) {
                        $members[] = $videos->get($id);
                    }
                }

                if (count($members) < 2) {
                    continue;
                }

                usort($members, function ($a, $b) {
                    $am = (int)($a->file_mtime ?? 0);
                    $bm = (int)($b->file_mtime ?? 0);

                    if ($am !== $bm) {
                        return $bm <=> $am;
                    }

                    return strnatcasecmp((string)$a->filename, (string)$b->filename);
                });

                $totalSize = 0;
                $durMin = null;
                $durMax = null;
                $groupMaxMtime = 0;

                foreach ($members as $m) {
                    $totalSize += (int)$m->file_size_bytes;

                    $d = (int)$m->duration_seconds;
                    $durMin = $durMin === null ? $d : min($durMin, $d);
                    $durMax = $durMax === null ? $d : max($durMax, $d);

                    $mt = (int)($m->file_mtime ?? 0);
                    if ($mt > $groupMaxMtime) {
                        $groupMaxMtime = $mt;
                    }
                }

                $groups[] = [
                    'key' => $key,
                    'count' => count($members),
                    'total_size_bytes' => $totalSize,
                    'total_size_human' => $this->humanBytes($totalSize),
                    'duration_range' => $this->formatDurationRange($durMin, $durMax),
                    'members' => $members,
                    'id_list' => array_values($ids),
                    'max_file_mtime' => $groupMaxMtime,
                ];

                $totalVideosShown += count($members);
            }

            usort($groups, function ($a, $b) {
                $am = (int)($a['max_file_mtime'] ?? 0);
                $bm = (int)($b['max_file_mtime'] ?? 0);

                if ($am !== $bm) {
                    return $bm <=> $am;
                }

                if ((int)$a['count'] !== (int)$b['count']) {
                    return (int)$b['count'] <=> (int)$a['count'];
                }

                if ((int)$a['total_size_bytes'] !== (int)$b['total_size_bytes']) {
                    return (int)$b['total_size_bytes'] <=> (int)$a['total_size_bytes'];
                }

                return strcmp((string)$a['key'], (string)$b['key']);
            });

            return view('videos.duplicates.index', [
                'groups' => $groups,
                'q' => $q,
                'stats' => [
                    'group_count' => count($groups),
                    'video_count' => $totalVideosShown,
                ],
            ]);
        }

        public function open(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'min:1'],
            ]);

            $video = VideoDuplicate::query()->findOrFail((int)$validated['id']);

            $path = (string)$video->full_path;
            if (!$this->isAllowedPath($path)) {
                return response()->json([
                    'ok' => false,
                    'message' => '此路徑不允許開啟（可用環境變數 VIDEO_DUP_ALLOWED_ROOTS 放行根目錄）',
                ], 403);
            }

            try {
                $this->openInFileManager($path);
                return response()->json(['ok' => true]);
            } catch (Throwable $e) {
                return response()->json([
                    'ok' => false,
                    'message' => '開啟失敗：' . $e->getMessage(),
                ], 500);
            }
        }

        public function markUnique(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'min:1'],
            ]);

            $targetId = (int)$validated['id'];

            $target = VideoDuplicate::query()->findOrFail($targetId);

            $relatedIds = $this->parseIds((string)$target->similar_video_ids);
            if (!in_array($targetId, $relatedIds, true)) {
                $relatedIds[] = $targetId;
                sort($relatedIds);
            }

            DB::beginTransaction();
            try {
                $rows = VideoDuplicate::query()
                    ->whereIn('id', $relatedIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if (!$rows->has($targetId)) {
                    DB::rollBack();
                    return response()->json([
                        'ok' => false,
                        'message' => '找不到指定影片',
                    ], 404);
                }

                foreach ($rows as $rowId => $row) {
                    $ids = $this->parseIds((string)$row->similar_video_ids);

                    if ((int)$rowId === $targetId) {
                        $newIds = [$targetId];
                    } else {
                        $newIds = array_values(array_filter($ids, function ($x) use ($targetId) {
                            return (int)$x !== $targetId;
                        }));

                        if (!in_array((int)$rowId, $newIds, true)) {
                            $newIds[] = (int)$rowId;
                        }

                        $newIds = array_values(array_unique(array_map('intval', $newIds)));
                        sort($newIds);
                    }

                    $newStr = implode(',', $newIds);

                    if ($newStr !== (string)$row->similar_video_ids) {
                        $row->similar_video_ids = $newStr;
                        $row->save();
                    }
                }

                DB::commit();

                return response()->json([
                    'ok' => true,
                    'affected_ids' => $relatedIds,
                ]);
            } catch (Throwable $e) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => '更新失敗：' . $e->getMessage(),
                ], 500);
            }
        }

        public function markGroupUnique(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'ids' => ['required', 'array', 'min:2'],
                'ids.*' => ['integer', 'min:1'],
            ]);

            $ids = array_values(array_unique(array_map('intval', (array)$validated['ids'])));
            $ids = array_values(array_filter($ids, function ($v) {
                return $v > 0;
            }));
            sort($ids);

            if (count($ids) < 2) {
                return response()->json([
                    'ok' => false,
                    'message' => 'ids 不足 2 筆',
                ], 422);
            }

            DB::beginTransaction();
            try {
                $rows = VideoDuplicate::query()
                    ->whereIn('id', $ids)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($rows->count() < 2) {
                    DB::rollBack();
                    return response()->json([
                        'ok' => false,
                        'message' => '找不到足夠的影片資料（至少要 2 筆）',
                    ], 404);
                }

                foreach ($rows as $rowId => $row) {
                    $newStr = (string)((int)$rowId);
                    if ($newStr !== (string)$row->similar_video_ids) {
                        $row->similar_video_ids = $newStr;
                        $row->save();
                    }
                }

                DB::commit();

                return response()->json([
                    'ok' => true,
                    'affected_ids' => array_keys($rows->all()),
                ]);
            } catch (Throwable $e) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => '更新失敗：' . $e->getMessage(),
                ], 500);
            }
        }

        public function delete(Request $request): JsonResponse
        {
            $validated = $request->validate([
                'id' => ['required', 'integer', 'min:1'],
            ]);

            $targetId = (int)$validated['id'];

            $target = VideoDuplicate::query()->findOrFail($targetId);

            $path = trim((string)$target->full_path);
            if ($path !== '' && !$this->isAllowedPath($path)) {
                return response()->json([
                    'ok' => false,
                    'message' => '此路徑不允許刪除（可用環境變數 VIDEO_DUP_ALLOWED_ROOTS 放行根目錄）',
                ], 403);
            }

            $relatedIds = $this->parseIds((string)$target->similar_video_ids);
            if (!in_array($targetId, $relatedIds, true)) {
                $relatedIds[] = $targetId;
                sort($relatedIds);
            }

            DB::beginTransaction();
            try {
                $rows = VideoDuplicate::query()
                    ->whereIn('id', $relatedIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if (!$rows->has($targetId)) {
                    DB::rollBack();
                    return response()->json([
                        'ok' => false,
                        'message' => '找不到指定影片',
                    ], 404);
                }

                foreach ($rows as $rowId => $row) {
                    if ((int)$rowId === $targetId) {
                        continue;
                    }

                    $ids = $this->parseIds((string)$row->similar_video_ids);
                    $newIds = array_values(array_filter($ids, function ($x) use ($targetId) {
                        return (int)$x !== $targetId;
                    }));

                    if (!in_array((int)$rowId, $newIds, true)) {
                        $newIds[] = (int)$rowId;
                    }

                    $newIds = array_values(array_unique(array_map('intval', $newIds)));
                    sort($newIds);

                    $newStr = implode(',', $newIds);
                    if ($newStr !== (string)$row->similar_video_ids) {
                        $row->similar_video_ids = $newStr;
                        $row->save();
                    }
                }

                $fileDeleted = null;
                $fileError = null;

                if ($path === '') {
                    $fileDeleted = false;
                    $fileError = 'full_path 為空，僅刪除資料庫記錄';
                } else {
                    $realPath = $path;
                    if (PHP_OS_FAMILY === 'Windows') {
                        $realPath = str_replace('/', '\\', $realPath);
                    }

                    if (!file_exists($realPath)) {
                        $fileDeleted = false;
                        $fileError = '檔案不存在，僅刪除資料庫記錄';
                    } else {
                        try {
                            $ok = @unlink($realPath);
                            if ($ok) {
                                $fileDeleted = true;
                            } else {
                                $fileDeleted = false;
                                $fileError = 'unlink 失敗（可能被占用或權限不足），仍刪除資料庫記錄';
                            }
                        } catch (Throwable $e) {
                            $fileDeleted = false;
                            $fileError = '刪檔例外：' . $e->getMessage() . '（仍刪除資料庫記錄）';
                        }
                    }
                }

                $rows->get($targetId)->delete();

                DB::commit();

                return response()->json([
                    'ok' => true,
                    'deleted_id' => $targetId,
                    'affected_ids' => array_values($relatedIds),
                    'file_deleted' => $fileDeleted,
                    'file_error' => $fileError,
                ]);
            } catch (Throwable $e) {
                DB::rollBack();
                return response()->json([
                    'ok' => false,
                    'message' => '刪除失敗：' . $e->getMessage(),
                ], 500);
            }
        }

        private function openInFileManager(string $path): void
        {
            $path = trim($path);
            if ($path === '') {
                throw new \RuntimeException('full_path 為空');
            }

            $os = PHP_OS_FAMILY;
            if ($os === 'Windows') {
                $p = str_replace('/', '\\', $path);
                $process = new Process(['explorer.exe', '/select,' . $p]);
                $process->setTimeout(3);
                $process->run();
                return;
            }

            $dir = dirname($path);

            if ($os === 'Darwin') {
                $process = new Process(['open', $dir]);
                $process->setTimeout(3);
                $process->run();
                return;
            }

            $process = new Process(['xdg-open', $dir]);
            $process->setTimeout(3);
            $process->run();
        }

        private function isAllowedPath(string $path): bool
        {
            $rootsRaw = (string)env('VIDEO_DUP_ALLOWED_ROOTS', '');
            $rootsRaw = trim($rootsRaw);
            if ($rootsRaw === '') {
                return true;
            }

            $roots = array_filter(array_map('trim', explode(';', $rootsRaw)), function ($v) {
                return $v !== '';
            });
            if (count($roots) === 0) {
                return true;
            }

            $os = PHP_OS_FAMILY;
            $p = $path;

            if ($os === 'Windows') {
                $p = strtolower(str_replace('/', '\\', $p));
                foreach ($roots as $r) {
                    $rr = strtolower(str_replace('/', '\\', $r));
                    if ($rr !== '' && str_starts_with($p, $rr)) {
                        return true;
                    }
                }
                return false;
            }

            foreach ($roots as $r) {
                if ($r !== '' && str_starts_with($p, $r)) {
                    return true;
                }
            }
            return false;
        }

        private function parseIds(string $raw): array
        {
            $raw = trim($raw);
            if ($raw === '') {
                return [];
            }

            $parts = array_map('trim', explode(',', $raw));
            $ids = [];
            foreach ($parts as $p) {
                if ($p === '') {
                    continue;
                }
                if (!ctype_digit($p)) {
                    continue;
                }
                $ids[] = (int)$p;
            }
            $ids = array_values(array_unique($ids));
            sort($ids);
            return $ids;
        }

        private function humanBytes(int $bytes): string
        {
            $units = ['B', 'KB', 'MB', 'GB', 'TB'];
            $i = 0;
            $size = (float)$bytes;

            while ($size >= 1024.0 && $i < count($units) - 1) {
                $size /= 1024.0;
                $i++;
            }

            if ($i === 0) {
                return (string)$bytes . ' ' . $units[$i];
            }

            return number_format($size, 2) . ' ' . $units[$i];
        }

        private function formatDurationRange(?int $min, ?int $max): string
        {
            if ($min === null || $max === null) {
                return '-';
            }
            if ($min === $max) {
                return $this->secToHms($min);
            }
            return $this->secToHms($min) . ' ~ ' . $this->secToHms($max);
        }

        private function secToHms(int $sec): string
        {
            if ($sec < 0) {
                $sec = 0;
            }
            $h = intdiv($sec, 3600);
            $m = intdiv($sec % 3600, 60);
            $s = $sec % 60;
            return sprintf('%02d:%02d:%02d', $h, $m, $s);
        }
    }
