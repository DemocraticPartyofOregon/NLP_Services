<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpSyncTurfProcessor {

  const int MAX_QUEUE_LIMIT = 17;

  protected ApiExportJobs $exportJobsObj;
  protected ApiSavedLists $savedListsObj;
  protected ApiVoter $apiVoterObj;
  protected NlpNls $nlsObj;
  protected ApiActivistCodes $activistCodesObj;
  protected NlpVoters $votersObj;
  protected NlpTurfs $turfsObj;
  protected NlpConfiguration $configurationObj;
  protected NlpAwards $awardsObj;
  protected NlpReports $reportsObj;
  protected NlpPaths $pathsObj;
  protected NlpTurfAssignments $turfAssignmentsObj;
  protected MessengerInterface $messenger;

  public function __construct(
    $exportJobsObj,
    $savedListsObj,
    $apiVoterObj,
    $nlsObj,
    $activistCodesObj,
    $votersObj,
    $turfsObj,
    $configurationObj,
    $awardsObj,
    $reportsObj,
    $pathsObj,
    $turfAssignmentsObj,
    $messenger
  ) {
    $this->exportJobsObj      = $exportJobsObj;
    $this->savedListsObj      = $savedListsObj;
    $this->apiVoterObj        = $apiVoterObj;
    $this->nlsObj             = $nlsObj;
    $this->activistCodesObj   = $activistCodesObj;
    $this->votersObj          = $votersObj;
    $this->turfsObj           = $turfsObj;
    $this->configurationObj   = $configurationObj;
    $this->awardsObj          = $awardsObj;
    $this->reportsObj         = $reportsObj;
    $this->pathsObj           = $pathsObj;
    $this->turfAssignmentsObj = $turfAssignmentsObj;
    $this->messenger          = $messenger;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('nlpservices.export_jobs'),
      $container->get('nlpservices.saved_lists'),
      $container->get('nlpservices.api_voter'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.turf_assignments'),
      $container->get('messenger'),
    );
  }

  public function processBatch(array $arg, array &$context): void {
    $county     = $arg['county'];
    $folderId   = $arg['folderId'];
    $folderName = $arg['folderName'];
    $turfId     = $arg['turfId'];
    $turfName   = $arg['turfName'];
    $mcid       = $arg['mcid'];

    if (empty($context['sandbox']['currentBatch'])) {
      $database  = 0;
      $exportJob = $this->exportJobsObj->getExportJobs($county, $database, $turfId);
      $context['sandbox']['votersInTurf'] = [];
      if (empty($exportJob['downloadUrl'])) {
        $result = $this->savedListsObj->recoverTurfId($county, $folderId, $turfName);
        if ($result['result'] == 'error') {
          $context['finished']              = 1;
          $context['results']['message']    = [0 => $result['message']];
          $context['results']['result']     = 'error';
          return;
        }
        $exportJob = $this->exportJobsObj->getExportJobs($county, $database, $result['turfId']);
        if (empty($exportJob['downloadUrl'])) {
          $context['finished']           = 1;
          $context['results']['message'] = [0 => t('The turf is not accessible by ID.')];
          $context['results']['result']  = 'error';
          return;
        }
      }
      $fh = fopen($exportJob['downloadUrl'], 'r');
      if (!$fh) {
        $context['finished']           = 1;
        $context['results']['message'] = [0 => t('Unable to download the turf voter list.')];
        $context['results']['result']  = 'error';
        return;
      }
      fgetcsv($fh);
      $turfVoters = [];
      $voterCount = 0;
      $voterBatch = 0;
      do {
        $listRecord = fgetcsv($fh);
        if ($listRecord === FALSE) { break; }
        if (empty($listRecord)) { continue; }
        $turfVoters[$voterBatch][] = $listRecord[1];
        $voterCount++;
        if ($voterCount == self::MAX_QUEUE_LIMIT) {
          $voterCount = 0;
          $voterBatch++;
        }
      } while (TRUE);
      if (!feof($fh)) {
        fclose($fh);
        $context['finished']           = 1;
        $context['results']['message'] = [0 => t('The turf voter download was interrupted. Please try again.')];
        $context['results']['result']  = 'error';
        return;
      }
      fclose($fh);

      $context['sandbox']['batchCount']   = count($turfVoters);
      $context['sandbox']['currentBatch'] = 0;
      $context['sandbox']['turfVoters']   = $turfVoters;
      $context['sandbox']['duplicates']   = [];
      $context['sandbox']['votersInTurf'] = [];
      $context['sandbox']['movedVoters']  = [];

      $turf = [
        'county'     => $county,
        'mcid'       => $mcid,
        'turfName'   => $turfName,
        'turfPdf'    => NULL,
        'folderId'   => $folderId,
        'folderName' => $folderName,
        'listId'     => $turfId,
      ];
      $turfIndex = $this->turfsObj->createTurf($turf);
      $context['sandbox']['turfIndex']            = $turfIndex;
      $context['sandbox']['mcid']                 = $mcid;
      $context['sandbox']['county']               = $county;
      $context['sandbox']['legislativeDistricts'] = [];
    }

    $currentBatch         = $context['sandbox']['currentBatch'];
    $batchCount           = $context['sandbox']['batchCount'];
    $turfVoters           = $context['sandbox']['turfVoters'];
    $turfIndex            = $context['sandbox']['turfIndex'];
    $votersInTurf         = $context['sandbox']['votersInTurf'];
    $legislativeDistricts = $context['sandbox']['legislativeDistricts'];

    $voters      = $this->apiVoterObj->getVoters($turfVoters[$currentBatch]);
    $onePrecinct = $this->validateTurfPrecinct($legislativeDistricts, $voters);
    $context['sandbox']['legislativeDistricts'] = $legislativeDistricts;

    if (!$onePrecinct) {
      $context['finished']              = 1;
      $context['results']['message']    = [0 => t('The turf must be for one precinct. This error likely means the saved list is old and the map region should be refreshed.')];
      $context['results']['result']     = 'error';
      $context['results']['turfIndex']  = $context['sandbox']['turfIndex'];
      $context['results']['mcid']       = $context['sandbox']['mcid'];
      $context['results']['county']     = $context['sandbox']['county'];
      return;
    }

    $duplicates = $this->getDuplicates($voters);
    if (!empty($duplicates)) {
      $movedVoters = $context['sandbox']['movedVoters'];
      foreach ($duplicates as $vanid => $duplicate) {
        $oldTurf = $this->turfsObj->getTurf($duplicate['turfIndex']);
        $movedVoters[$vanid] = [
          'firstName'   => $duplicate['firstName'],
          'lastName'    => $duplicate['lastName'],
          'oldTurfName' => $oldTurf['turfName'],
          'oldMcid'     => $duplicate['mcid'],
          'oldNlName'   => $oldTurf['nlFirstName'] . ' ' . $oldTurf['nlLastName'],
        ];
        $request = ['vanid' => $vanid, 'codeName' => 'NLPVoterAC', 'action' => 'Remove'];
        $this->activistCodesObj->setApiActivistCode($request);
        $this->votersObj->deleteVoterInTurf($vanid);
      }
      $context['sandbox']['movedVoters'] = $movedVoters;
    }

    foreach ($voters as $voter) {
      $vanid   = $voter['vanid'];
      $request = [
        'vanid'    => $vanid,
        'codeName' => 'NLPVoterAC',
        'action'   => 'Apply',
      ];
      if (!$this->activistCodesObj->setApiActivistCode($request)) {
        Drupal::logger('nlpservices')->warning('Failed to set NLPVoterAC for vanid @vanid during turf sync', ['@vanid' => $vanid]);
      }

      $voter['address']['turfIndex'] = $turfIndex;
      $voter['address']['moved']     = NULL;
      $this->votersObj->createVoter($voter);

      $voterStatus            = $this->votersObj->getVoterStatus($vanid);
      $cycle                  = $this->configurationObj->getCycle();
      $voterStatus['vanid']   = $vanid;
      $voterStatus['cycle']   = $cycle;
      $voterStatus['hostile'] = (!empty($voter['activistCodes']['hostile'])) ? 1 : 0;
      $this->votersObj->setVoterStatus($vanid, $voterStatus);

      $votersInTurf[$vanid] = $voter;
    }

    $currentBatch++;
    $context['sandbox']['currentBatch'] = $currentBatch;

    if ($currentBatch >= $batchCount) {
      foreach ($votersInTurf as $voter) {
        $turfVoter              = [];
        $turfVoter['mcid']      = $mcid;
        $turfVoter['county']    = $county;
        $turfVoter['turfIndex'] = $turfIndex;
        $turfVoter['vanid']     = $voter['vanid'];
        $this->votersObj->createTurfVoter($turfVoter);
        $this->reloadVoterStatus($voter, $turfIndex, $mcid);
      }

      $this->awardsObj->nlParticipation($mcid, 'Turf synced');
      $this->finalizeTurf($arg, $context['sandbox']);

      $nl = $this->nlsObj->getNlById($mcid);

      $context['results']['mcid']        = $mcid;
      $context['results']['turfName']    = $turfName;
      $context['results']['county']      = $county;
      $context['results']['voterCount']  = $arg['voterCount'];
      $context['results']['turfCnt']     = count($votersInTurf);
      $context['results']['nickname']    = $nl['nickname'];
      $context['results']['result']      = 'success';
      $context['results']['turfIndex']   = $context['sandbox']['turfIndex'];
      $context['results']['movedVoters'] = $context['sandbox']['movedVoters'];
      $context['sandbox']['finished']   = 1;
      return;
    }

    $context['finished']                = $currentBatch / $batchCount;
    $context['sandbox']['votersInTurf'] = $votersInTurf;
  }

  private function validateTurfPrecinct(array &$legislativeDistricts, array $voters): bool {
    $vanids = array_keys($voters);
    if (empty($legislativeDistricts)) {
      $firstVanid = reset($vanids);
      $precinct   = $voters[$firstVanid]['address']['precinct'];
      $hd         = $voters[$firstVanid]['address']['hd'];
      $cd         = $voters[$firstVanid]['address']['cd'];
      $count      = 0;
    } else {
      $precinct = $legislativeDistricts['precinct'];
      $hd       = $legislativeDistricts['hd'];
      $cd       = $legislativeDistricts['cd'];
      $count    = $legislativeDistricts['count'];
    }

    $count = $count + count($voters);
    foreach ($voters as $voter) {
      if (($precinct != $voter['address']['precinct']) || ($hd != $voter['address']['hd'])) {
        return FALSE;
      }
    }
    $legislativeDistricts = [
      'precinct' => $precinct,
      'hd'       => $hd,
      'cd'       => $cd,
      'count'    => $count,
    ];
    return TRUE;
  }

  private function getDuplicates(array $voters): array {
    $vanids        = array_keys($voters);
    $dupVoterTurfs = $this->votersObj->duplicateVoters($vanids);
    if (empty($dupVoterTurfs)) { return []; }
    $dupVanids = array_keys($dupVoterTurfs);
    $dupVoters = $this->votersObj->getVotersInList($dupVanids);
    foreach ($dupVanids as $vanid) {
      $dupVoters[$vanid]['mcid']      = $dupVoterTurfs[$vanid]['mcid'];
      $dupVoters[$vanid]['turfIndex'] = $dupVoterTurfs[$vanid]['turfIndex'];
      $dupVoters[$vanid]['county']    = $dupVoterTurfs[$vanid]['county'];
    }
    return $dupVoters;
  }

  private function reloadVoterStatus(array $voter, int $turfIndex, int $mcid): void {
    $vanid = $voter['vanid'];

    $surveyReports = $this->reportsObj->getNlpTypeReports($vanid, 'Survey');
    $pledgedToVote = 0;
    foreach ($surveyReports as $surveyReport) {
      if ($surveyReport['reportIndex'] > $pledgedToVote) {
        $pledgedToVote = $surveyReport['reportIndex'];
      }
    }

    $surveyReports    = $this->reportsObj->getNlpTypeReports($vanid, 'contact');
    $attemptedContact = 0;
    foreach ($surveyReports as $surveyReport) {
      if ($surveyReport['reportIndex'] > $attemptedContact) {
        $attemptedContact = $surveyReport['reportIndex'];
      }
    }

    $turfVoter                     = [];
    $turfVoter['vanid']            = $vanid;
    $turfVoter['attemptedContact'] = $attemptedContact;
    $turfVoter['pledgedToVote']    = $pledgedToVote;
    $turfVoter['turfIndex']        = $turfIndex;
    $this->votersObj->updateTurfVoter($turfVoter);

    $request          = [];
    $request['vanid'] = $vanid;
    $request['type']  = 'contact';
    $request['value'] = 'moved';
    $request['cycle'] = NULL;
    $movedReport = $this->reportsObj->getReport($request);
    if (!empty($movedReport['text'])) {
      $existingAddress = (array) json_decode($movedReport['text']);
      $address         = $voter['address'];
      if ($this->votersObj->addressCompare($address, $existingAddress)) {
        $this->votersObj->setMovedStatus($turfIndex, $vanid, 1);
      }
    }

    $req          = [];
    $req['vanid'] = $vanid;
    $req['mcid']  = $mcid;
    $comment      = $this->reportsObj->getComment($req);
    if (empty($comment['reportIndex'])) { return; }
    $this->votersObj->updateTurfNote($turfIndex, $vanid, $comment['text'], $comment['reportIndex'], 0);
  }

  private function finalizeTurf(array $arg, array $sandbox): void {
    $county        = $arg['county'];
    $folderId      = $arg['folderId'];
    $folderName    = $arg['folderName'];
    $showLists     = $arg['showLists'];
    $mapRegionId   = $arg['mapRegionId'];
    $mapRegionName = $arg['mapRegionName'];
    $turfId        = $arg['turfId'];
    $turfName      = $arg['turfName'];
    $mcid          = $arg['mcid'];
    $walksheetInfo = $arg['walksheetInfo'];
    $turfIndex     = $sandbox['turfIndex'];

    $nl = $this->nlsObj->getNlById($mcid);

    $nlsStatus             = $this->nlsObj->getNlsStatus($mcid, $county);
    $nlsStatus['mcid']     = $mcid;
    $nlsStatus['signedUp'] = 'Y';
    $nlsStatus['turfCut']  = 'Y';
    $nlsStatus['asked']    = 'Yes';
    $this->nlsObj->setNlsStatus($nlsStatus);

    $isoDate = explode('T', date('c'));

    $assignment = [
      'turfId'        => $turfId,
      'mcid'          => $mcid,
      'vfid'          => $nl['vfid'],
      'turfName'      => $turfName,
      'savedList'     => $showLists,
      'mapRegionId'   => $mapRegionId,
      'mapRegionName' => $mapRegionName,
      'folderId'      => $folderId,
      'folderName'    => $folderName,
      'firstName'     => $nl['firstName'],
      'lastName'      => $nl['lastName'],
      'county'        => $county,
      'dateAssigned'  => $isoDate[0],
    ];

    do {
      $priorAssignment = $this->turfAssignmentsObj->getTurfAssignmentByTurfName($folderId, $turfName);
      if (empty($priorAssignment)) { break; }
      $this->turfAssignmentsObj->deleteTurfAssignment($priorAssignment['turfId']);
    } while (TRUE);

    $this->turfAssignmentsObj->setTurfAssignment($assignment);

    $legislativeDistricts = $sandbox['legislativeDistricts'];

    $turf = [
      'nlFirstName'   => $nl['firstName'],
      'nlLastName'    => $nl['lastName'],
      'mapRegionName' => $arg['mapRegionName'],
      'mapRegionId'   => $arg['mapRegionId'],
      'turfPdf'       => '',
      'turfHd'        => $legislativeDistricts['hd'],
      'turfPrecinct'  => $legislativeDistricts['precinct'],
      'turfCd'        => $legislativeDistricts['cd'],
      'voterCount'    => $arg['voterCount'],
      'doorCount'     => $arg['doorCount'],
    ];
    if (!empty($arg['minivan'])) {
      $turf['minivanNumber']     = $arg['minivanNumber'];
      $turf['minivanCreateDate'] = $arg['minivanCreateDate'];
    }
    $this->turfsObj->updateTurfFields($turfIndex, $turf);

    if (!empty($walksheetInfo['tempName'])) {
      $uniqueWalksheetName = 'MCID' . $mcid . '_' . $walksheetInfo['fileNameBase']
        . '_TI' . $turfIndex . '.' . $walksheetInfo['fileType'];
      $uri = $this->pathsObj->getPath('PDF', $county) . $uniqueWalksheetName;
      copy($walksheetInfo['tempName'], $uri);
      $this->turfsObj->updateTurf($turfIndex, 'turfPDF', $uniqueWalksheetName);
    }
  }

}
