<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Store sales, how people buy, and stock lost and found.
 *
 * The dashboard is public, so this shows totals and items only. Buyers are
 * only ever counted, and per-item losses stay on the staff oddities page.
 */
class StoreSection extends DashboardSectionBase {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'store';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Store');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['store-dashboard']],
    ];
    $weight = 0;
    foreach ($this->buildTieredChartContainers($filters) as $tier => $container) {
      $container['#weight'] = $weight++;
      $build['tier_' . $tier] = $container;
    }
    return $build;
  }

}
