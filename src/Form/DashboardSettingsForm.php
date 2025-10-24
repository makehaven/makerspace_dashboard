<?php

namespace Drupal\makerspace_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\makerspace_dashboard\Service\GoogleSheetClientService;
use Drupal\makerspace_dashboard\Service\GoogleSheetChartManager;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Settings form for Makerspace dashboard configuration.
 */
class DashboardSettingsForm extends ConfigFormBase {

  /**
   * The Google Sheet Client service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GoogleSheetClientService
   */
  protected $googleSheetClientService;

  /**
   * The Google Sheet Chart Manager service.
   *
   * @var \Drupal\makerspace_dashboard\Service\GoogleSheetChartManager
   */
  protected $googleSheetChartManager;

  /**
   * Constructs a new DashboardSettingsForm object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The factory for configuration objects.
   * @param \Drupal\makerspace_dashboard\Service\GoogleSheetClientService $google_sheet_client_service
   *   The Google Sheet Client service.
   * @param \Drupal\makerspace_dashboard\Service\GoogleSheetChartManager $google_sheet_chart_manager
   *   The Google Sheet Chart Manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, GoogleSheetClientService $google_sheet_client_service, GoogleSheetChartManager $google_sheet_chart_manager) {
    parent::__construct($config_factory);
    $this->googleSheetClientService = $google_sheet_client_service;
    $this->googleSheetChartManager = $google_sheet_chart_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('makerspace_dashboard.google_sheet_client'),
      $container->get('makerspace_dashboard.google_sheet_chart_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['makerspace_dashboard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_dashboard_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('makerspace_dashboard.settings');

    $form['utilization'] = [
      '#type' => 'details',
      '#title' => $this->t('Utilization settings'),
      '#open' => TRUE,
    ];

    $form['utilization']['daily_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily chart window (days)'),
      '#default_value' => $config->get('utilization.daily_window_days') ?? 90,
      '#min' => 7,
      '#description' => $this->t('Number of days to include in the daily unique members chart.'),
    ];

    $form['utilization']['rolling_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Rolling-average window (days)'),
      '#default_value' => $config->get('utilization.rolling_window_days') ?? 365,
      '#min' => 30,
      '#description' => $this->t('Number of days to include when plotting the 7-day rolling average trend.'),
    ];

    $form['engagement'] = [
      '#type' => 'details',
      '#title' => $this->t('Engagement settings'),
      '#open' => FALSE,
    ];

    $form['engagement']['engagement_cohort_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Cohort lookback window (days)'),
      '#default_value' => $config->get('engagement.cohort_window_days') ?? 90,
      '#min' => 7,
      '#description' => $this->t('How many days of new-member joins to include when building engagement cohorts.'),
    ];

    $form['engagement']['engagement_activation_window_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Activation window (days)'),
      '#default_value' => $config->get('engagement.activation_window_days') ?? 90,
      '#min' => 7,
      '#description' => $this->t('Number of days after joining to consider for orientation and badge activation metrics.'),
    ];

    $orientationDefault = $config->get('engagement.orientation_badge_ids') ?? [270];
    $form['engagement']['engagement_orientation_badge_ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Orientation badge term IDs'),
      '#default_value' => implode(', ', array_map('intval', (array) $orientationDefault)),
      '#description' => $this->t('Comma-separated taxonomy term IDs that represent orientation prerequisites (e.g. Maker Safety).'),
    ];

    $form['notes'] = [
      '#type' => 'details',
      '#title' => $this->t('Tab notes'),
      '#description' => $this->t('Add contextual notes for each dashboard tab. Notes are visible to all viewers.'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => ['id' => 'edit-notes'],
    ];

    $notes = $config->get('tab_notes') ?? [];
    $tabs = [
      'utilization' => $this->t('Utilization'),
      'demographics' => $this->t('Demographics'),
      'engagement' => $this->t('New Member Engagement'),
      'events_membership' => $this->t('Events ➜ Membership'),
      'retention' => $this->t('Recruitment & Retention'),
      'financial' => $this->t('Financial Snapshot'),
    ];

    $form['google_sheets'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Sheets Integration'),
      '#open' => TRUE,
      '#description' => $this->t(
        'This section configures the connection to a central Google Sheet for fetching KPI data.<br><br>
        <b>API Key:</b> A Google API key is required for the site to read the sheet, even if it is public. You can create one in the <a href="@credentials_url" target="_blank">Google Cloud Console</a>. Ensure the "Google Sheets API" is enabled for your project.<br><br>
        <b>Sheet Formatting Instructions:</b><br>
        The data in the Google Sheet must be structured correctly to be parsed. The service expects a simple 2D table with the first row as headers. New chart types and data formats can be added by a developer via code.<br><br>
        <em>For Bar Charts:</em>
        <ul>
          <li><b>Column A:</b> The labels for each bar (e.g., "Q1 2023", "Q2 2023").</li>
          <li><b>Column B:</b> The numerical values for each bar.</li>
        </ul>',
        ['@credentials_url' => 'https://console.cloud.google.com/apis/credentials']
      ),
    ];

    $form['google_sheets']['google_sheet_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google Sheet URL'),
      '#default_value' => $config->get('google_sheet_url') ?? '',
      '#description' => $this->t('The full URL of the public Google Sheet.'),
    ];

    $form['google_sheets']['google_api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Google API Key'),
      '#default_value' => $config->get('google_api_key') ?? '',
      '#description' => $this->t('The Google API key for accessing the Google Sheets API.'),
    ];

    $form['google_sheets_status'] = [
      '#type' => 'details',
      '#title' => $this->t('Google Sheet Data Status'),
      '#open' => TRUE,
      '#description' => $this->t('This section shows the status of the data connection for each chart defined in the code.'),
    ];

    $chart_services = $this->googleSheetChartManager->getCharts();
    $items = [];
    foreach ($chart_services as $chart_service) {
      $metadata = $chart_service->getGoogleSheetChartMetadata();
      if (empty($metadata)) {
        continue;
      }
      $label = $metadata['label'];
      $tab_name = $metadata['tab_name'];
      $data = $this->googleSheetClientService->getSheetData($tab_name);
      $status = !empty($data) ? '✅ Data found' : '❌ Data not found';
      $items[] = $this->t('@label: @status', ['@label' => $label, '@status' => $status]);
    }

    $form['google_sheets_status']['status'] = [
      '#theme' => 'item_list',
      '#items' => $items,
      '#title' => $this->t('Chart Data Status'),
    ];

    foreach ($tabs as $key => $label) {
      $form['notes'][$key] = [
        '#type' => 'textarea',
        '#title' => $label,
        '#default_value' => $notes[$key] ?? '',
        '#rows' => 4,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $daily = (int) $form_state->getValue('daily_window_days');
    $rolling = (int) $form_state->getValue('rolling_window_days');
    if ($daily < 7) {
      $form_state->setErrorByName('daily_window_days', $this->t('The daily chart window must be at least 7 days.'));
    }
    if ($rolling < $daily) {
      $form_state->setErrorByName('rolling_window_days', $this->t('The rolling-average window must be greater than or equal to the daily chart window.'));
    }

    $cohort = (int) $form_state->getValue('engagement_cohort_window_days');
    $activation = (int) $form_state->getValue('engagement_activation_window_days');
    if ($cohort < 1) {
      $form_state->setErrorByName('engagement_cohort_window_days', $this->t('The cohort window must be at least 1 day.'));
    }
    if ($activation < 1) {
      $form_state->setErrorByName('engagement_activation_window_days', $this->t('The activation window must be at least 1 day.'));
    }

    $orientationInput = (string) $form_state->getValue('engagement_orientation_badge_ids');
    if ($orientationInput !== '') {
      $ids = $this->parseIdList($orientationInput);
      if (empty($ids)) {
        $form_state->setErrorByName('engagement_orientation_badge_ids', $this->t('Provide at least one numeric taxonomy term ID.'));
      }
      else {
        $form_state->setValue('engagement_orientation_badge_ids', $ids);
      }
    }
    else {
      $form_state->setValue('engagement_orientation_badge_ids', []);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $config = $this->configFactory->getEditable('makerspace_dashboard.settings');

    $config
      ->set('utilization.daily_window_days', (int) $form_state->getValue('daily_window_days'))
      ->set('utilization.rolling_window_days', (int) $form_state->getValue('rolling_window_days'))
      ->set('engagement.cohort_window_days', (int) $form_state->getValue('engagement_cohort_window_days'))
      ->set('engagement.activation_window_days', (int) $form_state->getValue('engagement_activation_window_days'))
      ->set('engagement.orientation_badge_ids', $form_state->getValue('engagement_orientation_badge_ids'))
      ->set('google_sheet_url', $form_state->getValue('google_sheet_url'))
      ->set('google_api_key', $form_state->getValue('google_api_key'));

    $notes = $form_state->getValue('notes', []);
    $config->set('tab_notes', $notes)->save();
  }

  /**
   * Parses a comma/space separated list of IDs into integers.
   */
  protected function parseIdList(string $input): array {
    $parts = preg_split('/[\s,]+/', $input);
    $ids = [];
    foreach ($parts as $part) {
      $part = trim($part);
      if ($part === '') {
        continue;
      }
      if (ctype_digit($part)) {
        $ids[] = (int) $part;
      }
    }
    return array_values(array_unique($ids));
  }

}
