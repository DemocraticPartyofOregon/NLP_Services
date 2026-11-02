<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\Batch\ImportMatchbacksBatch;
use Drupal\nlpservices\NlpMatchbacks;

/**
 * @noinspection PhpUnused
 */
class ImportMatchbacksForm extends FormBase
{

  use PlainFileUploadTrait;

  protected NlpMatchbacks $matchbacksObj;

  public function __construct( $fileObj, $matchbacksObj, $requestStack) {
    $this->fileObj = $fileObj;
    $this->matchbacksObj = $matchbacksObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportMatchbacksForm
  {
    return new static(
      $container->get('file_system'),
      $container->get('nlpservices.matchbacks'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_import_matchbacks_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attributes']['enctype'] = 'multipart/form-data';
    // Ask for the ballot-received file to upload.
    $form['hint'] = [
      '#type' => 'markup',
      '#markup' => t('Hint: The upload will be faster if the file is sorted by VANID.'),
    ];
    // Name of the matchback file to upload.  A plain file field; see
    // PlainFileUploadTrait.
    $form['matchback_file'] = [
      '#type' => 'file',
      '#title' => t('Matchback file name'),
      '#description' => t('Select a Ballot Received file.<br>'),
    ];
    // A button for the upload of voting results.
    $form['upload_file'] = [
      '#type' => 'submit',
      '#id' => 'upload-file',
      '#value' => t('Process the uploaded Matchback File >>'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Check that the file is a comma-separated (.csv) or tab-delimited (.txt)
   * export.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $matchbacksFile = $this->getUploadedFile('matchback_file');
    if(empty($matchbacksFile) || !$matchbacksFile->isValid()) {
      $form_state->setErrorByName('matchback_file', t('No file uploaded.'));
      return;
    }
    if(!in_array($this->uploadedFileType($matchbacksFile), ['csv', 'txt'])) {
      $form_state->setErrorByName('matchback_file',
        t('The matchback file must be a .csv or .txt file.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    $matchbacksFile = $this->getUploadedFile('matchback_file');
    if(empty($matchbacksFile)) {return;}
    $fileType = $this->uploadedFileType($matchbacksFile);

    // Check the header in PHP's upload copy first, so a bad file is never
    // moved into public://temp.
    $fh = fopen($matchbacksFile->getRealPath(), "r");
    if (!$fh) {
      $messenger->addError(t('Failed to open Matchback file'));
      return;
    }

    if($fileType == 'csv') {
      $fieldPosHeader = fgetcsv($fh, escape: '');
    } else {
      $headerRecord = (string)fgets($fh);
      $fieldPosHeader = empty($headerRecord) ? FALSE : explode("\t", $headerRecord);
    }
    fclose($fh);
    if (!$fieldPosHeader) {
      $messenger->addError(t('Failed to read Matchback File Header'));
      return;
    }
    $header = [];
    foreach($fieldPosHeader as $fieldPos) {
      $header[] = trim((string)$fieldPos);
    }
    $fieldPos = $this->matchbacksObj->decodeMatchbackHdr($header);
    if(!$fieldPos['ok']) {
      foreach ($fieldPos['err'] as $errMsg) {
        $messenger->addWarning($errMsg);
      }
      $messenger->addError(t('Fix the problem before resubmit.'));
      return;
    }

    $fileUri = $this->moveUploadToTemp($matchbacksFile);
    if (empty($fileUri)) {
      $messenger->addError(t('Failed to save Matchback file'));
      return;
    }

    $config = Drupal::service('config.factory')->getEditable('nlpservices.configuration');
    $config->set('nlpservices-last-matchback-time',time());
    $config->save();

    // Set up the call to start a batch operation.
    $args = [
      'fileUri' => $fileUri,
      'fileType' => $fileType,
      'fieldPos' => $fieldPos['pos'],
    ];
    $batch = [
      'operations' => [
        [[ImportMatchbacksBatch::class, 'process'], [$args]],
      ],
      'finished' => [ImportMatchbacksBatch::class, 'finished'],
      'title' => t('Processing import_matchbacks upload.'),
      'init_message' => t('Matchback import is starting.'),
      'progress_message' => t('Processed @percentage % of ballots received file.'),
      'error_message' => t('Import matchbacks has encountered an error.'),
    ];
    batch_set($batch);
  }

}
