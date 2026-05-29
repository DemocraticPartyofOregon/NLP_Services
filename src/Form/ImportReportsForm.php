<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Entity\EntityStorageException;
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
      '#upload_validators' => ['file_validate_extensions' => ['csv'],],
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
      nlp_debug_msg('File error.', $e->getMessage());
      return;
    }
    $fileName = $reportsUpload->getFilename();
    $fileUri = 'public://temp/' . $fileName;
  
    $emptyTable = $form_state->getValue('empty');
    if(!empty($emptyTable)) {
      $this->reports->emptyNlTable();
    }
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $args = [
      'uri' => $fileUri,
    ];
    
    $batch_builder = (new BatchBuilder())
      ->setTitle(t('Processing import reports.'))
      ->setFile($modulePath . '/src/Form/ImportReportsBatch.php')
      ->setFinishCallback('importReportsBatchFinished')
      ->setInitMessage(t('Reports import is starting.'))
      ->setProgressMessage(t('Processed @percentage % of reports file.'))
      ->setErrorMessage(t('import reports has encountered an error.'))
      ->addOperation('importReportsBatch', [$args,]);
    
    batch_set($batch_builder->toArray());
  }
  
}
