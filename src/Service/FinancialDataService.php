<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;

/**
 * Service to query financial data for the dashboard.
 */
class FinancialDataService {

  /**
   * Database connection.
   */
  protected Connection $database;

  /**
   * Cache backend.
   */
  protected CacheBackendInterface $cache;

  /**
   * Date formatter service.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, DateFormatterInterface $dateFormatter) {
    $this->database = $database;
    $this->cache = $cache;
    $this->dateFormatter = $dateFormatter;
  }

  /**
   * Gets monthly recurring revenue (MRR) trend.
   *
   * @param \DateTimeImmutable $start_date
   *   The start date for the query range.
   * @param \DateTimeImmutable $end_date
   *   The end date for the query range.
   *
   * @return array
   *   An array of MRR data.
   */
  public function getMrrTrend(\DateTimeImmutable $start_date, \DateTimeImmutable $end_date): array {
    $cid = 'makerspace_dashboard:mrr_trend:' . $start_date->getTimestamp() . ':' . $end_date->getTimestamp();
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile__field_membership_type', 'pmt');
    $query->join('profile__field_member_join_date', 'pmjd', 'pmt.entity_id = pmjd.entity_id');
    $query->join('taxonomy_term_field_data', 'tfd', 'pmt.field_membership_type_target_id = tfd.tid');
    $query->fields('pmjd', ['field_member_join_date_value']);
    $query->fields('tfd', ['name']);
    $query->condition('pmjd.field_member_join_date_value', [$start_date->format('Y-m-d'), $end_date->format('Y-m-d')], 'BETWEEN');
    $results = $query->execute()->fetchAll();

    $mrr = [];
    foreach ($results as $result) {
      $month = $this->dateFormatter->format(strtotime($result->field_member_join_date_value), 'custom', 'Y-m');
      if (!isset($mrr[$month])) {
        $mrr[$month] = 0;
      }
      // This is a simplified calculation. A real implementation would be more complex.
      if (strpos(strtolower($result->name), 'individual') !== false) {
        $mrr[$month] += 50;
      }
      elseif (strpos(strtolower($result->name), 'family') !== false) {
        $mrr[$month] += 75;
      }
    }
    ksort($mrr);

    $labels = array_keys($mrr);
    $data = array_values($mrr);

    $trend = [
      'labels' => $labels,
      'data' => $data,
    ];

    $this->cache->set($cid, $trend, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);

    return $trend;
  }

  /**
   * Gets payment mix data.
   *
   * @return array
   *   An array of payment mix data.
   */
  public function getPaymentMix(): array {
    $cid = 'makerspace_dashboard:payment_mix';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->database->select('profile__field_membership_type', 'pmt');
    $query->join('taxonomy_term_field_data', 'tfd', 'pmt.field_membership_type_target_id = tfd.tid');
    $query->addExpression('COUNT(pmt.entity_id)', 'count');
    $query->fields('tfd', ['name']);
    $query->groupBy('tfd.name');
    $results = $query->execute()->fetchAll();

    $mix = [];
    foreach ($results as $result) {
      $mix[$result->name] = (int) $result->count;
    }

    $this->cache->set($cid, $mix, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);

    return $mix;
  }

  /**
   * Computes average recorded monthly payment amount by membership type.
   */
  public function getAverageMonthlyPaymentByType(): array {
    $cid = 'makerspace_dashboard:avg_payment_by_type';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('profile__field_member_payment_monthly')) {
      return ['types' => [], 'overall' => 0.0];
    }

    $query = $this->database->select('profile__field_member_payment_monthly', 'payment');
    $query->innerJoin('profile', 'p', 'p.profile_id = payment.entity_id');
    $query->condition('p.type', 'main');
    $query->condition('p.status', 1);
    $query->condition('p.is_default', 1);
    $query->innerJoin('users_field_data', 'u', 'u.uid = p.uid');
    $query->condition('u.status', 1);
    $query->leftJoin('profile__field_membership_type', 'membership_type', 'membership_type.entity_id = p.profile_id AND membership_type.deleted = 0');
    $query->leftJoin('taxonomy_term_field_data', 'term', 'term.tid = membership_type.field_membership_type_target_id');
    $query->condition('payment.deleted', 0);
    $query->isNotNull('payment.field_member_payment_monthly_value');
    $query->where("payment.field_member_payment_monthly_value <> ''");

    $query->addExpression("COALESCE(term.name, 'Unknown')", 'membership_type');
    $query->addExpression('AVG(payment.field_member_payment_monthly_value)', 'avg_payment');
    $query->addExpression('COUNT(DISTINCT p.uid)', 'member_count');

    $query->groupBy('membership_type');
    $query->orderBy('avg_payment', 'DESC');

    $results = $query->execute();

    $typeStats = [];
    $totalAmount = 0;
    $totalMembers = 0;

    foreach ($results as $record) {
      $avg = (float) $record->avg_payment;
      $count = (int) $record->member_count;
      $total = $avg * $count;
      $typeStats[$record->membership_type] = [
        'average' => round($avg, 2),
        'members' => $count,
        'total' => round($total, 2),
      ];
      $totalAmount += $total;
      $totalMembers += $count;
    }

    ksort($typeStats);

    $overallAverage = $totalMembers > 0 ? round($totalAmount / $totalMembers, 2) : 0.0;

    $data = [
      'types' => $typeStats,
      'overall_average' => $overallAverage,
      'total_revenue' => round($totalAmount, 2),
      'total_members' => $totalMembers,
    ];

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['profile_list']);

    return $data;
  }

  /**
   * Returns Chargebee plan distribution for active users.
   */
  public function getChargebeePlanDistribution(): array {
    $cid = 'makerspace_dashboard:chargebee_plan_distribution';
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    if (!$this->database->schema()->tableExists('user__field_user_chargebee_plan')) {
      return [];
    }

    $query = $this->database->select('user__field_user_chargebee_plan', 'plan');
    $query->addExpression("COALESCE(NULLIF(plan.field_user_chargebee_plan_value, ''), 'Unassigned')", 'plan_label');
    $query->addExpression('COUNT(DISTINCT plan.entity_id)', 'member_count');
    $query->innerJoin('users_field_data', 'u', 'u.uid = plan.entity_id');
    $query->condition('u.status', 1);
    $query->groupBy('plan_label');
    $query->orderBy('member_count', 'DESC');

    $data = [];
    foreach ($query->execute() as $record) {
      $data[$record->plan_label] = (int) $record->member_count;
    }

    $this->cache->set($cid, $data, CacheBackendInterface::CACHE_PERMANENT, ['user_list']);

    return $data;
  }

}
