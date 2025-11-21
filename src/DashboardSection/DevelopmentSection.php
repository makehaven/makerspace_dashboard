<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Presents fundraising metrics and pacing insights.
 */
class DevelopmentSection extends DashboardSectionBase {

  protected KpiDataService $kpiDataService;
  protected DevelopmentDataService $developmentDataService;

  protected ?ChartBuilderManager $chartBuilderManager = NULL;

  public function __construct(KpiDataService $kpi_data_service, DevelopmentDataService $development_data_service, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->kpiDataService = $kpi_data_service;
    $this->developmentDataService = $development_data_service;
    $this->chartBuilderManager = $chart_builder_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('development'));
    $build['kpi_table']['#weight'] = $weight++;

    $annualData = $this->developmentDataService->getAnnualGivingSummary(6);
    $build['annual_summary'] = $this->buildAnnualSummaryTable($annualData);
    $build['annual_summary']['#weight'] = $weight++;

    $rangeData = $this->developmentDataService->getGiftRangeBreakdown();
    $build['range_distribution'] = $this->buildGiftRangeTable($rangeData);
    $build['range_distribution']['#weight'] = $weight++;

    $ytdData = $this->developmentDataService->getYearToDateComparison(2);
    $build['ytd_comparison'] = $this->buildYtdComparisonTable($ytdData);
    $build['ytd_comparison']['#weight'] = $weight++;

    if ($this->chartBuilderManager) {
      $charts = $this->buildChartsFromDefinitions($filters);
      if ($charts) {
        $build['charts_section_heading'] = [
          '#markup' => '<h2>' . $this->t('Giving trends') . '</h2>',
          '#weight' => $weight++,
        ];
        foreach ($charts as $chart_id => $chart_render_array) {
          $chart_render_array['#weight'] = $weight++;
          $build[$chart_id] = $chart_render_array;
        }
      }
    }

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'development';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Development');
  }

  protected function buildAnnualSummaryTable(array $data): array {
    $rows = [];
    foreach ($data as $row) {
      $rows[] = [
        $row['year'],
        $this->formatNumber($row['donors']),
        $this->formatNumber($row['first_time_donors']),
        $this->formatNumber($row['gifts']),
        $this->formatCurrency((float) $row['average_gift'], 2),
        $this->formatCurrency((float) $row['total_amount']),
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['development-annual-summary']],
      'heading' => ['#markup' => '<h2>' . $this->t('Annual fundraising summary') . '</h2>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Year'),
          $this->t('Unique donors'),
          $this->t('First-time donors'),
          $this->t('# gifts'),
          $this->t('Average gift'),
          $this->t('Total raised'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('Donation snapshots are not yet available.'),
      ],
    ];
  }

  protected function buildGiftRangeTable(array $data): array {
    $rows = [];
    $hasData = FALSE;
    foreach ($data['ranges'] as $range) {
      $rows[] = [
        $this->formatRangeLabel($range),
        $this->formatPercent((float) ($range['donor_pct'] ?? 0)),
        $this->formatNumber($range['donors'] ?? 0),
        $this->formatNumber($range['gifts'] ?? 0),
        $this->formatCurrency((float) ($range['amount'] ?? 0)),
        $this->formatPercent((float) ($range['amount_pct'] ?? 0)),
      ];
      if (($range['donors'] ?? 0) > 0 || ($range['gifts'] ?? 0) > 0 || (($range['amount'] ?? 0) > 0)) {
        $hasData = TRUE;
      }
    }

    $monthLabel = $this->formatMonthLabel($data['month'] ?? NULL);
    $summaryText = $monthLabel
      ? $this->t('Year-to-date giving as of @month @year', ['@month' => $monthLabel, '@year' => $data['year'] ?? ''])
      : $this->t('Year-to-date giving distribution');

    $container = [
      '#type' => 'container',
      '#attributes' => ['class' => ['development-range-distribution']],
      'heading' => [
        '#markup' => '<h2>' . $this->t('Gift range distribution') . '</h2><p>' . $summaryText . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Range'),
          $this->t('% donors'),
          $this->t('Donors'),
          $this->t('# gifts'),
          $this->t('Total $'),
          $this->t('% dollars'),
        ],
        '#rows' => $rows,
      ],
    ];
    if (!$hasData) {
      $container['notice'] = [
        '#markup' => '<p class="makerspace-dashboard-empty">' . $this->t('Range snapshots are not yet available.') . '</p>',
      ];
    }
    return $container;
  }

  protected function buildYtdComparisonTable(array $data): array {
    $rows = [];
    $previousAmount = NULL;
    foreach ($data as $row) {
      $deltaDisplay = $previousAmount === NULL
        ? '—'
        : $this->formatDeltaCurrency((float) $row['total_amount'] - (float) $previousAmount);
      $rows[] = [
        $row['year'],
        $row['month_label'] ?: $this->t('n/a'),
        $this->formatCurrency((float) $row['total_amount']),
        $deltaDisplay,
        $this->formatNumber($row['donors']),
        $this->formatNumber($row['first_time_donors']),
        $this->formatNumber($row['gifts']),
        $this->formatCurrency((float) $row['average_gift'], 2),
      ];
      $previousAmount = $row['total_amount'];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['development-ytd-comparison']],
      'heading' => ['#markup' => '<h2>' . $this->t('Year-to-date pace') . '</h2>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Year'),
          $this->t('As of'),
          $this->t('Total $'),
          $this->t('Δ vs prior year'),
          $this->t('Donors'),
          $this->t('First-time donors'),
          $this->t('# gifts'),
          $this->t('Average gift'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No year-to-date donation snapshots are available.'),
      ],
    ];
  }

  protected function formatRangeLabel(array $range): string {
    $label = trim((string) ($range['label'] ?? ''));
    if ($label !== '') {
      return $label;
    }
    $min = isset($range['min']) ? (float) $range['min'] : 0.0;
    $max = array_key_exists('max', $range) ? $range['max'] : NULL;
    if ($max === NULL) {
      return $this->t('@min+', ['@min' => $this->formatCurrency($min, 0)]);
    }
    return $this->formatCurrency($min, 0) . ' - ' . $this->formatCurrency((float) $max, 0);
  }

  protected function formatCurrency(float $value, int $decimals = 0): string {
    $formatted = number_format($value, $decimals);
    return '$' . $formatted;
  }

  protected function formatDeltaCurrency(float $delta): string {
    if (abs($delta) < 0.005) {
      return '±' . $this->formatCurrency(0.0);
    }
    $prefix = $delta >= 0 ? '+' : '-';
    return $prefix . $this->formatCurrency(abs($delta));
  }

  protected function formatNumber(int $value): string {
    return number_format($value);
  }

  protected function formatPercent(float $value): string {
    return number_format($value, 1) . '%';
  }

  protected function formatMonthLabel(?int $month): string {
    if (empty($month) || $month < 1 || $month > 12) {
      return '';
    }
    return date('F', mktime(0, 0, 0, $month, 1));
  }

}
