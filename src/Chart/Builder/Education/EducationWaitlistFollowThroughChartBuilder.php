<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Charts waitlisted people against whether they were ever given a seat.
 */
class EducationWaitlistFollowThroughChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'waitlist_follow_through';
  protected const WEIGHT = 18;
  protected const TIER = 'key';

  /**
   * Courses with fewer waiting than this are noise on a bar chart.
   */
  protected const MIN_PEOPLE = 2;

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $window = $this->buildRollingWindow();
    $data = $this->eventsMembershipDataService->getWaitlistFollowThrough($window['start'], $window['end']);
    $rows = array_values(array_filter($data['rows'] ?? [], static fn(array $r) => $r['people'] >= self::MIN_PEOPLE));
    $totals = $data['totals'] ?? [];
    if (!$rows || empty($totals['people'])) {
      return NULL;
    }

    $labels = array_column($rows, 'course');
    $never = array_map('intval', array_column($rows, 'never_served'));
    $served = array_map('intval', array_column($rows, 'served'));

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $labels),
        'datasets' => [
          [
            'label' => (string) $this->t('Never got a seat'),
            'data' => $never,
            'backgroundColor' => 'rgba(244,63,94,0.75)',
            'stack' => 'waitlist',
          ],
          [
            'label' => (string) $this->t('Served later'),
            'data' => $served,
            'backgroundColor' => 'rgba(22,163,74,0.65)',
            'stack' => 'waitlist',
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
          'x' => [
            'stacked' => TRUE,
            'beginAtZero' => TRUE,
            'title' => ['display' => TRUE, 'text' => (string) $this->t('People waitlisted')],
          ],
          'y' => ['stacked' => TRUE],
        ],
      ],
    ];

    $summary = (string) $this->t(
      '@never of @people people who asked for a seat never got one.',
      ['@never' => $totals['never_served'], '@people' => $totals['people']]
    );

    return $this->newDefinition(
      (string) $this->t('Waitlist — Who Never Got a Seat'),
      $summary,
      $visualization,
      [
        (string) $this->t('Source: CiviCRM participants on "On waitlist" or "Pending from waitlist" for a Ticketed Workshop that has already run, rolling twelve months, attendee role only.'),
        (string) $this->t('Processing: Counts distinct people, not registrations — somebody who waits on three runs is one person. Served means they were moved into that class, took a later run of the same course, or took any other workshop on or after the date they were waiting for.'),
        (string) $this->t('Definitions: "Pending from waitlist" is a status CiviCRM already counts as a seat, so those people are treated as served without looking for a second registration.'),
        (string) $this->t('Excluded: classes still to come, which have not turned anyone away yet, and courses with only one person waiting. Per-course bars overlap where somebody waited on more than one course, so they sum above the headline total.'),
      ],
    );
  }

}
