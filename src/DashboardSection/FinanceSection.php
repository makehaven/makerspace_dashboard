<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\FinancialDataService;

/**
 * Summarizes high-level financial metrics sourced from member profiles.
 */
class FinanceSection extends DashboardSectionBase {

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
    return 'finance';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Finance');
  }

  /**
   * {@inheritdoc}
   */
  public function getGoogleSheetChartMetadata(): array {
    return [
      'label' => 'Financial Snapshot',
      'tab_name' => 'Finance',
    ];
  }

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
    $average_payment_data = $this->financialDataService->getAverageMonthlyPaymentByType();
    $chargebee_plan_data = $this->financialDataService->getChargebeePlanDistribution();

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
        '#labels' => array_map('strval', $mrr_data['labels']),
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
        '#raw_options' => [
          'plugins' => [
            'legend' => [
              'position' => 'top',
            ],
          ],
        ],
      ];

      $build['payment_mix']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Share'),
        '#data' => array_values($payment_mix_data),
      ];
      $build['payment_mix']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', array_keys($payment_mix_data)),
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

    if (!empty($average_payment_data['types'])) {
      $averageLabels = array_keys($average_payment_data['types']);
      $averageValues = array_map(fn(array $row) => $row['average'], $average_payment_data['types']);

      $build['average_payment'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Average recorded monthly payment by membership type'),
        '#description' => $this->t('Uses field_member_payment_monthly to estimate typical recurring revenue by membership category.'),
      ];
      $build['average_payment']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Average $ / month'),
        '#data' => $averageValues,
      ];
      $build['average_payment']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', $averageLabels),
      ];
      $build['average_payment_info'] = $this->buildChartInfo([
        $this->t('Source: field_member_payment_monthly on active default member profiles with status = 1.'),
        $this->t('Processing: Averages monthly payment values per membership type; entries with blank or zero values are excluded.'),
        $this->t('Definitions: Provides directional insight only—confirm with billing exports before financial reporting.'),
      ]);

      $summaryLines = [
        $this->t('Overall average recorded payment: <strong>$@amount</strong> per month', ['@amount' => number_format($average_payment_data['overall_average'] ?? 0, 2)]),
      ];
      if (!empty($average_payment_data['total_revenue'])) {
        $summaryLines[] = $this->t('Projected monthly revenue from recorded amounts: <strong>$@total</strong>', ['@total' => number_format($average_payment_data['total_revenue'], 2)]);
      }
      $build['average_payment_summary'] = [
        '#type' => 'markup',
        '#markup' => implode('<br>', array_map('strval', $summaryLines)),
        '#prefix' => '<div class="makerspace-dashboard-definition">',
        '#suffix' => '</div>',
      ];
    }

    if (!empty($chargebee_plan_data)) {
      $build['chargebee_plans'] = [
        '#type' => 'chart',
        '#chart_type' => 'pie',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Chargebee plan distribution'),
        '#description' => $this->t('Active users grouped by Chargebee plan assignment.'),
        '#data_labels' => TRUE,
        '#raw_options' => [
          'plugins' => [
            'legend' => [
              'position' => 'top',
            ],
            'datalabels' => [
              'formatter' => "function(value, ctx) { var data = ctx.chart.data.datasets[0].data; var total = data.reduce(function(acc, curr){ return acc + curr; }, 0); if (!total) { return '0%'; } var pct = (value / total) * 100; return pct.toFixed(1) + '%'; }",
            ],
          ],
        ],
      ];
      $build['chargebee_plans']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Users'),
        '#data' => array_values($chargebee_plan_data),
      ];
      $build['chargebee_plans']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => array_map('strval', array_keys($chargebee_plan_data)),
      ];
      $build['chargebee_plans_info'] = $this->buildChartInfo([
        $this->t('Source: user profile field_user_chargebee_plan for published users.'),
        $this->t('Processing: Counts distinct users per plan value; empty values are labeled "Unassigned".'),
        $this->t('Definitions: Reflects CRM state only—verify against Chargebee before billing changes.'),
      ]);
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['profile_list'],
    ];

    return $build;
  }

}
