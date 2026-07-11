package monster.mystar.nasviewertv;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.ActivityInfo;
import android.content.res.ColorStateList;
import android.database.Cursor;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.graphics.Color;
import android.graphics.Insets;
import android.net.ConnectivityManager;
import android.net.NetworkInfo;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.provider.Settings;
import android.view.Gravity;
import android.view.KeyEvent;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowInsets;
import android.webkit.DownloadListener;
import android.webkit.JavascriptInterface;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;
import android.widget.VideoView;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;

public class MainActivity extends Activity {
    private static final int APP_VERSION_CODE = 5;
    private static final String APP_VERSION_NAME = "2026.07.11.5-tv";
    private static final String ANDROID_VERSION_PATH = "/nas-viewer-app/tv/android-version.json";
    private static final String[] APP_URLS = new String[] {
        "http://10.0.0.25:8090/nas-viewer-app",
        "http://10.0.0.19:8090/nas-viewer-app",
        "http://10.147.18.155:8090/nas-viewer-app"
    };

    private FrameLayout root;
    private WebView webView;
    private View errorView;
    private TextView errorMessageView;
    private TextView errorUrlView;
    private View customView;
    private WebChromeClient.CustomViewCallback customViewCallback;
    private int currentUrlIndex = 0;
    private String currentAppUrl = APP_URLS[0];
    private long updateDownloadId = -1L;
    private long lastApkUpdateCheckMs = 0L;
    private boolean apkUpdateCheckRunning = false;
    private boolean updateDownloadReceiverRegistered = false;
    private boolean pendingInstallPermission = false;
    private int systemBarTopInset = 0;
    private int systemBarBottomInset = 0;
    private boolean videoFullscreenEnabled = false;
    private volatile boolean tvViewerOpen = false;
    private volatile boolean tvViewerVideo = false;
    private FrameLayout nativeVideoOverlay;
    private VideoView nativeVideoView;
    private TextView nativeVideoStatus;
    private LinearLayout nativeSeekOverlay;
    private TextView nativeSeekLabel;
    private ProgressBar nativeSeekProgress;
    private TextView nativeSeekTime;
    private boolean nativeVideoOpen = false;
    private FrameLayout nativeImageOverlay;
    private ImageView nativeImageView;
    private TextView nativeImageStatus;
    private boolean nativeImageOpen = false;
    private long nativeImageGeneration = 0L;
    private long nativeVideoGeneration = 0L;
    private final Runnable hideNativeSeekOverlay = () -> {
        if (nativeSeekOverlay != null) nativeSeekOverlay.setVisibility(View.GONE);
    };

    private final BroadcastReceiver updateDownloadReceiver = new BroadcastReceiver() {
        @Override
        public void onReceive(Context context, Intent intent) {
            long completedId = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1L);
            if (completedId == updateDownloadId) {
                installDownloadedApk();
            }
        }
    };

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        requestWindowFeature(Window.FEATURE_NO_TITLE);

        root = new FrameLayout(this);
        root.setBackgroundColor(Color.BLACK);
        setContentView(root);

        webView = new WebView(this);
        root.addView(webView, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT
        ));
        createNativeVideoOverlay();
        createNativeImageOverlay();
        applySystemBarInsets();

        errorView = createErrorView();
        errorView.setVisibility(View.GONE);
        root.addView(errorView, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT
        ));

        configureWebView();
        registerUpdateDownloadReceiver();
        loadCurrentAppUrl();
    }

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    private void configureWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setCacheMode(WebSettings.LOAD_NO_CACHE);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        settings.setUserAgentString(settings.getUserAgentString() + " NasViewerTvApp/" + APP_VERSION_NAME);

        webView.setBackgroundColor(Color.BLACK);
        webView.clearCache(true);
        webView.setFocusable(true);
        webView.setFocusableInTouchMode(true);
        webView.addJavascriptInterface(new NasViewerAndroidBridge(), "NasViewerAndroid");
        webView.addJavascriptInterface(new NasViewerTvBridge(), "NasViewerTvAndroid");
        webView.setWebViewClient(new NasViewerWebViewClient());
        webView.setWebChromeClient(new FullscreenChromeClient());
        webView.setDownloadListener(new NasViewerDownloadListener());
        webView.requestFocus();
    }

    private void applySystemBarInsets() {
        Window window = getWindow();
        window.setStatusBarColor(Color.BLACK);
        window.setNavigationBarColor(Color.BLACK);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            window.setDecorFitsSystemWindows(false);
        }

        root.setOnApplyWindowInsetsListener((view, insets) -> {
            if (Build.VERSION.SDK_INT < Build.VERSION_CODES.R) {
                return insets;
            }

            int top = 0;
            int bottom = 0;
            Insets systemBars = insets.getInsets(WindowInsets.Type.systemBars());
            top = systemBars.top;
            bottom = systemBars.bottom;

            if (top != systemBarTopInset || bottom != systemBarBottomInset) {
                systemBarTopInset = top;
                systemBarBottomInset = bottom;
                root.setPadding(0, Math.max(0, systemBarTopInset), 0, Math.max(0, systemBarBottomInset));
                injectSystemBarInsets();
            }

            return WindowInsets.CONSUMED;
        });
        root.requestApplyInsets();
    }

    private void injectSystemBarInsets() {
        if (webView == null) {
            return;
        }

        final float density = Math.max(1f, getResources().getDisplayMetrics().density);
        final int bottomInset = Math.max(0, Math.round(systemBarBottomInset / density));
        webView.post(() -> webView.evaluateJavascript(
            "if (window.nasViewerSetAndroidInsets) { window.nasViewerSetAndroidInsets({bottom:" + bottomInset + "}); }",
            null
        ));
    }

    @Override
    protected void onResume() {
        super.onResume();
        checkForApkUpdate(false);
        if (
            pendingInstallPermission
            && updateDownloadId > 0
            && (Build.VERSION.SDK_INT < Build.VERSION_CODES.O || getPackageManager().canRequestPackageInstalls())
        ) {
            pendingInstallPermission = false;
            installDownloadedApk();
        }
        if (webView != null) {
            webView.evaluateJavascript("if (window.nasViewerCheckUpdates) { window.nasViewerCheckUpdates(); }", null);
        }
    }

    private class NasViewerTvBridge {
        @JavascriptInterface
        public void setViewerState(boolean open, String kind) {
            tvViewerOpen = open;
            tvViewerVideo = open && "video".equals(kind);
        }

        @JavascriptInterface
        public void playVideo(String mediaUrl, String entryId) {
            runOnUiThread(() -> startNativeVideo(mediaUrl, entryId));
        }

        @JavascriptInterface
        public void stopVideo() {
            runOnUiThread(() -> stopNativeMedia(false));
        }

        @JavascriptInterface
        public void showImage(String mediaUrl, String entryId) {
            runOnUiThread(() -> startNativeImage(mediaUrl, entryId));
        }
    }

    @Override
    protected void onPause() {
        if (nativeVideoOpen || nativeImageOpen) stopNativeMedia(true);
        super.onPause();
    }

    @Override
    public boolean dispatchKeyEvent(KeyEvent event) {
        String key = remoteKeyName(event.getKeyCode());
        if (key != null) {
            if (event.getAction() == KeyEvent.ACTION_DOWN) {
                if (nativeVideoOpen && ("left".equals(key) || "right".equals(key))) {
                    seekNativeVideo("left".equals(key) ? -5000 : 5000);
                } else if (nativeVideoOpen && "center".equals(key)) {
                    toggleNativeVideo();
                } else {
                    dispatchTvKey(key);
                }
            }
            return true;
        }
        return super.dispatchKeyEvent(event);
    }

    private String remoteKeyName(int keyCode) {
        if (keyCode == KeyEvent.KEYCODE_DPAD_LEFT || keyCode == KeyEvent.KEYCODE_MEDIA_REWIND) return "left";
        if (keyCode == KeyEvent.KEYCODE_DPAD_RIGHT || keyCode == KeyEvent.KEYCODE_MEDIA_FAST_FORWARD) return "right";
        if (keyCode == KeyEvent.KEYCODE_DPAD_UP) return "up";
        if (keyCode == KeyEvent.KEYCODE_DPAD_DOWN) return "down";
        if (keyCode == KeyEvent.KEYCODE_DPAD_CENTER || keyCode == KeyEvent.KEYCODE_ENTER || keyCode == KeyEvent.KEYCODE_MEDIA_PLAY_PAUSE) return "center";
        return null;
    }

    private void dispatchTvKey(String key) {
        if (webView == null) return;
        webView.post(() -> webView.evaluateJavascript(
            "if (window.nasViewerTvHandleKey) { window.nasViewerTvHandleKey('" + key + "'); }",
            null
        ));
    }

    private void createNativeVideoOverlay() {
        nativeVideoOverlay = new FrameLayout(this);
        nativeVideoOverlay.setBackgroundColor(Color.BLACK);
        nativeVideoOverlay.setVisibility(View.GONE);
        nativeVideoView = new VideoView(this);
        nativeVideoOverlay.addView(nativeVideoView, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT,
            Gravity.CENTER
        ));
        nativeVideoStatus = new TextView(this);
        nativeVideoStatus.setTextColor(Color.WHITE);
        nativeVideoStatus.setTextSize(24f);
        nativeVideoStatus.setGravity(Gravity.CENTER);
        nativeVideoStatus.setBackgroundColor(0x88000000);
        nativeVideoStatus.setPadding(28, 18, 28, 18);
        nativeVideoOverlay.addView(nativeVideoStatus, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.WRAP_CONTENT,
            ViewGroup.LayoutParams.WRAP_CONTENT,
            Gravity.CENTER
        ));

        nativeSeekOverlay = new LinearLayout(this);
        nativeSeekOverlay.setOrientation(LinearLayout.VERTICAL);
        nativeSeekOverlay.setGravity(Gravity.CENTER_HORIZONTAL);
        nativeSeekOverlay.setPadding(dp(24), dp(14), dp(24), dp(14));
        nativeSeekOverlay.setBackgroundColor(0xCC101820);
        nativeSeekOverlay.setVisibility(View.GONE);

        nativeSeekLabel = new TextView(this);
        nativeSeekLabel.setTextColor(Color.WHITE);
        nativeSeekLabel.setTextSize(20f);
        nativeSeekLabel.setGravity(Gravity.CENTER);
        nativeSeekOverlay.addView(nativeSeekLabel, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        ));

        nativeSeekProgress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        nativeSeekProgress.setMax(1000);
        nativeSeekProgress.setProgressTintList(ColorStateList.valueOf(Color.rgb(95, 231, 255)));
        LinearLayout.LayoutParams progressParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            dp(12)
        );
        progressParams.setMargins(0, dp(8), 0, dp(6));
        nativeSeekOverlay.addView(nativeSeekProgress, progressParams);

        nativeSeekTime = new TextView(this);
        nativeSeekTime.setTextColor(Color.WHITE);
        nativeSeekTime.setTextSize(16f);
        nativeSeekTime.setGravity(Gravity.CENTER);
        nativeSeekOverlay.addView(nativeSeekTime, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        ));

        FrameLayout.LayoutParams seekParams = new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT,
            Gravity.BOTTOM
        );
        seekParams.setMargins(dp(48), 0, dp(48), dp(42));
        nativeVideoOverlay.addView(nativeSeekOverlay, seekParams);
        root.addView(nativeVideoOverlay, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT
        ));
    }

    private void createNativeImageOverlay() {
        nativeImageOverlay = new FrameLayout(this);
        nativeImageOverlay.setBackgroundColor(Color.BLACK);
        nativeImageOverlay.setVisibility(View.GONE);
        nativeImageView = new ImageView(this);
        nativeImageView.setScaleType(ImageView.ScaleType.FIT_CENTER);
        nativeImageView.setAdjustViewBounds(false);
        nativeImageOverlay.addView(nativeImageView, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT,
            Gravity.CENTER
        ));
        nativeImageStatus = new TextView(this);
        nativeImageStatus.setTextColor(Color.WHITE);
        nativeImageStatus.setTextSize(24f);
        nativeImageStatus.setGravity(Gravity.CENTER);
        nativeImageStatus.setBackgroundColor(0x88000000);
        nativeImageStatus.setPadding(28, 18, 28, 18);
        nativeImageOverlay.addView(nativeImageStatus, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.WRAP_CONTENT,
            ViewGroup.LayoutParams.WRAP_CONTENT,
            Gravity.CENTER
        ));
        root.addView(nativeImageOverlay, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT
        ));
    }

    private String resolveMediaUrl(String mediaUrl) {
        try {
            String base = webView != null && webView.getUrl() != null ? webView.getUrl() : currentAppUrl;
            return new URL(new URL(base), mediaUrl).toString();
        } catch (Exception ignored) {
            return mediaUrl;
        }
    }

    private void startNativeVideo(String mediaUrl, String entryId) {
        if (mediaUrl == null || mediaUrl.trim().isEmpty()) return;
        stopNativeImage(false);
        stopNativeVideo(false);
        final long generation = ++nativeVideoGeneration;
        final String playbackEntryId = entryId == null ? "" : entryId;
        nativeVideoOpen = true;
        tvViewerOpen = true;
        tvViewerVideo = true;
        nativeSeekOverlay.setVisibility(View.GONE);
        nativeVideoStatus.setText("影片載入中…");
        nativeVideoStatus.setVisibility(View.VISIBLE);
        nativeVideoOverlay.setVisibility(View.VISIBLE);
        nativeVideoOverlay.bringToFront();
        updateImmersiveMode();
        nativeVideoView.setOnPreparedListener(player -> {
            if (generation != nativeVideoGeneration || !nativeVideoOpen) return;
            nativeVideoStatus.setVisibility(View.GONE);
            nativeVideoView.start();
            evaluateTvJavascript(
                "if (window.nasViewerTvNativePlaying) { window.nasViewerTvNativePlaying(" +
                    JSONObject.quote(playbackEntryId) + "); }"
            );
        });
        nativeVideoView.setOnCompletionListener(player -> {
            if (generation != nativeVideoGeneration || !nativeVideoOpen) return;
            stopNativeVideo(false);
            evaluateTvJavascript(
                "if (window.nasViewerTvNativeEnded) { window.nasViewerTvNativeEnded(" +
                    JSONObject.quote(playbackEntryId) + "); }"
            );
        });
        nativeVideoView.setOnErrorListener((player, what, extra) -> {
            if (generation != nativeVideoGeneration || !nativeVideoOpen) return true;
            stopNativeVideo(false);
            evaluateTvJavascript(
                "if (window.nasViewerTvNativeError) { window.nasViewerTvNativeError(" +
                    JSONObject.quote(playbackEntryId) + "); }"
            );
            return true;
        });
        nativeVideoView.setVideoURI(Uri.parse(resolveMediaUrl(mediaUrl)));
        nativeVideoView.requestFocus();
    }

    private void startNativeImage(String mediaUrl, String entryId) {
        if (mediaUrl == null || mediaUrl.trim().isEmpty()) return;
        stopNativeVideo(false);
        stopNativeImage(false);
        final long generation = ++nativeImageGeneration;
        final String imageEntryId = entryId == null ? "" : entryId;
        final String resolvedUrl = resolveMediaUrl(mediaUrl);
        nativeImageOpen = true;
        tvViewerOpen = true;
        tvViewerVideo = false;
        nativeImageView.setImageDrawable(null);
        nativeImageStatus.setText("圖片載入中…");
        nativeImageStatus.setVisibility(View.VISIBLE);
        nativeImageOverlay.setVisibility(View.VISIBLE);
        nativeImageOverlay.bringToFront();
        updateImmersiveMode();

        new Thread(() -> {
            Bitmap bitmap = decodeFittedBitmap(resolvedUrl);
            runOnUiThread(() -> {
                if (generation != nativeImageGeneration || !nativeImageOpen) {
                    if (bitmap != null) bitmap.recycle();
                    return;
                }
                if (bitmap == null) {
                    nativeImageStatus.setText("圖片無法顯示");
                    evaluateTvJavascript(
                        "if (window.nasViewerTvNativeImageError) { window.nasViewerTvNativeImageError(" +
                            JSONObject.quote(imageEntryId) + "); }"
                    );
                    return;
                }
                nativeImageView.setImageBitmap(bitmap);
                nativeImageStatus.setVisibility(View.GONE);
            });
        }, "nas-viewer-image-loader").start();
    }

    private Bitmap decodeFittedBitmap(String requestUrl) {
        try {
            BitmapFactory.Options bounds = new BitmapFactory.Options();
            bounds.inJustDecodeBounds = true;
            HttpURLConnection first = (HttpURLConnection) new URL(requestUrl).openConnection();
            first.setConnectTimeout(8000);
            first.setReadTimeout(20000);
            try (InputStream input = first.getInputStream()) {
                BitmapFactory.decodeStream(input, null, bounds);
            } finally {
                first.disconnect();
            }
            int screenWidth = Math.max(1, getResources().getDisplayMetrics().widthPixels);
            int screenHeight = Math.max(1, getResources().getDisplayMetrics().heightPixels);
            int sample = 1;
            while (bounds.outWidth / sample > screenWidth * 2 || bounds.outHeight / sample > screenHeight * 2) {
                sample *= 2;
            }
            BitmapFactory.Options options = new BitmapFactory.Options();
            options.inSampleSize = Math.max(1, sample);
            options.inPreferredConfig = Bitmap.Config.ARGB_8888;
            HttpURLConnection second = (HttpURLConnection) new URL(requestUrl).openConnection();
            second.setConnectTimeout(8000);
            second.setReadTimeout(30000);
            try (InputStream input = second.getInputStream()) {
                return BitmapFactory.decodeStream(input, null, options);
            } finally {
                second.disconnect();
            }
        } catch (Exception ignored) {
            return null;
        }
    }

    private void stopNativeImage(boolean notifyWeb) {
        nativeImageGeneration++;
        nativeImageOpen = false;
        if (nativeImageView != null) nativeImageView.setImageDrawable(null);
        if (nativeImageOverlay != null) nativeImageOverlay.setVisibility(View.GONE);
        updateImmersiveMode();
        if (notifyWeb) evaluateTvJavascript("if (window.nasViewerTvNativeClosed) { window.nasViewerTvNativeClosed(); }");
    }

    private void stopNativeMedia(boolean notifyWeb) {
        stopNativeVideo(false);
        stopNativeImage(false);
        if (notifyWeb) evaluateTvJavascript("if (window.nasViewerTvNativeClosed) { window.nasViewerTvNativeClosed(); }");
    }

    private void seekNativeVideo(int deltaMs) {
        if (!nativeVideoOpen || nativeVideoView == null) return;
        int duration = nativeVideoView.getDuration();
        int target = Math.max(0, nativeVideoView.getCurrentPosition() + deltaMs);
        if (duration > 0) target = Math.min(duration, target);
        nativeVideoView.seekTo(target);
        showNativeSeekProgress(target, duration, deltaMs > 0 ? "+5 秒" : "-5 秒");
    }

    private void toggleNativeVideo() {
        if (!nativeVideoOpen || nativeVideoView == null) return;
        if (nativeVideoView.isPlaying()) nativeVideoView.pause(); else nativeVideoView.start();
    }

    private void stopNativeVideo(boolean notifyWeb) {
        nativeVideoGeneration++;
        if (nativeVideoView != null) {
            nativeVideoView.setOnPreparedListener(null);
            nativeVideoView.setOnCompletionListener(null);
            nativeVideoView.setOnErrorListener(null);
            nativeVideoView.stopPlayback();
        }
        nativeVideoOpen = false;
        nativeVideoStatus.setVisibility(View.GONE);
        if (nativeSeekOverlay != null) {
            nativeSeekOverlay.removeCallbacks(hideNativeSeekOverlay);
            nativeSeekOverlay.setVisibility(View.GONE);
        }
        nativeVideoOverlay.setVisibility(View.GONE);
        updateImmersiveMode();
        if (notifyWeb) evaluateTvJavascript("if (window.nasViewerTvNativeClosed) { window.nasViewerTvNativeClosed(); }");
    }

    private void showNativeSeekProgress(int positionMs, int durationMs, String label) {
        if (nativeSeekOverlay == null) return;
        nativeSeekLabel.setText(label);
        int safeDuration = Math.max(0, durationMs);
        int safePosition = Math.max(0, positionMs);
        int progress = safeDuration > 0
            ? Math.min(1000, Math.round((safePosition * 1000f) / safeDuration))
            : 0;
        nativeSeekProgress.setProgress(progress);
        nativeSeekTime.setText(formatPlaybackTime(safePosition) + " / " + formatPlaybackTime(safeDuration));
        nativeSeekOverlay.setVisibility(View.VISIBLE);
        nativeSeekOverlay.removeCallbacks(hideNativeSeekOverlay);
        nativeSeekOverlay.postDelayed(hideNativeSeekOverlay, 500L);
    }

    private String formatPlaybackTime(int milliseconds) {
        int totalSeconds = Math.max(0, milliseconds / 1000);
        int hours = totalSeconds / 3600;
        int minutes = (totalSeconds % 3600) / 60;
        int seconds = totalSeconds % 60;
        return hours > 0
            ? String.format(java.util.Locale.US, "%d:%02d:%02d", hours, minutes, seconds)
            : String.format(java.util.Locale.US, "%02d:%02d", minutes, seconds);
    }

    private void evaluateTvJavascript(String script) {
        if (webView != null) webView.post(() -> webView.evaluateJavascript(script, null));
    }

    @Override
    public void onBackPressed() {
        if (nativeVideoOpen || nativeImageOpen) {
            stopNativeMedia(true);
            return;
        }
        if (customView != null) {
            hideCustomView();
            return;
        }

        if (errorView != null && errorView.getVisibility() == View.VISIBLE) {
            retryFromFirstUrl();
            return;
        }

        if (webView != null) {
            webView.evaluateJavascript(
                "(function(){return !!(window.nasViewerHandleBack && window.nasViewerHandleBack());})()",
                handled -> {
                    if ("true".equals(handled)) {
                        return;
                    }

                    finishWebBack();
                }
            );
            return;
        }

        super.onBackPressed();
    }

    private void finishWebBack() {
        if (webView != null && webView.canGoBack()) {
            webView.goBack();
            return;
        }

        super.onBackPressed();
    }

    private void setMediaOrientationEnabled(boolean enabled) {
        int requestedOrientation = ActivityInfo.SCREEN_ORIENTATION_LANDSCAPE;
        if (getRequestedOrientation() != requestedOrientation) {
            setRequestedOrientation(requestedOrientation);
        }
    }

    private class NasViewerAndroidBridge {
        @JavascriptInterface
        public void setMediaOrientationEnabled(boolean enabled) {
            runOnUiThread(() -> MainActivity.this.setMediaOrientationEnabled(enabled));
        }

        @JavascriptInterface
        public void setVideoFullscreenEnabled(boolean enabled) {
            runOnUiThread(() -> MainActivity.this.setVideoFullscreenEnabled(enabled));
        }
    }

    private void setVideoFullscreenEnabled(boolean enabled) {
        videoFullscreenEnabled = enabled;
        updateImmersiveMode();
    }

    private void updateImmersiveMode() {
        if (videoFullscreenEnabled || nativeVideoOpen || nativeImageOpen || customView != null) {
            root.setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_FULLSCREEN
                    | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                    | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                    | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                    | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                    | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
            );
        } else {
            root.setSystemUiVisibility(View.SYSTEM_UI_FLAG_VISIBLE);
        }
        root.requestApplyInsets();
    }

    @Override
    protected void onDestroy() {
        stopNativeMedia(false);
        if (updateDownloadReceiverRegistered) {
            unregisterReceiver(updateDownloadReceiver);
            updateDownloadReceiverRegistered = false;
        }

        if (webView != null) {
            root.removeView(webView);
            webView.destroy();
            webView = null;
        }
        super.onDestroy();
    }

    private View createErrorView() {
        LinearLayout layout = new LinearLayout(this);
        layout.setOrientation(LinearLayout.VERTICAL);
        layout.setGravity(Gravity.CENTER);
        layout.setPadding(dp(24), dp(24), dp(24), dp(24));
        layout.setBackgroundColor(Color.rgb(7, 9, 12));

        TextView title = new TextView(this);
        title.setText("無法連線到 NAS Viewer 服務");
        title.setTextColor(Color.WHITE);
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        title.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        layout.addView(title, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        ));

        errorMessageView = new TextView(this);
        errorMessageView.setTextColor(Color.rgb(194, 203, 218));
        errorMessageView.setTextSize(15);
        errorMessageView.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams messageParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        );
        messageParams.setMargins(0, dp(14), 0, 0);
        layout.addView(errorMessageView, messageParams);

        errorUrlView = new TextView(this);
        errorUrlView.setTextColor(Color.rgb(119, 214, 201));
        errorUrlView.setTextSize(12);
        errorUrlView.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams urlParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        );
        urlParams.setMargins(0, dp(12), 0, 0);
        layout.addView(errorUrlView, urlParams);

        Button retryButton = new Button(this);
        retryButton.setText("重試");
        retryButton.setAllCaps(false);
        retryButton.setOnClickListener(view -> retryFromFirstUrl());
        LinearLayout.LayoutParams buttonParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            dp(48)
        );
        buttonParams.setMargins(0, dp(22), 0, 0);
        layout.addView(retryButton, buttonParams);

        TextView hint = new TextView(this);
        hint.setText("請確認手機與電腦在同一個內網，且電腦的 NAS Viewer Caddy 服務正在 8090 埠執行。");
        hint.setTextColor(Color.rgb(137, 147, 164));
        hint.setTextSize(12);
        hint.setGravity(Gravity.CENTER);
        LinearLayout.LayoutParams hintParams = new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.WRAP_CONTENT
        );
        hintParams.setMargins(0, dp(16), 0, 0);
        layout.addView(hint, hintParams);

        return layout;
    }

    private void loadCurrentAppUrl() {
        currentAppUrl = APP_URLS[Math.max(0, Math.min(currentUrlIndex, APP_URLS.length - 1))];
        hideErrorView();
        webView.loadUrl(currentAppUrl);
    }

    private void retryFromFirstUrl() {
        currentUrlIndex = 0;
        loadCurrentAppUrl();
    }

    private void showErrorView(String message) {
        String networkHint = isNetworkConnected()
            ? message
            : "電視盒目前沒有可用網路，請先連上 Wi-Fi 或內網。";

        errorMessageView.setText(networkHint);
        errorUrlView.setText(currentAppUrl);
        errorView.setVisibility(View.VISIBLE);
        webView.setVisibility(View.INVISIBLE);
    }

    private void hideErrorView() {
        if (errorView != null) {
            errorView.setVisibility(View.GONE);
        }
        if (webView != null) {
            webView.setVisibility(View.VISIBLE);
        }
    }

    private void handleMainFrameError(String message) {
        if (currentUrlIndex < APP_URLS.length - 1) {
            currentUrlIndex++;
            loadCurrentAppUrl();
            return;
        }

        showErrorView(message);
    }

    private boolean isNetworkConnected() {
        try {
            ConnectivityManager manager = (ConnectivityManager) getSystemService(CONNECTIVITY_SERVICE);
            NetworkInfo info = manager == null ? null : manager.getActiveNetworkInfo();
            return info != null && info.isConnected();
        } catch (Exception ignored) {
            return true;
        }
    }

    private void showCustomView(View view, WebChromeClient.CustomViewCallback callback) {
        if (customView != null) {
            callback.onCustomViewHidden();
            return;
        }

        customView = view;
        customViewCallback = callback;
        webView.setVisibility(View.GONE);
        root.addView(customView, new FrameLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT,
            ViewGroup.LayoutParams.MATCH_PARENT
        ));
        updateImmersiveMode();
    }

    private void hideCustomView() {
        if (customView == null) {
            return;
        }

        root.removeView(customView);
        customView = null;
        webView.setVisibility(View.VISIBLE);
        updateImmersiveMode();

        if (customViewCallback != null) {
            customViewCallback.onCustomViewHidden();
            customViewCallback = null;
        }
    }

    private class NasViewerWebViewClient extends WebViewClient {
        @Override
        public void onPageStarted(WebView view, String url, Bitmap favicon) {
            if (nativeVideoOpen || nativeImageOpen) stopNativeMedia(false);
            super.onPageStarted(view, url, favicon);
        }

        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            Uri uri = request.getUrl();
            if (isInternalUri(uri)) {
                return false;
            }

            openExternal(uri);
            return true;
        }

        @SuppressWarnings("deprecation")
        @Override
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            Uri uri = Uri.parse(url);
            if (isInternalUri(uri)) {
                return false;
            }

            openExternal(uri);
            return true;
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            hideErrorView();
            injectSystemBarInsets();
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            super.onReceivedError(view, request, error);
            if (request.isForMainFrame()) {
                handleMainFrameError("載入失敗：" + error.getDescription());
            }
        }

        @SuppressWarnings("deprecation")
        @Override
        public void onReceivedError(WebView view, int errorCode, String description, String failingUrl) {
            super.onReceivedError(view, errorCode, description, failingUrl);
            if (isSameUrl(failingUrl, currentAppUrl)) {
                handleMainFrameError("載入失敗：" + description);
            }
        }

        @Override
        public void onReceivedHttpError(WebView view, WebResourceRequest request, WebResourceResponse errorResponse) {
            super.onReceivedHttpError(view, request, errorResponse);
            if (request.isForMainFrame()) {
                handleMainFrameError("伺服器回應 HTTP " + errorResponse.getStatusCode());
            }
        }
    }

    private class FullscreenChromeClient extends WebChromeClient {
        @Override
        public void onShowCustomView(View view, CustomViewCallback callback) {
            showCustomView(view, callback);
        }

        @Override
        public void onHideCustomView() {
            hideCustomView();
        }
    }

    private class NasViewerDownloadListener implements DownloadListener {
        @Override
        public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
            Uri downloadUri = url == null ? null : Uri.parse(url);
            String downloadPath = downloadUri == null ? "" : String.valueOf(downloadUri.getPath()).toLowerCase();
            boolean isApk = downloadPath.endsWith(".apk")
                || "application/vnd.android.package-archive".equalsIgnoreCase(mimeType);
            if (url != null && isApk) {
                downloadApk(url, APP_VERSION_CODE + 1, "latest");
                return;
            }

            openExternal(Uri.parse(url));
        }
    }

    private boolean isInternalUri(Uri uri) {
        if (uri == null || uri.getHost() == null) {
            return false;
        }

        for (String appUrl : APP_URLS) {
            Uri allowed = Uri.parse(appUrl);
            if (uri.getHost().equals(allowed.getHost())) {
                int allowedPort = allowed.getPort();
                return allowedPort == -1 || allowedPort == uri.getPort();
            }
        }

        return "blog.test".equals(uri.getHost());
    }

    private boolean isSameUrl(String left, String right) {
        if (left == null || right == null) {
            return false;
        }
        return Uri.parse(left).normalizeScheme().toString().equals(Uri.parse(right).normalizeScheme().toString());
    }

    private String currentBaseUrl() {
        Uri uri = Uri.parse(currentAppUrl);
        return uri.getScheme() + "://" + uri.getAuthority();
    }

    private String resolveServerUrl(String rawUrl) {
        if (rawUrl == null || rawUrl.length() == 0) {
            return "";
        }

        Uri uri = Uri.parse(rawUrl);
        if (uri.getScheme() == null) {
            return currentBaseUrl() + (rawUrl.startsWith("/") ? rawUrl : "/" + rawUrl);
        }

        if ("blog.test".equals(uri.getHost())) {
            String query = uri.getQuery() == null ? "" : "?" + uri.getQuery();
            return currentBaseUrl() + uri.getPath() + query;
        }

        return rawUrl;
    }

    private void checkForApkUpdate(boolean force) {
        long now = System.currentTimeMillis();
        if (!force && now - lastApkUpdateCheckMs < 60000L) {
            return;
        }
        if (apkUpdateCheckRunning) {
            return;
        }

        lastApkUpdateCheckMs = now;
        apkUpdateCheckRunning = true;

        new Thread(() -> {
            try {
                String versionUrl = currentBaseUrl() + ANDROID_VERSION_PATH + "?t=" + System.currentTimeMillis();
                JSONObject payload = new JSONObject(readHttpText(versionUrl));
                JSONObject data = payload.optJSONObject("data");
                if (data == null) {
                    return;
                }

                int latestCode = data.optInt("version_code", APP_VERSION_CODE);
                String latestName = data.optString("version_name", "");
                String apkUrl = resolveServerUrl(data.optString("apk_url", ""));

                if (latestCode > APP_VERSION_CODE && apkUrl.length() > 0) {
                    runOnUiThread(() -> downloadApk(apkUrl, latestCode, latestName));
                }
            } catch (Exception ignored) {
            } finally {
                apkUpdateCheckRunning = false;
            }
        }).start();
    }

    private String readHttpText(String requestUrl) throws Exception {
        HttpURLConnection connection = (HttpURLConnection) new URL(requestUrl).openConnection();
        connection.setConnectTimeout(4000);
        connection.setReadTimeout(7000);
        connection.setRequestProperty("User-Agent", "NasViewerApp/" + APP_VERSION_NAME);

        try {
            int status = connection.getResponseCode();
            InputStream stream = status >= 200 && status < 300
                ? connection.getInputStream()
                : connection.getErrorStream();

            try (BufferedReader reader = new BufferedReader(new InputStreamReader(stream, "UTF-8"))) {
                StringBuilder builder = new StringBuilder();
                String line;
                while ((line = reader.readLine()) != null) {
                    builder.append(line);
                }
                return builder.toString();
            }
        } finally {
            connection.disconnect();
        }
    }

    private void downloadApk(String apkUrl, int versionCode, String versionName) {
        try {
            DownloadManager.Request request = new DownloadManager.Request(Uri.parse(apkUrl));
            request.setTitle("APK 安裝檔");
            request.setDescription("下載 APK 安裝檔 " + (versionName == null || versionName.length() == 0 ? ("v" + versionCode) : versionName));
            request.setMimeType("application/vnd.android.package-archive");
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalFilesDir(
                this,
                Environment.DIRECTORY_DOWNLOADS,
                "nas-installer-" + System.currentTimeMillis() + ".apk"
            );

            DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (manager == null) {
                openExternal(Uri.parse(apkUrl));
                return;
            }

            updateDownloadId = manager.enqueue(request);
            Toast.makeText(this, "正在下載 APK 安裝檔", Toast.LENGTH_LONG).show();
        } catch (Exception error) {
            openExternal(Uri.parse(apkUrl));
        }
    }

    private void installDownloadedApk() {
        DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
        if (manager == null || updateDownloadId <= 0) {
            return;
        }

        if (!downloadSucceeded(manager, updateDownloadId)) {
            Toast.makeText(this, "APK 下載失敗，請稍後再試", Toast.LENGTH_LONG).show();
            return;
        }

        Uri apkUri = manager.getUriForDownloadedFile(updateDownloadId);
        if (apkUri == null) {
            Toast.makeText(this, "找不到已下載的 APK", Toast.LENGTH_LONG).show();
            return;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !getPackageManager().canRequestPackageInstalls()) {
            Toast.makeText(this, "請允許 NAS Viewer 安裝未知來源 APK", Toast.LENGTH_LONG).show();
            pendingInstallPermission = true;
            try {
                Intent settingsIntent = new Intent(
                    Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES,
                    Uri.parse("package:" + getPackageName())
                );
                startActivity(settingsIntent);
            } catch (ActivityNotFoundException ignored) {
                startActivity(new Intent(Settings.ACTION_SECURITY_SETTINGS));
            }
            return;
        }

        Intent installIntent = new Intent(Intent.ACTION_VIEW);
        installIntent.setDataAndType(apkUri, "application/vnd.android.package-archive");
        installIntent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);

        try {
            pendingInstallPermission = false;
            updateDownloadId = -1L;
            startActivity(installIntent);
        } catch (ActivityNotFoundException error) {
            Toast.makeText(this, "無法開啟 APK 安裝器", Toast.LENGTH_LONG).show();
        }
    }

    private boolean downloadSucceeded(DownloadManager manager, long downloadId) {
        DownloadManager.Query query = new DownloadManager.Query().setFilterById(downloadId);
        try (Cursor cursor = manager.query(query)) {
            if (cursor == null || !cursor.moveToFirst()) {
                return false;
            }

            int statusIndex = cursor.getColumnIndex(DownloadManager.COLUMN_STATUS);
            return statusIndex >= 0 && cursor.getInt(statusIndex) == DownloadManager.STATUS_SUCCESSFUL;
        }
    }

    private void registerUpdateDownloadReceiver() {
        IntentFilter filter = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
            registerReceiver(updateDownloadReceiver, filter, Context.RECEIVER_NOT_EXPORTED);
        } else {
            registerReceiver(updateDownloadReceiver, filter);
        }
        updateDownloadReceiverRegistered = true;
    }

    private void openExternal(Uri uri) {
        try {
            startActivity(new Intent(Intent.ACTION_VIEW, uri));
        } catch (ActivityNotFoundException ignored) {
        }
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
