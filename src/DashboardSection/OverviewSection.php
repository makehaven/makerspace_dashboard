<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Defines the OverviewSection class.
 */
class OverviewSection extends DashboardSectionBase {

  protected KpiDataService $kpiDataService;
  protected array $sectionLabels = [];

  /**
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->kpiDataService = $kpi_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'overview';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Overview');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    return [
      'stoplight' => $this->buildStoplightGrid(),
    ];
  }

  /**
   * Builds the stoplight KPI grid.
   */
  protected function buildStoplightGrid(): array {
    $definitions = $this->kpiDataService->getAllKpiDefinitions();
    $wiredRows = [];
    $inDevelopmentRows = [];

    if (!$this->sectionLabels) {
      try {
        $sectionManager = \Drupal::service('makerspace_dashboard.section_manager');
        foreach ($sectionManager->getSections() as $section) {
          $this->sectionLabels[$section->getId()] = (string) $section->getLabel();
        }
      }
      catch (\Throwable $e) {
        // Ignore and fall back to computed labels.
      }
    }

    $goalYearLabel = NULL;
    foreach ($definitions as $sectionId => $kpiDefinitions) {
      if (empty($kpiDefinitions)) {
        continue;
      }
      $kpis = $this->kpiDataService->getKpiData($sectionId);
      if (empty($kpis)) {
        continue;
      }
      $sectionLabel = $this->sectionLabels[$sectionId] ?? $this->t(ucwords(str_replace('_', ' ', $sectionId)));
    foreach ($kpiDefinitions as $kpiId => $info) {
      $kpi = $kpis[$kpiId] ?? NULL;
      if (!$kpi) {
        continue;
      }
        $format = $kpi['display_format'] ?? NULL;
        if ($goalYearLabel === NULL && !empty($kpi['goal_current_year_label'])) {
          $goalYearLabel = (int) $kpi['goal_current_year_label'];
        }

        $row = [
          $sectionLabel,
          $kpi['label'] ?? ($info['label'] ?? $kpiId),
          $this->buildStoplightBadge($kpi, $format),
          $this->buildCurrentValueCell($kpi, $format),
          $this->buildGoalValueCell($kpi, $format),
          $this->buildSourceNoteCell($kpi),
          [
            'data' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['kpi-sparkline-wrapper']],
              'sparkline' => $this->buildSparkline($kpi['trend'] ?? []),
            ],
            'attributes' => ['class' => 'kpi-sparkline-cell'],
          ],
        ];

        if ($this->isKpiInDevelopment($kpi)) {
          $inDevelopmentRows[] = $row;
        }
        else {
          $wiredRows[] = $row;
        }
      }
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['overview-stoplight']],
      'heading' => [
        '#markup' => '<h2>' . $this->t('KPI Stoplight Overview') . '</h2><p>' . $this->t('Each KPI displays its current value versus the active annual goal, along with a color-coded status and 12-month trend sparkline.') . '</p>',
      ],
    ];

    if (!$wiredRows && !$inDevelopmentRows) {
      $build['empty'] = [
        '#markup' => '<p class="makerspace-dashboard-empty">' . $this->t('KPI data is not available yet.') . '</p>',
      ];
      return $build;
    }

    $goalYearLabel = $goalYearLabel ?? (int) date('Y');
    $header = [
      $this->t('Section'),
      $this->t('KPI'),
      $this->t('Status'),
      $this->t('Current'),
      $this->t('Goal @year', ['@year' => $goalYearLabel]),
      $this->t('Source'),
      $this->t('Trend'),
    ];
    $build['wired_heading'] = [
      '#markup' => '<h3>' . $this->t('Wired KPIs') . '</h3>',
    ];
    $build['wired_table'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['kpi-stoplight-table']],
      '#header' => $header,
      '#rows' => $wiredRows,
      '#empty' => $this->t('No wired KPIs available yet.'),
    ];
    $build['in_development_heading'] = [
      '#markup' => '<h3>' . $this->t('KPIs In Development') . '</h3>',
    ];
    $build['in_development_table'] = [
      '#type' => 'table',
      '#attributes' => ['class' => ['kpi-stoplight-table', 'kpi-stoplight-table--in-development']],
      '#header' => $header,
      '#rows' => $inDevelopmentRows,
      '#empty' => $this->t('No in-development KPIs currently listed.'),
    ];

    $build['#cache'] = [
      'max-age' => 1800,
      'tags' => ['config:makerspace_dashboard.kpis'],
      'contexts' => ['timezone'],
    ];

    return $build;
  }

  /**
   * Builds the stoplight badge column.
   */
  protected function buildStoplightBadge(array $kpi, ?string $format): array {
    $class = $this->determinePerformanceClass($kpi['current'] ?? NULL, $kpi['goal_current_year'] ?? NULL, $format);
    $label = match ($class) {
      'kpi-progress--good' => (string) $this->t('On track'),
      'kpi-progress--warning' => (string) $this->t('Watch'),
      'kpi-progress--poor' => (string) $this->t('Off track'),
      default => (string) $this->t('No goal'),
    };

    $indicatorClasses = ['kpi-stoplight-indicator'];
    $indicatorClasses[] = $class ?? 'kpi-progress--na';

    $classString = implode(' ', $indicatorClasses);
    $markup = Markup::create(sprintf(
      '<span class="%s" aria-hidden="true"></span><span class="kpi-stoplight-text">%s</span>',
      Html::escape($classString),
      Html::escape($label),
    ));

    return [
      'data' => ['#markup' => $markup],
      'class' => ['kpi-stoplight-cell'],
    ];
  }

  /**
   * Formats the goal cell with the current year label.
   */
  protected function buildGoalValueCell(array $kpi, ?string $format) {
    $goalValue = $this->formatKpiValue($kpi['goal_current_year'] ?? NULL, $format);
    if (!$goalValue) {
      return $this->t('n/a');
    }
    $label = $kpi['goal_current_year_label'] ?? date('Y');
    $markup = Markup::create(sprintf(
      '<span class="kpi-goal-value">%s</span> <span class="kpi-goal-label">(%s)</span>',
      Html::escape((string) $goalValue),
      Html::escape((string) $label),
    ));

    return [
      'data' => ['#markup' => $markup],
    ];
  }

}
