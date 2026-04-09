<?php

use Escalated\Services\AdvancedReportingService;
use PHPUnit\Framework\TestCase;

class Test_Advanced_Reporting extends TestCase
{
    public function test_percentiles_calculation()
    {
        $result = AdvancedReportingService::calculatePercentiles([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
        $this->assertEquals(5.5, $result['p50']);
        $this->assertArrayHasKey('p75', $result);
        $this->assertArrayHasKey('p90', $result);
        $this->assertArrayHasKey('p95', $result);
        $this->assertArrayHasKey('p99', $result);
    }

    public function test_percentiles_empty()
    {
        $this->assertEmpty(AdvancedReportingService::calculatePercentiles([]));
    }

    public function test_distribution()
    {
        $result = AdvancedReportingService::buildDistribution([1, 2, 3, 4, 5], 'hours');
        $this->assertNotEmpty($result['buckets']);
        $this->assertEquals(5, $result['stats']['count']);
    }

    public function test_distribution_empty()
    {
        $result = AdvancedReportingService::buildDistribution([], 'hours');
        $this->assertEmpty($result['buckets']);
    }

    public function test_composite_score()
    {
        $score = AdvancedReportingService::compositeScore(80, 2.0, 24.0, 4.5);
        $this->assertGreaterThan(0, $score);
    }

    public function test_date_series()
    {
        $from = new \DateTime('2024-01-01');
        $to = new \DateTime('2024-01-10');
        $dates = AdvancedReportingService::dateSeries($from, $to);
        $this->assertCount(10, $dates);
    }

    public function test_calculate_changes()
    {
        $current = ['total_created' => 100, 'total_resolved' => 80, 'resolution_rate' => 80];
        $previous = ['total_created' => 50, 'total_resolved' => 40, 'resolution_rate' => 80];
        $changes = AdvancedReportingService::calculateChanges($current, $previous);
        $this->assertEquals(100, $changes['total_created']);
    }
}
