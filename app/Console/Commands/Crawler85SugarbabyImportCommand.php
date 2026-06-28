<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CrawlerProfileCandidate;
use Carbon\CarbonImmutable;
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
                            {--limit=10 : Maximum number of profiles to upsert}
                            {--source=85sugarbaby_active_flow : Deduplication source label}
                            {--output-dir= : Directory for crawler output files}
                            {--headless : Run without a visible browser window}
                            {--clear-source : Remove existing rows for the target source before import}
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
            '--timeout=' . max(5, (int) $this->option('timeout')),
            '--probe-85sugarbaby',
            '--active-clicks=1',
        ];

        if ((bool) $this->option('headless')) {
            $args[] = '--headless';
        }

        $this->info('Running 85sugarbaby crawler probe and import flow...');
        $process = new Process($args, base_path(), null, null, max(15, (int) $this->option('timeout') + 90));
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

        $source = trim((string) $this->option('source'));
        if ($source === '') {
            $source = '85sugarbaby_active_flow';
        }

        $filter = [
            'source' => $source,
            'areas' => $this->normalizeAreaList((string) $this->option('areas')),
            'age_min' => (int) $this->option('age-min'),
            'age_max' => (int) $this->option('age-max'),
            'source_note' => 'live-api import',
        ];

        if ((bool) $this->option('clear-source')) {
            CrawlerProfileCandidate::query()->where('source', $source)->delete();
            $this->info('Cleared previous rows for source: ' . $source);
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
        $inserted = 0;
        foreach ($toImport as $candidate) {
            $row = CrawlerProfileCandidate::query()->updateOrCreate(
                [
                    'source' => $source,
                    'external_user_id' => $candidate['external_user_id'],
                ],
                [
                    'nickname' => $candidate['nickname'],
                    'age' => $candidate['age'],
                    'area' => $candidate['area'],
                    'profile_url' => $candidate['profile_url'],
                    'matched_filter_json' => $filter,
                    'raw_payload' => $candidate['raw_payload'],
                    'captured_at' => $capturedAt,
                ]
            );

            $existingImageHashes = [];
            foreach ($candidate['images'] as $sortOrder => $imageUrl) {
                $hash = hash('sha256', $imageUrl);
                $existingImageHashes[] = $hash;
                $row->images()->updateOrCreate(
                    ['image_url_hash' => $hash],
                    [
                        'image_url' => $imageUrl,
                        'sort_order' => $sortOrder + 1,
                        'captured_at' => $capturedAt,
                    ]
                );
            }

            $row->images()->whereNotIn('image_url_hash', $existingImageHashes)->delete();
            $inserted += 1;
            $this->line('upsert ' . $candidate['external_user_id']);
        }

        $this->info('Import complete: ' . $inserted . ' records written.');

        return self::SUCCESS;
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
            $images = $this->extractImageUrls($row);

            $out[] = [
                'external_user_id' => $userId,
                'nickname' => $nickname ?? '',
                'age' => $age,
                'area' => $area,
                'profile_url' => $profileUrl,
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
}
