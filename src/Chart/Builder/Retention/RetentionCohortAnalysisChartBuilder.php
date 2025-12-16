<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Builds the cohort retention heatmap.
 */
class RetentionCohortAnalysisChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'cohort_analysis_heatmap';
  protected const WEIGHT = 100;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected MembershipMetricsService $membershipMetricsService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    // Show last 24 months by default.
    $monthsBack = 24;
    $matrix = $this->membershipMetricsService->getMonthlyCohortRetentionMatrix($monthsBack);

    if (empty($matrix)) {
      return NULL;
    }

    $html = '<div class="cohort-heatmap-container" style="overflow-x: auto;">';
    $html .= '<table class="cohort-heatmap" style="border-collapse: collapse; font-size: 12px; width: 100%;">';
    
    // Header
    $html .= '<thead><tr>';
    $html .= '<th style="text-align: left; padding: 4px; border-bottom: 1px solid #ccc;">' . $this->t('Cohort') . '</th>';
    $html .= '<th style="text-align: center; padding: 4px; border-bottom: 1px solid #ccc;">' . $this->t('Size') . '</th>';
    for ($i = 0; $i <= $monthsBack; $i++) {
      $html .= '<th style="text-align: center; padding: 4px; border-bottom: 1px solid #ccc; min-width: 30px;">' . $i . '</th>';
    }
    $html .= '</tr></thead>';

    $html .= '<tbody>';
    foreach ($matrix as $row) {
      $html .= '<tr>';
      $html .= '<td style="font-weight: bold; padding: 4px; border-bottom: 1px solid #eee; white-space: nowrap;">' . $row['label'] . '</td>';
      $html .= '<td style="text-align: center; padding: 4px; border-bottom: 1px solid #eee;">' . $row['joined'] . '</td>';
      
      foreach ($row['retention'] as $pct) {
        if ($pct === NULL) {
          $html .= '<td style="background-color: #f9f9f9; border-bottom: 1px solid #eee;"></td>';
        } else {
          $color = $this->getHeatmapColor($pct);
          $textColor = ($pct < 50) ? '#000' : '#000'; // Keep text dark for readability on pastel backgrounds
          $html .= sprintf(
            '<td style="background-color: %s; color: %s; text-align: center; padding: 4px; border-bottom: 1px solid #eee;" title="%s%%">%s%%</td>',
            $color,
            $textColor,
            $pct,
            round($pct)
          );
        }
      }
      // Fill remaining empty cells if any
      $remaining = $monthsBack + 1 - count($row['retention']);
      if ($remaining > 0) {
         $html .= str_repeat('<td style="background-color: #f9f9f9; border-bottom: 1px solid #eee;"></td>', $remaining);
      }

      $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';

    $visualization = [
      'type' => 'markup',
      'markup' => $html,
    ];

    $notes = [
      (string) $this->t('Cohorts are grouped by join month.'),
      (string) $this->t('Columns represent months elapsed since joining (Month 0 = Join Month).'),
      (string) $this->t('Cells show the percentage of the original cohort retained at the end of that month.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Monthly Cohort Retention (Heatmap)'),
      (string) $this->t('Detailed view of member retention over time, grouped by join date.'),
      $visualization,
      $notes
    );
  }

  /**
   * Generates a background color for the retention percentage.
   */
  private function getHeatmapColor(float $pct): string {
    // Gradient: Red (0) -> Yellow (50) -> Green (100)
    // Actually, retention usually starts at 100 (Green) and drops.
    // 90-100: Great (#86efac)
    // 70-90: Good (#bbf7d0)
    // 50-70: Okay (#fde047)
    // 30-50: Warning (#fdba74)
    // 0-30: Bad (#fca5a5)
    
    // Let's use HSL for a smooth gradient from Red (0) to Green (120).
    // But standard retention heatmaps often use Blue shades or just Green-White.
    // Let's stick to Green-Yellow-Red.
    
    // Map 0-100 to Hue 0-120.
    // $hue = ($pct / 100) * 120;
    // return "hsl({$hue}, 70%, 80%)"; // Light pastel
    
    if ($pct >= 95) return '#dcfce7'; // green-100
    if ($pct >= 85) return '#bbf7d0'; // green-200
    if ($pct >= 75) return '#86efac'; // green-300
    if ($pct >= 65) return '#bef264'; // yellow-green
    if ($pct >= 55) return '#fde047'; // yellow-300
    if ($pct >= 45) return '#fdba74'; // orange-300
    if ($pct >= 35) return '#fb923c'; // orange-400
    return '#fca5a5'; // red-300
  }

}
