<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Summarizes high-level financial metrics sourced from member profiles.
 */
class FinancialSection extends DashboardSectionBase {

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'financial';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Financial Snapshot');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $build = [];

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Blend Chargebee, Stripe storage rentals, and PayPal revenue to highlight recurring health without exposing individual payments.'),
    ];

    $build['mrr'] = [
      '#type' => 'chart',
      '#chart_type' => 'line',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Monthly recurring revenue trend (sample)'),
      '#description' => $this->t('Aggregate by billing source to highlight sustainability of recruitment/retention efforts.'),
    ];

    $build['mrr']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('MRR ($K)'),
      '#data' => [22.4, 23.1, 23.6, 24.0, 24.5, 24.9],
    ];

    $build['mrr']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => [
        $this->t('Jan'),
        $this->t('Feb'),
        $this->t('Mar'),
        $this->t('Apr'),
        $this->t('May'),
        $this->t('Jun'),
      ],
    ];

    $build['payment_mix'] = [
      '#type' => 'chart',
      '#chart_type' => 'pie',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Payment mix (sample)'),
      '#description' => $this->t('Show contribution of memberships, storage, donations, and education revenue streams.'),
    ];

    $build['payment_mix']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Share'),
      '#data' => [64, 12, 9, 15],
      '#labels' => [
        $this->t('Memberships'),
        $this->t('Storage rentals'),
        $this->t('Education programs'),
        $this->t('Donations & retail'),
      ],
    ];

    return $build;
  }

}
