<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles legacy route redirects for the dashboard.
 */
class DashboardRedirectController extends ControllerBase {

  /**
   * Redirects legacy dashboard URLs to the canonical route.
   */
  public function legacyRedirect(): Response {
    return $this->redirect('makerspace_dashboard.dashboard', [], 301);
  }

}
