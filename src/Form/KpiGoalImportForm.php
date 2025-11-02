<?php

namespace Drupal\makerspace_dashboard\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\makerspace_dashboard\Service\KpiGoalImporter;
use RuntimeException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * UI form for importing KPI goal snapshots.
 */
class KpiGoalImportForm extends FormBase {

  /**
   * KPI goal importer.
   */
  protected KpiGoalImporter $importer;

  /**
   * Constructs the form.
   */
  public function __construct(KpiGoalImporter $importer) {
    $this->importer = $importer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('makerspace_dashboard.kpi_goal_importer')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'makerspace_dashboard_kpi_goal_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Upload a CSV snapshot to update KPI labels, baseline targets, goals, and descriptions. Actual KPI results continue to flow from the makerspace snapshot data pipeline. You can run a dry run first to preview the rows that will change. Review the CSV format in <code>docs/kpi-goal-import.md</code>.') . '</p>',
    ];

    $form['template_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Download CSV template'),
      '#url' => Url::fromRoute('makerspace_dashboard.kpi_import_template'),
      '#attributes' => [
        'class' => ['button', 'button--small'],
      ],
    ];

    $form['snapshot'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('KPI snapshot CSV'),
      '#description' => $this->t('Upload a UTF-8 CSV file with headers such as <code>section,kpi_id,label,base_2025,goal_2030</code>.'),
      '#upload_location' => 'temporary://makerspace_dashboard',
      '#upload_validators' => [
        'file_validate_extensions' => ['csv'],
      ],
      '#required' => TRUE,
    ];

    $form['dry_run'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Dry run (no config changes)'),
      '#default_value' => TRUE,
      '#description' => $this->t('Parse the file and list affected KPIs without saving the results.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process snapshot'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fid = $form_state->getValue('snapshot')[0] ?? NULL;
    if (!$fid) {
      $this->messenger()->addError($this->t('Upload a CSV snapshot before importing.'));
      return;
    }

    /** @var \Drupal\file\Entity\File|null $file */
    $file = File::load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('Unable to read the uploaded file.'));
      return;
    }

    $dryRun = (bool) $form_state->getValue('dry_run');
    $uri = $file->getFileUri();

    try {
      $summary = $this->importer->import($uri, $dryRun);
    }
    catch (RuntimeException $exception) {
      $this->messenger()->addError($this->t('Import failed: @message', ['@message' => $exception->getMessage()]));
      $file->delete();
      return;
    }

    $file->delete();
    $form_state->setValue('snapshot', []);

    $count = count($summary['changes']);
    if ($dryRun) {
      $this->messenger()->addWarning($this->t('Dry run complete. @count KPI rows parsed from @file. No configuration was saved.', [
        '@count' => $count,
        '@file' => $summary['path'],
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Imported @count KPI rows from @file.', [
        '@count' => $count,
        '@file' => $summary['path'],
      ]));
    }

    $labels = array_slice(array_map(static function (array $change) {
      $baseText = $change['base_2025'] !== NULL ? $change['base_2025'] : 'keep';
      $goalText = $change['goal_2030'] !== NULL ? $change['goal_2030'] : 'keep';
      return sprintf('[%s] %s (%s) base:%s goal:%s', $change['section'], $change['label'], $change['kpi'], $baseText, $goalText);
    }, $summary['changes']), 0, 10);

    if ($labels) {
      $snippet = implode(', ', $labels);
      if ($count > count($labels)) {
        $remaining = $count - count($labels);
        $snippet .= $this->t(' …and @count more.', ['@count' => $remaining]);
      }
      $this->messenger()->addStatus($this->t('Rows processed: @list', ['@list' => $snippet]));
    }

    $form_state->setRebuild(TRUE);
  }

}
