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

        $this->assertStringContainsString(
            "const previewSrc = isFolderVideoTvApp() ? '' : (video.preview_cached ? video.preview_url : '');",
            $contents
        );
        $this->assertStringContainsString('class="preview-poster"', $contents);
        $this->assertStringContainsString("video.addEventListener('loadeddata', () => video.classList.add('has-frame'))", $contents);
        $this->assertStringContainsString('/preview-queue', $contents);
        $this->assertStringContainsString('const posterSrc = video.thumbnail_cached', $contents);
        $this->assertStringContainsString('data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', $contents);
        $this->assertStringContainsString("method: 'HEAD', cache: 'no-store'", $contents);
        $this->assertStringContainsString('await sleep(100);', $contents);
        $this->assertStringContainsString(
            'const decoderLimit = configuredPreviewLimit;',
            $contents
        );
        $this->assertStringNotContainsString(
            'const decoderLimit = isFolderVideoTvApp() ? 4 : 8;',
            $contents
        );
        $this->assertStringNotContainsString('tvPreviewOffset', $contents);
        $this->assertStringContainsString('scheduleTvPlaybackWarm(video, delay = 700)', $contents);

        $server = file_get_contents(base_path('scripts/folder_video_range_server.py'));
        $this->assertStringContainsString('BUFFER_SIZE = 1024 * 1024', $server);
        $this->assertStringContainsString('self.send_response(206 if partial else 200)', $server);
        $this->assertStringContainsString('h264_nvenc', $server);
        $this->assertStringContainsString('transcode_tv_preview', $server);
        $this->assertStringContainsString('transcode_hls', $server);
        $this->assertStringContainsString('"h264_nvenc", "-preset", "p3"', $server);
        $this->assertStringContainsString("scale=w='if(gte(iw,ih),-2,256)'", $server);
        $this->assertStringContainsString("scale_cuda=w='if(gte(iw,ih),-2,256)'", $server);
        $this->assertStringNotContainsString('pad_cuda=', $server);
        $this->assertStringNotContainsString('pad={canvas_width}', $server);
        $this->assertStringContainsString('parser.add_argument("--preview-workers", type=int, default=2)', $server);
        $this->assertStringContainsString('scale=640:360', $server);
        $this->assertStringContainsString('"-crf", "20"', $server);
        $this->assertStringContainsString('safe_unlink(working_path)', $server);
        $this->assertStringContainsString('range(max(1, min(args.preview_workers, 8)))', $server);
        $this->assertStringContainsString('has_newer_request', $server);

        $service = file_get_contents(base_path('app/Services/FolderVideoService.php'));
        $this->assertStringContainsString('preview-mp4-v2-aspect-safe', $service);

        $this->assertStringContainsString("method: 'HEAD'", $contents);
        $this->assertStringContainsString("playlist.matchAll(/#EXTINF:", $contents);
        $this->assertStringContainsString('queueTvPlaybackWarm', $contents);
        $this->assertStringContainsString(
            'window.FolderVideoTvAndroid.playVideo(normalized.stream_url, saved);',
            $contents
        );
        $this->assertStringContainsString('vendor/hls.js/hls.min.js', $contents);
        $this->assertStringContainsString("script.src = '/vendor/hls.js/hls.min.js';", $contents);
        $this->assertStringContainsString('function ensureHlsLibrary()', $contents);
        $this->assertStringContainsString('needsOptimizedPlayback', $contents);
        $this->assertStringNotContainsString('http://10.0.0.2:8095/', $contents);

        $activity = file_get_contents(base_path(
            'android/folder-video-tv-apk/app/src/main/java/monster/mystar/foldervideotv/MainActivity.java'
        ));
        $this->assertStringNotContainsString('NAS_NGINX_BASE_URL', $activity);
        $this->assertStringNotContainsString('AndroidKeyStore', $activity);
        $this->assertStringContainsString('setVideoURI(mediaUri)', $activity);

        $startup = file_get_contents(base_path('scripts/start-folder-video-api.ps1'));
        $this->assertStringContainsString('[int]$MediaStreamPort = 8092', $startup);
        $this->assertStringContainsString('media_stream_pid = $mediaProcess.Id', $startup);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:$MediaStreamPort', $startup);
    }

    public function test_nas_viewer_tv_stops_stale_native_video_and_skips_failed_entries(): void
    {
        $activity = file_get_contents(base_path(
            'android/nas-viewer-tv-apk/app/src/main/java/monster/mystar/nasviewertv/MainActivity.java'
        ));
        $view = file_get_contents(base_path('resources/views/nas-viewer-app/index.blade.php'));

        $this->assertStringContainsString('nativeVideoGeneration', $activity);
        $this->assertStringContainsString('playVideo(String mediaUrl, String entryId)', $activity);
        $this->assertStringContainsString('stopNativeMedia(true)', $activity);
        $this->assertStringContainsString('const failedViewerEntryIds = new Set()', $view);
        $this->assertStringContainsString('stopNativeTvVideo();', $view);
        $this->assertStringContainsString('window.NasViewerTvAndroid.playVideo(directUrl || entry.media_url, entry.id)', $view);
        $this->assertStringContainsString('prepareViewerHls(entry)', $view);
        $this->assertStringContainsString('vendor/hls.js/hls.min.js', $view);
        $this->assertStringContainsString("script.src = '/vendor/hls.js/hls.min.js';", $view);
        $this->assertStringContainsString('watchViewerPlaybackStart(entry)', $view);
        $this->assertStringContainsString('fallbackViewerVideoToHls(entry', $view);
        $this->assertStringContainsString('shouldStartViewerWithHls(entry)', $view);
        $this->assertStringContainsString('entry._fallbackInFlight', $view);
        $this->assertStringContainsString('entry._failureHandled', $view);
        $this->assertStringNotContainsString('原檔不相容', $view);
        $this->assertStringContainsString('playViewerHls', $view);
        $this->assertStringContainsString('skipFailedViewerVideo', $view);
        $this->assertStringContainsString('window.nasViewerTvNativeError = async entryId =>', $view);
        $this->assertStringContainsString("document.documentElement.classList.toggle('nas-viewer-tv', isNasViewerTvApp)", $view);
        $this->assertStringContainsString('html.nas-viewer-tv .viewer.image-mode .image-viewer.active', $view);
        $this->assertStringContainsString('object-fit: contain;', $view);
        $this->assertStringContainsString('new ImageView(this)', $activity);
        $this->assertStringContainsString('ImageView.ScaleType.FIT_CENTER', $activity);
        $this->assertStringContainsString('showImage(String mediaUrl, String entryId)', $activity);
        $this->assertStringContainsString('ExifInterface.TAG_ORIENTATION', $activity);
        $this->assertStringContainsString('applyExifOrientation', $activity);
        $this->assertStringContainsString('nativeVideoPrepareTimeout', $activity);
        $this->assertStringContainsString('postDelayed(nativeVideoPrepareTimeout, 6000L)', $activity);
        $this->assertStringContainsString('點一下即可開啟', $view);
        $this->assertStringNotContainsString('再點一下開啟檔案', $view);

        $rangeServer = file_get_contents(base_path('scripts/folder_video_range_server.py'));
        $this->assertStringContainsString('scale=-2:720,fps=30', $rangeServer);
        $this->assertStringContainsString('no-store, no-cache, must-revalidate', $rangeServer);
        $this->assertStringContainsString('parser.add_argument("--hls-source-root", action="append"', $rangeServer);
        $this->assertStringContainsString('any(is_within(source, root) for root in source_roots)', $rangeServer);
        $this->assertStringContainsString('"-hls_flags", "independent_segments"', $rangeServer);
        $this->assertStringNotContainsString('independent_segments+temp_file', $rangeServer);
        $this->assertStringContainsString('def publish_hls_playlist(hls_path: Path)', $rangeServer);
        $this->assertStringContainsString('os.replace(temporary, public)', $rangeServer);
        $this->assertStringContainsString('index.working.m3u8', $rangeServer);
        $this->assertStringContainsString('#EXT-X-START:TIME-OFFSET=0,PRECISE=YES', $rangeServer);
        $this->assertStringContainsString('startPosition: 0', $view);

        $startup = file_get_contents(base_path('scripts/start-folder-video-api.ps1'));
        $this->assertStringContainsString('$mediaArguments += "--hls-source-root=$hlsSourceRoot"', $startup);
    }

    public function test_nas_viewer_android_apps_keep_secure_nas_direct_support(): void
    {
        $activities = [
            base_path('android/nas-viewer-apk/app/src/main/java/monster/mystar/nasviewer/MainActivity.java'),
            base_path('android/nas-viewer-tv-apk/app/src/main/java/monster/mystar/nasviewertv/MainActivity.java'),
        ];
        foreach ($activities as $activity) {
            $contents = file_get_contents($activity);
            $this->assertIsString($contents);
            $normalizedActivity = str_replace('\\', '/', $activity);
            $apkRoot = strstr($normalizedActivity, '/app/src', true);
            $this->assertIsString($apkRoot);
            $this->assertStringContainsString('home_root_ca', file_get_contents(
                $apkRoot.'/app/src/main/res/xml/network_security_config.xml'
            ));
        }

        $bridge = file_get_contents(base_path('android/shared-nas-direct/java/monster/mystar/shared/NasDirectBridge.java'));
        $this->assertStringContainsString('AndroidKeyStore', $bridge);
        $this->assertStringContainsString('AES/GCM/NoPadding', $bridge);
        $this->assertStringContainsString('directUrl(String share, String relativePath)', $bridge);
        $this->assertStringContainsString('bundledCredentials(Activity activity)', $bridge);
        $this->assertStringContainsString('nas_bundled_username', $bridge);

        $videoTv = file_get_contents(base_path(
            'android/folder-video-tv-apk/app/src/main/java/monster/mystar/foldervideotv/MainActivity.java'
        ));
        $this->assertStringNotContainsString('NasDirectBridge', $videoTv);
        $this->assertStringNotContainsString('NAS 帳號', $videoTv);

        $this->assertFileDoesNotExist(base_path(
            'android/shared-nas-direct/nas-credentials.xml'
        ));
    }
}
