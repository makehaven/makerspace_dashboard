<?php

namespace Drupal\makerspace_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\makerspace_dashboard\Service\GoogleSheetClientService;
use Drupal\makerspace_dashboard\Service\DashboardSectionManager;
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
   * The Dashboard Section Manager service.
   *
   * @var \Drupal\makerspace_dashboard\Service\DashboardSectionManager
   */
  protected $dashboardSectionManager;

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
   * @param \Drupal\makerspace_dashboard\Service\DashboardSectionManager $dashboard_section_manager
   *   The Dashboard Section Manager service.
   * @param \Drupal\makerspace_dashboard\Service\GoogleSheetChartManager $google_sheet_chart_manager
   *   The Google Sheet Chart Manager service.
   */
  public function __construct(ConfigFactoryInterface $config_factory, GoogleSheetClientService $google_sheet_client_service, DashboardSectionManager $dashboard_section_manager, GoogleSheetChartManager $google_sheet_chart_manager) {
    parent::__construct($config_factory);
    $this->googleSheetClientService = $google_sheet_client_service;
    $this->dashboardSectionManager = $dashboard_section_manager;
    $this->googleSheetChartManager = $google_sheet_chart_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('makerspace_dashboard.google_sheet_client'),
      $container->get('makerspace_dashboard.section_manager'),
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

    $form['notes'] = [
      '#type' => 'details',
      '#title' => $this->t('Section intros'),
      '#description' => $this->t('Add introductory text for each dashboard section.'),
      '#open' => TRUE,
      '#tree' => TRUE,
      '#attributes' => ['id' => 'edit-notes'],
    ];

    $notes = $config->get('tab_notes') ?? [];
    $sections = $this->dashboardSectionManager->getSections();
    foreach ($sections as $section) {
      $form['notes'][$section->getId()] = [
        '#type' => 'textarea',
        '#title' => $section->getLabel(),
        '#default_value' => $notes[$section->getId()] ?? '',
        '#rows' => 4,
      ];
    }

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

    $form['google_sheets']['api_setup_help'] = [
      '#type' => 'item',
      '#markup' => $this->t(
        '<b>How to get a Google API Key:</b><br>
        <ol>
          <li>Go to the <a href="@cloud_console_url" target="_blank">Google Cloud Console</a>.</li>
          <li>Create a new project (or select an existing one).</li>
          <li>From the navigation menu, go to <b>APIs & Services > Library</b>.</li>
          <li>Search for "Google Sheets API" and click <b>Enable</b>.</li>
          <li>Go to <b>APIs & Services > Credentials</b>.</li>
          <li>Click <b>Create Credentials > API key</b>.</li>
          <li>Copy the generated API key and paste it into the field above.</li>
          <li><b>Important:</b> For security, it is highly recommended to restrict the API key. Click on the new key, and under "Application restrictions", select "HTTP referrers". Add your website\'s domain to the list of allowed referrers. Under "API restrictions", select "Restrict key" and choose the "Google Sheets API".</li>
        </ol>',
        ['@cloud_console_url' => 'https://console.cloud.google.com/']
      ),
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $config = $this->configFactory->getEditable('makerspace_dashboard.settings');

    $config
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
