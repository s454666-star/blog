<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class NasViewerControllerTest extends TestCase
{
    private string $tempRoot;

    private string $rootA;

    private string $rootB;

    private string $fakeApk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempRoot = storage_path('framework/testing/nas-viewer-'.uniqid());
        $this->rootA = $this->tempRoot.DIRECTORY_SEPARATOR.'share-a';
        $this->rootB = $this->tempRoot.DIRECTORY_SEPARATOR.'share-b';
        $this->fakeApk = $this->tempRoot.DIRECTORY_SEPARATOR.'nas-viewer-app.apk';

        File::ensureDirectoryExists($this->rootA.DIRECTORY_SEPARATOR.'Movies');
        File::ensureDirectoryExists($this->rootA.DIRECTORY_SEPARATOR.'Pictures');
        File::ensureDirectoryExists($this->rootA.DIRECTORY_SEPARATOR.'Docs');
        File::ensureDirectoryExists($this->rootA.DIRECTORY_SEPARATOR.'@eaDir');
        File::ensureDirectoryExists($this->rootB);
        file_put_contents($this->rootA.DIRECTORY_SEPARATOR.'Movies'.DIRECTORY_SEPARATOR.'clip.mp4', 'fake-video');
        file_put_contents($this->rootA.DIRECTORY_SEPARATOR.'Pictures'.DIRECTORY_SEPARATOR.'photo.jpg', $this->onePixelPng());
        file_put_contents($this->rootA.DIRECTORY_SEPARATOR.'Docs'.DIRECTORY_SEPARATOR.'readme.txt', 'NAS text viewer');
        file_put_contents($this->rootA.DIRECTORY_SEPARATOR.'installer.apk', 'fake-installer-apk');
        file_put_contents($this->rootA.DIRECTORY_SEPARATOR.'archive.zip', 'unsupported');
        file_put_contents($this->rootA.DIRECTORY_SEPARATOR.'.secret.txt', 'hidden');
        file_put_contents($this->fakeApk, 'fake-nas-viewer-apk');

        config()->set('nas_viewer.roots', [
            'a' => ['label' => 'Share A', 'path' => $this->rootA, 'stream_base_path' => ''],
            'b' => ['label' => 'Share B', 'path' => $this->rootB, 'stream_base_path' => ''],
        ]);
        config()->set('nas_viewer.video_extensions', ['mp4']);
        config()->set('nas_viewer.image_extensions', ['jpg', 'png']);
        config()->set('nas_viewer.apk_extensions', ['apk']);
        config()->set('nas_viewer.text_extensions', ['txt', 'md']);
        config()->set('nas_viewer.text_filenames', ['readme']);
        config()->set('nas_viewer.hidden_names', ['@eaDir']);
        config()->set('nas_viewer.hide_dot_files', true);
        config()->set('nas_viewer.page_limit', 300);
        config()->set('nas_viewer.max_page_limit', 1000);
        config()->set('nas_viewer.text_max_bytes', 5 * 1024 * 1024);
        config()->set('nas_viewer.app_version', 'test-nas-viewer');
        config()->set('nas_viewer.android_apk_version_code', 4);
        config()->set('nas_viewer.android_apk_version_name', 'test-nas-apk');
        config()->set('nas_viewer.android_apk_path', $this->fakeApk);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempRoot);
        parent::tearDown();
    }

    public function test_it_lists_configured_nas_shares(): void
    {
        $this->getJson('/api/nas-browser')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Share A')
            ->assertJsonPath('data.0.kind', 'directory')
            ->assertJsonPath('data.0.available', true)
            ->assertJsonPath('data.0.size_bytes', null)
            ->assertJsonPath('meta.title', 'NAS');
    }

    public function test_it_lists_directories_first_and_classifies_supported_files(): void
    {
        $rootId = $this->getJson('/api/nas-browser')->json('data.0.id');
        $response = $this->getJson('/api/nas-browser?directory='.urlencode($rootId));

        $response->assertOk()->assertJsonPath('meta.title', 'Share A');
        $entries = collect($response->json('data'));

        $this->assertSame(['Docs', 'Movies', 'Pictures'], $entries->where('kind', 'directory')->pluck('name')->all());
        $this->assertSame('apk', $entries->firstWhere('name', 'installer.apk')['kind']);
        $this->assertNotEmpty($entries->firstWhere('name', 'installer.apk')['download_url']);
        $this->assertSame('other', $entries->firstWhere('name', 'archive.zip')['kind']);
        $this->assertFalse($entries->contains('name', '@eaDir'));
        $this->assertFalse($entries->contains('name', '.secret.txt'));
    }

    public function test_it_paginates_directory_entries(): void
    {
        $rootId = $this->getJson('/api/nas-browser')->json('data.0.id');

        $this->getJson('/api/nas-browser?directory='.urlencode($rootId).'&limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonPath('meta.next_offset', 2);
    }

    public function test_it_streams_images_and_videos_through_the_fallback_route(): void
    {
        $rootId = $this->getJson('/api/nas-browser')->json('data.0.id');
        $rootEntries = collect($this->getJson('/api/nas-browser?directory='.urlencode($rootId))->json('data'));
        $pictures = $rootEntries->firstWhere('name', 'Pictures');
        $pictureEntries = $this->getJson('/api/nas-browser?directory='.urlencode($pictures['id']))->json('data');

        $this->get($pictureEntries[0]['media_url'])
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=600, private');

        $installer = $rootEntries->firstWhere('name', 'installer.apk');
        $this->get($installer['download_url'])
            ->assertOk()
            ->assertDownload('installer.apk')
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive');

        $archive = $rootEntries->firstWhere('name', 'archive.zip');
        $this->get('/api/nas-browser/stream?id='.urlencode($archive['id']))->assertNotFound();
    }

    public function test_it_reads_utf8_and_cp950_text(): void
    {
        $cp950Path = $this->rootA.DIRECTORY_SEPARATOR.'Docs'.DIRECTORY_SEPARATOR.'big5.txt';
        file_put_contents($cp950Path, mb_convert_encoding('繁體中文', 'CP950', 'UTF-8'));

        $rootId = $this->getJson('/api/nas-browser')->json('data.0.id');
        $docs = collect($this->getJson('/api/nas-browser?directory='.urlencode($rootId))->json('data'))
            ->firstWhere('name', 'Docs');
        $entries = collect($this->getJson('/api/nas-browser?directory='.urlencode($docs['id']))->json('data'));

        $this->getJson($entries->firstWhere('name', 'readme.txt')['text_url'])
            ->assertOk()
            ->assertJsonPath('data.content', 'NAS text viewer')
            ->assertJsonPath('data.encoding', 'UTF-8');

        $this->getJson($entries->firstWhere('name', 'big5.txt')['text_url'])
            ->assertOk()
            ->assertJsonPath('data.content', '繁體中文')
            ->assertJsonPath('data.encoding', 'BIG-5');
    }

    public function test_it_rejects_paths_outside_the_share(): void
    {
        $outside = $this->tempRoot.DIRECTORY_SEPARATOR.'outside.txt';
        file_put_contents($outside, 'outside');
        $forgedId = $this->encodeId('a', '../outside.txt');

        $this->getJson('/api/nas-browser/text?id='.urlencode($forgedId))->assertNotFound();
    }

    public function test_it_rejects_oversized_text_documents(): void
    {
        file_put_contents(
            $this->rootA.DIRECTORY_SEPARATOR.'Docs'.DIRECTORY_SEPARATOR.'oversized.txt',
            str_repeat('x', 2048)
        );
        config()->set('nas_viewer.text_max_bytes', 1024);
        $rootId = $this->getJson('/api/nas-browser')->json('data.0.id');
        $docs = collect($this->getJson('/api/nas-browser?directory='.urlencode($rootId))->json('data'))
            ->firstWhere('name', 'Docs');
        $text = collect($this->getJson('/api/nas-browser?directory='.urlencode($docs['id']))->json('data'))
            ->firstWhere('name', 'oversized.txt');

        $this->getJson($text['text_url'])->assertStatus(413);
    }

    public function test_it_exposes_app_versions_shell_and_apk(): void
    {
        $this->get('/nas-viewer-app')
            ->assertOk()
            ->assertSee('第一次選取，第二次開啟')
            ->assertSee('window.nasViewerHandleBack', false)
            ->assertSee('window.NasViewerAndroid.setMediaOrientationEnabled', false)
            ->assertSee('window.NasViewerAndroid.setVideoFullscreenEnabled', false)
            ->assertSee("setMediaAutoOrientation(['video', 'image'].includes(entry.kind))", false)
            ->assertSee("elements.videoRewind.addEventListener('click', () => seekVideo(-10, '-10 秒'))", false)
            ->assertSee('VIDEO_DRAG_SEEK_RATIO = .05', false)
            ->assertSee("const delta = dy < 0 ? 1 : -1", false)
            ->assertSee("showToast('上滑下一個・下滑上一個')", false)
            ->assertSee("state.entries.filter(entry => ['video', 'image', 'text'].includes(entry.kind))", false)
            ->assertSee("window.location.assign(entry.download_url)", false)
            ->assertSee('再點一下開啟安裝檔')
            ->assertSee('.viewer.video-mode .viewer-header', false)
            ->assertSee('window.nasViewerTvHandleKey', false)
            ->assertSee("if (state.viewerEntry.kind === 'video' && key === 'left')", false)
            ->assertSee("seekVideo(-5, '-5 秒')", false)
            ->assertSee('window.NasViewerTvAndroid.setViewerState', false)
            ->assertSee("elements.video.addEventListener('ended', () => closeViewer())", false);

        $this->getJson('/nas-viewer-app/version.json')
            ->assertOk()
            ->assertJsonPath('data.version', 'test-nas-viewer');

        $this->withHeaders([
            'X-Forwarded-Host' => '10.0.0.25',
            'X-Forwarded-Proto' => 'http',
            'X-Forwarded-Port' => '8090',
        ])->getJson('/nas-viewer-app/android-version.json')
            ->assertOk()
            ->assertJsonPath('data.version_code', 4)
            ->assertJsonPath('data.apk_url', 'http://10.0.0.25:8090/nas-viewer-app/nas-viewer-app.apk');

        $this->get('/nas-viewer-app/nas-viewer-app.apk')
            ->assertOk()
            ->assertDownload('nas-viewer-app.apk');
    }

    public function test_it_serves_the_nas_viewer_tv_update_channel(): void
    {
        config()->set('nas_viewer.tv_android_apk_version_code', 5);
        config()->set('nas_viewer.tv_android_apk_version_name', '2026.07.11.5-tv');
        config()->set('nas_viewer.tv_android_apk_path', storage_path('app/nas-viewer-tv.apk'));

        $this->withHeaders(['X-Forwarded-Host' => '10.0.0.25:8090', 'X-Forwarded-Proto' => 'http'])
            ->getJson('/nas-viewer-app/tv/android-version.json')
            ->assertOk()
            ->assertJsonPath('data.version_code', 5)
            ->assertJsonPath('data.version_name', '2026.07.11.5-tv')
            ->assertJsonPath('data.apk_url', 'http://10.0.0.25:8090/nas-viewer-app/tv/nas-viewer-tv.apk');

        $this->get('/nas-viewer-app/tv/nas-viewer-tv.apk')
            ->assertOk()
            ->assertDownload('nas-viewer-tv.apk');
    }

    private function encodeId(string $rootId, string $path): string
    {
        $json = json_encode(['r' => $rootId, 'p' => $path], JSON_UNESCAPED_SLASHES);

        return rtrim(strtr(base64_encode((string) $json), '+/', '-_'), '=');
    }

    private function onePixelPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2n0sAAAAASUVORK5CYII=');
    }
}
