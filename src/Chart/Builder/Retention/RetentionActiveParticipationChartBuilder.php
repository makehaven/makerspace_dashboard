<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use DateInterval;
use DateTimeImmutable;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;

/**
 * Quarterly door-access participation trend by demographic segment.
 *
 * Shows 8 completed quarters of participation rates for overall membership,
 * BIPOC members, and Female/Non-Binary members on a single line chart.
 */
class RetentionActiveParticipationChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'active_participation_trend';
  protected const WEIGHT = 6;

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected UtilizationDataService $utilizationData,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $quarters = 8;
    $now = new DateTimeImmutable('now');

    [$quarterWindows, $labels] = $this->buildQuarterWindows($now, $quarters);
    if (empty($quarterWindows)) {
      return NULL;
    }

    $overall = [];
    $bipoc = [];
    $femaleNb = [];

    foreach ($quarterWindows as $window) {
      [$start, $end] = $window;
      $startTs = $start->getTimestamp();
      $endTs = $end->getTimestamp();

      $allData = $this->utilizationData->getParticipationSummary($startTs, $endTs, NULL);
      $bipocData = $this->utilizationData->getParticipationSummary($startTs, $endTs, 'bipoc');
      $femaleNbData = $this->utilizationData->getParticipationSummary($startTs, $endTs, 'female_nb');

      $overall[] = round((float) ($allData['rate'] ?? 0) * 100, 1);
      $bipoc[] = round((float) ($bipocData['rate'] ?? 0) * 100, 1);
      $femaleNb[] = round((float) ($femaleNbData['rate'] ?? 0) * 100, 1);
    }

    if (!array_sum($overall)) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'line',
      'data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => (string) $this->t('All members'),
            'data' => $overall,
            'borderColor' => '#2563eb',
            'backgroundColor' => 'rgba(37,99,235,0.12)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 4,
            'borderWidth' => 2,
          ],
          [
            'label' => (string) $this->t('BIPOC members'),
            'data' => $bipoc,
            'borderColor' => '#16a34a',
            'backgroundColor' => 'rgba(22,163,74,0.12)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 4,
            'borderWidth' => 2,
          ],
          [
            'label' => (string) $this->t('Female / Non-Binary'),
            'data' => $femaleNb,
            'borderColor' => '#7c3aed',
            'backgroundColor' => 'rgba(124,58,237,0.12)',
            'fill' => FALSE,
            'tension' => 0.25,
            'pointRadius' => 4,
            'borderWidth' => 2,
          ],
        ],
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'percent',
                'decimals' => 1,
              ]),
            ],
          ],
        ],
        'scales' => [
          'y' => [
            'min' => 0,
            'max' => 100,
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('% who accessed space'),
            ],
          ],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Active Participation Rate'),
      (string) $this->t('Share of members who accessed the space at least once per quarter, by demographic segment.'),
      $visualization,
      [
        (string) $this->t('Source: Door-access logs from access_control_log via UtilizationDataService.'),
        (string) $this->t('Processing: Each bar covers one completed calendar quarter. Members with at least one door-access event count as "active".'),
        (string) $this->t('Denominator: Active member roster at query time for each segment.'),
        (string) $this->t('Definitions: BIPOC = members with non-white racial/ethnic identity; Female/NB = members with female or non-binary gender identity.'),
      ],
    );
  }

  /**
   * Builds an array of [start, end] DateTimeImmutable pairs for completed quarters.
   *
   * Returns the $quarters most recent completed quarters (oldest first) plus
   * a matching labels array.
   *
   * @return array{array<array{DateTimeImmutable, DateTimeImmutable}>, string[]}
   */
  private function buildQuarterWindows(DateTimeImmutable $now, int $quarters): array {
    $month = (int) $now->format('n');
    $year = (int) $now->format('Y');

    // Find the most recently completed quarter end.
    $currentQ = (int) ceil($month / 3);
    $prevQ = $currentQ - 1;
    $prevYear = $year;
    if ($prevQ <= 0) {
      $prevQ = 4;
      $prevYear--;
    }

    $windows = [];
    $labels = [];

    for ($i = $quarters - 1; $i >= 0; $i--) {
      $targetQ = $prevQ - $i;
      $targetYear = $prevYear;
      while ($targetQ <= 0) {
        $targetQ += 4;
        $targetYear--;
      }

      $startMonth = ($targetQ - 1) * 3 + 1;
      $endMonth = $targetQ * 3;
      $lastDay = (int) (new DateTimeImmutable(
        sprintf('%d-%02d-01', $targetYear, $endMonth)
      ))->format('t');

      $start = new DateTimeImmutable(
        sprintf('%d-%02d-01 00:00:00', $targetYear, $startMonth)
      );
      $end = new DateTimeImmutable(
        sprintf('%d-%02d-%02d 23:59:59', $targetYear, $endMonth, $lastDay)
      );

      $windows[] = [$start, $end];
      $labels[] = sprintf('Q%d %d', $targetQ, $targetYear);
    }

    return [$windows, $labels];
  }

}
