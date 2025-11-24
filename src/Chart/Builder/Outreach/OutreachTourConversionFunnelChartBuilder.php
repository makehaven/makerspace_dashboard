<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Displays the tour participation to membership conversion funnel.
 */
class OutreachTourConversionFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'tour_conversion_funnel';
  protected const WEIGHT = 18;

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
    $data = $this->funnelDataService->getTourFunnelData();
    $participants = (int) ($data['participants'] ?? 0);
    $conversions = (int) ($data['conversions'] ?? 0);

    if ($participants === 0 && $conversions === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'funnel',
      'stages' => [
        [
          'label' => (string) $this->t('Tours completed'),
          'value' => $participants,
          'helper' => (string) $this->t('Unique contacts recorded as tour participants in the past 12 months.'),
        ],
        [
          'label' => (string) $this->t('Tour participants who joined'),
          'value' => $conversions,
          'helper' => (string) $this->t('Participants whose membership join date is on or after their tour.'),
        ],
      ],
      'options' => [
        'showValues' => TRUE,
        'format' => 'integer',
      ],
    ];

    $notes = $this->buildRangeNotes($data['range'] ?? NULL);
    $notes[] = (string) $this->t('Source: Tour events in CiviCRM + Drupal member join dates.');
    $notes[] = (string) $this->t('Processing: Counts each contact once using their earliest tour date, then checks whether they subsequently activated a membership (regardless of join channel).');

    return $this->newDefinition(
      (string) $this->t('Tours to member conversions'),
      (string) $this->t('Shows how many documented tours translate into membership joins over the trailing 12 months.'),
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
