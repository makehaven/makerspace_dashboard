<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Outreach;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\FunnelDataService;

/**
 * Tracks people whose first-ever MakeHaven event led to membership.
 *
 * Distinct from the all-participant conversion funnel: the cohort here is
 * limited to contacts who had never registered for anything before, which is
 * the population outreach spending is actually trying to reach. Regulars who
 * attend repeatedly cannot dilute or flatter the rate.
 */
class OutreachFirstTimeRegistrantFunnelChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'outreach';
  protected const CHART_ID = 'first_time_registrant_funnel';
  protected const WEIGHT = 7;

  /**
   * Days after the first event within which a join counts as fast conversion.
   */
  protected const CONVERSION_WINDOW_DAYS = 90;

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
    $data = $this->funnelDataService->getFirstTimeParticipantCohort(12, self::CONVERSION_WINDOW_DAYS);

    $eligible = (int) ($data['eligible'] ?? 0);
    $convertedEver = (int) ($data['converted_ever'] ?? 0);
    $convertedWindow = (int) ($data['converted_window'] ?? 0);

    if ($eligible === 0) {
      return NULL;
    }

    // Stages are strictly nested — every contact counted in a later stage is
    // also counted in every earlier one — so the shrinking bars mean what a
    // funnel implies. "Returned for another event" is deliberately kept out of
    // the stages because joining without ever coming back to a second event is
    // a common and perfectly good outcome; it appears in the notes instead.
    $visualization = [
      'type' => 'funnel',
      'stages' => [
        [
          'label' => (string) $this->t('First-time event registrants'),
          'value' => $eligible,
          'helper' => (string) $this->t('Contacts whose first-ever MakeHaven registration fell in the window and who were not already members.'),
        ],
        [
          'label' => (string) $this->t('Became members (any time since)'),
          'value' => $convertedEver,
          'helper' => (string) $this->t('Joined at some point after that first event.'),
        ],
        [
          'label' => (string) $this->t('Became members within @days days', ['@days' => self::CONVERSION_WINDOW_DAYS]),
          'value' => $convertedWindow,
          'helper' => (string) $this->t('Joined quickly enough to attribute the join to the event with confidence.'),
        ],
      ],
      'options' => [
        'showValues' => TRUE,
        'format' => 'integer',
      ],
    ];

    $notes = $this->buildRangeNotes($data['range'] ?? NULL);
    $notes[] = (string) $this->t('Source: CiviCRM event participants in counted statuses (test registrations and template events excluded), plus Drupal member join dates.');
    $notes[] = (string) $this->t('Processing: "First time" means the contact had no counted registration at any earlier MakeHaven event, not merely none inside this window. Each contact is counted once, on their first event date.');

    $alreadyMembers = (int) ($data['already_members'] ?? 0);
    if ($alreadyMembers > 0) {
      $notes[] = (string) $this->t('Excluded: @count first-time registrants were already members when they attended, so they had nothing to convert to.', [
        '@count' => $alreadyMembers,
      ]);
    }

    $returned = (int) ($data['returned'] ?? 0);
    $notes[] = (string) $this->t('Repeat engagement: @count of the @eligible first-timers (@rate) came back for at least one more event. Returning and joining overlap but neither requires the other, which is why repeat engagement is not a funnel stage.', [
      '@count' => $returned,
      '@eligible' => $eligible,
      '@rate' => $this->formatRate($data['return_rate'] ?? NULL),
    ]);

    $notes[] = (string) $this->t('Definitions: Contacts whose first event was recent have not had a full @days days to convert, so the last quarter of the window understates the fast-conversion stage.', [
      '@days' => self::CONVERSION_WINDOW_DAYS,
    ]);

    return $this->newDefinition(
      (string) $this->t('First-Time Registrant Conversion'),
      (string) $this->t('Of the people whose first-ever MakeHaven event fell in the past 12 months, how many went on to become members — overall and within @days days.', ['@days' => self::CONVERSION_WINDOW_DAYS]),
      $visualization,
      $notes,
    );
  }

  /**
   * Formats a 0-1 ratio as a percentage string.
   */
  protected function formatRate($rate): string {
    if (!is_numeric($rate)) {
      return (string) $this->t('n/a');
    }
    return number_format(((float) $rate) * 100, 1) . '%';
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
