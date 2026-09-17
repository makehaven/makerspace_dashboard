<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Opens a subject average up into the courses it is made of.
 */
class EducationCourseFillSpreadChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'course_fill_spread';
  protected const WEIGHT = 17;
  protected const TIER = 'key';

  /**
   * A course needs a few runs before its fill rate means anything.
   */
  protected const MIN_RUNS = 2;

  /**
   * How many at each end. The point is the gap, not the full league table.
   */
  protected const ENDS = 6;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->buildRollingWindow();
    $rows = $this->eventsMembershipDataService->getCourseFillSpread(
      $window['start'], $window['end'], self::MIN_RUNS);
    if (count($rows) < 4) {
      return NULL;
    }

    // Ascending by fill, so the weakest are first and the strongest last.
    $weakest = array_slice($rows, 0, self::ENDS);
    $strongest = array_slice($rows, -self::ENDS);
    // Avoid showing the same course at both ends on a short list.
    $seen = [];
    $shown = [];
    foreach (array_merge($weakest, $strongest) as $row) {
      if (isset($seen[$row['course']])) {
        continue;
      }
      $seen[$row['course']] = TRUE;
      $shown[] = $row;
    }

    $labels = [];
    foreach ($shown as $row) {
      $label = $row['course'] . ' (' . $row['runs'] . ' run' . ($row['runs'] === 1 ? '' : 's') . ')';
      if ($row['waitlisted'] > 0) {
        $label .= ' · ' . $row['waitlisted'] . ' waiting';
      }
      $labels[] = $label;
    }

    $fill = array_map('floatval', array_column($shown, 'fill_rate'));
    $colours = array_map(static function (array $row): string {
      if ($row['waitlisted'] > 0 && $row['fill_rate'] >= 70) {
        return 'rgba(22,163,74,0.85)';
      }
      if ($row['fill_rate'] >= 65) {
        return 'rgba(22,163,74,0.55)';
      }
      return $row['fill_rate'] >= 40 ? 'rgba(234,179,8,0.75)' : 'rgba(244,63,94,0.75)';
    }, $shown);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $labels),
        'datasets' => [
          [
            'type' => 'bar',
            'label' => (string) $this->t('Fill rate (%)'),
            'data' => $fill,
            'backgroundColor' => $colours,
            'xAxisID' => 'xRate',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Revenue per run ($)'),
            'data' => array_map('floatval', array_column($shown, 'revenue_per_run')),
            'borderColor' => '#6b7280',
            'backgroundColor' => '#6b7280',
            'fill' => FALSE,
            'pointRadius' => 3,
            'xAxisID' => 'xMoney',
          ],
        ],
      ],
      'options' => [
        'indexAxis' => 'y',
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => ['legend' => ['position' => 'bottom']],
        'scales' => [
          'xRate' => [
            'position' => 'bottom',
            'beginAtZero' => TRUE,
            'max' => 100,
            'title' => ['display' => TRUE, 'text' => (string) $this->t('Fill rate (%)')],
          ],
          'xMoney' => [
            'position' => 'top',
            'beginAtZero' => TRUE,
            'grid' => ['drawOnChartArea' => FALSE],
            'title' => ['display' => TRUE, 'text' => (string) $this->t('Revenue per run ($)')],
          ],
        ],
      ],
    ];

    $empty = array_sum(array_column($shown, 'empty_runs'));
    $summary = (string) $this->t(
      'The weakest and strongest courses by fill. @empty run@s in this view sold no seats at all.',
      ['@empty' => $empty, '@s' => $empty === 1 ? '' : 's']
    );

    return $this->newDefinition(
      (string) $this->t('Course Fill — the Spread Inside a Subject'),
      $summary,
      $visualization,
      [
        (string) $this->t('Source: CiviCRM Ticketed Workshops with a declared capacity, rolling twelve months, live events only, grouped by parent course.'),
        (string) $this->t('Processing: Attendee-role registrations in counted statuses; hosts and volunteers excluded. Capacity clamped at 25. Revenue is deduplicated by contribution, so a group booking counts once rather than once per head.'),
        (string) $this->t('Read with the subject chart above, not instead of it. A subject average is an average of courses that often behave nothing like each other — the fiber areas held both the best-filling course in the programme and a block that sold almost nothing.'),
        (string) $this->t('Excluded: courses with fewer than @n runs in the window, which are too thin to read.', ['@n' => self::MIN_RUNS]),
      ],
    );
  }

}
