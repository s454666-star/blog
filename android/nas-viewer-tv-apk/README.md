# NAS Viewer TV APK

Android TV WebView wrapper for the local read-only NAS Viewer.

- Package: `monster.mystar.nasviewertv`
- Label: `NAS Viewer TV`
- Version: `2026.07.11.5-tv` / code `5`
- Default URL: `http://10.0.0.25:8090/nas-viewer-app`
- Android TV: Leanback launcher, landscape, touchscreen not required
- Viewer remote: up next file, down previous file; unplayable videos are skipped in the requested direction
- Video remote: left `-5s`, right `+5s`, hold for repeated seeking, center play/pause; seeking shows a progress bar and time for 0.5 seconds after the final key event
- Native video is stopped before every file switch, page reload, and app background transition so stale audio cannot continue behind the NAS list
- TV image mode uses a true 100vw x 100vh contain viewport so portrait and landscape images are always fully visible
- Output: `storage/app/nas-viewer-tv.apk`

APK rows can still open Android's installer. The wrapper checks `/nas-viewer-app/tv/android-version.json` and only installs the separately signed NAS Viewer TV APK. Version 2 must be installed once from USB; later TV builds can update automatically.
