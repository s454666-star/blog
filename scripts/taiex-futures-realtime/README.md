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

擴充背景每 30 秒讀取本機健康端點。只在台指期交易時段內，若登入後報價超過
90 秒未更新，會固定建立或重新載入 `TAIFEX:TXF1!` 的專用 TradingView 商品頁，
重新注入橋接與自動連接腳本；一般設定頁或其他 TradingView 分頁不會再被誤當成
可長期抓價的橋接頁。專用頁會設為不可由 Chrome 記憶體節省模式自動丟棄，放在
背景分頁時仍保持行情連線。除了既有 WebSocket 監聽，專用頁也會讀取 TradingView
標示為「即時」的 `TXF1!` 最新價作為頁面備援；價格變動時立即送出，未變動時每
5 秒保活一次，畫面報價時間超過 75 秒就停止送出，避免把凍結畫面冒充成即時價。
背景重載至少間隔 2 分鐘，休市時不會反覆重載頁面。

## Windows 登入排程

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\register_taiex_futures_realtime_task.ps1
```

排程名稱為 `Blog Taiex Futures Realtime Redis`，以隱藏視窗在登入時啟動。
