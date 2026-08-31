# Portfolio reference prices after a share conversion

On 2026-08-31, 6696 resumed emerging-stock trading after a 1:10 share
conversion. TPEx monthly history still reported the unadjusted 2026-08-19
average of 930.93, while broker quantities and current prices were already
on the new share basis. Multiplying the old reference by the new quantity
created a false daily loss. TradingView also still reported an old-basis
previous close; it was provisional and must not seed portfolio PnL.

Confirmed events are recorded in `config/tw_stock.php` under
`share_conversions`, with their effective trading date and new shares per
old share. `TwStockPriceAdjustmentService` adjusts each raw historical
price only when its date precedes an event that is effective as of the
requested date. Apply it to raw TPEx averages and stored exchange closes,
before calculating returns. Never apply it to already-adjusted Yahoo
history, current broker positions, quotes, or historical portfolio snapshots.

This is a verified event registry, not automatic corporate-action discovery.
Add future events only after checking an exchange/company announcement.
When changing events, invalidate the affected emerging-history cache (the
2026-08-31 fix changes v2 to v3) and rebuild production config cache.
Do not guess a split from a large price change or increased holding quantity.

Verification: compare `/data` and `/quotes` separately, verify raw historical
dates and prices, then check today PnL equals `(current - adjusted reference)
times current shares`. Check the summary sum and rendered row after another
quote refresh. Cover before/effective/after dates, mixed-basis return windows,
unchanged broker valuation, and protection against stale quote baselines.
Keep dashboard tokens and account identifiers out of diagnostic output.

Regression suites:

```text
php artisan test tests/Unit/TwStockPriceAdjustmentServiceTest.php tests/Unit/TwStockEmergingHistoryServiceTest.php tests/Unit/YuantaPortfolioServiceTest.php tests/Unit/EsunPortfolioServiceTest.php tests/Unit/TwStockRealtimeQuoteServiceTest.php tests/Feature/YuantaPortfolioControllerTest.php tests/Feature/EsunPortfolioControllerTest.php
node --test tests/node/portfolio_share_conversion.test.mjs
```
