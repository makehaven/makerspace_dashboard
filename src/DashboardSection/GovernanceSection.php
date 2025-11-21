<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\GovernanceBoardDataService;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Governance dashboard section.
 */
class GovernanceSection extends DashboardSectionBase {

  /**
   * Board composition data service.
   */
  protected GovernanceBoardDataService $boardDataService;

  /**
   * KPI data provider.
   */
  protected KpiDataService $kpiDataService;

  /**
   * Constructs the section.
   */
  public function __construct(GovernanceBoardDataService $boardDataService, KpiDataService $kpiDataService, ChartBuilderManager $chartBuilderManager) {
    parent::__construct(NULL, $chartBuilderManager);
    $this->boardDataService = $boardDataService;
    $this->kpiDataService = $kpiDataService;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'governance';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Governance');
  }

  /**
   * {@inheritdoc}
   */
  public function getGoogleSheetChartMetadata(): array {
    return [
      'label' => $this->t('Board Roster'),
      'tab_name' => 'Board-Roster',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];
    $weight = 0;

    $build['intro'] = $this->buildIntro($this->t('This section highlights the current board composition, how we are progressing toward diversity goals, and the KPIs tied to governance objectives.'));
    $build['intro']['#weight'] = $weight++;

    $build['kpi_table'] = $this->buildKpiTable($this->kpiDataService->getKpiData('governance'));
    $build['kpi_table']['#weight'] = $weight++;

    $composition = NULL;
    $compositionError = NULL;
    try {
      $composition = $this->boardDataService->getBoardComposition();
    }
    catch (\Throwable $exception) {
      $compositionError = $exception->getMessage();
    }

    if ($composition && !empty($composition['total_members'])) {
      $build['roster_summary'] = [
        '#type' => 'markup',
        '#markup' => $this->t('Board roster snapshot: @count members reported in the Google Sheet.', [
          '@count' => $composition['total_members'],
        ]),
        '#prefix' => '<div class="makerspace-dashboard-summary makerspace-dashboard-summary--single">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
      ];
    }

    if ($compositionError) {
      $build['composition_error'] = [
        '#markup' => $this->t('Unable to load board composition charts: @message', ['@message' => $compositionError]),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
        '#weight' => $weight++,
      ];
    }
    else {
      foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
        $container['#weight'] = $weight++;
        $build['tier_' . $tier] = $container;
      }
    }

    $build['#cache'] = [
      'max-age' => 900,
      'contexts' => ['user.permissions'],
    ];

    return $build;
  }

}
