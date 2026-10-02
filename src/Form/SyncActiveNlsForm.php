<?php

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Exception;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\ApiExportJobs;
use Drupal\nlpservices\ApiSavedLists;
use Drupal\nlpservices\ApiFolders;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpEncryption;
use Drupal\nlpservices\NlpSessionData;
use Drupal\nlpservices\Batch\SyncActiveNlsBatch;

/**
 * @noinspection PhpUnused
 */

class SyncActiveNlsForm extends FormBase
{
  
  const string DD_FOLDER_SUFFIX = '_NLs';
  
  protected ApiExportJobs $exportJobs;
  protected ApiSavedLists $savedLists;
  protected ApiFolders $folders;
  protected NlpNls $nls;
  protected NlpEncryption $nlpEncrypt;
  protected NlpSessionData $nlpSessionObj;
  
  
  public function __construct($exportJobs, $savedLists, $folders, $nls, $nlpEncrypt, $nlpSessionObj)
  {
    $this->exportJobs = $exportJobs;
    $this->savedLists = $savedLists;
    $this->folders = $folders;
    $this->nls = $nls;
    $this->nlpEncrypt = $nlpEncrypt;
    $this->nlpSessionObj = $nlpSessionObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): SyncActiveNlsForm
  {
    return new static(
      $container->get('nlpservices.export_jobs'),
      $container->get('nlpservices.saved_lists'),
      $container->get('nlpservices.folders'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.encryption'),
      $container->get('nlpservices.session_data'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_sync_active_nls_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {
    $form['#attached']['library'][] = 'nlpservices/info-boxes';
    $form['#attached']['library'][] = 'nlpservices/table-helper';
    $form['#attached']['library'][] = 'nlpservices/button';
    $form['#attached']['library'][] = 'nlpservices/hint-note';

    $county = $this->nlpSessionObj->getCounty();
    if (empty($county)) {
      $messenger = Drupal::messenger();
      $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
      return [];
    }
    $form_state->set('county', $county);
    
    // Search for any folders with the county name somewhere in the folder name.  We do this because
    // the API can see every folder, regardless of county, and only county-specific folders are of
    // value here.
    $database = 1;
    $folderInfo = $this->folders->getApiFolders($county, $database);
    $folderIdFound = 0;
    $countyFolderName = $county . self::DD_FOLDER_SUFFIX;
    $folderId = 0;
    foreach ($folderInfo as $folderId => $folderName) {
      if ($folderName == $countyFolderName) {
        $folderIdFound = $folderId;
        break;
      }
    }
    if (empty($folderIdFound)) {
      $form['done1'] = [
        '#markup' => '<p>The county folder has not been set up. Please contact
the NLP Services Admin.</p>',
      ];
      return $form;
    }
    // Get a list of saved lists in the county folder.
    $lists = $this->savedLists->getSavedLists( $county, $database, $folderId );
    if (empty($lists)) {
      $form['no-lists'] = [
        '#markup' => '<p>There are no lists saved in the county folder.</p>',
      ];
      return $form;
    }
    // Create a list of saved lists for the selection display.
    $options = [];
    foreach ($lists as $list) {
      $options[$list['savedListId']] = '<b>Name: </b>' . $list['name'] .
        ' <b>Description:</b> ' . $list['description'] .
        ' <b>NL Count:</b> ' . $list['listCount'];
    }
    
    // No #default_value: the options are keyed by saved list ID, so a default
    // of 0 matched no list and, if the user clicked Synchronize without
    // choosing one, Drupal rejected it as "The submitted value 0 ... is not
    // allowed." With no default, #required gives a plain "field is required".
    $form['file_select'] = [
      '#title' => t('Select file with list of NLs.'),
      '#type' => 'radios',
      '#options' => $options,
      '#required' => TRUE,
    ];
    
    // Add a button to submit the form.
    $form['submit'] = [
      '#type' => 'submit',
      '#value' => 'Synchronize',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();
    $listId = (int) $form_state->getValue('file_select');
    $county = $form_state->get('county');
    
    // Get a list of vanIds for the NLs in the list.
    $database = 1;
    $activistCodes = ['NLPNotRegisteredAC', 'NLPDropoutAC', ];
    $exportJob = $this->exportJobs->getExportJobs( $county, $database, $listId, $activistCodes );
    if (empty($exportJob) or $exportJob['result'] != 'ok' OR empty($exportJob['downloadUrl'])) {
      $messenger->addError('NL database build failed.');
      return;
    }
    $this->nls->deleteNls($county);
    $remoteUrl = $exportJob['downloadUrl'];

    // Create a temporary file with a unique name and copy the remote file to it.
    $temp_dir = 'public://temp';
    $createDate = date('Y-m-d-H-i-s', time());
    $localFileUrl = $temp_dir . '/nls_list_' . $createDate . '.csv';
    try {
      $file = Drupal::service('file.repository')->writeData('', $localFileUrl,
        FileExists::Replace);
    } catch (EntityStorageException $e) {
      nlp_debug_msg('File create failed.', $e->getMessage());
      return;
    }
    $file->setTemporary();
    try {
      $file->save();
    } catch (Exception $e) {
      nlp_debug_msg('error', $e->getMessage());
      return;
    }
    copy($remoteUrl, $localFileUrl);

    // Send the local file to the batch process to chunk the list into smaller
    // parts and provide a progress throbber for creating the new list of
    // active NLs.
    $args = [
      'uri'    => $localFileUrl,
      'county' => $county,
    ];
    $batch = [
      'title'            => t('Processing sync of active NLs.'),
      'init_message'     => t('Sync is starting.'),
      'progress_message' => t('Processed @percentage % of active NLs file.'),
      'error_message'    => t('Sync of NLs has encountered an error.'),
      'finished'         => [SyncActiveNlsBatch::class, 'finished'],
      'operations'       => [
        [[SyncActiveNlsBatch::class, 'process'], [$args]],
      ],
    ];
    batch_set($batch);
  }
  
}
