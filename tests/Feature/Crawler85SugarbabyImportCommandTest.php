<?php

namespace Tests\Feature;

use App\Console\Commands\Crawler85SugarbabyImportCommand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class Crawler85SugarbabyImportCommandTest extends TestCase
{
    private string $originalDatabaseDefault;
    private ?string $crawlerStatusTempDir = null;

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

        Schema::create('crawler_profile_candidates', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 80)->default('synthetic');
            $table->string('external_user_id', 120)->nullable();
            $table->string('nickname', 120);
            $table->unsignedTinyInteger('age')->nullable();
            $table->string('area', 40)->nullable();
            $table->text('profile_url')->nullable();
            $table->text('chat_url')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->unsignedSmallInteger('weight')->nullable();
            $table->json('matched_filter_json')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['source', 'external_user_id'], 'uq_crawler_profile_candidates_source_user');
        });

        Schema::create('crawler_profile_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('crawler_profile_candidate_id')
                ->constrained('crawler_profile_candidates')
                ->cascadeOnDelete();
            $table->text('image_url');
            $table->char('image_url_hash', 64);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['crawler_profile_candidate_id', 'image_url_hash'], 'uq_crawler_profile_images_candidate_hash');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('crawler_profile_images');
        Schema::dropIfExists('crawler_profile_candidates');
        if ($this->crawlerStatusTempDir !== null) {
            File::deleteDirectory($this->crawlerStatusTempDir);
        }

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_import_persists_only_new_profiles_without_touching_existing_rows(): void
    {
        $source = '85sugarbaby_active_flow';
        $originalCreatedAt = CarbonImmutable::parse('2026-06-28 15:21:56');
        $newCapturedAt = CarbonImmutable::parse('2026-06-28 22:55:26');

        $existingId = DB::table('crawler_profile_candidates')->insertGetId([
            'source' => $source,
            'external_user_id' => '123749',
            'nickname' => 'Lilyyye',
            'age' => 20,
            'area' => '新北',
            'profile_url' => 'https://85sugarbaby.com.tw/view?user_id=123749',
            'chat_url' => 'https://85sugarbaby.com.tw/chatroom?peerid=123749',
            'height' => 158,
            'weight' => 40,
            'matched_filter_json' => json_encode(['source' => $source]),
            'raw_payload' => json_encode(['UserId' => '123749']),
            'captured_at' => $originalCreatedAt,
            'created_at' => $originalCreatedAt,
            'updated_at' => $originalCreatedAt,
        ]);

        DB::table('crawler_profile_images')->insert([
            'crawler_profile_candidate_id' => $existingId,
            'image_url' => 'https://85sugarbaby.com.tw/old.jpg',
            'image_url_hash' => hash('sha256', 'https://85sugarbaby.com.tw/old.jpg'),
            'sort_order' => 1,
            'captured_at' => $originalCreatedAt,
            'created_at' => $originalCreatedAt,
            'updated_at' => $originalCreatedAt,
        ]);

        $inserted = $this->invokePersistCandidates($source, [
            $this->candidate('123749', 'Changed Existing', 'https://85sugarbaby.com.tw/new-existing.jpg'),
            $this->candidate('999001', 'New Person', 'https://85sugarbaby.com.tw/new-person.jpg'),
        ], $newCapturedAt);

        $this->assertSame(1, $inserted);
        $this->assertSame(2, DB::table('crawler_profile_candidates')->where('source', $source)->count());

        $existing = DB::table('crawler_profile_candidates')->where('external_user_id', '123749')->first();
        $this->assertSame('Lilyyye', $existing->nickname);
        $this->assertSame('2026-06-28 15:21:56', (string) $existing->captured_at);
        $this->assertSame('2026-06-28 15:21:56', (string) $existing->updated_at);
        $this->assertSame(1, DB::table('crawler_profile_images')->where('crawler_profile_candidate_id', $existingId)->count());

        $new = DB::table('crawler_profile_candidates')->where('external_user_id', '999001')->first();
        $this->assertNotNull($new);
        $this->assertSame('New Person', $new->nickname);
        $this->assertSame('2026-06-28 22:55:26', (string) $new->captured_at);
        $this->assertSame(1, DB::table('crawler_profile_images')->where('crawler_profile_candidate_id', $new->id)->count());
    }

    public function test_dashboard_orders_and_displays_first_created_time(): void
    {
        $source = '85sugarbaby_active_flow';

        DB::table('crawler_profile_candidates')->insert([
            [
                'source' => $source,
                'external_user_id' => 'old-user',
                'nickname' => 'Old User',
                'age' => 20,
                'area' => '新北',
                'captured_at' => '2026-06-28 22:55:26',
                'created_at' => '2026-06-28 15:21:56',
                'updated_at' => '2026-06-28 22:55:26',
            ],
            [
                'source' => $source,
                'external_user_id' => 'new-user',
                'nickname' => 'New User',
                'age' => 20,
                'area' => '台北',
                'captured_at' => '2026-06-28 18:00:00',
                'created_at' => '2026-06-28 19:37:26',
                'updated_at' => '2026-06-28 19:37:26',
            ],
        ]);

        $this->get('/crawler/85sugarbaby?source=' . $source)
            ->assertOk()
            ->assertSeeInOrder(['New User', 'Old User'])
            ->assertSee('第一次建檔時間新到舊')
            ->assertSee('重新登入 Session')
            ->assertSee('2026-06-28 15:21:56');
    }

    public function test_dashboard_shows_session_expired_when_latest_probe_requires_login(): void
    {
        $source = '85sugarbaby_active_flow';
        $this->crawlerStatusTempDir = storage_path('framework/testing/85sugarbaby-status-' . uniqid('', true));
        File::ensureDirectoryExists($this->crawlerStatusTempDir);
        config()->set('crawler.85sugarbaby.import_output_dir', $this->crawlerStatusTempDir);

        DB::table('crawler_profile_candidates')->insert([
            'source' => $source,
            'external_user_id' => 'old-user',
            'nickname' => 'Old User',
            'age' => 20,
            'area' => '新北',
            'captured_at' => '2026-07-01 04:27:55',
            'created_at' => '2026-07-01 04:27:55',
            'updated_at' => '2026-07-01 04:27:55',
        ]);

        file_put_contents($this->crawlerStatusTempDir . DIRECTORY_SEPARATOR . '20260701_173415_meta.json', json_encode([
            'captured_at' => '2026-07-01T09:34:18.775Z',
            'final_url' => 'https://85sugarbaby.com.tw/login.html',
            'status' => 'site_login_required',
            'reason' => '85sugarbaby redirected to login page',
            'api_probe_summary' => [
                'isLoggedIn' => false,
                'endpoints' => [
                    '/GetLoginListByLoginTime' => [
                        'rows' => null,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE));

        $this->get('/crawler/85sugarbaby?source=' . $source)
            ->assertOk()
            ->assertSee('啟用，但 Session 失效')
            ->assertSee('最近執行：2026-07-01 17:34:18')
            ->assertSee('API rows：0')
            ->assertSee('最新抓取沒有成功匯入')
            ->assertSee('https://85sugarbaby.com.tw/login.html');
    }

    public function test_login_session_route_is_safe_in_testing(): void
    {
        $this->get('/crawler/85sugarbaby/session/login')
            ->assertRedirect('/crawler/85sugarbaby')
            ->assertSessionHas('crawler_status', '測試模式已略過啟動 Chrome。');
    }

    private function invokePersistCandidates(string $source, array $candidates, CarbonImmutable $capturedAt): int
    {
        $method = new ReflectionMethod(Crawler85SugarbabyImportCommand::class, 'persistCandidates');
        $method->setAccessible(true);

        return (int) $method->invoke(
            $this->app->make(Crawler85SugarbabyImportCommand::class),
            $source,
            $candidates,
            ['source' => $source],
            $capturedAt
        );
    }

    private function candidate(string $userId, string $nickname, string $imageUrl): array
    {
        return [
            'external_user_id' => $userId,
            'nickname' => $nickname,
            'age' => 20,
            'area' => '新北',
            'profile_url' => 'https://85sugarbaby.com.tw/view?user_id=' . $userId,
            'chat_url' => 'https://85sugarbaby.com.tw/chatroom?peerid=' . $userId,
            'height' => 158,
            'weight' => 40,
            'images' => [$imageUrl],
            'raw_payload' => ['UserId' => $userId],
        ];
    }
}
