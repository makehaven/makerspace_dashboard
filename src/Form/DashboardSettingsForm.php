<?php

namespace Drupal\makerspace_dashboard\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings form for Makerspace dashboard configuration.
 */
class DashboardSettingsForm extends ConfigFormBase {

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
      ->set('engagement.orientation_badge_ids', $form_state->getValue('engagement_orientation_badge_ids'));

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
