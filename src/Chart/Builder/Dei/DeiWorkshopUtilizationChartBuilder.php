<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Dei;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\EventsMembershipDataService;
use Drupal\makerspace_dashboard\Support\RangeSelectionTrait;

/**
 * Visualizes workshop participant demographics by gender, ethnicity, and age.
 */
class DeiWorkshopUtilizationChartBuilder extends ChartBuilderBase {

  use RangeSelectionTrait;

  protected const SECTION_ID = 'dei';
  protected const CHART_ID = 'workshop_utilization';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y', 'all'];
  protected const RANGE_DEFAULT = '1y';

  public function __construct(
    protected EventsMembershipDataService $eventsMembershipDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $rangeKey = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($rangeKey, new \DateTimeImmutable('now'));
    $start = $bounds['start'] ?? $bounds['end']->modify('-1 year');
    $demographics = $this->eventsMembershipDataService->getParticipantDemographics($start, $bounds['end']);

    if (empty($demographics)) {
      return NULL;
    }

    $charts = [];
    if (!empty($demographics['gender']['labels'])) {
      $charts['gender'] = $this->buildUtilizationChart(
        $demographics['gender']['labels'],
        $demographics['gender']['workshop'],
        $demographics['gender']['other'],
        (string) $this->t('Workshop utilization by gender')
      );
    }
    if (!empty($demographics['ethnicity']['labels'])) {
      $charts['ethnicity'] = $this->buildUtilizationChart(
        $demographics['ethnicity']['labels'],
        $demographics['ethnicity']['workshop'],
        $demographics['ethnicity']['other'],
        (string) $this->t('Workshop utilization by ethnicity')
      );
    }
    if (!empty($demographics['age']['labels'])) {
      $charts['age'] = $this->buildUtilizationChart(
        $demographics['age']['labels'],
        $demographics['age']['workshop'],
        $demographics['age']['other'],
        (string) $this->t('Workshop utilization by age')
      );
    }

    if (!$charts) {
      return NULL;
    }

    $visualization = [
      'type' => 'container',
      'attributes' => ['class' => ['makerspace-dashboard-react-chart__children']],
      'children' => $charts,
    ];

    $rangeMetadata = $this->buildRangeMetadata($rangeKey, self::RANGE_OPTIONS);
    $notes = [
      (string) $this->t('Range: @start – @end', [
        '@start' => $start->format('M Y'),
        '@end' => $bounds['end']->format('M Y'),
      ]),
      (string) $this->t('Source: CiviCRM participants with counted statuses; “Workshop” bars include event types whose label contains “workshop”, all others are grouped under “Other events”.'),
      (string) $this->t('Demographics are derived from CiviCRM contact gender, demographic custom fields, and birth dates when available.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Workshop participant utilization'),
      (string) $this->t('Compares workshop participation across gender, ethnicity, and age groups for the selected range.'),
      $visualization,
      $notes,
      $rangeMetadata
    );
  }

  /**
   * Builds a stacked bar chart for the provided demographics.
   */
  protected function buildUtilizationChart(array $labels, array $workshop, array $other, string $title): array {
    $palette = $this->defaultColorPalette();
    $datasets = [
      [
        'label' => (string) $this->t('Workshops'),
        'data' => array_map('intval', $workshop),
        'backgroundColor' => $palette[0],
        'stack' => 'utilization',
      ],
      [
        'label' => (string) $this->t('Other events'),
        'data' => array_map('intval', $other),
        'backgroundColor' => '#94a3b8',
        'stack' => 'utilization',
      ],
    ];

    return [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => $labels,
        'datasets' => $datasets,
      ],
      'options' => [
        'responsive' => TRUE,
        'interaction' => ['mode' => 'index', 'intersect' => FALSE],
        'scales' => [
          'x' => ['stacked' => TRUE],
          'y' => [
            'stacked' => TRUE,
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Participants'),
            ],
          ],
        ],
        'plugins' => [
          'title' => [
            'display' => TRUE,
            'text' => $title,
          ],
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'integer',
                'suffix' => ' ' . (string) $this->t('registrations'),
              ]),
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Builds metadata describing available ranges and the current selection.
   */
  protected function buildRangeMetadata(string $activeRange, array $allowedRanges): array {
    return [
      'active' => $activeRange,
      'options' => $this->getRangePresets($allowedRanges),
    ];
  }

}
