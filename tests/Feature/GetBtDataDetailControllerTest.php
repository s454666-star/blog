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

        Schema::dropAllTables();

        Schema::create('articles', function (Blueprint $table): void {
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

        Schema::create('images', function (Blueprint $table): void {
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
}
