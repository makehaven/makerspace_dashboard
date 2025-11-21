<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Retention;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Service\AppointmentInsightsService;

/**
 * Shows appointment purposes scheduled early in the member lifecycle.
 */
class RetentionAppointmentOnboardingChartBuilder extends ChartBuilderBase {

  protected const SECTION_ID = 'retention';
  protected const CHART_ID = 'appointment_onboarding_purposes';
  protected const WEIGHT = 65;

  protected AppointmentInsightsService $appointmentInsights;

  public function __construct(AppointmentInsightsService $appointmentInsights, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->appointmentInsights = $appointmentInsights;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    $summary = $this->appointmentInsights->getOnboardingPurposeSummary();
    if (empty($summary['buckets'])) {
      return NULL;
    }

    $bucketDefinitions = $this->appointmentInsights->getBucketDefinitions();
    $totals = $summary['totals'] ?? [];
    $cohortTotal = (int) ($totals['members_cohort'] ?? 0);
    if ($cohortTotal <= 0) {
      return NULL;
    }

    $labels = [];
    $membersPerBucket = [];
    $cumulativePercent = [];
    $remainingPercent = [];
    $cumulativeMembers = 0;
    foreach ($bucketDefinitions as $key => $definition) {
      $labels[] = (string) $this->t($definition['label']);
      $bucketMembers = (int) ($summary['buckets'][$key]['members'] ?? 0);
      $membersPerBucket[] = $bucketMembers;
      $cumulativeMembers += $bucketMembers;
      $engagedPercent = round(($cumulativeMembers / $cohortTotal) * 100, 1);
      $cumulativePercent[] = $engagedPercent;
      $remainingPercent[] = max(0, round(100 - $engagedPercent, 1));
    }

    $datasets = [
      [
        'type' => 'bar',
        'label' => (string) $this->t('Members booking their first appointment'),
        'data' => $membersPerBucket,
        'backgroundColor' => '#3b82f6',
        'borderRadius' => 6,
        'yAxisID' => 'yMembers',
      ],
      [
        'type' => 'line',
        'label' => (string) $this->t('Members engaged'),
        'data' => $cumulativePercent,
        'borderColor' => '#16a34a',
        'backgroundColor' => 'rgba(22,163,74,0.15)',
        'fill' => FALSE,
        'tension' => 0.3,
        'pointRadius' => 3,
        'pointHoverRadius' => 5,
        'yAxisID' => 'yPercent',
      ],
      [
        'type' => 'line',
        'label' => (string) $this->t('Still not engaged'),
        'data' => $remainingPercent,
        'borderColor' => '#f97316',
        'backgroundColor' => 'rgba(249,115,22,0.15)',
        'fill' => FALSE,
        'tension' => 0.3,
        'pointRadius' => 3,
        'pointHoverRadius' => 5,
        'yAxisID' => 'yPercent',
      ],
    ];

    $purposeLabels = $this->appointmentInsights->getPurposeLabels();
    $bucketNotes = [];
    foreach ($bucketDefinitions as $key => $definition) {
      $purposes = $summary['buckets'][$key]['purpose_counts'] ?? [];
      arsort($purposes);
      $topPurpose = '';
      foreach ($purposes as $purposeKey => $count) {
        if ($count > 0) {
          $topPurpose = $this->t('@purpose (@count members)', [
            '@purpose' => $purposeLabels[$purposeKey] ?? $purposeKey,
            '@count' => number_format($count),
          ]);
          break;
        }
      }
      if ($topPurpose) {
        $bucketNotes[] = $this->t('@label top purpose: @summary', [
          '@label' => $definition['label'],
          '@summary' => $topPurpose,
        ]);
      }
    }

    $engagedTotal = (int) ($totals['engaged_members'] ?? 0);
    $notEngaged = max(0, $cohortTotal - $engagedTotal);
    $notes = [
      (string) $this->t('Cohort size: @total members, @engaged engaged within one year (@percent%).', [
        '@total' => number_format($cohortTotal),
        '@engaged' => number_format($engagedTotal),
        '@percent' => $cohortTotal ? round(($engagedTotal / $cohortTotal) * 100, 1) : 0,
      ]),
      (string) $this->t('@count members have not booked an appointment within their first year.', [
        '@count' => number_format($notEngaged),
      ]),
    ];

    $visualization = [
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
          'yMembers' => [
            'position' => 'left',
            'ticks' => ['precision' => 0],
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Members'),
            ],
          ],
          'yPercent' => [
            'position' => 'right',
            'grid' => ['drawOnChartArea' => FALSE],
            'min' => 0,
            'max' => 100,
            'title' => [
              'display' => TRUE,
              'text' => (string) $this->t('Share of total members'),
            ],
            'ticks' => [
              'callback' => $this->chartCallback('value_format', [
                'format' => 'percent',
                'decimals' => 0,
                'showLabel' => FALSE,
              ]),
            ],
          ],
        ],
        'plugins' => [
          'legend' => ['position' => 'bottom'],
          'tooltip' => [
            'callbacks' => [
              'label' => $this->chartCallback('series_value', [
                'format' => 'decimal',
                'decimals' => 1,
                'suffix' => '%',
                'perAxis' => [
                  'yMembers' => [
                    'format' => 'integer',
                    'suffix' => ' ' . (string) $this->t('members'),
                  ],
                ],
              ]),
            ],
          ],
        ],
      ],
    ];

    $description = $this->t('Tracks the share of members who schedule their first appointment within 30, 90, and 365 days of joining.');

    return $this->newDefinition(
      (string) $this->t('Appointments During Onboarding'),
      (string) $description,
      $visualization,
      array_merge([
        (string) $this->t('Source: appointment nodes joined to member profiles (excluding cancellations) and bucketed by days after the profile was created.'),
        (string) $this->t('Processing: Each member is counted once based on when their first appointment occurs; line markers show cumulative engagement vs. those still unengaged.'),
      ], $notes, $bucketNotes),
    );
  }

}
