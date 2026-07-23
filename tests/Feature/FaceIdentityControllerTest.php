<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FaceIdentityControllerTest extends TestCase
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

        Schema::connection('sqlite')->create('face_identity_people', function (Blueprint $table): void {
            $table->id();
            $table->string('feature_model', 64)->default('facenet_pytorch_vggface2_mtcnn_v1');
            $table->string('cover_sample_path', 500)->nullable();
            $table->unsignedInteger('video_count')->default(0);
            $table->unsignedInteger('sample_count')->default(0);
            $table->dateTime('first_seen_at')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->longText('centroid_embedding_json')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('face_identity_videos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('person_id')->nullable();
            $table->string('feature_model', 64)->default('facenet_pytorch_vggface2_mtcnn_v1');
            $table->string('source_root_label', 100)->nullable();
            $table->string('source_root_path', 500)->nullable();
            $table->string('relative_directory', 500)->nullable();
            $table->string('relative_path', 700);
            $table->text('absolute_path');
            $table->string('file_name', 255);
            $table->char('path_sha1', 40)->unique();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->dateTime('file_modified_at')->nullable();
            $table->decimal('duration_seconds', 10, 3)->default(0);
            $table->unsignedInteger('frame_interval_seconds')->default(240);
            $table->unsignedTinyInteger('accepted_sample_count')->default(0);
            $table->string('preview_sample_path', 500)->nullable();
            $table->decimal('match_confidence', 6, 4)->nullable();
            $table->string('assignment_source', 16)->default('auto');
            $table->boolean('group_locked')->default(false);
            $table->string('scan_status', 32)->default('pending');
            $table->dateTime('last_scanned_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('face_identity_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id');
            $table->foreignId('person_id')->nullable();
            $table->string('feature_model', 64)->default('facenet_pytorch_vggface2_mtcnn_v1');
            $table->unsignedTinyInteger('capture_order')->default(1);
            $table->decimal('capture_second', 10, 3)->default(0);
            $table->string('image_path', 500)->default('');
            $table->longText('embedding_json')->nullable();
            $table->char('embedding_sha1', 40)->nullable();
            $table->decimal('detector_score', 6, 4)->nullable();
            $table->decimal('quality_score', 8, 3)->nullable();
            $table->decimal('blur_score', 10, 3)->nullable();
            $table->decimal('frontal_score', 8, 3)->nullable();
            $table->json('bbox_json')->nullable();
            $table->json('landmarks_json')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            parent::tearDown();

            return;
        }

        Schema::connection('sqlite')->dropIfExists('face_identity_samples');
        Schema::connection('sqlite')->dropIfExists('face_identity_videos');
        Schema::connection('sqlite')->dropIfExists('face_identity_people');

        DB::disconnect('sqlite');
        config()->set('database.default', $this->originalDatabaseDefault);

        parent::tearDown();
    }

    public function test_index_orders_people_and_videos_by_video_modified_time_descending(): void
    {
        $olderPersonId = DB::table('face_identity_people')->insertGetId([
            'feature_model' => 'facenet_pytorch_vggface2_mtcnn_v1',
            'video_count' => 2,
            'sample_count' => 0,
            'first_seen_at' => '2026-04-01 10:00:00',
            'last_seen_at' => '2026-04-22 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $newerPersonId = DB::table('face_identity_people')->insertGetId([
            'feature_model' => 'facenet_pytorch_vggface2_mtcnn_v1',
            'video_count' => 1,
            'sample_count' => 0,
            'first_seen_at' => '2026-03-01 10:00:00',
            'last_seen_at' => '2026-04-01 12:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('face_identity_videos')->insert([
            [
                'person_id' => $olderPersonId,
                'relative_path' => 'older-group/scan-newer.mp4',
                'absolute_path' => 'C:/videos/older-group/scan-newer.mp4',
                'file_name' => 'scan-newer.mp4',
                'path_sha1' => sha1('scan-newer.mp4'),
                'file_modified_at' => '2026-04-01 09:00:00',
                'last_scanned_at' => '2026-04-22 09:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'person_id' => $olderPersonId,
                'relative_path' => 'older-group/file-newer.mp4',
                'absolute_path' => 'C:/videos/older-group/file-newer.mp4',
                'file_name' => 'file-newer.mp4',
                'path_sha1' => sha1('file-newer.mp4'),
                'file_modified_at' => '2026-04-10 09:00:00',
                'last_scanned_at' => '2026-04-20 09:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'person_id' => $newerPersonId,
                'relative_path' => 'newer-group/overall-newest.mp4',
                'absolute_path' => 'C:/videos/newer-group/overall-newest.mp4',
                'file_name' => 'overall-newest.mp4',
                'path_sha1' => sha1('overall-newest.mp4'),
                'file_modified_at' => '2026-04-20 09:00:00',
                'last_scanned_at' => '2026-04-05 09:00:00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->get(route('face-identities.index'));

        $response->assertOk();

        $html = $response->getContent();

        $this->assertLessThan(
            strpos($html, '#00001'),
            strpos($html, '#00002'),
            'People should be ordered by their newest video modified time.'
        );

        $this->assertLessThan(
            strpos($html, 'scan-newer.mp4'),
            strpos($html, 'file-newer.mp4'),
            'Videos inside a group should be ordered by modified time descending.'
        );

        $this->assertStringContainsString('影片修改日期', $html);
        $this->assertStringContainsString('2026-04-20 09:00:00', $html);
        $this->assertStringContainsString('preload="none"', $html);
        $this->assertStringNotContainsString('preload="metadata"', $html);
    }

    public function test_video_stream_uses_nginx_x_accel_redirect_under_local_herd(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Herd X-Accel redirect paths are Windows-specific.');
        }

        config()->set('app.env', 'local');

        $videoDir = storage_path('app/testing face identity');
        if (!is_dir($videoDir)) {
            mkdir($videoDir, 0777, true);
        }

        $videoPath = $videoDir . DIRECTORY_SEPARATOR . 'sample video.mp4';
        file_put_contents($videoPath, 'fake mp4 content');

        try {
            $personId = DB::table('face_identity_people')->insertGetId([
                'feature_model' => 'facenet_pytorch_vggface2_mtcnn_v1',
                'video_count' => 1,
                'sample_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $videoId = DB::table('face_identity_videos')->insertGetId([
                'person_id' => $personId,
                'relative_path' => 'testing/sample video.mp4',
                'absolute_path' => $videoPath,
                'file_name' => 'sample video.mp4',
                'path_sha1' => sha1($videoPath),
                'file_modified_at' => '2026-06-25 18:30:00',
                'last_scanned_at' => '2026-06-25 18:30:00',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this
                ->withServerVariables(['SERVER_SOFTWARE' => 'nginx/1.25.2'])
                ->get(route('face-identities.video', $videoId));

            $normalizedPath = str_replace('\\', '/', $videoPath);
            $drive = strtoupper(substr($normalizedPath, 0, 2));
            $path = ltrim(substr($normalizedPath, 2), '/');
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $path)));
            $expectedRedirect = '/41c270e4-5535-4daa-b23e-c269744c2f45/' . $drive . '/' . $encodedPath;

            $response->assertOk();
            $response->assertHeader('X-Accel-Redirect', $expectedRedirect);
            $response->assertHeader('Accept-Ranges', 'bytes');
            $this->assertSame('', $response->getContent());
        } finally {
            @unlink($videoPath);
        }
    }
}
