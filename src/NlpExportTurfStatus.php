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

  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * createTurfStatusFile
   *
   * @param string $tempFileName
   * @param string $county
   * @return bool
   */
  function createTurfStatusFile(string $tempFileName, string $county): bool
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
    $fh = fopen($tempFileName,"w");
    fputcsv($fh, $header);

    $turfRecords = $this->turfsObj->getCountyTurfs($county);
    if (empty($turfRecords)) {
      return FALSE;
    }
    //  This function can take a lot of time with a large county.
    //  Keep track of the elapsed time in case we need to upgrade the server.
    set_time_limit(60);
    
    foreach ($turfRecords as $turfRecord) {
      $turfIndex = $turfRecord['turfIndex'];
      $voters = $this->votersObj->getVotersInTurf($turfIndex);
      
      if (empty($voters)) {continue;}
      // For each voter, determine if a vote was recorded and determine if there was a
      // face-to-face contact.
      $voterCount = $votedCount = $surveyResponsesCnt = $surveyResponsesVoted = $attempts = 0;
      foreach ($voters as $vanid) {
        // Get the status of the ballot returned (indicates voted).
        $voted = $this->matchbacksObj->matchbackExists($vanid);
        // Add to the count of voters and the count of those who voted.
        $voterCount++;
        if (!empty($voted)) {$votedCount++;}
        // Now check if this voter was contacted face-to-face.
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
      // Create the display of counts for this turf.
      $nickname =  html_entity_decode($turfRecord['nlFirstName']);
      $lastName =  html_entity_decode($turfRecord['nlLastName']);
      $percentVoted = ($voterCount > 0)?round($votedCount/$voterCount*100,1).'%':'0%';
      $surveyResponsesPercent = ($surveyResponsesCnt > 0)?round($surveyResponsesVoted/$surveyResponsesCnt*100,1).'%':'0%';
      $mcid = $turfRecord['mcid'];
      $nl = $this->nlsObj->getNlById($mcid);
      $nlStatus = $this->nlsObj->getNlsStatus($mcid,$county);
      
      $contact = $nlStatus['contact'];
      $stampCount = ($contact=='mail')?$turfRecord['doorCount']:0;
      
      $turfOutput = [
        $turfRecord['turfName'],
        $turfRecord['folderName'],
        $turfRecord['mapRegionName'],
        $turfRecord['turfCd'],
        $turfRecord['county'],
        $turfRecord['turfHd'],
        $turfRecord['turfPrecinct'],
        $mcid,
        $nl['firstName'],
        $nl['lastName'],
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
      
      fputcsv($fh, $turfOutput);
    }
    fclose($fh);
    
    return TRUE;
  }
  
  
}
