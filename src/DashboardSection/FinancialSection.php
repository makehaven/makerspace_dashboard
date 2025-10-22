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
      '#labels' => array_keys($payment_mix_data),
    ];

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['profile_list'],
    ];

    return $build;
  }

}
