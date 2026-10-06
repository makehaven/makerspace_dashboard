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
    // The headline pools the trailing 12 matured cohorts (1 of 10), not the
    // single latest month (0 of 9), and is a ratio like the goals.
    $this->assertSame(0.1, $result['current']);
    $this->assertSame(0.1, $result['ttm_12']);
    $this->assertSame(0.1, $result['annual_values']['2026']);
    $this->assertSame('2026-08-31', $result['last_updated']);
    $this->assertSame('cohort', $result['period_basis']);
    $this->assertSame('1 of 10 members', $result['sample_note']);
    $this->assertSame('Joined Jul 2025–Aug 2025', $result['current_period_label']);
    $this->assertArrayNotHasKey('segments', $result);
    $fresh = KpiFreshness::annotate(['kpi_first_year_member_retention' => $result], ['computed_at' => 100, 'expires_at' => 200], 150);
    $this->assertTrue(KpiFreshness::canCompare($fresh['kpi_first_year_member_retention']));
  }

  public function testMonthlyCountContextGivesLastQuarterAndYearToDate(): void {
    $service = (new \ReflectionClass(KpiDataService::class))->newInstanceWithoutConstructor();
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getRequestTime')->willReturn(strtotime('2027-06-15'));
    (new \ReflectionProperty($service, 'time'))->setValue($service, $time);
    $method = new \ReflectionMethod($service, 'buildMonthlyCountContext');
    // Oct 2025 .. Sep 2026, one value per month.
    $trend = [10, 20, 30, 40, 50, 60, 70, 80, 90, 47, 50, 64];
    $context = $method->invoke($service, ['trend' => $trend, 'last_updated' => '2026-09-01']);
    $this->assertSame('Jul–Sep 2026', $context['quarter_label']);
    $this->assertSame(161, $context['quarter_value']);
    $this->assertSame('2026 through Sep', $context['ytd_label']);
    $this->assertSame(40 + 50 + 60 + 70 + 80 + 90 + 47 + 50 + 64, $context['ytd_value']);

    // One month into a quarter, the last complete quarter is the one before.
    $context = $method->invoke($service, ['trend' => $trend, 'last_updated' => '2026-10-01']);
    // Trend now ends with October, so the quarter is the three entries before.
    $this->assertSame('Jul–Sep 2026', $context['quarter_label']);
    $this->assertSame(90 + 47 + 50, $context['quarter_value']);

    // In February the last complete quarter is the previous year's Q4.
    $context = $method->invoke($service, ['trend' => $trend, 'last_updated' => '2027-02-01']);
    $this->assertSame('Oct–Dec 2026', $context['quarter_label']);
    $this->assertSame(80 + 90 + 47, $context['quarter_value']);
    $this->assertSame(50 + 64, $context['ytd_value']);
    $this->assertEqualsWithDelta(684.0, $context['ytd_pace'], 0.001);
  }

  public function testMonthlyCountContextSkipsTheMonthInProgress(): void {
    $service = (new \ReflectionClass(KpiDataService::class))->newInstanceWithoutConstructor();
    $time = $this->createMock(\Drupal\Component\Datetime\TimeInterface::class);
    $time->method('getRequestTime')->willReturn(strtotime('2026-10-05 12:00'));
    (new \ReflectionProperty($service, 'time'))->setValue($service, $time);
    // Nov 2025 .. Oct 2026, where October is only five days old.
    $trend = [10, 20, 30, 40, 50, 60, 70, 80, 90, 32, 37, 5];
    $context = (new \ReflectionMethod($service, 'buildMonthlyCountContext'))
      ->invoke($service, ['trend' => $trend, 'last_updated' => '2026-10-05']);
    $this->assertSame('Jul–Sep 2026', $context['quarter_label']);
    $this->assertSame(90 + 32 + 37, $context['quarter_value']);
    $this->assertSame('2026 through Sep', $context['ytd_label']);
    $this->assertSame(30 + 40 + 50 + 60 + 70 + 80 + 90 + 32 + 37, $context['ytd_value']);
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
