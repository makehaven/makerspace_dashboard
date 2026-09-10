<?php

namespace Drupal\Tests\makerspace_dashboard\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_dashboard\Service\DevelopmentDataService;

/**
 * Verifies submitted counts against real funding rows, including duplicates.
 *
 * @group makerspace_dashboard
 */
class GrantSubmittedCountTest extends KernelTestBase {

  /**
   * Tests the same deleted-funder exclusion in annual and monthly aggregates.
   */
  public function testDeletedFunderExclusion(): void {
    $database = $this->container->get('database');
    $schema = $database->schema();
    $schema->createTable('civicrm_contact', [
      'fields' => [
        'id' => ['type' => 'int', 'not null' => TRUE],
        'is_deleted' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['id'],
    ]);
    $schema->createTable('civicrm_value_funding_7', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
        'entity_id' => ['type' => 'int'],
        'date_due_21' => ['type' => 'varchar', 'length' => 19],
        'grant_status_14' => ['type' => 'varchar', 'length' => 32],
        'submitted_link_22' => ['type' => 'varchar', 'length' => 255],
      ],
      'primary key' => ['id'],
    ]);
    $database->insert('civicrm_contact')->fields(['id', 'is_deleted'])
      ->values([1, 0])->values([2, 1])->execute();
    $insert = $database->insert('civicrm_value_funding_7')->fields([
      'entity_id', 'date_due_21', 'grant_status_14', 'submitted_link_22',
    ]);
    foreach ([
      [1, '2026-07-01 00:00:00', 'waiting', ''],
      [2, '2026-07-01 00:00:00', 'waiting', ''],
      [1, '2026-08-01 00:00:00', 'researching', 'https://example.org/submitted'],
      [2, '2026-08-01 00:00:00', 'researching', 'https://example.org/submitted'],
      [1, '2026-08-02 00:00:00', 'researching', ''],
      [1, '2026-08-03 00:00:00', 'won', ''],
      [1, '2026-08-04 00:00:00', 'lost', ''],
      [1, '2026-08-05 00:00:00', 'abandoned', ''],
      [1, '2026-12-01 00:00:00', 'waiting', ''],
      [1, '2025-12-01 00:00:00', 'won', ''],
      [2, '2025-12-01 00:00:00', 'won', ''],
      [1, NULL, 'won', ''],
      [999, '2026-07-01 00:00:00', 'waiting', ''],
    ] as $row) {
      $insert->values($row);
    }
    $insert->execute();
    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(strtotime('2026-09-10 12:00:00 UTC'));
    $service = new DevelopmentDataService($database, new MemoryBackend($time), $time, $this->container->get('config.factory'));

    $this->assertSame(5, $service->getGrantSubmittedYtdCount(2026));
    $this->assertSame([1, 4, 0], $service->getGrantSubmittedTrend(3));
    $this->assertSame(1, $service->getGrantSubmittedYtdCount(2025));
    $this->assertSame([0, 0, 0, 1, 0, 0, 0, 0, 0, 0, 1, 4, 0], $service->getGrantSubmittedTrend(13));
    $this->assertSame(5, $service->getGrantSubmittedYtdCount(2026));
  }

}
