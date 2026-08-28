<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>主動式 ETF 操作日報</title>
    <style>
        :root {
            --bg: #111513;
            --bg-warm: #1d1718;
            --panel: rgba(248, 252, 247, 0.92);
            --panel-dark: rgba(21, 27, 24, 0.82);
            --line: rgba(212, 235, 220, 0.22);
            --line-dark: rgba(16, 24, 20, 0.12);
            --text: #f6fbf8;
            --ink: #17201b;
            --muted: #8ea096;
            --muted-dark: #65746b;
            --new: #f2b84b;
            --add: #e05262;
            --reduce: #28aa78;
            --remove: #7b8790;
            --cyan: #58d8d0;
            --glow: rgba(88, 216, 208, 0.24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            position: relative;
            margin: 0;
            min-height: 100vh;
            overflow-x: hidden;
            color: var(--text);
            background:
                linear-gradient(135deg, #101412 0%, #17201a 34%, #21191b 67%, #12191d 100%);
            font-family: "Noto Sans TC", "Segoe UI", Arial, sans-serif;
            letter-spacing: 0;
        }

        body::before {
            position: fixed;
            inset: 0;
            z-index: -2;
            content: "";
            background-image:
                linear-gradient(rgba(88, 216, 208, 0.1) 1px, transparent 1px),
                linear-gradient(90deg, rgba(242, 184, 75, 0.1) 1px, transparent 1px),
                linear-gradient(120deg, transparent 0%, rgba(255, 255, 255, 0.06) 46%, transparent 54%);
            background-size: 54px 54px, 54px 54px, 280px 100%;
            background-position: 0 0, 0 0, -280px 0;
            animation: gridDrift 18s linear infinite, scanBeam 6s ease-in-out infinite;
        }

        body::after {
            position: fixed;
            inset: 0;
            z-index: -1;
            content: "";
            background:
                linear-gradient(180deg, rgba(255, 255, 255, 0.07), transparent 18%, transparent 76%, rgba(40, 170, 120, 0.08)),
                repeating-linear-gradient(90deg, transparent 0 110px, rgba(255, 255, 255, 0.035) 110px 111px);
            pointer-events: none;
        }

        .fancy-cursor-layer {
            display: none !important;
        }

        @keyframes gridDrift {
            from { background-position: 0 0, 0 0, -280px 0; }
            to { background-position: 54px 54px, 54px 54px, 280px 0; }
        }

        @keyframes scanBeam {
            0%, 100% { opacity: 0.72; }
            50% { opacity: 1; }
        }

        .shell {
            width: min(1560px, 100%);
            margin: 0 auto;
            padding: 28px 16px 44px;
        }

        .topbar {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: end;
            margin-bottom: 18px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-height: 28px;
            margin-bottom: 8px;
            padding: 0 10px;
            border: 1px solid rgba(88, 216, 208, 0.34);
            border-radius: 8px;
            color: #bff7ef;
            background: rgba(8, 20, 18, 0.58);
            font-size: 12px;
            font-weight: 800;
        }

        .eyebrow::before {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 14px var(--glow);
            content: "";
        }

        h1 {
            margin: 0;
            font-size: clamp(28px, 4.2vw, 52px);
            line-height: 1.08;
            letter-spacing: 0;
        }

        .meta {
            margin-top: 10px;
            color: #b7c9bf;
            font-size: 14px;
            line-height: 1.55;
        }

        .nav-actions {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 8px;
            max-width: 760px;
        }

        .nav-actions a,
        .submit-button,
        .action-tabs button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border: 1px solid rgba(235, 247, 239, 0.2);
            border-radius: 8px;
            color: #dff4e9;
            background: rgba(10, 20, 17, 0.62);
            text-decoration: none;
            font-size: 13px;
            font-weight: 900;
            box-shadow: 0 10px 28px rgba(0, 0, 0, 0.18);
            transition: transform 180ms ease, border-color 180ms ease, background 180ms ease;
        }

        .nav-actions a {
            padding: 0 13px;
        }

        .nav-actions a:hover,
        .submit-button:hover,
        .action-tabs button:hover {
            border-color: rgba(88, 216, 208, 0.58);
            transform: translateY(-1px);
        }

        .nav-actions a.active,
        .action-tabs button.active {
            border-color: rgba(242, 184, 75, 0.72);
            color: #121611;
            background: linear-gradient(135deg, #f7d06d, #57d5c8);
        }

        .filter-tool,
        .ledger-panel {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            background: rgba(14, 21, 18, 0.76);
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.28);
            backdrop-filter: blur(18px);
        }

        .filter-tool::before,
        .ledger-panel::before {
            position: absolute;
            inset: 0 0 auto;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(88, 216, 208, 0.86), rgba(242, 184, 75, 0.75), transparent);
            content: "";
            animation: glint 4.8s ease-in-out infinite;
        }

        @keyframes glint {
            0%, 100% { transform: translateX(-36%); opacity: 0.4; }
            50% { transform: translateX(36%); opacity: 1; }
        }

        .filter-tool {
            display: grid;
            grid-template-columns: repeat(4, minmax(150px, 1fr)) auto;
            gap: 12px;
            align-items: end;
            margin-bottom: 16px;
            padding: 16px;
        }

        .field {
            min-width: 0;
        }

        .field label {
            display: block;
            margin-bottom: 7px;
            color: #bdd2c7;
            font-size: 12px;
            font-weight: 800;
        }

        .field input,
        .field select {
            width: 100%;
            min-height: 40px;
            padding: 0 12px;
            border: 1px solid rgba(235, 247, 239, 0.18);
            border-radius: 8px;
            color: var(--text);
            background: rgba(7, 13, 11, 0.72);
            font: inherit;
            font-size: 14px;
            outline: none;
        }

        .field input:focus,
        .field select:focus {
            border-color: rgba(88, 216, 208, 0.78);
            box-shadow: 0 0 0 3px rgba(88, 216, 208, 0.13);
        }

        .action-tabs {
            display: flex;
            flex-wrap: wrap;
            grid-column: 1 / -1;
            gap: 8px;
        }

        .action-tabs button {
            min-width: 74px;
            padding: 0 14px;
            cursor: pointer;
        }

        .submit-button {
            min-width: 86px;
            padding: 0 16px;
            cursor: pointer;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(8, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .summary-card,
        .etf-card {
            position: relative;
            display: block;
            overflow: hidden;
            min-width: 0;
            border: 1px solid rgba(246, 251, 248, 0.18);
            border-radius: 8px;
            background: var(--panel);
            color: var(--ink);
            text-decoration: none;
            box-shadow: 0 18px 44px rgba(0, 0, 0, 0.18);
            transition: transform 180ms ease, box-shadow 180ms ease, border-color 180ms ease, background 180ms ease;
        }

        .summary-card:hover,
        .etf-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 24px 54px rgba(0, 0, 0, 0.24);
        }

        .summary-card {
            min-height: 106px;
            padding: 14px;
        }

        .summary-label,
        .etf-kicker {
            color: var(--muted-dark);
            font-size: 12px;
            font-weight: 900;
        }

        .summary-value {
            margin-top: 10px;
            font-size: 26px;
            line-height: 1.05;
            font-weight: 950;
            font-variant-numeric: tabular-nums;
        }

        .summary-note {
            margin-top: 8px;
            color: var(--muted-dark);
            font-size: 12px;
            line-height: 1.45;
        }

        .value-new { color: #946417; }
        .value-add { color: #c63245; }
        .value-reduce { color: #08744f; }
        .value-remove { color: #48545d; }

        .etf-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .etf-card {
            padding: 14px;
        }

        .etf-card.active {
            border-color: rgba(242, 184, 75, 0.96);
            background:
                linear-gradient(135deg, rgba(255, 248, 217, 0.98), rgba(229, 255, 249, 0.95));
            box-shadow: 0 0 0 2px rgba(242, 184, 75, 0.34), 0 28px 70px rgba(88, 216, 208, 0.26);
            transform: translateY(-3px);
        }

        .etf-card:focus-visible {
            outline: 3px solid rgba(88, 216, 208, 0.7);
            outline-offset: 3px;
        }

        .etf-title {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 8px;
            margin-top: 8px;
        }

        .etf-code {
            font-size: 22px;
            font-weight: 950;
            font-variant-numeric: tabular-nums;
        }

        .etf-name {
            min-width: 0;
            color: #526158;
            font-size: 13px;
            font-weight: 800;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .quote-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 6px;
            margin-top: 12px;
        }

        .quote-pill {
            min-height: 50px;
            padding: 7px 8px;
            border: 1px solid rgba(20, 30, 24, 0.08);
            border-radius: 8px;
            background: rgba(245, 249, 246, 0.76);
        }

        .quote-pill span {
            display: block;
            color: var(--muted-dark);
            font-size: 11px;
            font-weight: 900;
        }

        .quote-pill strong {
            display: block;
            margin-top: 4px;
            overflow: hidden;
            color: var(--ink);
            font-size: 15px;
            font-weight: 950;
            text-overflow: ellipsis;
            white-space: normal;
        }

        .quote-sub {
            display: block;
            margin-top: 2px;
            font-size: 12px;
            font-weight: 900;
            line-height: 1.25;
        }

        .etf-meter {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 6px;
            margin-top: 12px;
        }

        .mini-stat {
            min-height: 48px;
            padding: 7px 6px;
            border: 1px solid var(--line-dark);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.62);
            text-align: center;
        }

        .mini-stat span {
            display: block;
            color: var(--muted-dark);
            font-size: 11px;
            font-weight: 900;
        }

        .mini-stat strong {
            display: block;
            margin-top: 5px;
            font-size: 17px;
            font-variant-numeric: tabular-nums;
        }

        .ledger-panel {
            background: rgba(248, 252, 247, 0.96);
            color: var(--ink);
        }

        .market-panel {
            margin-bottom: 16px;
        }

        .market-toggle {
            cursor: pointer;
            list-style-position: inside;
        }

        .market-toggle-label {
            display: inline-block;
            margin-left: 8px;
            color: var(--accent-dark);
            font-weight: 900;
        }

        .market-toggle-label .when-open {
            display: none;
        }

        .market-panel[open] .market-toggle-label .when-closed {
            display: none;
        }

        .market-panel[open] .market-toggle-label .when-open {
            display: inline;
        }

        .ranking-panel {
            margin-bottom: 16px;
        }

        .holdings-panel {
            margin-bottom: 16px;
            scroll-margin-top: 16px;
        }

        .holdings-table {
            min-width: 720px;
        }

        .ranking-period {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
        }

        .ranking-period label {
            font-weight: 900;
        }

        .ranking-period select {
            min-height: 36px;
            padding: 0 32px 0 10px;
            border: 1px solid rgba(20, 30, 24, 0.2);
            border-radius: 8px;
            color: var(--ink);
            background: #ffffff;
            font: inherit;
            font-weight: 850;
            cursor: pointer;
        }

        .ledger-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px;
            border-bottom: 1px solid var(--line-dark);
        }

        .ledger-title {
            margin: 0;
            font-size: 18px;
            line-height: 1.3;
        }

        .ledger-meta {
            color: var(--muted-dark);
            font-size: 12px;
            line-height: 1.5;
            text-align: right;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 1120px;
            border-collapse: collapse;
            table-layout: fixed;
            font-variant-numeric: tabular-nums;
        }

        .market-table {
            min-width: 980px;
        }

        .ranking-table {
            min-width: 760px;
        }

        .rank-number {
            width: 72px;
            color: #8a6400;
            font-size: 18px;
            font-weight: 950;
        }

        .ranking-etfs {
            max-width: 420px;
            overflow: hidden;
            color: var(--muted-dark);
            text-overflow: ellipsis;
        }

        th,
        td {
            padding: 13px 14px;
            border-bottom: 1px solid rgba(20, 30, 24, 0.09);
            text-align: left;
            vertical-align: middle;
            white-space: nowrap;
        }

        th {
            position: sticky;
            top: 0;
            z-index: 1;
            color: #526158;
            background: #eef5f0;
            font-size: 12px;
            font-weight: 950;
        }

        .sort-link {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: inherit;
            text-decoration: none;
        }

        .sort-link:hover {
            color: #0c766f;
        }

        .sort-link.active {
            color: #0b645e;
        }

        .sort-mark {
            color: #92a29a;
            font-size: 10px;
        }

        tbody tr {
            transition: background 140ms ease;
        }

        tbody tr:hover {
            background: #f6faf7;
        }

        .stock-line,
        .etf-line {
            display: flex;
            flex-direction: column;
            gap: 4px;
            min-width: 0;
        }

        .stock-line strong,
        .etf-line strong {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .stock-line span,
        .etf-line span {
            color: var(--muted-dark);
            font-size: 12px;
        }

        .action-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 62px;
            min-height: 30px;
            padding: 0 10px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 950;
        }

        .action-new {
            color: #8a6400;
            background: #fff3bd;
            border: 1px solid #f0cb4f;
        }

        .action-add {
            color: #be263a;
            background: #ffe5e9;
            border: 1px solid #f5a7b0;
        }

        .action-reduce {
            color: #06764e;
            background: #def8ec;
            border: 1px solid #90dfbd;
        }

        .action-remove {
            color: #42505b;
            background: #edf1f4;
            border: 1px solid #c9d2d8;
        }

        .change-lots,
        .amount-main {
            font-size: 18px;
            font-weight: 950;
        }

        .amount-main,
        .amount-sub {
            display: block;
        }

        .amount-sub {
            margin-top: 3px;
            color: var(--muted-dark);
            font-size: 11px;
            font-weight: 850;
        }

        .change-positive { color: #c63245; }
        .change-negative { color: #08744f; }
        .change-neutral { color: #26302a; }

        .numeric-cell {
            text-align: right;
        }

        .mobile-operations,
        .mobile-market,
        .mobile-ranking {
            display: none;
        }

        .operation-card,
        .market-card,
        .ranking-card {
            border: 1px solid var(--line-dark);
            border-radius: 8px;
            background: #ffffff;
            box-shadow: 0 14px 32px rgba(14, 21, 18, 0.08);
        }

        .operation-card-head,
        .market-card-head,
        .ranking-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
            padding: 12px;
            border-bottom: 1px solid rgba(20, 30, 24, 0.08);
        }

        .operation-card-body,
        .market-card-body,
        .ranking-card-body {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 10px;
            align-items: end;
            padding: 12px;
        }

        .market-card-body {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }

        .ranking-card-body {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .mobile-metric span {
            display: block;
            color: var(--muted-dark);
            font-size: 11px;
            font-weight: 900;
        }

        .mobile-metric strong {
            display: block;
            margin-top: 5px;
            font-size: 16px;
            font-weight: 950;
        }

        .operation-metrics {
            display: flex;
            flex-wrap: wrap;
            justify-content: flex-end;
            gap: 10px;
            text-align: right;
        }

        .empty {
            padding: 42px 18px;
            color: var(--muted-dark);
            text-align: center;
            line-height: 1.7;
        }

        @media (max-width: 1280px) {
            .summary-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }

            .etf-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .filter-tool {
                grid-template-columns: repeat(2, minmax(0, 1fr)) auto;
            }
        }

        @media (max-width: 900px) {
            .topbar {
                grid-template-columns: 1fr;
                align-items: start;
            }

            .nav-actions {
                justify-content: flex-start;
                max-width: none;
            }

            .etf-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 720px) {
            .shell {
                width: 100%;
                padding: 18px 8px 44px;
            }

            h1 {
                font-size: 30px;
            }

            .meta {
                font-size: 13px;
            }

            .nav-actions {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                width: 100%;
            }

            .nav-actions a {
                min-width: 0;
                min-height: 36px;
                padding: 0 8px;
                font-size: 12px;
            }

            .filter-tool {
                grid-template-columns: 1fr;
                padding: 12px;
            }

            .submit-button {
                width: 100%;
            }

            .action-tabs {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .action-tabs button {
                min-width: 0;
                padding: 0 8px;
            }

            .summary-grid,
            .etf-grid {
                grid-template-columns: 1fr 1fr;
            }

            .summary-card {
                min-height: 96px;
                padding: 12px;
            }

            .summary-value {
                font-size: 23px;
            }

            .etf-title {
                align-items: flex-start;
                flex-direction: column;
            }

            .etf-meter {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .quote-strip {
                grid-template-columns: 1fr;
            }

            .ledger-head {
                align-items: flex-start;
                flex-direction: column;
                padding: 14px;
            }

            .ledger-meta {
                text-align: left;
            }

            .desktop-ledger {
                display: none;
            }

            .mobile-operations,
            .mobile-market,
            .mobile-ranking {
                display: grid;
                grid-template-columns: 1fr;
                gap: 10px;
                padding: 12px;
            }
        }

        @media (max-width: 430px) {
            .summary-grid,
            .etf-grid,
            .nav-actions {
                grid-template-columns: 1fr;
            }

            .action-tabs {
                grid-template-columns: 1fr 1fr;
            }

            .operation-card-body {
                grid-template-columns: 1fr;
            }

            .market-card-body {
                grid-template-columns: 1fr;
            }

            .ranking-card-body {
                grid-template-columns: 1fr;
            }
        }
@include('tw-stock.partials.shared-shell-width')
    </style>
</head>
<body>
@php
    $formatLots = static function ($value): string {
        if ($value === null) {
            return 'N/A';
        }

        $number = (float) $value;
        $decimals = abs($number) >= 100 ? 0 : 3;
        $formatted = number_format(abs($number), $decimals);

        if ($decimals > 0) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        return ($number > 0 ? '+' : ($number < 0 ? '-' : '')) . $formatted . ' 張';
    };

    $formatPrice = static fn ($value): string => $value === null ? 'N/A' : number_format((float) $value, 2);
    $formatSignedNumber = static function ($value, int $decimals = 2, string $suffix = ''): string {
        if ($value === null) {
            return 'N/A';
        }

        $number = (float) $value;

        return ($number > 0 ? '+' : ($number < 0 ? '-' : '')) . number_format(abs($number), $decimals) . $suffix;
    };
    $formatPercent = static fn ($value): string => $formatSignedNumber($value, 2, '%');
    $formatVolume = static fn ($value): string => $value === null ? 'N/A' : number_format((int) $value);
    $formatTradeValue = static function ($value): string {
        if ($value === null) {
            return 'N/A';
        }

        $number = (int) $value;
        if ($number >= 100000000) {
            return number_format($number / 100000000, 1) . '億';
        }

        if ($number >= 10000) {
            return number_format($number / 10000, 1) . '萬';
        }

        return number_format($number);
    };
    $formatAmountPrice = static function ($price, $date) use ($formatPrice): string {
        if ($price === null) {
            return '缺股價';
        }

        $dateText = $date instanceof \Carbon\CarbonInterface ? $date->toDateString() : (string) $date;

        return '股價 ' . $formatPrice($price) . ($dateText !== '' ? ' / ' . $dateText : '');
    };

    $changeClass = static function ($value): string {
        $number = (float) $value;

        return $number < 0 ? 'change-negative' : ($number > 0 ? 'change-positive' : 'change-neutral');
    };
    $actionClass = static fn (string $action): string => 'action-' . $action;
    $withQuery = static function (array $updates = []): string {
        $query = array_merge(request()->query(), $updates);
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') {
                unset($query[$key]);
            }
        }

        return route('tw-stock.active-etf-operations.index', $query);
    };
    $sortUrl = static function (string $scope, string $field, string $currentSort, string $currentDirection) use ($withQuery): string {
        $sortKey = $scope . '_sort';
        $directionKey = $scope . '_dir';
        $nextDirection = $currentSort === $field && $currentDirection === 'asc' ? 'desc' : 'asc';

        return $withQuery([
            $sortKey => $field,
            $directionKey => $nextDirection,
        ]);
    };
    $sortMark = static function (string $field, string $currentSort, string $currentDirection): string {
        if ($field !== $currentSort) {
            return '↕';
        }

        return $currentDirection === 'asc' ? '↑' : '↓';
    };
@endphp
<main class="shell">
    <header class="topbar">
        <div>
            <div class="eyebrow">Active ETF Desk</div>
            <h1>主動式 ETF 操作日報</h1>
            <div class="meta">
                查詢區間：{{ $from }} ~ {{ $to }}，
                最新報告日：{{ $summary['latest_operation_date'] ?? 'N/A' }}，
                最後抓取：{{ $summary['latest_fetched_at'] ?? 'N/A' }}
            </div>
        </div>
        <nav class="nav-actions" aria-label="台股頁面">
            <a href="{{ route('tw-stock.q1-financial-reports.index') }}">Q1 排名</a>
            <a href="{{ route('tw-stock.annual-comparison.index') }}">年度比較</a>
            <a href="{{ route('tw-stock.daily-prices.index') }}">每日漲幅</a>
            <a href="{{ route('tw-stock.institutional-flows.index') }}">法人資金</a>
            <a href="{{ route('tw-stock.upcoming-dividends.index') }}">除權息</a>
            <a href="{{ route('tw-stock.monthly-revenues.index') }}">月營收</a>
            @include('tw-stock.partials.eps-growth-ranking-nav-link')
            <a class="active" href="{{ route('tw-stock.active-etf-operations.index') }}">主動ETF</a>
            <a href="{{ route('tw-stock.taiex-futures.kline') }}">台指期K線</a>
        </nav>
    </header>

    <form class="filter-tool" method="get" action="{{ route('tw-stock.active-etf-operations.index') }}">
        <input type="hidden" name="market_sort" value="{{ $marketSort }}">
        <input type="hidden" name="market_dir" value="{{ $marketDirection }}">
        <input type="hidden" name="detail_sort" value="{{ $detailSort }}">
        <input type="hidden" name="detail_dir" value="{{ $detailDirection }}">
        <div class="field">
            <label for="from">起始日期</label>
            <input id="from" name="from" type="date" value="{{ $from }}">
        </div>
        <div class="field">
            <label for="to">結束日期</label>
            <input id="to" name="to" type="date" value="{{ $to }}">
        </div>
        <div class="field">
            <label for="etf">ETF</label>
            <select id="etf" name="etf">
                <option value="">全部主動式 ETF</option>
                @foreach ($activeEtfs as $etf)
                    <option value="{{ $etf->stock_code }}" @selected($selectedEtf === $etf->stock_code)>
                        {{ $etf->stock_code }} {{ $etf->stock_name }}{{ $etf->exchange ? ' / ' . $etf->exchange : '' }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="field">
            <label for="q">關鍵字</label>
            <input id="q" name="q" type="search" value="{{ $keyword }}" placeholder="代碼或名稱">
        </div>
        <button class="submit-button" type="submit">套用</button>
        <div class="action-tabs" role="group" aria-label="操作類型">
            @foreach ($actions as $action => $label)
                <button class="{{ $selectedAction === $action ? 'active' : '' }}" type="submit" name="action" value="{{ $action }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </form>

    @if ($selectedEtfModel)
        <section id="etf-detail" class="ledger-panel holdings-panel">
            <div class="ledger-head">
                <div>
                    <h2 class="ledger-title">{{ $selectedEtfModel->stock_code }} {{ $selectedEtfModel->stock_name }} 持股明細</h2>
                    <div class="ledger-meta" style="text-align: left; margin-top: 4px;">
                        持股日：{{ optional($selectedEtfModel->holding_date)->toDateString() ?? 'N/A' }} · 依市值占比由高到低
                    </div>
                </div>
            </div>

            @if ($selectedEtfHoldings->isEmpty())
                <div class="empty">目前尚無這檔 ETF 的持股快照，請等待下一次資料更新。</div>
            @else
                <div class="table-wrap desktop-ledger">
                    <table class="holdings-table">
                        <thead>
                        <tr>
                            <th>排名</th>
                            <th>持股標的</th>
                            <th>商品種類</th>
                            <th class="numeric-cell">市值占比</th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($selectedEtfHoldings as $holding)
                            <tr>
                                <td class="rank-number">#{{ $loop->iteration }}</td>
                                <td>
                                    <div class="stock-line">
                                        <strong>{{ $holding['target_name'] }}</strong>
                                        <span>{{ $holding['target_code'] }}</span>
                                    </div>
                                </td>
                                <td>{{ $holding['asset_type'] ?: 'N/A' }}</td>
                                <td class="numeric-cell"><strong>{{ number_format((float) $holding['market_value_percent'], 2) }}%</strong></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mobile-ranking">
                    @foreach ($selectedEtfHoldings as $holding)
                        <article class="ranking-card">
                            <div class="ranking-card-head">
                                <div class="stock-line">
                                    <strong>#{{ $loop->iteration }} {{ $holding['target_name'] }}</strong>
                                    <span>{{ $holding['target_code'] }}</span>
                                </div>
                                <strong>{{ number_format((float) $holding['market_value_percent'], 2) }}%</strong>
                            </div>
                            <div class="ranking-card-body">
                                <div class="mobile-metric"><span>商品種類</span><strong>{{ $holding['asset_type'] ?: 'N/A' }}</strong></div>
                                <div class="mobile-metric"><span>排序依據</span><strong>市值占比</strong></div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    <section class="ledger-panel ranking-panel">
        <div class="ledger-head">
            <div>
                <h2 class="ledger-title">
                    @if ($selectedEtfModel)
                        {{ $selectedEtfModel->stock_code }} {{ $selectedEtfModel->stock_name }} 最近操作 {{ $rankingTypes[$rankingType] }}
                    @else
                        近期主動 ETF {{ $rankingTypes[$rankingType] }}
                    @endif
                </h2>
                <div class="ledger-meta" style="text-align: left; margin-top: 4px;">
                    {{ $selectedEtfModel ? '單一 ETF' : '全部 ETF 加總' }} · {{ $stockNetRankingFrom ?? 'N/A' }} ~ {{ $stockNetRankingTo ?? 'N/A' }}
                    （實際 {{ number_format($stockNetRankingDateCount) }} 個報告日）· 按各操作日收盤價換算
                    @if ($stockNetRankingIncompleteCount > 0)
                        · {{ number_format($stockNetRankingIncompleteCount) }} 檔缺歷史股價未列入
                    @endif
                </div>
            </div>
            <form class="ranking-period" method="get" action="{{ route('tw-stock.active-etf-operations.index') }}">
                @foreach (request()->except(['ranking_type', 'ranking_days']) as $name => $value)
                    @if (is_scalar($value))
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <label for="ranking_type">排行類型</label>
                <select id="ranking_type" name="ranking_type" onchange="this.form.submit()">
                    @foreach ($rankingTypes as $type => $label)
                        <option value="{{ $type }}" @selected($rankingType === $type)>{{ $label }}</option>
                    @endforeach
                </select>
                <label for="ranking_days">統計區間</label>
                <select id="ranking_days" name="ranking_days" onchange="this.form.submit()">
                    @foreach ($rankingDayOptions as $days)
                        <option value="{{ $days }}" @selected($rankingDays === $days)>最近 {{ $days }} 日</option>
                    @endforeach
                </select>
                <noscript><button class="submit-button" type="submit">套用</button></noscript>
            </form>
        </div>

        @if ($stockNetRanking->isEmpty())
            <div class="empty">這個區間沒有淨{{ $rankingType === 'sell' ? '賣超' : '買超' }}金額大於 0 且歷史股價完整的股票。</div>
        @else
            <div class="table-wrap desktop-ledger">
                <table class="ranking-table">
                    <thead>
                    <tr>
                        <th>排名</th>
                        <th>股票</th>
                        <th class="numeric-cell">淨{{ $rankingType === 'sell' ? '賣超' : '買超' }}金額</th>
                        <th class="numeric-cell">涵蓋 ETF</th>
                        <th>ETF 明細</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($stockNetRanking as $row)
                        <tr>
                            <td class="rank-number">#{{ $row['rank'] }}</td>
                            <td>
                                <div class="stock-line">
                                    <strong>{{ $row['stock_name'] }}</strong>
                                    <span>{{ $row['stock_code'] }}</span>
                                </div>
                            </td>
                            <td class="numeric-cell">
                                <strong class="change-lots {{ $rankingType === 'sell' ? 'change-negative' : 'change-positive' }}" title="NT$ {{ number_format($row['ranking_amount']) }}">
                                    NT$ {{ $formatTradeValue($row['ranking_amount']) }}
                                </strong>
                            </td>
                            <td class="numeric-cell">{{ number_format($row['etf_count']) }} 檔 ETF</td>
                            <td class="ranking-etfs" title="{{ implode('、', $row['etf_codes']) }}">{{ implode('、', $row['etf_codes']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mobile-ranking">
                @foreach ($stockNetRanking as $row)
                    <article class="ranking-card">
                        <div class="ranking-card-head">
                            <div class="stock-line">
                                <strong>#{{ $row['rank'] }} {{ $row['stock_name'] }}</strong>
                                <span>{{ $row['stock_code'] }}</span>
                            </div>
                            <strong class="{{ $rankingType === 'sell' ? 'change-negative' : 'change-positive' }}" title="NT$ {{ number_format($row['ranking_amount']) }}">NT$ {{ $formatTradeValue($row['ranking_amount']) }}</strong>
                        </div>
                        <div class="ranking-card-body">
                            <div class="mobile-metric"><span>涵蓋 ETF</span><strong>{{ number_format($row['etf_count']) }} 檔</strong></div>
                            <div class="mobile-metric"><span>ETF 明細</span><strong>{{ implode('、', $row['etf_codes']) }}</strong></div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>

    @include('tw-stock.partials.active-etf-operation-details')

    @include('tw-stock.partials.active-etf-operation-summary')

    @if ($etfCards->isNotEmpty())
        <section class="etf-grid" aria-label="ETF 操作概況">
            @foreach ($etfCards as $card)
                @php
                    $isFocused = $selectedEtf === $card['etf_code'];
                    $cardUrl = $isFocused
                        ? $withQuery(['etf' => null])
                        : $withQuery(['etf' => $card['etf_code']]) . '#etf-detail';
                @endphp
                <a class="etf-card {{ $isFocused ? 'active' : '' }}" href="{{ $cardUrl }}" @if ($isFocused) aria-current="true" @endif>
                    <div class="etf-kicker">{{ $card['latest_operation_date'] ?? 'N/A' }} · {{ number_format($card['report_count']) }} 份報告</div>
                    <div class="etf-title">
                        <div class="etf-code">{{ $card['etf_code'] }}</div>
                        <div class="etf-name" title="{{ $card['etf_name'] }}">{{ $card['etf_name'] }}</div>
                    </div>
                    <div class="quote-strip" aria-label="行情">
                        <div class="quote-pill">
                            <span>股價</span>
                            <strong>{{ $formatPrice($card['close_price']) }}</strong>
                        </div>
                        <div class="quote-pill">
                            <span>漲跌幅</span>
                            <strong class="{{ $changeClass($card['price_change_amount']) }}">
                                {{ $formatSignedNumber($card['price_change_amount']) }}
                                <span class="quote-sub">{{ $formatPercent($card['price_change_percent']) }}</span>
                            </strong>
                        </div>
                        <div class="quote-pill">
                            <span>成交金額</span>
                            <strong>{{ $formatTradeValue($card['trade_value']) }}</strong>
                        </div>
                    </div>
                    <div class="etf-meter">
                        <div class="mini-stat"><span>新增</span><strong class="value-new">{{ number_format($card['new_count']) }}</strong></div>
                        <div class="mini-stat"><span>加碼</span><strong class="value-add">{{ number_format($card['add_count']) }}</strong></div>
                        <div class="mini-stat"><span>減碼</span><strong class="value-reduce">{{ number_format($card['reduce_count']) }}</strong></div>
                        <div class="mini-stat"><span>刪除</span><strong class="value-remove">{{ number_format($card['remove_count']) }}</strong></div>
                    </div>
                </a>
            @endforeach
        </section>
    @endif

    <details class="ledger-panel market-panel">
        <summary class="ledger-head market-toggle">
            <h2 class="ledger-title">ETF 行情列表</h2>
            <div class="ledger-meta">
                共 {{ number_format($marketEtfs->count()) }} 檔，
                行情日：{{ optional($marketEtfs->firstWhere('quote_date', '!=', null)?->quote_date)->toDateString() ?? 'N/A' }}
                <span class="market-toggle-label">
                    <span class="when-closed">展開</span>
                    <span class="when-open">收合</span>
                </span>
            </div>
        </summary>

        @if ($marketEtfs->isEmpty())
            <div class="empty">目前沒有符合條件的主動式 ETF 行情。</div>
        @else
            <div class="table-wrap desktop-ledger">
                <table class="market-table">
                    <thead>
                    <tr>
                        <th>
                            <a class="sort-link {{ $marketSort === 'stock' ? 'active' : '' }}" href="{{ $sortUrl('market', 'stock', $marketSort, $marketDirection) }}">
                                股票 <span class="sort-mark">{{ $sortMark('stock', $marketSort, $marketDirection) }}</span>
                            </a>
                        </th>
                        <th>
                            <a class="sort-link {{ $marketSort === 'exchange' ? 'active' : '' }}" href="{{ $sortUrl('market', 'exchange', $marketSort, $marketDirection) }}">
                                市場 <span class="sort-mark">{{ $sortMark('exchange', $marketSort, $marketDirection) }}</span>
                            </a>
                        </th>
                        <th class="numeric-cell">
                            <a class="sort-link {{ $marketSort === 'price' ? 'active' : '' }}" href="{{ $sortUrl('market', 'price', $marketSort, $marketDirection) }}">
                                股價 <span class="sort-mark">{{ $sortMark('price', $marketSort, $marketDirection) }}</span>
                            </a>
                        </th>
                        <th class="numeric-cell">
                            <a class="sort-link {{ $marketSort === 'change_percent' ? 'active' : '' }}" href="{{ $sortUrl('market', 'change_percent', $marketSort, $marketDirection) }}">
                                漲跌幅 <span class="sort-mark">{{ $sortMark('change_percent', $marketSort, $marketDirection) }}</span>
                            </a>
                        </th>
                        <th class="numeric-cell">
                            <a class="sort-link {{ $marketSort === 'volume' ? 'active' : '' }}" href="{{ $sortUrl('market', 'volume', $marketSort, $marketDirection) }}">
                                成交量 <span class="sort-mark">{{ $sortMark('volume', $marketSort, $marketDirection) }}</span>
                            </a>
                        </th>
                        <th class="numeric-cell">
                            <a class="sort-link {{ $marketSort === 'trade_value' ? 'active' : '' }}" href="{{ $sortUrl('market', 'trade_value', $marketSort, $marketDirection) }}">
                                成交金額 <span class="sort-mark">{{ $sortMark('trade_value', $marketSort, $marketDirection) }}</span>
                            </a>
                        </th>
                        <th>
                            <a class="sort-link {{ $marketSort === 'quote_date' ? 'active' : '' }}" href="{{ $sortUrl('market', 'quote_date', $marketSort, $marketDirection) }}">
                                行情日 <span class="sort-mark">{{ $sortMark('quote_date', $marketSort, $marketDirection) }}</span>
                            </a>
                        </th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($marketEtfs as $etf)
                        <tr>
                            <td>
                                <div class="etf-line">
                                    <strong>{{ $etf->stock_code }}</strong>
                                    <span>{{ $etf->stock_name }}</span>
                                </div>
                            </td>
                            <td>{{ $etf->exchange ?? 'N/A' }}</td>
                            <td class="numeric-cell">{{ $formatPrice($etf->close_price) }}</td>
                            <td class="numeric-cell">
                                <span class="{{ $changeClass($etf->price_change_amount) }}">
                                    {{ $formatSignedNumber($etf->price_change_amount) }} / {{ $formatPercent($etf->price_change_percent) }}
                                </span>
                            </td>
                            <td class="numeric-cell">{{ $formatVolume($etf->volume_lots) }}</td>
                            <td class="numeric-cell">{{ $formatTradeValue($etf->trade_value) }}</td>
                            <td>{{ $etf->quote_date?->toDateString() ?? 'N/A' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mobile-market">
                @foreach ($marketEtfs as $etf)
                    <article class="market-card">
                        <div class="market-card-head">
                            <div class="etf-line">
                                <strong>{{ $etf->stock_code }} {{ $etf->stock_name }}</strong>
                                <span>{{ $etf->exchange ?? 'N/A' }} · {{ $etf->quote_date?->toDateString() ?? 'N/A' }}</span>
                            </div>
                        </div>
                        <div class="market-card-body">
                            <div class="mobile-metric"><span>股價</span><strong>{{ $formatPrice($etf->close_price) }}</strong></div>
                            <div class="mobile-metric">
                                <span>漲跌幅</span>
                                <strong class="{{ $changeClass($etf->price_change_amount) }}">
                                    {{ $formatSignedNumber($etf->price_change_amount) }} / {{ $formatPercent($etf->price_change_percent) }}
                                </strong>
                            </div>
                            <div class="mobile-metric"><span>成交金額</span><strong>{{ $formatTradeValue($etf->trade_value) }}</strong></div>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </details>

</main>
</body>
</html>
