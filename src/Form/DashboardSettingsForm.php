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

    $form['notes'] = [
      '#type' => 'details',
      '#title' => $this->t('Tab notes'),
      '#description' => $this->t('Add contextual notes for each dashboard tab. Notes are visible to all viewers.'),
      '#open' => TRUE,
      '#tree' => TRUE,
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
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    parent::submitForm($form, $form_state);
    $config = $this->configFactory->getEditable('makerspace_dashboard.settings');

    $config
      ->set('utilization.daily_window_days', (int) $form_state->getValue('daily_window_days'))
      ->set('utilization.rolling_window_days', (int) $form_state->getValue('rolling_window_days'));

    $notes = $form_state->getValue('notes', []);
    $config->set('tab_notes', $notes)->save();
  }

}
