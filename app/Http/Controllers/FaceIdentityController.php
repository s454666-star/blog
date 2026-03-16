<?php

namespace App\Http\Controllers;

use App\Models\FaceIdentityGroupChange;
use App\Models\FaceIdentityPerson;
use App\Models\FaceIdentitySample;
use App\Models\FaceIdentityVideo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FaceIdentityController extends Controller
{
    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $peopleQuery = FaceIdentityPerson::query()
            ->where('video_count', '>', 0);

        if ($q !== '') {
            $peopleQuery->where(function ($query) use ($q): void {
                $hasDirectIdFilter = false;

                if (ctype_digit($q)) {
                    $query->where('id', (int) $q);
                    $hasDirectIdFilter = true;
                }

                $method = $hasDirectIdFilter ? 'orWhereHas' : 'whereHas';
                $query->{$method}('videos', function ($videoQuery) use ($q): void {
                    $videoQuery->where('file_name', 'like', '%' . $q . '%')
                        ->orWhere('relative_path', 'like', '%' . $q . '%')
                        ->orWhere('absolute_path', 'like', '%' . $q . '%')
                        ->orWhere('source_root_label', 'like', '%' . $q . '%');
                });
            });
        }

        $people = $peopleQuery
            ->with([
                'videos' => function ($videoQuery): void {
                    $videoQuery
                        ->with([
                            'samples' => function ($sampleQuery): void {
                                $sampleQuery->orderBy('capture_order');
                            },
                        ])
                        ->withCount('samples')
                        ->orderByDesc('last_scanned_at')
                        ->orderBy('id');
                },
            ])
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->paginate(8)
            ->withQueryString();

        return view('face-identities.index', [
            'people' => $people,
            'q' => $q,
            'stats' => [
                'people_count' => FaceIdentityPerson::query()->where('video_count', '>', 0)->count(),
                'video_count' => FaceIdentityVideo::query()->count(),
                'manual_lock_count' => FaceIdentityVideo::query()->where('group_locked', 1)->count(),
                'last_scanned_at' => FaceIdentityVideo::query()->max('last_scanned_at'),
            ],
        ]);
    }

    public function detach(Request $request, FaceIdentityVideo $video): JsonResponse
    {
        $note = trim((string) $request->input('note', 'web detach'));

        $payload = DB::transaction(function () use ($video, $note): array {
            /** @var FaceIdentityVideo $lockedVideo */
            $lockedVideo = FaceIdentityVideo::query()
                ->with(['person', 'samples'])
                ->lockForUpdate()
                ->findOrFail($video->id);

            $fromPerson = $lockedVideo->person;

            if (!$fromPerson instanceof FaceIdentityPerson) {
                $lockedVideo->assignment_source = 'manual';
                $lockedVideo->group_locked = true;
                $lockedVideo->save();

                FaceIdentityGroupChange::query()->create([
                    'video_id' => $lockedVideo->id,
                    'from_person_id' => null,
                    'to_person_id' => null,
                    'action' => 'lock_single',
                    'note' => $note,
                    'metadata_json' => ['reason' => 'video_without_group'],
                ]);

                return [
                    'message' => '已鎖定此作品，不再自動併群。',
                    'person_code' => null,
                ];
            }

            $groupSize = FaceIdentityVideo::query()
                ->where('person_id', $fromPerson->id)
                ->lockForUpdate()
                ->count();

            if ($groupSize <= 1) {
                $lockedVideo->assignment_source = 'manual';
                $lockedVideo->group_locked = true;
                $lockedVideo->save();
                $this->refreshPersonSummary($fromPerson->id);

                FaceIdentityGroupChange::query()->create([
                    'video_id' => $lockedVideo->id,
                    'from_person_id' => $fromPerson->id,
                    'to_person_id' => $fromPerson->id,
                    'action' => 'lock_single',
                    'note' => $note,
                    'metadata_json' => ['reason' => 'single_video_group'],
                ]);

                return [
                    'message' => '此作品已是單獨群組，已改為手動鎖定。',
                    'person_code' => $fromPerson->display_code,
                ];
            }

            $newPerson = FaceIdentityPerson::query()->create([
                'feature_model' => (string) $lockedVideo->feature_model,
                'cover_sample_path' => $lockedVideo->preview_sample_path,
            ]);

            $lockedVideo->person()->associate($newPerson);
            $lockedVideo->assignment_source = 'manual';
            $lockedVideo->group_locked = true;
            $lockedVideo->match_confidence = null;
            $lockedVideo->save();

            FaceIdentitySample::query()
                ->where('video_id', $lockedVideo->id)
                ->update(['person_id' => $newPerson->id]);

            FaceIdentityGroupChange::query()->create([
                'video_id' => $lockedVideo->id,
                'from_person_id' => $fromPerson->id,
                'to_person_id' => $newPerson->id,
                'action' => 'detach_video',
                'note' => $note,
                'metadata_json' => [
                    'video_id' => $lockedVideo->id,
                    'previous_group_size' => $groupSize,
                ],
            ]);

            $this->refreshPersonSummary($fromPerson->id);
            $this->refreshPersonSummary($newPerson->id);

            return [
                'message' => '已將此作品拆成新的人物編號 ' . $newPerson->display_code,
                'person_code' => $newPerson->display_code,
            ];
        });

        return response()->json([
            'ok' => true,
            'message' => $payload['message'],
            'person_code' => $payload['person_code'],
        ]);
    }

    public function image(Request $request): BinaryFileResponse
    {
        $path = ltrim(str_replace('\\', '/', trim((string) $request->query('path', ''))), '/');
        abort_if($path === '', 404);
        abort_unless(str_starts_with($path, 'face-identity/'), 404);

        $absolutePath = Storage::disk('public')->path($path);
        abort_unless(is_file($absolutePath), 404);

        $mimeType = @mime_content_type($absolutePath) ?: 'image/jpeg';

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    public function video(FaceIdentityVideo $video): BinaryFileResponse
    {
        $absolutePath = trim((string) $video->absolute_path);
        abort_if($absolutePath === '', 404);
        abort_unless(is_file($absolutePath), 404);

        $mimeType = @mime_content_type($absolutePath) ?: 'video/mp4';

        return response()->file($absolutePath, [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'public, max-age=86400',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    private function refreshPersonSummary(?int $personId): void
    {
        if ($personId === null) {
            return;
        }

        /** @var FaceIdentityPerson|null $person */
        $person = FaceIdentityPerson::query()->find($personId);
        if (!$person instanceof FaceIdentityPerson) {
            return;
        }

        $videos = FaceIdentityVideo::query()
            ->where('person_id', $personId)
            ->orderBy('last_scanned_at')
            ->get([
                'preview_sample_path',
                'last_scanned_at',
            ]);

        if ($videos->isEmpty()) {
            $person->delete();
            return;
        }

        $samples = FaceIdentitySample::query()
            ->where('person_id', $personId)
            ->get([
                'embedding_json',
                'image_path',
            ]);

        $person->fill([
            'cover_sample_path' => $videos->pluck('preview_sample_path')->filter()->first()
                ?? $samples->pluck('image_path')->filter()->first(),
            'video_count' => $videos->count(),
            'sample_count' => $samples->count(),
            'first_seen_at' => $videos->pluck('last_scanned_at')->filter()->min(),
            'last_seen_at' => $videos->pluck('last_scanned_at')->filter()->max(),
            'centroid_embedding_json' => $this->buildCentroidEmbedding($samples),
        ]);

        $person->save();
    }

    private function buildCentroidEmbedding(Collection $samples): ?string
    {
        $vectors = [];
        $dimension = null;

        foreach ($samples as $sample) {
            $payload = json_decode((string) $sample->embedding_json, true);
            if (!is_array($payload) || $payload === []) {
                continue;
            }

            $vector = [];
            foreach ($payload as $value) {
                if (!is_numeric($value)) {
                    continue 2;
                }

                $vector[] = (float) $value;
            }

            if ($vector === []) {
                continue;
            }

            if ($dimension === null) {
                $dimension = count($vector);
            }

            if ($dimension !== count($vector)) {
                continue;
            }

            $vectors[] = $vector;
        }

        if ($vectors === [] || $dimension === null) {
            return null;
        }

        $sum = array_fill(0, $dimension, 0.0);

        foreach ($vectors as $vector) {
            foreach ($vector as $index => $value) {
                $sum[$index] += $value;
            }
        }

        $count = count($vectors);
        foreach ($sum as $index => $value) {
            $sum[$index] = $value / $count;
        }

        $norm = sqrt(array_sum(array_map(static fn (float $value): float => $value * $value, $sum)));
        if ($norm > 0) {
            foreach ($sum as $index => $value) {
                $sum[$index] = round($value / $norm, 8);
            }
        }

        return json_encode($sum, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
