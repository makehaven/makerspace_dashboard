<?php

namespace Drupal\makerspace_dashboard\DashboardSection;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\Markup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Chart\DashboardChartBuilderInterface;
use Drupal\makerspace_dashboard\Service\ChartBuilderManager;
use Drupal\makerspace_dashboard\Service\KpiDataService;

/**
 * Defines the OverviewSection class.
 */
class OverviewSection extends DashboardSectionBase {

  protected KpiDataService $kpiDataService;
  protected array $sectionLabels = [];

  /**
   * Constructs the section.
   */
  public function __construct(KpiDataService $kpi_data_service, ChartBuilderManager $chart_builder_manager) {
    parent::__construct(NULL, $chart_builder_manager);
    $this->kpiDataService = $kpi_data_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'overview';
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel(): TranslatableMarkup {
    return $this->t('Overview');
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $filters = []): array {
    return [
      'stoplight' => $this->buildStoplightGrid(),
    ];
  }

  /**
   * Builds the stoplight KPI overview grouped by section.
   */
  protected function buildStoplightGrid(): array {
    $definitions = $this->kpiDataService->getAllKpiDefinitions();
    $goalYearLabel = NULL;
    $sectionTables = [];
    $allSourceNotes = [];
    $jumpLinks = [];

    if (!$this->sectionLabels) {
      try {
        $sectionManager = \Drupal::service('makerspace_dashboard.section_manager');
        foreach ($sectionManager->getSections() as $section) {
          $this->sectionLabels[$section->getId()] = (string) $section->getLabel();
        }
      }
      catch (\Throwable $e) {
        // Ignore and fall back to computed labels.
      }
    }

    foreach ($definitions as $sectionId => $kpiDefinitions) {
      if ($sectionId === 'overview') {
        // Avoid duplicating Overview KPIs; these are represented in domain
        // sections where users can drill into context-specific charts.
        continue;
      }
      if (empty($kpiDefinitions)) {
        continue;
      }

      $kpis = $this->kpiDataService->getKpiData($sectionId);
      if (empty($kpis)) {
        continue;
      }

      $sectionLabel = (string) ($this->sectionLabels[$sectionId] ?? $this->t(ucwords(str_replace('_', ' ', $sectionId))));
      $rows = [];
      $sectionSourceNotes = [];

      foreach ($kpiDefinitions as $kpiId => $info) {
        $kpi = $kpis[$kpiId] ?? NULL;
        if (!$kpi) {
          continue;
        }

        $format = $kpi['display_format'] ?? NULL;
        if ($goalYearLabel === NULL && !empty($kpi['goal_current_year_label'])) {
          $goalYearLabel = $this->normalizeGoalYearLabelForOverview($kpi['goal_current_year_label']);
        }

        $kpiLabel = (string) ($kpi['label'] ?? ($info['label'] ?? $kpiId));
        $isInDevelopment = $this->isKpiInDevelopment($kpi);
        $sourceText = (string) $this->buildSourceNoteCell($kpi);
        $detailLink = $this->buildKpiDetailLinkCell($sectionId, $kpiId, $kpiLabel);

        $rows[] = [
          [
            'data' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['kpi-overview-label-cell']],
              'label' => ['#markup' => Html::escape($kpiLabel)],
              'state' => $isInDevelopment
                ? ['#markup' => '<span class="kpi-overview-state kpi-overview-state--dev">' . $this->t('In development') . '</span>']
                : [],
              'more' => [
                '#type' => 'container',
                '#attributes' => ['class' => ['kpi-overview-more-link']],
                'link' => $detailLink['data'],
              ],
            ],
          ],
          $this->buildStoplightBadge($kpi, $format),
          $this->buildCurrentValueCell($kpi, $format),
          $this->buildGoalValueCell($kpi, $format),
          [
            'data' => [
              '#type' => 'container',
              '#attributes' => ['class' => ['kpi-sparkline-wrapper']],
              'sparkline' => $this->buildSparkline($kpi['trend'] ?? []),
            ],
            'attributes' => ['class' => 'kpi-sparkline-cell'],
          ],
        ];

        $sectionSourceNotes[] = '<strong>' . Html::escape($kpiLabel) . ':</strong> ' . Html::escape($sourceText);
      }

      if (!$rows) {
        continue;
      }

      $sectionAnchor = 'overview-kpi-section-' . Html::getClass($sectionId);
      $jumpLinks[$sectionId] = [
        'title' => $sectionLabel,
        'url' => Url::fromRoute('makerspace_dashboard.dashboard', [], ['fragment' => $sectionAnchor]),
        'attributes' => [
          'class' => ['overview-kpi-jump-link'],
        ],
      ];

      $sectionTables[$sectionId] = [
        'label' => $sectionLabel,
        'anchor' => $sectionAnchor,
        'rows' => $rows,
        'source_notes' => $sectionSourceNotes,
      ];
      $allSourceNotes[$sectionLabel] = $sectionSourceNotes;
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['overview-stoplight']],
      'heading' => [
        '#markup' => '<h2>' . $this->t('KPI Stoplight Overview') . '</h2><p>' . $this->t('KPI table grouped by section for quick scanning. Use the More link under each KPI for deeper context in each section tab.') . '</p>',
      ],
    ];

    if (empty($sectionTables)) {
      $build['empty'] = [
        '#markup' => '<p class="makerspace-dashboard-empty">' . $this->t('KPI data is not available yet.') . '</p>',
      ];
      return $build;
    }

    $goalYearLabel = $goalYearLabel ?? (int) date('Y');
    $header = [
      $this->t('KPI'),
      $this->t('Status'),
      $this->t('Current'),
      $this->t('Goal @year', ['@year' => $goalYearLabel]),
      $this->t('Trend'),
    ];

    $build['jump_links'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['overview-kpi-jump-links']],
      'label' => ['#markup' => '<strong>' . $this->t('Jump to section:') . '</strong>'],
      'links' => [
        '#theme' => 'links',
        '#links' => $jumpLinks,
        '#attributes' => ['class' => ['overview-kpi-jump-list']],
      ],
    ];

    foreach ($sectionTables as $sectionId => $sectionTable) {
      $sectionUrl = Url::fromRoute('makerspace_dashboard.section_page', ['sid' => $sectionId]);
      $build['section_' . $sectionId] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['overview-kpi-section-table'],
          'id' => $sectionTable['anchor'],
        ],
        'heading' => [
          '#markup' => '<h3>' . Html::escape($sectionTable['label']) . ' <a class="overview-kpi-section-link" href="' . Html::escape($sectionUrl->toString()) . '">' . $this->t('Open section') . '</a></h3>',
        ],
        'table_wrap' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['overview-kpi-table-wrap']],
          'table' => [
            '#type' => 'table',
            '#attributes' => ['class' => ['kpi-stoplight-table', 'kpi-stoplight-table--sectioned']],
            '#header' => $header,
            '#rows' => $sectionTable['rows'],
            '#empty' => $this->t('No KPIs available for this section.'),
          ],
        ],
      ];
    }

    $sourceItems = [];
    foreach ($allSourceNotes as $sectionLabel => $notes) {
      $sourceItems[] = ['#markup' => '<h4>' . Html::escape($sectionLabel) . '</h4>'];
      foreach ($notes as $note) {
        $sourceItems[] = ['#markup' => $note];
      }
    }

    if (!empty($sourceItems)) {
      $build['source_details'] = [
        '#type' => 'details',
        '#title' => $this->t('Data Source Notes'),
        '#open' => FALSE,
        'items' => [
          '#theme' => 'item_list',
          '#items' => $sourceItems,
          '#attributes' => ['class' => ['overview-source-notes']],
        ],
      ];
    }

    $build['#cache'] = [
      'max-age' => 1800,
      'tags' => ['config:makerspace_dashboard.kpis'],
      'contexts' => ['timezone'],
    ];

    return $build;
  }

  /**
   * Builds the per-KPI details link cell.
   */
  protected function buildKpiDetailLinkCell(string $sectionId, string $kpiId, string $kpiLabel): array {
    $detailUrl = Url::fromRoute('makerspace_dashboard.section_page', ['sid' => $sectionId]);
    $chartId = $this->resolveDetailChartId($sectionId, $kpiId);
    if ($chartId !== NULL) {
      $fragment = Html::getId(sprintf('makerspace-dashboard-chart-%s-%s', $sectionId, $chartId));
      $detailUrl->setOption('fragment', $fragment);
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $this->t('More'),
        '#url' => $detailUrl,
        '#attributes' => [
          'class' => ['kpi-detail-link'],
          'title' => $this->t('See deeper context for @kpi', ['@kpi' => $kpiLabel]),
        ],
      ],
    ];
  }

  /**
   * Resolves the best matching chart id for a KPI within a section.
   */
  protected function resolveDetailChartId(string $sectionId, string $kpiId): ?string {
    if (!$this->chartBuilderManager) {
      return NULL;
    }

    $manual = [
      'outreach' => [
        'total_new_member_signups' => 'new_member_recruitment',
        'member_referral_rate' => 'discovery_sources',
        'tours_to_member_conversion' => 'tour_conversion_funnel',
        'guest_waivers_signed' => 'guest_waiver_conversion_funnel',
        'guest_waiver_conversion' => 'guest_waiver_conversion_funnel',
        'discovery_meetings_logged' => 'meeting_conversion_funnel',
        'meeting_to_member_conversion' => 'meeting_conversion_funnel',
      ],
      'retention' => [
        'total_active_members' => 'snapshot_monthly',
        'first_year_member_retention' => 'annual_retention',
        'member_nps' => 'appointment_feedback_outcomes',
        'active_participation' => 'badge_tenure_correlation',
        'membership_diversity_bipoc' => 'annual_retention_ethnicity',
      ],
      'education' => [
        'workshop_attendees' => 'registrations_by_type',
        'education_nps' => 'event_net_promoter',
        'workshop_participants_bipoc' => 'participant_ethnicity',
        'active_instructors_bipoc' => 'active_instructor_demographics',
      ],
      'finance' => [
        'member_revenue_quarterly' => 'mrr_trend',
        'reserve_funds_months' => 'average_monthly_payment',
      ],
      'governance' => [
        'board_ethnic_diversity' => 'board_ethnicity',
        'board_gender_diversity' => 'board_gender_identity',
      ],
      'infrastructure' => [
        'shop_utilization' => 'monthly_entries',
      ],
      'operations' => [
        'adherence_to_shop_budget' => 'storage_vacancy_trend',
      ],
      'overview' => [
        'total_active_members' => 'active_members',
        'workshop_attendees' => 'workshop_attendance',
        'reserve_funds_months' => 'reserve_funds',
      ],
    ];

    if (isset($manual[$sectionId][$kpiId]) && $this->chartBuilderManager->getBuilder($sectionId, $manual[$sectionId][$kpiId])) {
      return $manual[$sectionId][$kpiId];
    }

    $builders = $this->chartBuilderManager->getBuilders($sectionId);
    if (empty($builders)) {
      return NULL;
    }

    $kpiTokens = $this->tokenizeKpiId($kpiId);
    $bestChartId = NULL;
    $bestScore = 0;

    foreach ($builders as $builder) {
      if (!$builder instanceof DashboardChartBuilderInterface) {
        continue;
      }
      $chartId = $builder->getChartId();
      $chartTokens = $this->tokenizeKpiId($chartId);
      $score = count(array_intersect($kpiTokens, $chartTokens));
      if ($score > $bestScore) {
        $bestScore = $score;
        $bestChartId = $chartId;
      }
      if ($bestScore >= 2) {
        break;
      }
    }

    if ($bestChartId !== NULL) {
      return $bestChartId;
    }

    return $builders[0] instanceof DashboardChartBuilderInterface
      ? $builders[0]->getChartId()
      : NULL;
  }

  /**
   * Tokenizes KPI/chart ids into comparable terms.
   */
  protected function tokenizeKpiId(string $value): array {
    $tokens = array_filter(explode('_', strtolower($value)));
    $stop = [
      'total', 'of', 'and', 'to', 'rate', 'annual', 'monthly', 'quarterly',
      'member', 'members', 'program', 'active', 'first', 'year',
    ];
    return array_values(array_diff($tokens, $stop));
  }

  /**
   * Builds the stoplight badge column.
   */
  protected function buildStoplightBadge(array $kpi, ?string $format): array {
    $class = $this->determinePerformanceClass($kpi['current'] ?? NULL, $kpi['goal_current_year'] ?? NULL, $format);
    $label = match ($class) {
      'kpi-progress--good' => (string) $this->t('On track'),
      'kpi-progress--warning' => (string) $this->t('Watch'),
      'kpi-progress--poor' => (string) $this->t('Off track'),
      default => (string) $this->t('No goal'),
    };

    $indicatorClasses = ['kpi-stoplight-indicator'];
    $indicatorClasses[] = $class ?? 'kpi-progress--na';

    $classString = implode(' ', $indicatorClasses);
    $markup = Markup::create(sprintf(
      '<span class="%s" aria-hidden="true"></span><span class="kpi-stoplight-text">%s</span>',
      Html::escape($classString),
      Html::escape($label),
    ));

    return [
      'data' => ['#markup' => $markup],
      'class' => ['kpi-stoplight-cell'],
    ];
  }

  /**
   * Formats the goal cell with the current year label.
   */
  protected function buildGoalValueCell(array $kpi, ?string $format) {
    $goalValue = $this->formatKpiValue($kpi['goal_current_year'] ?? NULL, $format);
    if (!$goalValue) {
      return $this->t('n/a');
    }
    $label = $this->normalizeGoalYearLabelForOverview($kpi['goal_current_year_label'] ?? NULL);
    $markup = Markup::create(sprintf(
      '<span class="kpi-goal-value">%s</span> <span class="kpi-goal-label">(%s)</span>',
      Html::escape((string) $goalValue),
      Html::escape((string) $label),
    ));

    return [
      'data' => ['#markup' => $markup],
    ];
  }

  /**
   * Normalizes goal year labels to a 4-digit year for overview display.
   */
  protected function normalizeGoalYearLabelForOverview($rawLabel): int {
    $currentYear = (int) date('Y');
    if ($rawLabel === NULL || $rawLabel === '') {
      return $currentYear;
    }

    $label = (string) $rawLabel;
    if (preg_match('/(20\d{2})/', $label, $matches)) {
      return (int) $matches[1];
    }

    $digits = preg_replace('/[^0-9]/', '', $label);
    if ($digits === '') {
      return $currentYear;
    }

    if (strlen($digits) >= 4) {
      $year = (int) substr($digits, -4);
      return $year >= 2000 ? $year : $currentYear;
    }

    $short = (int) $digits;
    if ($short >= 20 && $short <= 99) {
      return 2000 + $short;
    }

    // Ambiguous short labels (e.g., "2") fall back to the active year.
    return $currentYear;
  }

}
