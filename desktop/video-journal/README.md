# 映記影片誌 Windows 桌面版

沿用 `https://blog/video-journal` 的本機服務和資料庫，不另建資料庫、不複製原始影片。需要本機 blog 服務正常執行；程式本身不會安裝或重設 PHP、Caddy 或資料庫。

## 使用

執行桌面的「映記影片誌」。在查詢頁任意位置拖入最多 50 部影片，即以原始檔名自動建立文章並返回查詢，不必按確認。也可點「新增影片」後點拖曳區多選檔案。不同資料夾、中文路徑與已連接的磁碟都會使用 Electron 取得的完整原始路徑；後端仍比對來源檔名、大小及抽樣指紋後整批新增。

來源無法確認或磁碟未連接時，不會直接新增，畫面保留待處理清單。一般瀏覽器依然使用原本的資料夾確認流程。原有封面、標籤、內文、大頭照、字幕和刪除功能共用網站實作。

## 開發與打包

```powershell
npm ci --prefix desktop/video-journal
npm test --prefix desktop/video-journal
npm start --prefix desktop/video-journal
npm run package --prefix desktop/video-journal
& ./desktop/video-journal/install.ps1
```

打包輸出 `dist/FrameJournal-win32-x64/FrameJournal.exe`，需保留同資料夾內全部資源。安裝腳本複製至 `%LOCALAPPDATA%/Programs/FrameJournal` 並建立桌面捷徑。更新前先關閉程式；不需要管理員權限。這是供本機使用的未簽章版本。

`FrameJournal.exe --smoke` 隱藏啟動，僅讀取不匹配的測試搜尋頁，確認原生橋接、網站 DOM 和 Node 隔離；結果寫入 `%APPDATA%/frame-video-journal/smoke-result.json`，不讀取既有文章內容。

## 原生能力範圍

只有 `https://blog/video-journal` 的主框架能將使用者提供的影片 File 轉成路徑；未提供通用磁碟讀取、命令執行或任意 IPC。保留 sandbox、contextIsolation 和 HTTPS 驗證，禁止新視窗、外站導向和 webview；blog 固定解析至 127.0.0.1。

原生路徑使用官方 [webUtils.getPathForFile](https://www.electronjs.org/docs/latest/api/web-utils)；安全設定依 [Electron security](https://www.electronjs.org/docs/latest/tutorial/security)。
