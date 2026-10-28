<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\makerspace_dashboard\Service\StaffBriefingService;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Log\LoggerInterface;

/**
 * Governance Dance actions and meetings, read over its MCP endpoint.
 *
 * @group makerspace_dashboard
 */
class StaffBriefingSourcesTest extends UnitTestCase {

  /**
   * A service with a token configured and the given HTTP responses.
   */
  protected function service(array $responses, string $token = 'gda_test'): object {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturnCallback(static fn ($k) => match ($k) {
      'briefing_governance_token' => $token,
      'timezone.default' => 'America/New_York',
      default => NULL,
    });
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->willReturn($config);
    $http = $this->createMock(ClientInterface::class);
    $http->method('request')->willReturnCallback(function (string $method, string $url, array $options) use ($responses) {
      $this->assertSame('POST', $method);
      $this->assertSame('Bearer gda_test', $options['headers']['Authorization']);
      $this->assertSame('tools/call', $options['json']['method']);
      $tool = $options['json']['params']['name'];
      $this->assertContains($tool, ['list_actions', 'list_meetings'], 'Only read tools are called.');
      return $responses[$tool];
    });
    return new class(
      $this->createMock(Connection::class),
      $factory,
      $this->createMock(StateInterface::class),
      $this->createMock(TimeInterface::class),
      $this->createMock(MailManagerInterface::class),
      $this->createMock(LoggerInterface::class),
      NULL,
      NULL,
      NULL,
      $http,
    ) extends StaffBriefingService {

      public function governanceFor(\DateTimeImmutable $weekEnd): array {
        return $this->governance($weekEnd);
      }

    };
  }

  /**
   * An MCP tools/call JSON response carrying $data as text content.
   */
  protected static function mcp(array $data): Response {
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
      'jsonrpc' => '2.0',
      'id' => 1,
      'result' => ['content' => [['type' => 'text', 'text' => json_encode($data)]]],
    ]));
  }

  /**
   * Overdue and due-soon actions are kept; undated ones are only counted.
   *
   * Shapes copied from Governance Dance's list_actions on 2026-10-28.
   */
  public function testActionsAndMeetings(): void {
    $svc = $this->service([
      'list_actions' => self::mcp([
        ['text' => 'Audit accounts payable', 'owner' => 'J.R. Logan', 'status' => 'open', 'dueDate' => '2026-10-15', 'committeeId' => 'finance'],
        ['text' => 'Post a reversing entry', 'owner' => 'J.R. Logan', 'status' => 'open', 'committeeId' => 'finance'],
        ['text' => 'Due soon', 'owner' => 'Ashley Zdeb', 'status' => 'open', 'dueDate' => '2026-11-10', 'committeeId' => 'finance'],
        ['text' => 'Due later', 'owner' => 'Ashley Zdeb', 'status' => 'open', 'dueDate' => '2026-12-20'],
      ]),
      'list_meetings' => self::mcp([
        ['title' => 'DEI Committee — October', 'scheduledAt' => '2026-11-04T19:00:00.000Z', 'url' => 'https://governance.makehaven.org/meetings/x'],
        ['title' => 'Next month', 'scheduledAt' => '2026-11-20T19:00:00.000Z'],
      ]),
    ]);
    $out = $svc->governanceFor(new \DateTimeImmutable('2026-11-02 00:00', new \DateTimeZone('America/New_York')));

    $this->assertSame(['Audit accounts payable', 'Due soon'], array_column($out['actions'], 'text'));
    $this->assertTrue($out['actions'][0]['overdue']);
    $this->assertFalse($out['actions'][1]['overdue']);
    $this->assertSame(1, $out['undated']);
    $this->assertSame(['DEI Committee — October'], array_column($out['meetings'], 'title'));
    $this->assertSame('Wed Nov 4, 2:00pm', $out['meetings'][0]['when']);
  }

  /**
   * A revoked token (401) leaves the section empty instead of failing.
   */
  public function testFailureIsQuiet(): void {
    $denied = new Response(401, [], json_encode(['jsonrpc' => '2.0', 'error' => ['code' => -32001, 'message' => 'revoked'], 'id' => NULL]));
    $out = $this->service(['list_actions' => $denied, 'list_meetings' => $denied])->governanceFor(new \DateTimeImmutable('2026-11-02'));
    $this->assertSame(['actions' => [], 'meetings' => [], 'undated' => 0], $out);
  }

  /**
   * No token configured: Governance Dance is never called.
   */
  public function testNoTokenNoCall(): void {
    $out = $this->service([], '')->governanceFor(new \DateTimeImmutable('2026-11-02'));
    $this->assertSame([], $out['actions']);
  }

}
