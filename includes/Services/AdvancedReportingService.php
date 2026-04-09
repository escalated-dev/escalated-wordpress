<?php

namespace Jeremykenedy\Escalated\Services;

class AdvancedReportingService
{
    public static function calculatePercentiles(array $values): array
    {
        if (empty($values)) {
            return [];
        }
        sort($values);
        return [
            'p50' => self::percentileValue($values, 50),
            'p75' => self::percentileValue($values, 75),
            'p90' => self::percentileValue($values, 90),
            'p95' => self::percentileValue($values, 95),
            'p99' => self::percentileValue($values, 99),
        ];
    }

    public static function percentileValue(array $sorted, float $p): float
    {
        if (count($sorted) === 1) {
            return round($sorted[0], 2);
        }
        $k = ($p / 100) * (count($sorted) - 1);
        $f = (int) floor($k);
        $c = (int) ceil($k);
        if ($f === $c) {
            return round($sorted[$f], 2);
        }
        return round($sorted[$f] + ($k - $f) * ($sorted[$c] - $sorted[$f]), 2);
    }

    public static function buildDistribution(array $values, string $unit): array
    {
        if (empty($values)) {
            return ['buckets' => [], 'stats' => []];
        }
        sort($values);
        $max = end($values);
        $bucketSize = max((int) ceil($max / 10), 1);
        $buckets = [];
        for ($start = 0; $start <= (int) ceil($max); $start += $bucketSize) {
            $end = $start + $bucketSize;
            $count = count(array_filter($values, fn ($v) => $v >= $start && $v < $end));
            if ($count > 0) {
                $buckets[] = ['range' => "{$start}-{$end}", 'count' => $count];
            }
        }
        return [
            'buckets' => $buckets,
            'stats' => [
                'min' => $values[0],
                'max' => end($values),
                'avg' => round(array_sum($values) / count($values), 2),
                'median' => self::percentileValue($values, 50),
                'count' => count($values),
                'unit' => $unit,
            ],
            'percentiles' => self::calculatePercentiles($values),
        ];
    }

    public static function compositeScore(float $resRate, ?float $avgFrt, ?float $avgRes, ?float $avgCsat): float
    {
        $score = ($resRate / 100) * 30;
        $weights = 30.0;
        if ($avgFrt !== null && $avgFrt > 0) {
            $score += max(1 - $avgFrt / 24, 0) * 25;
            $weights += 25;
        }
        if ($avgRes !== null && $avgRes > 0) {
            $score += max(1 - $avgRes / 72, 0) * 25;
            $weights += 25;
        }
        if ($avgCsat !== null) {
            $score += ($avgCsat / 5) * 20;
            $weights += 20;
        }
        return $weights > 0 ? round(($score / $weights) * 100, 1) : 0;
    }

    public static function dateSeries(\DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $days = min(max((int) $from->diff($to)->days + 1, 1), 90);
        $dates = [];
        for ($i = 0; $i < $days; ++$i) {
            $dates[] = (clone $from)->modify("+{$i} days");
        }
        return $dates;
    }

    public static function calculateChanges(array $current, array $previous): array
    {
        $changes = [];
        foreach (['total_created', 'total_resolved', 'resolution_rate'] as $key) {
            $cur = (float) ($current[$key] ?? 0);
            $prev = (float) ($previous[$key] ?? 0);
            $changes[$key] = $prev == 0 ? ($cur > 0 ? 100.0 : 0.0) : round(($cur - $prev) / $prev * 100, 1);
        }
        return $changes;
    }
}
