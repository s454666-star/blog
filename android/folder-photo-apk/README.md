# Folder Photo Android APK

Native Android WebView wrapper for the local `blog` Folder Photo app.

- Default URL: `http://10.0.0.25:8090/folder-photo-app`
- Fallback URLs: `http://10.0.0.19:8090/folder-photo-app`, `http://10.147.18.155:8090/folder-photo-app`
- Android update metadata: `/folder-photo-app/android-version.json`
- Package: `monster.mystar.folderphoto`
- Version: `2026.07.10.1` / code `1`
- Min SDK: 23
- Target SDK: 34

The Laravel app remains the backend and UI source. It recursively reads `\\mc\photo`; the APK exists so Android can install and launch the random photo wall like a normal app.
