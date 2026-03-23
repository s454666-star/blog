<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FolderVideoControllerTest extends TestCase
{
    private string $tempRoot;

    private string $fakeFfprobe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = storage_path('framework/testing/folder-video-'.uniqid());
        $this->fakeFfprobe = $this->tempRoot.DIRECTORY_SEPARATOR.'fake-ffprobe.bat';

        File::ensureDirectoryExists($this->tempRoot);
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'short.mp4', 'short-video');
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'long.mp4', 'long-video');
        File::ensureDirectoryExists($this->tempRoot.DIRECTORY_SEPARATOR.'good');

        file_put_contents(
            $this->fakeFfprobe,
            "@echo off\r\n".
            "set file=%~nx6\r\n".
            "if /I \"%file%\"==\"short.mp4\" echo {\"format\":{\"duration\":\"5.0\"}}\r\n".
            "if /I \"%file%\"==\"mid.mp4\" echo {\"format\":{\"duration\":\"12.0\"}}\r\n".
            "if /I \"%file%\"==\"long.mp4\" echo {\"format\":{\"duration\":\"20.0\"}}\r\n"
        );

        config()->set('folder_video.root', $this->tempRoot);
        config()->set('folder_video.ffprobe_bin', $this->fakeFfprobe);
        config()->set('folder_video.index_filename', 'folder-video-index.json');
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
            ->assertJsonPath('data.filename', 'short.mp4');

        $this->assertFileDoesNotExist($this->tempRoot.DIRECTORY_SEPARATOR.'short.mp4');
        $this->assertFileExists($this->tempRoot.DIRECTORY_SEPARATOR.'good'.DIRECTORY_SEPARATOR.'short.mp4');
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
}
