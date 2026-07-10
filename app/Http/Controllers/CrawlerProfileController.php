<?php

namespace App\Http\Controllers;

use App\Models\CrawlerProfileCandidate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use SplFileInfo;

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
            'localLoginUrl' => route('crawler.profiles.login-session', [], false),
            'crawlerEnabled' => (bool) config('crawler.85sugarbaby.enabled', true),
            'crawlerRuntime' => $this->crawlerRuntimeStatus(),
        ]);
    }

    public function loginSession(Request $request): RedirectResponse
    {
        if ($this->isAutomatedTestRequest()) {
            return redirect()
                ->route('crawler.profiles')
                ->with('crawler_status', '測試模式已略過啟動 Chrome。');
        }

        $manualRefreshLock = 'crawler:85sugarbaby:manual-session-refresh';
        if (! Cache::add($manualRefreshLock, true, now()->addMinute())) {
            return redirect()
                ->route('crawler.profiles')
                ->with('crawler_status', 'AWS Session 驗證與抓取已在執行，請稍後重新整理。');
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? $this->crawlerLoginLauncherCommand()
            : $this->crawlerAwsRefreshCommand();

        $handle = popen($command, 'r');
        if ($handle === false) {
            Cache::forget($manualRefreshLock);

            return redirect()
                ->route('crawler.profiles')
                ->with('crawler_error', '無法啟動登入用 Chrome，請改用 php artisan crawler:85sugarbaby-login。');
        }

        pclose($handle);

        $message = PHP_OS_FAMILY === 'Windows'
            ? '已啟動登入用 Chrome。完成 Google 登入後，排程會用更新後的 Session 繼續抓資料。'
            : '已在 AWS 啟動 Session 驗證與抓取，完成後排程會延續使用更新後的 Session。';

        return redirect()
            ->route('crawler.profiles')
            ->with('crawler_status', $message);
    }

    private function crawlerLoginLauncherCommand(): string
    {
        $taskName = 'Blog 85Sugarbaby Login';
        $queryCommand = 'schtasks /Query /TN ' . $this->cmdQuote($taskName) . ' >NUL 2>NUL';
        $taskExists = false;
        exec($queryCommand, $output, $exitCode);
        $taskExists = $exitCode === 0;

        if ($taskExists) {
            return 'schtasks /Run /TN ' . $this->cmdQuote($taskName) . ' >NUL 2>NUL';
        }

        $scriptPath = base_path('scripts/start_85sugarbaby_login_visible.ps1');

        return 'powershell.exe -NoProfile -ExecutionPolicy Bypass -File ' . $this->cmdQuote($scriptPath) . ' >NUL 2>NUL';
    }

    private function cmdQuote(string $value): string
    {
        return '"' . str_replace('"', '\"', $value) . '"';
    }

    private function crawlerAwsRefreshCommand(): string
    {
        return sprintf(
            'cd %s && nohup %s artisan crawler:85sugarbaby-import --headless --source=85sugarbaby_active_flow --limit=20 --age-min=18 --age-max=22 --areas=%s --timeout=90 >> %s 2>&1 &',
            escapeshellarg(base_path()),
            escapeshellarg('/usr/bin/php'),
            escapeshellarg('台北,新北'),
            escapeshellarg(storage_path('logs/crawler_85sugarbaby_manual.log'))
        );
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

    /**
     * @return array{
     *     enabled: bool,
     *     state: string,
     *     label: string,
     *     latest_run_at: Carbon|null,
     *     rows: int|null,
     *     final_url: string|null,
     *     reason: string|null
     * }
     */
    private function crawlerRuntimeStatus(): array
    {
        $enabled = (bool) config('crawler.85sugarbaby.enabled', true);
        $status = [
            'enabled' => $enabled,
            'state' => $enabled ? 'unknown' : 'disabled',
            'label' => $enabled ? '啟用，尚無最新執行紀錄' : '停用',
            'latest_run_at' => null,
            'rows' => null,
            'final_url' => null,
            'reason' => null,
        ];

        if (! $enabled) {
            return $status;
        }

        $latestMeta = $this->latestCrawlerMeta((string) config('crawler.85sugarbaby.import_output_dir'));
        $latestLoginMeta = $this->latestCrawlerMeta((string) config('crawler.85sugarbaby.login_output_dir'));
        if ($latestMeta === null) {
            if ($this->crawlerMetaLooksAuthenticated($latestLoginMeta)) {
                return $this->sessionRefreshedStatus($status, $latestLoginMeta);
            }

            return $status;
        }

        $status['latest_run_at'] = $this->crawlerMetaRunAt($latestMeta);

        $probe = is_array($latestMeta['payload']['api_probe_summary'] ?? null)
            ? $latestMeta['payload']['api_probe_summary']
            : [];
        $endpoint = is_array($probe['endpoints']['/GetLoginListByLoginTime'] ?? null)
            ? $probe['endpoints']['/GetLoginListByLoginTime']
            : [];

        $rows = $endpoint['rows'] ?? null;
        $status['rows'] = is_numeric($rows) ? (int) $rows : null;
        $status['final_url'] = $this->stringOrNull($latestMeta['payload']['final_url'] ?? null);
        $status['reason'] = $this->stringOrNull($latestMeta['payload']['reason'] ?? null);

        $probeStatus = (string) ($latestMeta['payload']['status'] ?? '');
        $isLoggedIn = $probe['isLoggedIn'] ?? null;
        $haystack = strtolower($probeStatus . ' ' . ($status['reason'] ?? '') . ' ' . ($status['final_url'] ?? ''));

        if ($isLoggedIn === true || ($status['rows'] !== null && $status['rows'] > 0)) {
            $status['state'] = 'ok';
            $status['label'] = '啟用，最新抓取正常';

            return $status;
        }

        if ($this->newerAuthenticatedMeta($latestLoginMeta, $status['latest_run_at'])) {
            return $this->sessionRefreshedStatus($status, $latestLoginMeta);
        }

        if ($isLoggedIn === false || str_contains($haystack, 'login')) {
            $status['state'] = 'session_expired';
            $status['label'] = '啟用，但 Session 失效';
            $status['rows'] ??= 0;

            return $status;
        }

        if (str_contains($haystack, 'cloudflare') || str_contains($haystack, 'security')) {
            $status['state'] = 'blocked';
            $status['label'] = '啟用，但網站驗證阻擋';

            return $status;
        }

        $status['state'] = 'failing';
        $status['label'] = '啟用，但最新抓取失敗';

        return $status;
    }

    /**
     * @return array{file: SplFileInfo, payload: array<string, mixed>}|null
     */
    private function latestCrawlerMeta(string $dir): ?array
    {
        if ($dir === '' || ! is_dir($dir)) {
            return null;
        }

        $latestFile = null;
        $latestMtime = -1;
        foreach (glob($dir . DIRECTORY_SEPARATOR . '*_meta.json') ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $mtime = filemtime($path);
            if ($mtime === false || $mtime < $latestMtime) {
                continue;
            }

            $latestFile = new SplFileInfo($path);
            $latestMtime = $mtime;
        }

        if ($latestFile === null) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($latestFile->getPathname()), true);
        if (! is_array($payload)) {
            return null;
        }

        return [
            'file' => $latestFile,
            'payload' => $payload,
        ];
    }

    /**
     * @param array{file: SplFileInfo, payload: array<string, mixed>} $latestMeta
     */
    private function crawlerMetaRunAt(array $latestMeta): Carbon
    {
        return $this->parseCrawlerTimestamp($latestMeta['payload']['captured_at'] ?? null)
            ?? Carbon::createFromTimestamp($latestMeta['file']->getMTime(), config('app.timezone'));
    }

    /**
     * @param array{file: SplFileInfo, payload: array<string, mixed>}|null $latestMeta
     */
    private function crawlerMetaLooksAuthenticated(?array $latestMeta): bool
    {
        if ($latestMeta === null) {
            return false;
        }

        $probe = is_array($latestMeta['payload']['api_probe_summary'] ?? null)
            ? $latestMeta['payload']['api_probe_summary']
            : [];
        $endpoint = is_array($probe['endpoints']['/GetLoginListByLoginTime'] ?? null)
            ? $probe['endpoints']['/GetLoginListByLoginTime']
            : [];
        $rows = $endpoint['rows'] ?? null;

        return ($probe['isLoggedIn'] ?? null) === true
            || (is_numeric($rows) && (int) $rows > 0);
    }

    /**
     * @param array{file: SplFileInfo, payload: array<string, mixed>}|null $latestMeta
     */
    private function newerAuthenticatedMeta(?array $latestMeta, ?Carbon $baseline): bool
    {
        if (! $this->crawlerMetaLooksAuthenticated($latestMeta)) {
            return false;
        }

        if (! $baseline instanceof Carbon) {
            return true;
        }

        return $this->crawlerMetaRunAt($latestMeta)->greaterThan($baseline);
    }

    /**
     * @param array{file: SplFileInfo, payload: array<string, mixed>} $latestMeta
     */
    private function sessionRefreshedStatus(array $status, array $latestMeta): array
    {
        $probe = is_array($latestMeta['payload']['api_probe_summary'] ?? null)
            ? $latestMeta['payload']['api_probe_summary']
            : [];
        $endpoint = is_array($probe['endpoints']['/GetLoginListByLoginTime'] ?? null)
            ? $probe['endpoints']['/GetLoginListByLoginTime']
            : [];
        $rows = $endpoint['rows'] ?? null;

        $status['state'] = 'ok';
        $status['label'] = '啟用，Session 已重新整理';
        $status['latest_run_at'] = $this->crawlerMetaRunAt($latestMeta);
        $status['rows'] = is_numeric($rows) ? (int) $rows : null;
        $status['final_url'] = $this->stringOrNull($latestMeta['payload']['final_url'] ?? null);
        $status['reason'] = null;

        return $status;
    }

    private function parseCrawlerTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->timezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function isAutomatedTestRequest(): bool
    {
        return app()->runningUnitTests()
            || app()->environment('testing')
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__')
            || class_exists(\PHPUnit\Framework\TestCase::class, false);
    }
}
