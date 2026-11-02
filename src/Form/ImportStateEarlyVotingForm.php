<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\Batch\ImportStateEarlyVotingBatch;
use Drupal\nlpservices\NlpEarlyVoting;

/**
 * @noinspection PhpUnused
 */
class ImportStateEarlyVotingForm extends FormBase
{

  use PlainFileUploadTrait;

  protected NlpEarlyVoting $earlyVotingObj;

  public function __construct($earlyVotingObj, $fileObj, $requestStack)
  {
    $this->earlyVotingObj = $earlyVotingObj;
    $this->fileObj = $fileObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportStateEarlyVotingForm
  {
    return new static(
      $container->get('nlpservices.early_voting'),
      $container->get('file_system'),
      $container->get('request_stack'),
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
    $form['#attributes']['enctype'] = 'multipart/form-data';

    // A plain file field; see PlainFileUploadTrait.
    $form['state_early_voting_file'] = [
      '#type' => 'file',
      '#title' => $this->t('State early voting file name'),
      '#description' => $this->t('Select a state early voting CSV file. The first row must contain the headers "Assigned Voter ID" and "Ballot Received Date".'),
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
  public function validateForm(array &$form, FormStateInterface $form_state): void
  {
    $uploadedFile = $this->getUploadedFile('state_early_voting_file');
    if (empty($uploadedFile) || !$uploadedFile->isValid()) {
      $form_state->setErrorByName('state_early_voting_file', $this->t('No file uploaded.'));
      return;
    }
    if (!in_array($this->uploadedFileType($uploadedFile), ['csv', 'txt'])) {
      $form_state->setErrorByName('state_early_voting_file',
        $this->t('The state early voting file must be a .csv or .txt file.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = $this->messenger();

    $uploadedFile = $this->getUploadedFile('state_early_voting_file');
    if (empty($uploadedFile)) {
      return;
    }

    // Check the header in PHP's upload copy first, so a bad file is never
    // moved into public://temp.
    $fh = fopen($uploadedFile->getRealPath(), 'r');
    if (!$fh) {
      $messenger->addError($this->t('Failed to open the state early voting file.'));
      return;
    }

    $headerRow = fgetcsv($fh, escape: '');
    fclose($fh);
    if (empty($headerRow)) {
      $messenger->addError($this->t('Failed to read the state early voting file header.'));
      return;
    }

    $fieldPos = $this->earlyVotingObj->decodeStateEarlyVotingHdr($headerRow);
    if (!$fieldPos['ok']) {
      foreach ($fieldPos['err'] as $errMsg) {
        $messenger->addWarning($errMsg);
      }
      $messenger->addError($this->t('Fix the problem before resubmitting.'));
      return;
    }

    $fileUri = $this->moveUploadToTemp($uploadedFile);
    if (empty($fileUri)) {
      $messenger->addError($this->t('Failed to save the state early voting file.'));
      return;
    }

    $args = [
      'fileUri' => $fileUri,
      'fieldPos' => $fieldPos['pos'],
    ];
    $batch = [
      'operations' => [
        [[ImportStateEarlyVotingBatch::class, 'process'], [$args]],
      ],
      'finished' => [ImportStateEarlyVotingBatch::class, 'finished'],
      'title' => $this->t('Processing state early voting upload.'),
      'init_message' => $this->t('State early voting import is starting.'),
      'progress_message' => $this->t('Processed @percentage % of the state early voting file.'),
      'error_message' => $this->t('Import of state early voting has encountered an error.'),
    ];
    batch_set($batch);
  }

}
