# Folder Photo TV APK

Android TV WebView wrapper for the local Folder Photo random wall.

- Package: `monster.mystar.folderphototv`
- Label: `Folder Photo TV`
- Version: `2026.07.23.5-tv` / code `5`
- Default URL: `http://10.0.0.31:8090/folder-photo-app`
- Android TV: Leanback launcher, landscape, touchscreen not required
- Remote: up adds a row, down removes a row, left adds a column, right removes a column
- Output: `storage/app/folder-photo-tv.apk`

The wrapper checks `/folder-photo-app/tv/android-version.json` and only installs the separately signed Folder Photo TV APK. It uses the Caddy static photo route and does not prompt for NAS credentials.
