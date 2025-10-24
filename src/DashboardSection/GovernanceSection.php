<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\GoogleSheetClientService;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Governance dashboard section.
 */
class GovernanceSection extends DashboardSectionBase {

  /**
   * The Google Sheet client service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GoogleSheetClientService
   */
  protected $googleSheetClientService;

  /**
   * Constructs a new GovernanceSection object.
   *
   * @param \Drupal\makerspace_dashboard\Service\GoogleSheetClientService $google_sheet_client_service
   *   The Google Sheet client service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(GoogleSheetClientService $google_sheet_client_service, TranslationInterface $string_translation) {
    $this->googleSheetClientService = $google_sheet_client_service;
    $this->setStringTranslation($string_translation);
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'governance';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Governance');
  }

  /**
   * {@inheritdoc}
   */
  public function getGoogleSheetChartMetadata(): array {
    return [
      'label' => 'Governance',
      'tab_name' => 'Governance',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    $raw_rows = $this->googleSheetClientService->getSheetData('Governance');

    if (!is_array($raw_rows) || empty($raw_rows)) {
      return [
        '#markup' => $this->t('No data found for the Governance chart.'),
      ];
    }

    // Filter out empty rows that Google Sheets may return past the data range.
    $rows = array_values(array_filter($raw_rows, static function ($row) {
      if (!is_array($row)) {
        return FALSE;
      }
      foreach ($row as $value) {
        if ($value !== '' && $value !== NULL) {
          return TRUE;
        }
      }
      return FALSE;
    }));

    if (empty($rows)) {
      return [
        '#markup' => $this->t('No usable data found for the Governance chart.'),
      ];
    }

    $headers = array_shift($rows);
    if (!is_array($headers) || count($headers) < 2) {
      return [
        '#markup' => $this->t('The Governance sheet must contain a header row with at least two columns.'),
      ];
    }

    $series_headers = array_slice($headers, 1);
    $series_count = count($series_headers);
    $labels = [];
    $series_data = array_fill(0, $series_count, []);

    foreach ($rows as $row) {
      if (!is_array($row) || !isset($row[0])) {
        continue;
      }

      $label = trim((string) $row[0]);
      if ($label === '') {
        continue;
      }

      $labels[] = $label;

      for ($i = 0; $i < $series_count; $i++) {
        $value = $row[$i + 1] ?? NULL;
        $series_data[$i][] = static::normalizeNumericValue($value);
      }
    }

    if (empty($labels)) {
      return [
        '#markup' => $this->t('No labeled rows were found for the Governance chart.'),
      ];
    }

    $build['chart'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#chart_library' => 'google',
      '#title' => $this->t('Board & Committee Attendance'),
      '#legend_position' => $series_count > 1 ? 'right' : 'none',
      '#attached' => [
        'library' => [
          'charts/chart',
        ],
      ],
    ];

    $build['chart']['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    foreach ($series_headers as $index => $series_label) {
      $key = 'series_' . $index;
      $build['chart'][$key] = [
        '#type' => 'chart_data',
        '#title' => (string) ($series_label ?: $this->t('Series @num', ['@num' => $index + 1])),
        '#data' => $series_data[$index],
      ];
    }

    return $build;
  }

  /**
   * Convert sheet cell values into floats while preserving NULL gaps.
   */
  protected static function normalizeNumericValue($value): ?float {
    if ($value === '' || $value === NULL) {
      return NULL;
    }

    if (is_numeric($value)) {
      return (float) $value;
    }

    if (is_string($value)) {
      $cleaned = str_replace([',', '$', '%'], '', $value);
      if (is_numeric($cleaned)) {
        return (float) $cleaned;
      }
    }

    return NULL;
  }

}
