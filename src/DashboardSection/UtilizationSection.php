<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\makerspace_dashboard\Service\UtilizationDataService;

/**
 * Provides utilization insights.
 */
class UtilizationSection extends DashboardSectionBase {

  /**
   * Date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Time service used for date window calculations.
   */
  protected TimeInterface $time;

  /**
   * Utilization data service.
   */
  protected UtilizationDataService $dataService;

  /**
   * Config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs the utilization section.
   */
  public function __construct(DateFormatterInterface $date_formatter, TimeInterface $time, UtilizationDataService $data_service, ConfigFactoryInterface $config_factory) {
    parent::__construct();
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->dataService = $data_service;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'utilization';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Utilization');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $config = $this->configFactory->get('makerspace_dashboard.settings');
    $dailyWindow = max(7, (int) ($config->get('utilization.daily_window_days') ?? 90));
    $rollingWindow = max($dailyWindow, (int) ($config->get('utilization.rolling_window_days') ?? 365));
    $window = 7;

    $timezone = new \DateTimeZone(date_default_timezone_get());
    $end_of_day = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($timezone)
      ->setTime(23, 59, 59);

    $totalWindowDays = max($dailyWindow, $rollingWindow);
    $totalInterval = new \DateInterval('P' . max(0, $totalWindowDays - 1) . 'D');
    $startAll = $end_of_day->sub($totalInterval)->setTime(0, 0, 0);

    $daily_counts = $this->dataService->getDailyUniqueEntries($startAll->getTimestamp(), $end_of_day->getTimestamp());

    $allDates = [];
    $allData = [];
    $current = $startAll;
    for ($i = 0; $i < $totalWindowDays; $i++) {
      $day_key = $current->format('Y-m-d');
      $allDates[] = $current;
      $allData[] = $daily_counts[$day_key] ?? 0;
      $current = $current->add(new \DateInterval('P1D'));
    }

    $dailyData = array_slice($allData, -$dailyWindow);
    $dailyDates = array_slice($allDates, -$dailyWindow);

    $dailyLabels = [];
    $dailyCount = count($dailyDates);
    foreach ($dailyDates as $index => $date) {
      if ($index === 0 || $index === $dailyCount - 1 || $date->format('j') === '1') {
        $dailyLabels[] = $this->dateFormatter->format($date->getTimestamp(), 'custom', 'M Y');
      }
      else {
        $dailyLabels[] = '';
      }
    }

    $rollingDates = array_slice($allDates, -$rollingWindow);
    $rollingCounts = array_slice($allData, -$rollingWindow);

    $rollingLabels = [];
    $rollingAverage = [];
    $running_sum = 0;
    $rollingCount = count($rollingCounts);
    $sumX = 0;
    $sumY = 0;
    $sumXY = 0;
    $sumXX = 0;
    for ($i = 0; $i < $rollingCount; $i++) {
      $running_sum += $rollingCounts[$i];
      if ($i >= $window) {
        $running_sum -= $rollingCounts[$i - $window];
      }
      $divisor = $i < $window - 1 ? $i + 1 : $window;
      $rollingAverage[] = $divisor > 0 ? round($running_sum / $divisor, 2) : 0;

      $day = $rollingDates[$i];
      if ($i === 0 || $i === $rollingCount - 1 || $day->format('j') === '1') {
        $rollingLabels[] = $this->dateFormatter->format($day->getTimestamp(), 'custom', 'M Y');
      }
      else {
        $rollingLabels[] = '';
      }

      $x = $i;
      $y = $rollingAverage[$i];
      $sumX += $x;
      $sumY += $y;
      $sumXY += $x * $y;
      $sumXX += $x * $x;
    }

    $trendLine = [];
    $slope = NULL;
    $intercept = NULL;
    if ($rollingCount > 1) {
      $denominator = $rollingCount * $sumXX - ($sumX * $sumX);
      $slope = $denominator ? (($rollingCount * $sumXY) - ($sumX * $sumY)) / $denominator : 0;
      $intercept = ($sumY - $slope * $sumX) / $rollingCount;
      for ($i = 0; $i < $rollingCount; $i++) {
        $trendLine[] = round($slope * $i + $intercept, 2);
      }
    }

    $total_entries = array_sum($dailyData);
    $average_per_day = $dailyWindow > 0 ? round($total_entries / $dailyWindow, 2) : 0;
    $max_value = 0;
    $max_label = $this->t('n/a');
    foreach ($dailyData as $index => $value) {
      if ($value > $max_value) {
        $max_value = $value;
        $max_label = $dailyLabels[$index];
      }
    }

    $weekdayTotals = array_fill(0, 7, 0);
    $weekdayCounts = array_fill(0, 7, 0);
    foreach ($rollingDates as $index => $date) {
      $weekday = (int) $date->format('w');
      $weekdayTotals[$weekday] += $rollingCounts[$index];
      $weekdayCounts[$weekday]++;
    }
    $weekdayLabels = [];
    $weekdayAverages = [];
    $weekdayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    foreach ($weekdayNames as $index => $shortName) {
      $weekdayLabels[] = $this->t($shortName);
      $count = $weekdayCounts[$index] ?: 1;
      $weekdayAverages[] = round($weekdayTotals[$index] / $count, 2);
    }

    $build = [];
    $rollingStartDate = $rollingDates[0] ?? $startAll;
    $range_start_label = $this->dateFormatter->format($rollingStartDate->getTimestamp(), 'custom', 'M j, Y');
    $range_end_label = $this->dateFormatter->format($end_of_day->getTimestamp(), 'custom', 'M j, Y');

    $build['intro'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Aggregates door access requests into unique members per day to highlight peak usage windows. Showing @start – @end.', [
        '@start' => $range_start_label,
        '@end' => $range_end_label,
      ]),
    ];

    $summary = [
      $this->t('Total entries in last @days days: @total', ['@days' => $dailyWindow, '@total' => $total_entries]),
      $this->t('Average per day: @avg', ['@avg' => $average_per_day]),
      $this->t('Busiest day: @day (@count members)', ['@day' => $max_label, '@count' => $max_value]),
    ];
    if ($slope !== NULL) {
      $summary[] = $this->t('Rolling trend: Δ = @per_day members/day (@per_week / week, @per_month / month)', [
        '@per_day' => round($slope, 3),
        '@per_week' => round($slope * 7, 2),
        '@per_month' => round($slope * 30, 2),
      ]);
      $summary[] = $this->t('Trendline: y = @m x + @b', [
        '@m' => round($slope, 3),
        '@b' => round($intercept, 2),
      ]);
    }
    $build['summary'] = [
      '#theme' => 'item_list',
      '#items' => array_filter($summary),
      '#attributes' => ['class' => ['makerspace-dashboard-summary']],
    ];

    $build['definition_note'] = [
      '#type' => 'markup',
      '#markup' => $this->t('An entry counts every access-control request. A unique entry counts each member once per day, even if they badge multiple times.'),
      '#prefix' => '<div class="makerspace-dashboard-definition">',
      '#suffix' => '</div>',
    ];

    $build['daily_unique_entries'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Daily unique members entering (last @days days)', ['@days' => $dailyWindow]),
      '#description' => $this->t('Counts distinct members with the Current Member role who badge in each day.'),
    ];
    $build['daily_unique_entries']['#raw_options']['options']['scales']['x']['ticks'] = [
      'autoSkip' => FALSE,
      'maxRotation' => 0,
      'minRotation' => 0,
      'padding' => 8,
      'callback' => 'function(value){ return value || ""; }',
    ];

    $build['daily_unique_entries']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Members per day'),
      '#data' => $dailyData,
    ];

    $build['daily_unique_entries']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#title' => $this->t('Date'),
      '#labels' => $dailyLabels,
    ];

    $build['daily_unique_entries']['yaxis'] = [
      '#type' => 'chart_yaxis',
      '#title' => $this->t('Unique members'),
    ];

    if (!empty($rollingAverage)) {
      $build['rolling_average_chart'] = [
        '#type' => 'chart',
        '#chart_type' => 'line',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('7 day rolling average'),
        '#description' => $this->t('Smooths daily fluctuations to highlight longer-term trends over the last @days days.', ['@days' => $rollingWindow]),
      ];
      $build['rolling_average_chart']['#raw_options']['options']['scales']['x']['ticks'] = [
        'autoSkip' => FALSE,
        'maxRotation' => 0,
        'minRotation' => 0,
        'padding' => 8,
        'callback' => 'function(value){ return value || ""; }',
      ];
      $build['rolling_average_chart']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Rolling average'),
        '#data' => $rollingAverage,
        '#settings' => [
          'borderColor' => '#ff7f0e',
          'backgroundColor' => 'rgba(255,127,14,0.2)',
          'fill' => FALSE,
          'tension' => 0.3,
          'borderWidth' => 2,
          'pointRadius' => 0,
        ],
      ];
      if (!empty($trendLine)) {
        $build['rolling_average_chart']['trend'] = [
          '#type' => 'chart_data',
          '#title' => $this->t('Trend'),
          '#data' => $trendLine,
          '#settings' => [
            'borderColor' => '#2ca02c',
            'borderDash' => [6, 4],
            'fill' => FALSE,
            'pointRadius' => 0,
            'borderWidth' => 2,
          ],
        ];
      }
      $build['rolling_average_chart']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $rollingLabels,
      ];
      $build['rolling_average_chart']['yaxis'] = [
        '#type' => 'chart_yaxis',
        '#title' => $this->t('Average unique members'),
      ];
    }

    $frequency_buckets = $this->dataService->getVisitFrequencyBuckets($rollingStartDate->getTimestamp(), $end_of_day->getTimestamp());
    $bucket_labels = [
      'one_per_month' => $this->t('1 visit / month'),
      'two_to_three' => $this->t('2-3 visits / month'),
      'weekly' => $this->t('Weekly (4-6)'),
      'twice_weekly' => $this->t('2-3 per week'),
      'daily_plus' => $this->t('Daily or more'),
    ];
    $frequency_data = [];
    $frequency_label_values = [];
    foreach ($bucket_labels as $bucket_key => $label) {
      $frequency_data[] = $frequency_buckets[$bucket_key] ?? 0;
      $frequency_label_values[] = $label;
    }

    $build['frequency_buckets'] = [
      '#type' => 'chart',
      '#chart_type' => 'pie',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('Visit frequency distribution'),
      '#description' => $this->t('Distribution of member visit frequency based on distinct check-in days over the last @days days.', ['@days' => $rollingWindow]),
    ];

    $build['frequency_buckets']['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Share of members'),
      '#data' => $frequency_data,
    ];

    $build['frequency_buckets']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $frequency_label_values,
    ];

    if (!empty($rollingDates)) {
      $build['weekday_profile'] = [
        '#type' => 'chart',
        '#chart_type' => 'bar',
        '#chart_library' => 'chartjs',
        '#title' => $this->t('Average members by day of week'),
        '#description' => $this->t('Average unique members badging in on each weekday over the last @days days.', ['@days' => $rollingWindow]),
      ];
      $build['weekday_profile']['series'] = [
        '#type' => 'chart_data',
        '#title' => $this->t('Average members'),
        '#data' => $weekdayAverages,
      ];
      $build['weekday_profile']['xaxis'] = [
        '#type' => 'chart_xaxis',
        '#labels' => $weekdayLabels,
      ];
    }

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['access_control_log_list', 'user_list', 'config:makerspace_dashboard.settings'],
    ];

    return $build;
  }

}
