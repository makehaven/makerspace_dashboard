<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Aggregates basic contact list metrics from CiviCRM.
 */
class ContactDataService {

  protected Connection $database;

  protected CacheBackendInterface $cache;

  protected \DateTimeZone $timezone;

  protected ?string $smsConsentTable = NULL;

  protected ?string $smsConsentColumn = NULL;

  protected bool $smsMetadataLoaded = FALSE;

  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
    $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * Returns cumulative contact, email, and SMS counts over time.
   */
  public function getContactGrowthSeries(int $months = 36): array {
    $months = max(1, $months);
    $cacheId = sprintf('makerspace_dashboard:contacts:%d', $months);
    if ($cached = $this->cache->get($cacheId)) {
      return $cached->data;
    }

    $now = $this->now();
    $monthMap = $this->buildMonthSeries($now, $months);
    if (empty($monthMap)) {
      return [];
    }

    $monthKeys = array_keys($monthMap);
    $start = $this->createDateFromKey(reset($monthKeys))->setTime(0, 0, 0);
    $end = $this->createDateFromKey(end($monthKeys))->modify('last day of this month')->setTime(23, 59, 59);

    $baseline = $this->countContactsBefore($start);
    $monthly = $this->countContactsByMonth($start, $end);

    $totals = [];
    $email = [];
    $sms = [];

    $runningTotal = $baseline['total'];
    $runningEmail = $baseline['email'];
    $runningSms = $baseline['sms'];

    foreach ($monthKeys as $key) {
      $delta = $monthly[$key] ?? ['total' => 0, 'email' => 0, 'sms' => 0];
      $runningTotal += $delta['total'];
      $runningEmail += $delta['email'];
      $runningSms += $delta['sms'];
      $totals[] = $runningTotal;
      $email[] = $runningEmail;
      $sms[] = $runningSms;
    }

    $result = [
      'labels' => array_values($monthMap),
      'totals' => $totals,
      'email' => $email,
      'sms' => $sms,
      'current' => [
        'total' => $runningTotal,
        'email' => $runningEmail,
        'sms' => $runningSms,
      ],
    ];

    $this->cache->set($cacheId, $result, $this->now()->getTimestamp() + 3600);
    return $result;
  }

  /**
   * Counts contacts created before the provided date.
   */
  protected function countContactsBefore(\DateTimeImmutable $cutoff): array {
    $query = $this->baseContactQuery();
    $this->applyContactJoins($query);
    $smsAlias = $this->applySmsConsentJoin($query);
    $this->addCountExpressions($query, $smsAlias);
    $query->condition('c.created_date', $cutoff->format('Y-m-d H:i:s'), '<');
    $record = $query->execute()->fetchAssoc();
    return $record ? $this->normalizeCounts($record) : ['total' => 0, 'email' => 0, 'sms' => 0];
  }

  /**
   * Counts contacts grouped by creation month within the window.
   */
  protected function countContactsByMonth(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $query = $this->baseContactQuery();
    $this->applyContactJoins($query);
    $query->addExpression("DATE_FORMAT(c.created_date, '%Y-%m')", 'month_key');
    $smsAlias = $this->applySmsConsentJoin($query);
    $this->addCountExpressions($query, $smsAlias);
    $query->condition('c.created_date', [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
    $query->groupBy('month_key');

    $rows = [];
    foreach ($query->execute() as $record) {
      $monthKey = is_array($record) ? ($record['month_key'] ?? NULL) : ($record->month_key ?? NULL);
      if (!$monthKey) {
        continue;
      }
      $rows[(string) $monthKey] = $this->normalizeCounts((array) $record);
    }
    return $rows;
  }

  /**
   * Normalizes count result rows.
   */
  protected function normalizeCounts(array $record): array {
    return [
      'total' => (int) ($record['total_contacts'] ?? 0),
      'email' => (int) ($record['email_contacts'] ?? 0),
      'sms' => (int) ($record['sms_contacts'] ?? 0),
    ];
  }

  /**
   * Base query for civicrm_contact.
   */
  protected function baseContactQuery(): SelectInterface {
    $query = $this->database->select('civicrm_contact', 'c');
    $query->condition('c.is_deleted', 0);
    $query->condition('c.contact_type', ['Individual', 'Household', 'Organization'], 'IN');
    $query->condition('c.created_date', NULL, 'IS NOT NULL');
    return $query;
  }

  /**
   * Joins primary email and phone tables.
   */
  protected function applyContactJoins(SelectInterface $query): void {
    $query->leftJoin('civicrm_email', 'email', 'email.contact_id = c.id AND email.is_primary = 1');
    $query->leftJoin('civicrm_phone', 'phone', 'phone.contact_id = c.id AND phone.is_primary = 1');
  }

  /**
   * Adds expressions counting total/email/sms contacts.
   */
  protected function addCountExpressions(SelectInterface $query, ?string $smsAlias = NULL): void {
    $query->addExpression('COUNT(c.id)', 'total_contacts');
    $query->addExpression(
      'SUM(CASE WHEN email.id IS NOT NULL AND COALESCE(email.on_hold, 0) = 0 AND COALESCE(c.do_not_email, 0) = 0 AND COALESCE(c.is_opt_out, 0) = 0 THEN 1 ELSE 0 END)',
      'email_contacts'
    );
    $smsConsentCondition = '1=1';
    if ($smsAlias && $this->smsConsentColumn) {
      $smsConsentCondition = sprintf('COALESCE(%s.%s, 0) = 1', $smsAlias, $this->smsConsentColumn);
    }
    $query->addExpression(sprintf(
      'SUM(CASE WHEN phone.id IS NOT NULL AND COALESCE(c.do_not_sms, 0) = 0 AND %s THEN 1 ELSE 0 END)',
      $smsConsentCondition
    ), 'sms_contacts');
  }

  /**
   * Builds month labels keyed by YYYY-MM.
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

  /**
   * Left joins the SMS consent custom field table when available.
   */
  protected function applySmsConsentJoin(SelectInterface $query): ?string {
    if (!$this->ensureSmsConsentMetadata()) {
      return NULL;
    }
    $alias = 'sms_pref';
    $query->leftJoin($this->smsConsentTable, $alias, "$alias.entity_id = c.id");
    return $alias;
  }

  /**
   * Loads the SMS consent custom table metadata.
   */
  protected function ensureSmsConsentMetadata(): bool {
    if ($this->smsMetadataLoaded) {
      return $this->smsConsentTable !== NULL && $this->smsConsentColumn !== NULL;
    }
    $this->smsMetadataLoaded = TRUE;
    $query = $this->database->select('civicrm_custom_group', 'cg');
    $query->innerJoin('civicrm_custom_field', 'cf', 'cf.custom_group_id = cg.id');
    $query->addField('cg', 'table_name');
    $query->addField('cf', 'column_name');
    $query->condition('cg.name', 'SMS_Preferences');
    $query->condition('cf.name', 'SMS_Consent');
    if ($record = $query->execute()->fetchAssoc()) {
      $this->smsConsentTable = $record['table_name'] ?? NULL;
      $this->smsConsentColumn = $record['column_name'] ?? NULL;
    }
    return $this->smsConsentTable !== NULL && $this->smsConsentColumn !== NULL;
  }

}
