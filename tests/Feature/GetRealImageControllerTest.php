<?php

namespace Tests\Feature;

use App\Http\Controllers\GetRealImageController;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class GetRealImageControllerTest extends TestCase
{
    public function test_it_follows_soft_redirect_before_extracting_the_real_image_url(): void
    {
        $mock = new MockHandler([
            new Response(404, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Location' => 'https://s6.4up.pics/en/example-FC2.jpg',
            ], <<<'HTML'
<html>
    <head><meta http-equiv="refresh" content="0;url='https://s6.4up.pics/en/example-FC2.jpg'" /></head>
    <body>Redirecting</body>
</html>
HTML),
            new Response(404, ['Content-Type' => 'text/html; charset=UTF-8'], <<<'HTML'
<html>
    <body>
        <div class="fileviewer-file">
            <img src="https://s6.4up.pics/Application/storage/app/public/uploads/users/aQ2WVGrBGkx7y/example-FC2.jpg" />
        </div>
    </body>
</html>
HTML),
        ]);

        $controller = new GetRealImageController(new Client([
            'handler' => HandlerStack::create($mock),
        ]));

        $this->assertSame(
            'https://s6.4up.pics/Application/storage/app/public/uploads/users/aQ2WVGrBGkx7y/example-FC2.jpg',
            $controller->processImage('https://s6.4up.pics/example-FC2.jpg')
        );
    }
}
