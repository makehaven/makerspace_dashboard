<?php

namespace Drupal\makerspace_dashboard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Google\Client as GoogleClient;
use Google\Service\Sheets as GoogleSheets;

/**
 * A service for fetching data from a public Google Sheet.
 */
class GoogleSheetClientService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a new GoogleSheetClientService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Fetches data from a specific tab in the configured Google Sheet.
   *
   * @param string $tab_config_key
   *   The config key for the tab name (e.g., 'google_sheet_tab_finance').
   *
   * @return array
   *   A 2D array of the data, or an empty array on failure.
   */
  public function getSheetData(string $tab_config_key): array {
    $config = $this->configFactory->get('makerspace_dashboard.settings');
    $sheet_url = $config->get('google_sheet_url');
    $tab_name = $config->get($tab_config_key);
    $api_key = $config->get('google_api_key');

    if (empty($sheet_url) || empty($tab_name) || empty($api_key)) {
      return [];
    }

    try {
      $spreadsheet_id = $this->extractSpreadsheetIdFromUrl($sheet_url);
      if (!$spreadsheet_id) {
        return [];
      }

      $client = new GoogleClient();
      $client->setApplicationName('Makerspace Dashboard');
      $client->setScopes([GoogleSheets::SPREADSHEETS_READONLY]);
      $client->setDeveloperKey($api_key);

      $service = new GoogleSheets($client);
      $range = $tab_name;
      $response = $service->spreadsheets_values->get($spreadsheet_id, $range);
      return $response->getValues();
    }
    catch (\Exception $e) {
      // Log the exception.
      \Drupal::logger('makerspace_dashboard')->error('Failed to fetch Google Sheet data: @message', ['@message' => $e->getMessage()]);
      return [];
    }
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

}
