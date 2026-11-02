<?php

namespace Drupal\nlpservices\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\Batch\ImportUserAccountsBatch;
use Drupal\nlpservices\DrupalUser;

/**
 * @noinspection PhpUnused
 */
class ImportUserAccountsForm extends FormBase {

  use PlainFileUploadTrait;

  protected DrupalUser $drupalUser;

  public function __construct( $drupalUser, $fileObj, $requestStack) {
    $this->drupalUser = $drupalUser;
    $this->fileObj = $fileObj;
    $this->setRequestStack($requestStack);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportUserAccountsForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
      $container->get('file_system'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_import_user_accounts_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attributes']['enctype'] = 'multipart/form-data';

    // Name of the user account file to upload.  A plain file field; see
    // PlainFileUploadTrait.
    $form['user_file'] = [
      '#type' => 'file',
      '#title' => t('User accounts file name'),
      '#description' => t('Select a user accounts file.<br>'),
    ];

    // A button for the upload of voting results.
    $form['import_file'] = [
      '#type' => 'submit',
      '#id' => 'import-file',
      '#value' => t('Process the uploaded user accounts File >>'),
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
    $userFile = $this->getUploadedFile('user_file');
    if (empty($userFile) || !$userFile->isValid()) {
      $form_state->setErrorByName('user_file', t('No file uploaded.'));
      return;
    }
    if ($this->uploadedFileType($userFile) != 'csv') {
      $form_state->setErrorByName('user_file',
        t('The user accounts file must be a .csv file.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $userFile = $this->getUploadedFile('user_file');
    if (empty($userFile)) { return; }

    $fileUri = $this->moveUploadToTemp($userFile);
    if (empty($fileUri)) {
      $this->messenger()->addError(t('Failed to save the user accounts file.'));
      return;
    }
    $args = [
      'uri' => $fileUri,
    ];
    $batch = [
      'operations' => [
        [[ImportUserAccountsBatch::class, 'process'], [$args]],
      ],
      'finished' => [ImportUserAccountsBatch::class, 'finished'],
      'title' => t('Processing import users.'),
      'init_message' => t('Users import is starting.'),
      'progress_message' => t('Processed @percentage % of user accounts file.'),
      'error_message' => t('import users has encountered an error.'),
    ];
    batch_set($batch);
  }

}
