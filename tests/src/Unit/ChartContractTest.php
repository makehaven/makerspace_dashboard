<?php

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Chart\Builder\ChartBuilderBase;
use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use PHPUnit\Framework\TestCase;

/**
 * Tests the framework contract shared by every dashboard chart.
 */
class ChartContractTest extends TestCase {

  /**
   * Callback placeholders survive metadata serialization.
   */
  public function testCallbackNormalization(): void {
    $definition = new ChartDefinition('test', 'callback', 'Title', 'Description', [
      'type' => 'chart',
      'options' => [
        'callback' => [
          '#makerspace_callback' => 'value_format',
          '#options' => ['format' => 'currency'],
        ],
      ],
    ]);

    $metadata = $definition->toMetadata();
    $this->assertSame('value_format', $metadata['visualization']['options']['callback']['__callback']);
    $this->assertSame('currency', $metadata['visualization']['options']['callback']['options']['format']);
  }

  /**
   * Linear trend helpers return the least-squares line in input order.
   */
  public function testTrendLineCalculation(): void {
    $builder = new class () extends ChartBuilderBase {
      protected const SECTION_ID = 'test';
      protected const CHART_ID = 'trend';

      public function build(array $filters = []): ?ChartDefinition {
        return NULL;
      }

      public function trend(array $values): array {
        return $this->calculateTrendLine($values);
      }
    };

    $this->assertSame([1.0, 2.0, 3.0], $builder->trend([1, 2, 3]));
    $this->assertSame([], $builder->trend([1]));
    $this->assertSame([], $builder->trend([1, 'missing']));
  }

}
