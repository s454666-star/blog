# 本機台指期 TradingView 即時 Redis worker

這個 worker 共用目前 Chrome 已登入的 TradingView 頁面連線來接收
`TAIFEX:TXF1!`，不會再另開第二條登入 WebSocket，因此不會由程式自己
觸發 TradingView 的單一活動會話限制。它只在台北時間的台指期日盤／夜盤時段內工作。

- 日盤：平日 08:45–13:45
- 夜盤：平日 15:00–翌日 05:00
- Redis key：`tw-futures:realtime:tradingview:TAIFEX:TXF1!`
- Redis TTL：5 秒
- 本機健康檢查：`http://127.0.0.1:18765/health`

worker 還會檢查 TradingView 報價時間及市場狀態。休市、週末、Chrome
登出、頁面會話中斷或報價超過 15 秒未更新時，不會續寫；key 會在 5 秒內
自然過期。

## 第一次啟用

1. 在 Chrome 開啟 `chrome://extensions`。
2. 開啟「開發人員模式」。
3. 選擇「載入未封裝項目」。
4. 選擇本目錄下的 `chrome-extension` 資料夾。
5. 保留至少一個 `https://tw.tradingview.com/` 分頁並保持登入。

擴充不會讀取或保存 token。它只把頁面既有行情 WebSocket 收到的
`TXF1!` 報價傳給 `127.0.0.1:18765` 的本機 worker。

當 TradingView 因另一台裝置啟用而顯示「會話中斷」時，擴充會在確認彈窗同時包含
「會話中斷」與「您的會話已結束」後，自動點擊唯一可見的「連接」按鈕。每次自動
重連至少間隔 30 秒；這台 PC 會成為抓價優先會話，其他裝置的行情會話可能被取代。

## Windows 登入排程

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\register_taiex_futures_realtime_task.ps1
```

排程名稱為 `Blog Taiex Futures Realtime Redis`，以隱藏視窗在登入時啟動。
