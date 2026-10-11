<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Single source of truth for the tw-stock workspace: navigation order, labels,
 * per-page icons (nav + favicon + portal cards), accent colours and the
 * animated background scene each page uses.
 */
class TwStockPages
{
    /**
     * Icon artwork is drawn on a 24x24 grid with `currentColor` strokes so the
     * same paths render in the nav, on portal cards and inside favicons.
     */
    private const PAGES = [
        'financial-reports' => [
            'route' => 'tw-stock.financial-reports.index',
            'match' => ['tw-stock.financial-reports.*', 'tw-stock.q1-financial-reports.*'],
            'label' => '財報排名',
            'title' => '季度財報評分排名',
            'description' => 'Q1 / Q2 財報 EPS、毛利率、營益率、淨利率加權評分，一鍵切換季度。',
            'from' => '#0080ff',
            'to' => '#00c2ff',
            'scene' => 'bars',
            'icon' => '<path d="M7 3h7l4 4v14H7z"/><path d="M14 3v4h4"/><path d="M10 17v-3M13 17v-5M16 17v-2"/>',
        ],
        'annual-comparison' => [
            'route' => 'tw-stock.annual-comparison.index',
            'match' => ['tw-stock.annual-comparison.*'],
            'label' => '年度比較',
            'title' => '年度營收 EPS 比較',
            'description' => '五年營收與 EPS 加權成長、當年度 Q1 動能與估值，一張表看完。',
            'from' => '#2f7bff',
            'to' => '#7a6cff',
            'scene' => 'waves',
            'icon' => '<path d="M4 20h16"/><path d="M6 16V9M10 16V5M14 16v-6M18 16V7"/><path d="M5 8l4-3 4 4 6-5"/>',
        ],
        'daily-prices' => [
            'route' => 'tw-stock.daily-prices.index',
            'match' => ['tw-stock.daily-prices.*'],
            'label' => '每日漲幅',
            'title' => '每日漲幅排行',
            'description' => '盤後漲幅排行、即時報價與分時走勢，快速掃出當日強勢股。',
            'from' => '#ff4d6d',
            'to' => '#2894ff',
            'scene' => 'candles',
            'icon' => '<path d="M7 3v4M7 15v6M17 5v3M17 17v4"/><rect x="5" y="7" width="4" height="8" rx="1"/><rect x="15" y="8" width="4" height="9" rx="1"/>',
        ],
        'institutional-flows' => [
            'route' => 'tw-stock.institutional-flows.index',
            'match' => ['tw-stock.institutional-flows.*'],
            'label' => '法人資金',
            'title' => '法人資金流向',
            'description' => '外資、投信買賣超累計，追蹤資金正在流進哪些股票。',
            'from' => '#0091ff',
            'to' => '#14c8a8',
            'scene' => 'streams',
            'icon' => '<path d="M3 10l9-6 9 6"/><path d="M5 10v8M9.5 10v8M14.5 10v8M19 10v8"/><path d="M3 21h18"/>',
        ],
        'upcoming-dividends' => [
            'route' => 'tw-stock.upcoming-dividends.index',
            'match' => ['tw-stock.upcoming-dividends.*'],
            'label' => '除權息',
            'title' => '近期除權息',
            'description' => '未來 30 天除權息日程、現金股利與殖利率一覽。',
            'from' => '#ffb020',
            'to' => '#2894ff',
            'scene' => 'bubbles',
            'icon' => '<circle cx="12" cy="12" r="8"/><path d="M14.5 9.5c-.5-1-1.5-1.5-2.5-1.5-1.4 0-2.5.8-2.5 2s1.1 1.7 2.5 2 2.5.8 2.5 2-1.1 2-2.5 2c-1 0-2-.5-2.5-1.5M12 6.5v1.5M12 16v1.5"/>',
        ],
        'monthly-revenues' => [
            'route' => 'tw-stock.monthly-revenues.index',
            'match' => ['tw-stock.monthly-revenues.*'],
            'label' => '月營收',
            'title' => '月營收排行',
            'description' => '月營收年增、月增與累計表現排行，抓出營收創高的公司。',
            'from' => '#00b4d8',
            'to' => '#0066ff',
            'scene' => 'tiles',
            'icon' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/><path d="M8 18v-3M12 18v-5M16 18v-2"/>',
        ],
        'eps-growth-rankings' => [
            'route' => 'tw-stock.eps-growth-rankings.index',
            'match' => ['tw-stock.eps-growth-rankings.*'],
            'label' => 'EPS 成長',
            'title' => 'EPS 三年成長排行',
            'description' => '未來三年 EPS 預估成長加權評分，觀察名次變化與資料來源。',
            'from' => '#7a5cff',
            'to' => '#00b2ff',
            'scene' => 'radar',
            'icon' => '<path d="M4 19l5-5 4 3 7-9"/><path d="M15 8h5v5"/><path d="M4 4v16h16"/>',
        ],
        'active-etf-operations' => [
            'route' => 'tw-stock.active-etf-operations.index',
            'match' => ['tw-stock.active-etf-operations.*'],
            'label' => '主動 ETF',
            'title' => '主動式 ETF 操作',
            'description' => '主動式 ETF 每日新增、加碼、減碼與出清明細。',
            'from' => '#00c6ff',
            'to' => '#5a5cff',
            'scene' => 'orbits',
            'icon' => '<circle cx="12" cy="12" r="3"/><ellipse cx="12" cy="12" rx="9" ry="4"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="9" ry="4" transform="rotate(120 12 12)"/>',
        ],
        'taiex-index-kline' => [
            'route' => 'tw-stock.taiex-index.kline',
            'match' => ['tw-stock.taiex-index.*'],
            'label' => '加權指數',
            'title' => '加權指數 K 線',
            'description' => '加權指數日 K、均線與量能分析。',
            'from' => '#0080ff',
            'to' => '#00d4a0',
            'scene' => 'horizon',
            'icon' => '<path d="M3 17l5-6 4 3 5-7 4 4"/><path d="M3 21h18"/><circle cx="17" cy="7" r="1.5"/>',
        ],
        'taiex-futures-kline' => [
            'route' => 'tw-stock.taiex-futures.kline',
            'match' => ['tw-stock.taiex-futures.*'],
            'label' => '台指期',
            'title' => '台指期 15K 差值 K 線',
            'description' => '台指期 15 分 K 與現貨差值走勢。',
            'from' => '#ff7a18',
            'to' => '#ff3d77',
            'scene' => null,
            'icon' => '<path d="M6 3v3M6 14v7M14 3v5M14 16v5"/><rect x="4" y="6" width="4" height="8" rx="1"/><path d="M19 9l-3 5h3l-2 5"/><rect x="12" y="8" width="4" height="8" rx="1"/>',
        ],
    ];

    public const PORTAL = [
        'label' => '首頁',
        'title' => 'Star 台股資訊站',
        'from' => '#0080ff',
        'to' => '#2894ff',
        'scene' => 'constellation',
        'icon' => '<path d="M3 11l9-7 9 7"/><path d="M5 10v10h14V10"/><path d="M9 20v-5h6v5"/>',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::PAGES;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function find(string $key): ?array
    {
        if ($key === 'portal') {
            return self::PORTAL + ['key' => 'portal'];
        }

        return isset(self::PAGES[$key]) ? self::PAGES[$key] + ['key' => $key] : null;
    }

    public static function currentKey(?Request $request = null): ?string
    {
        $request ??= request();

        if ($request->routeIs('tw-stock.portal', 'tw-stock.index', 'stock.home')) {
            return 'portal';
        }

        foreach (self::PAGES as $key => $page) {
            if ($request->routeIs(...$page['match'])) {
                return $key;
            }
        }

        return null;
    }

    public static function iconSvg(string $key, int $size = 18, float $stroke = 1.8): string
    {
        $page = self::find($key);
        if ($page === null) {
            return '';
        }

        return sprintf(
            '<svg class="sx-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="%d" height="%d" fill="none" stroke="currentColor" stroke-width="%s" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            $size,
            $size,
            $stroke,
            $page['icon'],
        );
    }

    /**
     * Rounded-square badge with the page gradient; used for favicons and portal cards.
     */
    public static function badgeSvg(string $key, int $size = 64): string
    {
        $page = self::find($key);
        if ($page === null) {
            return '';
        }

        $id = 'g' . substr(md5($key), 0, 6);

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="%1$d" height="%1$d">'
            . '<defs><linearGradient id="%2$s" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="%3$s"/><stop offset="1" stop-color="%4$s"/></linearGradient>'
            . '<linearGradient id="%2$sh" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#fff" stop-opacity=".45"/><stop offset="1" stop-color="#fff" stop-opacity="0"/></linearGradient></defs>'
            . '<rect x="2" y="2" width="60" height="60" rx="16" fill="url(#%2$s)"/>'
            . '<rect x="6" y="5" width="52" height="24" rx="12" fill="url(#%2$sh)"/>'
            . '<g transform="translate(12 12) scale(1.6667)" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">%5$s</g>'
            . '</svg>',
            $size,
            $id,
            $page['from'],
            $page['to'],
            $page['icon'],
        );
    }

    public static function faviconDataUri(string $key): string
    {
        return 'data:image/svg+xml,' . rawurlencode(self::badgeSvg($key));
    }
}
