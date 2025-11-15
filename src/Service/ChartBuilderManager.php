<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface;

/**
 * Collects chart builders and exposes lookup helpers per section/chart.
 */
class ChartBuilderManager {

  /**
   * Builders grouped by section id.
   *
   * @var array<string, \Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface[]>
   */
  protected array $builders = [];

  /**
   * Builders keyed by section + chart id.
   *
   * @var array<string, \Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface>
   */
  protected array $builderIndex = [];

  /**
   * Constructs the manager.
   *
   * @param iterable $builders
   *   Tagged builder services.
   */
  public function __construct(iterable $builders) {
    foreach ($builders as $builder) {
      if (!$builder instanceof DashboardChartBuilderInterface) {
        continue;
      }
      $section = $builder->getSectionId();
      $chart = $builder->getChartId();

      $this->builders[$section][] = $builder;
      $this->builderIndex[$this->buildIndexKey($section, $chart)] = $builder;
    }
  }

  /**
   * Returns builders registered for a section.
   *
   * @return \Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface[]
   *   Builder instances keyed numerically for predictable iteration order.
   */
  public function getBuilders(string $sectionId): array {
    return $this->builders[$sectionId] ?? [];
  }

  /**
   * Returns a specific builder for the requested section/chart combo.
   */
  public function getBuilder(string $sectionId, string $chartId): ?DashboardChartBuilderInterface {
    $key = $this->buildIndexKey($sectionId, $chartId);
    return $this->builderIndex[$key] ?? NULL;
  }

  /**
   * Builds an internal lookup key.
   */
  protected function buildIndexKey(string $sectionId, string $chartId): string {
    return $sectionId . ':' . $chartId;
  }

}
