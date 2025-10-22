<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\makerspace_dashboard\DashboardSectionInterface;

/**
 * Collects Makerspace dashboard sections registered in the service container.
 */
class DashboardSectionManager {

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

}
