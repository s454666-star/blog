<?php

namespace Tests\Feature;

use App\Contracts\RecycleBin;
use App\Models\TgVideoReview;
use App\Services\TgVideoReviewActionService;
use App\Services\TgVideoReviewScanner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TgVideoReviewTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blog-tg-video-review-' . Str::uuid();
        File::ensureDirectoryExists($this->root);
        config()->set('tg_video_review.root', $this->root);

        Schema::connection('sqlite')->create('tg_video_reviews', function (Blueprint $table): void {
            $table->id();
            $table->char('path_hash', 40)->unique();
            $table->text('video_path');
            $table->text('image_path');
            $table->unsignedBigInteger('file_size_bytes');
            $table->unsignedBigInteger('file_modified_at');
            $table->decimal('duration_seconds', 12, 3);
            $table->unsignedTinyInteger('screenshot_count')->default(20);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('tg_video_reviews');
        if (is_dir($this->root)) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_page_has_only_selection_and_image_columns_with_required_controls(): void
    {
        $record = $this->makeRecord('page.mp4');

        $response = $this->get(route('tg-video-review.index'));
        $response->assertOk()
            ->assertSee('<th>圖片</th>', false)
            ->assertSee('id="selectPage"', false)
            ->assertSee('全選本頁所有資料', false)
            ->assertSee('class="selection-cell"', false)
            ->assertSee("document.querySelectorAll('.selection-cell')", false)
            ->assertSee('data-action="delete"', false)
            ->assertSee('data-action="ok"', false)
            ->assertSee('data-action="watermark"', false)
            ->assertSee('<option value="50" selected>50</option>', false)
            ->assertSee('position:fixed; inset:0', false)
            ->assertSee(route('tg-video-review.image', $record), false)
            ->assertDontSee('<th>檔名</th>', false);

        $this->get(route('tg-video-review.index', ['per_page' => 100]))
            ->assertSee('<option value="100" selected>100</option>', false);
        $this->get(route('tg-video-review.index', ['per_page' => 1000]))
            ->assertSee('<option value="1000" selected>1000</option>', false);
        $this->get(route('tg-video-review.index', ['per_page' => 2000]))
            ->assertSee('<option value="2000" selected>2000</option>', false);
        $this->get(route('tg-video-review.index', ['per_page' => 999]))
            ->assertSee('<option value="50" selected>50</option>', false);
    }

    public function test_image_route_serves_only_an_image_from_the_configured_root(): void
    {
        $record = $this->makeRecord('image.mp4');
        $this->get(route('tg-video-review.image', $record))->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $outside = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'outside-' . Str::uuid() . '.jpg';
        file_put_contents($outside, $this->tinyJpeg());
        $record->update(['image_path' => $outside]);
        $this->get(route('tg-video-review.image', $record))->assertForbidden();
        @unlink($outside);
    }

    public function test_delete_moves_generated_files_through_recycle_bin_then_deletes_exact_row(): void
    {
        $record = $this->makeRecord('delete.mp4');
        $this->app->instance(RecycleBin::class, new class implements RecycleBin {
            public function move(array $paths): void
            {
                foreach ($paths as $path) {
                    unlink($path);
                }
            }
        });

        $response = $this->postJson(route('tg-video-review.actions'), [
            'ids' => [$record->id],
            'action' => 'delete',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'completed_ids' => [$record->id]]);
        $this->assertDatabaseMissing('tg_video_reviews', ['id' => $record->id]);
        $this->assertFileDoesNotExist($record->video_path);
        $this->assertFileDoesNotExist($record->image_path);
    }

    public function test_delete_cleans_stale_row_when_video_and_image_are_already_missing(): void
    {
        $record = $this->makeRecord('stale-delete.mp4');
        unlink($record->video_path);
        unlink($record->image_path);
        $recycleBin = new class implements RecycleBin {
            public bool $called = false;
            public function move(array $paths): void
            {
                $this->called = true;
            }
        };
        $this->app->instance(RecycleBin::class, $recycleBin);

        $response = $this->postJson(route('tg-video-review.actions'), [
            'ids' => [$record->id],
            'action' => 'delete',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'completed_ids' => [$record->id]]);
        $this->assertFalse($recycleBin->called);
        $this->assertDatabaseMissing('tg_video_reviews', ['id' => $record->id]);
    }

    public function test_delete_cleans_legacy_stale_row_even_when_its_missing_paths_are_outside_current_root(): void
    {
        $record = $this->makeRecord('legacy-stale.mp4');
        unlink($record->video_path);
        unlink($record->image_path);
        $record->update([
            'video_path' => 'X:\\old-root\\legacy-stale.mp4',
            'image_path' => 'X:\\old-root\\legacy-stale.jpg',
        ]);

        $response = $this->postJson(route('tg-video-review.actions'), [
            'ids' => [$record->id],
            'action' => 'delete',
        ]);

        $response->assertOk()->assertJson(['ok' => true, 'completed_ids' => [$record->id]]);
        $this->assertDatabaseMissing('tg_video_reviews', ['id' => $record->id]);
    }

    public function test_delete_recycles_only_the_file_that_still_exists(): void
    {
        $record = $this->makeRecord('partial-delete.mp4');
        unlink($record->image_path);
        $recycleBin = new class implements RecycleBin {
            public array $recycled = [];
            public function move(array $paths): void
            {
                $this->recycled = $paths;
                foreach ($paths as $path) {
                    unlink($path);
                }
            }
        };
        $this->app->instance(RecycleBin::class, $recycleBin);

        $response = $this->postJson(route('tg-video-review.actions'), [
            'ids' => [$record->id],
            'action' => 'delete',
        ]);

        $response->assertOk()->assertJson(['ok' => true]);
        $this->assertSame([$record->video_path], $recycleBin->recycled);
        $this->assertDatabaseMissing('tg_video_reviews', ['id' => $record->id]);
    }

    public function test_delete_failure_with_non_utf8_windows_output_still_returns_valid_json(): void
    {
        $record = $this->makeRecord('invalid-output.mp4');
        $this->app->instance(RecycleBin::class, new class implements RecycleBin {
            public function move(array $paths): void
            {
                throw new \RuntimeException("\xA5\x5C\xB1\xD0");
            }
        });

        $response = $this->postJson(route('tg-video-review.actions'), [
            'ids' => [$record->id],
            'action' => 'delete',
        ]);

        $response->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonCount(1, 'failed');
        $this->assertTrue(mb_check_encoding((string) $response->json('failed.0.message'), 'UTF-8'));
        $this->assertDatabaseHas('tg_video_reviews', ['id' => $record->id]);
        $this->assertFileExists($record->video_path);
        $this->assertFileExists($record->image_path);
    }

    public function test_windows_recycle_bin_passes_multiple_paths_through_a_utf8_manifest(): void
    {
        $service = file_get_contents(app_path('Services/WindowsRecycleBin.php'));
        $script = file_get_contents(base_path('scripts/send-to-recycle-bin.ps1'));

        $this->assertIsString($service);
        $this->assertIsString($script);
        $this->assertStringContainsString("'-ManifestPath', \$manifestPath", $service);
        $this->assertStringContainsString('[string]$ManifestPath', $script);
        $this->assertStringContainsString('ConvertFrom-Json', $script);
        $this->assertStringNotContainsString('[string[]]$Paths', $script);
    }

    public function test_delete_action_has_no_php_or_recycle_process_timeout(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/TgVideoReviewController.php'));
        $recycleBin = file_get_contents(app_path('Services/WindowsRecycleBin.php'));

        $this->assertIsString($controller);
        $this->assertIsString($recycleBin);
        $this->assertStringContainsString('set_time_limit(0)', $controller);
        $this->assertStringContainsString("ini_set('max_execution_time', '0')", $controller);
        $this->assertStringContainsString('setTimeout(null)', $recycleBin);
        $this->assertStringNotContainsString('setTimeout(120)', $recycleBin);
    }

    public function test_ok_and_watermark_move_video_delete_image_and_delete_exact_row(): void
    {
        $service = app(TgVideoReviewActionService::class);
        foreach ([
            ['action' => 'ok', 'directory' => 'ok'],
            ['action' => 'watermark', 'directory' => '水'],
        ] as $case) {
            $record = $this->makeRecord($case['action'] . '.mp4');
            $videoName = basename($record->video_path);
            $imagePath = $record->image_path;
            $result = $service->handle($record, $case['action']);
            $this->assertTrue($result['ok'], $result['message']);
            $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . $case['directory'] . DIRECTORY_SEPARATOR . $videoName);
            $this->assertFileDoesNotExist($imagePath);
            $this->assertDatabaseMissing('tg_video_reviews', ['id' => $record->id]);
        }
    }

    public function test_ok_still_moves_video_when_contact_sheet_is_already_missing(): void
    {
        $record = $this->makeRecord('ok-without-image.mp4');
        unlink($record->image_path);

        $result = app(TgVideoReviewActionService::class)->handle($record, 'ok');

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . 'ok' . DIRECTORY_SEPARATOR . 'ok-without-image.mp4');
        $this->assertDatabaseMissing('tg_video_reviews', ['id' => $record->id]);
    }

    public function test_destination_collision_preserves_source_image_and_table_row(): void
    {
        $record = $this->makeRecord('collision.mp4');
        File::ensureDirectoryExists($this->root . DIRECTORY_SEPARATOR . 'ok');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'ok' . DIRECTORY_SEPARATOR . 'collision.mp4', 'existing');

        $result = app(TgVideoReviewActionService::class)->handle($record, 'ok');

        $this->assertFalse($result['ok']);
        $this->assertFileExists($record->video_path);
        $this->assertFileExists($record->image_path);
        $this->assertDatabaseHas('tg_video_reviews', ['id' => $record->id]);
    }

    public function test_batch_validation_rejects_empty_selection_and_unknown_action(): void
    {
        $this->postJson(route('tg-video-review.actions'), ['ids' => [], 'action' => 'ok'])
            ->assertUnprocessable()->assertJsonValidationErrors('ids');
        $this->postJson(route('tg-video-review.actions'), ['ids' => [1], 'action' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('action');
    }

    public function test_scanner_uses_fake_video_ignores_subfolders_and_is_idempotent(): void
    {
        $video = $this->root . DIRECTORY_SEPARATOR . 'fake.mp4';
        $this->generateFakeVideo($video);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'ignore.txt', 'not a video');
        $subdirectory = $this->root . DIRECTORY_SEPARATOR . 'nested';
        File::ensureDirectoryExists($subdirectory);
        copy($video, $subdirectory . DIRECTORY_SEPARATOR . 'nested.mp4');

        $first = app(TgVideoReviewScanner::class)->scan($this->root, null, 'testrun01');
        $this->assertSame(['videos' => 1, 'generated' => 1, 'unchanged' => 0, 'failed' => 0], $first);
        $this->assertDatabaseCount('tg_video_reviews', 1);
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . 'fake.jpg');
        $size = getimagesize($this->root . DIRECTORY_SEPARATOR . 'fake.jpg');
        $this->assertGreaterThan(2000, $size[0]);
        $this->assertGreaterThan(1000, $size[1]);

        $second = app(TgVideoReviewScanner::class)->scan($this->root, null, 'testrun02');
        $this->assertSame(['videos' => 1, 'generated' => 0, 'unchanged' => 1, 'failed' => 0], $second);
        $this->assertDatabaseCount('tg_video_reviews', 1);
    }

    public function test_scanner_processes_windows_creation_time_from_oldest_to_newest(): void
    {
        $templateDirectory = $this->root . DIRECTORY_SEPARATOR . 'templates';
        File::ensureDirectoryExists($templateDirectory);
        $template = $templateDirectory . DIRECTORY_SEPARATOR . 'template.mp4';
        $this->generateFakeVideo($template);

        $oldest = $this->root . DIRECTORY_SEPARATOR . 'z-oldest.mp4';
        $newest = $this->root . DIRECTORY_SEPARATOR . 'a-newest.mp4';
        copy($template, $oldest);
        usleep(1_200_000);
        copy($template, $newest);

        app(TgVideoReviewScanner::class)->scan($this->root, null, 'createdorder01');

        $paths = TgVideoReview::query()->orderBy('id')->pluck('video_path')->all();
        $this->assertSame([$oldest, $newest], $paths);
    }

    public function test_scanner_publishes_each_completed_video_and_keeps_it_on_interruption(): void
    {
        $this->generateFakeVideo($this->root . DIRECTORY_SEPARATOR . 'first.mp4');
        usleep(1_200_000);
        $this->generateFakeVideo($this->root . DIRECTORY_SEPARATOR . 'second.mp4');
        $observedPublishedResult = false;

        try {
            app(TgVideoReviewScanner::class)->scan(
                $this->root,
                function (int $completed) use (&$observedPublishedResult): void {
                    if ($completed !== 1) {
                        return;
                    }

                    $observedPublishedResult = TgVideoReview::query()->count() === 1
                        && is_file($this->root . DIRECTORY_SEPARATOR . 'first.jpg');
                    throw new \RuntimeException('simulated Ctrl+C');
                },
                'livepublish01'
            );
            $this->fail('The simulated interruption should stop the scan.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated Ctrl+C', $e->getMessage());
        }

        $this->assertTrue($observedPublishedResult, 'The first row and JPG must be visible before progress is reported.');
        $this->assertDatabaseCount('tg_video_reviews', 1);
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . 'first.jpg');
        $this->assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'second.jpg');
        $this->assertDirectoryDoesNotExist(storage_path('app/tg-video-review-runs/livepublish01'));
    }

    public function test_desktop_launcher_only_runs_contact_sheet_scan_without_opening_review_page(): void
    {
        $launcher = file_get_contents(base_path('scripts/launch-tg-video-contact-sheets.cmd'));
        $script = file_get_contents(base_path('scripts/run-tg-video-contact-sheets.ps1'));

        $this->assertIsString($launcher);
        $this->assertIsString($script);
        $this->assertStringContainsString('run-tg-video-contact-sheets.ps1', $launcher);
        $this->assertStringContainsString('tg-video-review:scan', $script);
        $this->assertStringContainsString('依影片建立日期，由舊到新', $script);
        $this->assertStringNotContainsString('Start-Process', $script);
        $this->assertStringNotContainsString('開啟審核頁面', $script);
    }

    public function test_aws_zerotier_proxy_generates_https_action_and_image_urls(): void
    {
        $record = $this->makeRecord('proxy.mp4');

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.147.18.34'])
            ->withHeaders([
                'X-Forwarded-Proto' => 'https',
                'X-Forwarded-Host' => 'mystar.monster',
                'X-Forwarded-Port' => '443',
            ])
            ->get('http://mystar.monster/tg-video-review');

        $response->assertOk()
            ->assertSee('https:\/\/mystar.monster\/tg-video-review\/actions', false)
            ->assertSee("https://mystar.monster/tg-video-review/{$record->id}/image", false)
            ->assertDontSee('http:\/\/mystar.monster\/tg-video-review\/actions', false);
    }

    public function test_zerotier_proxy_listener_accepts_only_the_aws_private_ip(): void
    {
        $caddy = file_get_contents(base_path('Caddyfile.local'));

        $this->assertIsString($caddy);
        $this->assertStringContainsString(':8099', $caddy);
        $this->assertStringContainsString('bind 10.147.18.198', $caddy);
        $this->assertStringContainsString('remote_ip 10.147.18.34', $caddy);
        $this->assertStringContainsString('env HTTPS on', $caddy);
        $this->assertStringContainsString('header_up X-Forwarded-Proto https', $caddy);
        $this->assertStringContainsString('header_up X-Forwarded-Port 443', $caddy);
        $this->assertStringContainsString('respond "Forbidden" 403', $caddy);
    }

    public function test_invalid_video_is_skipped_without_residue_and_later_videos_continue(): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'a-broken.mp4', 'broken video');
        usleep(1_200_000);
        $this->generateFakeVideo($this->root . DIRECTORY_SEPARATOR . 'z-valid.mp4');

        $statuses = [];
        $result = app(TgVideoReviewScanner::class)->scan(
            $this->root,
            function (int $current, int $total, string $status) use (&$statuses): void {
                $statuses[] = [$current, $total, $status];
            },
            'invalidvideo01'
        );

        $this->assertSame(['videos' => 2, 'generated' => 1, 'unchanged' => 0, 'failed' => 1], $result);
        $this->assertSame('影片無法讀取，已略過', $statuses[0][2]);
        $this->assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'a-broken.jpg');
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . 'z-valid.jpg');
        $this->assertDatabaseCount('tg_video_reviews', 1);
        $this->assertDirectoryDoesNotExist(storage_path('app/tg-video-review-runs/invalidvideo01'));
    }

    public function test_scanner_never_overwrites_an_unmanaged_same_name_jpeg(): void
    {
        $this->generateFakeVideo($this->root . DIRECTORY_SEPARATOR . 'protected.mp4');
        $image = $this->root . DIRECTORY_SEPARATOR . 'protected.jpg';
        file_put_contents($image, 'user-owned-image');

        try {
            app(TgVideoReviewScanner::class)->scan($this->root, null, 'protected01');
            $this->fail('Unmanaged same-name JPG should block the scan.');
        } catch (\Throwable) {
            $this->assertSame('user-owned-image', file_get_contents($image));
            $this->assertDatabaseCount('tg_video_reviews', 0);
            $this->assertDirectoryDoesNotExist(storage_path('app/tg-video-review-runs/protected01'));
        }
    }

    public function test_abandoned_post_commit_run_removes_new_image_and_row_but_keeps_original_video(): void
    {
        $record = $this->makeRecord('hard-interrupt.mp4');
        $runDirectory = storage_path('app/tg-video-review-runs/hardstop01');
        File::ensureDirectoryExists($runDirectory);
        file_put_contents($runDirectory . DIRECTORY_SEPARATOR . 'journal.json', json_encode([
            'token' => 'hardstop01',
            'root' => $this->root,
            'status' => 'publishing',
            'entries' => [[
                'path_hash' => $record->path_hash,
                'video_path' => $record->video_path,
                'image_path' => $record->image_path,
                'stage_path' => $runDirectory . DIRECTORY_SEPARATOR . 'already-moved.jpg',
                'backup_path' => null,
                'published' => true,
                'committed' => false,
            ]],
            'database_before' => [$record->path_hash => null],
        ], JSON_THROW_ON_ERROR));

        app(TgVideoReviewScanner::class)->cleanupRun('hardstop01');

        $this->assertFileExists($record->video_path);
        $this->assertFileDoesNotExist($record->image_path);
        $this->assertDatabaseMissing('tg_video_reviews', ['id' => $record->id]);
        $this->assertDirectoryDoesNotExist($runDirectory);
    }

    private function makeRecord(string $videoName): TgVideoReview
    {
        $video = $this->root . DIRECTORY_SEPARATOR . $videoName;
        $image = $this->root . DIRECTORY_SEPARATOR . pathinfo($videoName, PATHINFO_FILENAME) . '.jpg';
        file_put_contents($video, 'fake-video');
        file_put_contents($image, $this->tinyJpeg());

        return TgVideoReview::query()->create([
            'path_hash' => sha1(mb_strtolower(str_replace('\\', '/', $video))),
            'video_path' => $video,
            'image_path' => $image,
            'file_size_bytes' => filesize($video),
            'file_modified_at' => filemtime($video),
            'duration_seconds' => 2,
            'screenshot_count' => 20,
        ]);
    }

    private function generateFakeVideo(string $path): void
    {
        $process = new Process([
            (string) config('tg_video_review.ffmpeg_bin'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', 'testsrc=size=320x180:rate=20', '-t', '2',
            '-c:v', 'mpeg4', '-q:v', '5', $path,
        ]);
        $process->setTimeout(60);
        $process->mustRun();
    }

    private function tinyJpeg(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAX/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAEf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=', true);
    }
}
