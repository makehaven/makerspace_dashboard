<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;

/**
 * A service for fetching data from a public Google Sheet.
 */
class GoogleSheetClientService {

  /**
   * Cache TTL for sheet data: 1 hour.
   */
  const SHEET_CACHE_TTL = 3600;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The cache backend.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cache;

  /**
   * Constructs a new GoogleSheetClientService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache backend.
   */
  public function __construct(ConfigFactoryInterface $config_factory, CacheBackendInterface $cache) {
    $this->configFactory = $config_factory;
    $this->cache = $cache;
  }

  /**
   * Fetches data from a specific tab in the configured Google Sheet.
   *
   * @param string $tab_name
   *   The name of the tab/worksheet to fetch data from.
   *
   * @return array
   *   A 2D array of the data, or an empty array on failure.
   */
  public function getSheetData(string $tab_name): array {
    $config = $this->configFactory->get('makerspace_dashboard.settings');
    $sheet_url = $config->get('google_sheet_url');
    $api_key = $config->get('google_api_key');

    if (empty($sheet_url) || empty($tab_name) || empty($api_key)) {
      return [];
    }

    $spreadsheet_id = $this->extractSpreadsheetIdFromUrl($sheet_url);
    if (!$spreadsheet_id) {
      return [];
    }

    // Return cached data when available. Cache key includes the spreadsheet ID
    // so that a URL change in settings automatically yields a fresh fetch.
    $cid = 'makerspace_dashboard:sheet:' . md5($spreadsheet_id . ':' . $tab_name);
    $cached = $this->cache->get($cid);
    if ($cached !== FALSE) {
      return $cached->data;
    }

    try {
      $client = new GoogleClient();
      $client->setApplicationName('Makerspace Dashboard');
      $client->setScopes([GoogleSheets::SPREADSHEETS_READONLY]);
      $client->setDeveloperKey($api_key);

      $service = new GoogleSheets($client);
      $response = $this->suppressDeprecatedGoogleWarnings(function () use ($service, $spreadsheet_id, $tab_name) {
        return $service->spreadsheets_values->get($spreadsheet_id, $tab_name);
      });
      if (!$response) {
        return [];
      }
      $data = $response->getValues() ?? [];
      // Cache with a tag on settings so that saving a new sheet URL or key
      // immediately invalidates all cached tabs.
      $this->cache->set($cid, $data, time() + self::SHEET_CACHE_TTL, ['config:makerspace_dashboard.settings']);
      return $data;
    }
    catch (\Exception $e) {
      \Drupal::logger('makerspace_dashboard')->error('Failed to fetch Google Sheet data: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Returns the configured Google Sheet URL.
   *
   * @return string
   *   The Google Sheet URL, or an empty string if not configured.
   */
  public function getGoogleSheetUrl(): string {
    $config = $this->configFactory->get('makerspace_dashboard.settings');
    return $config->get('google_sheet_url') ?? '';
  }

  /**
   * Extracts the spreadsheet ID from a Google Sheet URL.
   *
   * @param string $url
   *   The Google Sheet URL.
   *
   * @return string|null
   *   The spreadsheet ID, or null if not found.
   */
  protected function extractSpreadsheetIdFromUrl(string $url): ?string {
    $matches = [];
    if (preg_match('/\/spreadsheets\/d\/([a-zA-Z0-9-_]+)/', $url, $matches)) {
      return $matches[1];
    }
    return NULL;
  }

  /**
   * Suppresses specific deprecated warnings emitted by google/apiclient.
   *
   * PHP 8.3 deprecates calling get_class() with no arguments, but the upstream
   * client still invokes it inside Google\Http\REST::execute(). To avoid noisy
   * logs we temporarily intercept that warning while making API requests.
   *
   * @param callable $callback
   *   The API call to execute.
   *
   * @return mixed
   *   The callback return value.
   */
  protected function suppressDeprecatedGoogleWarnings(callable $callback) {
    $handler = function (int $severity, string $message, string $file): bool {
      $isDeprecatedLevel = in_array($severity, [E_DEPRECATED, E_USER_DEPRECATED], TRUE);
      if (!$isDeprecatedLevel) {
          return FALSE;
      }
      $isGoogleRestCall = str_contains($file, 'Google/Http/REST.php')
        && str_contains($message, 'get_class() without arguments is deprecated');
      return $isGoogleRestCall;
    };

    set_error_handler($handler, E_DEPRECATED | E_USER_DEPRECATED);
    $bufferStarted = ob_start();
    try {
      return $callback();
    }
    finally {
      if ($bufferStarted && ob_get_level() > 0) {
        ob_end_clean();
      }
      restore_error_handler();
    }
  }

}
