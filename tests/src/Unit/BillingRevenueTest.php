<?php

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\makerspace_dashboard\Service\BillingRevenueService;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Uses billing-normalized MRR and rejects incomplete API results.
 */
class BillingRevenueTest extends TestCase {

  public function testMonthlyMrrDoesNotTreatAnnualChargesAsMonthlyRevenue(): void {
    $rows = [
      ['customer_id' => 'annual', 'status' => 'active', 'currency_code' => 'USD', 'plan_amount' => 55000, 'mrr' => 4583],
      ['customer_id' => 'discount', 'status' => 'active', 'currency_code' => 'USD', 'plan_amount' => 5000, 'mrr' => 4000],
      ['customer_id' => 'annual', 'status' => 'non_renewing', 'currency_code' => 'USD', 'mrr' => 1000],
      ['customer_id' => 'paused', 'status' => 'paused', 'currency_code' => 'USD', 'mrr' => 5000],
      ['customer_id' => 'trial', 'status' => 'in_trial', 'currency_code' => 'USD', 'mrr' => 5000],
      ['customer_id' => 'missing', 'status' => 'active', 'currency_code' => 'USD'],
      ['customer_id' => 'foreign', 'status' => 'active', 'currency_code' => 'EUR', 'mrr' => 5000],
      ['customer_id' => 'zero', 'status' => 'active', 'currency_code' => 'USD', 'mrr' => 0],
    ];
    $result = BillingRevenueService::monthlyRevenueByCustomer($rows);
    $this->assertSame(55.83, $result['annual']['amount']);
    $this->assertSame(40.0, $result['discount']['amount']);
    $this->assertSame(0.0, $result['paused']['amount']);
    $this->assertSame(0.0, $result['trial']['amount']);
    $this->assertFalse($result['missing']['complete']);
    $this->assertFalse($result['foreign']['complete']);
    $this->assertTrue($result['zero']['complete']);
  }

  public function testPaginationFailureCannotCacheAPartialTotal(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnMap([
      ['live_api_key', 'fixture'], ['live_portal_url', 'https://example.chargebee.com'],
    ]);
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $cache = $this->createMock(CacheBackendInterface::class);
    $cache->method('get')->willReturn(FALSE);
    $cache->expects($this->never())->method('set');
    $http = $this->createMock(ClientInterface::class);
    $calls = 0;
    $http->expects($this->exactly(2))->method('request')->willReturnCallback(static function () use (&$calls) {
      if (++$calls === 2) {
        throw new \RuntimeException('Do not expose request details');
      }
      return new Response(200, [], json_encode(['list' => [['subscription' => ['id' => 'one', 'customer_id' => 'customer']]], 'next_offset' => 'next']));
    });
    $this->expectExceptionMessage('Chargebee revenue inventory could not be completed.');
    (new BillingRevenueService($factory, $http, $cache))->getInventory();
  }

}
