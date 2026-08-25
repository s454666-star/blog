<?php

namespace App\Services;

class TwStockEpsGrowthScoringService
{
    private const GROWTH_WEIGHTS = [
        'growth_2025_2026' => 2.5,
        'growth_2026_2027' => 2.5,
        'growth_2027_2028' => 1.0,
    ];

    private const TOTAL_WEIGHT = 6.0;

    /**
     * Convert each growth stage to a 0-100 percentile score, apply the
     * 2.5:2.5:1 weights, then rank by the resulting weighted score.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function scoreAndRank(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $percentiles = [];
        foreach (array_keys(self::GROWTH_WEIGHTS) as $field) {
            $percentiles[$field] = $this->percentileScores(array_map(
                fn (array $row): float => (float) $row[$field],
                $rows,
            ));
        }

        foreach ($rows as $index => &$row) {
            $weightedPercentile = 0.0;
            $weightedGrowth = 0.0;
            foreach (self::GROWTH_WEIGHTS as $field => $weight) {
                $weightedPercentile += $percentiles[$field][$index] * $weight;
                $weightedGrowth += (float) $row[$field] * $weight;
            }

            $row['weighted_score'] = round($weightedPercentile / self::TOTAL_WEIGHT, 4);
            $row['_weighted_growth'] = $weightedGrowth;
        }
        unset($row);

        usort($rows, function (array $left, array $right): int {
            $scoreComparison = $right['weighted_score'] <=> $left['weighted_score'];
            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $growthComparison = $right['_weighted_growth'] <=> $left['_weighted_growth'];

            return $growthComparison !== 0
                ? $growthComparison
                : $left['stock_code'] <=> $right['stock_code'];
        });

        foreach ($rows as $index => &$row) {
            $row['rank'] = $index + 1;
            unset($row['_weighted_growth']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Percent-rank with average positions for ties. A single sample receives 100.
     *
     * @param list<float> $values
     * @return list<float>
     */
    private function percentileScores(array $values): array
    {
        $count = count($values);
        if ($count === 1) {
            return [100.0];
        }

        $indexed = [];
        foreach ($values as $index => $value) {
            $indexed[] = ['index' => $index, 'value' => $value];
        }
        usort($indexed, fn (array $left, array $right): int =>
            ($left['value'] <=> $right['value']) ?: ($left['index'] <=> $right['index'])
        );

        $scores = array_fill(0, $count, 0.0);
        for ($start = 0; $start < $count;) {
            $end = $start + 1;
            while ($end < $count && $indexed[$end]['value'] === $indexed[$start]['value']) {
                $end++;
            }

            $averagePosition = ($start + $end - 1) / 2;
            $score = ($averagePosition / ($count - 1)) * 100;
            for ($position = $start; $position < $end; $position++) {
                $scores[$indexed[$position]['index']] = $score;
            }
            $start = $end;
        }

        return $scores;
    }
}
