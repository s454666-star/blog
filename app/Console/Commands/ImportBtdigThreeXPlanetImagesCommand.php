<?php

namespace App\Console\Commands;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Process\Process;
use Throwable;

class ImportBtdigThreeXPlanetImagesCommand extends Command
{
    private const MAX_CONSECUTIVE_SITE_FAILURES = 3;
    private const MAX_TOTAL_SITE_FAILURES = 3;
    private const REQUEST_RETRY_DELAYS_MS = [0, 5000, 15000];
    private const MIN_REQUEST_INTERVAL_US = 350000;
    private const MAX_REQUEST_INTERVAL_US = 900000;
    private const CHROME_FETCH_WAIT_SECONDS = 8;

    private ?CookieJar $cookieJar = null;
    private float $lastRequestAt = 0.0;

    protected $signature = 'btdig:import-3xplanet-images
                            {keyword? : Start keyword number or FC2-PPV-1237064}
                            {count? : Number of distinct search_keyword groups to process}
                            {--limit=100 : Number of distinct search_keyword groups to process}';

    protected $description = 'Fetch 3xplanet preview images for FC2 btdig results and store them as base64';

    public function handle(): int
    {
        if (!Schema::hasTable('btdig_result_images')) {
            $this->error('Table btdig_result_images does not exist. Run the migration first.');

            return self::FAILURE;
        }

        try {
            $startKeywordNumber = $this->resolveStartKeywordNumber($this->argument('keyword'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $limit = $this->resolveLimit(
            $this->argument('count'),
            $this->option('limit')
        );

        if ($limit === null) {
            $this->error('limit must be a positive integer.');

            return self::FAILURE;
        }

        $groups = $this->resolveKeywordGroups($startKeywordNumber, $limit);
        if ($groups->isEmpty()) {
            $this->warn('No FC2 btdig_results rows matched the requested range.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Processing %d keyword group(s) from FC2-PPV-%d downward.',
            $groups->count(),
            $startKeywordNumber
        ));

        $storedImages = 0;
        $siteFailures = 0;
        $skippedGroups = 0;
        $consecutiveSiteFailures = 0;
        $stoppedEarly = false;

        foreach ($groups as $index => $group) {
            $label = sprintf(
                '[%d/%d] %s',
                $index + 1,
                $groups->count(),
                $group->search_keyword
            );

            $this->line($label);

            try {
                $groupStoredImages = $this->importGroup($group);
                $storedImages += $groupStoredImages;
                $consecutiveSiteFailures = 0;

                $this->line(sprintf('  stored %d image(s)', $groupStoredImages));
            } catch (SkippableImportException $exception) {
                $skippedGroups++;
                $consecutiveSiteFailures = 0;
                $this->warn('  skipped: ' . $exception->getMessage());
            } catch (SiteUnavailableException $exception) {
                $siteFailures++;
                $consecutiveSiteFailures++;
                $this->error('  site failure: ' . $exception->getMessage());

                if ($siteFailures >= self::MAX_TOTAL_SITE_FAILURES) {
                    $stoppedEarly = true;
                    $this->error(sprintf(
                        'Stopping import after %d site failures in this run.',
                        self::MAX_TOTAL_SITE_FAILURES
                    ));
                    break;
                }

                if ($consecutiveSiteFailures >= self::MAX_CONSECUTIVE_SITE_FAILURES) {
                    $stoppedEarly = true;
                    $this->error(sprintf(
                        'Stopping import after %d consecutive site failures.',
                        self::MAX_CONSECUTIVE_SITE_FAILURES
                    ));
                    break;
                }
            } catch (Throwable $exception) {
                $this->error('  unexpected failure: ' . $exception->getMessage());
                $stoppedEarly = true;
                break;
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. groups=%d, site_failures=%d, skipped_groups=%d, stored_images=%d',
            $groups->count(),
            $siteFailures,
            $skippedGroups,
            $storedImages
        ));

        return ($siteFailures === 0 && !$stoppedEarly) ? self::SUCCESS : self::FAILURE;
    }

    private function importGroup(object $group): int
    {
        $keywordNumber = (int) $group->keyword_number;
        $searchUrl = 'https://3xplanet.net/?s=' . $keywordNumber;

        try {
            $searchHtml = $this->fetchHtml($searchUrl);
        } catch (RequestFailureException $exception) {
            throw SiteUnavailableException::fromRequestFailure($exception, 'Search page failed');
        }

        $articleFailures = [];
        $articleUrls = $this->extractArticleUrls($searchHtml, $keywordNumber);

        foreach ($articleUrls as $articleUrl) {
            $attempt = $this->attemptArticleImport($group, $keywordNumber, $searchUrl, $articleUrl);

            if ($attempt['status'] !== 'success') {
                $articleFailures[] = $attempt;
                continue;
            }

            return $this->storeImagesForGroup((int) $group->btdig_result_id, $attempt['images']);
        }

        $maddawgAttempt = $this->attemptMaddawgImport($group, $keywordNumber);
        if ($maddawgAttempt['status'] === 'success') {
            return $this->storeImagesForGroup((int) $group->btdig_result_id, $maddawgAttempt['images']);
        }

        if (isset($maddawgAttempt['message'])) {
            $articleFailures[] = $maddawgAttempt;
        }

        $lastFailure = end($articleFailures);
        if ($lastFailure === false) {
            throw new SkippableImportException('No 3xplanet article found for this keyword, and Maddawg JAV had no matching result.');
        }

        $failureMessage = sprintf(
            'All %d article candidate(s) failed. Last reason: %s',
            count($articleFailures),
            $lastFailure['message'] ?? 'unknown failure'
        );

        if ($articleFailures !== [] && collect($articleFailures)->every(function (array $failure): bool {
            return $failure['status'] === 'site_unavailable';
        })) {
            throw new SiteUnavailableException($failureMessage);
        }

        throw new SkippableImportException($failureMessage);
    }

    private function storeImagesForGroup(int $btdigResultId, array $images): int
    {
        foreach ($images as $image) {
            $this->upsertImageRow(
                $btdigResultId,
                $image['search_keyword'],
                $image['keyword_number'],
                $image['search_url'],
                $image['article_url'],
                $image['article_title'],
                $image['viewimage_url'],
                $image['image_url'],
                $image['image_body'],
                $image['image_mime_type'],
                $image['sort_order']
            );
        }

        return count($images);
    }

    private function parseKeywordArgument(string $keyword): int
    {
        $value = trim($keyword);
        if ($value === '') {
            throw new RuntimeException('keyword cannot be empty.');
        }

        if (preg_match('/^FC2-PPV-(\d+)$/i', $value, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException('keyword must be a number like 1237064 or FC2-PPV-1237064.');
    }

    private function resolveLimit($argumentLimit, $optionLimit): ?int
    {
        $rawOptionLimit = trim((string) $optionLimit);
        if ($rawOptionLimit !== '' && $rawOptionLimit !== '100') {
            return $this->parsePositiveInteger($rawOptionLimit);
        }

        $rawArgumentLimit = trim((string) $argumentLimit);
        if ($rawArgumentLimit !== '') {
            return $this->parsePositiveInteger($rawArgumentLimit);
        }

        if ($rawOptionLimit !== '') {
            return $this->parsePositiveInteger($rawOptionLimit);
        }

        return 100;
    }

    private function parsePositiveInteger(string $value): ?int
    {
        if (preg_match('/^[1-9]\d*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    private function resolveStartKeywordNumber($keywordArgument): int
    {
        $rawKeyword = $keywordArgument === null ? '' : (string) $keywordArgument;
        if (trim($rawKeyword) !== '') {
            return $this->parseKeywordArgument($rawKeyword);
        }

        $defaultKeywordNumber = $this->determineDefaultStartKeywordNumber();
        $this->info(sprintf(
            'No keyword provided. Using default start keyword FC2-PPV-%d.',
            $defaultKeywordNumber
        ));

        return $defaultKeywordNumber;
    }

    private function determineDefaultStartKeywordNumber(): int
    {
        $importedMinimumKeyword = DB::table('btdig_result_images')->min('keyword_number');

        if ($importedMinimumKeyword === null) {
            $fallbackKeyword = $this->resolveHighestAvailableKeywordNumber();
            if ($fallbackKeyword === null) {
                throw new RuntimeException('No FC2 btdig_results data found to determine a default keyword.');
            }

            return $fallbackKeyword;
        }

        $nextKeyword = $this->resolveNextAvailableKeywordBelow((int) $importedMinimumKeyword);
        if ($nextKeyword === null) {
            throw new RuntimeException(sprintf(
                'No lower FC2 keyword exists below imported minimum FC2-PPV-%d.',
                (int) $importedMinimumKeyword
            ));
        }

        return $nextKeyword;
    }

    private function resolveKeywordGroups(int $startKeywordNumber, int $limit): Collection
    {
        $keywordNumberExpr = $this->keywordNumberExpression('btdig_results.search_keyword');

        return DB::table('btdig_results')
            ->selectRaw("MIN(id) AS btdig_result_id, search_keyword, {$keywordNumberExpr} AS keyword_number")
            ->where('type', '=', '2')
            ->where('search_keyword', 'like', 'FC2-PPV-%')
            ->whereRaw("{$keywordNumberExpr} <= ?", [$startKeywordNumber])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw('1'))
                    ->from('btdig_result_images')
                    ->whereRaw("btdig_result_images.keyword_number = {$this->keywordNumberExpression('btdig_results.search_keyword')}");
            })
            ->groupBy('search_keyword')
            ->groupByRaw($keywordNumberExpr)
            ->orderByDesc('keyword_number')
            ->limit($limit)
            ->get();
    }

    private function resolveNextAvailableKeywordBelow(int $keywordNumber): ?int
    {
        $keywordNumberExpr = $this->keywordNumberExpression();

        $row = DB::table('btdig_results')
            ->selectRaw("{$keywordNumberExpr} AS keyword_number")
            ->where('type', '=', '2')
            ->where('search_keyword', 'like', 'FC2-PPV-%')
            ->whereRaw("{$keywordNumberExpr} < ?", [$keywordNumber])
            ->groupBy('search_keyword')
            ->groupByRaw($keywordNumberExpr)
            ->orderByDesc('keyword_number')
            ->first();

        return $row !== null ? (int) $row->keyword_number : null;
    }

    private function resolveHighestAvailableKeywordNumber(): ?int
    {
        $keywordNumberExpr = $this->keywordNumberExpression();

        $row = DB::table('btdig_results')
            ->selectRaw("{$keywordNumberExpr} AS keyword_number")
            ->where('type', '=', '2')
            ->where('search_keyword', 'like', 'FC2-PPV-%')
            ->groupBy('search_keyword')
            ->groupByRaw($keywordNumberExpr)
            ->orderByDesc('keyword_number')
            ->first();

        return $row !== null ? (int) $row->keyword_number : null;
    }

    private function keywordNumberExpression(string $column = 'search_keyword'): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST(REPLACE({$column}, 'FC2-PPV-', '') AS INTEGER)";
        }

        return "CAST(SUBSTRING_INDEX({$column}, '-', -1) AS UNSIGNED)";
    }

    private function fetchHtml(string $url): string
    {
        $response = $this->requestUrl($url, $this->defaultHeaders());
        $body = (string) $response->body();

        if ($body === '') {
            throw RequestFailureException::forEmptyBody($url);
        }

        return $body;
    }

    private function fetchHtmlViaCurrentChrome(string $url): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'maddawg_html_');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate temp file for Chrome fetch.');
        }

        $clipboardBackupPath = tempnam(sys_get_temp_dir(), 'chrome_clipboard_');
        if ($clipboardBackupPath === false) {
            @unlink($tempPath);
            throw new RuntimeException('Unable to allocate temp file for clipboard backup.');
        }

        $script = <<<POWERSHELL
Add-Type -AssemblyName System.Windows.Forms
\$outputPath = '{$this->escapePowerShellSingleQuotedString($tempPath)}'
\$clipboardBackupPath = '{$this->escapePowerShellSingleQuotedString($clipboardBackupPath)}'
\$targetUrl = 'view-source:{$this->escapePowerShellSingleQuotedString($url)}'
\$wshell = New-Object -ComObject WScript.Shell
if (-not \$wshell.AppActivate('Google Chrome')) {
    throw 'Google Chrome window not found.'
}
try {
    \$clipboardText = ''
    try {
        \$clipboardText = Get-Clipboard -Raw
    } catch {
        \$clipboardText = ''
    }
    Set-Content -Path \$clipboardBackupPath -Value \$clipboardText -Encoding UTF8

    [System.Windows.Forms.SendKeys]::SendWait('^t')
    Start-Sleep -Milliseconds 400
    Set-Clipboard -Value \$targetUrl
    [System.Windows.Forms.SendKeys]::SendWait('^l')
    Start-Sleep -Milliseconds 250
    [System.Windows.Forms.SendKeys]::SendWait('^v')
    Start-Sleep -Milliseconds 150
    [System.Windows.Forms.SendKeys]::SendWait('{ENTER}')
    Start-Sleep -Seconds %{CHROME_WAIT_SECONDS}%
    [System.Windows.Forms.SendKeys]::SendWait('^a')
    Start-Sleep -Milliseconds 300
    [System.Windows.Forms.SendKeys]::SendWait('^c')
    Start-Sleep -Milliseconds 800

    \$html = Get-Clipboard -Raw
    if ([string]::IsNullOrWhiteSpace(\$html)) {
        throw 'Chrome returned an empty clipboard after copying page source.'
    }

    Set-Content -Path \$outputPath -Value \$html -Encoding UTF8
} finally {
    [System.Windows.Forms.SendKeys]::SendWait('^w')
    Start-Sleep -Milliseconds 200
    if (Test-Path \$clipboardBackupPath) {
        try {
            Set-Clipboard -Value (Get-Content -Path \$clipboardBackupPath -Raw)
        } catch {
        }
    }
}
POWERSHELL;

        $script = str_replace('%{CHROME_WAIT_SECONDS}%', (string) self::CHROME_FETCH_WAIT_SECONDS, $script);

        $process = new Process(['powershell', '-NoProfile', '-ExecutionPolicy', 'Bypass', '-Command', $script]);
        $process->setTimeout(45);
        $process->run();

        try {
            if (!$process->isSuccessful()) {
                $message = trim($process->getErrorOutput() . "\n" . $process->getOutput());
                throw new RuntimeException($message !== '' ? $message : 'Chrome automation failed.');
            }

            $html = file_get_contents($tempPath);
            if ($html === false || trim($html) === '') {
                throw new RuntimeException('Chrome automation did not return any HTML.');
            }

            return $html;
        } finally {
            @unlink($tempPath);
            @unlink($clipboardBackupPath);
        }
    }

    private function attemptArticleImport(object $group, int $keywordNumber, string $searchUrl, string $articleUrl): array
    {
        try {
            $articleHtml = $this->fetchHtml($articleUrl);
        } catch (RequestFailureException $exception) {
            return [
                'status' => $this->isSiteRequestFailure($exception) ? 'site_unavailable' : 'skippable',
                'message' => $exception->getStatusCode() === 404
                    ? sprintf('Article page not found: %s', $articleUrl)
                    : sprintf('Article page failed for %s (%s).', $articleUrl, $exception->getFriendlyReason()),
            ];
        }

        $articleTitle = $this->extractArticleTitle($articleHtml);
        $viewImageUrls = $this->extractViewImageUrls($articleHtml);

        if (count($viewImageUrls) === 0) {
            return [
                'status' => 'skippable',
                'message' => sprintf('No preview images found in article %s.', $articleUrl),
            ];
        }

        $images = [];

        foreach ($viewImageUrls as $sortOrder => $viewImageUrl) {
            try {
                $viewImageHtml = $this->fetchHtml($viewImageUrl);
            } catch (RequestFailureException $exception) {
                return [
                    'status' => $this->isSiteRequestFailure($exception) ? 'site_unavailable' : 'skippable',
                    'message' => sprintf(
                        'Preview page failed for %s (%s).',
                        $viewImageUrl,
                        $exception->getFriendlyReason()
                    ),
                ];
            }

            $imageUrl = $this->extractImageUrlFromViewImagePage($viewImageHtml);
            if ($imageUrl === null) {
                return [
                    'status' => 'skippable',
                    'message' => sprintf('Preview page did not contain a usable image URL: %s', $viewImageUrl),
                ];
            }

            try {
                [$imageBody, $imageMimeType] = $this->downloadImage($imageUrl, $viewImageUrl);
            } catch (RequestFailureException $exception) {
                return [
                    'status' => $this->isSiteRequestFailure($exception) ? 'site_unavailable' : 'skippable',
                    'message' => sprintf(
                        'Image download failed for %s (%s).',
                        $imageUrl,
                        $exception->getFriendlyReason()
                    ),
                ];
            } catch (SkippableImportException $exception) {
                return [
                    'status' => 'skippable',
                    'message' => sprintf(
                        'Image download failed for %s (%s).',
                        $imageUrl,
                        $exception->getMessage()
                    ),
                ];
            }

            $images[] = [
                'search_keyword' => (string) $group->search_keyword,
                'keyword_number' => $keywordNumber,
                'search_url' => $searchUrl,
                'article_url' => $articleUrl,
                'article_title' => $articleTitle,
                'viewimage_url' => $viewImageUrl,
                'image_url' => $imageUrl,
                'image_body' => $imageBody,
                'image_mime_type' => $imageMimeType,
                'sort_order' => $sortOrder + 1,
            ];
        }

        return [
            'status' => 'success',
            'images' => $images,
        ];
    }

    private function attemptMaddawgImport(object $group, int $keywordNumber): array
    {
        $searchUrl = 'https://maddawgjav.net/?s=' . $keywordNumber;

        try {
            $searchHtml = $this->fetchHtmlViaCurrentChrome($searchUrl);
        } catch (RuntimeException $exception) {
            return [
                'status' => 'skippable',
                'message' => 'Maddawg JAV fallback failed: ' . $exception->getMessage(),
            ];
        }

        $articles = $this->extractMaddawgArticlesFromSearchHtml($searchHtml);
        if ($articles === []) {
            return [
                'status' => 'skippable',
                'message' => 'Maddawg JAV search returned no matching article.',
            ];
        }

        $failures = [];

        foreach ($articles as $article) {
            $images = $article['images'];

            if ($images === []) {
                try {
                    $articleHtml = $this->fetchHtmlViaCurrentChrome($article['article_url']);
                } catch (RuntimeException $exception) {
                    $failures[] = [
                        'status' => 'skippable',
                        'message' => sprintf(
                            'Maddawg JAV article fetch failed for %s (%s).',
                            $article['article_url'],
                            $exception->getMessage()
                        ),
                    ];
                    continue;
                }

                $article['images'] = $this->extractMaddawgImages($articleHtml);
                $article['article_title'] = $article['article_title'] ?: $this->extractArticleTitle($articleHtml);
                $images = $article['images'];
            }

            if ($images === []) {
                $failures[] = [
                    'status' => 'skippable',
                    'message' => sprintf('No Maddawg JAV preview images found in %s.', $article['article_url']),
                ];
                continue;
            }

            $storedImages = [];

            foreach ($images as $sortOrder => $imageMeta) {
                try {
                    [$imageBody, $imageMimeType] = $this->downloadImage($imageMeta['image_url'], $article['article_url']);
                } catch (RequestFailureException $exception) {
                    $failures[] = [
                        'status' => $this->isSiteRequestFailure($exception) ? 'site_unavailable' : 'skippable',
                        'message' => sprintf(
                            'Maddawg JAV image download failed for %s (%s).',
                            $imageMeta['image_url'],
                            $exception->getFriendlyReason()
                        ),
                    ];
                    continue 2;
                } catch (SkippableImportException $exception) {
                    $failures[] = [
                        'status' => 'skippable',
                        'message' => sprintf(
                            'Maddawg JAV image download failed for %s (%s).',
                            $imageMeta['image_url'],
                            $exception->getMessage()
                        ),
                    ];
                    continue 2;
                }

                $storedImages[] = [
                    'search_keyword' => (string) $group->search_keyword,
                    'keyword_number' => $keywordNumber,
                    'search_url' => $searchUrl,
                    'article_url' => $article['article_url'],
                    'article_title' => $article['article_title'],
                    'viewimage_url' => $imageMeta['viewimage_url'],
                    'image_url' => $imageMeta['image_url'],
                    'image_body' => $imageBody,
                    'image_mime_type' => $imageMimeType,
                    'sort_order' => $sortOrder + 1,
                ];
            }

            if ($storedImages !== []) {
                return [
                    'status' => 'success',
                    'images' => $storedImages,
                ];
            }
        }

        $lastFailure = end($failures);

        return [
            'status' => $failures !== [] && collect($failures)->every(fn (array $failure): bool => $failure['status'] === 'site_unavailable')
                ? 'site_unavailable'
                : 'skippable',
            'message' => $lastFailure['message'] ?? 'Maddawg JAV fallback did not yield any usable image.',
        ];
    }

    private function extractArticleUrl(string $html, int $keywordNumber): ?string
    {
        return $this->extractArticleUrls($html, $keywordNumber)[0] ?? null;
    }

    private function extractArticleUrls(string $html, int $keywordNumber): array
    {
        $crawler = new Crawler($html, 'https://3xplanet.net');
        $baseSlug = 'fc2-ppv-' . $keywordNumber;
        $exactPattern = '#^https?://3xplanet\.net/' . preg_quote($baseSlug, '#') . '(?:-(\d+))?/?$#i';
        $fallbackPattern = '#^https?://3xplanet\.net/[^"\']*' . preg_quote((string) $keywordNumber, '#') . '[^"\']*/?$#i';

        $exactMatches = collect();
        $fallbackMatches = collect();

        foreach ($crawler->filter('a[href]') as $node) {
            $href = $this->normalizeUrl($node->getAttribute('href'));
            if ($href === null) {
                continue;
            }

            if (preg_match($exactPattern, $href, $matches) === 1) {
                $exactMatches->push([
                    'url' => $href,
                    'revision' => isset($matches[1]) ? (int) $matches[1] : 1,
                ]);
                continue;
            }

            if (preg_match($fallbackPattern, $href) === 1) {
                $fallbackMatches->push($href);
            }
        }

        if ($exactMatches->isNotEmpty()) {
            return $exactMatches
                ->sortByDesc('revision')
                ->pluck('url')
                ->unique()
                ->values()
                ->all();
        }

        if ($fallbackMatches->isNotEmpty()) {
            return $fallbackMatches
                ->unique()
                ->values()
                ->all();
        }

        return [];
    }

    private function extractMaddawgArticlesFromSearchHtml(string $html): array
    {
        $crawler = new Crawler($html, 'https://maddawgjav.net');
        $articles = [];

        foreach ($crawler->filter('.post') as $node) {
            $postCrawler = new Crawler($node, 'https://maddawgjav.net');
            if ($postCrawler->filter('h2.title a[href]')->count() === 0) {
                continue;
            }

            $articleUrl = $this->normalizeExternalUrl($postCrawler->filter('h2.title a[href]')->first()->attr('href'), 'https://maddawgjav.net');
            if ($articleUrl === null) {
                continue;
            }

            $articleTitle = $this->cleanText($postCrawler->filter('h2.title a[href]')->first()->text(''));
            $articleHtml = $node->ownerDocument !== null ? $node->ownerDocument->saveHTML($node) : '';

            $articles[$articleUrl] = [
                'article_url' => $articleUrl,
                'article_title' => $articleTitle !== '' ? $articleTitle : null,
                'images' => $this->extractMaddawgImages($articleHtml),
            ];
        }

        return array_values($articles);
    }

    private function extractMaddawgImages(string $html): array
    {
        $crawler = new Crawler($html, 'https://maddawgjav.net');
        $images = [];

        foreach ($crawler->filter('img[src]') as $node) {
            $src = $this->normalizeExternalUrl($node->getAttribute('src'), 'https://maddawgjav.net');
            if ($src === null) {
                continue;
            }

            if (preg_match('#^https?://img\d+\.pixhost\.to/images/.+\.(?:jpg|jpeg|png|gif|webp)$#i', $src) === 1) {
                $images[$src] = [
                    'viewimage_url' => $src,
                    'image_url' => $src,
                ];
                continue;
            }

            if (preg_match('#^https?://t(\d+)\.pixhost\.to/thumbs/(.+)$#i', $src, $matches) !== 1) {
                continue;
            }

            $imageUrl = 'https://img' . $matches[1] . '.pixhost.to/images/' . $matches[2];
            $parent = $node->parentNode;
            $viewImageUrl = $src;

            if ($parent !== null && method_exists($parent, 'hasAttribute') && $parent->hasAttribute('href')) {
                $viewImageUrl = $this->normalizeExternalUrl($parent->getAttribute('href'), 'https://maddawgjav.net') ?? $src;
            }

            $images[$imageUrl] = [
                'viewimage_url' => $viewImageUrl,
                'image_url' => $imageUrl,
            ];
        }

        return array_values($images);
    }

    private function extractArticleTitle(string $html): ?string
    {
        $crawler = new Crawler($html, 'https://3xplanet.net');

        foreach (['h1', 'title'] as $selector) {
            if ($crawler->filter($selector)->count() === 0) {
                continue;
            }

            $title = $this->cleanText($crawler->filter($selector)->first()->text(''));
            if ($title !== '') {
                return $title;
            }
        }

        return null;
    }

    private function extractViewImageUrls(string $html): array
    {
        $crawler = new Crawler($html, 'https://3xplanet.net');
        $urls = [];

        foreach ($crawler->filter('a[href]') as $node) {
            $href = $this->normalizeUrl($node->getAttribute('href'));
            if ($href === null) {
                continue;
            }

            if (preg_match('#^https?://3xplanet\.net/viewimage/\d+\.html/?$#i', $href) !== 1) {
                continue;
            }

            $urls[$href] = $href;
        }

        return array_values($urls);
    }

    private function extractImageUrlFromViewImagePage(string $html): ?string
    {
        $crawler = new Crawler($html, 'https://3xplanet.net');

        if ($crawler->filter('#show_image[src]')->count() > 0) {
            $src = $crawler->filter('#show_image[src]')->first()->attr('src');
            $url = $this->normalizeUrl($src);
            if ($url !== null) {
                return $url;
            }
        }

        foreach ($crawler->filter('img[src]') as $node) {
            $src = $this->normalizeUrl($node->getAttribute('src'));
            if ($src === null) {
                continue;
            }

            if (Str::contains($src, ['/viewimage/skin/', 'favicon'])) {
                continue;
            }

            return $src;
        }

        return null;
    }

    private function downloadImage(string $imageUrl, string $referer): array
    {
        $response = $this->requestUrl($imageUrl, $this->defaultHeaders($referer));
        $contentType = strtolower((string) $response->header('Content-Type', ''));
        $mimeType = trim(Str::before($contentType, ';'));

        if ($mimeType === '') {
            $mimeType = $this->guessMimeTypeFromUrl($imageUrl);
        }

        if (!Str::startsWith($mimeType, 'image/')) {
            throw new SkippableImportException('Downloaded resource is not an image.');
        }

        $body = (string) $response->body();
        if ($body === '') {
            throw new SkippableImportException('Downloaded image body is empty.');
        }

        return [$body, $mimeType];
    }

    private function requestUrl(string $url, array $headers): Response
    {
        $lastFailure = null;

        foreach (self::REQUEST_RETRY_DELAYS_MS as $attempt => $delayMs) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $this->throttleRequests();

            try {
                $response = $this->httpClient()
                    ->withHeaders($headers)
                    ->get($url);
            } catch (Throwable $exception) {
                $lastFailure = RequestFailureException::forThrowable($url, $exception);

                if ($this->shouldRetryRequestFailure($lastFailure) && $attempt < count(self::REQUEST_RETRY_DELAYS_MS) - 1) {
                    continue;
                }

                throw $lastFailure;
            }

            if ($this->isBotProtectionResponse($response)) {
                $lastFailure = RequestFailureException::forBotProtection($url, $response->status());

                if ($attempt < count(self::REQUEST_RETRY_DELAYS_MS) - 1) {
                    continue;
                }

                throw $lastFailure;
            }

            if (!$response->successful()) {
                $lastFailure = RequestFailureException::forStatus($url, $response->status());

                if ($this->shouldRetryRequestFailure($lastFailure) && $attempt < count(self::REQUEST_RETRY_DELAYS_MS) - 1) {
                    continue;
                }

                throw $lastFailure;
            }

            return $response;
        }

        if ($lastFailure !== null) {
            throw $lastFailure;
        }

        throw RequestFailureException::forThrowable($url, new RuntimeException('Request failed without a usable response.'));
    }

    private function upsertImageRow(
        int $btdigResultId,
        string $searchKeyword,
        int $keywordNumber,
        string $searchUrl,
        string $articleUrl,
        ?string $articleTitle,
        string $viewImageUrl,
        string $imageUrl,
        string $imageBody,
        string $imageMimeType,
        int $sortOrder
    ): void {
        $now = now();
        $imageExtension = strtolower((string) pathinfo((string) parse_url($imageUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
        $payload = [
            'search_keyword' => $searchKeyword,
            'keyword_number' => $keywordNumber,
            'search_url' => $searchUrl,
            'article_url' => $articleUrl,
            'article_title' => $articleTitle,
            'viewimage_url' => $viewImageUrl,
            'image_url' => $imageUrl,
            'image_mime_type' => $imageMimeType,
            'image_extension' => $imageExtension !== '' ? $imageExtension : null,
            'image_size_bytes' => strlen($imageBody),
            'image_sha1' => sha1($imageBody),
            'image_base64' => base64_encode($imageBody),
            'sort_order' => $sortOrder,
            'fetched_at' => $now,
            'updated_at' => $now,
        ];

        $existingId = DB::table('btdig_result_images')
            ->where('btdig_result_id', $btdigResultId)
            ->where('viewimage_url', $viewImageUrl)
            ->value('id');

        if ($existingId !== null) {
            DB::table('btdig_result_images')
                ->where('id', $existingId)
                ->update($payload);

            return;
        }

        DB::table('btdig_result_images')->insert($payload + [
            'btdig_result_id' => $btdigResultId,
            'created_at' => $now,
        ]);
    }

    private function defaultHeaders(?string $referer = null): array
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
            'Accept-Language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Upgrade-Insecure-Requests' => '1',
        ];

        if ($referer !== null && $referer !== '') {
            $headers['Referer'] = $referer;
        }

        return $headers;
    }

    private function httpClient(): PendingRequest
    {
        if ($this->cookieJar === null) {
            $this->cookieJar = new CookieJar();
        }

        return Http::timeout(30)
            ->withOptions([
                'verify' => false,
                'cookies' => $this->cookieJar,
            ]);
    }

    private function throttleRequests(): void
    {
        $minimumInterval = random_int(self::MIN_REQUEST_INTERVAL_US, self::MAX_REQUEST_INTERVAL_US);
        $now = microtime(true);

        if ($this->lastRequestAt > 0.0) {
            $elapsedUs = (int) (($now - $this->lastRequestAt) * 1000000);

            if ($elapsedUs < $minimumInterval) {
                usleep($minimumInterval - $elapsedUs);
                $now = microtime(true);
            }
        }

        $this->lastRequestAt = $now;
    }

    private function shouldRetryRequestFailure(RequestFailureException $exception): bool
    {
        $statusCode = $exception->getStatusCode();

        if ($statusCode === null) {
            return true;
        }

        return in_array($statusCode, [403, 429, 500, 502, 503, 504], true);
    }

    private function isSiteRequestFailure(RequestFailureException $exception): bool
    {
        $statusCode = $exception->getStatusCode();

        if ($statusCode === null) {
            return true;
        }

        return in_array($statusCode, [403, 429, 500, 502, 503, 504], true);
    }

    private function isBotProtectionResponse(Response $response): bool
    {
        $body = Str::lower((string) $response->body());

        if ($body === '') {
            return false;
        }

        if (!Str::contains($body, ['just a moment', 'cf-browser-verification', 'cf-chl', 'cloudflare'])) {
            return false;
        }

        return $response->status() === 403 || Str::contains($body, ['challenge-platform', 'cf-challenge']);
    }

    private function normalizeUrl(?string $url): ?string
    {
        $value = trim((string) $url);
        $value = trim($value, "\"' \t\n\r\0\x0B");
        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, '//')) {
            return 'https:' . $value;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        if (Str::startsWith($value, '/')) {
            return 'https://3xplanet.net' . $value;
        }

        return 'https://3xplanet.net/' . ltrim($value, '/');
    }

    private function normalizeExternalUrl(?string $url, string $baseUrl): ?string
    {
        $value = trim((string) $url);
        $value = trim($value, "\"' \t\n\r\0\x0B");
        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, '//')) {
            return 'https:' . $value;
        }

        if (preg_match('#^https?://#i', $value) === 1) {
            return $value;
        }

        if (Str::startsWith($value, '/')) {
            return rtrim($baseUrl, '/') . $value;
        }

        return rtrim($baseUrl, '/') . '/' . ltrim($value, '/');
    }

    private function guessMimeTypeFromUrl(string $url): string
    {
        $extension = strtolower((string) pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ][$extension] ?? 'application/octet-stream';
    }

    private function cleanText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function escapePowerShellSingleQuotedString(string $value): string
    {
        return str_replace("'", "''", $value);
    }
}

class RequestFailureException extends RuntimeException
{
    private ?int $statusCode;

    public function __construct(string $message, ?int $statusCode = null)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }

    public static function forStatus(string $url, int $statusCode): self
    {
        return new self(sprintf('Request failed for %s (HTTP %d).', $url, $statusCode), $statusCode);
    }

    public static function forThrowable(string $url, Throwable $exception): self
    {
        return new self(sprintf('Request failed for %s (%s).', $url, $exception->getMessage()));
    }

    public static function forBotProtection(string $url, int $statusCode): self
    {
        return new self(sprintf(
            'Request blocked by Cloudflare challenge for %s (HTTP %d).',
            $url,
            $statusCode
        ), $statusCode);
    }

    public static function forEmptyBody(string $url): self
    {
        return new self('Empty HTML returned for ' . $url);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getFriendlyReason(): string
    {
        if (Str::contains($this->getMessage(), 'Cloudflare challenge')) {
            return 'Cloudflare challenge' . ($this->statusCode !== null ? ' (HTTP ' . $this->statusCode . ')' : '');
        }

        if ($this->statusCode !== null) {
            return 'HTTP ' . $this->statusCode;
        }

        return $this->getMessage();
    }
}

class SiteUnavailableException extends RuntimeException
{
    public static function fromRequestFailure(RequestFailureException $exception, string $context): self
    {
        return new self($context . ': ' . $exception->getFriendlyReason());
    }
}

class SkippableImportException extends RuntimeException
{
}
