<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Reads Chargebee's monthly-normalized MRR without changing billing records.
 */
class BillingRevenueService {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * Returns a complete current subscription inventory, or fails closed.
   */
  public function getInventory(): array {
    $cid = 'makerspace_dashboard:billing_mrr:v1';
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $config = $this->configFactory->get('chargebee_portal.settings');
    $key = $config->get('live_api_key');
    $host = parse_url((string) $config->get('live_portal_url'), PHP_URL_HOST);
    if (!$key || !$host || !str_ends_with($host, '.chargebee.com')) {
      throw new \RuntimeException('Chargebee reporting connection is unavailable.');
    }
    $subscriptions = [];
    $offset = NULL;
    $seen = [];
    try {
      do {
        $query = ['limit' => 100];
        if ($offset !== NULL) {
          $query['offset'] = $offset;
        }
        $response = $this->httpClient->request('GET', 'https://' . $host . '/api/v2/subscriptions', [
          'auth' => [$key, ''],
          'query' => $query,
          'timeout' => 30,
        ]);
        $page = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
        if (!isset($page['list']) || !is_array($page['list'])) {
          throw new \RuntimeException('Invalid subscription inventory.');
        }
        foreach ($page['list'] as $entry) {
          $subscription = $entry['subscription'] ?? [];
          if (empty($subscription['id']) || empty($subscription['customer_id'])) {
            throw new \RuntimeException('Incomplete subscription record.');
          }
          $subscriptions[$subscription['id']] = array_intersect_key($subscription, array_flip([
            'customer_id', 'status', 'currency_code', 'mrr', 'plan_amount', 'billing_period', 'billing_period_unit',
          ]));
        }
        $offset = $page['next_offset'] ?? NULL;
        if ($offset !== NULL) {
          if (isset($seen[$offset]) || count($seen) >= 100) {
            throw new \RuntimeException('Incomplete subscription pagination.');
          }
          $seen[$offset] = TRUE;
        }
      } while ($offset !== NULL);
    }
    catch (\Throwable $e) {
      // Do not expose HTTP credentials, customer data, or a partial total.
      throw new \RuntimeException('Chargebee revenue inventory could not be completed.');
    }
    $data = ['subscriptions' => array_values($subscriptions), 'fetched_at' => time()];
    $this->cache->set($cid, $data, time() + 3600, ['config:chargebee_portal.settings']);
    return $data;
  }

  /**
   * Groups active/non-renewing MRR; trials, pauses and cancellations are zero.
   */
  public static function monthlyRevenueByCustomer(array $subscriptions): array {
    $customers = [];
    foreach ($subscriptions as $subscription) {
      $id = $subscription['customer_id'];
      $customers[$id] ??= ['amount' => 0.0, 'complete' => TRUE];
      if (!in_array($subscription['status'] ?? '', ['active', 'non_renewing'], TRUE)) {
        continue;
      }
      if (($subscription['currency_code'] ?? '') !== 'USD' || !isset($subscription['mrr']) || !is_numeric($subscription['mrr']) || $subscription['mrr'] < 0) {
        $customers[$id]['complete'] = FALSE;
        continue;
      }
      // Chargebee has already normalized annual/multi-month billing and applied
      // its configured MRR treatment of recurring discounts and add-ons.
      $customers[$id]['amount'] += (float) $subscription['mrr'] / 100;
    }
    return $customers;
  }

}
