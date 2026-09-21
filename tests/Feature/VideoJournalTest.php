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

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.video_journal.database' => ':memory:']);
        DB::purge('video_journal');
        Schema::connection('video_journal')->create('video_journal_entries', function (Blueprint $table) {
            $table->id(); $table->string('title'); $table->text('source'); $table->longText('body')->default(''); $table->timestamps();
        });
        $this->video = sys_get_temp_dir().DIRECTORY_SEPARATOR.'journal-test-'.bin2hex(random_bytes(8)).'.mp4';
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
}
