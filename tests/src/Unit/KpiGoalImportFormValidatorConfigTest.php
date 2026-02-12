<?php

declare(strict_types=1);

namespace Drupal\Tests\makerspace_dashboard\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards managed_file validator configuration for KPI goal import.
 *
 * @group makerspace_dashboard
 */
class KpiGoalImportFormValidatorConfigTest extends TestCase {

  /**
   * Ensures the form uses the modern FileExtension validator key.
   */
  public function testFormUsesFileExtensionValidator(): void {
    $path = dirname(__DIR__, 3) . '/src/Form/KpiGoalImportForm.php';
    $source = file_get_contents($path);

    $this->assertIsString($source);
    $this->assertStringContainsString("'FileExtension' => ['extensions' => 'csv']", $source);
    $this->assertStringNotContainsString('file_validate_extensions', $source);
  }

}

