<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TwStockEpsForecastSource
{
    /** Parse calendar years, including incomplete/zero/negative observations. */
    public function article(array $article): ?array
    {
        if (!preg_match('/調查：(.+?)\((\d{4})-TW\).*EPS預估/u', (string) ($article['title'] ?? ''), $match)) {
            return null;
        }
        $timestamp = $article['publishAt'] ?? null;
        if (!is_numeric($timestamp) || $timestamp <= 0) {
            return null;
        }
        $date = CarbonImmutable::createFromTimestampUTC((int) $timestamp)->setTimezone('Asia/Taipei')->toDateString();
        $html = (string) ($article['content'] ?? '');
        for ($i = 0; $i < 2; $i++) {
            $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!preg_match('/<table\b[^>]*>(.*?)<\/table>/si', $html, $table)) {
            return null;
        }
        preg_match_all('/<tr\b[^>]*>(.*?)<\/tr>/si', $table[1], $matches);
        $rows = [];
        foreach ($matches[1] as $row) {
            preg_match_all('/<t[dh]\b[^>]*>(.*?)<\/t[dh]>/si', $row, $cells);
            $rows[] = array_map(fn ($cell) => trim(strip_tags($cell)), $cells[1]);
        }
        $median = null;
        foreach ($rows as $row) {
            if (($row[0] ?? '') === '中位數') {
                $median = $row;
                break;
            }
        }
        if ($median === null) {
            return null;
        }
        $observations = [];
        foreach ($rows[0] ?? [] as $index => $header) {
            if (!preg_match('/^(202[678])年/u', $header, $year)) {
                continue;
            }
            $observations[(int) $year[1]] = [
                'value' => $this->number($median[$index] ?? null),
                'source_date' => $date,
                'source_type' => 'factset',
                'date_type' => 'article_publication',
                'source_label' => 'FactSet 中位數（鉅亨新聞）',
                'source_url' => 'https://news.cnyes.com/news/id/'.(int) $article['newsId'],
                // The article's headline count is not necessarily the count for every year.
                'analyst_count' => null,
                'currency' => 'TWD',
                'news_id' => (int) $article['newsId'],
                'priority' => 1,
            ];
        }
        return ['stock_code' => $match[2], 'stock_name' => trim(str_replace("\xEF\xBB\xBF", '', $match[1])), 'years' => $observations];
    }

    /** Never identify a fiscal year by its array position; never use feMean or 2025E as 2025A. */
    public function feed(string $code, array $payload, string $url): array
    {
        if (($payload['statusCode'] ?? 200) !== 200 || !is_array($payload['data'] ?? null)) {
            throw new RuntimeException('FactSet EPS feed 回應格式錯誤：'.$code);
        }
        $years = [];
        foreach ($payload['data'] as $row) {
            $year = (int) ($row['financialYear'] ?? 0);
            if (!in_array($year, [2026, 2027, 2028], true) || (string) ($row['code'] ?? '') !== $code) {
                continue;
            }
            $date = $row['rateDate'] ?? null;
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new RuntimeException('FactSet EPS feed 缺少來源日期：'.$code);
            }
            if (($row['currency'] ?? null) !== 'TWD') {
                throw new RuntimeException('FactSet EPS feed 非新台幣：'.$code);
            }
            $candidate = [
                'value' => is_numeric($row['feMedian'] ?? null) ? (float) $row['feMedian'] : null,
                'source_date' => $date,
                'source_type' => 'factset',
                'date_type' => 'estimate_rate_date',
                'source_label' => 'FactSet 中位數（鉅亨原始資料）',
                'source_url' => $url,
                'analyst_count' => is_numeric($row['numEst'] ?? null) ? (int) $row['numEst'] : null,
                'currency' => 'TWD',
                'news_id' => null,
                'priority' => 2,
            ];
            if (!isset($years[$year]) || $date > $years[$year]['source_date']) {
                $years[$year] = $candidate;
            }
        }
        return $years;
    }

    public function merge(array $existing, array $incoming, CarbonImmutable $asOf): array
    {
        foreach ($incoming as $year => $candidate) {
            $date = $candidate['source_date'] ?? null;
            if (!is_string($date) || $date > $asOf->toDateString()) {
                continue;
            }
            $old = $existing[$year] ?? null;
            if ($old !== null && ($candidate['source_type'] ?? '') === 'site_neutral'
                && ($old['source_type'] ?? '') !== 'site_neutral') {
                continue;
            }
            if ($old !== null && ($candidate['source_type'] ?? '') === 'single_research' && ($old['source_type'] ?? '') === 'factset') {
                continue;
            }
            $sameConsensus = $old !== null && $candidate['value'] !== null
                && $candidate['value'] === $old['value']
                && ($candidate['source_type'] ?? '') === 'factset' && ($old['source_type'] ?? '') === 'factset';
            if ($sameConsensus && ($candidate['priority'] ?? 0) !== ($old['priority'] ?? 0)) {
                // A news republication of the same value must not refresh the underlying rateDate.
                $existing[$year] = ($candidate['priority'] ?? 0) > ($old['priority'] ?? 0) ? $candidate : $old;
                continue;
            }
            $hasSourceDifference = $old !== null && $candidate['value'] !== $old['value']
                && ($candidate['source_type'] ?? '') === 'factset' && ($old['source_type'] ?? '') === 'factset'
                && ($candidate['priority'] ?? 0) !== ($old['priority'] ?? 0);
            if ($old === null || (($candidate['source_type'] ?? '') === 'factset' && in_array($old['source_type'] ?? '', ['single_research', 'site_neutral'], true))
                || (($candidate['source_type'] ?? '') === 'single_research' && ($old['source_type'] ?? '') === 'site_neutral') || $date > $old['source_date']
                || ($date === $old['source_date'] && ($candidate['priority'] ?? 0) > ($old['priority'] ?? 0))) {
                // A newer explicit null is meaningful: do not resurrect an older numeric estimate.
                $existing[$year] = $candidate;
                $alternative = $old;
            } else {
                $alternative = $candidate;
            }
            if ($hasSourceDifference) {
                unset($alternative['alternate_source'], $alternative['priority']);
                $existing[$year]['alternate_source'] = $alternative;
            }
        }
        return $existing;
    }

    public function latest(array $articles, array $universe, CarbonImmutable $asOf, array $previousReviews = []): array
    {
        $observations = [];
        foreach ($articles as $article) {
            $parsed = $this->article($article);
            if ($parsed === null) {
                continue;
            }
            $code = $parsed['stock_code'];
            $universe[$code] = $parsed['stock_name'];
            $observations[$code] = $this->merge($observations[$code] ?? [], $parsed['years'], $asOf);
        }
        foreach (config('tw_stock.eps_growth_ranking.manual_neutral_forecasts', []) as $code => $reference) {
            $universe[(string) $code] = $reference['stock_name'] ?? (string) $code;
            foreach ([2026, 2027] as $year) {
                if (!is_numeric($reference['eps_'.$year] ?? null)) {
                    continue;
                }
                $candidate = [
                    'value' => (float) $reference['eps_'.$year],
                    'source_date' => $reference['forecast_date'],
                    'source_type' => 'single_research',
                    'source_label' => $reference['source_label'].'（單一研究來源）',
                    'source_url' => $reference['source_url'],
                    'analyst_count' => $reference['analyst_count'] ?? 1,
                    'currency' => 'TWD', 'news_id' => null, 'priority' => 0,
                ];
                $observations[$code] = $this->merge($observations[$code] ?? [], [$year => $candidate], $asOf);
            }
        }
        // Explicit dated references are loaded on every scheduled refresh, not copied from a prior snapshot.
        foreach (config('tw_stock_eps_supplemental.stocks', []) as $code => $reference) {
            $universe[(string) $code] = $reference['stock_name'];
            foreach ($reference['years'] as $year => $source) {
                if (!in_array((int) $year, [2026, 2027, 2028], true)
                    || !in_array($source['source_type'] ?? '', ['single_research', 'site_neutral'], true)
                    || !is_numeric($source['value'] ?? null)) {
                    throw new RuntimeException('補充 EPS 來源設定錯誤：'.$code.' / '.$year);
                }
                $candidate = [...$source, 'value' => (float) $source['value'],
                    'analyst_count' => null, 'currency' => 'TWD', 'news_id' => null, 'priority' => 0];
                $observations[$code] = $this->merge($observations[$code] ?? [], [(int) $year => $candidate], $asOf);
            }
        }
        $forecasts = [];
        foreach ($universe as $code => $name) {
            $code = (string) $code;
            $url = str_replace('{code}', $code, config('tw_stock.eps_growth_ranking.factset_eps_url'));
            $payload = Http::acceptJson()->timeout(25)->retry(2, 300)->get($url)->throw()->json();
            $years = $this->merge($observations[$code] ?? [], $this->feed($code, $payload, $url), $asOf);
            $reference = config('tw_stock_eps_supplemental.stocks.'.$code, []);
            if (($reference['selection_policy'] ?? '') === 'reviewed_reference') {
                foreach ($reference['years'] as $year => $source) {
                    if ($source['source_date'] > $asOf->toDateString()) { continue; }
                    $alternative = $years[$year] ?? null;
                    $years[$year] = [...$source, 'value' => (float) $source['value'],
                        'analyst_count' => null, 'currency' => 'TWD', 'news_id' => null];
                    if ($alternative !== null && $alternative['source_type'] !== 'site_neutral') {
                        unset($alternative['priority']);
                        $years[$year]['alternate_source'] = $alternative;
                    }
                }
            }
            $sourceReview = isset($reference['review_url'])
                ? app(TwStockSupplementalEpsReviewService::class)->review($code, $reference, $asOf, $previousReviews[$code] ?? [])
                : null;
            $dates = [];
            foreach ([2026, 2027, 2028] as $year) {
                $observation = $years[$year] ?? ['value' => null, 'source_date' => null, 'source_type' => null, 'source_label' => null, 'source_url' => null, 'analyst_count' => null, 'currency' => 'TWD'];
                $age = $observation['source_date'] === null ? null : (int) CarbonImmutable::parse($observation['source_date'])->diffInDays($asOf);
                $observation['status'] = $observation['value'] === null ? 'missing'
                    : ($age > (int) config('tw_stock.eps_growth_ranking.max_source_age_days', 90) ? 'stale' : 'current');
                if ($observation['source_date'] !== null) {
                    $dates[] = $observation['source_date'];
                }
                unset($observation['priority']);
                $years[$year] = $observation;
            }
            $forecasts[$code] = [
                'stock_code' => $code, 'stock_name' => $name,
                'eps_2026' => $years[2026]['value'], 'eps_2027' => $years[2027]['value'], 'eps_2028' => $years[2028]['value'],
                'forecast_metadata' => $years,
                'source_review' => $sourceReview,
                'forecast_date' => $dates === [] ? null : max($dates),
                'news_id' => null, 'analyst_count' => null,
                'is_neutral_estimate' => count(array_filter($years, fn ($source) => ($source['source_type'] ?? null) === 'site_neutral')) > 0,
                'revenue_2026_thousands' => null, 'revenue_2027_thousands' => null, 'revenue_2028_thousands' => null,
            ];
        }
        return $forecasts;
    }

    public function number(mixed $raw): ?float
    {
        $text = str_replace(',', '', trim((string) $raw));
        // News cells may include the previous value in parentheses. Reject units/garbage.
        return preg_match('/^(-?\d+(?:\.\d+)?)(?:\(-?\d+(?:\.\d+)?\))?$/', $text, $m) ? (float) $m[1] : null;
    }
}
