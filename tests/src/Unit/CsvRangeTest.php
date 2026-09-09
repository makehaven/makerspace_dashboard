<?php

namespace Drupal\Tests\makerspace_dashboard\Unit;

use Drupal\makerspace_dashboard\Chart\ChartDefinition;
use Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface;
use Drupal\makerspace_dashboard\Controller\CsvDownloadController;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\DashboardSectionManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Export must carry the requested range through both chart implementations.
 */
class CsvRangeTest extends TestCase {

  public function testBuilderExportUsesRequestedRange(): void {
    $definition = new ChartDefinition('retention', 'test', 'Title', '', [
      'type' => 'chart',
      'data' => ['labels' => ['Jan'], 'datasets' => [['label' => 'Members', 'data' => [42]]]],
    ]);
    $builder = $this->createMock(DashboardChartBuilderInterface::class);
    $builder->expects($this->once())->method('build')
      ->with(['range' => '24m', 'ranges' => ['test' => '24m']])->willReturn($definition);
    $manager = $this->createMock(ChartBuilderManager::class);
    $manager->method('getBuilder')->willReturn($builder);
    $sections = $this->createMock(DashboardSectionManager::class);
    $controller = new CsvDownloadController($sections, $manager);
    $response = $controller->downloadCsv(Request::create('/?range=24m'), 'retention', 'test');
    ob_start();
    $response->sendContent();
    $csv = ob_get_clean();
    $this->assertSame("Label,Members\nJan,42\n", $csv);
  }

  public function testLegacyExportReceivesSameFilters(): void {
    $sections = $this->createMock(DashboardSectionManager::class);
    $sections->expects($this->once())->method('getChartDefinition')
      ->with('retention', 'test', ['range' => '6m', 'ranges' => ['test' => '6m']])
      ->willReturn(['visualization' => ['type' => 'chart', 'data' => ['datasets' => [['data' => [1]]]]]]);
    $manager = $this->createMock(ChartBuilderManager::class);
    $manager->method('getBuilder')->willReturn(NULL);
    $controller = new CsvDownloadController($sections, $manager);
    $this->assertSame(200, $controller->downloadCsv(Request::create('/?range=6m'), 'retention', 'test')->getStatusCode());
  }

}
