<?php

namespace Drupal\makerspace_dashboard\Chart\Builder;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface;

/**
 * Shared helpers for chart builders.
 */
abstract class ChartBuilderBase implements DashboardChartBuilderInterface {

  use StringTranslationTrait;

  /**
   * Section id constant each builder must define.
   */
  protected const SECTION_ID = '';

  /**
   * Chart id constant each builder must define.
   */
  protected const CHART_ID = '';

  /**
   * Default weight ordering per chart, overridable per builder.
   */
  protected const WEIGHT = 0;

  /**
   * {@inheritdoc}
   */
  public function __construct(?TranslationInterface $stringTranslation = NULL) {
    if ($stringTranslation) {
      $this->setStringTranslation($stringTranslation);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getSectionId(): string {
    return static::SECTION_ID;
  }

  /**
   * {@inheritdoc}
   */
  public function getChartId(): string {
    return static::CHART_ID;
  }

  /**
   * Gets the default weight for the chart.
   */
  protected function getWeight(): int {
    return static::WEIGHT;
  }

  /**
   * Helper to instantiate a definition with shared defaults.
   */
  protected function newDefinition(string $title, string $description, array $visualization, array $notes = [], ?array $range = NULL, ?int $weight = NULL, array $cache = []): ChartDefinition {
    return new ChartDefinition(
      $this->getSectionId(),
      $this->getChartId(),
      $title,
      $description,
      $visualization,
      $notes,
      $range,
      $weight ?? $this->getWeight(),
      $cache,
    );
  }

  /**
   * Builds a callback definition consumed by the React renderer.
   */
  protected function chartCallback(string $id, array $options = []): array {
    return [
      '#makerspace_callback' => $id,
      '#options' => $options,
    ];
  }

  /**
   * Builds a dashed trend dataset for the supplied numeric values.
   */
  protected function buildTrendDataset(array $values, string $label, string $color = '#9ca3af'): ?array {
    $trendValues = $this->calculateTrendLine($values);
    if (empty($trendValues)) {
      return NULL;
    }

    $rounded = array_map(static fn($value) => round((float) $value, 2), $trendValues);

    return [
      'label' => $label,
      'data' => array_values($rounded),
      'borderColor' => $color,
      'backgroundColor' => $color,
      'borderDash' => [6, 4],
      'pointRadius' => 0,
      'pointHoverRadius' => 0,
      'pointHitRadius' => 0,
      'borderWidth' => 2,
      'tension' => 0,
      'fill' => FALSE,
    ];
  }

  /**
   * Calculates a linear regression trend line for the dataset.
   */
  protected function calculateTrendLine(array $values): array {
    $count = count($values);
    if ($count < 2) {
      return [];
    }

    $sumX = 0.0;
    $sumY = 0.0;
    $sumXY = 0.0;
    $sumX2 = 0.0;

    foreach ($values as $index => $rawValue) {
      if (!is_numeric($rawValue)) {
        return [];
      }
      $x = (float) $index;
      $y = (float) $rawValue;
      $sumX += $x;
      $sumY += $y;
      $sumXY += $x * $y;
      $sumX2 += $x * $x;
    }

    $denominator = ($count * $sumX2) - ($sumX * $sumX);
    if (abs($denominator) < 1e-8) {
      return [];
    }

    $slope = (($count * $sumXY) - ($sumX * $sumY)) / $denominator;
    $intercept = ($sumY - ($slope * $sumX)) / $count;

    $trend = [];
    foreach (array_keys($values) as $index) {
      $trend[] = ($slope * $index) + $intercept;
    }

    return $trend;
  }

  /**
   * Provides a default palette for dataset colors.
   */
  protected function defaultColorPalette(): array {
    return [
      '#2563eb',
      '#16a34a',
      '#f97316',
      '#dc2626',
      '#7c3aed',
      '#0d9488',
      '#6366f1',
      '#f59e0b',
    ];
  }

}
