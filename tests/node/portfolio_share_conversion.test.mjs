import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/tw-stock/esun-portfolio.blade.php', import.meta.url), 'utf8');
const functions = [
    'number', 'finiteNumber', 'applyQuotePayloadToRows', 'quoteCanRepriceRow',
    'quoteCanUpdatePnl', 'rowLooksParkedAtPreviousClose', 'quotePreviousCloseMatchesRow',
    'applyQuoteToRow', 'applyStaleQuoteToRow', 'applyPreviousCloseToRow',
].map(name => {
    const start = view.indexOf(`function ${name}(`);
    assert.ok(start >= 0, `${name} exists in the shared dashboard`);
    return view.slice(start, view.indexOf('\nfunction ', start + 1));
}).join('\n');

function applyQuote(quote) {
    const context = vm.createContext({ state: { lastPayload: {} } });
    vm.runInContext(functions, context);
    return context.applyQuotePayloadToRows([{
        stockNo: '6696', quantity: 600, currentPrice: 100.5, previousClose: 93.093,
        esunCurrentPrice: 100.5, todayPnl: 4444.2, esunTodayPnl: 4444.2,
        marketValue: 60300, unrealizedPnl: 15496, costBasis: 44539,
    }], { quotes: { '6696': quote } });
}

test('provisional old-basis quotes cannot corrupt converted holding PnL', () => {
    const result = applyQuote({ price: 99.7, previousClose: 939, priceType: 'provisional' });
    assert.equal(result.changed, false);
    assert.equal(result.rows[0].previousClose, 93.093);
    assert.equal(result.rows[0].todayPnl, 4444.2);
});

test('confirmed old-basis quotes cannot replace the corrected reference price', () => {
    for (const price of [99.7, 100.5]) {
        const result = applyQuote({ price, previousClose: 939, priceType: 'last' });
        assert.equal(result.rows[0].previousClose, 93.093);
        assert.ok(Math.abs(result.rows[0].todayPnl - 4444.2) < 0.000001);
    }
});

test('confirmed quotes on the new share basis continue updating PnL', () => {
    const result = applyQuote({ price: 101, previousClose: 93.093, priceType: 'last' });
    assert.equal(result.changed, true);
    assert.ok(Math.abs(result.rows[0].todayPnl - 4744.2) < 0.000001);
    assert.equal(result.rows[0].unrealizedPnl, 15796);
});
