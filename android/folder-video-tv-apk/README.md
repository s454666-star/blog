# Folder Video TV APK

Android TV WebView wrapper for the local Folder Video LAN app.

- Package: `monster.mystar.foldervideotv`
- Label: `Folder Video TV`
- Version: `2026.07.11.5-tv` / code `5`
- Default URL: `http://10.0.0.25:8090/folder-video-app`
- Android TV: Leanback launcher, landscape, touchscreen not required
- Remote while playing: left `-5s`, right `+5s`, hold for repeated seeking, up next, down previous, center play/pause; seeking shows a progress bar and time for 0.5 seconds after the final key event
- Grid previews always show a generated thumbnail and automatically queue missing lightweight previews; four TV-safe preview decoders rotate through the visible grid
- Full playback uses the dedicated high-throughput byte-range service instead of Caddy reading the mapped NAS drive directly
- Output: `storage/app/folder-video-tv.apk`

The wrapper checks `/folder-video-app/tv/android-version.json` and only installs the separately signed Folder Video TV APK. Version 2 must be installed once from USB; later TV builds can update automatically.
