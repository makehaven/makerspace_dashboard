<?php

namespace Drupal\makerspace_dashboard\Controller;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The data behind the "What's next" board on process.makehaven.org.
 *
 * Drupal stays the system of record for board items (feedback_ledger nodes) and
 * member wishes; the registry renders and ranks them. This endpoint hands over
 * the raw signals — report counts, ▲ marks, wish votes, pins, urgency — and no
 * score, so the ranking formula lives in exactly one place
 * (Process-Registry public/board.js) and can be re-tuned without a deploy here.
 *
 * Nothing in the response is per-user and nothing names a reporter: titles and
 * notes are the member-safe restatements staff write at triage, which is what
 * lets every signed-in member see the same board.
 *
 * See docs/ops/WORK_SYSTEM.md in the site repo.
 */
class WorkBoardFeedController extends ControllerBase {

  /**
   * Board item statuses that are still open, i.e. shown on the board.
   */
  public const OPEN_STATUSES = ['in_progress', 'planned', 'reviewing', 'received', 'needs_info'];

  /**
   * Wish statuses that mean the wish is settled and no longer asks for work.
   */
  public const CLOSED_WISH_STATUSES = ['implemented', 'decline'];

  /**
   * Constructs the feed controller.
   */
  public function __construct(
    protected Connection $database,
    protected CacheBackendInterface $cache,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('cache.default'),
    );
  }

  /**
   * GET /api/work-board.
   */
  public function feed(): JsonResponse {
    $cid = 'makerspace_dashboard:work_board_feed';
    if ($cached = $this->cache->get($cid)) {
      $data = $cached->data;
    }
    else {
      $data = $this->build();
      // Five minutes keeps a ▲ click or a staff pin feeling live without
      // rebuilding on every page view; node saves clear it straight away.
      $this->cache->set($cid, $data, time() + 300, ['node_list:feedback_ledger', 'node_list:wish']);
    }
    $response = new JsonResponse($data);
    $response->headers->set('Cache-Control', 'private, max-age=60');
    return $response;
  }

  /**
   * Builds the feed payload.
   */
  public function build(): array {
    $counts = $this->flagCounts();
    $storage = $this->entityTypeManager()->getStorage('node');

    $item_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'feedback_ledger')
      ->condition('status', 1)
      ->condition('field_fb_status', self::OPEN_STATUSES, 'IN')
      ->execute();

    $status_labels = $this->allowedValues('field_fb_status');
    $area_labels = $this->allowedValues('field_fb_area');
    $items = [];
    $linked_wishes = [];
    foreach ($storage->loadMultiple($item_ids) as $node) {
      $nid = (int) $node->id();
      $wishes = array_map('intval', array_column($node->get('field_fb_wishes')->getValue(), 'target_id'));
      foreach ($wishes as $wid) {
        $linked_wishes[$wid] = $nid;
      }
      $status = (string) $node->get('field_fb_status')->value;
      $area = (string) $node->get('field_fb_area')->value;
      $items[] = [
        'nid' => $nid,
        'title' => $node->label(),
        'note' => (string) $node->get('field_fb_public_note')->value,
        'status' => $status,
        'status_label' => $status_labels[$status] ?? $status,
        'area' => $area,
        'area_label' => $area_labels[$area] ?? ucfirst(str_replace(['-', '_'], ' ', $area)),
        'reported' => (string) $node->get('field_fb_reported')->value,
        'reports' => self::reportCount((string) $node->get('field_fb_source')->value),
        'important' => $counts['fb_priority'][$nid] ?? 0,
        'urgent' => (bool) $node->get('field_fb_urgent')->value,
        'pinned' => (bool) $node->get('field_fb_pinned')->value,
        'pin_reason' => (string) $node->get('field_fb_pin_reason')->value,
        'process' => self::processId((string) ($node->get('field_fb_process')->uri ?? '')),
        'wishes' => $wishes,
        'url' => $node->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ];
    }

    // Every wish that still asks for something, linked or not: the unlinked
    // ones are the "members asked, nobody has triaged it yet" pile, which the
    // board shows on its own so it cannot silently grow.
    $wish_ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'wish')
      ->condition('status', 1)
      ->execute();
    $wish_status_labels = $this->allowedValues('field_wish_status');
    $wish_type_labels = $this->allowedValues('field_wish_type');
    $wishes = [];
    foreach ($storage->loadMultiple($wish_ids) as $wish) {
      $wid = (int) $wish->id();
      $status = (string) $wish->get('field_wish_status')->value;
      if (in_array($status, self::CLOSED_WISH_STATUSES, TRUE) && !isset($linked_wishes[$wid])) {
        continue;
      }
      $type = (string) $wish->get('field_wish_type')->value;
      $wishes[] = [
        'nid' => $wid,
        'title' => $wish->label(),
        'status' => $status,
        'status_label' => $status === '' ? 'Not reviewed yet' : ($wish_status_labels[$status] ?? $status),
        'type' => $type,
        'type_label' => $wish_type_labels[$type] ?? $type,
        'votes' => $counts['wish_vote'][$wid] ?? 0,
        'created' => date('Y-m-d', (int) $wish->getCreatedTime()),
        'item' => $linked_wishes[$wid] ?? NULL,
        'url' => $wish->toUrl('canonical', ['absolute' => TRUE])->toString(),
      ];
    }

    return [
      'generated' => date('c'),
      'board_mine_url' => Url::fromUserInput('/feedback-status/mine', ['absolute' => TRUE])->toString(),
      'report_url' => Url::fromUserInput('/website-feedback', ['absolute' => TRUE])->toString(),
      'wish_url' => Url::fromUserInput('/node/add/wish', ['absolute' => TRUE])->toString(),
      'items' => $items,
      'wishes' => $wishes,
    ];
  }

  /**
   * Counts the submissions behind an item from its provenance string.
   *
   * `field_fb_source` reads like "website_feedback sids 19557, 20355": one id
   * per submission folded into the item. Items born elsewhere ("staff report
   * 2026-07-13 …", "cycle-review …") carry no ids and count as one report.
   */
  public static function reportCount(string $source): int {
    if (!preg_match('/\b(?:sids?|ids?)\b\s*(.*)$/i', $source, $m)) {
      return 1;
    }
    preg_match_all('/\b\d{1,7}\b/', $m[1], $ids);
    return max(1, count(array_unique($ids[0])));
  }

  /**
   * Extracts the registry process id from a process.makehaven.org link.
   */
  public static function processId(string $uri): ?string {
    if ($uri === '' || !str_contains($uri, 'process.makehaven.org')) {
      return NULL;
    }
    $query = parse_url($uri, PHP_URL_QUERY) ?: '';
    parse_str($query, $params);
    $pid = $params['p'] ?? '';
    return is_string($pid) && $pid !== '' ? $pid : NULL;
  }

  /**
   * Flag counts for the two flags the board reads, keyed flag => nid => count.
   */
  protected function flagCounts(): array {
    $out = ['fb_priority' => [], 'wish_vote' => []];
    if (!$this->database->schema()->tableExists('flag_counts')) {
      return $out;
    }
    $rows = $this->database->select('flag_counts', 'fc')
      ->fields('fc', ['flag_id', 'entity_id', 'count'])
      ->condition('fc.flag_id', array_keys($out), 'IN')
      ->condition('fc.entity_type', 'node')
      ->execute();
    foreach ($rows as $row) {
      $out[$row->flag_id][(int) $row->entity_id] = (int) $row->count;
    }
    return $out;
  }

  /**
   * Allowed-value labels for a node list field, value => label.
   */
  protected function allowedValues(string $field_name): array {
    $storage = $this->entityTypeManager()->getStorage('field_storage_config')->load('node.' . $field_name);
    return $storage ? options_allowed_values($storage) : [];
  }

}
