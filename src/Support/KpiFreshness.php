<?php

namespace Drupal\makerspace_dashboard\Support;

/**
 * Adds refresh state at read time so cached values cannot freeze their age.
 */
final class KpiFreshness {

  /**
   * Known definition gaps that a successful refresh cannot resolve.
   *
   * Remove each note only after its source reconciliation is complete.
   */
  private const REVIEW_NOTES = [
    'kpi_first_year_member_retention' => 'Definition under review: this cohort can include unconfirmed joins and omit disabled accounts. Check the source before comparing against the goal.',
    'kpi_total_new_recurring_revenue' => 'Reconciliation needed: recorded dues may represent different billing periods. Confirm monthly equivalents before using this figure for a revenue decision.',
  ];

  /**
   * Keeps calculation freshness separate from source observation dates.
   */
  public static function annotate(array $kpis, array $stored, int $now): array {
    $computedAt = (int) ($stored['computed_at'] ?? 0);
    $expiresAt = (int) ($stored['expires_at'] ?? 0);
    $refresh = $computedAt > 0 && $expiresAt > 0
      ? ($expiresAt <= $now ? 'stale' : 'current')
      : 'unknown';
    foreach ($kpis as $id => &$kpi) {
      // Old persisted payloads must wait for a normal refresh before claiming
      // provenance. A cache clear alone does not refresh these payloads.
      $kpi['value_source'] = $kpi['value_source'] ?? 'unverified';
      $kpi['refresh_status'] = $refresh;
      $kpi['computed_at'] = $computedAt ?: NULL;
      if (isset(self::REVIEW_NOTES[$id])) {
        $kpi['quality_note'] = self::REVIEW_NOTES[$id];
      }
      if ($id === 'kpi_workshop_attendees') {
        $kpi['interpretation_note'] = 'Counted registrations, including Registered and Attended statuses. This is not verified attendance.';
      }
    }
    return $kpis;
  }

  /**
   * Only compare a freshly calculated, available value against a target.
   */
  public static function canCompare(array $kpi): bool {
    return ($kpi['value_source'] ?? '') === 'calculated'
      && ($kpi['refresh_status'] ?? '') === 'current'
      && empty($kpi['quality_note'])
      && is_numeric($kpi['current'] ?? NULL);
  }

}
