<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Aggregates event evaluation insights from the primary webform.
 */
class EducationEvaluationDataService {

  protected const WEBFORM_ID = 'webform_1181';
  protected const TYPE_FALLBACK = 'Unspecified';

  protected Connection $database;

  protected CacheBackendInterface $cache;

  protected \DateTimeZone $timezone;

  /**
   * Constructs the service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache) {
    $this->database = $database;
    $this->cache = $cache;
    $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * Builds average satisfaction by event type.
   */
  public function getSatisfactionByType(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $cid = $this->buildCacheId('satisfaction_type', $start, $end);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->baseSubmissionQuery($start, $end);
    $query->addField('type', 'value', 'event_type');
    $query->addField('rating', 'value', 'satisfaction');
    $query->innerJoin('webform_submission_data', 'type', 'type.sid = ws.sid AND type.name = :type_field', [
      ':type_field' => 'what_type_of_event_did_you_attend',
    ]);
    $query->innerJoin('webform_submission_data', 'rating', 'rating.sid = ws.sid AND rating.name = :rating_field', [
      ':rating_field' => 'overall_how_satisfied_were_you_with_the_event',
    ]);

    $totals = [];
    foreach ($query->execute() as $row) {
      $type = trim((string) ($row->event_type ?? ''));
      if ($type === '') {
        $type = self::TYPE_FALLBACK;
      }
      $value = $this->parseNumericValue($row->satisfaction);
      if ($value <= 0) {
        continue;
      }
      if (!isset($totals[$type])) {
        $totals[$type] = [
          'type' => $type,
          'sum' => 0.0,
          'count' => 0,
        ];
      }
      $totals[$type]['sum'] += $value;
      $totals[$type]['count']++;
    }

    $data = [];
    foreach ($totals as $type => $info) {
      if ($info['count'] <= 0) {
        continue;
      }
      $data[] = [
        'type' => $type,
        'average' => round($info['sum'] / $info['count'], 2),
        'responses' => $info['count'],
      ];
    }

    usort($data, static function (array $a, array $b) {
      if ($a['responses'] === $b['responses']) {
        return $b['average'] <=> $a['average'];
      }
      return $b['responses'] <=> $a['responses'];
    });

    $this->cache->set($cid, $data, $this->buildTtl(), ['webform_submission_list', 'civicrm_event_list']);
    return $data;
  }

  /**
   * Builds a month-over-month net promoter series.
   */
  public function getNetPromoterSeries(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $cid = $this->buildCacheId('nps_series', $start, $end);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $query = $this->baseSubmissionQuery($start, $end);
    $query->addField('nps', 'value', 'score');
    $query->fields('ws', ['created']);
    $query->innerJoin('webform_submission_data', 'nps', 'nps.sid = ws.sid AND nps.name = :nps_field', [
      ':nps_field' => 'how_likely_are_you_to_recommend_this_event_to_others',
    ]);

    $months = $this->buildMonthMap($start, $end);
    $series = [];
    foreach (array_keys($months) as $key) {
      $series[$key] = [
        'promoters' => 0,
        'detractors' => 0,
        'passives' => 0,
        'total' => 0,
      ];
    }

    $overall = ['promoters' => 0, 'detractors' => 0, 'total' => 0];

    foreach ($query->execute() as $row) {
      $timestamp = (int) $row->created;
      $monthKey = $this->formatMonthKey($timestamp);
      if (!isset($series[$monthKey])) {
        continue;
      }
      $score = (int) $this->parseNumericValue($row->score);
      if ($score <= 0) {
        continue;
      }
      if ($score >= 5) {
        $series[$monthKey]['promoters']++;
        $overall['promoters']++;
      }
      elseif ($score <= 3) {
        $series[$monthKey]['detractors']++;
        $overall['detractors']++;
      }
      else {
        $series[$monthKey]['passives']++;
      }
      $series[$monthKey]['total']++;
      $overall['total']++;
    }

    $labels = [];
    $npsValues = [];
    $promoterRates = [];
    $detractorRates = [];
    $counts = [];
    foreach ($months as $key => $label) {
      $labels[] = $label;
      $bucket = $series[$key];
      $counts[] = $bucket['total'];
      if ($bucket['total'] > 0) {
        $promoterRate = round(($bucket['promoters'] / $bucket['total']) * 100, 1);
        $detractorRate = round(($bucket['detractors'] / $bucket['total']) * 100, 1);
        $promoterRates[] = $promoterRate;
        $detractorRates[] = $detractorRate;
        $npsValues[] = round($promoterRate - $detractorRate, 1);
      }
      else {
        $promoterRates[] = NULL;
        $detractorRates[] = NULL;
        $npsValues[] = NULL;
      }
    }

    $overallRate = [
      'responses' => $overall['total'],
      'promoter_rate' => $overall['total'] ? round(($overall['promoters'] / $overall['total']) * 100, 1) : 0,
      'detractor_rate' => $overall['total'] ? round(($overall['detractors'] / $overall['total']) * 100, 1) : 0,
      'nps' => $overall['total'] ? round((($overall['promoters'] - $overall['detractors']) / $overall['total']) * 100, 1) : 0,
    ];

    $data = [
      'labels' => $labels,
      'nps' => $npsValues,
      'promoter_rates' => $promoterRates,
      'detractor_rates' => $detractorRates,
      'counts' => $counts,
      'overall' => $overallRate,
    ];

    $this->cache->set($cid, $data, $this->buildTtl(), ['webform_submission_list']);
    return $data;
  }

  /**
   * Builds monthly evaluation completion data relative to registrants.
   */
  public function getEvaluationCompletionSeries(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $cid = $this->buildCacheId('evaluation_completion', $start, $end);
    if ($cache = $this->cache->get($cid)) {
      return $cache->data;
    }

    $months = $this->buildMonthMap($start, $end);
    $registrations = array_fill_keys(array_keys($months), 0);
    $evaluations = array_fill_keys(array_keys($months), 0);

    // Registrations per month (counted participant statuses).
    $regQuery = $this->database->select('civicrm_participant', 'p');
    $regQuery->addExpression("DATE_FORMAT(e.start_date, '%Y-%m')", 'month_key');
    $regQuery->addExpression('COUNT(DISTINCT p.id)', 'registrants');
    $regQuery->innerJoin('civicrm_event', 'e', 'e.id = p.event_id');
    $regQuery->innerJoin('civicrm_participant_status_type', 'pst', 'pst.id = p.status_id');
    $regQuery->condition('pst.is_counted', 1);
    $this->applyDateCondition($regQuery, $start, $end, 'e.start_date');
    $regQuery->groupBy('month_key');

    foreach ($regQuery->execute() as $row) {
      $key = (string) $row->month_key;
      if (isset($registrations[$key])) {
        $registrations[$key] += (int) $row->registrants;
      }
    }

    // Evaluation submissions mapped to event month when available.
    $evalQuery = $this->database->select('webform_submission', 'ws');
    $evalQuery->fields('ws', ['created']);
    $evalQuery->addField('event_ref', 'value', 'event_id');
    $evalQuery->addField('e', 'start_date', 'event_start');
    $evalQuery->condition('ws.webform_id', self::WEBFORM_ID);
    $evalQuery->condition('ws.in_draft', 0);
    $evalQuery->leftJoin('webform_submission_data', 'event_ref', 'event_ref.sid = ws.sid AND event_ref.name = :event_field', [
      ':event_field' => 'event_id',
    ]);
    $evalQuery->leftJoin('civicrm_event', 'e', 'e.id = CAST(event_ref.value AS UNSIGNED)');

    $rangeGroup = $evalQuery->orConditionGroup();
    $rangeGroup->condition('e.start_date', [
      $start->format('Y-m-d H:i:s'),
      $end->format('Y-m-d H:i:s'),
    ], 'BETWEEN');
    $fallbackGroup = $evalQuery->andConditionGroup()
      ->isNull('e.start_date')
      ->condition('ws.created', [$start->getTimestamp(), $end->getTimestamp()], 'BETWEEN');
    $rangeGroup->condition($fallbackGroup);
    $evalQuery->condition($rangeGroup);

    foreach ($evalQuery->execute() as $row) {
      $key = $row->event_start
        ? $this->formatMonthKey(strtotime($row->event_start))
        : $this->formatMonthKey((int) $row->created);
      if (!isset($evaluations[$key])) {
        continue;
      }
      $evaluations[$key]++;
    }

    $labels = [];
    $registrationSeries = [];
    $evaluationSeries = [];
    $completionRates = [];
    $totalRegistrations = 0;
    $totalEvaluations = 0;

    foreach ($months as $key => $label) {
      $labels[] = $label;
      $reg = $registrations[$key] ?? 0;
      $eval = $evaluations[$key] ?? 0;
      $registrationSeries[] = $reg;
      $evaluationSeries[] = $eval;
      $totalRegistrations += $reg;
      $totalEvaluations += $eval;
      $completionRates[] = $reg > 0 ? round(($eval / $reg) * 100, 1) : 0;
    }

    $overallRate = $totalRegistrations > 0 ? round(($totalEvaluations / $totalRegistrations) * 100, 1) : 0;

    $data = [
      'labels' => $labels,
      'registrations' => $registrationSeries,
      'evaluations' => $evaluationSeries,
      'completion_rates' => $completionRates,
      'totals' => [
        'registrations' => $totalRegistrations,
        'evaluations' => $totalEvaluations,
        'rate' => $overallRate,
      ],
    ];

    $this->cache->set($cid, $data, $this->buildTtl(), ['webform_submission_list', 'civicrm_participant_list']);
    return $data;
  }

  /**
   * Builds a baseline submission query filtered to the evaluation form.
   */
  protected function baseSubmissionQuery(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): SelectInterface {
    $query = $this->database->select('webform_submission', 'ws');
    $query->condition('ws.webform_id', self::WEBFORM_ID);
    $query->condition('ws.in_draft', 0);
    $this->applyTimestampCondition($query, $start, $end, 'ws.created');
    return $query;
  }

  /**
   * Applies date range conditions to a timestamp column.
   */
  protected function applyTimestampCondition(SelectInterface $query, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end, string $field): void {
    if ($start) {
      $query->condition($field, $start->getTimestamp(), '>=');
    }
    if ($end) {
      $query->condition($field, $end->getTimestamp(), '<=');
    }
  }

  /**
   * Applies date conditions for datetime columns (Y-m-d H:i:s).
   */
  protected function applyDateCondition(SelectInterface $query, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end, string $field): void {
    if ($start && $end) {
      $query->condition($field, [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')], 'BETWEEN');
      return;
    }
    if ($start) {
      $query->condition($field, $start->format('Y-m-d H:i:s'), '>=');
    }
    if ($end) {
      $query->condition($field, $end->format('Y-m-d H:i:s'), '<=');
    }
  }

  /**
   * Formats a timestamp into the canonical month key.
   */
  protected function formatMonthKey(int $timestamp): string {
    $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone)->setTime(0, 0);
    return $date->format('Y-m');
  }

  /**
   * Builds a map of sequential months between two dates.
   */
  protected function buildMonthMap(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
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
   * Parses numeric strings, returning 0 for invalid entries.
   */
  protected function parseNumericValue($value): float {
    if (is_numeric($value)) {
      return (float) $value;
    }
    if (is_string($value)) {
      $filtered = preg_replace('/[^0-9\.\-]/', '', $value);
      return is_numeric($filtered) ? (float) $filtered : 0.0;
    }
    return 0.0;
  }

  /**
   * Builds a cache identifier for the supplied range.
   */
  protected function buildCacheId(string $prefix, ?\DateTimeImmutable $start, ?\DateTimeImmutable $end): string {
    $startKey = $start ? $start->getTimestamp() : 'none';
    $endKey = $end ? $end->getTimestamp() : 'none';
    return "makerspace_dashboard:evaluations:$prefix:$startKey:$endKey";
  }

  /**
   * Determines a cache TTL (default one hour).
   */
  protected function buildTtl(): int {
    return 3600;
  }

}
