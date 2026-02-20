<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;

/**
 * @noinspection PhpUnused
 */
class NlpExportNlsStatus
{

  protected FileSystemInterface $fileSystem;
  protected Connection $connection;
  protected NlpVoters $voters;
  protected NlpReports $reports;
  protected NlpNls $nls;
  protected NlpTurfs $turfsObj;
  protected DrupalUser $drupalUserObj;
  
  
  public function __construct( $fileSystem,$connection,$voters,$reports,
                               $nls, $turfsObj, $drupalUserObj ) {
    $this->fileSystem = $fileSystem;
    $this->connection = $connection;
    $this->voters = $voters;
    $this->reports = $reports;
    $this->nls = $nls;
    $this->turfsObj = $turfsObj;
    $this->drupalUserObj = $drupalUserObj;
  }

  public static function create(ContainerInterface $container): NlpExportNlsStatus
  {
    return new static(
      $container->get('file.system'),
      $container->get('database'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.drupal_user'),
    );
  }
  
  /**
   * createNlsStatusFile
   * 
   * Create a CSV file with the status of all the NLS in a county.
   *
   * @param string $tempFileName
   *   The name of the temporary file to create.
   * @param string $county
   *   The county to get the NLS for. If 'all', get all counties.
   * @param int $hd
   *   The house district to filter by, if applicable.
   *
   * @return bool
   *   True if the file was created.
   */
  public function createNlsStatusFile(string $tempFileName, string $county, int $hd=0): bool
  {
    $hdr = [  // Column names for the CSV file.
      'mcid',
      'county',
      'hd',
      'precinct',
      'First Name',
      'Last Name',
      'email',
      'phone',
      'Signed up',
      'Last Access Date',
      'Reported',
      'Attempts',
      'P2V',
      'Voters',
      'Doors',
      'Contact',
      'Stamps',
      'Address',
      'Assigned Turf',
      'Email (formatted)'
    ];
    $hdrCsv = implode(',', $hdr);
    $fileRepository = Drupal::service('file.repository');
    $file = $fileRepository->writeData($hdrCsv, $tempFileName, FileSystemInterface::EXISTS_REPLACE);
    if ($file) {
      $fileUri = $file->getFileUri();
    }
    else {
      nlp_debug_msg('ExportNlsParticipationForm','Failed to create temporary file: ');
      return FALSE;
    }
    $fh = fopen($fileUri, "a");
    fputcsv($fh, []);  // Add an empty line to separate the header from the data.
    
    if ($county=='all') {
      $counties = $this->voters->getParticipatingCounties();
    } else {
      $counties = [$county];
    }
    foreach ($counties as $county) {
      // Get the list of all the NLs in a county.
      $nlList = $this->nls->getCountyNls($county,$hd);
      if (!$nlList) {
        nlp_debug_msg('$county',$county);
        continue;
      }
      $counts = $this->reports->getCountyReportCounts($county);
      foreach ($nlList as $mcid => $nl) {
        // Count the number of voters assigned to this NL.
        $voterCount = $this->voters->getVoterCountByNl($mcid);
        //  Now get the contact attempt count and the successful f2f count.
        if (!empty($counts[$mcid]['attempts'])) {
          $attempts = $counts[$mcid]['attempts'];
          $contacts = $counts[$mcid]['contacts'];
        } else {
          $attempts = $contacts = '';
        }
        // restore the apostrophes.
        $nickname =  html_entity_decode($nl['nickname']);
        $lastName =  html_entity_decode($nl['lastName']);
        $email = $nl['email'];
        if (empty($email)) {
          $formatted_email = '';
        } else {
          $formatted_email = $nickname . ' ' . $lastName . '<' . $email . '>';
        }

        $doors = 0;
        $currentStatus = $this->nls->getNlsStatus($mcid);
        $signupStatus = $currentStatus['asked'];
        $contactMethod = $currentStatus['contact'];
        
        $turfs = $this->turfsObj->getTurfsById($mcid);
        $turfNames = '';
        foreach ($turfs as $turf) {
          $turfIndex = $turf['turfIndex'];
          if(!empty($turfNames)) {
            $turfNames .= ', ';
          }
          $turfNames .= $turf['turfName'];
          $votersInTurf = $this->voters->fetchVotersByTurf($turfIndex);
          $vanidsInTurf = array_keys($votersInTurf);
          $doorCount = $this->voters->getDoorCount($vanidsInTurf);
          $doors += $doorCount;
        }
        
        $stamps = ($contactMethod == 'mail')?$doors:0;
        $user = $this->drupalUserObj->getUserByMcid($mcid);
        $accessDate =(!empty($user['access']))?date('Y/n/j',$user['access']):'Never';
        
        $nlRecord = [
          $nl['mcid'],
          $nl['county'],
          $nl['hd'],
          $nl['precinct'],
          $nickname,
          $lastName,
          $email,
          $nl['phone'],
          $signupStatus,
          $accessDate."\t", //Stop Excel date conversion.
          $nl['resultsReported'],
          $attempts,
          $contacts,
          $voterCount,
          $doors,
          $contactMethod,
          $stamps,
          $nl['address'],
          $turfNames,
          $formatted_email,
        ];
        fputcsv($fh, $nlRecord);
      }
    }
    fclose($fh);
    return TRUE;
  }

}
