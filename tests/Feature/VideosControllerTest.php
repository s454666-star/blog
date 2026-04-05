<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VideosControllerTest extends TestCase
{
    private string $originalDatabaseDefault;
    private ?string $originalM3u8TargetRoot = null;
    private string $basePath;
    private string $dbRoot;
    private string $m3u8Root;
    private string $rerunRoot;
    private string $eagleLibrary;
    private array $eagleItems = [];

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->basePath = storage_path('framework/testing/videos-controller-' . uniqid());
        $this->dbRoot = $this->basePath . DIRECTORY_SEPARATOR . 'db-video';
        $this->m3u8Root = $this->basePath . DIRECTORY_SEPARATOR . 'm3u8';
        $this->rerunRoot = $this->basePath . DIRECTORY_SEPARATOR . 'rerun';
        $this->eagleLibrary = $this->basePath . DIRECTORY_SEPARATOR . '重跑資源.library';

        File::ensureDirectoryExists($this->dbRoot);
        File::ensureDirectoryExists($this->m3u8Root);
        File::ensureDirectoryExists($this->rerunRoot);
        File::ensureDirectoryExists($this->eagleLibrary . DIRECTORY_SEPARATOR . 'images');

        config()->set('filesystems.disks.videos.root', $this->dbRoot);
        config()->set('video_rerun_sync.rerun_root', $this->rerunRoot);
        config()->set('video_rerun_sync.eagle.library_path', $this->eagleLibrary);
        config()->set('video_rerun_sync.eagle.base_url', 'http://eagle.test');

        $this->originalM3u8TargetRoot = getenv('M3U8_TARGET_ROOT') !== false ? (string) getenv('M3U8_TARGET_ROOT') : null;
        putenv('M3U8_TARGET_ROOT=' . $this->m3u8Root);
        $_ENV['M3U8_TARGET_ROOT'] = $this->m3u8Root;
        $_SERVER['M3U8_TARGET_ROOT'] = $this->m3u8Root;

        $this->dropTables();
        $this->createTables();
        $this->fakeEagleApi();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        File::deleteDirectory($this->basePath);
        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        if ($this->originalM3u8TargetRoot === null) {
            putenv('M3U8_TARGET_ROOT');
            unset($_ENV['M3U8_TARGET_ROOT'], $_SERVER['M3U8_TARGET_ROOT']);
        } else {
            putenv('M3U8_TARGET_ROOT=' . $this->originalM3u8TargetRoot);
            $_ENV['M3U8_TARGET_ROOT'] = $this->originalM3u8TargetRoot;
            $_SERVER['M3U8_TARGET_ROOT'] = $this->originalM3u8TargetRoot;
        }

        parent::tearDown();
    }

    public function test_set_master_face_persists_and_returns_verified_payload(): void
    {
        $videoId = DB::table('video_master')->insertGetId([
            'video_name' => 'Clip_001.mp4',
            'video_path' => 'Clip_001/Clip_001.mp4',
            'm3u8_path' => null,
            'duration' => 12.34,
            'video_type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $screenshotId = DB::table('video_screenshots')->insertGetId([
            'video_master_id' => $videoId,
            'screenshot_path' => 'Clip_001/screenshot_1.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstFaceId = DB::table('video_face_screenshots')->insertGetId([
            'video_screenshot_id' => $screenshotId,
            'face_image_path' => 'Clip_001/Clip_001_face_1.jpg',
            'is_master' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $secondFaceId = DB::table('video_face_screenshots')->insertGetId([
            'video_screenshot_id' => $screenshotId,
            'face_image_path' => 'Clip_001/Clip_001_face_2.jpg',
            'is_master' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('video_features')->insert([
            'video_master_id' => $videoId,
            'master_face_screenshot_id' => $firstFaceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->post(route('video.setMasterFace'), [
            'face_id' => $secondFaceId,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $secondFaceId)
            ->assertJsonPath('data.video_id', $videoId)
            ->assertJsonPath('data.video_name', 'Clip_001.mp4');

        $this->assertDatabaseHas('video_face_screenshots', [
            'id' => $firstFaceId,
            'is_master' => 0,
        ]);
        $this->assertDatabaseHas('video_face_screenshots', [
            'id' => $secondFaceId,
            'is_master' => 1,
        ]);
        $this->assertDatabaseHas('video_features', [
            'video_master_id' => $videoId,
            'master_face_screenshot_id' => $secondFaceId,
        ]);

        $masterCount = DB::table('video_face_screenshots')
            ->join('video_screenshots', 'video_screenshots.id', '=', 'video_face_screenshots.video_screenshot_id')
            ->where('video_screenshots.video_master_id', $videoId)
            ->where('video_face_screenshots.is_master', 1)
            ->count();

        $this->assertSame(1, $masterCount);
    }

    public function test_delete_selected_removes_video_folder_feature_m3u8_rerun_and_eagle_assets(): void
    {
        $videoFolder = $this->dbRoot . DIRECTORY_SEPARATOR . 'Clip_001';
        File::ensureDirectoryExists($videoFolder);
        file_put_contents($videoFolder . DIRECTORY_SEPARATOR . 'Clip_001.mp4', 'video-body');
        file_put_contents($videoFolder . DIRECTORY_SEPARATOR . 'screenshot_1.jpg', 'shot');
        file_put_contents($videoFolder . DIRECTORY_SEPARATOR . 'Clip_001_face_1.jpg', 'face');

        $m3u8Folder = $this->m3u8Root . DIRECTORY_SEPARATOR . 'Clip_001';
        File::ensureDirectoryExists($m3u8Folder);
        file_put_contents($m3u8Folder . DIRECTORY_SEPARATOR . 'video.m3u8', '#EXTM3U');
        file_put_contents($m3u8Folder . DIRECTORY_SEPARATOR . 'video_00001.ts', 'ts-data');

        file_put_contents($this->rerunRoot . DIRECTORY_SEPARATOR . 'Clip_001.mp4', 'video-body');
        $this->addEagleItem('EAGLE001', 'Clip_001.mp4', 'mp4', 'video-body');

        $videoId = DB::table('video_master')->insertGetId([
            'video_name' => 'Clip_001.mp4',
            'video_path' => 'Clip_001/Clip_001.mp4',
            'm3u8_path' => '/m3u8/Clip_001/video.m3u8',
            'duration' => 66.6,
            'video_type' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $screenshotId = DB::table('video_screenshots')->insertGetId([
            'video_master_id' => $videoId,
            'screenshot_path' => 'Clip_001/screenshot_1.jpg',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('video_face_screenshots')->insert([
            'video_screenshot_id' => $screenshotId,
            'face_image_path' => 'Clip_001/Clip_001_face_1.jpg',
            'is_master' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('video_features')->insert([
            'video_master_id' => $videoId,
            'master_face_screenshot_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('videos_ts')->insert([
            'video_name' => 'Clip_001.mp4',
            'path' => '/m3u8/Clip_001/video_00001.ts',
            'video_time' => 1,
            'tags' => null,
            'rating' => null,
        ]);

        $response = $this->post(route('video.deleteSelected'), [
            'ids' => [$videoId],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('video_master', ['id' => $videoId]);
        $this->assertDatabaseMissing('video_features', ['video_master_id' => $videoId]);
        $this->assertSame(0, DB::table('videos_ts')->count());
        $this->assertFalse(File::isDirectory($videoFolder));
        $this->assertFalse(File::exists($this->rerunRoot . DIRECTORY_SEPARATOR . 'Clip_001.mp4'));
        $this->assertFalse(File::isDirectory($m3u8Folder));
        $this->assertCount(0, $this->eagleItems);
    }

    private function fakeEagleApi(): void
    {
        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();

            if (str_ends_with($url, '/api/library/info')) {
                return Http::response([
                    'status' => 'success',
                    'data' => [
                        'library' => [
                            'path' => $this->eagleLibrary,
                            'name' => '重跑資源',
                        ],
                    ],
                ], 200);
            }

            if (str_ends_with($url, '/api/library/switch')) {
                return Http::response(['status' => 'success'], 200);
            }

            if (str_contains($url, '/api/item/list')) {
                return Http::response([
                    'status' => 'success',
                    'data' => array_values($this->eagleItems),
                ], 200);
            }

            if (str_ends_with($url, '/api/item/moveToTrash')) {
                $payload = json_decode($request->body(), true);
                foreach (($payload['itemIds'] ?? []) as $itemId) {
                    if (!isset($this->eagleItems[$itemId])) {
                        continue;
                    }

                    File::deleteDirectory($this->eagleLibrary . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $itemId . '.info');
                    unset($this->eagleItems[$itemId]);
                }

                return Http::response(['status' => 'success'], 200);
            }

            return Http::response(['status' => 'success', 'data' => []], 200);
        });
    }

    private function addEagleItem(string $itemId, string $name, string $ext, string $contents): void
    {
        $directory = $this->eagleLibrary . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $itemId . '.info';
        File::ensureDirectoryExists($directory);
        file_put_contents($directory . DIRECTORY_SEPARATOR . $name . '.' . $ext, $contents);
        file_put_contents($directory . DIRECTORY_SEPARATOR . 'metadata.json', json_encode(['id' => $itemId]));

        $this->eagleItems[$itemId] = [
            'id' => $itemId,
            'name' => $name,
            'ext' => $ext,
        ];
    }

    private function createTables(): void
    {
        Schema::create('video_master', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('video_name', 255)->nullable();
            $table->string('video_path', 500);
            $table->string('m3u8_path', 500)->nullable();
            $table->decimal('duration', 10, 2)->default(0);
            $table->unsignedTinyInteger('video_type')->default(1);
            $table->timestamps();
        });

        Schema::create('video_screenshots', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('video_master_id');
            $table->string('screenshot_path', 500);
            $table->timestamps();
        });

        Schema::create('video_face_screenshots', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('video_screenshot_id');
            $table->string('face_image_path', 500);
            $table->boolean('is_master')->default(false);
            $table->timestamps();
        });

        Schema::create('videos_ts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('video_name', 255)->nullable();
            $table->string('path', 500)->nullable();
            $table->integer('video_time')->default(0);
            $table->string('tags', 255)->nullable();
            $table->tinyInteger('rating')->nullable();
        });

        Schema::create('video_features', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('video_master_id')->unique();
            $table->unsignedInteger('master_face_screenshot_id')->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('video_features');
        Schema::dropIfExists('video_face_screenshots');
        Schema::dropIfExists('video_screenshots');
        Schema::dropIfExists('videos_ts');
        Schema::dropIfExists('video_master');
    }
}
