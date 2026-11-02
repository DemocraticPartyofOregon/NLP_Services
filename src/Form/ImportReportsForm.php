<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\nlpservices\Batch\ImportReportsBatch;
use Drupal\nlpservices\NlpReports;

/**
 * @noinspection PhpUnused
 */
class ImportReportsForm extends FormBase {

  protected NlpReports $reports;
  protected FileSystemInterface $fileObj;

  public function __construct( $reports, $fileObj, $requestStack) {
    $this->reports = $reports;
    $this->fileObj = $fileObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportReportsForm
  {
    return new static(
      $container->get('nlpservices.reports'),
      $container->get('file_system'),
      $container->get('request_stack'),
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
    $form['#attributes']['enctype'] = 'multipart/form-data';

    $form['empty'] = [
      '#type' => 'checkbox',
      '#title' => t('Empty the results table before this upload.'),
    ];

    // A plain file field is used, not managed_file, so no file entity is
    // saved.  On a site with the file_entity module, saving one fails with
    // "The referenced entity (file_type: undefined) does not exist."
    $form['nl_reports_file'] = [
      '#type' => 'file',
      '#title' => t('NL reports file name'),
      '#description' => t('Select a file of historical results reported by NLs.'),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $reportsFile = $this->getUploadedFile();
    if (empty($reportsFile) || !$reportsFile->isValid()) {
      $form_state->setErrorByName('nl_reports_file', t('No file uploaded.'));
      return;
    }
    $fileType = strtolower(pathinfo($reportsFile->getClientOriginalName(), PATHINFO_EXTENSION));
    if ($fileType != 'csv') {
      $form_state->setErrorByName('nl_reports_file',
        t('The reports file must be a .csv file.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $reportsFile = $this->getUploadedFile();
    if (empty($reportsFile)) { return; }

    $fileName = basename($reportsFile->getClientOriginalName());
    $fileUri = 'public://temp/' . $fileName;

    // PHP deletes the uploaded file when this request ends, so move it where
    // the batch can read it.  The batch deletes it when it finishes.
    $tempDir = 'public://temp';
    $this->fileObj->prepareDirectory($tempDir, FileSystemInterface::CREATE_DIRECTORY);
    if (!$this->fileObj->moveUploadedFile($reportsFile->getRealPath(), $fileUri)) {
      $this->messenger()->addError(t('Failed to save the reports file.'));
      return;
    }

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

  /**
   * Returns the file uploaded in the nl_reports_file field, or NULL if none.
   */
  protected function getUploadedFile(): ?UploadedFile
  {
    $files = $this->getRequest()->files->get('files', []);
    $file = $files['nl_reports_file'] ?? NULL;
    return ($file instanceof UploadedFile) ? $file : NULL;
  }

}
