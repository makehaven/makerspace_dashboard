<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\makerspace_dashboard\Form\DashboardForm;

/**
 * Returns the Makerspace dashboard landing page.
 */
class DashboardController extends ControllerBase {

  /**
   * Displays the dashboard with vertical tabs.
   */
  public function dashboard(string $sid): array {
    return $this->formBuilder()->getForm(DashboardForm::class, $sid);
  }

}
