<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\Core\File\FileSystemInterface;
use Drupal\nlpservices\NlpMatchbacks;

/**
 * @noinspection PhpUnused
 */
class ImportMatchbacksForm extends FormBase
{

  protected FileSystemInterface $fileObj;
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
    // Name of the matchback file to upload.  A plain file field is used, not
    // managed_file, so no file entity is saved.  On a site with the
    // file_entity module, saving one fails with "The referenced entity
    // (file_type: undefined) does not exist."
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
   * The upload field accepts any extension, so check here that the file is
   * a comma-separated (.csv) or tab-delimited (.txt) export.
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $matchbacksFile = $this->getUploadedFile();
    if(empty($matchbacksFile) || !$matchbacksFile->isValid()) {
      $form_state->setErrorByName('matchback_file', t('No file uploaded.'));
      return;
    }
    $fileName = $matchbacksFile->getClientOriginalName();
    $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if(!in_array($fileType, ['csv', 'txt'])) {
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

    $matchbacksFile = $this->getUploadedFile();
    if(empty($matchbacksFile)) {return;}
    $fileName = basename($matchbacksFile->getClientOriginalName());
    $fileUri = 'public://temp/'.$fileName;
    $form_state->set('fileUri',$fileUri);

    // PHP deletes the uploaded file when this request ends, so move it where
    // the batch can read it.  The batch deletes it when it finishes.
    $tempDir = 'public://temp';
    $this->fileObj->prepareDirectory($tempDir, FileSystemInterface::CREATE_DIRECTORY);
    if (!$this->fileObj->moveUploadedFile($matchbacksFile->getRealPath(), $fileUri)) {
      $messenger->addError(t('Failed to save Matchback file'));
      return;
    }

    $fileType = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

    $fh = fopen($fileUri, "r");
    if (!$fh) {
      $messenger->addError(t('Failed to open Matchback file'));
      return;
    }

    if($fileType == 'csv') {
      $fieldPosHeader = fgetcsv($fh, escape: '');
      if (!$fieldPosHeader) {
        $messenger->addError(t('Failed to read Matchback File Header'));
        fclose($fh);
        $this->fileObj->unlink($fileUri);
        return;
      }
    } else {
      $headerRecord = (string)fgets($fh);
      if (empty($headerRecord)) {
        $messenger->addError(t('Failed to read Matchback File Header'));
        fclose($fh);
        $this->fileObj->unlink($fileUri);
        return;
      }
      $fieldPosHeader = explode("\t", $headerRecord);
    }
    $header = [];
    foreach($fieldPosHeader as $fieldPos) {
      $headerField = trim($fieldPos);
      $header[] = $headerField;
    }
    $fieldPos = $this->matchbacksObj->decodeMatchbackHdr($header);
    if(!$fieldPos['ok']) {
      foreach ($fieldPos['err'] as $errMsg) {
        $messenger->addWarning($errMsg);
      }
      $messenger->addError(t('Fix the problem before resubmit.'));
      return;
    }
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    
    $createTime = time();
    
    $config = Drupal::service('config.factory')->getEditable('nlpservices.configuration');
    $config->set('nlpservices-last-matchback-time',$createTime);
    $config->save();
    
    fclose($fh);

    // Set up the call to start a batch operation.
    $args = [
      'fileUri' => $fileUri,
      'fileType' => $fileType,
      'fieldPos' => $fieldPos['pos'],
    ];
    $batch = [
      'operations' => [
        ['importMatchbacksBatch', [$args]],
      ],
      'file' => $modulePath.'/src/Form/ImportMatchbacksBatch.php',
      'finished' => 'importMatchbacksBatchFinished',
      'title' => t('Processing import_matchbacks upload.'),
      'init_message' => t('Matchback import is starting.'),
      'progress_message' => t('Processed @percentage % of ballots received file.'),
      'error_message' => t('Import matchbacks has encountered an error.'),
    ];
    batch_set($batch);
  }

  /**
   * Returns the file uploaded in the matchback_file field, or NULL if none.
   */
  protected function getUploadedFile(): ?UploadedFile
  {
    $files = $this->getRequest()->files->get('files', []);
    $file = $files['matchback_file'] ?? NULL;
    return ($file instanceof UploadedFile) ? $file : NULL;
  }

}
