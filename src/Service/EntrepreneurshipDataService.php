<?php

namespace Drupal\makerspace_dashboard\Service;

use DateTimeImmutable;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;

/**
 * Provides aggregates for member entrepreneurship interests.
 */
class EntrepreneurshipDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Time service.
   */
  protected TimeInterface $time;

  /**
   * Roles treated as active members.
   */
  protected array $memberRoles;

  /**
   * Cache lifetime in seconds.
   */
  protected int $ttl;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, TimeInterface $time, array $member_roles = NULL, int $ttl = 1800) {
    $this->database = $database;
    $this->cache = $cache;
    $this->time = $time;
    $this->memberRoles = $member_roles ?: ['current_member', 'member'];
    $this->ttl = $ttl;
  }

  /**
   * Builds a quarterly trend for entrepreneurship goals among new members.
   */
  public function getEntrepreneurGoalTrend(DateTimeImmutable $start, DateTimeImmutable $end): array {
    $rangeEnd = $end;
    $cid = sprintf('makerspace_dashboard:entrepreneur:goal_trend:%s:%s', $start->format('Ymd'), $rangeEnd->format('Ymd'));
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (
      !$schema->tableExists('profile') ||
      !$schema->tableExists('profile__field_member_goal') ||
      !$schema->tableExists('profile__field_member_join_date')
    ) {
      return [];
    }

    $quarterKeys = $this->buildQuarterKeys($start, $rangeEnd);
    if (empty($quarterKeys)) {
      return [];
    }

    $query = $this->database->select('profile', 'p');
    $query->fields('p', ['uid', 'profile_id', 'created']);
    $query->leftJoin('profile__field_member_goal', 'goal', 'goal.entity_id = p.profile_id AND goal.deleted = 0');
    $query->addField('goal', 'field_member_goal_value', 'goal_value');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->condition('p.created', [
      $start->getTimestamp(),
      $rangeEnd->getTimestamp(),
    ], 'BETWEEN');
    $query->orderBy('p.created', 'ASC');

    $trend = [];
    foreach ($quarterKeys as $quarterKey) {
      $trend[$quarterKey] = [
        'entrepreneur' => 0,
        'seller' => 0,
        'inventor' => 0,
        'other' => 0,
      ];
    }

    $entrepreneurialGoals = ['entrepreneur', 'seller', 'inventor'];
    $memberQuarterFlags = [];
    foreach ($query->execute() as $record) {
      $joinValue = $record->created ?? '';
      $joinDate = DateTimeImmutable::createFromFormat('U', $joinValue);
      if (!$joinDate) {
        continue;
      }
      $quarterKey = $this->buildQuarterKeyFromDate($joinDate);
      if (!isset($trend[$quarterKey])) {
        continue;
      }
      $uid = (int) $record->uid;
      $hash = $quarterKey . ':' . $uid;
      if (!isset($memberQuarterFlags[$hash])) {
        $memberQuarterFlags[$hash] = [
          'entrepreneur' => FALSE,
          'seller' => FALSE,
          'inventor' => FALSE,
          'other' => FALSE,
        ];
      }
      $goalValue = trim((string) ($record->goal_value ?? ''));
      if ($goalValue !== '' && isset($memberQuarterFlags[$hash][$goalValue])) {
        $memberQuarterFlags[$hash][$goalValue] = TRUE;
      }
      elseif ($goalValue === '') {
        $memberQuarterFlags[$hash]['other'] = TRUE;
      }
    }

    foreach ($memberQuarterFlags as $composite => $goals) {
      [$quarterKey] = explode(':', $composite, 2);
      if (!isset($trend[$quarterKey])) {
        continue;
      }
      $tracked = FALSE;
      foreach ($entrepreneurialGoals as $goalKey) {
        if (!empty($goals[$goalKey])) {
          $trend[$quarterKey][$goalKey]++;
          $tracked = TRUE;
        }
      }
      if (!$tracked) {
        $trend[$quarterKey]['other']++;
      }
    }

    $now = (new DateTimeImmutable())->setTimestamp($this->time->getCurrentTime());
    $currentQuarterKey = $this->buildQuarterKeyFromDate($now);
    if (isset($trend[$currentQuarterKey])) {
      unset($trend[$currentQuarterKey]);
      $quarterKeys = array_filter($quarterKeys, fn($key) => $key !== $currentQuarterKey);
    }

    $labels = $quarterKeys;
    $goalTotals = [];
    foreach (array_merge($entrepreneurialGoals, ['other']) as $goalKey) {
      $goalTotals[$goalKey] = [];
      foreach ($labels as $quarterKey) {
        $goalTotals[$goalKey][] = $trend[$quarterKey][$goalKey] ?? 0;
      }
    }

    $data = [
      'labels' => $labels,
      'series' => $goalTotals,
    ];

    $this->cache->set($cid, $data, $this->time->getRequestTime() + $this->ttl, ['profile_list']);

    return $data;
  }

  /**
   * Returns summary totals for entrepreneurship goals.
   */
  public function getEntrepreneurGoalSummary(): array {
    $cid = 'makerspace_dashboard:entrepreneur:goal_summary';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (
      !$schema->tableExists('profile') ||
      !$schema->tableExists('profile__field_member_goal')
    ) {
      return [
        'all_time' => 0,
        'active_goal' => 0,
        'active_members' => 0,
      ];
    }

    $goalKeys = ['entrepreneur', 'seller', 'inventor'];
    $query = $this->database->select('profile', 'p');
    $query->leftJoin('profile__field_member_goal', 'goal', 'goal.entity_id = p.profile_id AND goal.deleted = 0');
    $query->condition('p.type', 'main');
    $query->condition('p.is_default', 1);
    $query->condition('p.status', 1);
    $query->condition('goal.field_member_goal_value', $goalKeys, 'IN');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'total_members');
    $allTime = (int) $query->execute()->fetchField() ?: 0;

    $snapshot = $this->getActiveEntrepreneurSnapshot();
    $activeGoal = (int) ($snapshot['totals']['goal_any'] ?? 0);
    $activeMembers = (int) ($snapshot['totals']['active_members'] ?? 0);

    $summary = [
      'all_time' => $allTime,
      'active_goal' => $activeGoal,
      'active_members' => $activeMembers,
    ];

    $this->cache->set($cid, $summary, $this->time->getRequestTime() + $this->ttl, ['profile_list', 'user_list']);

    return $summary;
  }

  /**
   * Summarizes active members with entrepreneurship goals or experience.
   */
  public function getActiveEntrepreneurSnapshot(): array {
    $cid = 'makerspace_dashboard:entrepreneur:active_snapshot';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $schema = $this->database->schema();
    if (
      !$schema->tableExists('users_field_data') ||
      !$schema->tableExists('user__roles') ||
      !$schema->tableExists('profile') ||
      !$schema->tableExists('profile__field_member_goal') ||
      !$schema->tableExists('profile__field_member_entrepreneurship')
    ) {
      return [];
    }

    $query = $this->database->select('users_field_data', 'u');
    $query->addField('u', 'uid');
    $query->condition('u.status', 1);
    $query->innerJoin('user__roles', 'ur', 'ur.entity_id = u.uid');
    $query->condition('ur.roles_target_id', $this->memberRoles, 'IN');
    $query->innerJoin('profile', 'p', 'p.uid = u.uid AND p.type = :type_main AND p.is_default = 1 AND p.status = 1', [':type_main' => 'main']);
    $query->leftJoin('profile__field_member_goal', 'goal', 'goal.entity_id = p.profile_id AND goal.deleted = 0');
    $query->leftJoin('profile__field_member_entrepreneurship', 'exp', 'exp.entity_id = p.profile_id AND exp.deleted = 0');
    $goalExpressions = [
      'entrepreneur' => "MAX(CASE WHEN goal.field_member_goal_value = 'entrepreneur' THEN 1 ELSE 0 END)",
      'seller' => "MAX(CASE WHEN goal.field_member_goal_value = 'seller' THEN 1 ELSE 0 END)",
      'inventor' => "MAX(CASE WHEN goal.field_member_goal_value = 'inventor' THEN 1 ELSE 0 END)",
    ];
    foreach ($goalExpressions as $alias => $expression) {
      $query->addExpression($expression, $alias . '_goal_flag');
    }
    $query->addExpression("MAX(CASE WHEN exp.field_member_entrepreneurship_value = 'serial_entrepreneur' THEN 1 ELSE 0 END)", 'serial_flag');
    $query->addExpression("MAX(CASE WHEN exp.field_member_entrepreneurship_value = 'patent' THEN 1 ELSE 0 END)", 'patent_flag');
    $query->groupBy('u.uid');

    $totals = [
      'active_members' => 0,
    ];
    $goalCounts = [
      'entrepreneur' => 0,
      'seller' => 0,
      'inventor' => 0,
      'other' => 0,
    ];
    $goalMembers = [];
    $experience = [
      'serial_entrepreneur' => 0,
      'patent' => 0,
    ];

    foreach ($query->execute() as $record) {
      $totals['active_members']++;
      $goalTracked = FALSE;
      foreach (array_keys($goalExpressions) as $goalKey) {
        if ((int) $record->{$goalKey . '_goal_flag'} === 1) {
          $goalCounts[$goalKey]++;
          $goalTracked = TRUE;
        }
      }
      if (!$goalTracked) {
        $goalCounts['other']++;
      }
      if ($goalTracked) {
        $goalMembers[(int) $record->uid] = TRUE;
      }

      if ((int) $record->serial_flag === 1) {
        $experience['serial_entrepreneur']++;
      }
      if ((int) $record->patent_flag === 1) {
        $experience['patent']++;
      }
    }

    $snapshot = [
      'totals' => [
        'active_members' => $totals['active_members'],
        'goal_counts' => $goalCounts,
        'goal_any' => count($goalMembers),
        'goal_entrepreneur' => $goalCounts['entrepreneur'] ?? 0,
        'goal_other' => $goalCounts['other'] ?? 0,
      ],
      'experience' => [
        [
          'id' => 'serial_entrepreneur',
          'label' => 'Launched multiple businesses',
          'count' => $experience['serial_entrepreneur'],
        ],
        [
          'id' => 'patent',
          'label' => 'Patent pursuits',
          'count' => $experience['patent'],
        ],
      ],
    ];

    $this->cache->set($cid, $snapshot, $this->time->getRequestTime() + $this->ttl, ['profile_list', 'user_list']);

    return $snapshot;
  }

  /**
   * Builds a list of month keys between two dates (inclusive).
   */
  protected function buildQuarterKeys(DateTimeImmutable $start, DateTimeImmutable $end): array {
    $startQuarter = $this->getQuarterStart($start);
    $endQuarter = $this->getQuarterStart($end)->modify('+3 months');
    $period = new \DatePeriod($startQuarter, new \DateInterval('P3M'), $endQuarter);
    $keys = [];
    foreach ($period as $quarter) {
      $keys[] = $this->buildQuarterKeyFromDate($quarter);
    }
    return array_values(array_unique($keys));
  }

  /**
   * Finds the first day of the quarter for the provided date.
   */
  protected function getQuarterStart(DateTimeImmutable $date): DateTimeImmutable {
    $month = (int) $date->format('n');
    $quarterStartMonth = (int) (floor(($month - 1) / 3) * 3) + 1;
    return $date->setDate((int) $date->format('Y'), $quarterStartMonth, 1)->setTime(0, 0);
  }

  /**
   * Returns a quarter key (e.g., 2025-Q1) from a date.
   */
  protected function buildQuarterKeyFromDate(DateTimeImmutable $date): string {
    $year = (int) $date->format('Y');
    $quarter = (int) floor(((int) $date->format('n') - 1) / 3) + 1;
    return sprintf('%d-Q%d', $year, $quarter);
  }


}
