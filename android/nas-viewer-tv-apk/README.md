# NAS Viewer TV APK

Android TV WebView wrapper for the local read-only NAS Viewer.

- Package: `monster.mystar.nasviewertv`
- Label: `NAS Viewer TV`
- Version: `2026.07.11.1-tv` / code `1`
- Default URL: `http://10.0.0.25:8090/nas-viewer-app`
- Android TV: Leanback launcher, landscape, touchscreen not required
- Viewer remote: up next file, down previous file
- Video remote: left `-5s`, right `+5s`, hold for repeated seeking, center play/pause
- Output: `storage/app/nas-viewer-tv.apk`

APK rows can still open Android's installer. Automatic wrapper updates are disabled so the TV package never installs the phone APK; the web UI still reloads when its server version changes.
