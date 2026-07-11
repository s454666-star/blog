<?php

namespace Tests\Feature;

use Tests\TestCase;

class AndroidTvAppSourceTest extends TestCase
{
    public function test_all_tv_activities_intercept_dpad_before_webview(): void
    {
        $sources = [
            'android/folder-video-tv-apk/app/src/main/java/monster/mystar/foldervideotv/MainActivity.java',
            'android/folder-photo-tv-apk/app/src/main/java/monster/mystar/folderphototv/MainActivity.java',
            'android/nas-viewer-tv-apk/app/src/main/java/monster/mystar/nasviewertv/MainActivity.java',
        ];

        foreach ($sources as $source) {
            $contents = file_get_contents(base_path($source));
            $this->assertIsString($contents);
            $this->assertStringContainsString('public boolean dispatchKeyEvent(KeyEvent event)', $contents);
            $this->assertStringContainsString('WebSettings.LOAD_NO_CACHE', $contents);
            $this->assertStringContainsString('checkForApkUpdate(false)', $contents);
            $this->assertStringContainsString('/tv/android-version.json', $contents);
        }
    }

    public function test_video_tv_apps_use_native_video_and_five_second_remote_seeking(): void
    {
        foreach ([
            'android/folder-video-tv-apk/app/src/main/java/monster/mystar/foldervideotv/MainActivity.java',
            'android/nas-viewer-tv-apk/app/src/main/java/monster/mystar/nasviewertv/MainActivity.java',
        ] as $source) {
            $contents = file_get_contents(base_path($source));
            $this->assertStringContainsString('new VideoView(this)', $contents);
            $this->assertStringContainsString('seekNativeVideo("left".equals(key) ? -5000 : 5000)', $contents);
            $this->assertStringContainsString('event.getAction() == KeyEvent.ACTION_DOWN', $contents);
            $this->assertStringContainsString('progressBarStyleHorizontal', $contents);
            $this->assertStringContainsString('postDelayed(hideNativeSeekOverlay, 500L)', $contents);
        }
    }

    public function test_folder_video_previews_keep_a_thumbnail_until_a_video_frame_is_ready(): void
    {
        $contents = file_get_contents(base_path('resources/views/folder-video-app/index.blade.php'));

        $this->assertStringContainsString("const previewSrc = video.preview_cached ? video.preview_url : '';", $contents);
        $this->assertStringContainsString('class="preview-poster"', $contents);
        $this->assertStringContainsString("video.addEventListener('loadeddata', () => video.classList.add('has-frame'))", $contents);
    }

    public function test_nas_viewer_tv_stops_stale_native_video_and_skips_failed_entries(): void
    {
        $activity = file_get_contents(base_path(
            'android/nas-viewer-tv-apk/app/src/main/java/monster/mystar/nasviewertv/MainActivity.java'
        ));
        $view = file_get_contents(base_path('resources/views/nas-viewer-app/index.blade.php'));

        $this->assertStringContainsString('nativeVideoGeneration', $activity);
        $this->assertStringContainsString('playVideo(String mediaUrl, String entryId)', $activity);
        $this->assertStringContainsString('stopNativeVideo(true)', $activity);
        $this->assertStringContainsString('const failedViewerEntryIds = new Set()', $view);
        $this->assertStringContainsString('stopNativeTvVideo();', $view);
        $this->assertStringContainsString('window.NasViewerTvAndroid.playVideo(entry.media_url, entry.id)', $view);
        $this->assertStringContainsString('window.nasViewerTvNativeError = async entryId =>', $view);
    }
}
