<?php

namespace Tests\Feature;

use App\Http\Controllers\GetBtDataDetailController;
use App\Http\Controllers\GetRealImageController;
use App\Models\Article;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Tests\TestCase;

class GetBtDataDetailControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this feature test.');
        }

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        Config::set('cache.default', 'array');
        Config::set('bt.detail_lock_seconds', 60);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::connection('sqlite')->create('articles', function (Blueprint $table): void {
            $table->increments('article_id');
            $table->string('title');
            $table->text('password')->nullable();
            $table->string('https_link')->default('');
            $table->string('detail_url')->default('');
            $table->integer('source_type')->nullable();
            $table->timestamp('article_time')->nullable();
            $table->boolean('is_disabled')->default(false);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('images', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('article_id');
            $table->string('image_name')->nullable();
            $table->string('image_path');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_skips_fetching_when_the_detail_url_already_exists(): void
    {
        DB::table('articles')->insert([
            'title' => 'Existing article',
            'password' => 'magnet:?xt=existing',
            'https_link' => 'https://example.com/download.torrent',
            'detail_url' => 'https://sukebei.nyaa.si/view/4572636',
            'source_type' => 2,
            'article_time' => now(),
            'is_disabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $imageController = Mockery::mock(GetRealImageController::class);
        $imageController->shouldNotReceive('processImage');

        $controller = new GetBtDataDetailController($imageController);
        $controller->fetchDetail('https://sukebei.nyaa.si/view/4572636');

        $this->assertSame(1, Article::query()->count());
    }

    public function test_it_skips_fetching_when_the_detail_lock_is_already_held(): void
    {
        $imageController = Mockery::mock(GetRealImageController::class);
        $imageController->shouldNotReceive('processImage');

        $lock = Cache::lock('bt-crawler:detail:' . sha1('https://sukebei.nyaa.si/view/4572636'), 60);
        $this->assertTrue($lock->get());

        try {
            $controller = new GetBtDataDetailController($imageController);
            $controller->fetchDetail('https://sukebei.nyaa.si/view/4572636');
        } finally {
            $lock->release();
        }

        $this->assertSame(0, Article::query()->count());
    }

    public function test_it_replaces_an_existing_article_only_after_images_are_resolved(): void
    {
        $articleId = DB::table('articles')->insertGetId([
            'title' => 'Existing article',
            'password' => 'magnet:?xt=existing',
            'https_link' => 'https://example.com/old.torrent',
            'detail_url' => 'https://sukebei.nyaa.si/view/4572636',
            'source_type' => 2,
            'article_time' => now(),
            'is_disabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('images')->insert([
            'article_id' => $articleId,
            'image_name' => 'old.jpg',
            'image_path' => 'https://example.com/old.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $html = <<<'HTML'
<html><body>
<h3 class="panel-title">Updated article</h3>
<div class="row"><div class="col-md-1">Date:</div><div class="col-md-5">2026-08-19 10:30 UTC</div></div>
<a href="magnet:?xt=updated">Magnet</a>
<div class="panel-footer clearfix"><a href="/download/updated.torrent">Download</a></div>
<div id="torrent-description">https://images.example.com/updated.jpg</div>
</body></html>
HTML;

        $imageController = Mockery::mock(GetRealImageController::class);
        $imageController->shouldReceive('processImage')
            ->once()
            ->with('https://images.example.com/updated.jpg')
            ->andReturn('https://images.example.com/updated.jpg');

        $controller = new GetBtDataDetailController($imageController);
        $property = new \ReflectionProperty($controller, 'client');
        $property->setValue($controller, new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'text/html'], $html),
            ])),
        ]));

        $this->assertTrue($controller->fetchDetail('https://sukebei.nyaa.si/view/4572636', true));
        $this->assertSame(1, Article::query()->count());
        $this->assertSame('Updated article', Article::query()->findOrFail($articleId)->title);
        $this->assertDatabaseMissing('images', ['image_path' => 'https://example.com/old.jpg']);
        $this->assertDatabaseHas('images', [
            'article_id' => $articleId,
            'image_path' => 'https://images.example.com/updated.jpg',
        ]);
    }

    public function test_it_uses_the_official_fc2_cover_when_the_source_image_host_fails(): void
    {
        $detailHtml = <<<'HTML'
<html><body>
<h3 class="panel-title">+++ FC2-PPV-4963159 Example</h3>
<div class="row"><div class="col-md-1">Date:</div><div class="col-md-5">2026-08-19 10:30 UTC</div></div>
<a href="magnet:?xt=example">Magnet</a>
<div class="panel-footer clearfix"><a href="/download/example.torrent">Download</a></div>
<div id="torrent-description">https://ai18.pics/upload/example.jpg</div>
</body></html>
HTML;

        $officialHtml = <<<'HTML'
<html><head><meta property="og:image" content="https://storage201000.contents.fc2.com/file/example.png"></head></html>
HTML;

        $imageController = Mockery::mock(GetRealImageController::class);
        $imageController->shouldReceive('processImage')
            ->once()
            ->with('https://ai18.pics/upload/example.jpg')
            ->andReturnNull();

        $controller = new GetBtDataDetailController($imageController);
        $property = new \ReflectionProperty($controller, 'client');
        $property->setValue($controller, new Client([
            'handler' => HandlerStack::create(new MockHandler([
                new Response(200, ['Content-Type' => 'text/html'], $detailHtml),
                new Response(200, ['Content-Type' => 'text/html'], $officialHtml),
            ])),
        ]));

        $this->assertTrue($controller->fetchDetail('https://sukebei.nyaa.si/view/4687019'));
        $this->assertDatabaseHas('images', [
            'image_path' => 'https://storage201000.contents.fc2.com/file/example.png',
        ]);
    }
}
