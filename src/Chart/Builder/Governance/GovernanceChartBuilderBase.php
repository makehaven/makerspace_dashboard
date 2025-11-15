<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Governance;

use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Service\GovernanceBoardDataService;

/**
 * Shared helpers for governance chart builders.
 */
abstract class GovernanceChartBuilderBase extends ChartBuilderBase {

  protected const SECTION_ID = 'governance';

  /**
   * Constructs the builder.
   */
  public function __construct(
    protected GovernanceBoardDataService $boardDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * Returns the normalized board composition snapshot.
   */
  protected function getComposition(): array {
    return $this->boardDataService->getBoardComposition();
  }

  /**
   * Returns a reusable data source note referencing the Google Sheet.
   */
  protected function buildSourceNote(): array {
    $markup = $this->t('Data Source: <a href=":url" target="_blank" rel="noopener noreferrer">Board Roster & Goals (Google Sheet)</a>', [
      ':url' => $this->boardDataService->getSourceUrl(),
    ]);
    return [
      '#markup' => Markup::create((string) $markup),
    ];
  }

  /**
   * Converts fractional percentages into 0-100 float values.
   */
  protected function formatPercentValues(array $values): array {
    $formatted = [];
    foreach ($values as $label => $value) {
      $formatted[$label] = round(((float) $value) * 100, 2);
    }
    return $formatted;
  }

  /**
   * Builds a Chart.js pie visualization with consistent styling.
   */
  protected function buildPieVisualization(string $title, array $values, array $colorMap): array {
    $labels = array_map('strval', array_keys($values));
    $data = array_map('floatval', array_values($values));

    $colors = [];
    foreach ($labels as $label) {
      $colors[] = $colorMap[$label] ?? '#9ca3af';
    }

    return [
      'type' => 'chart',
      'library' => 'chartjs',
      'chartType' => 'pie',
      'data' => [
        'labels' => $labels,
        'datasets' => [[
          'label' => $title,
          'data' => $data,
          'backgroundColor' => $colors,
        ]],
      ],
      'options' => [
        'plugins' => [
          'legend' => [
            'display' => FALSE,
          ],
          'title' => [
            'display' => TRUE,
            'text' => $title,
          ],
          'datalabels' => [
            'formatter' => $this->chartCallback('value_format', [
              'format' => 'percent',
              'decimals' => 1,
              'showLabel' => FALSE,
            ]),
          ],
        ],
      ],
    ];
  }

  /**
   * Determines whether at least one value is greater than zero.
   */
  protected function hasMeaningfulValues(array $values): bool {
    foreach ($values as $value) {
      if ((float) $value > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
