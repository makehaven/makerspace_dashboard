<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\makerspace_dashboard\Service\GoogleSheetClientService;
use Psr\Log\LoggerInterface;
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
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new GovernanceSection object.
   *
   * @param \Drupal\makerspace_dashboard\Service\GoogleSheetClientService $google_sheet_client_service
   *   The Google Sheet client service.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   */
  public function __construct(GoogleSheetClientService $google_sheet_client_service, LoggerInterface $logger, TranslationInterface $string_translation) {
    $this->googleSheetClientService = $google_sheet_client_service;
    $this->logger = $logger;
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
    $chart_data = $this->googleSheetClientService->getSheetData('Governance');

    if (empty($chart_data)) {
      return [
        '#markup' => $this->t('No data found for the Governance chart.'),
      ];
    }

    // Remove header row.
    array_shift($chart_data);

    $labels = array_map('strval', array_column($chart_data, 0));
    $data = array_map('intval', array_column($chart_data, 1));

    $build['chart'] = [
      '#type' => 'chart',
      '#chart_type' => 'bar',
      '#title' => $this->t('Board & Committee Attendance'),
      '#legend_position' => 'none',
      '#data' => [
        'labels' => $labels,
        'datasets' => [
          [
            'label' => $this->t('Attendance'),
            'data' => $data,
          ],
        ],
      ],
    ];

    return $build;
  }

}
