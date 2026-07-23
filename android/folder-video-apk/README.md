# Folder Video Android APK

Native Android WebView wrapper for the local `blog` Folder Video app.

- Default URL: `http://10.0.0.31:8090/folder-video-app`
- Fallback URLs: `http://10.0.0.25:8090/folder-video-app`, `http://10.0.0.19:8090/folder-video-app`, `http://10.147.18.155:8090/folder-video-app`
- Android update metadata: `/folder-video-app/android-version.json`
- Package: `monster.mystar.foldervideo`
- Version: `2026.07.23.19` / code `19`
- Min SDK: 23
- Target SDK: 34

The Laravel app remains the backend and UI source. Videos are delivered from `E:\video` through the Caddy static `/video/` endpoint; the APK does not request or store NAS credentials.
