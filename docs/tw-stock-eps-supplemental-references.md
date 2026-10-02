# EPS 補充研究參考與每週查核

`config/tw_stock_eps_supplemental.php` 維護經人工核對的逐年度參考。每週一 Asia/Taipei 09:00 的既有 `tw-stock:refresh-eps-growth-rankings` 會重新載入設定、取得 FactSet、執行公開來源查核，並把結果保存到新快照 `forecast_audit`。沒有新增排程，也不回寫歷史快照。

## 尖點（8021）目前口徑

2026／2027 中性參考為 8.8／16.8 元，取自 2026-08-18 報導的 8.83／16.83 元並四捨五入。來源是券商研究／媒體交叉參考，不是假設已核實的統一原報，也非 FactSet 或多家平均。選用理由、原始數字、來源日期、人工評估日期、現增與兩種 CB 的股數不確定性均保存在每年 metadata。FactSet 另列比較，不和研究平均。未確定原報是否已計稀釋，不能直接重複折減。

2028 採 2026-10-02 本站產業模型22.0元，非機構或FactSet預測。公式為 `16.8 × {0.70 × [(8000×0.85)/(5750×0.90)] ×1.03 +0.30×1.15} ×1.02 =22.1466` 取整。平均產能、利用率、產品組合、服務成長及淨利率皆為模型假設，完整來源與17／27元敏感度情境保存於2028 metadata。沿用2027股數口徑，不宣稱完全稀釋EPS；兩種纯分母敏感度為21.79／21.21，未計轉股利息回加。

8021以相同三段成長率權重1.8／2.5／1、除以5.3後，在完整樣本中轉百分位，正常納入主表。實際2026模式仍按所有股票一致規則使用H1×2.05。模型沒有獨立分母、強行名次或單獨加分。未來新來源列入待審／比較，不默默覆蓋經人工審核的模型。歷史快照保持原資料。

## 每次週更實際執行的檢查

`TwStockSupplementalEpsReviewService` 查詢鉅亨最新100則8021新聞，篩選研究、EPS、財報、增資與CB公告；查 FinMind 已人工核對季度之後的財報EPS；重讀公司財務資訊頁並比較可見內容雜湊。結果包含實際 checked_at、每個來源的成功／失敗與待審連結，保存於當期 `source_review`，並出現在頁面與既有排程日誌。

新文章標示發布日，財報列標示會計期間，不能混為公告日。來源抓取失敗會顯示部分查核失敗；沒有新核實證據時保留預估及原日期，不更新 assessed_at 或 source_date。來源檢索有限制，不能保證涵蓋所有付費研究。

## 人工採入新研究

1. 查看頁面「查核紀錄與待審來源」及 `storage/logs/tw_stock_eps_growth_rankings.log`。新證據只入待審，不從標題自動猜數值。
2. 每機構每年度採最新版本，辨識轉載及不同平台同一研究，確認年度、單位、基本／稀釋EPS及全年加權股數。Q3財報、現增完成、CB轉股均可能要求重估。
3. 修改設定中相應年度的 reported_eps/value、method、assumptions、references 與原始 source_date；人工研究截止日才更新 reviewed_at。已核對新財報後，更新 reviewed_financial_period。
4. 執行 `php artisan test --filter="TwStockEps|TwStockSupplementalEps"`，再以 `php artisan tw-stock:refresh-eps-growth-rankings --dry-run --audit-output=<path>` 檢查；部署後建立新快照，不回填既有期數。

`selection_policy=reviewed_reference` 是明示的本站中性研究選擇；一般補充來源預設讓機構／FactSet 資料優先。所有來源超過90天仍標為過期，不藉每週查核日期假裝已更新。
