# Folder Video TV APK

Android TV WebView wrapper for the local Folder Video LAN app.

- Package: `monster.mystar.foldervideotv`
- Label: `Folder Video TV`
- Version: `2026.07.11.2-tv` / code `2`
- Default URL: `http://10.0.0.25:8090/folder-video-app`
- Android TV: Leanback launcher, landscape, touchscreen not required
- Remote while playing: left `-5s`, right `+5s`, hold for repeated seeking, up next, down previous, center play/pause
- Output: `storage/app/folder-video-tv.apk`

The wrapper checks `/folder-video-app/tv/android-version.json` and only installs the separately signed Folder Video TV APK. Version 2 must be installed once from USB; later TV builds can update automatically.
