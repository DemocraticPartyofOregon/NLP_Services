<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\File\FileExists;
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
   * Creates a CSV file containing the status of NLS users for a specified county and HD. The file is stored in a temporary directory and is intended for use in analyzing NLS participation and engagement.
   *
   * @param string $tempFileName The name of the temporary file to be created.
   * @param string $county The county for which to generate the NLS status report. Use 'all' to include all counties.
   * @param int $hd (optional) The HD for which to generate the NLS status report. Default is 0, which includes all HDs.
   * @return bool TRUE if the file was created successfully, FALSE otherwise.
   */
  public function createNlsStatusFile(string $tempFileName, string $county, int $hd=0): bool
  {
    $hdr = [
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
    $hdrCsv = implode(',', $hdr) . "\n";
    $fileRepository = Drupal::service('file.repository');
    try {
      $file = $fileRepository->writeData($hdrCsv, $tempFileName, FileExists::Replace);
    } catch (EntityStorageException $e) {
      nlp_debug_msg('ExportNlsParticipationForm', $e->getMessage());
      return FALSE;
    }
    if ($file) {
      $fileUri = $file->getFileUri();
    } else {
      nlp_debug_msg('ExportNlsParticipationForm','Failed to create temporary file: ');
      return FALSE;
    }
    $fh = fopen($fileUri, "a");
    if (!$fh) {
      return FALSE;
    }

    if ($county === 'all') {
      $counties = $this->voters->getParticipatingCounties();
    } else {
      $counties = [$county];
    }
    nlp_debug_msg_safe('$counties',$counties);
    foreach ($counties as $countyName) {
      $nlList = $this->nls->getCountyNls($countyName,$hd);
      if (!$nlList) {
        nlp_debug_msg('$countyName',$countyName);
        continue;
      }
      $counts = $this->reports->getCommitteeReportCounts($countyName);
      foreach ($nlList as $mcid => $nl) {
        $voterCount = $this->voters->getVoterCountByNl($mcid);
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

        $stamps = ($contactMethod === 'mail') ? $doors : 0;
        $user = $this->drupalUserObj->getUserByMcid($mcid);
        $accessDate = (!empty($user['access'])) ? date('Y/n/j',$user['access']) : 'Never';

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
