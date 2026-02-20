<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;
use Drupal\nlpservices\DrupalUser;

/**
 * @noinspection PhpUnused
 */
class ImportUserAccountsForm extends FormBase {
  
  protected DrupalUser $drupalUser;
  
  public function __construct( $drupalUser) {
    $this->drupalUser = $drupalUser;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportUserAccountsForm
  {
    return new static(
      $container->get('nlpservices.drupal_user'),
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
    $tempDir = 'public://temp';
  
    // Name of the user account file to upload.
    $form['user_file'] = array(
      '#type' => 'managed_file',
      '#title' => t('User accounts file name'),
      '#description' => t('Select a user accounts file.<br>'),
      '#progress_message' => 'Uploading',
      '#upload_location' => $tempDir,
      '#upload_validators' => array('file_validate_extensions' => array('csv'),),
    );
    
    // A button for the upload of voting results.
    $form['import_file'] = array(
      '#type' => 'submit',
      '#id' => 'import-file',
      '#value' => t('Process the uploaded user accounts File >>'),
    );
    
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
    $messenger = Drupal::messenger();
    $user_file = $form_state->getValue('user_file');
  
    $bg_file = File::load ($user_file[0]);
    $fileName = '';
    if(gettype($bg_file) == 'object'){
      try {
        $bg_file->save();
      } catch (Drupal\Core\Entity\EntityStorageException $e) {
        nlp_debug_msg('File save error',$e->getMessage());
        return;
      }
      $fileName = $bg_file->getFilename() ;
    }
    $fileUri = 'public://temp/'.$fileName;
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $args = array (
      'uri' => $fileUri,
    );
    $batch = array(
      'operations' => array(
        array('importUsersBatch', array($args))
      ),
      'file' => $modulePath.'/src/Form/ImportUsersBatch.php',
      'finished' => 'importUsersBatchFinished',
      'title' => t('Processing import users.'),
      'init_message' => t('Users import is starting.'),
      'progress_message' => t('Processed @percentage % of user accounts file.'),
      'error_message' => t('import users has encountered an error.'),
    );
    batch_set($batch);
    $messenger->addStatus( $this->t('import complete.'));
  }
  
}
