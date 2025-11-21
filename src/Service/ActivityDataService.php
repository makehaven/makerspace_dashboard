<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides read-only aggregates for CiviCRM activities.
 */
class ActivityDataService {

  protected Connection $database;

  protected CacheBackendInterface $cache;

  protected \DateTimeZone $timezone;

  protected ?int $activityTypeGroupId = NULL;

  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
    $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * Returns stacked monthly activity counts keyed by type.
   */
  public function getMonthlyActivityTypeCounts(int $months = 12, int $typeLimit = 6): array {
    $months = max(1, $months);
    $typeLimit = max(1, $typeLimit);
    $now = $this->now();
    $series = $this->buildMonthSeries($now, $months);
    if (!$series) {
      return [];
    }

    $cacheId = sprintf('makerspace_dashboard:activities:%d:%d', $months, $typeLimit);
    if ($cached = $this->cache->get($cacheId)) {
      return $cached->data;
    }

    $monthKeys = array_keys($series);
    $indexMap = array_flip($monthKeys);
    $start = $this->createDateFromKey(reset($monthKeys))->setTime(0, 0, 0);
    $end = $this->createDateFromKey(end($monthKeys))->modify('last day of this month')->setTime(23, 59, 59);

    $query = $this->database->select('civicrm_activity', 'a');
    $query->addExpression("DATE_FORMAT(a.activity_date_time, '%Y-%m')", 'month_key');
    $query->addExpression('COUNT(*)', 'activity_count');
    
    $labelExpression = "COALESCE(type.label, CONCAT('Type #', a.activity_type_id))";
    $keyExpression = "COALESCE(type.name, CONCAT('type_', a.activity_type_id))";
    $query->addExpression($labelExpression, 'type_label');
    $query->addExpression($keyExpression, 'type_key');

    $groupId = $this->getActivityTypeOptionGroupId();
    if ($groupId) {
      $query->leftJoin('civicrm_option_value', 'type', 'type.value = a.activity_type_id AND type.option_group_id = :group_id', [
        ':group_id' => $groupId,
      ]);
    }
    else {
      $query->leftJoin('civicrm_option_value', 'type', 'type.value = a.activity_type_id');
    }
    $query->condition('a.activity_date_time', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->condition('a.is_test', 0);
    $query->condition('a.is_deleted', 0);
    $query->groupBy('month_key');
    $query->groupBy('type_key');

    $raw = [];
    $totals = [];
    foreach ($query->execute() as $row) {
      $monthKey = (string) $row->month_key;
      if (!isset($indexMap[$monthKey])) {
        continue;
      }
      $typeKey = (string) $row->type_key;
      $raw[$typeKey]['label'] = (string) $row->type_label;
      $raw[$typeKey]['months'][$monthKey] = (int) $row->activity_count;
      $totals[$typeKey] = ($totals[$typeKey] ?? 0) + (int) $row->activity_count;
    }

    if (empty($raw)) {
      return [];
    }

    arsort($totals);
    $topKeys = array_slice(array_keys($totals), 0, $typeLimit - 1, TRUE);
    $datasets = [];
    $otherCounts = array_fill(0, count($monthKeys), 0);

    foreach ($topKeys as $key) {
      $dataset = [
        'key' => $key,
        'label' => $raw[$key]['label'] ?? $key,
        'data' => [],
      ];
      foreach ($monthKeys as $monthKey) {
        $dataset['data'][] = (int) ($raw[$key]['months'][$monthKey] ?? 0);
      }
      $datasets[] = $dataset;
    }

    foreach ($raw as $key => $info) {
      if (in_array($key, $topKeys, TRUE)) {
        continue;
      }
      foreach ($monthKeys as $idx => $monthKey) {
        $otherCounts[$idx] += (int) ($info['months'][$monthKey] ?? 0);
      }
    }
    if (array_sum($otherCounts) > 0) {
      $datasets[] = [
        'key' => 'other',
        'label' => 'Other activity types',
        'data' => $otherCounts,
      ];
    }

    $result = [
      'labels' => array_values($series),
      'datasets' => $datasets,
      'range' => [
        'start' => $start,
        'end' => $end,
      ],
    ];

    $this->cache->set($cacheId, $result, $this->now()->getTimestamp() + 3600);
    return $result;
  }

  /**
   * Looks up the option group id for activity types.
   */
  protected function getActivityTypeOptionGroupId(): ?int {
    if ($this->activityTypeGroupId !== NULL) {
      return $this->activityTypeGroupId;
    }
    $query = $this->database->select('civicrm_option_group', 'g');
    $query->addField('g', 'id');
    $query->condition('g.name', 'activity_type');
    $result = $query->execute()->fetchField();
    $this->activityTypeGroupId = $result ? (int) $result : NULL;
    return $this->activityTypeGroupId;
  }

  /**
   * Builds YYYY-MM => label map.
   */
  protected function buildMonthSeries(\DateTimeImmutable $end, int $months): array {
    $series = [];
    $start = $end->modify('first day of this month')->setTime(0, 0, 0)->modify('-' . ($months - 1) . ' months');
    for ($i = 0; $i < $months; $i++) {
      $month = $start->modify("+$i months");
      $series[$month->format('Y-m')] = $month->format('M Y');
    }
    return $series;
  }

  protected function createDateFromKey(string $key): \DateTimeImmutable {
    return new \DateTimeImmutable($key . '-01', $this->timezone);
  }

  protected function now(): \DateTimeImmutable {
    return new \DateTimeImmutable('now', $this->timezone);
  }

}
