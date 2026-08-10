<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\makerspace_dashboard\DashboardSectionInterface;

/**
 * Collects Makerspace dashboard sections registered in the service container.
 */
class DashboardSectionManager {

  /**
   * Sections that resolve by id but are not peer tabs on the dashboard.
   *
   * Listening health reports on the instrumentation rather than on the
   * makerspace, so it does not belong alongside Finance and Retention. The
   * listening *content* is distributed into those sections instead; this page
   * is where you go to ask whether the channels feeding them are working.
   * It stays resolvable so its own route and chart API keep functioning.
   */
  protected const HIDDEN_FROM_NAVIGATION = ['listening'];

  /**
   * Dashboard sections keyed by machine name.
   *
   * @var \Drupal\makerspace_dashboard\DashboardSectionInterface[]
   */
  protected array $sections = [];

  /**
   * Constructs the manager.
   *
   * @param iterable $sections
   *   The tagged dashboard section services.
   */
  public function __construct(iterable $sections) {
    foreach ($sections as $section) {
      if ($section instanceof DashboardSectionInterface) {
        $this->sections[$section->getId()] = $section;
      }
    }

    if (isset($this->sections['operations'])) {
      $operations = $this->sections['operations'];
      unset($this->sections['operations']);
      $this->sections['operations'] = $operations;
    }
  }

  /**
   * Gets all registered sections keyed by machine name.
   *
   * @return \Drupal\makerspace_dashboard\DashboardSectionInterface[]
   *   The registered dashboard sections.
   */
  public function getSections(): array {
    return $this->sections;
  }

  /**
   * Gets the sections that should appear as dashboard tabs.
   */
  public function getNavigableSections(): array {
    return array_diff_key($this->sections, array_flip(self::HIDDEN_FROM_NAVIGATION));
  }

  /**
   * Gets a single section by ID.
   */
  public function getSection(string $sectionId): ?DashboardSectionInterface {
    return $this->sections[$sectionId] ?? NULL;
  }

  /**
   * Builds a section chart for a specific range/filter selection.
   */
  public function buildSectionChart(string $sectionId, string $chartId, array $filters = []): ?array {
    $section = $this->getSection($sectionId);
    if (!$section) {
      return NULL;
    }
    return $section->buildChart($chartId, $filters);
  }

  /**
   * Builds chart metadata suitable for React rendering.
   */
  public function getChartDefinition(string $sectionId, string $chartId, array $filters = []): ?array {
    $chart = $this->buildSectionChart($sectionId, $chartId, $filters);
    if (!$chart) {
      return NULL;
    }
    return $chart['#makerspace_chart'] ?? NULL;
  }

}
