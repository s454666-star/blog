<?php

namespace Tests\Feature;

use App\Models\VideoJournalEntry;
use App\Services\VideoJournalContent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VideoJournalTest extends TestCase
{
    private string $video;
    private string $srt;
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.video_journal.database' => ':memory:']);
        DB::purge('video_journal');
        Schema::connection('video_journal')->create('video_journal_entries', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->text('source'); $table->longText('body')->default(''); $table->timestamps();
        });
        $this->artisan('video-journal:install')->assertSuccessful();
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'journal-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->video = $this->directory.DIRECTORY_SEPARATOR.'原始%20影片.mp4';
        $this->srt = substr($this->video, 0, -4).'.srt';
        file_put_contents($this->video, 'synthetic-video-content');
        file_put_contents($this->srt, "\xEF\xBB\xBF1\r\n00:00:00,000 --> 00:00:01,500\r\n測試字幕\r\n");
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    }

    protected function tearDown(): void
    {
        foreach ([$this->video, $this->srt] as $file) {
            if (is_file($file)) unlink($file);
        }
        rmdir($this->directory);
        DB::purge('video_journal');
        parent::tearDown();
    }

    public function test_local_crud_search_and_embedded_image_persistence_preserve_source(): void
    {
        $this->get('https://blog/')->assertRedirect('https://blog/video-journal');
        $this->post('https://blog/video-journal', ['title' => '測試旅行', 'source' => $this->video])->assertRedirect();
        $entry = VideoJournalEntry::sole();
        $image = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB1sAAAAASUVORK5CYII=';
        $body = '<h2>旅行手記</h2><p>文字與<strong>圖片</strong></p><img src="'.$image.'">';
        $this->putJson('https://blog/video-journal/'.$entry->id, ['title' => '收藏回憶', 'body' => $body])->assertOk()->assertJsonPath('message', '已儲存所有變更');
        $this->assertStringContainsString($image, $entry->fresh()->body);
        $this->get('https://blog/video-journal/'.$entry->id)->assertOk()->assertSee('收藏回憶')->assertSee($image, false);
        $this->get('https://blog/video-journal?q=收藏')->assertOk()->assertSee('收藏回憶')->assertDontSee($image, false);
        $this->get('https://blog/video-journal?q=不存在')->assertOk()->assertDontSee('收藏回憶');
        $this->delete('https://blog/video-journal/'.$entry->id)->assertRedirect('https://blog/video-journal');
        $this->assertSame(0, VideoJournalEntry::count());
        $this->assertFileExists($this->video);
        $this->assertFileExists($this->srt);
        $this->get('https://blog/video-journal/'.$entry->id)->assertNotFound();
    }

    public function test_picker_lists_only_folders_and_videos_and_preserves_original_filename(): void
    {
        $folder = $this->directory.DIRECTORY_SEPARATOR.'nested';
        mkdir($folder);
        try {
            $url = 'https://blog/video-journal/browse?'.http_build_query(['path' => $this->directory]);
            $this->getJson($url)->assertOk()->assertJsonCount(2, 'items')
                ->assertJsonPath('items.0.name', 'nested')->assertJsonPath('items.0.directory', true)
                ->assertJsonPath('items.1.name', '原始%20影片.mp4')->assertJsonPath('items.1.path', $this->video)
                ->assertJsonPath('items.1.directory', false)->assertJsonPath('truncated', false);
            $this->getJson($url.'&q='.urlencode('影片'))->assertOk()->assertJsonCount(1, 'items');
            $this->getJson('https://blog/video-journal/browse?path='.urlencode($this->video))->assertUnprocessable();
            $this->getJson('https://mystar.monster/video-journal/browse')->assertForbidden();
            $this->post('https://blog/video-journal', ['source' => $this->video])->assertRedirect();
            $entry = VideoJournalEntry::sole();
            $this->assertSame('原始%20影片.mp4', $entry->title);
            $this->assertSame($this->video, $entry->source);
        } finally {
            rmdir($folder);
        }
    }

    public function test_media_supports_ranges_and_same_name_srt_is_webvtt(): void
    {
        $entry = VideoJournalEntry::create(['title' => 'Synthetic', 'source' => $this->video]);
        $this->get('https://blog/video-journal/'.$entry->id.'/media', ['Range' => 'bytes=0-8'])
            ->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-8/23')->assertHeader('Content-Type', 'video/mp4');
        $this->get('https://blog/video-journal/'.$entry->id.'/subtitles')->assertOk()
            ->assertHeader('Content-Type', 'text/vtt; charset=UTF-8')
            ->assertSee("WEBVTT\n\n1\n00:00:00.000 --> 00:00:01.500\n測試字幕", false);
    }

    public function test_rejects_unsafe_source_and_remote_access(): void
    {
        foreach (['php://filter/resource=.env', base_path('.env'), 'javascript:alert(1)', 'ftp://example.com/movie.mp4', 'D:\\missing-journal-test.mp4'] as $source) {
            $this->postJson('https://blog/video-journal', ['title' => 'Test', 'source' => $source])->assertUnprocessable()->assertJsonValidationErrors('source');
        }
        $this->get('https://mystar.monster/video-journal')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->get('https://blog/video-journal')->assertForbidden();
        $this->assertSame(0, VideoJournalEntry::count());
    }

    public function test_sanitizer_removes_executable_html_and_external_images(): void
    {
        $safe = (new VideoJournalContent)->sanitize('<p onclick="evil()">中文<b>文字</b></p><script>alert(1)</script><svg onload="evil()"></svg><a href="javascript:evil()">連結</a><img src="https://example.com/track.png"><img src="data:image/png;base64,aGVsbG8="><iframe srcdoc="evil"></iframe>');
        $this->assertSame('<p>中文<b>文字</b></p>連結', $safe);
    }

    public function test_image_replacement_and_deletion_are_saved_as_article_content(): void
    {
        $entry = VideoJournalEntry::create(['title' => 'Images', 'source' => $this->video, 'body' => '<p>Original</p>']);
        $image = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $this->putJson('https://blog/video-journal/'.$entry->id, ['title' => 'Images', 'body' => '<p>Replaced</p><img src="'.$image.'" class="selected-image" onerror="evil()">'])->assertOk();
        $this->assertStringContainsString($image, $entry->fresh()->body);
        $this->assertStringNotContainsString('onerror', $entry->fresh()->body);
        $this->putJson('https://blog/video-journal/'.$entry->id, ['title' => 'Images', 'body' => '<p>Kept text</p>'])->assertOk();
        $this->assertSame('<p>Kept text</p>', $entry->fresh()->body);
    }

    public function test_remote_source_is_not_fetched_by_server_and_missing_sources_fail_cleanly(): void
    {
        $this->post('https://blog/video-journal', ['title' => 'Remote', 'source' => 'https://example.com/test.mp4'])->assertRedirect();
        $entry = VideoJournalEntry::sole();
        $this->get('https://blog/video-journal/'.$entry->id.'/media')->assertNotFound();
        $this->get('https://blog/video-journal/'.$entry->id.'/subtitles')->assertNotFound();
        $entry->update(['source' => $this->video]);
        unlink($this->video); unlink($this->srt);
        $this->get('https://blog/video-journal/'.$entry->id.'/media')->assertNotFound();
        $this->get('https://blog/video-journal/'.$entry->id.'/subtitles')->assertNotFound();
    }

    public function test_twenty_mb_image_survives_html_sanitizing_and_database_save(): void
    {
        $entry = VideoJournalEntry::create(['title' => 'Large image', 'source' => $this->video]);
        // A valid GIF with comment blocks sized to exactly the supported limit.
        $gif = substr(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 0, -1)."\x21\xFE";
        $remaining = VideoJournalContent::IMAGE_BYTES - strlen($gif) - 2;
        $gif .= str_repeat("\xFF".str_repeat('a', 255), intdiv($remaining, 256));
        $tail = $remaining % 256;
        if ($tail > 1) $gif .= chr($tail - 1).str_repeat('a', $tail - 1);
        $gif .= "\x00\x3B";
        $this->assertSame(VideoJournalContent::IMAGE_BYTES, strlen($gif));
        $src = 'data:image/gif;base64,'.base64_encode($gif);
        $expected = hash('sha256', $src);
        $this->putJson('https://blog/video-journal/'.$entry->id, ['title' => 'Large image', 'body' => '<p>Preserved</p><img src="'.$src.'">'])->assertOk();
        $stored = $entry->fresh()->body;
        $this->assertStringStartsWith('<p>Preserved</p><img src="', $stored);
        $start = strpos($stored, 'src="') + 5;
        $this->assertSame($expected, hash('sha256', substr($stored, $start, strpos($stored, '"', $start) - $start)));
        unset($stored, $src);
        $this->putJson('https://blog/video-journal/'.$entry->id, ['title' => 'Rejected', 'body' => '<img src="data:image/gif;base64,'.base64_encode($gif.'x').'">'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->assertSame('Large image', $entry->fresh()->title);
    }

    public function test_listing_uses_first_article_image_and_falls_back_when_removed(): void
    {
        $first = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $second = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jB1sAAAAASUVORK5CYII=';
        $entry = VideoJournalEntry::create(['title' => 'Cover test', 'source' => $this->video, 'body' => (new VideoJournalContent)->sanitize('<p>Text before images</p><img src="'.$first.'"><img src="'.$second.'">')]);
        $this->get('https://blog/video-journal')->assertOk()->assertSee('Cover test')->assertSee('/video-journal/'.$entry->id.'/cover', false)->assertDontSee($first, false);
        $response = $this->get('https://blog/video-journal/'.$entry->id.'/cover')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->assertSame(base64_decode(substr($first, strpos($first, ',') + 1)), $response->getContent());
        $entry->update(['body' => '<p>No image</p>']);
        $this->get('https://blog/video-journal')->assertOk()->assertSee('Cover test')->assertDontSee('/video-journal/'.$entry->id.'/cover', false);
        $this->get('https://blog/video-journal/'.$entry->id.'/cover')->assertNotFound();
        $this->get('https://mystar.monster/video-journal/'.$entry->id.'/cover')->assertForbidden();
    }

    public function test_save_resizes_images_to_full_hd_and_cover_uses_compressed_image(): void
    {
        $entry = VideoJournalEntry::create(['title' => 'Resize test', 'source' => $this->video]);
        foreach ([[3840, 2160, 1920, 1080], [2000, 3000, 720, 1080]] as [$width, $height, $expectedWidth, $expectedHeight]) {
            $image = imagecreatetruecolor($width, $height);
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
            imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), 400, 400, imagecolorallocatealpha($image, 220, 100, 70, 0));
            ob_start(); imagepng($image); $png = ob_get_clean(); unset($image);
            $body = '<p>Keep text</p><img src="data:image/png;base64,'.base64_encode($png).'">';
            $this->putJson('https://blog/video-journal/'.$entry->id, ['title' => 'Resize test', 'body' => $body])->assertOk();
            $stored = $entry->fresh()->body;
            $this->assertStringContainsString('<p>Keep text</p><img src="data:image/webp;base64,', $stored);
            $cover = $this->get('https://blog/video-journal/'.$entry->id.'/cover')->assertOk()->assertHeader('Content-Type', 'image/webp')->getContent();
            $size = getimagesizefromstring($cover);
            $this->assertSame([$expectedWidth, $expectedHeight], [$size[0], $size[1]]);
            $decoded = imagecreatefromstring($cover);
            $this->assertSame(127, imagecolorsforindex($decoded, imagecolorat($decoded, 0, 0))['alpha']);
            unset($decoded);
            $this->assertSame($stored, (new VideoJournalContent)->sanitize($stored), 'Already resized images must not be re-encoded.');
        }
    }

    public function test_tags_portraits_limits_search_and_future_identity_fields(): void
    {
        $entry = VideoJournalEntry::create(['title' => 'Portrait fixture', 'source' => $this->video]);
        $image = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $tags = ['旅行', '日常', '收藏', '城市', '記錄'];
        $url = 'https://blog/video-journal/'.$entry->id;
        $payload = ['title' => $entry->title, 'body' => '', 'tags' => $tags, 'portraits' => array_fill(0, 5, $image)];
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('tags', $tags)->assertJsonCount(5, 'portraits');
        $faces = $entry->faces()->get();
        $this->assertCount(5, $faces);
        $this->assertSame('pending', $faces[0]->feature_status);
        $this->assertNull($faces[0]->person_id);
        $this->assertNull($faces[0]->embedding);
        $this->assertSame(64, strlen($faces[0]->image_sha256));
        $this->get($url.'/portraits/0')->assertOk()->assertHeader('Content-Type', 'image/gif');
        $this->get($url.'/portraits/5')->assertNotFound();
        $list = $this->get('https://blog/video-journal?q='.urlencode('城市'))->assertOk()->assertSee($entry->title)->assertSee($url.'/portraits/0', false);
        foreach ($tags as $tag) $list->assertSee('# '.$tag);
        $this->get($url)->assertOk()->assertSee('人臉特寫')->assertSee('journal-metadata', false);
        $this->putJson($url, array_replace($payload, ['tags' => [...$tags, '第六個']]))->assertUnprocessable();
        $this->putJson($url, array_replace($payload, ['portraits' => array_fill(0, 6, $image)]))->assertUnprocessable();
        $this->putJson($url, array_replace($payload, ['portraits' => ['data:image/png;base64,aGVsbG8=']]))->assertUnprocessable();
        $this->assertSame(5, $entry->faces()->count());
        $this->artisan('video-journal:install')->assertSuccessful();
        $this->assertSame(5, $entry->faces()->count());
        $this->assertTrue(Schema::connection('video_journal')->hasColumns('video_journal_faces', ['embedding', 'embedding_model', 'embedding_version', 'embedding_dimensions', 'face_box', 'landmarks', 'person_id', 'preprocessing_version', 'quality_score', 'processed_at']));
        $this->assertTrue(Schema::connection('video_journal')->hasTable('video_journal_people'));
        $this->assertTrue(Schema::connection('video_journal')->hasTable('video_journal_person_entries'));
        $this->assertSame(0, DB::connection('video_journal')->table('video_journal_people')->count());
        $this->putJson($url, ['title' => $entry->title, 'body' => ''])->assertOk();
        $this->assertSame($tags, $entry->fresh()->tags); // Older clients cannot erase new metadata.
        $this->assertSame(5, $entry->faces()->count());
        $this->putJson($url, ['title' => $entry->title, 'body' => '', 'tags' => [], 'portraits' => []])->assertOk();
        $this->assertSame([], $entry->fresh()->tags);
        $this->assertSame(0, $entry->faces()->count());
    }

    public function test_portrait_resize_stable_ids_reordering_and_replacement(): void
    {
        $entry = VideoJournalEntry::create(['title' => 'Portrait resize', 'source' => $this->video]);
        $canvas = imagecreatetruecolor(2400, 3200);
        ob_start(); imagepng($canvas); $large = 'data:image/png;base64,'.base64_encode(ob_get_clean()); unset($canvas);
        $small = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
        $url = 'https://blog/video-journal/'.$entry->id;
        $this->putJson($url, ['title' => $entry->title, 'portraits' => [$large, $small]])->assertOk();
        $faces = $entry->faces()->get();
        $firstId = $faces[0]->id;
        $secondId = $faces[1]->id;
        $image = $this->get($url.'/portraits/0')->assertOk()->assertHeader('Content-Type', 'image/webp')->getContent();
        $size = getimagesizefromstring($image);
        $this->assertSame([810, 1080], [$size[0], $size[1]]);
        // Synthetic future features must remain attached to the same image, not its position.
        DB::connection('video_journal')->table('video_journal_faces')->where('id', $firstId)->update(['embedding_model' => 'test-model', 'feature_status' => 'ready']);
        $this->putJson($url, ['title' => $entry->title, 'portraits' => [$small, $faces[0]->image]])->assertOk();
        $reordered = $entry->faces()->get();
        $this->assertSame([$secondId, $firstId], $reordered->pluck('id')->all());
        $this->assertSame('test-model', $reordered[1]->embedding_model);
        $this->putJson($url, ['title' => $entry->title, 'portraits' => [$small, $small]])->assertOk();
        $replacement = $entry->faces()->get()[1];
        $this->assertNotSame($firstId, $replacement->id);
        $this->assertNull($replacement->embedding_model);
        $this->assertSame('pending', $replacement->feature_status);
        $this->delete($url)->assertRedirect();
        $this->assertSame(0, DB::connection('video_journal')->table('video_journal_faces')->where('entry_id', $entry->id)->count());
    }

    public function test_dropped_video_resolution_and_atomic_batch_creation(): void
    {
        $second = $this->directory.DIRECTORY_SEPARATOR.'第二部.mp4';
        file_put_contents($second, 'second-synthetic-video');
        $firstFingerprint = hash('sha256', str_repeat(file_get_contents($this->video), 3));
        $secondFingerprint = hash('sha256', str_repeat(file_get_contents($second), 3));
        try {
            $resolve = ['name' => basename($this->video), 'size' => filesize($this->video), 'fingerprint' => $firstFingerprint];
            $this->postJson('https://blog/video-journal/resolve-drop', $resolve)->assertOk()->assertJsonCount(0, 'matches');
            $this->getJson('https://blog/video-journal/browse?path='.urlencode($this->directory))->assertOk();
            $this->postJson('https://blog/video-journal/resolve-drop', $resolve)->assertOk()->assertJsonPath('matches.0.path', $this->video);
            $this->postJson('https://blog/video-journal/resolve-drop', array_replace($resolve, ['fingerprint' => str_repeat('0', 64)]))->assertOk()->assertJsonCount(0, 'matches');
            $this->postJson('https://mystar.monster/video-journal/resolve-drop', $resolve)->assertForbidden();
            $items = [['source' => $this->video, 'size' => filesize($this->video), 'fingerprint' => $firstFingerprint], ['source' => $second, 'size' => filesize($second), 'fingerprint' => $secondFingerprint]];
            $invalid = $items; $invalid[1]['fingerprint'] = str_repeat('0', 64);
            $this->postJson('https://blog/video-journal/batch', ['items' => $invalid])->assertUnprocessable();
            $this->assertSame(0, VideoJournalEntry::count());
            $this->postJson('https://blog/video-journal/batch', ['items' => [$items[0], $items[0]]])->assertUnprocessable();
            $this->postJson('https://blog/video-journal/batch', ['items' => array_fill(0, 51, $items[0])])->assertUnprocessable();
            $this->postJson('https://blog/video-journal/batch', ['items' => $items])->assertOk()->assertJsonPath('count', 2);
            $this->assertSame([basename($this->video), basename($second)], VideoJournalEntry::orderBy('id')->pluck('title')->all());
            $this->assertFileExists($this->video);
            $this->assertFileExists($second);
        } finally { unlink($second); }
    }
}
