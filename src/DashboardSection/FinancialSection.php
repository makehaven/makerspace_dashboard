<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Summarizes high-level financial metrics sourced from member profiles.
 */
class FinancialSection extends DashboardSectionBase {

  /**
   * Financial data service.
   */
  protected FinancialDataService $financialDataService;

  /**
   * Constructs the section.
   *
   * @param \Drupal\makerspace_dashboard\Service\FinancialDataService $financial_data_service
   *   The financial data service.
   */
  public function __construct(FinancialDataService $financial_data_service) {
    parent::__construct();
    $this->financialDataService = $financial_data_service;
  }

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

    $end_date = new \DateTimeImmutable();
    $start_date = $end_date->modify('-6 months');
    $mrr_data = $this->financialDataService->getMrrTrend($start_date, $end_date);
    $payment_mix_data = $this->financialDataService->getPaymentMix();

    if (!empty(array_filter($mrr_data['data']))) {
      $build['mrr'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Monthly recurring revenue trend'),
        '#description' => $this->t('Aggregate by billing source to highlight sustainability of recruitment/retention efforts.'),
      ];

      $build['mrr']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('MRR ($)'),
        '#data' => $mrr_data['data'],
      ];

      $build['mrr']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $mrr_data['labels'],
      ];
      $build['mrr_info'] = $this->buildChartInfo([
        $this->t('Source: Member join dates (profile__field_member_join_date) paired with membership type taxonomy terms.'),
        $this->t('Processing: Includes joins within the selected six-month window and multiplies counts by assumed monthly values ($50 individual, $75 family).'),
        $this->t('Definitions: Other membership types currently use a zero value and therefore will not contribute until pricing is modeled.'),
      ]);
    }
    else {
      $build['mrr_empty'] = [
        '#markup' => $this->t('Recurring revenue trend data is not available. Connect financial exports to populate this chart.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
    }

    if (!empty($payment_mix_data)) {
      $build['payment_mix'] = [
        '#type' => 'chart',
        '#chart_type' => 'pie',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Payment mix'),
        '#description' => $this->t('Show contribution of memberships, storage, donations, and education revenue streams.'),
      ];

      $build['payment_mix']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Share'),
        '#data' => array_values($payment_mix_data),
      ];
      $build['payment_mix']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_keys($payment_mix_data),
      ];
      $build['payment_mix_info'] = $this->buildChartInfo([
        $this->t('Source: Membership type assignments from profile__field_membership_type for active member profiles.'),
        $this->t('Processing: Counts distinct profile records per membership type without applying revenue assumptions.'),
        $this->t('Definitions: Represents share of members by type, not dollars; taxonomy term labels surface directly in the chart.'),
      ]);
    }
    else {
      $build['payment_mix_empty'] = [
        '#markup' => $this->t('Payment mix data is not available. Connect billing sources to populate this chart.'),
        '#prefix' => '<div class="makerspace-dashboard-empty">',
        '#suffix' => '</div>',
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['profile_list'],
    ];

    return $build;
  }

}
