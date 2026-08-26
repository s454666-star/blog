<?php

namespace App\Services;

class TwStockEpsGrowthScoringService
{
    private const GROWTH_WEIGHTS = [
        'growth_2025_2026' => 1.8,
        'growth_2026_2027' => 2.5,
        'growth_2027_2028' => 1.0,
    ];

    private const TOTAL_WEIGHT = 5.3;

    /**
     * Apply the 1.8:2.5:1 weights to the raw growth rates first, then convert
     * that composite growth value to a 0-100 percentile score for ranking.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public function scoreAndRank(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $weightedGrowthValues = [];
        foreach ($rows as $index => &$row) {
            $weightedGrowth = 0.0;
            foreach (self::GROWTH_WEIGHTS as $field => $weight) {
                $weightedGrowth += (float) $row[$field] * $weight;
            }

            $row['_weighted_growth'] = $weightedGrowth / self::TOTAL_WEIGHT;
            $weightedGrowthValues[$index] = $row['_weighted_growth'];
        }
        unset($row);

        $percentiles = $this->percentileScores($weightedGrowthValues);
        foreach ($rows as $index => &$row) {
            $row['weighted_score'] = round($percentiles[$index], 4);
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
