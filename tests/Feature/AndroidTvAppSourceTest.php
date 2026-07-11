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
        }
    }
}
