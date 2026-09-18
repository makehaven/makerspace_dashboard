<?php

declare(strict_types=1);

namespace Drupal\makerspace_dashboard;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Keeps the dashboard container compilable when an owning module is absent.
 *
 * The referral charts take `makerspace_referrals.stats`, following the same
 * direction of dependency as the lending and storage collectors. A hard
 * argument reference is fine once both modules are enabled — but there is a
 * window during every deploy where it is not: the code artifact lands with
 * this module already enabled and `makerspace_referrals` still absent from
 * the database's module list, because config import is what enables it.
 *
 * In that window Symfony cannot resolve `@makerspace_referrals.stats`, the
 * container fails to compile, and the site returns 500 — including Drush, so
 * the `cim` that would have enabled the module cannot be run either. Caught on
 * Pantheon dev 2026-09-18 before it reached test or live.
 *
 * Dropping the definitions is the right response rather than passing NULL:
 * a chart builder with no stats service has nothing to draw, and the tagged
 * collector simply offers three fewer charts until the module arrives. The
 * next cache rebuild after `cim` registers them.
 */
class MakerspaceDashboardServiceProvider extends ServiceProviderBase {

  /**
   * Chart builders that cannot exist without makerspace_referrals.
   */
  private const REFERRAL_CHART_BUILDERS = [
    'makerspace_dashboard.chart_builder.outreach_referrals_monthly',
    'makerspace_dashboard.chart_builder.outreach_top_referrers',
    'makerspace_dashboard.chart_builder.outreach_referral_resolution',
  ];

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    if (is_array($modules) && array_key_exists('makerspace_referrals', $modules)) {
      return;
    }

    foreach (self::REFERRAL_CHART_BUILDERS as $id) {
      if ($container->hasDefinition($id)) {
        $container->removeDefinition($id);
      }
    }
  }

}
