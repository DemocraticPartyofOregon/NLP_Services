<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\nlpservices\Batch\ImportReportsBatch;
use Drupal\nlpservices\NlpReports;

/**
 * @noinspection PhpUnused
 */
class ImportReportsForm extends FormBase {
  
  protected NlpReports $reports;
  
  public function __construct( $reports) {
    $this->reports = $reports;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportReportsForm
  {
    return new static(
      $container->get('nlpservices.reports'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_import_reports_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $tempDir = 'public://temp';

    $form['empty'] = [
      '#type' => 'checkbox',
      '#title' => t('Empty the results table before this upload.'),
    ];
  
    $form['nl_reports_file'] = [
      '#type' => 'managed_file',
      '#title' => t('NL reports file name'),
      '#description' => t('Select a file of historical results reported by NLs.'),
      '#progress_message' => 'Uploading',
      '#upload_location' => $tempDir,
      '#upload_validators' => ['FileExtension' => ['extensions' => 'csv'],],
    ];
    
    $form['upload_file'] = [
      '#type' => 'submit',
      '#id' => 'upload-file',
      '#value' => t('Process the uploaded reports file >>'),
    ];
    
    $form['button_link'] = [
      '#markup' => '<a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a>',
    ];
   
    return $form;
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $reportsFile = $form_state->getValue('nl_reports_file');
    if (empty($reportsFile)) { return; }
    $reportsUpload = File::load($reportsFile[0]);
    if (!($reportsUpload instanceof File)) { return; }

    try {
      $reportsUpload->save();
    }
    catch (EntityStorageException $e) {
      nlp_debug_msg('File error.', $e);
      return;
    }
    $fileName = $reportsUpload->getFilename();
    $fileUri = 'public://temp/' . $fileName;
  
    $emptyTable = $form_state->getValue('empty');
    if(!empty($emptyTable)) {
      $this->reports->emptyNlTable();
    }
    $args = [
      'uri' => $fileUri,
    ];

    $batch = [
      'operations' => [
        [[ImportReportsBatch::class, 'process'], [$args]],
      ],
      'finished' => [ImportReportsBatch::class, 'finished'],
      'title' => t('Processing import reports.'),
      'init_message' => t('Reports import is starting.'),
      'progress_message' => t('Processed @percentage % of reports file.'),
      'error_message' => t('import reports has encountered an error.'),
    ];
    batch_set($batch);
  }
  
}
