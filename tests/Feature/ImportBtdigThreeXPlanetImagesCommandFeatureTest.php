<?php

namespace Tests\Feature;

use App\Console\Commands\ImportBtdigThreeXPlanetImagesCommand;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use ReflectionClass;
use Tests\TestCase;

class ImportBtdigThreeXPlanetImagesCommandFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required for this feature test.');
        }

        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        DB::reconnect('sqlite');

        Schema::dropAllTables();

        Schema::create('btdig_results', function (Blueprint $table): void {
            $table->id();
            $table->string('search_keyword');
            $table->string('type');
        });

        Schema::create('btdig_result_images', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('btdig_result_id');
            $table->string('search_keyword');
            $table->unsignedInteger('keyword_number');
            $table->string('search_url')->nullable();
            $table->string('article_url')->nullable();
            $table->string('article_title')->nullable();
            $table->string('viewimage_url');
            $table->string('image_url');
            $table->string('image_mime_type')->nullable();
            $table->string('image_extension')->nullable();
            $table->unsignedBigInteger('image_size_bytes')->nullable();
            $table->string('image_sha1')->nullable();
            $table->longText('image_base64')->nullable();
            $table->unsignedInteger('sort_order')->default(1);
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_skips_keyword_groups_that_already_have_images(): void
    {
        DB::table('btdig_results')->insert([
            ['id' => 1, 'search_keyword' => 'FC2-PPV-1233512', 'type' => '2'],
            ['id' => 2, 'search_keyword' => 'FC2-PPV-1233511', 'type' => '2'],
        ]);

        DB::table('btdig_result_images')->insert([
            'btdig_result_id' => 1,
            'search_keyword' => 'FC2-PPV-1233512',
            'keyword_number' => 1233512,
            'search_url' => 'https://example.com/search',
            'article_url' => 'https://example.com/article',
            'article_title' => 'Example',
            'viewimage_url' => 'https://example.com/viewimage/1',
            'image_url' => 'https://example.com/image/1.jpg',
            'image_mime_type' => 'image/jpeg',
            'image_extension' => 'jpg',
            'image_size_bytes' => 123,
            'image_sha1' => sha1('example'),
            'image_base64' => base64_encode('example'),
            'sort_order' => 1,
            'fetched_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $command = $this->app->make(ImportBtdigThreeXPlanetImagesCommand::class);
        $reflection = new ReflectionClass($command);
        $method = $reflection->getMethod('resolveKeywordGroups');
        $method->setAccessible(true);

        $groups = $method->invoke($command, 1233512, 10);

        $this->assertCount(1, $groups);
        $this->assertSame('FC2-PPV-1233511', $groups->first()->search_keyword);
    }
}
