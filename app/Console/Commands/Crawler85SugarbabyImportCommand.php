<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CrawlerProfileCandidate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class Crawler85SugarbabyImportCommand extends Command
{
    protected $signature = 'crawler:85sugarbaby-import
                            {--url=https://85sugarbaby.com.tw/home : Target page URL}
                            {--profile= : Chrome user data directory that stores the reusable login session}
                            {--timeout=120 : Seconds to wait for page load and API responses}
                            {--age-min=18 : Minimum age to include}
                            {--age-max=22 : Maximum age to include}
                            {--areas=台北,新北 : Comma-separated area names to include}
                             {--limit=20 : Maximum number of profiles to upsert}
                             {--active-clicks=1 : Number of times to click the active button for pagination-like refresh}
                             {--source=85sugarbaby_active_flow : Deduplication source label}
                             {--output-dir= : Directory for crawler output files}
                             {--headless : Run without a visible browser window}
                             {--clear-source : Deprecated no-op; imports are append-only}
                             {--backfill-only : Only backfill missing chat links using existing rows of source}
                             {--backfill-chat-links : Populate chat_url for rows missing chat URL only}
                             {--backfill-metrics : Populate height/weight for rows missing metric data only}
                             {--dry-run : Print what would be written without writing DB changes}
                             {--skip-import : Only fetch and print candidate count summary}';

    protected $description = 'Import active members from 85sugarbaby into crawler_profile_candidates.';

    private const AREA_MAP = [
        '1' => '台北',
        '2' => '新北',
        '3' => '基隆',
        '4' => '宜蘭',
        '5' => '花蓮',
        '6' => '桃園',
        '7' => '新竹',
        '8' => '苗栗',
        '9' => '台中',
        '10' => '彰化',
        '11' => '南投',
        '12' => '雲林',
        '13' => '嘉義',
        '14' => '台南',
        '15' => '高雄',
        '16' => '屏東',
        '17' => '台東',
        '18' => '金門',
        '19' => '連江',
        '20' => '澎湖',
        '21' => '香港',
        '22' => '澳門',
    ];

    private const API_ENDPOINT = '/GetLoginListByLoginTime';

    public function handle(): int
    {
        if ($this->loginRefreshLockIsActive()) {
            $this->warn('85sugarbaby login session refresh is active; skipping import to avoid Chrome profile contention.');

            return self::SUCCESS;
        }

        $source = trim((string) $this->option('source'));
        if ($source === '') {
            $source = '85sugarbaby_active_flow';
        }

        if ((bool) $this->option('backfill-only')) {
            $chat = (bool) $this->option('backfill-chat-links');
            $metrics = (bool) $this->option('backfill-metrics');
            if (!$chat && !$metrics) {
                $chat = true;
                $metrics = true;
            }
            $count = $this->backfillMissingFields($source, $chat, $metrics);
            $this->info('backfill-only mode complete for source=' . $source);

            return self::SUCCESS;
        }

        $url = trim((string) $this->option('url'));
        if (!$this->isHttpUrl($url)) {
            $this->error('The --url value must be a valid http(s) URL.');

            return self::FAILURE;
        }

        $baseDir = (string) $this->option('output-dir');
        if ($baseDir === '') {
            $baseDir = storage_path('app/google-login-crawler/85sugarbaby-import');
        }

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        $stamp = CarbonImmutable::now()->format('Ymd_His');
        $scriptPath = base_path('scripts/google_login_crawler_probe.mjs');
        if (!is_file($scriptPath)) {
            $this->error('Crawler probe script was not found: ' . $scriptPath);

            return self::FAILURE;
        }

        $profilePath = $this->option('profile')
            ? (string) $this->option('profile')
            : storage_path('app/google-login-crawler/chrome-profile');
        $apiOutput = $baseDir . DIRECTORY_SEPARATOR . $stamp . '_api.json';
        $metaOutput = $baseDir . DIRECTORY_SEPARATOR . $stamp . '_meta.json';
        $htmlOutput = $baseDir . DIRECTORY_SEPARATOR . $stamp . '_page.html';
        $textOutput = $baseDir . DIRECTORY_SEPARATOR . $stamp . '_page.txt';

        $args = [
            $this->nodeBinary(),
            $scriptPath,
            '--url=' . $url,
            '--email=' . trim((string) config('services.google_login_crawler.email', 's454666123@gmail.com')),
            '--profile=' . $profilePath,
            '--output=' . $htmlOutput,
            '--text-output=' . $textOutput,
            '--meta-output=' . $metaOutput,
            '--api-output=' . $apiOutput,
            '--cookie-state=' . $this->cookieStatePath(),
            '--timeout=' . max(5, (int) $this->option('timeout')),
            '--probe-85sugarbaby',
            '--active-clicks=' . max(1, min(20, (int) $this->option('active-clicks'))),
        ];

        if ((bool) $this->option('headless')) {
            $args[] = '--headless';
        }

        $this->info('Running 85sugarbaby crawler probe and import flow...');
        $process = new Process($args, base_path(), null, null, max(30, (int) $this->option('timeout') + 25));
        $process->run(function (string $type, string $buffer): void {
            if ($type === Process::ERR) {
                $this->getOutput()->write('<error>' . $buffer . '</error>');

                return;
            }

            $this->getOutput()->write($buffer);
        });

        if (!$process->isSuccessful()) {
            $this->error('Crawler process failed: ' . $process->getErrorOutput());

            return self::FAILURE;
        }

        $apiPayload = $this->readActiveListFromApiOutput($apiOutput);
        if (empty($apiPayload)) {
            $this->warn('API payload missing or no rows found from /GetLoginListByLoginTime.');

            return self::FAILURE;
        }

        $filtered = $this->filterCandidates($apiPayload);
        $this->info('Candidates matched: ' . count($filtered) . ' / raw: ' . count($apiPayload));

        if (count($filtered) === 0) {
            $this->warn('No candidate matches the current area/age filter.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $toImport = array_slice($filtered, 0, $limit);

        if ((bool) $this->option('skip-import')) {
            $this->line('skip-import=true, first ' . min($limit, count($filtered)) . ' item(s) preview:');
            foreach ($toImport as $index => $candidate) {
                $this->line(sprintf('%d) %s %s', $index + 1, $candidate['external_user_id'], $candidate['nickname']));
            }

            return self::SUCCESS;
        }

        $filter = [
            'source' => $source,
            'areas' => $this->normalizeAreaList((string) $this->option('areas')),
            'age_min' => (int) $this->option('age-min'),
            'age_max' => (int) $this->option('age-max'),
            'source_note' => 'live-api import',
        ];

        if ((bool) $this->option('clear-source')) {
            $this->warn('--clear-source is deprecated and ignored. Crawler imports are append-only for source: ' . $source);
        }

        if ((bool) $this->option('dry-run')) {
            $this->warn('dry-run=true, no DB write. Would upsert:' . count($toImport));
            foreach ($toImport as $index => $candidate) {
                $this->line(sprintf(
                    '%d) %s - %s (%s, age=%s) profile=%s',
                    $index + 1,
                    $candidate['external_user_id'],
                    $candidate['nickname'],
                    $candidate['area'],
                    $candidate['age'],
                    $candidate['profile_url']
                ));
            }

            return self::SUCCESS;
        }

        $capturedAt = now();
        $existingUserIds = CrawlerProfileCandidate::query()
            ->where('source', $source)
            ->whereIn('external_user_id', array_column($toImport, 'external_user_id'))
            ->pluck('external_user_id')
            ->map(static fn ($userId): string => (string) $userId)
            ->all();
        $existingUserIdLookup = array_fill_keys($existingUserIds, true);
        $newCandidates = array_values(array_filter(
            $toImport,
            static fn (array $candidate): bool => !isset($existingUserIdLookup[(string) $candidate['external_user_id']])
        ));

        if (count($newCandidates) < count($toImport)) {
            $this->info('Skipped existing records: ' . (count($toImport) - count($newCandidates)));
        }

        $inserted = $this->persistCandidates($source, $newCandidates, $filter, $capturedAt);
        foreach ($newCandidates as $candidate) {
            $this->line('insert ' . $candidate['external_user_id']);
        }

        $backfilled = 0;
        if ((bool) $this->option('backfill-chat-links') || (bool) $this->option('backfill-metrics')) {
            $backfilled = $this->backfillMissingFields(
                $source,
                (bool) $this->option('backfill-chat-links'),
                (bool) $this->option('backfill-metrics')
            );
        }

        $message = 'Import complete: ' . $inserted . ' new records written.';
        if ($backfilled > 0) {
            $message .= ' and ' . $backfilled . ' records enriched.';
        }
        $this->info($message);

        return self::SUCCESS;
    }

    private function persistCandidates(string $source, array $toImport, array $filter, DateTimeInterface $capturedAt): int
    {
        $inserted = 0;
        foreach ($toImport as $candidate) {
            $row = CrawlerProfileCandidate::query()->firstOrCreate(
                [
                    'source' => $source,
                    'external_user_id' => $candidate['external_user_id'],
                ],
                [
                    'nickname' => $candidate['nickname'],
                    'age' => $candidate['age'],
                    'area' => $candidate['area'],
                    'profile_url' => $candidate['profile_url'],
                    'chat_url' => $candidate['chat_url'],
                    'height' => $candidate['height'],
                    'weight' => $candidate['weight'],
                    'matched_filter_json' => $filter,
                    'raw_payload' => $candidate['raw_payload'],
                    'captured_at' => $capturedAt,
                ]
            );

            if (!$row->wasRecentlyCreated) {
                continue;
            }

            foreach ($candidate['images'] as $sortOrder => $imageUrl) {
                $row->images()->updateOrCreate(
                    ['image_url_hash' => hash('sha256', $imageUrl)],
                    [
                        'image_url' => $imageUrl,
                        'sort_order' => $sortOrder + 1,
                        'captured_at' => $capturedAt,
                    ]
                );
            }

            $inserted += 1;
        }

        return $inserted;
    }

    private function backfillMissingFields(string $source, bool $fillChatLinks, bool $fillMetrics): int
    {
        if (!$fillChatLinks && !$fillMetrics) {
            return 0;
        }

        $query = CrawlerProfileCandidate::query()
            ->where('source', $source)
            ->whereNotNull('external_user_id');

        if ($fillChatLinks && $fillMetrics) {
            $query->where(function ($query): void {
                $query->whereNull('chat_url')->orWhere('chat_url', '')
                    ->orWhereNull('height')
                    ->orWhereNull('weight');
            });
        } elseif ($fillChatLinks) {
            $query->where(function ($query): void {
                $query->whereNull('chat_url')->orWhere('chat_url', '');
            });
        } else {
            $query->where(function ($query): void {
                $query->whereNull('height')->orWhereNull('weight');
            });
        }

        $rows = $query->orderByDesc('captured_at')->get();
        $updated = 0;
        foreach ($rows as $row) {
            $userId = trim((string) $row->external_user_id);
            if ($userId === '') {
                continue;
            }

            $shouldSave = false;

            if ($fillChatLinks && trim((string) $row->chat_url) === '') {
                $row->chat_url = 'https://85sugarbaby.com.tw/chatroom?peerid=' . rawurlencode($userId);
                $shouldSave = true;
            }

            if ($fillMetrics) {
                $payload = is_array($row->raw_payload) ? $row->raw_payload : [];
                if (($row->height === null || $row->height === 0) && isset($payload['Height'])) {
                    $height = $this->extractNumeric($payload, ['Height', 'height', '身高']);
                    if ($height > 0) {
                        $row->height = $height;
                        $shouldSave = true;
                    }
                }

                if (($row->weight === null || $row->weight === 0) && isset($payload['Weight'])) {
                    $weight = $this->extractNumeric($payload, ['Weight', 'weight', '體重']);
                    if ($weight > 0) {
                        $row->weight = $weight;
                        $shouldSave = true;
                    }
                }
            }

            if (!$shouldSave) {
                continue;
            }

            $row->save();
            $updated += 1;
        }

        if ($fillChatLinks && $fillMetrics) {
            $this->info('Backfilled chat links and metrics for ' . $updated . ' rows in source: ' . $source);
        } elseif ($fillChatLinks) {
            $this->info('Backfilled chat urls for ' . $updated . ' rows in source: ' . $source);
        } else {
            $this->info('Backfilled metrics for ' . $updated . ' rows in source: ' . $source);
        }

        return $updated;
    }

    private function readActiveListFromApiOutput(string $apiOutput): array
    {
        if (!is_file($apiOutput)) {
            return [];
        }

        $raw = json_decode((string) file_get_contents($apiOutput), true);
        if (!is_array($raw)) {
            return [];
        }

        $endpoint = $raw['endpoints'][self::API_ENDPOINT] ?? null;
        if (!is_array($endpoint) || (!isset($endpoint['data']) && !array_key_exists('rows', $endpoint))) {
            return [];
        }

        $rows = $endpoint['data'] ?? $endpoint['rows'];
        if (!is_array($rows)) {
            return [];
        }

        return $rows;
    }

    private function filterCandidates(array $rows): array
    {
        $areas = array_map(
            static fn (string $area): string => trim($area),
            $this->normalizeAreaList((string) $this->option('areas'))
        );
        $ageMin = (int) $this->option('age-min');
        $ageMax = (int) $this->option('age-max');

        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $userId = $this->extractString($row, ['UserId', 'user_id', 'userId', 'Id', 'ID', 'MemberId']);
            if ($userId === null) {
                continue;
            }

            if (isset($seen[$userId])) {
                continue;
            }

            $nickname = $this->extractString($row, ['Nickname', 'nickName', 'Name', 'name', 'UserName']);
            $age = (int) $this->extractNumeric($row, ['Age', 'age']);
            $area = $this->normalizeArea($this->extractString($row, ['Area', 'area', 'City', 'city', 'AreaName', 'County']));
            if ($area === null) {
                $area = $this->normalizeArea((string) $this->extractNumeric($row, ['AreaId', 'AreaID', 'area_id']));
            }

            if ($area === null || $age < $ageMin || $age > $ageMax || !in_array($area, $areas, true)) {
                continue;
            }

            $profileUrl = 'https://85sugarbaby.com.tw/view?user_id=' . rawurlencode($userId);
            $chatUrl = 'https://85sugarbaby.com.tw/chatroom?peerid=' . rawurlencode($userId);
            $images = $this->extractImageUrls($row);
            $height = $this->extractNumeric($row, ['Height', 'height', '身高']);
            $weight = $this->extractNumeric($row, ['Weight', 'weight', '體重']);

            $out[] = [
                'external_user_id' => $userId,
                'nickname' => $nickname ?? '',
                'age' => $age,
                'area' => $area,
                'profile_url' => $profileUrl,
                'chat_url' => $chatUrl,
                'height' => $height > 0 ? $height : null,
                'weight' => $weight > 0 ? $weight : null,
                'images' => $images,
                'raw_payload' => $row,
            ];

            $seen[$userId] = true;
        }

        return $out;
    }

    private function normalizeAreaList(string $areas): array
    {
        return array_values(
            array_filter(array_unique(array_map(static fn (string $area): string => trim($area), explode(',', $areas))))
        );
    }

    private function normalizeArea(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (is_numeric($value)) {
            $mapped = self::AREA_MAP[(string) (int) $value] ?? null;
            if ($mapped !== null) {
                return $mapped;
            }

            return (string) (int) $value;
        }

        return $value;
    }

    private function extractString(array $row, array $candidates): ?string
    {
        foreach ($candidates as $name) {
            if (!array_key_exists($name, $row)) {
                continue;
            }

            $value = trim((string) $row[$name]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function extractNumeric(array $row, array $candidates): int
    {
        foreach ($candidates as $name) {
            if (!array_key_exists($name, $row)) {
                continue;
            }

            $candidate = (string) $row[$name];
            $numeric = trim((string) preg_replace('/\D+/', '', $candidate));
            if ($numeric !== '') {
                return (int) $numeric;
            }
        }

        return 0;
    }

    private function extractImageUrls(array $row): array
    {
        $candidates = [
            'HeadPic',
            'Headpic',
            'headPic',
            'headpic',
            'Picture',
            'picture',
            'Image',
            'image',
            'Avatar',
            'avatar',
            'Photo',
            'photo',
        ];

        $images = [];
        $seen = [];
        foreach ($candidates as $name) {
            if (!array_key_exists($name, $row)) {
                continue;
            }

            $candidate = trim((string) $row[$name]);
            if ($candidate === '') {
                continue;
            }

            if (!Str::startsWith($candidate, ['http://', 'https://'])) {
                if (Str::startsWith($candidate, '/')) {
                    $candidate = 'https://85sugarbaby.com.tw' . $candidate;
                } else {
                    continue;
                }
            }

            if (!isset($seen[$candidate])) {
                $seen[$candidate] = true;
                $images[] = $candidate;
            }
        }

        // Keep compatibility with 85sugarbaby API fields (Pic1..Pic9).
        for ($i = 1; $i <= 9; $i++) {
            $candidate = trim((string) ($row['Pic' . $i] ?? ''));
            if ($candidate === '') {
                continue;
            }

            if (!Str::startsWith($candidate, ['http://', 'https://'])) {
                if (Str::startsWith($candidate, '/')) {
                    $candidate = 'https://85sugarbaby.com.tw' . $candidate;
                } else {
                    continue;
                }
            }

            if (!isset($seen[$candidate])) {
                $seen[$candidate] = true;
                $images[] = $candidate;
            }
        }

        return $images;
    }

    private function isHttpUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        return in_array($scheme, ['http', 'https'], true);
    }

    private function nodeBinary(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';
    }

    private function loginRefreshLockIsActive(): bool
    {
        $lockPath = storage_path('app/google-login-crawler/85sugarbaby-login.lock');
        if (!is_file($lockPath)) {
            return false;
        }

        $mtime = filemtime($lockPath);
        if ($mtime === false) {
            return false;
        }

        $ttl = max(600, (int) config('crawler.85sugarbaby.login_lock_ttl', 1800));

        return $mtime >= time() - $ttl;
    }

    private function cookieStatePath(): string
    {
        return (string) config(
            'crawler.85sugarbaby.cookie_state_path',
            storage_path('app/google-login-crawler/85sugarbaby-session-cookies.json')
        );
    }
}
