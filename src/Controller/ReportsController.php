<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpExportNlsStatus;
use Drupal\nlpservices\NlpExportTurfStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\nlpservices\NlpResultsSummary;
use Drupal\nlpservices\NlpCdResultsSummary;
use Exception;

/**
 * @noinspection PhpUnused
 * used in route.
 */
class ReportsController extends ControllerBase
{
  protected NlpResultsSummary $resultsSummaryObj;
  protected NlpExportTurfStatus $exportTurfStatusObj;
  protected  NlpExportNlsStatus $exportNlsStatusObj;
  protected DrupalUser $drupalUser;
  
  public function __construct( $resultsSummaryObj, $exportTurfStatusObj, $exportNlsStatusObj, $drupalUser ) {
    $this->resultsSummaryObj = $resultsSummaryObj;
    $this->exportTurfStatusObj = $exportTurfStatusObj;
    $this->exportNlsStatusObj = $exportNlsStatusObj;
    $this->drupalUser = $drupalUser;
  }
  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ReportsController
  {
    return new static(
      $container->get('nlpservices.results_summary'),
      $container->get('nlpservices.export_turf_status'),
      $container->get('nlpservices.export_nls_status'),
      $container->get('nlpservices.drupal_user'),
    );
  }
  
  
  /**
   * reports
   *
   * Displays the reports template with the links to the various reports.
   * This is the main entry point for the reports.
   *
   * @return array - a render array containing the reports template.
   */
  public function reports(): array
  {
    $modulePath = Drupal::service('extension.list.module')->getPath(NLP_MODULE);
    $template_path = $modulePath . "/src/Templates/reports.html.twig";
    $template = file_get_contents($template_path);
    $variables = ['module' => 'nlpservices',];
    Drupal::service("page_cache_kill_switch")->trigger();
    return
      [
        'description' => [
          '#type' => 'inline_template',
          '#template' => $template,
          '#context' => $variables,
        ],
      ];
  }
  
  /**
   * countyResultsSummary
   *
   * Displays the county results summary.
   *
   * @noinspection PhpUnused
   * used in route.
   * @return array
   */
  public function countyResultsSummary(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
    $output = $this->resultsSummaryObj->displayCountyResultsSummary();
    $output['table-start']['#attached']['library'][] = 'nlpservices/info-boxes';
    return $output;
  }
  
  /**
   * cdResultsSummary
   *
   * Displays the results summary for the participating CDs.
   *
   * @noinspection PhpUnused
   * used in route.
   * @return array
   */
  public function cdResultsSummary(): array
  {
    Drupal::service("page_cache_kill_switch")->trigger();
    $output = $this->resultsSummaryObj->displayCdResultsSummary();
    $output['table-start']['#attached']['library'][] = 'nlpservices/info-boxes';
    return $output;
  }
  
  /**
   * exportReport
   *
   * Exports the report data to a CSV file.  There are two possible reports created
   * here, one for the turf status and one for the NLs status.  The columns for each
   * are intend to help coordinators with identifying NLs that are completing their
   * assigned tasks.
   *
   * The file is created in the public://temp directory and is set as temporary.
   * The file will be deleted by Drupal after 6 hours.
   *
   * @noinspection PhpUnused
   * used in route.
   * @return array - a render array containing the link to download the file.
   */
  public function exportReport(): array
  {
    $statusType = $_GET['statusType'];
    $currentUser = $this->drupalUser->getCurrentUser();
    $admin = (in_array('administrator',$currentUser['roles']) OR in_array('nlp_admin',$currentUser['roles']));
    // For an NLP admin, the report will be for all the counties.  Otherwise, only the users
    // county will be included in the report.
    if($admin) {
      $county = 'all';
      $countyMessage = 'all Nlp Counties';
    } else {
      $factory = Drupal::service('tempstore.private');
      $store = $factory->get('nlpservices.session_data');
      $county = $store->get('County');
      if(empty($county)) {
        $messenger = Drupal::messenger();
        $messenger->addWarning(t('The county is not specified. Use the "Manage County" tab to select a county.'));
        return ['#markup' => "County is missing.",];
      }
      $countyMessage = $county.' County';
    }
    
    // Create a managed file for temporary use.  Drupal will delete it after 6 hours.
    $tempDir = 'public://temp';
    $contactDate = date('Y-m-d-H-i-s',time());
    $tempFileName = "$tempDir/$statusType-$contactDate.csv";
    $file = Drupal::service('file.repository')->writeData('', $tempFileName, FileSystemInterface::EXISTS_REPLACE);
    $file->setTemporary();
    try{
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('setTemporary',$e->getMessage());
      return ['#markup' => "Can't set file as temporary.",];
    }
    // Create a file with one of the two possible reports.
    $results = FALSE;
    $statusMessage = '??';
    switch ($statusType) {
      case 'nlp_turf_status':
        $results = $this->exportTurfStatusObj->createTurfStatusFile($tempFileName,$county);
        $statusMessage = 'Turf status';
        break;
      case 'nlp_nls_status':
        $results = $this->exportNlsStatusObj->createNlsStatusFile($tempFileName,$county);
        $statusMessage = 'NLs status';
        break;
    }
    // Display a link to the file for the user to download.
    $page = '';
    if($results) {
      $url = Drupal::service('file_url_generator')->generateAbsoluteString($tempFileName);
      $page .= "<h2>Export of $statusMessage for $countyMessage.</h2>";
      $page .= '<a href="'.$url.'">Right-click to download.  </a>';
    } else {
      $page .= "</h2><p>There are no records in the table.</p>";
    }
    
    Drupal::service("page_cache_kill_switch")->trigger();
    return [
      '#markup' => $page,
      '#cache' => [
        'contexts' => ['url'],
        'max-age' => 0,
      ],
    ];
    
  }

}
