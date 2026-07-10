# NAS Viewer Android APK

Native Android WebView wrapper for the local `blog` NAS Viewer app.

- Default URL: `http://10.0.0.25:8090/nas-viewer-app`
- Fallback URLs: `http://10.0.0.19:8090/nas-viewer-app`, `http://10.147.18.155:8090/nas-viewer-app`
- Android update metadata: `/nas-viewer-app/android-version.json`
- Package: `monster.mystar.nasviewer`
- Version: `2026.07.10.2` / code `2`
- Min SDK: 23
- Target SDK: 34

Laravel provides a read-only NAS directory API and the list/viewer UI. Caddy serves video and image files directly from the configured `\\mc` shares. Image and video viewers use full-sensor orientation, then restore the user's orientation preference when returning to the list. The Android back button closes the active viewer, then walks back through visited directories before exiting the app.
