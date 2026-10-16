<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;
use Drupal\nlpservices\NlpEarlyVoting;

/**
 * @noinspection PhpUnused
 */
class ImportStateEarlyVotingForm extends FormBase
{

  protected NlpEarlyVoting $earlyVotingObj;

  public function __construct($earlyVotingObj)
  {
    $this->earlyVotingObj = $earlyVotingObj;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportStateEarlyVotingForm
  {
    return new static(
      $container->get('nlpservices.early_voting'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_import_state_early_voting_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $tempDir = 'public://temp';

    $form['state_early_voting_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('State early voting file name'),
      '#description' => $this->t('Select a state early voting CSV file. The first row must contain the headers "Assigned Voter ID" and "Ballot Received Date".'),
      '#progress_message' => 'Uploading',
      '#upload_location' => $tempDir,
      '#upload_validators' => ['FileExtension' => ['extensions' => 'csv txt']],
      '#required' => TRUE,
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Import >>'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = $this->messenger();

    $uploadedFile = $form_state->getValue('state_early_voting_file');
    if (empty($uploadedFile)) {
      return;
    }
    $fileEntity = File::load($uploadedFile[0]);
    if (!$fileEntity instanceof File) {
      $messenger->addError($this->t('Failed to load the uploaded file.'));
      return;
    }
    try {
      $fileEntity->save();
    } catch (Drupal\Core\Entity\EntityStorageException $e) {
      nlp_debug_msg('File save error', $e);
      return;
    }
    $fileUri = 'public://temp/' . $fileEntity->getFilename();

    $fh = fopen($fileUri, 'r');
    if (!$fh) {
      $messenger->addError($this->t('Failed to open the state early voting file.'));
      $fileEntity->delete();
      return;
    }

    $headerRow = fgetcsv($fh, escape: '');
    if (empty($headerRow)) {
      $messenger->addError($this->t('Failed to read the state early voting file header.'));
      fclose($fh);
      $fileEntity->delete();
      return;
    }
    fclose($fh);

    $fieldPos = $this->earlyVotingObj->decodeStateEarlyVotingHdr($headerRow);
    if (!$fieldPos['ok']) {
      foreach ($fieldPos['err'] as $errMsg) {
        $messenger->addWarning($errMsg);
      }
      $messenger->addError($this->t('Fix the problem before resubmitting.'));
      $fileEntity->delete();
      return;
    }

    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);

    $args = [
      'fileUri' => $fileUri,
      'fieldPos' => $fieldPos['pos'],
    ];
    $batch = [
      'operations' => [
        ['importStateEarlyVotingBatch', [$args]],
      ],
      'file' => $modulePath . '/src/Form/ImportStateEarlyVotingBatch.php',
      'finished' => 'importStateEarlyVotingBatchFinished',
      'title' => $this->t('Processing state early voting upload.'),
      'init_message' => $this->t('State early voting import is starting.'),
      'progress_message' => $this->t('Processed @percentage % of the state early voting file.'),
      'error_message' => $this->t('Import of state early voting has encountered an error.'),
    ];
    batch_set($batch);
  }

}
