package monster.mystar.shared;

import android.app.Activity;
import android.app.AlertDialog;
import android.content.SharedPreferences;
import android.net.Uri;
import android.security.keystore.KeyGenParameterSpec;
import android.security.keystore.KeyProperties;
import android.text.InputType;
import android.util.Base64;
import android.view.ViewGroup;
import android.webkit.HttpAuthHandler;
import android.webkit.JavascriptInterface;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.Toast;

import java.nio.charset.StandardCharsets;
import java.security.KeyStore;

import javax.crypto.Cipher;
import javax.crypto.KeyGenerator;
import javax.crypto.SecretKey;
import javax.crypto.spec.GCMParameterSpec;

public final class NasDirectBridge {
    private static final String NAS_HOST = "nas";
    private static final String NAS_BASE_URL = "https://nas:5006";
    private final Activity activity;
    private final String keyAlias;
    private final SharedPreferences preferences;
    private boolean promptShowing = false;

    public NasDirectBridge(Activity activity, String appKey) {
        this.activity = activity;
        this.keyAlias = "nas-direct-" + appKey;
        this.preferences = activity.getSharedPreferences("nas-direct-settings", Activity.MODE_PRIVATE);
    }

    public static String[] bundledCredentials(Activity activity) {
        if (activity == null) return null;
        int usernameId = activity.getResources().getIdentifier(
            "nas_bundled_username", "string", activity.getPackageName()
        );
        int passwordId = activity.getResources().getIdentifier(
            "nas_bundled_password", "string", activity.getPackageName()
        );
        if (usernameId == 0 || passwordId == 0) return null;
        String username = activity.getString(usernameId).trim();
        String password = activity.getString(passwordId);
        return username.isEmpty() || password.isEmpty()
            ? null
            : new String[] {username, password};
    }

    @JavascriptInterface
    public boolean ready() {
        return load() != null;
    }

    @JavascriptInterface
    public String directUrl(String share, String relativePath) {
        if (!ready() || share == null || share.trim().isEmpty() || relativePath == null) return "";
        Uri.Builder builder = Uri.parse(NAS_BASE_URL).buildUpon().appendPath(share.trim());
        for (String segment : relativePath.replace('\\', '/').split("/")) {
            if (!segment.isEmpty() && !".".equals(segment) && !"..".equals(segment)) builder.appendPath(segment);
        }
        return builder.build().toString();
    }

    public String authorizationHeader() {
        String[] credentials = load();
        if (credentials == null) return "";
        return "Basic " + Base64.encodeToString(
            (credentials[0] + ":" + credentials[1]).getBytes(StandardCharsets.UTF_8),
            Base64.NO_WRAP
        );
    }

    public boolean handleHttpAuth(HttpAuthHandler handler, String host) {
        if (handler == null || !NAS_HOST.equalsIgnoreCase(host)) return false;
        String[] credentials = load();
        if (credentials == null) return false;
        handler.proceed(credentials[0], credentials[1]);
        return true;
    }

    public void promptIfNeeded(Runnable onSaved) {
        if (ready() || promptShowing || activity.isFinishing()) return;
        promptShowing = true;
        LinearLayout fields = new LinearLayout(activity);
        fields.setOrientation(LinearLayout.VERTICAL);
        int padding = Math.round(28 * activity.getResources().getDisplayMetrics().density);
        fields.setPadding(padding, padding / 2, padding, 0);

        EditText username = new EditText(activity);
        username.setHint("NAS 帳號");
        username.setSingleLine(true);
        fields.addView(username, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT
        ));
        EditText password = new EditText(activity);
        password.setHint("NAS 密碼");
        password.setSingleLine(true);
        password.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_PASSWORD);
        fields.addView(password, new LinearLayout.LayoutParams(
            ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT
        ));

        AlertDialog dialog = new AlertDialog.Builder(activity)
            .setTitle("設定 NAS 直連")
            .setMessage("只需輸入一次。帳密由 Android Keystore 加密保存，不會寫入 APK。")
            .setView(fields)
            .setPositiveButton("儲存", null)
            .setNegativeButton("暫用 Caddy", (ignored, which) -> promptShowing = false)
            .create();
        dialog.setOnShowListener(ignored -> {
            dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(button -> {
                String user = username.getText().toString().trim();
                String pass = password.getText().toString();
                if (user.isEmpty() || pass.isEmpty()) {
                    Toast.makeText(activity, "請輸入 NAS 帳號與密碼", Toast.LENGTH_SHORT).show();
                    return;
                }
                if (!save(user, pass)) {
                    Toast.makeText(activity, "無法安全儲存 NAS 帳密", Toast.LENGTH_LONG).show();
                    return;
                }
                promptShowing = false;
                dialog.dismiss();
                if (onSaved != null) onSaved.run();
            });
            username.requestFocus();
        });
        dialog.setOnCancelListener(ignored -> promptShowing = false);
        dialog.show();
    }

    private SecretKey key() throws Exception {
        KeyStore store = KeyStore.getInstance("AndroidKeyStore");
        store.load(null);
        if (store.containsAlias(keyAlias)) {
            return ((KeyStore.SecretKeyEntry) store.getEntry(keyAlias, null)).getSecretKey();
        }
        KeyGenerator generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore");
        generator.init(new KeyGenParameterSpec.Builder(
            keyAlias, KeyProperties.PURPOSE_ENCRYPT | KeyProperties.PURPOSE_DECRYPT
        ).setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .build());
        return generator.generateKey();
    }

    private boolean save(String username, String password) {
        try {
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.ENCRYPT_MODE, key());
            byte[] encrypted = cipher.doFinal((username + "\n" + password).getBytes(StandardCharsets.UTF_8));
            preferences.edit()
                .putString("iv", Base64.encodeToString(cipher.getIV(), Base64.NO_WRAP))
                .putString("credentials", Base64.encodeToString(encrypted, Base64.NO_WRAP))
                .apply();
            return true;
        } catch (Exception ignored) {
            return false;
        }
    }

    private String[] load() {
        String[] bundled = bundledCredentials(activity);
        if (bundled != null) return bundled;
        try {
            String iv = preferences.getString("iv", "");
            String encrypted = preferences.getString("credentials", "");
            if (iv.isEmpty() || encrypted.isEmpty()) return null;
            Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
            cipher.init(Cipher.DECRYPT_MODE, key(), new GCMParameterSpec(128, Base64.decode(iv, Base64.NO_WRAP)));
            String decoded = new String(
                cipher.doFinal(Base64.decode(encrypted, Base64.NO_WRAP)), StandardCharsets.UTF_8
            );
            int separator = decoded.indexOf('\n');
            return separator > 0
                ? new String[] {decoded.substring(0, separator), decoded.substring(separator + 1)}
                : null;
        } catch (Exception ignored) {
            return null;
        }
    }
}
