<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Aggregates appointment insights for retention dashboards.
 */
class AppointmentInsightsService {

  /**
   * Time buckets covering the first year of membership.
   */
  protected const BUCKETS = [
    'first_month' => [
      'label' => 'First 30 days',
      'min' => 0,
      'max' => 30,
    ],
    'first_three_months' => [
      'label' => 'Days 31-90',
      'min' => 31,
      'max' => 90,
    ],
    'first_year' => [
      'label' => 'Days 91-365',
      'min' => 91,
      'max' => 365,
    ],
  ];

  /**
   * Appointment purpose machine names mapped to human-readable labels.
   */
  protected const PURPOSES = [
    'informational' => 'General informational',
    'checkout' => 'Badge checkout',
    'project' => 'Project advice',
    'other' => 'Other / noted in record',
    '_none' => 'Unspecified',
  ];

  /**
   * Result options recorded after appointments.
   */
  protected const RESULT_KEYS = [
    'met_successful',
    'met_unsuccesful',
    'member_absent',
    'volunteer_absent',
    '_none',
  ];

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
  }

  /**
   * Gets appointment purpose counts within the first year of membership.
   */
  public function getOnboardingPurposeSummary(): array {
    $cid = 'makerspace_dashboard:appointment_onboarding_purposes_v2';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $definitions = self::BUCKETS;
    $purposeKeys = array_keys(self::PURPOSES);
    $buckets = [];
    foreach ($definitions as $key => $definition) {
      $buckets[$key] = [
        'label' => $definition['label'],
        'members' => 0,
        'purpose_counts' => array_fill_keys($purposeKeys, 0),
      ];
    }

    $totalMembers = $this->countMemberProfiles();

    $appointments = $this->loadFirstYearAppointments();
    $engagedMembers = [];
    foreach ($appointments as $row) {
      $bucketKey = $this->determineBucket((int) $row['days_after_join']);
      if (!$bucketKey) {
        continue;
      }
      $uid = (int) $row['uid'];
      if (!isset($engagedMembers[$uid])) {
        $purposeKey = $this->normalizePurpose($row['purpose']);
        $buckets[$bucketKey]['members']++;
        $buckets[$bucketKey]['purpose_counts'][$purposeKey]++;
        $engagedMembers[$uid] = TRUE;
      }
    }

    $engagedCount = count($engagedMembers);
    $result = [
      'buckets' => $buckets,
      'purpose_keys' => $purposeKeys,
      'totals' => [
        'members_cohort' => $totalMembers,
        'engaged_members' => $engagedCount,
        'non_engaged_members' => max(0, $totalMembers - $engagedCount),
      ],
    ];

    $this->cache->set($cid, $result, $this->buildTtl(), ['node_list', 'node_list:appointment', 'profile_list']);
    return $result;
  }

  /**
   * Returns monthly appointment outcomes + feedback completion rates.
   */
  public function getFeedbackOutcomeSeries(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $startKey = $start->format('Ymd');
    $endKey = $end->format('Ymd');
    $cid = "makerspace_dashboard:appointment_feedback:$startKey:$endKey";
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $months = $this->buildMonthRange($start, $end);
    if (empty($months)) {
      return [];
    }

    $resultKeys = self::RESULT_KEYS;
    $resultSeries = [];
    $monthlyTotals = [];
    $monthlyFeedback = [];
    foreach ($months as $key => $label) {
      $resultSeries[$key] = array_fill_keys($resultKeys, 0);
      $monthlyTotals[$key] = 0;
      $monthlyFeedback[$key] = 0;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_appointment_date', 'date_field', 'date_field.entity_id = n.nid AND date_field.deleted = 0');
    $query->leftJoin('node__field_appointment_result', 'result', 'result.entity_id = n.nid AND result.deleted = 0');
    $query->leftJoin('node__field_appointment_feedback', 'feedback', 'feedback.entity_id = n.nid AND feedback.deleted = 0');
    $query->leftJoin('node__field_appointment_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->addExpression("DATE_FORMAT(date_field.field_appointment_date_value, '%Y-%m')", 'month_key');
    $query->addExpression("COALESCE(result.field_appointment_result_value, '_none')", 'result_key');
    $query->addExpression('COUNT(*)', 'appointments');
    $query->addExpression("SUM(CASE WHEN feedback.field_appointment_feedback_value IS NULL OR feedback.field_appointment_feedback_value = '' THEN 0 ELSE 1 END)", 'feedback_count');
    $query->condition('n.type', 'appointment');
    $query->condition('n.status', 1);
    $query->condition('date_field.field_appointment_date_value', [
      $start->format('Y-m-d'),
      $end->format('Y-m-d'),
    ], 'BETWEEN');
    $query->condition('status.field_appointment_status_value', 'canceled', '<>');
    $query->groupBy('month_key');
    $query->groupBy('result_key');

    foreach ($query->execute() as $row) {
      $monthKey = $row->month_key;
      if (!isset($resultSeries[$monthKey])) {
        continue;
      }
      $resultKey = $row->result_key ?: '_none';
      if (!isset($resultSeries[$monthKey][$resultKey])) {
        $resultSeries[$monthKey][$resultKey] = 0;
      }
      $count = (int) $row->appointments;
      $feedbackCount = (int) $row->feedback_count;
      $resultSeries[$monthKey][$resultKey] += $count;
      $monthlyTotals[$monthKey] += $count;
      $monthlyFeedback[$monthKey] += $feedbackCount;
    }

    $labels = array_values($months);
    $resultDatasets = [];
    foreach ($resultKeys as $resultKey) {
      $resultDatasets[$resultKey] = [];
      foreach (array_keys($months) as $monthKey) {
        $resultDatasets[$resultKey][] = $resultSeries[$monthKey][$resultKey] ?? 0;
      }
    }

    $feedbackRates = [];
    foreach (array_keys($months) as $monthKey) {
      $total = (int) $monthlyTotals[$monthKey];
      $feedbackCounts = (int) $monthlyFeedback[$monthKey];
      $feedbackRates[] = $total > 0 ? round(($feedbackCounts / $total) * 100, 1) : 0;
    }

    $totals = [
      'appointments' => array_sum($monthlyTotals),
      'feedback' => array_sum($monthlyFeedback),
    ];
    $totals['rate'] = $totals['appointments'] > 0
      ? round(($totals['feedback'] / $totals['appointments']) * 100, 1)
      : 0;

    $data = [
      'labels' => $labels,
      'month_keys' => array_keys($months),
      'monthly_totals' => array_map('intval', array_values($monthlyTotals)),
      'results' => $resultDatasets,
      'feedback_rates' => $feedbackRates,
      'totals' => $totals,
    ];

    $this->cache->set($cid, $data, $this->buildTtl(), ['node_list', 'node_list:appointment']);
    return $data;
  }

  /**
   * Returns total non-canceled appointments per month for a date window.
   */
  public function getMonthlyAppointmentVolumeSeries(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $startKey = $start->format('Ymd');
    $endKey = $end->format('Ymd');
    $cid = "makerspace_dashboard:appointment_volume:$startKey:$endKey";
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $months = $this->buildMonthRange($start, $end);
    if (empty($months)) {
      return [];
    }

    $monthlyTotals = array_fill_keys(array_keys($months), 0);
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_appointment_date', 'date_field', 'date_field.entity_id = n.nid AND date_field.deleted = 0');
    $query->leftJoin('node__field_appointment_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->addExpression("DATE_FORMAT(date_field.field_appointment_date_value, '%Y-%m')", 'month_key');
    $query->addExpression('COUNT(*)', 'appointments');
    $query->condition('n.type', 'appointment');
    $query->condition('n.status', 1);
    $query->condition('date_field.field_appointment_date_value', [
      $start->format('Y-m-d'),
      $end->format('Y-m-d'),
    ], 'BETWEEN');
    $query->condition('status.field_appointment_status_value', 'canceled', '<>');
    $query->groupBy('month_key');

    foreach ($query->execute() as $row) {
      $monthKey = (string) ($row->month_key ?? '');
      if (isset($monthlyTotals[$monthKey])) {
        $monthlyTotals[$monthKey] = (int) ($row->appointments ?? 0);
      }
    }

    $counts = array_values($monthlyTotals);
    $data = [
      'labels' => array_values($months),
      'month_keys' => array_keys($months),
      'counts' => array_map('intval', $counts),
      'totals' => [
        'appointments' => array_sum($counts),
        'monthly_average' => count($counts) > 0 ? round(array_sum($counts) / count($counts), 1) : 0,
      ],
    ];

    $this->cache->set($cid, $data, $this->buildTtl(), ['node_list', 'node_list:appointment']);
    return $data;
  }

  /**
   * Exposes the configured onboarding buckets.
   */
  public function getBucketDefinitions(): array {
    return self::BUCKETS;
  }

  /**
   * Gets the known appointment purpose keys.
   */
  public function getPurposeKeys(): array {
    return array_keys(self::PURPOSES);
  }

  /**
   * Map of appointment purposes to default labels.
   */
  public function getPurposeLabels(): array {
    return self::PURPOSES;
  }

  /**
   * Gets the known appointment result keys.
   */
  public function getResultKeys(): array {
    return self::RESULT_KEYS;
  }

  /**
   * Provides a reusable base appointment query with standard joins/filters.
   */
  protected function baseAppointmentQuery(): SelectInterface {
    $query = $this->database->select('node_field_data', 'n');
    $query->innerJoin('node__field_appointment_date', 'date_field', 'date_field.entity_id = n.nid AND date_field.deleted = 0');
    $query->leftJoin('node__field_appointment_purpose', 'purpose', 'purpose.entity_id = n.nid AND purpose.deleted = 0');
    $query->leftJoin('node__field_appointment_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->innerJoin('profile', 'profile_main', 'profile_main.uid = n.uid AND profile_main.type = :profile_type AND profile_main.is_default = 1', [
      ':profile_type' => 'main',
    ]);
    $query->condition('n.type', 'appointment');
    $query->condition('n.status', 1);
    $query->condition('date_field.field_appointment_date_value', NULL, 'IS NOT NULL');
    $query->condition('profile_main.created', 0, '>');
    $query->condition('status.field_appointment_status_value', 'canceled', '<>');
    return $query;
  }

  /**
   * Builds the CASE statement used to bucket appointments.
   */
  protected function determineBucket(int $daysAfterJoin): ?string {
    foreach (self::BUCKETS as $key => $bucket) {
      if ($daysAfterJoin >= $bucket['min'] && $daysAfterJoin <= $bucket['max']) {
        return $key;
      }
    }
    return NULL;
  }

  protected function loadFirstYearAppointments(): array {
    $query = $this->database->select('node_field_data', 'n');
    $query->addField('n', 'uid');
    $query->addField('date_field', 'field_appointment_date_value', 'appointment_date');
    $query->addField('purpose', 'field_appointment_purpose_value', 'purpose');
    $query->addExpression('DATEDIFF(date_field.field_appointment_date_value, FROM_UNIXTIME(profile_main.created))', 'days_after_join');
    $query->innerJoin('node__field_appointment_date', 'date_field', 'date_field.entity_id = n.nid AND date_field.deleted = 0');
    $query->leftJoin('node__field_appointment_purpose', 'purpose', 'purpose.entity_id = n.nid AND purpose.deleted = 0');
    $query->leftJoin('node__field_appointment_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->innerJoin('profile', 'profile_main', 'profile_main.uid = n.uid AND profile_main.type = :profile_type AND profile_main.is_default = 1', [
      ':profile_type' => 'main',
    ]);
    $query->condition('n.type', 'appointment');
    $query->condition('n.status', 1);
    $query->condition('status.field_appointment_status_value', 'canceled', '<>');
    $query->condition('date_field.field_appointment_date_value', NULL, 'IS NOT NULL');
    $query->where('DATEDIFF(date_field.field_appointment_date_value, FROM_UNIXTIME(profile_main.created)) BETWEEN 0 AND 365');
    $query->orderBy('n.uid', 'ASC');
    $query->orderBy('date_field.field_appointment_date_value', 'ASC');

    $results = [];
    foreach ($query->execute() as $row) {
      $results[] = [
        'uid' => (int) $row->uid,
        'appointment_date' => $row->appointment_date,
        'purpose' => $row->purpose,
        'days_after_join' => (int) $row->days_after_join,
      ];
    }
    return $results;
  }

  protected function normalizePurpose(?string $purpose): string {
    $key = $purpose ?? '';
    return $key !== '' ? $key : '_none';
  }

  protected function countMemberProfiles(): int {
    $query = $this->database->select('profile', 'p');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->addExpression('COUNT(DISTINCT p.uid)', 'total');
    return (int) $query->execute()->fetchField();
  }

  /**
   * Builds a month => label map between the provided dates.
   */
  protected function buildMonthRange(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    if ($end < $start) {
      return [];
    }
    $months = [];
    $cursor = $start->setTime(0, 0)->modify('first day of this month');
    $last = $end->setTime(0, 0)->modify('first day of this month');
    while ($cursor <= $last) {
      $months[$cursor->format('Y-m')] = $cursor->format('M Y');
      $cursor = $cursor->modify('+1 month');
    }
    return $months;
  }

  /**
   * Shared cache TTL for appointment analytics (1 hour).
   */
  protected function buildTtl(): int {
    return 3600;
  }

}
