# Folder Video TV APK

Android TV WebView wrapper for the local Folder Video LAN app.

- Package: `monster.mystar.foldervideotv`
- Label: `Folder Video TV`
- Version: `2026.07.23.16-tv` / code `16`
- Default URL: `http://10.0.0.31:8090/folder-video-app`
- Android TV: Leanback launcher, landscape, touchscreen not required
- Remote while playing: left `-5s`, right `+5s`, hold for repeated seeking, up next, down previous, center play/pause; seeking shows a progress bar and time for 0.5 seconds after the final key event
- Grid previews use two parallel workers to build compact 320 x 180, 5 fps animated WebP files, with the TV polling the static asset directly
- Focused videos immediately prewarm a prioritized two-second-segment HLS stream; the TV polls the growing playlist directly instead of waiting on repeated Laravel status calls
- Full playback uses the Caddy static `/video/` URL backed by `E:\video`; incompatible formats use the prewarmed Caddy HLS stream. No NAS credentials are requested or stored.
- Output: `storage/app/folder-video-tv.apk`

The wrapper checks `/folder-video-app/tv/android-version.json` and only installs the separately signed Folder Video TV APK. Version 2 must be installed once from USB; later TV builds can update automatically.
