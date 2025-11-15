<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Computes utilization window metrics shared across charts.
 */
class UtilizationWindowService {

  protected ConfigFactoryInterface $configFactory;
  protected UtilizationDataService $utilizationDataService;
  protected TimeInterface $time;
  protected DateFormatterInterface $dateFormatter;

  /**
   * Cached metrics keyed by config hash.
   *
   * @var array<string, array>
   */
  protected array $cache = [];

  public function __construct(ConfigFactoryInterface $configFactory, UtilizationDataService $utilizationDataService, TimeInterface $time, DateFormatterInterface $dateFormatter) {
    $this->configFactory = $configFactory;
    $this->utilizationDataService = $utilizationDataService;
    $this->time = $time;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * Returns utilization metrics for the configured rolling windows.
   */
  public function getWindowMetrics(): array {
    $config = $this->configFactory->get('makerspace_dashboard.settings');
    $dailyWindow = max(7, (int) ($config->get('utilization.daily_window_days') ?? 90));
    $rollingWindow = max($dailyWindow, (int) ($config->get('utilization.rolling_window_days') ?? 365));
    $hash = sprintf('%d:%d', $dailyWindow, $rollingWindow);
    if (isset($this->cache[$hash])) {
      return $this->cache[$hash];
    }

    $timezone = new \DateTimeZone(date_default_timezone_get());
    $endOfDay = (new \DateTimeImmutable('@' . $this->time->getRequestTime()))
      ->setTimezone($timezone)
      ->setTime(23, 59, 59);
    $startAll = $endOfDay->sub(new \DateInterval('P' . max(0, $rollingWindow - 1) . 'D'))->setTime(0, 0, 0);
    $rollingStart = $endOfDay->sub(new \DateInterval('P' . max(0, $dailyWindow - 1) . 'D'))->setTime(0, 0, 0);

    $dailyCounts = $this->utilizationDataService->getDailyUniqueEntries($startAll->getTimestamp(), $endOfDay->getTimestamp());
    $allDates = [];
    $allData = [];
    $current = $startAll;
    $totalWindowDays = (int) $startAll->diff($endOfDay)->format('%a') + 1;
    for ($i = 0; $i < $totalWindowDays; $i++) {
      $dayKey = $current->format('Y-m-d');
      $allDates[] = $current;
      $allData[] = $dailyCounts[$dayKey] ?? 0;
      $current = $current->add(new \DateInterval('P1D'));
    }

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
    $monthlySlice = array_slice(array_keys($monthlyBuckets), -12);
    $monthlyLabels = [];
    $monthlyData = [];
    foreach ($monthlySlice as $key) {
      $monthlyLabels[] = $monthlyBuckets[$key]['label'];
      $monthlyData[] = $monthlyBuckets[$key]['value'];
    }

    $rollingAverage = [];
    $rollingLabels = [];
    $trendLine = [];
    $window = 7;
    $runningSum = 0;
    $rollingCounts = array_slice($allData, -$rollingWindow);
    $rollingDates = array_slice($allDates, -$rollingWindow);
    $sumX = 0;
    $sumY = 0;
    $sumXY = 0;
    $sumXX = 0;

    foreach ($rollingCounts as $i => $count) {
      $runningSum += $count;
      if ($i >= $window) {
        $runningSum -= $rollingCounts[$i - $window];
      }
      $divisor = $i < $window - 1 ? $i + 1 : $window;
      $average = $divisor > 0 ? round($runningSum / $divisor, 2) : 0;
      $rollingAverage[] = $average;

      $day = $rollingDates[$i];
      if ($i === 0 || $i === count($rollingCounts) - 1 || $day->format('j') === '1') {
        $rollingLabels[] = $this->dateFormatter->format($day->getTimestamp(), 'custom', 'M Y');
      }
      else {
        $rollingLabels[] = '';
      }

      $sumX += $i;
      $sumY += $average;
      $sumXY += $i * $average;
      $sumXX += $i * $i;
    }

    $slope = NULL;
    $intercept = NULL;
    $count = count($rollingCounts);
    $denominator = ($count * $sumXX) - ($sumX * $sumX);
    if ($count > 1 && abs($denominator) > 0.0001) {
      $slope = (($count * $sumXY) - ($sumX * $sumY)) / $denominator;
      $intercept = (($sumY - ($slope * $sumX)) / $count);
      foreach ($rollingCounts as $i => $_) {
        $trendLine[] = round(($slope * $i) + $intercept, 2);
      }
    }

    $monthlyTotals = array_slice($allData, -$dailyWindow);
    $totalEntries = array_sum($monthlyTotals);
    $averagePerDay = $dailyWindow > 0 ? round($totalEntries / $dailyWindow, 2) : 0;
    $maxValue = max($monthlyTotals ?: [0]);
    $maxIndex = $maxValue > 0 ? array_search($maxValue, $monthlyTotals, TRUE) : 0;
    $dailyDates = array_slice($allDates, -$dailyWindow);
    $maxLabel = isset($dailyDates[$maxIndex]) ? $this->dateFormatter->format($dailyDates[$maxIndex]->getTimestamp(), 'custom', 'M j, Y') : '';

    $weekdayTotals = array_fill(0, 7, 0.0);
    $weekdayCounts = array_fill(0, 7, 0);
    foreach ($rollingDates as $index => $date) {
      $weekday = (int) $date->format('w');
      $weekdayTotals[$weekday] += $rollingCounts[$index] ?? 0;
      $weekdayCounts[$weekday]++;
    }
    $weekdayLabels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $weekdayAverages = [];
    foreach ($weekdayLabels as $idx => $label) {
      $count = $weekdayCounts[$idx] ?: 1;
      $weekdayAverages[] = round($weekdayTotals[$idx] / $count, 2);
    }

    $frequencyBuckets = $this->utilizationDataService->getVisitFrequencyBuckets($rollingStart->getTimestamp(), $endOfDay->getTimestamp());
    $hourlyAverages = $this->utilizationDataService->getAverageEntriesByHour($rollingStart->getTimestamp(), $endOfDay->getTimestamp());
    $firstEntryBuckets = $this->utilizationDataService->getFirstEntryBucketsByWeekday($rollingStart->getTimestamp(), $endOfDay->getTimestamp());
    $timeOfDayLabels = $this->utilizationDataService->getTimeOfDayBucketLabels();

    $summary = [
      'total_entries' => $totalEntries,
      'average_per_day' => $averagePerDay,
      'max_value' => $maxValue,
      'max_label' => $maxLabel,
      'slope' => $slope,
      'intercept' => $intercept,
      'days' => $dailyWindow,
    ];

    return $this->cache[$hash] = [
      'daily_window' => $dailyWindow,
      'rolling_window' => $rollingWindow,
      'start_all' => $startAll,
      'end_of_day' => $endOfDay,
      'rolling_start' => $rollingStart,
      'monthly_labels' => $monthlyLabels,
      'monthly_data' => $monthlyData,
      'rolling_average' => $rollingAverage,
      'rolling_labels' => $rollingLabels,
      'trend_line' => $trendLine,
      'weekday_averages' => $weekdayAverages,
      'weekday_labels' => $weekdayLabels,
      'frequency_buckets' => $frequencyBuckets,
      'hourly_averages' => $hourlyAverages,
      'first_entry_buckets' => $firstEntryBuckets,
      'time_of_day_labels' => $timeOfDayLabels,
      'summary' => $summary,
    ];
  }

}
