<?php /** @noinspection PhpUnused */

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpResyncTurf
{
  const int TURF_SYNC_BATCH_SIZE = 10;
  
  protected NlpVoters $votersObj;
  protected NlpTurfs $turfsObj;
  protected ApiExportJobs $exportJobsObj;
  protected ConfigFactoryInterface $config;
  protected ApiSavedLists $savedListsObj;
  protected NlpsyncTurfAddVoter $syncTurfAddVoterObj;
  protected ApiVoter $apiVoterObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected ApiFolders $apiFoldersObj;
  protected NlpCoordinators $coordinatorsObj;
  protected DatabaseFunctions $databaseFunctionsObj;
  protected NlpNls $nlsObj;
  protected NlpSendEmail $sendEmailObj;
  protected ApiActivistCodes $activistCodesObj;
  protected NlpTurfMapRegions $turfMapRegionsObj;
  protected NlpSessionData $sessionDataObj;
  
  public function __construct($votersObj,$turfsObj, $exportJobsObj, $savedListsObj,
                              $syncTurfAddVoterObj, $apiVoterObj, $nlpConfigurationObj,
                              $apiFoldersObj, $activistCodesObj, $coordinatorsObj,
                              $databaseFunctionsObj, $nlsObj, $sendEmailObj, $turfMapRegionsObj,
                              $sessionDataObj ) {
    $this->votersObj = $votersObj;
    $this->turfsObj = $turfsObj;
    $this->exportJobsObj = $exportJobsObj;
    $this->savedListsObj = $savedListsObj;
    $this->syncTurfAddVoterObj = $syncTurfAddVoterObj;
    $this->apiVoterObj = $apiVoterObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->apiFoldersObj = $apiFoldersObj;
    $this->activistCodesObj = $activistCodesObj;
    $this->coordinatorsObj = $coordinatorsObj;
    $this->databaseFunctionsObj = $databaseFunctionsObj;
    $this->nlsObj = $nlsObj;
    $this->sendEmailObj = $sendEmailObj;
    $this->turfMapRegionsObj = $turfMapRegionsObj;
    $this->sessionDataObj = $sessionDataObj;
  }
  
  public static function create(ContainerInterface $container): NlpResyncTurf
  {
    return new static(
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.export_jobs'),
      $container->get('nlpservices.saved_lists'),
      $container->get('nlpservices.sync_turf_add_voter'),
      $container->get('nlpservices.api_voter'),
      $container->get('nlpservices.nlpservices.configuration'),
      $container->get('nlpservices.api_folders'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.coordinators'),
      $container->get('nlpservices.database_functions'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.send_email'),
      $container->get('nlpservices.turf_map_regions'),
      $container->get('nlpservices.session_data'),
    );
  }
  
  /**
   * resyncTurf
   *
   * THis turf has been identified as experiencing some change that may have changed
   * the voters in the turf.   If the changes are suspicious, the turf is deleted and the
   * coordinator gets an email to decide what to do.
   *
   * If the changes are minor, the changes are made.   When the NL next looks at the turf,
   * they are notified that the turf has been changed.
   *
   * @param $county
   * @param $turfIndex
   * @param $fixAC
   * @return void
   */
  public function resyncTurf($county, $turfIndex, $fixAC): void
  {
    $resyncCheckResult = $this->turfResyncCheck($county,$turfIndex);
    if($resyncCheckResult['result'] == 'error') {
      $turf = $this->turfsObj->getTurf($turfIndex);
      if(empty($turf['turfName'])) {  // Debug code to help with identifying corrupted turfs.
        $msg = 'Unexpected empty turf - $turfIndex: ' . $turfIndex;
        Drupal::logger('nlpservices')->notice($msg);
      }
      $this->resyncTurfDeleteNotify($turfIndex);
      $turfName = (empty($turf['turfName'])) ? '[Missing name]' : $turf['turfName'];
      $msg = "Turf $turfName has been deleted. turfIndex: $turfIndex, county: .$county";
      Drupal::logger('nlpservices')->notice($msg);
      return;
    }
    
    $newTurfId = (empty($resyncCheckResult['newTurfId']))?0:$resyncCheckResult['newTurfId'];
    $previousTurfId = (empty($resyncCheckResult['previousTurfId']))?0:$resyncCheckResult['previousTurfId'];
    
    // If the turfId was changed by VoteBuilder, update the NLP turf with the new turfId.
    if(!empty($newTurfId) AND $newTurfId != $previousTurfId) {
      $this->turfsObj->updateTurf($turfIndex, 'listId', $resyncCheckResult['newTurfId']);
      $this->turfsObj->fixTurfAssignment($resyncCheckResult['previousTurfId'], $resyncCheckResult['newTurfId']);
      $this->turfsObj->updateTurfFields($turfIndex, ['listId' => $newTurfId,]);
    }
    
    $turf = $this->turfsObj->getTurf($turfIndex);
    $mcid = $turf['mcid'];
    
    switch ($resyncCheckResult['result']) {
      case 'noChange':
        $date = date('Y-m-d',time());
        $this->turfsObj->updateTurf($turfIndex, 'syncDate', $date);
        break;
        
      case 'error':
        $msg = 'Turf '.$turf['turfName']. " has been deleted. turfIndex: $turfIndex, county: .$county";
        Drupal::logger('nlpservices')->notice($msg);
        return;
        
        
      case 'changes':
        
        $deletedVanids = $resyncCheckResult['deletedVoters'];
        $addedVanids = $resyncCheckResult['addedVoters'];
        // There seem to be too many changes to the list of voters in the turf.  Something
        // may be wrong, so the turf is deleted, and the coordinator has to check it.
        if(count($addedVanids)>6 OR count($deletedVanids)>6) {
          $this->resyncTurfDeleteNotify($turfIndex);
          return;
        }
        
        // Add any new voter records to the turf.
        $newVoters = [];
        if(!empty($addedVanids)) {
          $newVoters = $this->apiVoterObj->getVoters($addedVanids);
          foreach ($newVoters as $newVoter) {
            $this->syncTurfAddVoterObj->syncAddVoter($county, $newVoter, $turfIndex, $mcid);
          }
        }
        
        // Delete any records for voters that are no longer in the turf.
        if(!empty($deletedVanids)) {
          foreach ($deletedVanids as $vanid) {
            $this->votersObj->deleteVoterInTurf($vanid);
            $this->votersObj->deleteAddressInTurf($vanid);
            $request = [
              'vanid' => $vanid,
              'codeName' => 'NLPVoterAC',
              'action' => 'Remove',
            ];
            $this->activistCodesObj->setApiActivistCode($request);
            
          }
        }
        
        // This information is the accumulated changes since the turf was synced and represents
        // the difference with the walksheet created by VoteBuilder.
        $changeHistory = (!empty($turf['changeHistory']))?json_decode($turf['changeHistory'],true):[];
        $maxFieldSize = $this->databaseFunctionsObj->getColumnSize('nlp_turf','changeHistory');
        $date = date('Y-m-d',time());
        $newChanges = $this->resyncChangeHistory($date, $addedVanids, $deletedVanids, $newVoters);
        if(empty($changeHistory[$date])) {
          $changeHistory[$date] = $newChanges;
        }
        // Check if there are too many accumulated changes, about 30.
        $changeHistory = json_encode($changeHistory);
        if(strlen($changeHistory) >= $maxFieldSize) {
          $this->resyncTurfDeleteNotify($turfIndex);
          return;
        }
        
        $fields = [
          'unreportedChanges' => true,
          'changeHistory' => $changeHistory,
        ];
        $this->turfsObj->updateTurfFields($turfIndex, $fields);
        
        break;
    }
    
    // If there are voters in the turf that do not have the NLP Voter AC,
    // then add it.
    $missingNlpVoterAc = $resyncCheckResult['missingNlpVoterAc'];
    if(!empty($missingNlpVoterAc)) {
      $turf = $this->turfsObj->getTurf($turfIndex);
      $turfName = $turf['turfName'];
      $mcid = $turf['mcid'];
      $nl = $this->nlsObj->getNlById($mcid);
      $firstName = $nl['firstName'];
      $lastName = $nl['lastName'];
      $missingAc = $this->votersObj->getVotersInList($missingNlpVoterAc);

      $title = "Voters with missing NLP Voters AC: $turfName for $firstName $lastName [$mcid] $county";
      $missingACDisplay = $this->displayVotersChanged($missingAc);
      $missingACDisplay = array_merge([$title], $missingACDisplay);
      $voterDisplay = implode("<br>", $missingACDisplay);
      Drupal::logger('nlpservices')->notice($voterDisplay);
      
      if($fixAC) {
        $this->fixMissingAC($turfIndex, $missingNlpVoterAc, $county);
      }
    }
    
  }
  
  /**
   * turfResyncCheck
   *
   * Check if the turf needs to be resynced.
   *
   * Return the list of voters added or deleted, a list of voters with the NLP Voter AC missing
   * and the new turf ID if it changed.   All of these indicate something has to be updated.
   *
   * @param $county
   * @param $turfIndex
   * @return array
   */
  function turfResyncCheck($county,$turfIndex): array
  {
    $listId = $previousTurfId = $this->turfsObj->getListId($turfIndex);
    if(empty($listId)) {
      return ['result' => 'error', 'message' => 'No turfId found'];
    }
    
    $votersInTurf = $this->votersObj->fetchVotersByTurf($turfIndex);
    $turf = $this->turfsObj->getTurf($turfIndex);
    
    if(empty($votersInTurf)) {
      return ['result' => 'error', 'message' => 'There are no voters in this turf in '.$turf['turfName']];
    }
    
    $vanidsInNlpTurf = array_keys($votersInTurf);
    $newTurfId = 0;
    // Get the list of vanids in the turf in VoteBuilder
    $savedList = $this->savedListsObj->getSavedLists($county,0,0,$listId);
    
    if(empty($savedList)) {
      // The original list ID has changed, see if we can find the turf by name.
      $turf = $this->turfsObj->getTurf($turfIndex);
      // Search all the turfs in the folder for the turf name.
      $savedLists = $this->savedListsObj->getSavedLists($county, 0, $turf['folderId']);
      $needle = $turf['turfName'];
      foreach ($savedLists as $savedList) {
        if (strcmp($savedList['name'], $needle) === 0) {
          $newTurfId = $savedList['savedListId'];
          break;
        }
      }
      if(empty($newTurfId)) {
        return ['result' => 'error', 'message' => 'Unable to find new turfId for '.$turf['turfName']];
      }
      $listId = $newTurfId;
    }
    
    $exportJob = $this->exportJobsObj->getExportJobs($county,0,$listId,'NLPVoterAC');
    if(empty($exportJob)) {
      // This should not happen and needs to be investigated.
      $msg = 'No export job found for listId: '.$listId.' in county: '.$county;
      if (!str_contains(Drupal::routeMatch()->getRouteName(), 'system.cron')) {
        nlp_debug_msg('turfResyncCheckError',$msg);
      }
      Drupal::logger('nlpservices')->notice($msg);
      return ['result' => 'error', 'message' => 'Export job failed'];
    }
    
    $fh = fopen($exportJob['downloadUrl'], "r");
    $hdr = fgetcsv($fh);
    // The third column in the header is the name of the NLP Voter AC.
    $nlpVoterAcName = $hdr[2];
    
    // Convert the result of the export job into an associative array.
    $votersInVbTurf = [];
    do {
      $listRecord = fgetcsv($fh);
      if (empty($listRecord)) {break;}
      $associativeRecord = array_combine($hdr, $listRecord);
      $votersInVbTurf[$associativeRecord['VanID']] =$associativeRecord;
    } while (TRUE);
    
    // Create the list of voters in the NLP turf that are not in the VoteBuilder turf.
    $deletedVoterIds = [];
    foreach ($vanidsInNlpTurf as $vanid) {
      if(!isset($votersInVbTurf[$vanid])) {
        $deletedVoterIds[] = $vanid;  // Voter is no longer in the list from VoteBuilder.
      }
    }
    
    // Create the list of voters in the VoteBuilder turf that are not in the NLP turf.
    $newVoterIds = $missingNlpVoterAc = [];
    foreach ($votersInVbTurf as $voterInfo) {
      $voterId = $voterInfo['VanID'];
      if(!in_array($voterId,$vanidsInNlpTurf)) {
        $newVoterIds[] = $voterId;
      } elseif(empty($voterInfo[$nlpVoterAcName])) {
        $missingNlpVoterAc[$voterId] = $voterId;
      }
    }
    
    return [
      'result' => (empty($deletedVoterIds) AND empty($newVoterIds))? 'noChange':'changes',
      'deletedVoters' => $deletedVoterIds,
      'addedVoters' => $newVoterIds,
      'missingNlpVoterAc' => $missingNlpVoterAc,
      'newTurfId' => $newTurfId,
      'previousTurfId' => $previousTurfId,
    ];
    
  }
  
  /**
   * displayVotersChanged
   *
   * Create a list of voters suitable for display.
   *
   * @param $voters
   * @return array
   */
  function displayVotersChanged($voters): array
  {
    $voterDisplay = [];
    foreach ($voters as $voter) {
      $vanid = $voter['vanid'];
      $firstName = $voter['firstName'];
      $lastName = $voter['lastName'];
      $age = $voter['age'];
      $party = $voter['party'];
      $sex = $voter['sex'];
      
      $voterLine  = ".. [$vanid] $firstName $lastName" ;
      $voterLine .= " Age $age Party $party Gender $sex";
      
      $voterAddress = '';
      if(!empty($voter['address'])) {
        $voterAddress  = [
          ' : ',
          $voter['address']['streetNo'],
          $voter['address']['streetPrefix'],
          $voter['address']['streetName'],
          $voter['address']['streetType'],
          $voter['address']['streetSuffix'],
          $voter['address']['aptType'],
          $voter['address']['aptNo'],
          ', ',
          $voter['address']['city'],
        ];
        $voterAddress = implode(' ',$voterAddress);
      }
      $voterDisplay[] = $voterLine.' '.$voterAddress;
    }
    return $voterDisplay;
  }
  
  /**
   * processTurfResync
   *
   * Process a queue element with a list of turfs to check for resync.
   *
   * @param $turfData - An array with keys 'county' and 'turfIndexes'.
   * @return void
   * @noinspection PhpUnused
   */
  public function processTurfResync($turfData): void
  {
    $queueStart = (!empty($turfData['queueStart'])) ? $turfData['queueStart'] : '';
    switch ($queueStart) {
      case 'start':
        $queueStart = time();
        $this->sessionDataObj->set('queueStart', $queueStart);
        break;
      case 'end':
        $queueStart = $this->sessionDataObj->get('queueStart');
        $queueTime = time() - $queueStart;
        $hours = floor($queueTime / 3600);
        $minutes = floor(($queueTime % 3600) / 60);
        $secs = $queueTime % 60;
        $displayTime = "$hours:$minutes:$secs";
        Drupal::messenger()->addStatus('Turf resync queue time: ' . $displayTime);
        Drupal::logger('nlpservices')->notice('Turf resync queue time: ' . $displayTime);
        $this->sessionDataObj->set('queueInProgress', FALSE);
        $this->sessionDataObj->set('queueStart', 0);
        return;
    }
    
    $county = $turfData['county'];
    foreach ($turfData['turfIndexes'] as $turfIndex) {
      $this->resyncTurf($county, $turfIndex, TRUE);
    }
  }
  
  /**
   * resyncChangeHistory
   *
   * Create the change history for the turf.
   *
   * @param $date
   * @param $addedVanids
   * @param $deletedVanids
   * @param $newVoters
   * @return array
   */
  private function resyncChangeHistory($date,$addedVanids,$deletedVanids, $newVoters): array
  {
    $addedVoters = $deletedVoters = $history = [];
    foreach ($deletedVanids as $vanid) {
      $voter = $this->votersObj->getVoterById($vanid);
      $firstName = $voter['firstName'];
      $lastName = $voter['lastName'];
      $deletedVoters[$vanid] = $firstName.' '.$lastName;
    }
    foreach ($addedVanids as $vanid) {
      $voter = $newVoters[$vanid];
      $firstName = $voter['firstName'];
      $lastName = $voter['lastName'];
      $addedVoters[$vanid] = $firstName.' '.$lastName;
    }
    $history['date'] = $date;
    $history['deleted'] = $deletedVoters;
    $history['added'] = $addedVoters;
    return $history;
  }
  
  /**
   * resyncTurfDeleteNotify
   *
   * A turf is corrupted enough to need attention.  The turf is deleted, and the coordinator
   * is notified of the deletion.
   *
   * @param $turfIndex
   * @return void
   */
  public function resyncTurfDeleteNotify($turfIndex): void
  {
    $turf = $this->turfsObj->getTurf($turfIndex);
    if(empty($turf)) {return;}
    
    // First, delete the turf.
    $turfInfo = [
      'county' => $turf['county'],
      'mcid' => $turf['mcid'],
      'turfIndex' => $turfIndex,
    ];
    $this->turfsObj->deleteTurf($turfInfo);
   
    // Now, notify the coordinator of the turf deletion.
    $nl = $this->nlsObj->getNlById($turf['mcid']);
    if(empty($nl)) {return;}
    $coordinator = $this->coordinatorsObj->getAssignedCoordinator($nl);
    if(empty($coordinator)) {return;}
    
    $firstName = $coordinator['firstName'];
    $to = $coordinator['email'];
    $subject = "Notification of turf deletion";
    $message = "<p>$firstName,</p>";
    $message .= '<p>The turf named "'.$turf['turfName'].'" that was assigned to '.$turf['nlFirstName']." ".
      $turf['nlLastName']." has been deleted.</p>";
    $message .= "<p>One or more problems were found with this turf, and the remedy is to sync it again.
If the turf is still valid and the NL is still active, then please sync the turf with NLP Services again.</p>";
    $this->sendEmailObj->sendEmail('turf_deletion_notification',$to, $subject, $message);
    $msg1 = "Turf deletion notification sent to: ".$firstName.' '.$nl['lastName'].' ['.$nl['email'].']';
    $msg2 = $turf['turfName'].' TurfIndex: '.$turfIndex;
    
    Drupal::logger('nlpservices')->notice($msg1.' '.$msg2);
    
  }
  
  /**
   * fixMissingAC
   *
   * Add the missing NLP Voter AC to the voters in the turf and record the issue in
   * the log file for further analysis.   Something is definitely wrong.
   *
   * @param $turfIndex
   * @param $vanids
   * @param $county
   * @return void
   */
  public function fixMissingAC($turfIndex,$vanids,$county): void
  {
    $turf = $this->turfsObj->getTurf($turfIndex);
    $turfName = $turf['turfName'];
    $msg = ">> missing AC in $county : $turfName ";
    $idList = implode(',',$vanids);
    Drupal::logger('nlpservices')->notice($msg. ' VanIDs: '.$idList);
    $request = [
      'codeName' => 'NLPVoterAC',
      'action' => 'Apply',
    ];
    foreach ($vanids as $vanid) {
      $request['vanid'] = $vanid;
      $this->activistCodesObj->setApiActivistCode($request);
    }
      
  }
  
  /**
   * turfsToResyncCheck
   *
   * Create a queue of turfs to check for resync.  The turfs are selected either because the
   * turf was synced before the last update of the VoterFile or the map region was refreshed.
   *
   * @param $county
   * @return array
   * @noinspection PhpUnused
   */
  public function turfsToResyncCheck($county) : array
  {
    $turfIndexes = $this->turfsObj->getCountyTurfIndexes($county);
    $turfIndexChunks = array_chunk($turfIndexes, $this::TURF_SYNC_BATCH_SIZE);
    $queueChunks = [];
    foreach ($turfIndexChunks as $chunk) {
      $queueChunk = ['county' => $county, 'turfIndexes' => $chunk];
      $queueChunks[] = $queueChunk;
    }
    return $queueChunks;
  }
}
