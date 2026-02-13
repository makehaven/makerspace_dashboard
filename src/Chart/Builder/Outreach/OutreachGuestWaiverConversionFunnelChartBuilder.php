<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Displays guest waiver to member conversion funnel.
 */
class OutreachGuestWaiverConversionFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'guest_waiver_conversion_funnel';
  protected const WEIGHT = 24;

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
    $data = $this->funnelDataService->getActivityFunnelData('guest waiver');
    $waivers = (int) ($data['activities'] ?? 0);
    $conversions = (int) ($data['conversions'] ?? 0);

    if ($waivers === 0 && $conversions === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'funnel',
      'stages' => [
        [
          'label' => (string) $this->t('Guest waivers signed'),
          'value' => $waivers,
          'helper' => (string) $this->t('Unique contacts with a Guest Waiver activity in the past 12 months.'),
        ],
        [
          'label' => (string) $this->t('Waiver contacts who joined'),
          'value' => $conversions,
          'helper' => (string) $this->t('Guest waiver contacts whose membership join date is on or after their first waiver activity.'),
        ],
      ],
      'options' => [
        'showValues' => TRUE,
        'format' => 'integer',
      ],
    ];

    $notes = $this->buildRangeNotes($data['range'] ?? NULL);
    $notes[] = (string) $this->t('Source: CiviCRM Guest Waiver activities + Drupal member join dates.');
    $notes[] = (string) $this->t('Processing: Counts each contact once using the earliest waiver activity, then checks whether they later activated a membership.');

    return $this->newDefinition(
      (string) $this->t('Guest waiver conversions'),
      (string) $this->t('Shows how often guest waiver signers convert into memberships over the trailing 12 months.'),
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
