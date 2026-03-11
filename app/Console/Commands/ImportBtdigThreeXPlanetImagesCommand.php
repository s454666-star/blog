<?php

namespace App\Console\Commands;

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
use Throwable;

class ImportBtdigThreeXPlanetImagesCommand extends Command
{
    private const MAX_CONSECUTIVE_SITE_FAILURES = 3;

    protected $signature = 'btdig:import-3xplanet-images
                            {keyword? : Start keyword number or FC2-PPV-1237064}
                            {limit=1000 : Number of distinct search_keyword groups to process}';

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

        $limit = (int) $this->argument('limit');
        if ($limit < 1) {
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

        $articleUrl = $this->extractArticleUrl($searchHtml, $keywordNumber);
        if ($articleUrl === null) {
            throw new SkippableImportException('No 3xplanet article found for this keyword.');
        }

        try {
            $articleHtml = $this->fetchHtml($articleUrl);
        } catch (RequestFailureException $exception) {
            if ($exception->getStatusCode() === 404) {
                throw new SkippableImportException('3xplanet article page not found.');
            }

            throw SiteUnavailableException::fromRequestFailure($exception, 'Article page failed');
        }

        $articleTitle = $this->extractArticleTitle($articleHtml);
        $viewImageUrls = $this->extractViewImageUrls($articleHtml);

        if (count($viewImageUrls) === 0) {
            throw new SkippableImportException('No preview images found in the 3xplanet article.');
        }

        $images = [];

        foreach ($viewImageUrls as $sortOrder => $viewImageUrl) {
            try {
                $viewImageHtml = $this->fetchHtml($viewImageUrl);
            } catch (RequestFailureException $exception) {
                throw new SkippableImportException(sprintf(
                    'Preview page failed for %s (%s).',
                    $viewImageUrl,
                    $exception->getFriendlyReason()
                ));
            }

            $imageUrl = $this->extractImageUrlFromViewImagePage($viewImageHtml);
            if ($imageUrl === null) {
                throw new SkippableImportException('Preview page did not contain a usable image URL.');
            }

            try {
                [$imageBody, $imageMimeType] = $this->downloadImage($imageUrl, $viewImageUrl);
            } catch (RequestFailureException $exception) {
                throw new SkippableImportException(sprintf(
                    'Image download failed for %s (%s).',
                    $imageUrl,
                    $exception->getFriendlyReason()
                ));
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

        foreach ($images as $image) {
            $this->upsertImageRow(
                (int) $group->btdig_result_id,
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
        $keywordNumberExpr = $this->keywordNumberExpression();

        return DB::table('btdig_results')
            ->selectRaw("MIN(id) AS btdig_result_id, search_keyword, {$keywordNumberExpr} AS keyword_number")
            ->where('type', '=', '2')
            ->where('search_keyword', 'like', 'FC2-PPV-%')
            ->whereRaw("{$keywordNumberExpr} <= ?", [$startKeywordNumber])
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

    private function keywordNumberExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST(REPLACE(search_keyword, 'FC2-PPV-', '') AS INTEGER)";
        }

        return "CAST(SUBSTRING_INDEX(search_keyword, '-', -1) AS UNSIGNED)";
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

    private function extractArticleUrl(string $html, int $keywordNumber): ?string
    {
        $crawler = new Crawler($html, 'https://3xplanet.net');
        $exactPattern = '#^https?://3xplanet\.net/fc2-ppv-' . preg_quote((string) $keywordNumber, '#') . '/?$#i';
        $fallbackPattern = '#^https?://3xplanet\.net/[^"\']*' . preg_quote((string) $keywordNumber, '#') . '[^"\']*/?$#i';

        $exactMatches = [];
        $fallbackMatches = [];

        foreach ($crawler->filter('a[href]') as $node) {
            $href = $this->normalizeUrl($node->getAttribute('href'));
            if ($href === null) {
                continue;
            }

            if (preg_match($exactPattern, $href) === 1) {
                $exactMatches[] = $href;
                continue;
            }

            if (preg_match($fallbackPattern, $href) === 1) {
                $fallbackMatches[] = $href;
            }
        }

        if ($exactMatches !== []) {
            return $exactMatches[0];
        }

        if ($fallbackMatches !== []) {
            return $fallbackMatches[0];
        }

        return null;
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
        try {
            $response = $this->httpClient()
                ->withHeaders($headers)
                ->get($url);
        } catch (Throwable $exception) {
            throw RequestFailureException::forThrowable($url, $exception);
        }

        if (!$response->successful()) {
            throw RequestFailureException::forStatus($url, $response->status());
        }

        return $response;
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
            'Accept-Language' => 'zh-TW,zh;q=0.9,en-US;q=0.8,en;q=0.7',
        ];

        if ($referer !== null && $referer !== '') {
            $headers['Referer'] = $referer;
        }

        return $headers;
    }

    private function httpClient(): PendingRequest
    {
        return Http::retry(2, 500)
            ->timeout(30)
            ->withOptions([
                'verify' => false,
            ]);
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
