<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\DatabaseFunctions;
use Drupal\nlpservices\DrupalUser;
use Exception;

/**
 * @noinspection PhpUnused
 */
class ExportTableController extends ControllerBase
{
  protected DatabaseFunctions $databaseFunctionsObj;
  protected DrupalUser $drupalUser;
  
  public function __construct( $databaseFunctionsObj , $drupalUser ) {
    $this->databaseFunctionsObj = $databaseFunctionsObj;
    $this->drupalUser = $drupalUser;
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ExportTableController
  {
    return new static(
      $container->get('nlpservices.database_functions'),
      $container->get('nlpservices.drupal_user'),
    );
  }
  
  /**
   * @return array
   * @noinspection PhpUnused
   * used in route
   */
  public function exportTable(): array
  {
    $tableName = $_GET['tableName'];
    $source = empty($_GET['source'])? '': $_GET['source'];
    
    $county = '';
    $currentUser = $this->drupalUser->getCurrentUser();
    $admin = (in_array('administrator',$currentUser['roles']) OR in_array('nlp_admin',$currentUser['roles']));
    if(!$admin) {
      $county = $currentUser['county'];
      $county = (empty($county)) ? '' : (string) $county;
    }
    
    $tempDir = 'public://temp';
    // Use a date in the name to make the file unique., just in case two people
    // are doing an export at the same time.
    $contactDate = date('Y-m-d-H-i-s',time());
    // Open a temp file for receiving the records.
    $tempFileName = "$tempDir/$tableName-$contactDate.csv";
    
    // Create a managed file for temporary use.  Drupal will delete it after 6 hours.
    $file = Drupal::service('file.repository')->writeData('', $tempFileName, FileSystemInterface::EXISTS_REPLACE);
    $file->setTemporary();
    try{
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('setTemporary',$e->getMessage());
      return ['#markup' => "Can't set file as temporary.",];
    }
    $columnNames = $this->databaseFunctionsObj->getColumnNames($tableName);
    
    $results = $this->databaseFunctionsObj->exportTable($tableName,$columnNames,$tempFileName,$county,!$admin);
    
    $page = '';
    if($results) {
      $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempFileName);
      $page .= "<h2>Export of NLP Database table $tableName.</h2>";
      $page .= '<a href="'.$url.'">Right-click to download.  </a>';
    } else {
      $page .= "</h2><p>There are no records in the table.</p>";
    }
    
    Drupal::service("page_cache_kill_switch")->trigger();
    
    $display['table_content'] = [
        '#markup' => $page,
        '#cache' => [
          'contexts' => ['url'],
          'max-age' => 0,
        ],
      ];

    if($source == 'config') {
      $display['button_link'] = [
          '#markup' => '<p></p><a href="/admin/config/nlp_menu/nlp_conf" class="button">Go back</a></p>',
      ];
    }

    return $display;
  }
  
}
