<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\DemographicsDataService;

/**
 * Highlights interests for new members over the last three months.
 */
class OutreachRecentInterestChartBuilder extends OutreachChartBuilderBase {

  protected const CHART_ID = 'recent_member_interests';
  protected const WEIGHT = 20;

  public function __construct(
    DemographicsDataService $demographicsDataService,
    protected TimeInterface $time,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($demographicsDataService, $stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $end = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    $start = $end->modify('-3 months');

    $rows = $this->demographicsDataService->getRecentInterestDistribution($start, $end);
    if (empty($rows)) {
      return NULL;
    }

    $labels = array_map(static fn(array $row) => (string) $row['label'], $rows);
    $counts = array_map(static fn(array $row) => (int) $row['count'], $rows);

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => (string) $this->t('Members'),
          'data' => $counts,
          'backgroundColor' => '#10b981',
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('New Member Interests (Last 3 Months)'),
      (string) $this->t('Interest areas selected on member profiles created between @start and @end.', [
        '@start' => $start->format('M j, Y'),
        '@end' => $end->format('M j, Y'),
      ]),
      $visualization,
      [
        (string) $this->t('Source: Default "main" member profiles created in the last 3 months with interest selections (field_member_interest).'),
        (string) $this->t('Processing: Filters to published users, active membership roles, and aggregates distinct members per interest.'),
        (string) $this->t('Definitions: Bins with fewer than two members roll into "Other" to avoid displaying sensitive counts.'),
      ],
    );
  }

}
