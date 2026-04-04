<?php

namespace Tests\Feature;

use App\Services\VideoRerunSyncService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VideoRerunSyncControllerTest extends TestCase
{
    private string $originalDatabaseDefault;
    private string $dbRoot;
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

        $base = storage_path('framework/testing/video-rerun-sync-' . uniqid());
        $this->dbRoot = $base . DIRECTORY_SEPARATOR . 'db-video';
        $this->rerunRoot = $base . DIRECTORY_SEPARATOR . 'rerun';
        $this->eagleLibrary = $base . DIRECTORY_SEPARATOR . '重跑資源.library';

        File::ensureDirectoryExists($this->dbRoot);
        File::ensureDirectoryExists($this->rerunRoot);
        File::ensureDirectoryExists($this->eagleLibrary . DIRECTORY_SEPARATOR . 'images');

        config()->set('filesystems.disks.videos.root', $this->dbRoot);
        config()->set('video_rerun_sync.rerun_root', $this->rerunRoot);
        config()->set('video_rerun_sync.eagle.library_path', $this->eagleLibrary);
        config()->set('video_rerun_sync.eagle.base_url', 'http://eagle.test');
        config()->set('video_rerun_sync.eagle.page_size', 500);

        $this->dropTables();
        $this->createTables();
        $this->fakeEagleApi();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        File::deleteDirectory(dirname($this->dbRoot));
        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_diff_page_groups_same_file_even_when_names_differ(): void
    {
        $this->createDbVideo(101, '口交.mp4', '口交_11/口交.mp4', 'same-file');
        $this->createRerunFile('另名_檔案.mp4', 'same-file');

        app(VideoRerunSyncService::class)->scan(false);

        $response = $this->get(route('videos.rerun-sync.index'));

        $response->assertOk();
        $response->assertSee('口交_11');
        $response->assertSee('另名_檔案');
        $response->assertSee('有缺少來源');
    }

    public function test_fill_missing_copies_file_to_rerun_and_eagle(): void
    {
        $this->createDbVideo(102, '自拍.mp4', '自拍_12/自拍.mp4', 'fill-me');
        $hash = sha1('fill-me');

        app(VideoRerunSyncService::class)->scan(false);

        $response = $this->post(route('videos.rerun-sync.apply'), [
            'action' => 'fill_missing',
            'hashes' => [$hash],
        ]);

        $response->assertRedirect(route('videos.rerun-sync.index', ['mode' => 'all']));
        $this->assertFileExists($this->rerunRoot . DIRECTORY_SEPARATOR . '自拍_12.mp4');
        $this->assertNotEmpty($this->eagleItems);

        $page = $this->get(route('videos.rerun-sync.index'));
        $page->assertOk();
        $page->assertDontSee('自拍_12');
    }

    public function test_delete_extras_removes_rerun_and_eagle_when_db_missing(): void
    {
        $this->createRerunFile('多出_01.mp4', 'delete-me');
        $this->addEagleItem('EXTRA01', 'Eagle_多出', 'mp4', 'delete-me');
        $hash = sha1('delete-me');

        app(VideoRerunSyncService::class)->scan(false);

        $response = $this->post(route('videos.rerun-sync.apply'), [
            'action' => 'delete_extras',
            'hashes' => [$hash],
        ]);

        $response->assertRedirect(route('videos.rerun-sync.index', ['mode' => 'all']));
        $this->assertFileDoesNotExist($this->rerunRoot . DIRECTORY_SEPARATOR . '多出_01.mp4');
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
                return Http::response([
                    'status' => 'success',
                ], 200);
            }

            if (str_contains($url, '/api/item/list')) {
                return Http::response([
                    'status' => 'success',
                    'data' => array_values($this->eagleItems),
                ], 200);
            }

            if (str_ends_with($url, '/api/item/addFromPaths')) {
                $payload = json_decode($request->body(), true);
                foreach (($payload['items'] ?? []) as $index => $item) {
                    $itemId = 'NEW' . str_pad((string) (count($this->eagleItems) + $index + 1), 4, '0', STR_PAD_LEFT);
                    $path = (string) ($item['path'] ?? '');
                    $name = (string) ($item['name'] ?? pathinfo($path, PATHINFO_FILENAME));
                    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                    $this->addEagleItem($itemId, $name, $ext, (string) file_get_contents($path));
                }

                return Http::response(['status' => 'success'], 200);
            }

            if (str_ends_with($url, '/api/item/moveToTrash')) {
                $payload = json_decode($request->body(), true);
                foreach (($payload['itemIds'] ?? []) as $itemId) {
                    $entry = $this->eagleItems[$itemId] ?? null;
                    if ($entry !== null) {
                        File::deleteDirectory($this->eagleLibrary . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $itemId . '.info');
                        unset($this->eagleItems[$itemId]);
                    }
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

    private function createDbVideo(int $id, string $videoName, string $relativePath, string $contents): void
    {
        $absolutePath = $this->dbRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        file_put_contents($absolutePath, $contents);

        DB::table('video_master')->insert([
            'id' => $id,
            'video_name' => $videoName,
            'video_path' => $relativePath,
            'video_type' => '1',
            'duration' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createRerunFile(string $fileName, string $contents): void
    {
        file_put_contents($this->rerunRoot . DIRECTORY_SEPARATOR . $fileName, $contents);
    }

    private function createTables(): void
    {
        Schema::create('video_master', function (Blueprint $table): void {
            $table->integer('id')->primary();
            $table->string('video_name', 255)->nullable();
            $table->string('video_path', 500)->nullable();
            $table->string('m3u8_path', 500)->nullable();
            $table->decimal('duration', 10, 2)->nullable();
            $table->string('video_type', 32)->nullable();
            $table->timestamps();
        });

        Schema::create('video_rerun_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('status', 32)->default('running');
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->unsignedInteger('db_seen_count')->default(0);
            $table->unsignedInteger('rerun_seen_count')->default(0);
            $table->unsignedInteger('eagle_seen_count')->default(0);
            $table->unsignedInteger('hashed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('missing_file_count')->default(0);
            $table->unsignedInteger('diff_group_count')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->json('summary_json')->nullable();
            $table->timestamps();
        });

        Schema::create('video_rerun_sync_entries', function (Blueprint $table): void {
            $table->id();
            $table->string('source_type', 32);
            $table->string('source_key', 191);
            $table->string('source_item_id', 191)->nullable();
            $table->string('resource_key', 255)->nullable();
            $table->string('display_name', 500)->nullable();
            $table->string('relative_path', 1000)->nullable();
            $table->string('absolute_path', 1000)->nullable();
            $table->string('file_extension', 32)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->char('content_sha1', 40)->nullable();
            $table->string('fingerprint_status', 32)->default('pending');
            $table->text('last_error')->nullable();
            $table->unsignedBigInteger('last_seen_run_id')->nullable();
            $table->dateTime('discovered_at')->nullable();
            $table->dateTime('fingerprinted_at')->nullable();
            $table->boolean('is_present')->default(true);
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('video_rerun_sync_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('action_type', 32);
            $table->char('content_sha1', 40)->nullable();
            $table->string('target_source', 32);
            $table->string('target_key', 191)->nullable();
            $table->string('status', 32)->default('success');
            $table->text('message')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamps();
        });
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('video_rerun_sync_action_logs');
        Schema::dropIfExists('video_rerun_sync_entries');
        Schema::dropIfExists('video_rerun_sync_runs');
        Schema::dropIfExists('video_master');
    }
}
