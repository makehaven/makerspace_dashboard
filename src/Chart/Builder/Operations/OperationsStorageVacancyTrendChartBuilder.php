<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\storage_manager\Service\StatisticsService;

/**
 * Shows how storage vacancy rates change over time.
 */
class OperationsStorageVacancyTrendChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'storage_vacancy_trend';
  protected const WEIGHT = 40;

  protected StatisticsService $statisticsService;

  protected EntityTypeManagerInterface $entityTypeManager;

  protected DateFormatterInterface $dateFormatter;

  protected TimeInterface $time;

  protected \DateTimeZone $timezone;

  public function __construct(StatisticsService $statisticsService, EntityTypeManagerInterface $entityTypeManager, DateFormatterInterface $dateFormatter, TimeInterface $time, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->statisticsService = $statisticsService;
    $this->entityTypeManager = $entityTypeManager;
    $this->dateFormatter = $dateFormatter;
    $this->time = $time;
    $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $stats = $this->statisticsService->getStatistics();
    $totalUnits = (int) ($stats['overall']['total_units'] ?? 0);
    if ($totalUnits <= 0) {
      return NULL;
    }

    $series = $this->buildVacancySeries($totalUnits, 12);
    if (empty($series)) {
      return NULL;
    }

    $labels = [];
    $vacancyRates = [];
    $vacantUnits = [];
    foreach ($series as $point) {
      $labels[] = $point['label'];
      $vacancyRates[] = $point['vacancy_rate'];
      $vacantUnits[] = $point['vacant_units'];
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('Vacancy rate %'),
            'data' => $vacancyRates,
            'borderColor' => '#f97316',
            'backgroundColor' => 'rgba(249,115,22,0.15)',
            'borderWidth' => 3,
            'pointRadius' => 3,
            'pointHoverRadius' => 5,
            'fill' => TRUE,
            'yAxisID' => 'yRate',
          ],
          [
            'label' => (string) $this->t('Vacant units'),
            'data' => $vacantUnits,
            'borderColor' => '#0ea5e9',
            'backgroundColor' => 'rgba(14,165,233,0.2)',
            'borderDash' => [6, 4],
            'pointRadius' => 3,
            'pointHoverRadius' => 4,
            'fill' => FALSE,
            'yAxisID' => 'yUnits',
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'yRate' => [
            'position' => 'left',
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Vacancy %'),
            ],
            'min' => 0,
          ],
          'yUnits' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('# of vacant units'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Storage vacancy trend'),
      (string) $this->t('Shows how many storage units are empty versus the overall vacancy rate each month.'),
      $visualization,
      [
        (string) $this->t('Source: live storage assignments and unit counts from storage_manager.'),
        (string) $this->t('Processing: Counts assignments active within each calendar month.'),
      ],
    );
  }

  /**
   * Builds the vacancy dataset for the requested window.
   */
  protected function buildVacancySeries(int $totalUnits, int $months): array {
    if ($months <= 0) {
      return [];
    }

    $window = $this->getMonthWindow($months);
    $assignments = $this->loadAssignmentWindows($window['start'], $window['end']);
    if (empty($assignments)) {
      return [];
    }

    $series = [];
    for ($i = 0; $i < $months; $i++) {
      $monthStart = $window['start']->modify("+$i months");
      $monthEnd = $monthStart->modify('+1 month');

      $active = 0;
      foreach ($assignments as $assignment) {
        if ($assignment['start'] >= $monthEnd) {
          continue;
        }
        if ($assignment['end'] !== NULL && $assignment['end'] < $monthStart) {
          continue;
        }
        $active++;
      }

      $vacant = max(0, $totalUnits - $active);
      $rate = $totalUnits > 0 ? round(($vacant / $totalUnits) * 100, 1) : 0;
      $series[] = [
        'label' => $this->dateFormatter->format($monthStart->getTimestamp(), 'custom', 'M Y'),
        'vacancy_rate' => $rate,
        'vacant_units' => $vacant,
      ];
    }

    return $series;
  }

  /**
   * Loads assignment date windows intersecting the requested range.
   */
  protected function loadAssignmentWindows(\DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd): array {
    if (!$this->entityTypeManager->hasDefinition('storage_assignment')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('storage_assignment');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'storage_assignment')
      ->condition('field_storage_start_date.value', $windowEnd->format('Y-m-d'), '<=');
    $group = $query->orConditionGroup()
      ->condition('field_storage_end_date.value', $windowStart->format('Y-m-d'), '>=')
      ->notExists('field_storage_end_date.value');
    $query->condition($group);
    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $windows = [];
    foreach ($storage->loadMultiple($ids) as $assignment) {
      if ($assignment->get('field_storage_start_date')->isEmpty()) {
        continue;
      }

      $startRaw = $assignment->get('field_storage_start_date')->value;
      try {
        $start = new \DateTimeImmutable($startRaw, $this->timezone);
      }
      catch (\Exception $e) {
        continue;
      }

      $end = NULL;
      if (!$assignment->get('field_storage_end_date')->isEmpty()) {
        $endRaw = $assignment->get('field_storage_end_date')->value;
        try {
          $end = new \DateTimeImmutable($endRaw, $this->timezone);
        }
        catch (\Exception $e) {
          $end = NULL;
        }
      }

      $windows[] = [
        'start' => $start,
        'end' => $end,
      ];
    }

    return $windows;
  }

  /**
   * Calculates the rolling month window bounds.
   */
  protected function getMonthWindow(int $months): array {
    $anchor = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($this->timezone)
      ->modify('first day of this month')
      ->setTime(0, 0);
    $start = $anchor->modify('-' . ($months - 1) . ' months');
    $end = $start->modify("+$months months");
    return [
      'start' => $start,
      'end' => $end,
    ];
  }

}
