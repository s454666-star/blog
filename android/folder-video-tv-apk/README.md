# Folder Video TV APK

Android TV WebView wrapper for the local Folder Video LAN app.

- Package: `monster.mystar.foldervideotv`
- Label: `Folder Video TV`
- Version: `2026.07.11.6-tv` / code `6`
- Default URL: `http://10.0.0.25:8090/folder-video-app`
- Android TV: Leanback launcher, landscape, touchscreen not required
- Remote while playing: left `-5s`, right `+5s`, hold for repeated seeking, up next, down previous, center play/pause; seeking shows a progress bar and time for 0.5 seconds after the final key event
- Grid previews use lightweight 5 fps animated WebP files instead of one-frame-per-second sprite strips
- Focused videos prewarm a prioritized two-second-segment HLS stream so full playback and up/down file switching start sooner
- Output: `storage/app/folder-video-tv.apk`

The wrapper checks `/folder-video-app/tv/android-version.json` and only installs the separately signed Folder Video TV APK. Version 2 must be installed once from USB; later TV builds can update automatically.
