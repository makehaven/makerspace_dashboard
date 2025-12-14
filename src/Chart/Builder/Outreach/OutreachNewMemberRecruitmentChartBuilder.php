<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\MembershipMetricsService;

/**
 * Builds the Outreach section New Member Recruitment chart.
 */
class OutreachNewMemberRecruitmentChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'new_member_recruitment';
  protected const WEIGHT = 10;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected MembershipMetricsService $membershipMetrics,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $recruitmentHistory = $this->membershipMetrics->getMonthlyRecruitmentHistory();

    if (empty($recruitmentHistory)) {
      return NULL;
    }

    // Identify and remove current incomplete month.
    $currentDate = new \DateTimeImmutable();
    $currentYear = (int) $currentDate->format('Y');
    $currentMonth = (int) $currentDate->format('n');

    if (isset($recruitmentHistory[$currentYear][$currentMonth])) {
      unset($recruitmentHistory[$currentYear][$currentMonth]);
    }

    $labels = [
      $this->t('Jan'), $this->t('Feb'), $this->t('Mar'), $this->t('Apr'),
      $this->t('May'), $this->t('Jun'), $this->t('Jul'), $this->t('Aug'),
      $this->t('Sep'), $this->t('Oct'), $this->t('Nov'), $this->t('Dec'),
    ];
    $labels = array_map('strval', $labels);

    // Identify last 5 years for average calculation and default visibility.
    $availableYears = array_keys($recruitmentHistory);
    sort($availableYears);
    $last5Years = array_slice($availableYears, -5);
    $displayYears = !empty($last5Years) ? $last5Years : $availableYears;

    // Calculate 5-year average.
    $averageData = [];
    for ($m = 1; $m <= 12; $m++) {
      $sum = 0;
      $count = 0;
      foreach ($last5Years as $year) {
        if (isset($recruitmentHistory[$year][$m])) {
          $sum += $recruitmentHistory[$year][$m];
          $count++;
        }
      }
      $averageData[] = $count > 0 ? round($sum / $count, 1) : NULL;
    }

    $datasets = [];

    // Add Average dataset first.
    $datasets[] = [
      'label' => (string) $this->t('5-Year Average'),
      'data' => $averageData,
      'borderColor' => '#000000',
      'backgroundColor' => '#000000',
      'borderDash' => [5, 5],
      'borderWidth' => 2,
      'fill' => FALSE,
      'tension' => 0.4,
      'pointRadius' => 0,
      'pointHitRadius' => 10,
    ];

    $palette = $this->defaultColorPalette();
    $colorIndex = 0;

    foreach ($displayYears as $year) {
      $months = $recruitmentHistory[$year];
      $data = [];
      for ($m = 1; $m <= 12; $m++) {
        $data[] = $months[$m] ?? NULL;
      }

      // Check if the year has any data to display.
      if (empty(array_filter($data, fn($v) => $v !== NULL))) {
        continue;
      }

      $color = $palette[$colorIndex % count($palette)];
      $colorIndex++;

      $datasets[] = [
        'label' => (string) $year,
        'data' => $data,
        'borderColor' => $color,
        'backgroundColor' => $color,
        'fill' => FALSE,
        'tension' => 0.1,
      ];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'interaction' => [
          'mode' => 'index',
          'intersect' => FALSE,
        ],
        'scales' => [
          'y' => [
            'beginAtZero' => TRUE,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('New Members'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('New Member Recruitment by Year'),
      (string) $this->t('Annual comparison of new member profile creations. The average trend is based on the last 5 years.'),
      $visualization,
      [
        (string) $this->t('Source: Member profile creation dates.'),
        (string) $this->t('Note: The current month is excluded until complete.'),
      ],
    );
  }

}
