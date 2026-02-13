<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Displays meeting to member conversion funnel.
 */
class OutreachMeetingConversionFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'meeting_conversion_funnel';
  protected const WEIGHT = 23;

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
    $data = $this->funnelDataService->getActivityFunnelData('meeting');
    $meetings = (int) ($data['activities'] ?? 0);
    $conversions = (int) ($data['conversions'] ?? 0);

    if ($meetings === 0 && $conversions === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'funnel',
      'stages' => [
        [
          'label' => (string) $this->t('Discovery meetings logged'),
          'value' => $meetings,
          'helper' => (string) $this->t('Unique contacts with a CiviCRM activity type containing "Meeting" in the past 12 months.'),
        ],
        [
          'label' => (string) $this->t('Meeting contacts who joined'),
          'value' => $conversions,
          'helper' => (string) $this->t('Meeting contacts whose membership join date is on or after their first meeting activity.'),
        ],
      ],
      'options' => [
        'showValues' => TRUE,
        'format' => 'integer',
      ],
    ];

    $notes = $this->buildRangeNotes($data['range'] ?? NULL);
    $notes[] = (string) $this->t('Source: CiviCRM Meeting activities + Drupal member join dates.');
    $notes[] = (string) $this->t('Processing: Counts each contact once using earliest meeting activity and checks if they later activated membership.');

    return $this->newDefinition(
      (string) $this->t('Meeting to member conversions'),
      (string) $this->t('Shows how often logged discovery meetings convert into memberships over the trailing 12 months.'),
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
