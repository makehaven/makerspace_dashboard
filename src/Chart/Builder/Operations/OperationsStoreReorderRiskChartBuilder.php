<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Operations;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;

/**
 * Counts of materials currently flagged for reorder, broken down by severity.
 *
 * Surfaces the same data that appears on /admin/store/reorder-queue but as a
 * dashboard tile so it's visible alongside other operational KPIs.
 */
class OperationsStoreReorderRiskChartBuilder extends OperationsChartBuilderBase {

  protected const CHART_ID = 'store_reorder_risk';
  protected const WEIGHT = 1;

  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, ?TranslationInterface $stringTranslation = NULL) {
    parent::__construct($stringTranslation);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): ?ChartDefinition {
    if (!$this->entityTypeManager->hasDefinition('node')) {
      return NULL;
    }

    $storage = $this->entityTypeManager->getStorage('node');
    $statuses = ['critical', 'pending_order', 'low'];
    $counts = ['critical' => 0, 'pending_order' => 0, 'low' => 0];

    foreach ($statuses as $status) {
      $count = (int) $storage->getQuery()
        ->condition('type', 'material')
        ->condition('status', 1)
        ->condition('field_material_reorder_status', $status)
        ->accessCheck(FALSE)
        ->count()
        ->execute();
      $counts[$status] = $count;
    }

    $total = array_sum($counts);
    if ($total === 0) {
      // Don't render a chart when there's nothing to show — keeps the
      // dashboard quiet when everything is healthy.
      return NULL;
    }

    $visualization = [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'bar',
      'data' => [
        'labels' => [
          (string) $this->t('Critical'),
          (string) $this->t('Pending order'),
          (string) $this->t('Low'),
        ],
        'datasets' => [[
          'label' => (string) $this->t('Items'),
          'data' => [$counts['critical'], $counts['pending_order'], $counts['low']],
          'backgroundColor' => ['#dc3545', '#fd7e14', '#ffc107'],
          'borderWidth' => 0,
        ]],
      ],
      'options' => [
        'indexAxis' => 'y',
        'plugins' => [
          'legend' => ['display' => FALSE],
        ],
        'scales' => [
          'x' => ['ticks' => ['precision' => 0]],
        ],
      ],
    ];

    $notes = [
      (string) $this->t('Source: field_material_reorder_status on material nodes, maintained by makerspace_material_store.reorder_evaluator.'),
      (string) $this->t('Action: open /admin/store/reorder-queue to triage individual items.'),
    ];

    return $this->newDefinition(
      (string) $this->t('Store: Reorder risk'),
      (string) $this->t('@total items currently flagged for reorder.', ['@total' => $total]),
      $visualization,
      $notes,
      NULL,
      NULL,
      [],
      'key',
    );
  }

}
