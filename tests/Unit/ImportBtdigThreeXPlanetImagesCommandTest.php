<?php

namespace Tests\Unit;

use App\Console\Commands\ImportBtdigThreeXPlanetImagesCommand;
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
        <a href="https://3xplanet.net/fc2-ppv-1237064/">Article</a>
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
            'https://3xplanet.net/fc2-ppv-1237064/',
            $this->invoke('extractArticleUrl', [$searchHtml, 1237064])
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

    private function invoke(string $methodName, array $arguments = [])
    {
        $reflection = new ReflectionClass($this->command);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($this->command, $arguments);
    }
}
