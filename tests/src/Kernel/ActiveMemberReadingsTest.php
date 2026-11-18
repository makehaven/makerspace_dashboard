<?php

namespace Drupal\Tests\makerspace_dashboard\Kernel;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\KernelTests\KernelTestBase;
use Drupal\makerspace_dashboard\Service\SnapshotDataService;

/**
 * Active-member readings sit on the day they were counted.
 *
 * Reproduces live rows from 2026: the cron run of Feb 2 was filed as
 * 2026-01-01, which drew February's count under January and left February
 * empty on the Overview chart; a hand-run backfill copied one roster (823)
 * into past months.
 *
 * @group makerspace_dashboard
 */
class ActiveMemberReadingsTest extends KernelTestBase {

  /**
   * Tests placement by capture date and exclusion of backfills.
   */
  public function testReadingsUseCaptureDate(): void {
    date_default_timezone_set('America/New_York');
    $database = $this->container->get('database');
    $schema = $database->schema();
    $schema->createTable('ms_snapshot', [
      'fields' => [
        'id' => ['type' => 'int', 'not null' => TRUE],
        'definition' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
        'snapshot_type' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
        'snapshot_date' => ['type' => 'varchar', 'length' => 10, 'not null' => TRUE],
        'source' => ['type' => 'varchar', 'length' => 32, 'not null' => TRUE],
        'created_at' => ['type' => 'int'],
      ],
      'primary key' => ['id'],
    ]);
    $schema->createTable('ms_fact_org_snapshot', [
      'fields' => [
        'snapshot_id' => ['type' => 'int', 'not null' => TRUE],
        'members_active' => ['type' => 'int', 'not null' => TRUE],
        'joins' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => ['snapshot_id'],
    ]);

    $rows = [
      // id, snapshot_date, source, created_at (UTC), members_active.
      [480, '2025-12-01', 'manual_drush', '2026-04-23 05:18', 823],
      [5, '2026-01-01', 'manual_form', '2026-01-22 06:48', 802],
      [328, '2026-01-01', 'automatic_cron', '2026-02-02 04:35', 795],
      [370, '2026-01-01', 'manual_drush', '2026-04-07 07:30', 808],
      [468, '2026-02-01', 'manual_drush', '2026-04-23 05:14', 823],
      [342, '2026-03-01', 'automatic_cron', '2026-03-01 05:39', 802],
    ];
    foreach ($rows as [$id, $date, $source, $created, $active]) {
      $database->insert('ms_snapshot')->fields([
        'id' => $id,
        'definition' => 'membership_totals',
        'snapshot_type' => 'monthly',
        'snapshot_date' => $date,
        'source' => $source,
        'created_at' => strtotime($created . ' UTC'),
      ])->execute();
      $database->insert('ms_fact_org_snapshot')->fields([
        'snapshot_id' => $id,
        'members_active' => $active,
        'joins' => 0,
      ])->execute();
    }

    $time = $this->createMock(TimeInterface::class);
    $time->method('getRequestTime')->willReturn(strtotime('2026-11-18 12:00:00 UTC'));
    $service = new SnapshotDataService($database, new MemoryBackend($time), $time);

    $readings = array_map(static fn(array $row) => [
      $row['captured']->format('Y-m-d'),
      $row['members_active'],
    ], $service->getActiveMemberReadings());

    $this->assertSame([
      ['2026-01-22', 802],
      // 04:35 UTC on Feb 2 is the evening of Feb 1 in New Haven.
      ['2026-02-01', 795],
      ['2026-03-01', 802],
    ], $readings);
  }

}
