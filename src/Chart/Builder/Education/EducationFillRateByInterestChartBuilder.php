<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Charts workshop fill rate against offered capacity, by area of interest.
 */
class EducationFillRateByInterestChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'fill_rate_by_interest';
  protected const WEIGHT = 16;
  protected const TIER = 'key';

  /**
   * Rows below this many seats are too thin to read as a trend.
   */
  protected const MIN_CAPACITY = 40;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->buildRollingWindow();
    $rows = $this->eventsMembershipDataService->getFillRateByInterestArea($window['start'], $window['end']);
    $rows = array_values(array_filter($rows, static fn(array $r) => $r['capacity'] >= self::MIN_CAPACITY));
    if (count($rows) < 2) {
      return NULL;
    }

    $labels = array_column($rows, 'interest');
    $fillRates = array_map('floatval', array_column($rows, 'fill_rate'));
    $capacity = array_map('intval', array_column($rows, 'capacity'));
    $unsold = [];
    foreach ($rows as $row) {
      $unsold[] = max(0, (int) $row['capacity'] - (int) $row['seats_filled']);
    }

    // Colour the bar by how it reads, so the chart survives being glanced at.
    $colours = array_map(static function (float $rate): string {
      if ($rate >= 65) {
        return 'rgba(22,163,74,0.75)';
      }
      return $rate >= 50 ? 'rgba(234,179,8,0.75)' : 'rgba(244,63,94,0.75)';
    }, $fillRates);

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
            'data' => $fillRates,
            'backgroundColor' => $colours,
            'xAxisID' => 'xRate',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Seats offered'),
            'data' => $capacity,
            'borderColor' => '#6b7280',
            'backgroundColor' => '#6b7280',
            'borderDash' => [6, 4],
            'fill' => FALSE,
            'pointRadius' => 3,
            'xAxisID' => 'xSeats',
          ],
          [
            'type' => 'line',
            'label' => (string) $this->t('Seats unsold'),
            'data' => $unsold,
            'borderColor' => '#f97316',
            'backgroundColor' => '#f97316',
            'fill' => FALSE,
            'pointRadius' => 3,
            'xAxisID' => 'xSeats',
          ],
        ],
      ],
      'options' => [
        'indexAxis' => 'y',
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
        ],
        'scales' => [
          'xRate' => [
            'position' => 'bottom',
            'beginAtZero' => TRUE,
            'max' => 100,
            'title' => ['display' => TRUE, 'text' => (string) $this->t('Fill rate (%)')],
          ],
          'xSeats' => [
            'position' => 'top',
            'beginAtZero' => TRUE,
            'grid' => ['drawOnChartArea' => FALSE],
            'title' => ['display' => TRUE, 'text' => (string) $this->t('Seats')],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Workshop Fill Rate by Area of Interest'),
      (string) $this->t('Which subjects sell the seats they are offered, and where the unsold seats are.'),
      $visualization,
      [
        (string) $this->t('Source: CiviCRM events of type "Ticketed Workshop" carrying an area-of-interest term, rolling twelve months, live events only.'),
        (string) $this->t('Processing: Seats filled counts attendee-role registrations in counted statuses; hosts, volunteers, waitlist and cancellations are excluded. Capacity is clamped at 25 so a placeholder cap such as 99 cannot swamp a subject on one run.'),
        (string) $this->t('Definitions: Areas are the top-level parent terms, so a sub-interest rolls up. A class can carry more than one area and contributes its seats to each, so rows overlap and must not be added together.'),
        (string) $this->t('Excluded: areas offering fewer than 40 seats in the window, which are too thin to read as a trend.'),
        (string) $this->t('Do not act on this chart alone. A subject average is an average of courses that behave nothing like each other: the fiber areas hold both the best-filling course in the programme, with the largest waitlist, and a block of runs that sold almost nothing. Open the course chart below before moving capacity between subjects.'),
      ],
    );
  }

}
