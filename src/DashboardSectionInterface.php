<?php

namespace Drupal\makerspace_dashboard;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the contract for Makerspace dashboard sections.
 */
interface DashboardSectionInterface {

  /**
   * Gets the machine name for the section.
   */
  public function getId(): string;

  /**
   * Gets the translated label for the section.
   */
  public function getLabel(): TranslatableMarkup;

  /**
   * Builds the render array representing the section contents.
   *
   * @param array $filters
   *   Optional runtime filters (date ranges, cohorts, etc.).
   */
  public function build(array $filters = []): array;

}
