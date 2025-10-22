<?php

namespace Drupal\makerspace_dashboard\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Drupal\makerspace_dashboard\Service\DashboardSectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form wrapper for rendering Makerspace dashboard vertical tabs.
 */
class DashboardForm extends FormBase {

  /**
   * Dashboard section manager.
   */
  protected DashboardSectionManager $sectionManager;

  /**
   * Renderer service.
   */
  protected RendererInterface $renderer;

  /**
   * Constructs the dashboard form.
   */
  public function __construct(DashboardSectionManager $section_manager, RendererInterface $renderer) {
    $this->sectionManager = $section_manager;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('makerspace_dashboard.section_manager'),
      $container->get('renderer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_dashboard_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'makerspace-dashboard-wrapper';
    $form['#method'] = 'get';

    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Makerspace Dashboard'),
      '#attributes' => ['class' => ['makerspace-dashboard-tabs']],
    ];

    $filters = [];
    $sections = $this->sectionManager->getSections();
    $tab_notes = $this->config('makerspace_dashboard.settings')->get('tab_notes') ?? [];
    $index = 0;
    foreach ($sections as $section) {
      $section_id = $section->getId();
      $form[$section_id] = [
        '#type' => 'details',
        '#title' => $section->getLabel(),
        '#group' => 'tabs',
        '#open' => $index === 0,
        '#attributes' => ['class' => ['makerspace-dashboard-section']],
      ];
      $note_raw = $tab_notes[$section_id] ?? '';
      $note_value = trim((string) $note_raw);
      if ($note_value !== '') {
        $edit_link = Link::fromTextAndUrl(
          $this->t('Edit notes'),
          Url::fromRoute('makerspace_dashboard.settings', [], ['fragment' => 'edit-notes'])
        )->toRenderable();
        $form[$section_id]['note'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['makerspace-dashboard-tab-note']],
          'text' => [
            '#markup' => nl2br(Html::escape($note_value)),
          ],
          'edit' => $edit_link,
        ];
      }
      $form[$section_id]['content'] = $section->build($filters);
      $index++;
    }

    $form['footer_note'] = [
      '#type' => 'markup',
      '#markup' => $this->t('All metrics shown are aggregated to protect member privacy. Use configuration to adjust minimum counts and data sources.'),
      '#prefix' => '<div class="makerspace-dashboard-footer">',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Dashboard page does not submit; method exists to satisfy interface.
  }

}
