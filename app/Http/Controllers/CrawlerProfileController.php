<?php

namespace App\Http\Controllers;

use App\Models\CrawlerProfileCandidate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class CrawlerProfileController extends Controller
{
    public function index(Request $request): View
    {
        $requestedSource = trim((string) $request->query('source', ''));
        $source = $requestedSource === '' ? $this->resolveDefaultSource() : $requestedSource;
        $perPage = (int) $request->query('per_page', 24);
        $perPage = min(max($perPage, 12), 60);

        $q = trim((string) $request->query('q', ''));
        $area = trim((string) $request->query('area', ''));

        $query = CrawlerProfileCandidate::query()
            ->with(['images' => function ($images) {
                $images->orderBy('sort_order');
            }])
            ->where('source', $source)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder->where('external_user_id', 'like', "%{$q}%")
                    ->orWhere('nickname', 'like', "%{$q}%")
                    ->orWhere('profile_url', 'like', "%{$q}%")
                    ->orWhere('chat_url', 'like', "%{$q}%");
            });
        }

        if ($area !== '') {
            $query->where('area', $area);
        }

        $candidates = $query->paginate($perPage)->appends($request->query());

        $stats = [
            'source' => $source,
            'total' => CrawlerProfileCandidate::query()->where('source', $source)->count(),
            'taibei' => CrawlerProfileCandidate::query()
                ->where('source', $source)
                ->where('area', '台北')
                ->count(),
            'newTaipei' => CrawlerProfileCandidate::query()
                ->where('source', $source)
                ->where('area', '新北')
                ->count(),
            'with_image' => CrawlerProfileCandidate::query()
                ->where('source', $source)
                ->whereHas('images')
                ->count(),
            'recent_created_at' => CrawlerProfileCandidate::query()
                ->where('source', $source)
                ->max('created_at'),
        ];

        $areas = [
            '台北',
            '新北',
        ];

        return view('crawler.profiles', [
            'candidates' => $candidates,
            'stats' => $stats,
            'query' => $q,
            'selectedArea' => $area,
            'areas' => $areas,
            'source' => $source,
            'perPage' => $perPage,
        ]);
    }

    private function resolveDefaultSource(): string
    {
        $preferredSource = '85sugarbaby_active_flow';
        $hasPreferred = CrawlerProfileCandidate::query()
            ->where('source', $preferredSource)
            ->whereHas('images')
            ->exists();

        if ($hasPreferred) {
            return $preferredSource;
        }

        $source = CrawlerProfileCandidate::query()
            ->whereHas('images')
            ->where('source', 'not like', 'synthetic_%')
            ->select('source')
            ->selectRaw('MAX(created_at) as latest')
            ->groupBy('source')
            ->orderByDesc('latest')
            ->orderByDesc('source')
            ->value('source');

        return (string) ($source ?: $preferredSource);
    }
}
