<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Placeholder for Governance dashboard section.
 */
class GovernanceSection extends PlaceholderSection {

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
      'label' => 'Governance',
      'tab_name' => 'Governance',
    ];
  }

}
