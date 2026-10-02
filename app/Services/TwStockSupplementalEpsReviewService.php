<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwStockSupplementalEpsReviewService
{
    /** Collect new evidence for human review; never turn headlines into EPS estimates. */
    public function review(string $code, array $reference, CarbonImmutable $asOf, array $previous = []): array
    {
        $review = ['checked_at' => CarbonImmutable::now('Asia/Taipei')->toIso8601String(),
            'reviewed_through' => $reference['reviewed_at'], 'status' => 'unchanged', 'checks' => [], 'candidates' => []];
        $searchUrl = 'https://ess.api.cnyes.com/ess/api/v1/news/keyword';
        try {
            $data = $this->http()->get($searchUrl, ['q' => $code, 'limit' => 100, 'page' => 1])->throw()->json();
            if (!is_array(data_get($data, 'data.items'))) {
                throw new \RuntimeException('Invalid news response');
            }
            foreach (data_get($data, 'data.items') as $item) {
                $title = html_entity_decode(strip_tags($item['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!str_contains($title, $reference['stock_name'])
                    || !preg_match('/EPS|預估|盈餘|財報|財務報告|現金增資|轉換公司債|轉換價格|認股|股本|法說/iu', $title)
                    || !is_numeric($item['publishAt'] ?? null)) {
                    continue;
                }
                $date = CarbonImmutable::createFromTimestampUTC((int) $item['publishAt'])->setTimezone('Asia/Taipei')->toDateString();
                if ($date > $reference['reviewed_at'] && $date <= $asOf->toDateString()) {
                    $review['candidates'][] = ['title' => $title, 'date' => $date,
                        'date_type' => 'article_publication', 'url' => 'https://news.cnyes.com/news/id/'.(int) $item['newsId']];
                }
            }
            $review['checks']['news'] = ['status' => 'ok', 'url' => $searchUrl.'?q='.$code.'&limit=100&page=1'];
        } catch (Throwable) {
            $review['checks']['news'] = ['status' => 'failed', 'url' => $searchUrl.'?q='.$code];
        }

        $finmindUrl = config('tw_stock.eps_growth_ranking.finmind_url');
        $reviewedPeriod = $reference['reviewed_financial_period'] ?? '2026-06-30';
        $nextPeriod = CarbonImmutable::parse($reviewedPeriod)->addDay()->toDateString();
        try {
            $payload = $this->http()->get($finmindUrl, ['dataset' => 'TaiwanStockFinancialStatements',
                'data_id' => $code, 'start_date' => $nextPeriod, 'end_date' => $asOf->toDateString()])->throw()->json();
            if (!is_array($payload['data'] ?? null)) {
                throw new \RuntimeException('Invalid financial response');
            }
            foreach ($payload['data'] as $row) {
                if (($row['type'] ?? '') === 'EPS' && is_numeric($row['value'] ?? null)
                    && ($row['date'] ?? '') >= $nextPeriod && $row['date'] <= $asOf->toDateString()) {
                    $review['candidates'][] = ['title' => '新增季度財報 EPS '.$row['value'].'，需確認股數及更新年度模型',
                        'date' => $row['date'], 'date_type' => 'financial_period', 'url' => $finmindUrl];
                }
            }
            $review['checks']['financials'] = ['status' => 'ok', 'url' => $finmindUrl, 'reviewed_financial_period' => $reviewedPeriod];
        } catch (Throwable) {
            $review['checks']['financials'] = ['status' => 'failed', 'url' => $finmindUrl];
        }

        $officialUrl = $reference['review_url'];
        try {
            $html = $this->http()->get($officialUrl)->throw()->body();
            // Avoid scripts, navigation tokens and page decoration in the comparison.
            $visible = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/si', '', $html);
            $visible = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($visible), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $hash = hash('sha256', $visible);
            $oldHash = data_get($previous, 'checks.official.sha256');
            if ($oldHash !== null && $oldHash !== $hash) {
                $review['candidates'][] = ['title' => '公司財務資訊頁內容變更，需核對營收、財報及股本公告',
                    'date' => null, 'date_type' => 'detected_change', 'url' => $officialUrl];
            }
            $review['checks']['official'] = ['status' => 'ok', 'url' => $officialUrl, 'sha256' => $hash];
        } catch (Throwable) {
            $review['checks']['official'] = ['status' => 'failed', 'url' => $officialUrl];
        }
        if ($review['candidates'] !== []) {
            $review['status'] = 'needs_review';
        } elseif (collect($review['checks'])->contains(fn ($check) => $check['status'] === 'failed')) {
            $review['status'] = 'partial';
        }
        return $review;
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withUserAgent('Mozilla/5.0 (compatible; mystar.eps-source-review/1.0)')->timeout(25)->retry(2, 300);
    }
}
