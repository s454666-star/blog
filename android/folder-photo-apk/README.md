# Folder Photo Android APK

Native Android WebView wrapper for the local `blog` Folder Photo app.

- Default URL: `http://10.0.0.31:8090/folder-photo-app`
- Fallback URLs: `http://10.0.0.19:8090/folder-photo-app`, `http://10.147.18.155:8090/folder-photo-app`
- Android update metadata: `/folder-photo-app/android-version.json`
- Package: `monster.mystar.folderphoto`
- Version: `2026.07.23.4` / code `4`
- Min SDK: 23
- Target SDK: 34

The Laravel app remains the backend and UI source. Caddy serves `D:\photo` directly at `/folder-photo-media/*`; the APK does not prompt for NAS credentials.
