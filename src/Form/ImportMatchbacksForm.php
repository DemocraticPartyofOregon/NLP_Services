<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\nlpservices\NlpMatchbacks;

/**
 * @noinspection PhpUnused
 */
class ImportMatchbacksForm extends FormBase
{

  protected FileSystemInterface $fileObj;
  protected NlpMatchbacks $matchbacksObj;

  public function __construct( $fileObj, $matchbacksObj) {
    $this->fileObj = $fileObj;
    $this->matchbacksObj = $matchbacksObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportMatchbacksForm
  {
    return new static(
      $container->get('file_system'),
      $container->get('nlpservices.matchbacks'),
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
    $tempDir = 'public://temp';
    // Ask for the ballot-received file to upload.
    $form['hint'] = [
      '#type' => 'markup',
      '#markup' => t('Hint: The upload will be faster if the file is sorted by VANID.'),
    ];
    // Name of the matchback file to upload.
    $form['matchback_file'] = [
      '#type' => 'managed_file',
      '#title' => t('Matchback file name'),
      '#description' => t('Select a Ballot Received file.<br>'),
      '#progress_message' => 'Uploading',
      '#upload_location' => $tempDir,
      '#upload_validators' => ['FileExtension' => []],
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
    // The file field's own Upload and Remove buttons also run this.
    $trigger = $form_state->getTriggeringElement();
    if(($trigger['#id'] ?? '') != 'upload-file') {return;}

    $matchbacksFile = $form_state->getValue('matchback_file');
    if(empty($matchbacksFile)) {
      $form_state->setErrorByName('matchback_file', t('No file uploaded.'));
      return;
    }
    $matchbacksLoad = File::load($matchbacksFile[0]);
    $fileName = ($matchbacksLoad instanceof File) ? $matchbacksLoad->getFilename() : '';
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

    $matchbacksFile = $form_state->getValue('matchback_file');
    if(empty($matchbacksFile)) {return;}
    $matchbacksLoad = File::load ($matchbacksFile[0]);
    $fileName = '';
    if($matchbacksLoad instanceof File){
      try {
        $matchbacksLoad->save();
      } catch (Drupal\Core\Entity\EntityStorageException $e) {
        nlp_debug_msg('File save error', $e);
        return;
      }
      $fileName = $matchbacksLoad->getFilename() ;
    }
    $fileUri = 'public://temp/'.$fileName;
    $form_state->set('fileUri',$fileUri);

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

}
