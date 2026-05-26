<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\nlpservices\NlpExportTurfStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
//use Drupal\nlpservices\DatabaseFunctions;
use Drupal\nlpservices\DrupalUser;
use Exception;

/**
 * @noinspection PhpUnused
 */

class BasicTestController extends ControllerBase
{
  protected NlpExportTurfStatus $exportTurfStatusObj;
  protected DrupalUser $drupalUser;
  
  public function __construct($exportTurfStatusObj, $drupalUser)
  {
    $this->exportTurfStatusObj = $exportTurfStatusObj;
    $this->drupalUser = $drupalUser;
  }
  
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): BasicTestController
  {
    return new static(
      $container->get('nlpservices.export_turf_status'),
      $container->get('nlpservices.drupal_user'),
    );
  }
  
  /** @noinspection PhpUnused */
  public function exportStatus(): array
  {
    $statusType = $_GET['statusType'];
    nlp_debug_msg('$statusType',$statusType);
    //$option = $_GET['option'];
    //nlp_debug_msg('$option',$option);
    
    
    
    $currentUser = $this->drupalUser->getCurrentUser();
    //nlp_debug_msg('$currentUser',$currentUser);
    $admin = (in_array('administrator',$currentUser['roles']) OR in_array('nlp_admin',$currentUser['roles']));
    if($admin) {
      $county = 'all';
    } else {
      $factory = Drupal::service('tempstore.private');
      $store = $factory->get('nlpservices.session_data');
      $county = $store->get('County');
      if(empty($county)) {
        $messenger = Drupal::messenger();
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return [
          '#markup' => "County is missing.",
        ];
      }
    }
    
    nlp_debug_msg('$admin',$admin);
    nlp_debug_msg('$county',$county);
    
    $tempDir = 'public://temp';
    // Use a date in the name to make the file unique., just in case two people
    // are doing an export at the same time.
    $contactDate = date('Y-m-d-H-i-s',time());
    // Open a temp file for receiving the records.
    $tempFileName = "$tempDir/$statusType-$contactDate.csv";
    
    // Create a managed file for temporary use.  Drupal will delete after 6 hours.
    $file = Drupal::service('file.repository')->writeData('', $tempFileName, FileExists::Replace);
    $file->setTemporary();
    try{
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('setTemporary',$e->getMessage());
      return [
        '#markup' => "Can't set file as temporary.",
      ];
    }
    //$columnNames = $this->databaseFunctionsObj->getColumnNames($tableName);
    
    $results = FALSE;
    switch ($statusType) {
      case 'nlp_turf_status':
        $results = $this->exportTurfStatusObj->createTurfStatusFile($tempFileName,$county);
        break;
      case 'nls_status':
        break;
    }
    
    
    $page = '';
    if($results) {
      $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempFileName);
      $page .= "<h2>Export of $statusType.</h2>";
      $page .= '<a href="'.$url.'">Right-click to download.  </a>';
    } else {
      $page .= "</h2><p>There are no records in the table.</p>";
    }
    
    
    Drupal::service("page_cache_kill_switch")->trigger();
    //nlp_debug_msg('$output',$output);
    
    return [
      '#markup' => $page,
      '#cache' => [
        'contexts' => ['url'],
        'max-age' => 0,
      ],
    ];
    
    
  }
  
}
