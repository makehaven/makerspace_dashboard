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
class InfrastructureSection extends DashboardSectionBase {

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
    return 'infrastructure';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Infrastructure');
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

    $monthlyBuckets = [];
    foreach ($allDates as $index => $date) {
      $monthKey = $date->format('Y-m');
      if (!isset($monthlyBuckets[$monthKey])) {
        $monthlyBuckets[$monthKey] = [
          'label' => $this->dateFormatter->format($date->getTimestamp(), 'custom', 'M Y'),
          'value' => 0,
        ];
      }
      $monthlyBuckets[$monthKey]['value'] += $allData[$index];
    }
    $monthlyKeys = array_keys($monthlyBuckets);
    $monthlySlice = array_slice($monthlyKeys, -12);
    $monthlyLabels = [];
    $monthlyData = [];
    foreach ($monthlySlice as $key) {
      $monthlyLabels[] = $monthlyBuckets[$key]['label'];
      $monthlyData[] = $monthlyBuckets[$key]['value'];
    }

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
      '#title' => $this->t('Monthly member entries (unique visits)'),
      '#description' => $this->t('Sums the number of unique members badging in per day into monthly totals.'),
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
      '#title' => $this->t('Unique entries'),
      '#data' => $monthlyData,
    ];

    $build['daily_unique_entries']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#title' => $this->t('Month'),
      '#labels' => $monthlyLabels,
    ];

    $build['daily_unique_entries']['yaxis'] = [
      '#type' => 'chart_yaxis',
      '#title' => $this->t('Unique members'),
    ];
    $build['daily_unique_entries_info'] = $this->buildChartInfo([
      $this->t('Source: Access-control request logs (type = access_control_request) joined to the requesting user.'),
      $this->t('Processing: Counts distinct members per day within the configured window, then aggregates those counts into monthly totals (latest 12 months).'),
      $this->t('Definitions: Only users with active membership roles (defaults: current_member, member) are included.'),
    ]);

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
      $build['rolling_average_chart_info'] = $this->buildChartInfo([
        $this->t('Source: Seven-day rolling average derived from the daily unique member counts.'),
        $this->t('Processing: Uses a sliding seven-day window (or shorter for the first few points) and overlays a least-squares trendline.'),
        $this->t('Definitions: Positive slope indicates growing average traffic; negative slope signals declining activity.'),
      ]);
    }

    $frequency_buckets = $this->dataService->getVisitFrequencyBuckets($rollingStartDate->getTimestamp(), $end_of_day->getTimestamp());
    $bucket_labels = [
      'no_visits' => $this->t('No visits'),
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
      '#chart_type' => 'bar',
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
    $build['frequency_buckets_info'] = $this->buildChartInfo([
      $this->t('Source: Distinct visit days per member calculated from access-control logs over the rolling window.'),
      $this->t('Processing: Normalizes visits to a 30-day window before bucketing (none, 1, 2-3, 4-6, 7-12, 13+ per month equivalent).'),
      $this->t('Definitions: Each active member appears in exactly one bucket; members with no visits during the window are counted explicitly.'),
    ]);

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
      $build['weekday_profile_info'] = $this->buildChartInfo([
        $this->t('Source: Same daily unique member dataset used for the rolling average chart.'),
        $this->t('Processing: Totals unique members per weekday over the configured window and divides by the number of occurrences.'),
        $this->t('Definitions: Values represent average unique members per weekday; blank weekdays indicate no data within the window.'),
      ]);
    }

    $timeOfDayLabels = $this->dataService->getTimeOfDayBucketLabels();
    $firstEntryBuckets = $this->dataService->getFirstEntryBucketsByWeekday($rollingStartDate->getTimestamp(), $end_of_day->getTimestamp());
    $weekdayOrder = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $bucketOrder = array_keys($timeOfDayLabels);
    $firstEntryLabels = array_map(fn($day) => $this->t($day), $weekdayOrder);

    $firstEntryChart = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'chartjs',
      '#title' => $this->t('First entry time by weekday (stacked)'),
      '#description' => $this->t('Shows when members first badge in each day, grouped by weekday and time-of-day bucket.'),
      '#stacking' => 1,
    ];

    foreach ($bucketOrder as $index => $bucketId) {
      $series = [];
      foreach (range(0, 6) as $weekdayIndex) {
        $series[] = $firstEntryBuckets[$weekdayIndex][$bucketId] ?? 0;
      }
      $firstEntryChart['series_' . $index] = [
        '#type' => 'chart_data',
        '#title' => $timeOfDayLabels[$bucketId],
        '#data' => $series,
      ];
    }

    $firstEntryChart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $firstEntryLabels,
    ];
    $firstEntryChart['yaxis'] = [
      '#type' => 'chart_yaxis',
      '#title' => $this->t('Members (first entry)'),
    ];

    $build['weekday_first_entry'] = $firstEntryChart;
    $build['weekday_first_entry_info'] = $this->buildChartInfo([
      $this->t('Source: Access-control requests within the rolling window grouped by member, day, and time of day.'),
      $this->t('Processing: Members are counted once per weekday/time bucket (early morning, morning, midday, afternoon, evening, night) for each day they badge in that range.'),
      $this->t('Definitions: Buckets use 24-hour ranges; night spans 22:00-04:59 and rolls across midnight.'),
    ]);

    $build['#cache'] = [
      'max-age' => 3600,
      'contexts' => ['timezone'],
      'tags' => ['access_control_log_list', 'user_list', 'config:makerspace_dashboard.settings'],
    ];

    return $build;
  }

}
