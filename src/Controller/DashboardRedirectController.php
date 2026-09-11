<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles legacy route redirects for the dashboard.
 */
class DashboardRedirectController extends ControllerBase {

  /**
   * Redirects legacy dashboard URLs to the canonical route.
   *
   * The status code is the fourth argument, not the third. Passing 301 as
   * $options raised a TypeError on every request to /makehaven-dashboard,
   * so the legacy alias answered 500 rather than redirecting.
   */
  public function legacyRedirect(): Response {
    return $this->redirect('makerspace_dashboard.dashboard', [], [], 301);
  }

}
