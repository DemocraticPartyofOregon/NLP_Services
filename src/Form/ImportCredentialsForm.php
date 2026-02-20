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
    
    $verify = $form_state->getValue('verify');
    $tempName = $form_state->get('tempName');
    $fieldPos = $form_state->get('fieldPos');
    nlp_debug_msg($fieldPos);
    
    $fh = fopen($tempName, "r");
    if (empty($fh)) {
      $messenger->addError('Failed to open table file.');
      return;
    }
    fgetcsv($fh);
    
    do {
      $rawRecord = fgetcsv($fh);
      if (!$rawRecord) {
        break;
      }
      
      $credentials = array_map(function ($pos) use ($rawRecord) {
        return $rawRecord[$pos];
      }, $fieldPos);
      //nlp_debug_msg('$credentials', $credentials);
      
      if($verify) {  // Check if the MCID still exists.
        $mcid = $credentials['mcid'];
        $county = $credentials['county'];
        $nlExists = $this->apiNlsObj->nlExists($county,$mcid);
        if(!$nlExists) {continue;}
      }
      if(empty($credentials['magicWord'])) {
        $credentials['magicWord'] = 'Unknown';
      }
      if(empty($credentials['userName'])) {
        continue;
      }
      
      if(empty($credentials['hd']) OR !is_numeric($credentials['hd'])) {
        $credentials['hd'] = NULL;
      }
      if(empty($credentials['created'])) {
        $credentials['created'] = NULL;
      }
      if(empty($credentials['access'])) {
        $credentials['access'] = NULL;
      }
      if(empty($credentials['roles'])) {
        $credentials['roles'] = NULL;
      } else {
        $credentials['roles'] = json_decode($credentials['roles']);
      }
      $this->magicWordObj->updateCredentials($credentials);
      //break;
    } while (TRUE);
    
    $messenger->addStatus('The table has been successfully uploaded.');
  }
}
