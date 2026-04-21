<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlogBtControllerTest extends TestCase
{
    private string $originalDatabaseDefault;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this feature test.');
        }

        $this->originalDatabaseDefault = (string) config('database.default');

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        DB::setDefaultConnection('sqlite');

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
        Schema::dropIfExists('images');
        Schema::dropIfExists('articles');

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_index_shows_rerun_button_only_for_bt_articles_without_images(): void
    {
        $missingImagesArticleId = DB::table('articles')->insertGetId([
            'title' => '+++ FC2-PPV-RERUN-MISSING',
            'password' => 'magnet:?xt=missing',
            'https_link' => 'https://example.com/missing.torrent',
            'detail_url' => 'https://sukebei.nyaa.si/view/900001',
            'source_type' => 2,
            'article_time' => now(),
            'is_disabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $readyArticleId = DB::table('articles')->insertGetId([
            'title' => '+++ FC2-PPV-RERUN-READY',
            'password' => 'magnet:?xt=ready',
            'https_link' => 'https://example.com/ready.torrent',
            'detail_url' => 'https://sukebei.nyaa.si/view/900002',
            'source_type' => 2,
            'article_time' => now(),
            'is_disabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('images')->insert([
            'article_id' => $readyArticleId,
            'image_name' => 'ready.jpg',
            'image_path' => 'https://example.com/ready.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('blogBt.index', ['search' => 'FC2-PPV-RERUN']));

        $response->assertOk()
            ->assertSee('目前沒有圖片，可直接重跑')
            ->assertSee('圖片 1 張');

        $html = $response->getContent();

        $this->assertStringContainsString(route('blogBt.rerun', $missingImagesArticleId), $html);
        $this->assertStringNotContainsString(route('blogBt.rerun', $readyArticleId), $html);
    }

    public function test_rerun_uses_existing_bt_reimport_command(): void
    {
        $articleId = DB::table('articles')->insertGetId([
            'title' => '+++ FC2-PPV-RERUN-ACTION',
            'password' => 'magnet:?xt=action',
            'https_link' => 'https://example.com/action.torrent',
            'detail_url' => 'https://sukebei.nyaa.si/view/900003',
            'source_type' => 2,
            'article_time' => now(),
            'is_disabled' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::spy();

        $response = $this->from(route('blogBt.index'))
            ->post(route('blogBt.rerun', $articleId));

        $response->assertRedirect(route('blogBt.index'));
        $response->assertSessionHas('success');

        Artisan::shouldHaveReceived('call')
            ->once()
            ->with('bt:reimport', ['url' => 'https://sukebei.nyaa.si/view/900003']);
    }
}
