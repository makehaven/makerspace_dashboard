<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Displays the visits (activities) to member conversion funnel.
 */
class OutreachVisitConversionFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'visit_conversion_funnel';
  protected const WEIGHT = 22;

  public function __construct(
    protected FunnelDataService $funnelDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $data = $this->funnelDataService->getVisitFunnelData();
    $visits = (int) ($data['visits'] ?? 0);
    $conversions = (int) ($data['conversions'] ?? 0);

    if ($visits === 0 && $conversions === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'funnel',
      'stages' => [
        [
          'label' => (string) $this->t('Recorded visits (activities)'),
          'value' => $visits,
          'helper' => (string) $this->t('Unique contacts logged with an activity whose type contains “visit” in the past 12 months.'),
        ],
        [
          'label' => (string) $this->t('Visited contacts who joined'),
          'value' => $conversions,
          'helper' => (string) $this->t('Visit activity targets whose membership join date is on or after their first visit.'),
        ],
      ],
      'options' => [
        'showValues' => TRUE,
        'format' => 'integer',
      ],
    ];

    $notes = $this->buildRangeNotes($data['range'] ?? NULL);
    $notes[] = (string) $this->t('Source: CiviCRM activities tagged as visits + Drupal membership join dates.');
    $notes[] = (string) $this->t('Processing: Uses each contact’s earliest visit activity to determine whether they later activated a membership.');

    return $this->newDefinition(
      (string) $this->t('Visits to member conversions'),
      (string) $this->t('Shows how often recorded space visits lead to membership joins over the trailing 12 months.'),
      $visualization,
      $notes,
      NULL,
      NULL,
      [],
      'experimental',
    );
  }

  /**
   * Formats range metadata for chart notes.
   */
  protected function buildRangeNotes(?array $range): array {
    if (empty($range['start']) || empty($range['end'])) {
      return [];
    }
    if ($range['start'] instanceof \DateTimeInterface && $range['end'] instanceof \DateTimeInterface) {
      return [
        (string) $this->t('Window: @start – @end', [
          '@start' => $range['start']->format('M Y'),
          '@end' => $range['end']->format('M Y'),
        ]),
      ];
    }
    return [];
  }

}
