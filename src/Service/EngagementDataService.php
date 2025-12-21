<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;

/**
 * Provides aggregation helpers for engagement metrics.
 */
class EngagementDataService {

  /**
   * Default activation/cohort window in days.
   */
  protected const DEFAULT_WINDOW_DAYS = 90;

  /**
   * Default orientation badge term IDs (Maker Safety).
   */
  protected const DEFAULT_ORIENTATION_BADGES = [270];

  protected Connection $database;

  protected CacheBackendInterface $cache;

  protected ConfigFactoryInterface $configFactory;

  protected TimeInterface $time;

  /**
   * Cached tool-enabled badge term IDs.
   */
  protected ?array $toolBadgeIds = NULL;

  public function __construct(Connection $database, ConfigFactoryInterface $config_factory, TimeInterface $time, CacheBackendInterface $cache) {
    // Inject dependencies.
    $this->database = $database;
    $this->configFactory = $config_factory;
    $this->time = $time;
    $this->cache = $cache;
  }

  /**
   * Returns the activation window in days.
   */
  public function getActivationWindowDays(): int {
    $value = (int) ($this->getSettings()->get('engagement.activation_window_days') ?? self::DEFAULT_WINDOW_DAYS);
    return max(1, $value);
  }

  /**
   * Returns the cohort lookback window in days.
   */
  public function getCohortWindowDays(): int {
    $value = (int) ($this->getSettings()->get('engagement.cohort_window_days') ?? self::DEFAULT_WINDOW_DAYS);
    return max(1, $value);
  }

  /**
   * Returns a date range for the default cohort.
   */
  public function getDefaultRange(\DateTimeImmutable $now): array {
    $days = $this->getCohortWindowDays();
    $end = $now->setTime(23, 59, 59);
    $start = $end->sub(new \DateInterval('P' . max(0, $days - 1) . 'D'))->setTime(0, 0, 0);
    return ['start' => $start, 'end' => $end];
  }

  /**
   * Orientation badge term IDs.
   */
  public function getOrientationBadgeIds(): array {
    $ids = $this->getSettings()->get('engagement.orientation_badge_ids') ?? self::DEFAULT_ORIENTATION_BADGES;
    if (!is_array($ids)) {
      $ids = self::DEFAULT_ORIENTATION_BADGES;
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    return $ids ?: self::DEFAULT_ORIENTATION_BADGES;
  }

  /**
   * Returns an engagement snapshot for the given cohort range.
   */
  public function getEngagementSnapshot(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $members = $this->loadCohortMembers($start, $end);
    if (empty($members)) {
      return [
        'funnel' => [
          'labels' => ['Joined', 'Orientation complete', 'First badge', 'Tool-enabled badge'],
          'counts' => [0, 0, 0, 0],
          'totals' => ['joined' => 0, 'orientation' => 0, 'first_badge' => 0, 'tool_enabled' => 0],
        ],
        'velocity' => [
          'labels' => ['0-3 days', '4-7 days', '8-14 days', '15-30 days', '31-60 days', '60+ days', 'No badge yet'],
          'counts' => array_fill(0, 7, 0),
          'cohort_percent' => 0,
          'median' => 0,
        ],
      ];
    }

    $activationSeconds = $this->getActivationWindowDays() * 86400;
    $events = $this->fetchBadgeEvents($members, $start, $end, $activationSeconds);

    $orientationIds = $this->getOrientationBadgeIds();
    $orientationSet = [];
    $firstBadgeTimes = [];
    $toolSet = [];

    foreach ($members as $uid => $joinTs) {
      if (!isset($events[$uid])) {
        continue;
      }
      foreach ($events[$uid] as $event) {
        if ($event['created'] > $joinTs + $activationSeconds || $event['created'] < $joinTs) {
          continue;
        }
        if (in_array($event['badge_tid'], $orientationIds, TRUE)) {
          $orientationSet[$uid] = TRUE;
        }
        else {
          if (!isset($firstBadgeTimes[$uid]) || $event['created'] < $firstBadgeTimes[$uid]) {
            $firstBadgeTimes[$uid] = $event['created'];
          }
          if ($event['is_tool']) {
            $toolSet[$uid] = TRUE;
          }
        }
      }
    }

    $joinedCount = count($members);
    $orientationCount = count($orientationSet);
    $firstBadgeCount = count($firstBadgeTimes);
    $toolCount = count($toolSet);

    $funnel = [
      'labels' => ['Joined', 'Orientation complete', 'First badge', 'Tool-enabled badge'],
      'counts' => [
        $joinedCount,
        $orientationCount,
        $firstBadgeCount,
        $toolCount,
      ],
      'totals' => [
        'joined' => $joinedCount,
        'orientation' => $orientationCount,
        'first_badge' => $firstBadgeCount,
        'tool_enabled' => $toolCount,
      ],
    ];

    $velocity = $this->buildVelocityBuckets($members, $firstBadgeTimes);

    $badgeVolume = $this->buildBadgeVolumeBuckets($members, $events);

    return [
      'funnel' => $funnel,
      'velocity' => $velocity,
      'badge_volume' => $badgeVolume,
    ];
  }

  /**
   * Loads cohort members keyed by uid => join timestamp.
   */
  protected function loadCohortMembers(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['uid']);
    $query->innerJoin('profile__field_member_join_date', 'join_date', 'join_date.entity_id = p.profile_id AND join_date.deleted = 0');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('join_date.field_member_join_date_value', [$start->format('Y-m-d'), $end->format('Y-m-d')], 'BETWEEN');

    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->addField('join_date', 'field_member_join_date_value', 'join_value');

    $members = [];
    foreach ($query->execute() as $record) {
      $joinValue = trim($record->join_value);
      if ($joinValue === '') {
        continue;
      }
      $joinTs = strtotime($joinValue . ' 00:00:00');
      if ($joinTs === FALSE) {
        continue;
      }
      $members[(int) $record->uid] = $joinTs;
    }
    return $members;
  }

  /**
   * Fetches badge request events for cohort members.
   */
  protected function fetchBadgeEvents(array $members, \DateTimeImmutable $start, \DateTimeImmutable $end, int $activationSeconds): array {
    if (empty($members)) {
      return [];
    }
    $uids = array_keys($members);
    $minJoin = min($members);
    $maxJoin = max($members);
    $upperBound = $maxJoin + $activationSeconds;

    $query = $this->database->select('node_field_data', 'n');
    $query->addField('mtb', 'field_member_to_badge_target_id', 'uid');
    $query->addField('req', 'field_badge_requested_target_id', 'badge_tid');
    $query->addField('n', 'created', 'created');
    $query->leftJoin('taxonomy_term__field_badge_access_control', 'ac', 'ac.entity_id = req.field_badge_requested_target_id AND ac.deleted = 0');
    $query->addField('ac', 'field_badge_access_control_value', 'tool_flag');

    $query->innerJoin('node__field_member_to_badge', 'mtb', 'mtb.entity_id = n.nid AND mtb.deleted = 0');
    $query->innerJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->innerJoin('node__field_badge_requested', 'req', 'req.entity_id = n.nid AND req.deleted = 0');

    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    $query->condition('status.field_badge_status_value', 'active');
    $query->condition('mtb.field_member_to_badge_target_id', $uids, 'IN');
    $query->condition('n.created', [$minJoin, $upperBound], 'BETWEEN');

    $events = [];
    /** @var \stdClass $record */
    foreach ($query->execute() as $record) {
      $uid = (int) $record->uid;
      $created = (int) $record->created;
      $badgeTid = (int) $record->badge_tid;
      $isTool = $record->tool_flag === 'true';
      $events[$uid][] = [
        'badge_tid' => $badgeTid,
        'created' => $created,
        'is_tool' => $isTool,
      ];
    }

    return $events;
  }

  /**
   * Builds histogram buckets for days to first badge.
   */
  protected function buildVelocityBuckets(array $members, array $firstBadgeTimes): array {
    $joinedCount = count($members);
    $activationSeconds = $this->getActivationWindowDays() * 86400;

    $buckets = [
      ['label' => '0-3 days', 'max' => 3, 'count' => 0],
      ['label' => '4-7 days', 'max' => 7, 'count' => 0],
      ['label' => '8-14 days', 'max' => 14, 'count' => 0],
      ['label' => '15-30 days', 'max' => 30, 'count' => 0],
      ['label' => '31-60 days', 'max' => 60, 'count' => 0],
    ];
    $over60 = 0;

    $daysList = [];
    foreach ($members as $uid => $joinTs) {
      if (!isset($firstBadgeTimes[$uid])) {
        continue;
      }
      $days = max(0, (int) floor(($firstBadgeTimes[$uid] - $joinTs) / 86400));
      $daysList[] = $days;
      $placed = FALSE;
      foreach ($buckets as &$bucket) {
        if ($days <= $bucket['max']) {
          $bucket['count']++;
          $placed = TRUE;
          break;
        }
      }
      unset($bucket);
      if (!$placed) {
        $over60++;
      }
    }

    $labels = [];
    $counts = [];
    foreach ($buckets as $bucket) {
      $labels[] = $bucket['label'];
      $counts[] = $bucket['count'];
    }
    $labels[] = '60+ days';
    $counts[] = $over60;
    $labels[] = 'No badge yet';
    $counts[] = max(0, $joinedCount - count($firstBadgeTimes));

    sort($daysList);
    $median = 0;
    $countDays = count($daysList);
    if ($countDays) {
      $middle = intdiv($countDays, 2);
      if ($countDays % 2) {
        $median = $daysList[$middle];
      }
      else {
        $median = ($daysList[$middle - 1] + $daysList[$middle]) / 2;
      }
    }

    $cohortPercent = $joinedCount ? round((count($firstBadgeTimes) / $joinedCount) * 100, 1) : 0;

    return [
      'labels' => $labels,
      'counts' => $counts,
      'median' => $median,
      'cohort_percent' => $cohortPercent,
    ];
  }

  /**
   * Builds histogram for total badges earned within the activation window.
   */
  protected function buildBadgeVolumeBuckets(array $members, array $events): array {
    $activationSeconds = $this->getActivationWindowDays() * 86400;
    $bucketDefs = [
      ['label' => '0-3 days', 'max' => 3, 'count' => 0],
      ['label' => '4-7 days', 'max' => 7, 'count' => 0],
      ['label' => '8-14 days', 'max' => 14, 'count' => 0],
      ['label' => '15-30 days', 'max' => 30, 'count' => 0],
      ['label' => '31-60 days', 'max' => 60, 'count' => 0],
    ];
    $over60 = 0;

    foreach ($members as $uid => $joinTs) {
      if (empty($events[$uid])) {
        continue;
      }
      foreach ($events[$uid] as $event) {
        $created = (int) $event['created'];
        if ($created < $joinTs) {
          continue;
        }
        if ($created > $joinTs + $activationSeconds) {
          continue;
        }
        $days = (int) floor(($created - $joinTs) / 86400);
        $placed = FALSE;
        foreach ($bucketDefs as &$bucket) {
          if ($days <= $bucket['max']) {
            $bucket['count']++;
            $placed = TRUE;
            break;
          }
        }
        unset($bucket);
        if (!$placed) {
          $over60++;
        }
      }
    }

    $labels = [];
    $counts = [];
    foreach ($bucketDefs as $bucket) {
      $labels[] = $bucket['label'];
      $counts[] = $bucket['count'];
    }
    $labels[] = '60+ days';
    $counts[] = $over60;

    return [
      'labels' => $labels,
      'counts' => $counts,
    ];
  }

  /**
   * Returns monthly badge issuance counts for the given period.
   */
  public function getMonthlyBadgeIssuance(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $cid = sprintf('makerspace_dashboard:engagement:monthly_badges:%s:%s', $start->format('Ymd'), $end->format('Ymd'));
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('node__field_badge_status', 'status', 'status.entity_id = n.nid AND status.deleted = 0');
    $query->addExpression("FROM_UNIXTIME(n.created, '%Y-%m-01')", 'month_key');
    $query->addExpression('COUNT(DISTINCT n.nid)', 'badge_count');
    
    $query->condition('n.type', 'badge_request');
    $query->condition('n.status', 1);
    
    $orGroup = $query->orConditionGroup()
      ->condition('status.field_badge_status_value', 'active')
      ->isNull('status.field_badge_status_value');
    $query->condition($orGroup);

    $query->condition('n.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    
    $query->groupBy('month_key');
    $query->orderBy('month_key', 'ASC');

    $results = $query->execute()->fetchAll();

    $dataMap = [];
    foreach ($results as $row) {
      $dataMap[$row->month_key] = (int) $row->badge_count;
    }

    $labels = [];
    $counts = [];
    $current = $start->setTime(0, 0)->modify('first day of this month');
    $last = $end->setTime(0, 0)->modify('first day of this month');

    while ($current <= $last) {
      $key = $current->format('Y-m-01');
      $labels[] = $current->format('M Y');
      $counts[] = $dataMap[$key] ?? 0;
      $current = $current->modify('+1 month');
    }

    $data = [
      'labels' => $labels,
      'counts' => $counts,
    ];

    $this->cache->set($cid, $data, $this->buildTtl(), ['node_list:badge_request']);
    return $data;
  }

  /**
   * Retrieves configuration.
   */
  protected function getSettings() {
    return $this->configFactory->get('makerspace_dashboard.settings');
  }

  /**
   * Shared cache TTL.
   */
  protected function buildTtl(): int {
    return 3600;
  }

}
