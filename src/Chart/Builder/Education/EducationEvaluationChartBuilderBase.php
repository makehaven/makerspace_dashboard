<?php

namespace Drupal\makerspace_dashboard\Chart\Builder\Education;

use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\makerspace_dashboard\Service\EducationEvaluationDataService;

/**
 * Base class for charts powered by evaluation submissions.
 */
abstract class EducationEvaluationChartBuilderBase extends EducationChartBuilderBase {

  protected const RANGE_DEFAULT = '1y';
  protected const RANGE_OPTIONS = ['3m', '1y', '2y'];

  public function __construct(
    protected EducationEvaluationDataService $evaluationDataService,
    ?TranslationInterface $stringTranslation = NULL,
  ) {
    parent::__construct($stringTranslation);
  }

  /**
   * Provides a timezone-aware "now" timestamp.
   */
  protected function now(): \DateTimeImmutable {
    return new \DateTimeImmutable('now', new \DateTimeZone(date_default_timezone_get()));
  }

}
