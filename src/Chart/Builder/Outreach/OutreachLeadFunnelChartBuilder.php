<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Displays the mailing list → workshop → membership funnel.
 */
class OutreachLeadFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'lead_generation_funnel';
  protected const WEIGHT = 12;

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
    $data = $this->funnelDataService->getLeadFunnelData();
    $mailingList = (int) ($data['mailing_list'] ?? 0);
    $workshops = (int) ($data['workshop_participants'] ?? 0);
    $joins = (int) ($data['member_joins'] ?? 0);

    if ($mailingList === 0 && $workshops === 0 && $joins === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'funnel',
      'stages' => [
        [
          'label' => (string) $this->t('Mailing list (opt-in)'),
          'value' => $mailingList,
          'helper' => (string) $this->t('Contacts subscribed to receive email updates.'),
        ],
        [
          'label' => (string) $this->t('Workshop participants (12 months)'),
          'value' => $workshops,
          'helper' => (string) $this->t('Unique contacts who attended a workshop in the window.'),
        ],
        [
          'label' => (string) $this->t('New members (12 months)'),
          'value' => $joins,
          'helper' => (string) $this->t('Members who activated during the same window.'),
        ],
      ],
      'options' => [
        'showValues' => TRUE,
        'format' => 'integer',
      ],
    ];

    $notes = $this->buildRangeNotes($data['range'] ?? NULL);
    $notes[] = (string) $this->t('Source: CiviCRM contacts + participant records + Drupal membership join dates.');
    $notes[] = (string) $this->t('Processing: Contacts counted in later stages are not required to originate in the mailing list, but the funnel visualizes top-of-funnel scale versus downstream conversions.');

    return $this->newDefinition(
      (string) $this->t('Lead acquisition funnel'),
      (string) $this->t('Compares the size of the opted-in mailing list, active workshop participants, and new member joins over the trailing 12 months.'),
      $visualization,
      $notes,
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
