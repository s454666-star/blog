<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FolderVideoControllerTest extends TestCase
{
    private string $tempRoot;

    private string $fakeFfprobe;

    private string $fakeApk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = storage_path('framework/testing/folder-video-'.uniqid());
        $this->fakeFfprobe = $this->tempRoot.DIRECTORY_SEPARATOR.'fake-ffprobe.bat';
        $this->fakeApk = $this->tempRoot.DIRECTORY_SEPARATOR.'folder-video-app.apk';

        File::ensureDirectoryExists($this->tempRoot);
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'short.mp4', 'short-video');
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'long.mp4', 'long-video');
        file_put_contents($this->fakeApk, 'fake-apk');
        File::ensureDirectoryExists($this->tempRoot.DIRECTORY_SEPARATOR.'good');

        file_put_contents(
            $this->fakeFfprobe,
            "@echo off\r\n".
            "set file=%~nx7\r\n".
            "if /I \"%file%\"==\"short.mp4\" echo 5.0\r\n".
            "if /I \"%file%\"==\"mid.mp4\" echo 12.0\r\n".
            "if /I \"%file%\"==\"long.mp4\" echo 20.0\r\n"
        );

        config()->set('folder_video.root', $this->tempRoot);
        config()->set('folder_video.ffmpeg_bin', '');
        config()->set('folder_video.ffprobe_bin', $this->fakeFfprobe);
        config()->set('folder_video.preview_cache_path', $this->tempRoot.DIRECTORY_SEPARATOR.'previews');
        config()->set('folder_video.thumbnail_cache_path', $this->tempRoot.DIRECTORY_SEPARATOR.'thumbnails');
        config()->set('folder_video.stream_base_path', '');
        config()->set('folder_video.preview_fallback_to_source', true);
        config()->set('folder_video.index_filename', 'folder-video-index.json');
        config()->set('folder_video.index_path', null);
        config()->set('folder_video.probe_on_request', true);
        config()->set('folder_video.app_version', 'test-version');
        config()->set('folder_video.app_preview_max_connections', 4);
        config()->set('folder_video.app_page_limit', 18);
        config()->set('folder_video.android_apk_version_code', 9);
        config()->set('folder_video.android_apk_version_name', 'test-apk-version');
        config()->set('folder_video.android_apk_path', $this->fakeApk);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);
        parent::tearDown();
    }

    public function test_it_lists_videos_sorted_by_duration(): void
    {
        $response = $this->getJson('/api/folder-videos');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.filename', 'short.mp4')
            ->assertJsonPath('data.1.filename', 'long.mp4');
    }

    public function test_it_respects_the_limit_query_parameter(): void
    {
        $response = $this->getJson('/api/folder-videos?limit=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.filename', 'short.mp4')
            ->assertJsonPath('meta.next_after_filename', 'short.mp4');
    }

    public function test_it_can_fetch_the_next_batch_using_cursor_fields(): void
    {
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'mid.mp4', 'mid-video');

        $response = $this->getJson('/api/folder-videos?limit=2');

        $response->assertOk()
            ->assertJsonPath('data.0.filename', 'short.mp4')
            ->assertJsonPath('data.1.filename', 'mid.mp4')
            ->assertJsonPath('meta.next_after_filename', 'mid.mp4');

        $nextResponse = $this->getJson('/api/folder-videos?limit=2&after_duration=12&after_filename=mid.mp4');

        $nextResponse->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.filename', 'long.mp4');
    }

    public function test_it_moves_video_to_good_folder(): void
    {
        $id = rtrim(strtr(base64_encode('short.mp4'), '+/', '-_'), '=');

        $response = $this->postJson("/api/folder-videos/{$id}/like");

        $response->assertOk()
            ->assertJsonPath('data.filename', 'short.mp4')
            ->assertJsonPath('data.liked', true)
            ->assertJsonPath('data.stream_url', "/api/folder-videos/{$id}/stream")
            ->assertJsonPath('data.preview_url', "/api/folder-videos/{$id}/preview");

        $this->assertFileDoesNotExist($this->tempRoot.DIRECTORY_SEPARATOR.'short.mp4');
        $this->assertFileExists($this->tempRoot.DIRECTORY_SEPARATOR.'good'.DIRECTORY_SEPARATOR.'short.mp4');
    }

    public function test_it_lists_and_streams_liked_videos_from_good_folder(): void
    {
        $id = rtrim(strtr(base64_encode('short.mp4'), '+/', '-_'), '=');
        $this->postJson("/api/folder-videos/{$id}/like")->assertOk();

        $this->getJson('/api/folder-videos?liked=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.filename', 'short.mp4')
            ->assertJsonPath('data.0.liked', true)
            ->assertJsonPath('data.0.stream_url', "/api/folder-videos/{$id}/stream")
            ->assertJsonPath('data.0.preview_url', "/api/folder-videos/{$id}/preview");

        $this->get("/api/folder-videos/{$id}/stream")
            ->assertOk();

        $this->assertFileDoesNotExist($this->tempRoot.DIRECTORY_SEPARATOR.'short.mp4');
        $this->assertFileExists($this->tempRoot.DIRECTORY_SEPARATOR.'good'.DIRECTORY_SEPARATOR.'short.mp4');
    }

    public function test_it_can_cancel_liked_video(): void
    {
        $id = rtrim(strtr(base64_encode('short.mp4'), '+/', '-_'), '=');
        $this->postJson("/api/folder-videos/{$id}/like")->assertOk();

        $response = $this->deleteJson("/api/folder-videos/{$id}/like");

        $response->assertOk()
            ->assertJsonPath('data.filename', 'short.mp4')
            ->assertJsonPath('data.liked', false)
            ->assertJsonPath('data.stream_url', "/api/folder-videos/{$id}/stream")
            ->assertJsonPath('data.preview_url', "/api/folder-videos/{$id}/preview");

        $this->assertFileExists($this->tempRoot.DIRECTORY_SEPARATOR.'short.mp4');
        $this->assertFileDoesNotExist($this->tempRoot.DIRECTORY_SEPARATOR.'good'.DIRECTORY_SEPARATOR.'short.mp4');

        $this->getJson('/api/folder-videos?liked=1')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_deletes_video(): void
    {
        $id = rtrim(strtr(base64_encode('long.mp4'), '+/', '-_'), '=');

        $response = $this->deleteJson("/api/folder-videos/{$id}");

        $response->assertOk()
            ->assertJsonPath('data.filename', 'long.mp4');

        $this->assertFileDoesNotExist($this->tempRoot.DIRECTORY_SEPARATOR.'long.mp4');
    }

    public function test_scan_command_writes_index_file_into_video_folder(): void
    {
        $this->artisan('folder-video:warm-cache', ['--force' => true])
            ->assertExitCode(0);

        $indexPath = $this->tempRoot.DIRECTORY_SEPARATOR.'folder-video-index.json';

        $this->assertFileExists($indexPath);
        $payload = json_decode((string) file_get_contents($indexPath), true);

        $this->assertIsArray($payload);
        $this->assertSame('short.mp4', $payload['videos'][0]['filename']);
        $this->assertEquals(5.0, $payload['videos'][0]['duration_seconds']);
    }

    public function test_unknown_durations_are_sorted_after_known_durations(): void
    {
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'unknown.mp4', 'unknown-video');

        $response = $this->getJson('/api/folder-videos');

        $response->assertOk()
            ->assertJsonPath('data.0.filename', 'short.mp4')
            ->assertJsonPath('data.1.filename', 'long.mp4')
            ->assertJsonPath('data.2.filename', 'unknown.mp4');
    }

    public function test_it_can_list_by_offset_without_probing_durations(): void
    {
        config()->set('folder_video.probe_on_request', false);

        $response = $this->getJson('/api/folder-videos?limit=1&offset=1');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.duration_seconds', 0)
            ->assertJsonPath('meta.next_offset', 2)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_folder_video_routes_are_not_rate_limited(): void
    {
        for ($attempt = 0; $attempt < 80; $attempt++) {
            $this->getJson('/api/folder-videos?limit=1')
                ->assertOk()
                ->assertJsonPath('data.0.filename', 'short.mp4');
        }
    }

    public function test_app_config_endpoint_exposes_runtime_settings(): void
    {
        $response = $this->getJson('/api/folder-videos/app-config');

        $response->assertOk()
            ->assertJsonPath('data.version', 'test-version')
            ->assertJsonPath('data.preview_max_connections', 4)
            ->assertJsonPath('data.page_limit', 18)
            ->assertJsonPath('data.root', $this->tempRoot);
    }

    public function test_video_payload_uses_relative_stream_urls_for_lan_clients(): void
    {
        $response = $this->getJson('/api/folder-videos?limit=1');

        $response->assertOk()
            ->assertJsonPath('data.0.stream_url', '/api/folder-videos/c2hvcnQubXA0/stream')
            ->assertJsonPath('data.0.preview_url', '/api/folder-videos/c2hvcnQubXA0/preview')
            ->assertJsonPath('data.0.thumbnail_url', '/api/folder-videos/c2hvcnQubXA0/thumbnail')
            ->assertJsonPath('data.0.preview_cached', false)
            ->assertJsonPath('data.0.thumbnail_cached', false);
    }

    public function test_video_payload_can_use_direct_static_stream_urls(): void
    {
        config()->set('folder_video.stream_base_path', '/folder-video-media');

        $response = $this->getJson('/api/folder-videos?limit=1');

        $response->assertOk()
            ->assertJsonPath('data.0.stream_url', '/folder-video-media/short.mp4')
            ->assertJsonPath('data.0.preview_url', '/api/folder-videos/c2hvcnQubXA0/preview')
            ->assertJsonPath('data.0.thumbnail_url', '/api/folder-videos/c2hvcnQubXA0/thumbnail')
            ->assertJsonPath('data.0.preview_cached', false)
            ->assertJsonPath('data.0.thumbnail_cached', false);

        $id = rtrim(strtr(base64_encode('short.mp4'), '+/', '-_'), '=');

        $this->postJson("/api/folder-videos/{$id}/like")
            ->assertOk()
            ->assertJsonPath('data.stream_url', '/folder-video-media/good/short.mp4');
    }

    public function test_preview_endpoint_falls_back_to_source_when_cache_is_unavailable(): void
    {
        $id = rtrim(strtr(base64_encode('short.mp4'), '+/', '-_'), '=');

        $response = $this->get("/api/folder-videos/{$id}/preview");

        $response->assertOk()
            ->assertHeader('accept-ranges', 'bytes');

        $this->assertSame(
            realpath($this->tempRoot.DIRECTORY_SEPARATOR.'short.mp4'),
            realpath($response->baseResponse->getFile()->getPathname())
        );
    }

    public function test_preview_endpoint_can_disable_source_fallback(): void
    {
        config()->set('folder_video.preview_fallback_to_source', false);

        $id = rtrim(strtr(base64_encode('short.mp4'), '+/', '-_'), '=');

        $this->get("/api/folder-videos/{$id}/preview")
            ->assertNotFound()
            ->assertJsonPath('message', 'Preview is not available.');
    }

    public function test_thumbnail_endpoint_returns_not_found_without_ffmpeg(): void
    {
        $id = rtrim(strtr(base64_encode('short.mp4'), '+/', '-_'), '=');

        $this->get("/api/folder-videos/{$id}/thumbnail")
            ->assertNotFound()
            ->assertJsonPath('message', 'Thumbnail is not available.');
    }

    public function test_random_order_is_seeded_and_pageable(): void
    {
        config()->set('folder_video.probe_on_request', false);
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'mid.mp4', 'mid-video');

        $expected = collect(['short.mp4', 'long.mp4', 'mid.mp4'])
            ->sort(fn (string $left, string $right) => [
                hash('sha256', 'seed-1|'.$left),
                $left,
            ] <=> [
                hash('sha256', 'seed-1|'.$right),
                $right,
            ])
            ->values()
            ->all();

        $response = $this->getJson('/api/folder-videos?limit=3&offset=0&order=random_new_first&seed=seed-1');
        $responseAgain = $this->getJson('/api/folder-videos?limit=3&offset=0&order=random_new_first&seed=seed-1');
        $secondPage = $this->getJson('/api/folder-videos?limit=1&offset=1&order=random_new_first&seed=seed-1');

        $response->assertOk()
            ->assertJsonPath('data.0.filename', $expected[0])
            ->assertJsonPath('data.1.filename', $expected[1])
            ->assertJsonPath('data.2.filename', $expected[2])
            ->assertJsonPath('data.0.stream_url', '/api/folder-videos/'.rtrim(strtr(base64_encode($expected[0]), '+/', '-_'), '=').'/stream')
            ->assertJsonPath('data.0.preview_url', '/api/folder-videos/'.rtrim(strtr(base64_encode($expected[0]), '+/', '-_'), '=').'/preview');

        $this->assertSame($response->json('data'), $responseAgain->json('data'));

        $secondPage->assertOk()
            ->assertJsonPath('data.0.filename', $expected[1])
            ->assertJsonPath('meta.next_offset', 2);
    }

    public function test_random_listing_writes_lightweight_index_without_probing(): void
    {
        config()->set('folder_video.probe_on_request', false);
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'mid.mp4', 'mid-video');

        $response = $this->getJson('/api/folder-videos?limit=2&offset=0&order=random_new_first&seed=seed-2');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.duration_seconds', 0);

        $indexPath = $this->tempRoot.DIRECTORY_SEPARATOR.'folder-video-index.json';
        $payload = json_decode((string) file_get_contents($indexPath), true);

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('generated_at_unix', $payload);
        $this->assertArrayHasKey('directory_mtime', $payload);
        $this->assertCount(3, $payload['videos']);
    }

    public function test_folder_video_app_shell_loads(): void
    {
        $response = $this->get('/folder-video-app');

        $response->assertOk()
            ->assertSee('Folder Video')
            ->assertSee('watchedOnlyButton')
            ->assertSee('likedOnlyButton')
            ->assertSee('/api/folder-videos', false)
            ->assertSee('window.folderVideoTvHandleKey', false)
            ->assertSee("seekPlayer(-5, '-5s')", false)
            ->assertSee('window.FolderVideoTvAndroid.setPlayerOpen', false)
            ->assertSee('folder-video-app/sw.js', false);
    }

    public function test_folder_video_manifest_and_service_worker_load(): void
    {
        $this->get('/folder-video-app/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Folder Video')
            ->assertJsonPath('start_url', '/folder-video-app')
            ->assertJsonPath('icons.0.src', '/folder-video-app/icon-192.png');

        $this->get('/folder-video-app/sw.js')
            ->assertOk()
            ->assertSee('folder-video-app-test-version', false);
    }

    public function test_android_update_endpoint_uses_forwarded_lan_host(): void
    {
        $response = $this->withHeaders([
            'X-Forwarded-Host' => '10.0.0.19',
            'X-Forwarded-Port' => '8090',
            'X-Forwarded-Proto' => 'http',
        ])->getJson('/folder-video-app/android-version.json');

        $response->assertOk()
            ->assertJsonPath('data.version_code', 9)
            ->assertJsonPath('data.version_name', 'test-apk-version')
            ->assertJsonPath('data.apk_url', 'http://10.0.0.19:8090/folder-video-app/folder-video-app.apk')
            ->assertJsonPath('data.sha256', hash_file('sha256', $this->fakeApk))
            ->assertJsonPath('data.size_bytes', filesize($this->fakeApk));

        $this->get('/folder-video-app/folder-video-app.apk')
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.android.package-archive');
    }
}
