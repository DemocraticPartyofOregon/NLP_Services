<?php

namespace Drupal\nlpservices\Controller;

use Drupal;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\NlpExportTurfStatus;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

/**
 * @noinspection PhpUnused
 * used in route.
 */
class ReportsController extends ControllerBase
{
  protected NlpExportTurfStatus $exportTurfStatusObj;
  protected DrupalUser $drupalUser;

  public function __construct($exportTurfStatusObj, $drupalUser) {
    $this->exportTurfStatusObj = $exportTurfStatusObj;
    $this->drupalUser = $drupalUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): ReportsController
  {
    return new static(
      $container->get('nlpservices.export_turf_status'),
      $container->get('nlpservices.drupal_user'),
    );
  }


  /**
   * reports
   *
   * Displays the report template with the links to the various reports.
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
          '#attached' => ['library' => ['nlpservices/menu-page']],
        ],
      ];
  }

  /**
   * exportReport
   *
   * Exports the turf status to a CSV file. The columns are intended to help
   * coordinators identify NLs that are completing their assigned tasks.
   *
   * The file is created in the public://temp directory and is set as temporary.
   * Drupal will delete the file after 6 hours.
   *
   * @noinspection PhpUnused
   * used in route.
   * @return array - a render array containing the link to download the file.
   */
  public function exportReport(): array
  {
    $statusType = Drupal::service('request_stack')->getCurrentRequest()->query->get('statusType');
    $currentUser = $this->drupalUser->getCurrentUser();
    $admin = (in_array('administrator',$currentUser['roles']) OR in_array('nlp_admin',$currentUser['roles']));
    // For an NLP admin, the report will be for all the counties.  Otherwise, only the user's
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
    $file = Drupal::service('file.repository')->writeData('', $tempFileName, FileExists::Replace);
    $file->setTemporary();
    try{
      $file->save();
    }
    catch (Exception $e) {
      nlp_debug_msg('setTemporary', $e);
      return ['#markup' => "Can't set file as temporary.",];
    }
    // Create the turf status report.
    $results = FALSE;
    $statusMessage = 'Turf status';
    if ($statusType === 'nlp_turf_status') {
      $results = $this->exportTurfStatusObj->createTurfStatusFile($tempFileName, $county);
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