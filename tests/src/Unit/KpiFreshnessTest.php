<?php

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Service\KpiDataService;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;
use Drupal\makerspace_dashboard\Support\KpiFreshness;
use Drupal\makerspace_dashboard\DashboardSection\OverviewSection;
use Drupal\Core\StringTranslation\TranslationInterface;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that old values cannot masquerade as current goal performance.
 */
class KpiFreshnessTest extends TestCase {

  public function testAgeChangesWithoutRecomputingValue(): void {
    $data = ['members' => ['current' => 800, 'value_source' => 'calculated']];
    $stored = ['computed_at' => 100, 'expires_at' => 200];
    $fresh = KpiFreshness::annotate($data, $stored, 199)['members'];
    $stale = KpiFreshness::annotate($data, $stored, 200)['members'];
    $this->assertTrue(KpiFreshness::canCompare($fresh));
    $this->assertFalse(KpiFreshness::canCompare($stale));
    $this->assertSame(800, $stale['current']);
    $this->assertSame(100, $stale['computed_at']);
  }

  public function testLegacyPayloadDoesNotClaimVerifiedProvenance(): void {
    $value = KpiFreshness::annotate(['kpi' => ['current' => 90]], ['computed_at' => 100, 'expires_at' => 200], 150)['kpi'];
    $this->assertSame('unverified', $value['value_source']);
    $this->assertFalse(KpiFreshness::canCompare($value));
  }

  public function testFallbackUsesSnapshotDateAndDropsCurrentPeriodLabel(): void {
    $service = $this->serviceWithSnapshot();
    $method = new \ReflectionMethod($service, 'buildKpiResult');
    $value = $method->invokeArgs($service, [
      [], [], [], NULL, NULL, '2026-09-09', NULL, 'test', 'number',
      'Source unavailable', NULL, 'September 2026',
    ]);
    $this->assertSame(80.0, $value['current']);
    $this->assertSame('snapshot_fallback', $value['value_source']);
    $this->assertSame('2026-01-01', $value['snapshot_date']);
    $this->assertSame('2026-01-01', $value['last_updated']);
    $this->assertNull($value['current_period_label']);
    $fresh = KpiFreshness::annotate(['test' => $value], ['computed_at' => 100, 'expires_at' => 200], 150)['test'];
    $this->assertFalse(KpiFreshness::canCompare($fresh));
  }

  public function testRealZeroIsNotReplacedBySnapshot(): void {
    $service = $this->serviceWithSnapshot();
    $value = (new \ReflectionMethod($service, 'buildKpiResult'))->invokeArgs($service, [[], [], [], NULL, NULL, NULL, 0, 'test']);
    $this->assertSame(0, $value['current']);
    $this->assertSame('calculated', $value['value_source']);
  }

  public function testKnownDefinitionGapCannotTurnGreenOnRefresh(): void {
    $value = KpiFreshness::annotate(['kpi_first_year_member_retention' => ['current' => 0.9, 'value_source' => 'calculated']], ['computed_at' => 100, 'expires_at' => 200], 150)['kpi_first_year_member_retention'];
    $this->assertNotEmpty($value['quality_note']);
    $this->assertFalse(KpiFreshness::canCompare($value));
  }

  public function testStaleRenderHasDateButNoPerformanceBadgeOrBar(): void {
    $section = (new \ReflectionClass(OverviewSection::class))->newInstanceWithoutConstructor();
    $translation = $this->createMock(TranslationInterface::class);
    $translation->method('translateString')->willReturnCallback(static fn($markup) => $markup->getUntranslatedString());
    $section->setStringTranslation($translation);
    $kpi = ['current' => 90, 'goal_current_year' => 80, 'value_source' => 'calculated', 'refresh_status' => 'stale', 'computed_at' => 100, 'quality_note' => 'Definition under review'];
    $render = (new \ReflectionMethod($section, 'buildCurrentValueCell'))->invoke($section, $kpi, 'number', TRUE);
    $html = (string) $render['#markup'];
    $this->assertStringContainsString('Refresh overdue', $html);
    $this->assertStringContainsString('Definition under review', $html);
    $this->assertStringNotContainsString('kpi-progress--good', $html);
    $this->assertStringNotContainsString('kpi-goal-progress__fill', $html);
  }

  private function serviceWithSnapshot(): KpiDataService {
    $service = (new \ReflectionClass(KpiDataService::class))->newInstanceWithoutConstructor();
    $snapshots = $this->createMock(SnapshotDataService::class);
    $snapshots->method('getKpiMetricSeries')->willReturn([
      ['value' => 80, 'snapshot_date' => new \DateTimeImmutable('2026-01-01'), 'snapshot_type' => 'monthly'],
    ]);
    foreach (['snapshotDataService' => $snapshots, 'sheetGoalCache' => [], 'sheetAnnualTargets' => []] as $property => $value) {
      (new \ReflectionProperty($service, $property))->setValue($service, $value);
    }
    return $service;
  }

}
