<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FolderPhotoControllerTest extends TestCase
{
    private string $tempRoot;

    private string $indexPath;

    private string $fakeApk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = storage_path('framework/testing/folder-photo-'.uniqid());
        $this->indexPath = $this->tempRoot.DIRECTORY_SEPARATOR.'folder-photo-index.json';
        $this->fakeApk = $this->tempRoot.DIRECTORY_SEPARATOR.'folder-photo-app.apk';
        $nestedDirectory = $this->tempRoot.DIRECTORY_SEPARATOR.'album'.DIRECTORY_SEPARATOR.'day 1';

        File::ensureDirectoryExists($nestedDirectory);
        file_put_contents($this->tempRoot.DIRECTORY_SEPARATOR.'root.jpg', $this->onePixelPng());
        file_put_contents($nestedDirectory.DIRECTORY_SEPARATOR.'nested photo.png', $this->onePixelPng());
        file_put_contents($nestedDirectory.DIRECTORY_SEPARATOR.'ignored.mp4', 'not-a-photo');
        file_put_contents($this->fakeApk, 'fake-photo-apk');

        config()->set('folder_photo.root', $this->tempRoot);
        config()->set('folder_photo.stream_base_path', '');
        config()->set('folder_photo.index_path', $this->indexPath);
        config()->set('folder_photo.index_refresh_seconds', 3600);
        config()->set('folder_photo.extensions', ['jpg', 'jpeg', 'png']);
        config()->set('folder_photo.random_pool_limit', 500);
        config()->set('folder_photo.initial_columns', 3);
        config()->set('folder_photo.initial_rows', 4);
        config()->set('folder_photo.max_columns', 6);
        config()->set('folder_photo.max_rows', 8);
        config()->set('folder_photo.display_min_seconds', 7);
        config()->set('folder_photo.display_max_seconds', 12);
        config()->set('folder_photo.app_version', 'test-photo-version');
        config()->set('folder_photo.android_apk_version_code', 7);
        config()->set('folder_photo.android_apk_version_name', 'test-photo-apk');
        config()->set('folder_photo.android_apk_path', $this->fakeApk);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);
        parent::tearDown();
    }

    public function test_it_recursively_returns_only_unique_supported_photos(): void
    {
        $response = $this->getJson('/api/folder-photos/random?count=50');

        $response->assertOk()->assertJsonCount(2, 'data');
        $photos = $response->json('data');

        $this->assertCount(2, array_unique(array_column($photos, 'id')));
        $this->assertTrue(collect($photos)->contains(
            fn (array $photo): bool => str_starts_with($photo['url'], '/api/folder-photos/')
        ));
        $this->assertFileExists($this->indexPath);
        $this->assertSame(2, json_decode((string) file_get_contents($this->indexPath), true)['count']);
    }

    public function test_it_serves_a_nested_photo_from_its_opaque_id(): void
    {
        $photos = $this->getJson('/api/folder-photos/random?count=2')
            ->assertOk()
            ->json('data');

        $response = $this->get($photos[0]['url']);

        $response->assertOk()->assertHeader('Cache-Control', 'max-age=86400, public');
    }

    public function test_it_rejects_a_path_outside_the_configured_root(): void
    {
        $outsidePath = dirname($this->tempRoot).DIRECTORY_SEPARATOR.'outside-photo.jpg';
        file_put_contents($outsidePath, $this->onePixelPng());
        $id = rtrim(strtr(base64_encode('../outside-photo.jpg'), '+/', '-_'), '=');

        try {
            $this->get('/api/folder-photos/'.$id)->assertNotFound();
        } finally {
            @unlink($outsidePath);
        }
    }

    public function test_it_exposes_grid_timing_and_app_versions(): void
    {
        $this->getJson('/api/folder-photos/app-config')
            ->assertOk()
            ->assertJsonPath('data.initial_columns', 3)
            ->assertJsonPath('data.initial_rows', 4)
            ->assertJsonPath('data.display_min_ms', 7000)
            ->assertJsonPath('data.display_max_ms', 12000);

        $this->getJson('/folder-photo-app/version.json')
            ->assertOk()
            ->assertJsonPath('data.version', 'test-photo-version');

        $this->withHeaders([
            'X-Forwarded-Host' => '10.0.0.25',
            'X-Forwarded-Proto' => 'http',
            'X-Forwarded-Port' => '8090',
        ])->getJson('/folder-photo-app/android-version.json')
            ->assertOk()
            ->assertJsonPath('data.version_code', 7)
            ->assertJsonPath('data.version_name', 'test-photo-apk')
            ->assertJsonPath('data.apk_url', 'http://10.0.0.25:8090/folder-photo-app/folder-photo-app.apk');
    }

    public function test_it_renders_the_photo_wall_and_downloads_the_apk(): void
    {
        $this->get('/folder-photo-app')
            ->assertOk()
            ->assertSee('id="photo-wall"', false)
            ->assertSee('object-fit: contain', false)
            ->assertSee('display_min_ms', false)
            ->assertSee('上滑 +1 列', false)
            ->assertSee('photo-enter-flip-x', false)
            ->assertSee('window.folderPhotoTvHandleKey', false)
            ->assertSee("if (key === 'left')", false)
            ->assertSee('setGrid(state.columns + 1, state.rows)', false)
            ->assertDontSee('touchDistance', false);

        $this->get('/folder-photo-app/folder-photo-app.apk')
            ->assertOk()
            ->assertDownload('folder-photo-app.apk');
    }

    private function onePixelPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n0sAAAAASUVORK5CYII=');
    }
}
