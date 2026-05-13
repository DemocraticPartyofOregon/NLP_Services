<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices\Form;

use Drupal;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\ApiNls;

//use Drupal\nlpservices\NlpExportCredentials;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ImportCredentialsForm extends FormBase
{
  protected FileSystemInterface $fileSystem;
  protected Connection $connection;
  protected DrupalUser $drupalUserObj;
  protected MagicWord $magicWordObj;
  protected ApiNls $apiNlsObj;
  
  public function __construct($fileSystem, $connection, $drupalUserObj, $magicWordObj, $apiNlsObj)
  {
    $this->fileSystem = $fileSystem;
    $this->connection = $connection;
    $this->drupalUserObj = $drupalUserObj;
    $this->magicWordObj = $magicWordObj;
    $this->apiNlsObj = $apiNlsObj;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ImportCredentialsForm
  {
    return new static(
      $container->get('file_system'),
      $container->get('database'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.api_nls'),
    );
  }
  
  /**
   * {@inheritdoc}
   */
  public function getFormId(): string
  {
    return 'nlpservices_import_table_form';
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array
  {

    $form['operation'] = [
      '#type' => 'radios',
      '#title' => t('Operation'),
      '#options' => [
        'import'  => t('Import credentials'),
        'restore' => t('Restore missing passwords'),
      ],
      '#default_value' => 'import',
      '#description' => t('Import replaces all credential fields. Restore only writes back a
        password when the saved password is empty, the Drupal account exists, and the
        password in the file still matches the Drupal account.'),
    ];

    $form['verify'] = [
      '#type' => 'checkbox',
      '#title' => t('Verify NL record exists'),
      '#description' => t('Check this box only if you want to remove credentials for NL records
      that no longer exist in VoteBuilder.'),
    ];

    $form['table_file'] = [
      '#type' => 'file',
      '#title' => "Credentials File",
    ];

    $form['upload_file'] = [
      '#type' => 'submit',
      '#value' => "Credentials File",
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
    // Verify we have a good export file with the necessary fields
    $tempName = $_FILES['files']['tmp_name']['table_file'];
    
    $fh = fopen($tempName, "r");
    if (!$fh) {
      $form_state->setErrorByName('table_file', 'Failed to open table file.');
      return;
    }
    $rawHeader = fgetcsv($fh);
    if (!$rawHeader) {
      $form_state->setErrorByName('table_file', 'Failed to read header.');
      return;
    }
    $headerColumns = [];
    foreach ($rawHeader as $hdrColumn) {
      $headerColumns[] = trim(strip_tags(htmlentities(stripslashes($hdrColumn), ENT_QUOTES)));
    }
    $fieldPos = array_flip($headerColumns);
    $form_state->set('tempName', $tempName);
    $form_state->set('fieldPos', $fieldPos);
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void
  {
    $messenger = Drupal::messenger();

    $operation = $form_state->getValue('operation');
    $verify    = $form_state->getValue('verify');
    $tempName  = $form_state->get('tempName');
    $fieldPos  = $form_state->get('fieldPos');
    nlp_debug_msg($fieldPos);

    $fh = fopen($tempName, "r");
    if (empty($fh)) {
      $messenger->addError('Failed to open table file.');
      return;
    }
    fgetcsv($fh);

    $restored = 0;

    do {
      $rawRecord = fgetcsv($fh);
      if (!$rawRecord) {
        break;
      }

      $credentials = array_map(function ($pos) use ($rawRecord) {
        return $rawRecord[$pos];
      }, $fieldPos);

      if ($verify) {
        $mcid   = $credentials['mcid'];
        $county = $credentials['county'];
        $nlExists = $this->apiNlsObj->nlExists($county, $mcid);
        if (!$nlExists) {continue;}
      }

      if (empty($credentials['userName'])) {
        continue;
      }

      if ($operation === 'restore') {
        $this->restorePassword($credentials, $restored);
        continue;
      }

      // --- import (original behaviour) ---
      if (empty($credentials['magicWord'])) {
        $credentials['magicWord'] = 'Unknown';
      }
      if (empty($credentials['hd']) || !is_numeric($credentials['hd'])) {
        $credentials['hd'] = NULL;
      }
      if (empty($credentials['created'])) {
        $credentials['created'] = NULL;
      }
      if (empty($credentials['access'])) {
        $credentials['access'] = NULL;
      }
      if (empty($credentials['roles'])) {
        $credentials['roles'] = NULL;
      } else {
        $credentials['roles'] = json_decode($credentials['roles']);
      }
      $this->magicWordObj->updateCredentials($credentials);

    } while (TRUE);

    if ($operation === 'restore') {
      $messenger->addStatus(t('@count password(s) restored.', ['@count' => $restored]));
    } else {
      $messenger->addStatus('The table has been successfully uploaded.');
    }
  }

  /**
   * Restores the saved password for one credential record when the password is missing.
   *
   * Skips the record when:
   * - the file password is empty,
   * - no credentials row exists in the magicWord table for this MCID,
   * - the magicWord table already has a password for this MCID, or
   * - a Drupal account exists but the file password no longer matches it.
   * Restores the password when no Drupal account exists (account not yet created).
   *
   * @param array $credentials  One row from the credentials file.
   * @param int   $restored     Running count incremented on each successful restore.
   */
  private function restorePassword(array $credentials, int &$restored): void
  {
    $mcid     = $credentials['mcid'];
    $userName = $credentials['userName'];
    $password = $credentials['magicWord'] ?? '';

    if (empty($password)) {
      return;
    }

    $existing = $this->magicWordObj->getCredentials($mcid);
    if (empty($existing)) {
      return;
    }

    $saved = $this->magicWordObj->getMagicWord($mcid);
    if (!empty($saved)) {
      return;
    }

    $drupalUser = $this->drupalUserObj->getUserByName($userName);
    if (!empty($drupalUser) && !$this->drupalUserObj->verifyPassword($userName, $password)) {
      return;
    }

    $this->magicWordObj->setMagicWord($mcid, $password);
    $restored++;
  }
}
