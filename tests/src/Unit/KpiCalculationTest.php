<?php

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Service\FinancialDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;
use Drupal\makerspace_dashboard\Support\KpiFreshness;
use PHPUnit\Framework\TestCase;

/**
 * Corrected KPI paths must not quietly restore legacy snapshot calculations.
 */
class KpiCalculationTest extends TestCase {

  public function testRetentionPoolsCountsAndOmitsIncompatibleSegments(): void {
    $membership = $this->createMock(MembershipMetricsService::class);
    $membership->method('getMonthlyFirstYearRetentionSeries')->willReturn([
      ['total' => 100, 'retained' => 100, 'retention_percent' => 100.0, 'evaluation_date' => '2025-01-31', 'label' => 'Jan 2024'],
      ['total' => 1, 'retained' => 1, 'retention_percent' => 100.0, 'evaluation_date' => '2026-07-31', 'label' => 'Jul 2025'],
      ['total' => 9, 'retained' => 0, 'retention_percent' => 0.0, 'evaluation_date' => '2026-08-31', 'label' => 'Aug 2025'],
    ]);
    $service = (new \ReflectionClass(KpiDataService::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty($service, 'membershipMetricsService'))->setValue($service, $membership);
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getRequestTime')->willReturn(strtotime('2026-09-09'));
    (new \ReflectionProperty($service, 'time'))->setValue($service, $time);
    $result = (new \ReflectionMethod($service, 'getKpiFirstYearMemberRetentionData'))->invoke($service, []);
    $this->assertSame(0.0, $result['current']);
    $this->assertSame(10.0, $result['ttm_12']);
    $this->assertSame(10.0, $result['annual_values']['2026']);
    $this->assertSame('2026-08-31', $result['last_updated']);
    $this->assertArrayNotHasKey('segments', $result);
    $fresh = KpiFreshness::annotate(['kpi_first_year_member_retention' => $result], ['computed_at' => 100, 'expires_at' => 200], 150);
    $this->assertTrue(KpiFreshness::canCompare($fresh['kpi_first_year_member_retention']));
  }

  public function testUnavailableBillingNeverBecomesZeroOrHistoricalSnapshot(): void {
    $financial = $this->createMock(FinancialDataService::class);
    $financial->method('getJoinCohortRevenue')->willThrowException(new \RuntimeException('unavailable'));
    $service = (new \ReflectionClass(KpiDataService::class))->newInstanceWithoutConstructor();
    (new \ReflectionProperty($service, 'financialDataService'))->setValue($service, $financial);
    $result = (new \ReflectionMethod($service, 'getKpiTotalNewRecurringRevenueData'))->invoke($service, ['annual_values' => ['2026' => 5000], 'base_2025' => 1000]);
    $this->assertSame('TBD', $result['current']);
    $this->assertSame('unavailable', $result['value_source']);
    $this->assertNull($result['base_2025']);
    $this->assertNull($result['goal_current_year']);
    $this->assertSame([], $result['annual_values']);
  }

}
