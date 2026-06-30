<?php

namespace App\Http\Controllers;

use App\Models\CrawlerProfileCandidate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
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
            'localLoginUrl' => config('crawler.85sugarbaby.local_login_url'),
            'crawlerEnabled' => (bool) config('crawler.85sugarbaby.enabled', true),
        ]);
    }

    public function loginSession(Request $request): RedirectResponse
    {
        if (app()->environment('testing')) {
            return redirect()
                ->route('crawler.profiles')
                ->with('crawler_status', '測試模式已略過啟動 Chrome。');
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return redirect()
                ->route('crawler.profiles')
                ->with('crawler_error', '這個登入按鈕需要在本機 Windows 的 blog.test 執行；AWS 主機沒有可互動 Chrome 視窗。');
        }

        $command = sprintf(
            'cmd /C start "85sugarbaby login" /D %s %s artisan crawler:85sugarbaby-login --timeout=300',
            escapeshellarg(base_path()),
            escapeshellarg(PHP_BINARY)
        );

        $handle = popen($command, 'r');
        if ($handle === false) {
            return redirect()
                ->route('crawler.profiles')
                ->with('crawler_error', '無法啟動登入用 Chrome，請改用 php artisan crawler:85sugarbaby-login。');
        }

        pclose($handle);

        return redirect()
            ->route('crawler.profiles')
            ->with('crawler_status', '已啟動登入用 Chrome。完成 Google 登入後，排程會用更新後的 session 繼續抓資料。');
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
