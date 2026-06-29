<?php

namespace Drupal\nlpservices;

use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpExportTurfStatus
{

  protected FileSystemInterface $fileSystem;
  protected NlpVoters $votersObj;
  protected NlpReports $reportsObj;
  protected NlpNls $nlsObj;
  protected NlpTurfs $turfsObj;
  protected NlpMatchbacks $matchbacksObj;

  public function __construct($fileSystem, $votersObj, $reportsObj, $nlsObj,
                              $turfsObj, $matchbacksObj) {
    $this->fileSystem = $fileSystem;
    $this->votersObj = $votersObj;
    $this->reportsObj = $reportsObj;
    $this->nlsObj = $nlsObj;
    $this->turfsObj = $turfsObj;
    $this->matchbacksObj = $matchbacksObj;
  }

  public static function create(ContainerInterface $container): NlpExportTurfStatus
  {
    return new static(
      $container->get('file.system'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.matchbacks'),
    );
  }

  /**
   * Creates a CSV file with the status of turfs in a county.
   *
   * @param string $tempFileName
   *   The name of the temporary file to create.
   * @param string $county
   *   The county for which to create the turf status file.
   *
   * @return bool
   *   TRUE if the file was created successfully, FALSE otherwise.
   */
  public function createTurfStatusFile(string $tempFileName, string $county): bool
  {
    $header = [
      'Turf Name',
      'Folder Name',
      'Map Region Name',
      'CD',
      'County',
      'House District',
      'Precinct',
      'MCID',
      'First Name',
      'Last Name',
      'Contact',
      'Doors',
      'Stamps',
      'Voters in turf',
      'Voted',
      'Percent voted',
      'Pledged to Vote',
      'Pledged and voted',
      'Percent pledged and voted',
      'Contact attempts',
      'Sync Date',
    ];
    $fh = fopen($tempFileName, "w");
    if (!$fh) {
      return FALSE;
    }
    fputcsv($fh, $header, escape: '');

    $turfRecords = $this->turfsObj->getCountyTurfs($county);
    if (empty($turfRecords)) {
      fclose($fh);
      return FALSE;
    }
    // Large counties can exhaust the default time limit.
    set_time_limit(60);

    foreach ($turfRecords as $turfRecord) {
      $turfIndex = $turfRecord['turfIndex'];
      $voters = $this->votersObj->getVotersInTurf($turfIndex);

      if (empty($voters)) {continue;}
      $voterCount = $votedCount = $surveyResponsesCnt = $surveyResponsesVoted = $attempts = 0;
      foreach ($voters as $vanid) {
        $voted = $this->matchbacksObj->matchbackExists($vanid);
        $voterCount++;
        if (!empty($voted)) {$votedCount++;}
        $canvassResponse = $this->reportsObj->contactAttempt($vanid);
        $surveyResponse = $canvassResponse['survey'];
        if ($surveyResponse) {
          $surveyResponsesCnt++;
          if (!empty($voted)) {$surveyResponsesVoted++;}
        }
        $attempt = $canvassResponse['attempt'];
        if ($attempt) {
          $attempts++;
        }
      }
      $percentVoted = ($voterCount > 0) ? round($votedCount/$voterCount*100,1).'%' : '0%';
      $surveyResponsesPercent = ($surveyResponsesCnt > 0) ? round($surveyResponsesVoted/$surveyResponsesCnt*100,1).'%' : '0%';
      $mcid = $turfRecord['mcid'];
      $nl = $this->nlsObj->getNlById($mcid);
      $firstName = $nl['firstName'] ?? '';
      $lastName  = $nl['lastName']  ?? '';
      $nlStatus  = $this->nlsObj->getNlsStatus($mcid,$county);
      $contact   = $nlStatus['contact'] ?? '';
      $stampCount = ($contact === 'mail') ? $turfRecord['doorCount'] : 0;

      $turfOutput = [
        $turfRecord['turfName'],
        $turfRecord['folderName'],
        $turfRecord['mapRegionName'],
        $turfRecord['turfCd'],
        $turfRecord['county'],
        $turfRecord['turfHd'],
        $turfRecord['turfPrecinct'],
        $mcid,
        $firstName,
        $lastName,
        $contact,
        $turfRecord['doorCount'],
        $stampCount,
        $voterCount,
        $votedCount,
        $percentVoted,
        $surveyResponsesCnt,
        $surveyResponsesVoted,
        $surveyResponsesPercent,
        $attempts,
        $turfRecord['syncDate'],
      ];

      fputcsv($fh, $turfOutput, escape: '');
    }
    fclose($fh);

    return TRUE;
  }

}
