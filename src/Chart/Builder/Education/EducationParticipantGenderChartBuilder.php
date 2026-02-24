<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Participant gender mix across workshops vs other events.
 */
class EducationParticipantGenderChartBuilder extends EducationEventsChartBuilderBase {

  protected const CHART_ID = 'demographics_gender';
  protected const WEIGHT = 70;
  protected const TIER = 'supplemental';
  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['1m', '3m', '1y', '2y'];

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $activeRange = $this->resolveSelectedRange($filters, $this->getChartId(), self::RANGE_DEFAULT, self::RANGE_OPTIONS);
    $bounds = $this->calculateRangeBounds($activeRange, $this->now());
    $demographics = $this->eventsMembershipDataService->getParticipantDemographics($bounds['start'], $bounds['end']);
    $gender = $demographics['gender'] ?? NULL;
    if (empty($gender['labels'])) {
      return NULL;
    }

    $labels = [];
    $workshopValues = [];
    $otherValues = [];
    foreach ($gender['labels'] as $index => $label) {
      $labelText = (string) $label;
      if ($this->isUnspecifiedGenderLabel($labelText)) {
        continue;
      }
      $labels[] = $labelText;
      $workshopValues[] = (int) ($gender['workshop'][$index] ?? 0);
      $otherValues[] = (int) ($gender['other'][$index] ?? 0);
    }
    if (empty($labels)) {
      return NULL;
    }

    $workshopTotal = array_sum($workshopValues);
    $otherTotal = array_sum($otherValues);
    if (($workshopTotal + $otherTotal) === 0) {
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => array_map('strval', $labels),
        'datasets' => [
          [
            'label' => (string) $this->t('Workshops'),
            'data' => $workshopValues,
            'backgroundColor' => '#8b5cf6',
          ],
          [
            'label' => (string) $this->t('Other events'),
            'data' => $otherValues,
            'backgroundColor' => '#f97316',
          ],
        ],
      ],
      'options' => [
        'plugins' => [
          'legend' => ['position' => 'bottom'],
        ],
        'scales' => [
          'y' => ['ticks' => ['precision' => 0]],
        ],
      ],
    ];

    return $this->newDefinition(
      (string) $this->t('Participant Gender by Event Type'),
      (string) $this->t('Counted participants grouped by gender across workshops and other events.'),
      $visualization,
      [
        (string) $this->t('Source: Participant gender from CiviCRM contacts linked to workshop and other event registrations.'),
        (string) $this->t('Processing: Includes counted participant statuses only and excludes unspecified/non-response gender values from the displayed mix.'),
      ],
      $this->buildRangeMetadata($activeRange, self::RANGE_OPTIONS),
    );
  }

  /**
   * Determines whether a gender label should be treated as non-response.
   */
  protected function isUnspecifiedGenderLabel(string $label): bool {
    $normalized = strtolower(trim($label));
    if ($normalized === '') {
      return TRUE;
    }

    $tokens = [
      'unspecified',
      'unknown',
      'not provided',
      'prefer not',
      'decline',
      'did not respond',
    ];
    foreach ($tokens as $token) {
      if (str_contains($normalized, $token)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
