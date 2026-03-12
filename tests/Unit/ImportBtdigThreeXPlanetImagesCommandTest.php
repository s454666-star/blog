<?php

namespace Tests\Unit;

use App\Console\Commands\ImportBtdigThreeXPlanetImagesCommand;
use App\Console\Commands\RequestFailureException;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

class ImportBtdigThreeXPlanetImagesCommandTest extends TestCase
{
    private ImportBtdigThreeXPlanetImagesCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new ImportBtdigThreeXPlanetImagesCommand();
    }

    public function test_it_parses_numeric_and_fc2_keywords(): void
    {
        $this->assertSame(1237064, $this->invoke('parseKeywordArgument', ['1237064']));
        $this->assertSame(1237064, $this->invoke('parseKeywordArgument', ['FC2-PPV-1237064']));
    }

    public function test_it_rejects_invalid_keywords(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keyword must be a number like 1237064 or FC2-PPV-1237064.');

        $this->invoke('parseKeywordArgument', ['abc123']);
    }

    public function test_it_rejects_empty_keywords(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('keyword cannot be empty.');

        $this->invoke('parseKeywordArgument', ['']);
    }

    public function test_it_extracts_article_viewimage_and_image_urls_from_html(): void
    {
        $searchHtml = <<<'HTML'
<html>
    <body>
        <a href="https://3xplanet.net/fc2-ppv-1237064/">Old Article</a>
        <a href="https://3xplanet.net/fc2-ppv-1237064-2/">New Article</a>
    </body>
</html>
HTML;

        $articleHtml = <<<'HTML'
<html>
    <head><title>FC2 PPV 1237064</title></head>
    <body>
        <a href="https://3xplanet.net/viewimage/132394.html">Cover</a>
        <a href="https://3xplanet.net/viewimage/132532.html">Preview</a>
    </body>
</html>
HTML;

        $viewImageHtml = <<<'HTML'
<html>
    <body>
        <div class="view-content">
            <img id="show_image" src="https://1.bp.blogspot.com/example/177426_3xplanet_FC2_PPV_1237064_cover.jpg" />
        </div>
    </body>
</html>
HTML;

        $this->assertSame(
            'https://3xplanet.net/fc2-ppv-1237064-2/',
            $this->invoke('extractArticleUrl', [$searchHtml, 1237064])
        );
        $this->assertSame(
            [
                'https://3xplanet.net/fc2-ppv-1237064-2/',
                'https://3xplanet.net/fc2-ppv-1237064/',
            ],
            $this->invoke('extractArticleUrls', [$searchHtml, 1237064])
        );
        $this->assertSame(
            'FC2 PPV 1237064',
            $this->invoke('extractArticleTitle', [$articleHtml])
        );
        $this->assertSame(
            [
                'https://3xplanet.net/viewimage/132394.html',
                'https://3xplanet.net/viewimage/132532.html',
            ],
            $this->invoke('extractViewImageUrls', [$articleHtml])
        );
        $this->assertSame(
            'https://1.bp.blogspot.com/example/177426_3xplanet_FC2_PPV_1237064_cover.jpg',
            $this->invoke('extractImageUrlFromViewImagePage', [$viewImageHtml])
        );
    }

    public function test_it_uses_default_limit_of_100_when_no_override_is_provided(): void
    {
        $this->assertSame(100, $this->invoke('resolveLimit', [null, '100']));
    }

    public function test_it_prefers_explicit_option_limit_over_default_and_supports_positional_count(): void
    {
        $this->assertSame(10, $this->invoke('resolveLimit', [null, '10']));
        $this->assertSame(25, $this->invoke('resolveLimit', ['25', '100']));
    }

    public function test_it_rejects_non_positive_limits(): void
    {
        $this->assertNull($this->invoke('resolveLimit', ['0', '100']));
        $this->assertNull($this->invoke('resolveLimit', [null, '-1']));
    }

    public function test_it_retries_cloudflare_and_server_failures_but_not_404(): void
    {
        $this->assertTrue($this->invoke('shouldRetryRequestFailure', [
            RequestFailureException::forStatus('https://example.com', 403),
        ]));
        $this->assertTrue($this->invoke('shouldRetryRequestFailure', [
            RequestFailureException::forStatus('https://example.com', 503),
        ]));
        $this->assertFalse($this->invoke('shouldRetryRequestFailure', [
            RequestFailureException::forStatus('https://example.com', 404),
        ]));
    }

    public function test_it_detects_cloudflare_challenge_pages(): void
    {
        $response = new Response(new PsrResponse(
            403,
            ['Content-Type' => 'text/html'],
            '<html><head><title>Just a moment...</title></head><body>cloudflare challenge-platform</body></html>'
        ));

        $this->assertTrue($this->invoke('isBotProtectionResponse', [$response]));
    }

    public function test_it_extracts_maddawg_articles_and_pixhost_images(): void
    {
        $searchHtml = <<<'HTML'
<html>
    <body>
        <div class="post">
            <h2 class="title">
                <a href="https://maddawgjav.net/fc2-ppv-1882737-sample/">FC2 PPV 1882737</a>
            </h2>
            <div class="entry">
                <p><img src="https://img58.pixhost.to/images/34/221186853_1625825888-56.jpg" /></p>
                <p>
                    <a href="https://pixhost.to/show/34/221234319_fc2ppv-1882737-1_s.jpg">
                        <img src="https://t58.pixhost.to/thumbs/34/221234319_fc2ppv-1882737-1_s.jpg" />
                    </a>
                    <a href="https://pixhost.to/show/34/221234322_fc2ppv-1882737-2_s.jpg">
                        <img src="https://t58.pixhost.to/thumbs/34/221234322_fc2ppv-1882737-2_s.jpg" />
                    </a>
                </p>
            </div>
        </div>
    </body>
</html>
HTML;

        $this->assertSame(
            [
                [
                    'article_url' => 'https://maddawgjav.net/fc2-ppv-1882737-sample/',
                    'article_title' => 'FC2 PPV 1882737',
                    'images' => [
                        [
                            'viewimage_url' => 'https://img58.pixhost.to/images/34/221186853_1625825888-56.jpg',
                            'image_url' => 'https://img58.pixhost.to/images/34/221186853_1625825888-56.jpg',
                        ],
                        [
                            'viewimage_url' => 'https://pixhost.to/show/34/221234319_fc2ppv-1882737-1_s.jpg',
                            'image_url' => 'https://img58.pixhost.to/images/34/221234319_fc2ppv-1882737-1_s.jpg',
                        ],
                        [
                            'viewimage_url' => 'https://pixhost.to/show/34/221234322_fc2ppv-1882737-2_s.jpg',
                            'image_url' => 'https://img58.pixhost.to/images/34/221234322_fc2ppv-1882737-2_s.jpg',
                        ],
                    ],
                ],
            ],
            $this->invoke('extractMaddawgArticlesFromSearchHtml', [$searchHtml])
        );
    }

    private function invoke(string $methodName, array $arguments = [])
    {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->command, $arguments);
    }
}
