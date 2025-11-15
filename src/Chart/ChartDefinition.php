<?php

namespace Drupal\makerspace_dashboard\Chart;

/**
 * Value object representing a dashboard chart definition.
 */
class ChartDefinition {

  /**
   * Constructs a new definition.
   *
   * @param string $sectionId
   *   Section machine name.
   * @param string $chartId
   *   Chart machine name unique within the section.
   * @param string $title
   *   Human readable chart title.
   * @param string $description
   *   Chart description/subtitle.
   * @param array $visualization
   *   Visualization payload consumed by the React renderer.
   * @param array $notes
   *   Optional list of supporting notes.
   * @param array|null $range
   *   Optional active/available range metadata.
   * @param int $weight
   *   Ordering hint relative to other charts in the section.
   * @param array $cache
   *   Optional cache metadata (max-age, tags, contexts).
   */
  public function __construct(
    protected string $sectionId,
    protected string $chartId,
    protected string $title,
    protected string $description,
    protected array $visualization,
    protected array $notes = [],
    protected ?array $range = NULL,
    protected int $weight = 0,
    protected array $cache = [],
  ) {
  }

  /**
   * Gets the section id.
   */
  public function getSectionId(): string {
    return $this->sectionId;
  }

  /**
   * Gets the chart id.
   */
  public function getChartId(): string {
    return $this->chartId;
  }

  /**
   * Gets the display title.
   */
  public function getTitle(): string {
    return $this->title;
  }

  /**
   * Gets the description.
   */
  public function getDescription(): string {
    return $this->description;
  }

  /**
   * Gets the visualization payload.
   */
  public function getVisualization(): array {
    return $this->visualization;
  }

  /**
   * Gets supporting notes.
   */
  public function getNotes(): array {
    return $this->notes;
  }

  /**
   * Gets range metadata, if any.
   */
  public function getRange(): ?array {
    return $this->range;
  }

  /**
   * Gets the weight hint.
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * Gets cache metadata defined for the chart.
   */
  public function getCacheMetadata(): array {
    return $this->cache;
  }

  /**
   * Converts the definition into array metadata for React/JSON responses.
   */
  public function toMetadata(): array {
    return [
      'sectionId' => $this->sectionId,
      'chartId' => $this->chartId,
      'title' => $this->title,
      'description' => $this->description,
      'notes' => $this->notes,
      'visualization' => $this->normalizeVisualization($this->visualization),
      'range' => $this->range,
    ];
  }

  /**
   * Normalizes callback placeholders inside visualization arrays.
   */
  protected function normalizeVisualization(array $visualization): array {
    return $this->normalizeValue($visualization);
  }

  /**
   * Converts nested arrays into JSON-friendly structures.
   */
  protected function normalizeValue($value) {
    if (is_array($value)) {
      if (isset($value['#makerspace_callback'])) {
        return [
          '__callback' => $value['#makerspace_callback'],
          'options' => $this->normalizeValue($value['#options'] ?? []),
        ];
      }
      $normalized = [];
      foreach ($value as $key => $item) {
        $normalized[$key] = $this->normalizeValue($item);
      }
      return $normalized;
    }

    if ($value instanceof \Stringable) {
      return (string) $value;
    }

    return $value;
  }

}
