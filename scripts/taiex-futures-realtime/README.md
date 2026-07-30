# 本機台指期 TradingView 即時 Redis worker

這個 worker 使用目前 Chrome 已登入的 TradingView 帳號訂閱
`TAIFEX:TXF1!`，只在台北時間的台指期日盤／夜盤時段內工作。

- 日盤：平日 08:45–13:45
- 夜盤：平日 15:00–翌日 05:00
- Redis key：`tw-futures:realtime:tradingview:TAIFEX:TXF1!`
- Redis TTL：5 秒
- 本機健康檢查：`http://127.0.0.1:18765/health`

worker 還會檢查 TradingView 報價時間及市場狀態。休市、週末、Chrome
登出、token 過期或報價超過 15 秒未更新時，不會續寫；key 會在 5 秒內
自然過期。

## 第一次啟用

1. 在 Chrome 開啟 `chrome://extensions`。
2. 開啟「開發人員模式」。
3. 選擇「載入未封裝項目」。
4. 選擇本目錄下的 `chrome-extension` 資料夾。
5. 保留至少一個 `https://tw.tradingview.com/` 分頁並保持登入。

擴充不會保存 token。它只把頁面產生的短效 token 傳給
`127.0.0.1:18765` 的本機 worker。

## Windows 登入排程

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\scripts\register_taiex_futures_realtime_task.ps1
```

排程名稱為 `Blog Taiex Futures Realtime Redis`，以隱藏視窗在登入時啟動。
