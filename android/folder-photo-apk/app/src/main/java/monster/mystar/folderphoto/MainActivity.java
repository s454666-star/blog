package monster.mystar.folderphoto;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.database.Cursor;
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
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowInsets;
import android.webkit.DownloadListener;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.HttpAuthHandler;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.TextView;
import android.widget.Toast;

import org.json.JSONObject;

import monster.mystar.shared.NasDirectBridge;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;

public class MainActivity extends Activity {
    private static final int APP_VERSION_CODE = 2;
    private static final String APP_VERSION_NAME = "2026.07.11.2";
    private static final String ANDROID_VERSION_PATH = "/folder-photo-app/android-version.json";
    private static final String[] APP_URLS = new String[] {
        "http://10.0.0.25:8090/folder-photo-app",
        "http://10.0.0.19:8090/folder-photo-app",
        "http://10.147.18.155:8090/folder-photo-app"
    };

    private FrameLayout root;
    private WebView webView;
    private NasDirectBridge directNas;
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
    private int systemBarTopInset = 0;
    private int systemBarBottomInset = 0;

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
        checkForApkUpdate(true);
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void configureWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setAllowFileAccess(false);
        settings.setAllowContentAccess(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_ALWAYS_ALLOW);
        settings.setUserAgentString(settings.getUserAgentString() + " FolderPhotoApp/" + APP_VERSION_NAME);

        webView.setBackgroundColor(Color.BLACK);
        directNas = new NasDirectBridge(this, "folder-photo-phone");
        webView.addJavascriptInterface(directNas, "DirectNas");
        webView.setWebViewClient(new FolderPhotoWebViewClient());
        webView.setWebChromeClient(new FullscreenChromeClient());
        webView.setDownloadListener(new FolderPhotoDownloadListener());
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
            "if (window.folderPhotoSetAndroidInsets) { window.folderPhotoSetAndroidInsets({bottom:" + bottomInset + "}); }",
            null
        ));
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) {
            webView.evaluateJavascript("if (window.folderPhotoCheckUpdates) { window.folderPhotoCheckUpdates(); }", null);
        }
        checkForApkUpdate(true);
    }

    @Override
    public void onBackPressed() {
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
                "(function(){return !!(window.folderPhotoHandleBack && window.folderPhotoHandleBack());})()",
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

    @Override
    protected void onDestroy() {
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
        title.setText("無法連線到圖片服務");
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
        hint.setText("請確認手機與電腦在同一個內網，且電腦的 Folder Photo Caddy 服務正在 8090 埠執行。");
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
        checkForApkUpdate(true);
    }

    private void showErrorView(String message) {
        String networkHint = isNetworkConnected()
            ? message
            : "手機目前沒有可用網路，請先連上 Wi-Fi 或內網。";

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
        root.setSystemUiVisibility(
            View.SYSTEM_UI_FLAG_FULLSCREEN
                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
        );
    }

    private void hideCustomView() {
        if (customView == null) {
            return;
        }

        root.removeView(customView);
        customView = null;
        webView.setVisibility(View.VISIBLE);
        root.setSystemUiVisibility(View.SYSTEM_UI_FLAG_VISIBLE);

        if (customViewCallback != null) {
            customViewCallback.onCustomViewHidden();
            customViewCallback = null;
        }
    }

    private class FolderPhotoWebViewClient extends WebViewClient {
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
        public void onReceivedHttpAuthRequest(WebView view, HttpAuthHandler handler, String host, String realm) {
            if (directNas != null && directNas.handleHttpAuth(handler, host)) return;
            super.onReceivedHttpAuthRequest(view, handler, host, realm);
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            if (directNas != null) directNas.promptIfNeeded(() -> view.reload());
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

    private class FolderPhotoDownloadListener implements DownloadListener {
        @Override
        public void onDownloadStart(String url, String userAgent, String contentDisposition, String mimeType, long contentLength) {
            if (url != null && url.endsWith(".apk")) {
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
        connection.setRequestProperty("User-Agent", "FolderPhotoApp/" + APP_VERSION_NAME);

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
            request.setTitle("Folder Photo 更新");
            request.setDescription("下載 " + (versionName == null || versionName.length() == 0 ? ("v" + versionCode) : versionName));
            request.setMimeType("application/vnd.android.package-archive");
            request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            request.setDestinationInExternalFilesDir(
                this,
                Environment.DIRECTORY_DOWNLOADS,
                "folder-photo-app-" + versionCode + ".apk"
            );

            DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
            if (manager == null) {
                openExternal(Uri.parse(apkUrl));
                return;
            }

            updateDownloadId = manager.enqueue(request);
            Toast.makeText(this, "正在下載 Folder Photo 更新", Toast.LENGTH_LONG).show();
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
            Toast.makeText(this, "更新下載失敗，請稍後再試", Toast.LENGTH_LONG).show();
            return;
        }

        Uri apkUri = manager.getUriForDownloadedFile(updateDownloadId);
        if (apkUri == null) {
            Toast.makeText(this, "找不到已下載的更新檔", Toast.LENGTH_LONG).show();
            return;
        }

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O && !getPackageManager().canRequestPackageInstalls()) {
            Toast.makeText(this, "請允許 Folder Photo 安裝未知來源更新", Toast.LENGTH_LONG).show();
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
