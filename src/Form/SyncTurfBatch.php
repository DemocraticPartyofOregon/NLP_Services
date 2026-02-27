<?php /** @noinspection PhpUnused */

const MAX_QUEUE_LIMIT = '4';


/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * syncTurf
 *
 * @param $arg
 * @param $context
 * @return void
 */
  function syncTurf($arg,&$context): void
  {
    $county = $arg['county'];
    $folderId = $arg['folderId'];
    $folderName = $arg['folderName'];
    $turfId = $arg['turfId'];
    $turfName = $arg['turfName'];
    $mcid = $arg['mcid'];
    
    $exportJobsObj = Drupal::getContainer()->get('nlpservices.export_jobs');
    $savedListsObj = Drupal::getContainer()->get('nlpservices.saved_lists');
    $apiVoterObj = Drupal::getContainer()->get('nlpservices.api_voter');
    $nlsObj = Drupal::getContainer()->get('nlpservices.nls');
    $activistCodesObj = Drupal::getContainer()->get('nlpservices.activist_codes');
    $votersObj = Drupal::getContainer()->get('nlpservices.voters');
    $turfsObj = Drupal::getContainer()->get('nlpservices.turfs');
    $configurationObj = Drupal::getContainer()->get('nlpservices.configuration');
    $awardsObj = Drupal::getContainer()->get('nlpservices.awards');
    
    if(empty($context['sandbox']['currentBatch'])) {
      $database = 0;  // VoterFile.
      $exportJob = $exportJobsObj->getExportJobs($county,$database,$turfId);
      $context['sandbox']['votersInTurf'] = [];
      if(empty($exportJob['downloadUrl'])) {
        $result = $savedListsObj->recoverTurfId($county,$folderId,$turfName);
        if($result['result'] == 'error') {
          $context['finished'] = 1;
          $context['results']['message'] = [0=>$result['message']];
          $context['results']['result'] = 'error';
          return;
        }
        $exportJob = $exportJobsObj->getExportJobs($county,$database,$result['turfId']);
        if(empty($exportJob['downloadUrl'])) {
          $context['finished'] = 1;
          $context['results']['message'] = [0=>'The turf is not accessible by ID.',];
          $context['results']['result'] = 'error';
          return;
        }
      }
      $fh = fopen($exportJob['downloadUrl'] , "r");
      fgetcsv($fh);
      $turfVoters = [];
      $voterCount = 0;
      $voterBatch = 0;
      do {
        $listRecord = fgetcsv($fh);
        if(empty($listRecord)) {break; }
        $turfVoters[$voterBatch][] = $listRecord[1];
        $voterCount++;
        if($voterCount == MAX_QUEUE_LIMIT) {
          $voterCount = 0;
          $voterBatch++;
        }
      } while (TRUE);
      fclose($fh);
      $context['sandbox']['batchCount'] = count($turfVoters);
      $context['sandbox']['currentBatch'] = 0;
      $context['sandbox']['turfVoters'] = $turfVoters;
      $context['sandbox']['duplicates'] = [];
      $context['sandbox']['votersInTurf'] = [];
      
      $turf = [
        'county' => $county,
        'mcid' => $mcid,
        'turfName' => $turfName,
        'turfPdf' => NULL,
        'folderId' => $folderId,
        'folderName' => $folderName,
        'listId' => $turfId,
      ];
      $turfIndex = $turfsObj->createTurf($turf);
      $context['sandbox']['turfIndex'] = $turfIndex;
      $context['sandbox']['mcid'] = $mcid;
      $context['sandbox']['county'] = $county;
      
      $context['sandbox']['legislativeDistricts'] = [];
      
    }
    
    $currentBatch = $context['sandbox']['currentBatch'];
    $batchCount = $context['sandbox']['batchCount'];
    $turfVoters = $context['sandbox']['turfVoters'];
    $turfIndex = $context['sandbox']['turfIndex'];
    $votersInTurf = $context['sandbox']['votersInTurf'];
    $legislativeDistricts = $context['sandbox']['legislativeDistricts'];
    
    // Get the voters in the turf export file.
    $voters = $apiVoterObj->getVoters($turfVoters[$currentBatch]);
    $onePrecinct = validateTurfPrecinct($legislativeDistricts, $voters);
    $context['sandbox']['legislativeDistricts'] = $legislativeDistricts;
    
    if (!$onePrecinct) {
      $context['finished'] = 1;
      $context['results']['message'] = [0=>'The turf must be for one precinct. This error likely means
      the saved list is old and the map region should be refreshed.',];
      $context['results']['result'] = 'error';
      $context['results']['turfIndex'] = $context['sandbox']['turfIndex'];
      $context['results']['mcid'] = $context['sandbox']['mcid'];
      $context['results']['county'] = $context['sandbox']['county'];
      
      return;
    }
    
    // Check if any of the voters in this new list to be synced are already assigned to another turf.
    $duplicates = getDuplicates($voters);
    if(!empty($duplicates)) {
      $context['sandbox']['duplicates'] = $context['sandbox']['duplicates'] + $duplicates;
      foreach ($duplicates as $duplicate) {
        unset($voters[$duplicate['vanid']]);
      }
    }
    
    foreach ($voters as $voter) {
      // Set common fields for setting the AC.
      $vanid = $voter['vanid'];
      $request = [
        'vanid' => $vanid,
        'codeName' => 'NLPVoterAC',
        'action' => 'Apply',
      ];
      $activistCodesObj->setApiActivistCode($request);
      
      // Add voter to turf.
      $voter['address']['turfIndex'] = $turfIndex;
      $voter['address']['moved'] = NULL;
      
      $votersObj->createVoter($voter);
      
      $voterStatus = $votersObj->getVoterStatus($vanid);
      $cycle = $configurationObj->getCycle();
      $voterStatus['vanid'] = $vanid;
      $voterStatus['cycle'] = $cycle;
      $voterStatus['hostile'] = (!empty($voter['activistCodes']['hostile']))?1:0;
      $votersObj->setVoterStatus($vanid, $voterStatus);
      
      $votersInTurf[$vanid] = $voter;
    }

    $currentBatch++;
    $context['sandbox']['currentBatch'] = $currentBatch;
    if($currentBatch >= $batchCount) {

      foreach ($votersInTurf as $voter) {
        $turfVoter['mcid'] = $mcid;
        $turfVoter['county'] = $county;
        $turfVoter['turfIndex'] = $turfIndex;
        $turfVoter['vanid'] = $voter['vanid'];
        $votersObj->createTurfVoter($turfVoter);

        reloadVoterStatus($voter,$turfIndex,$mcid);
      }
      
      $awardsObj->nlParticipation($mcid,'Turf synced');
      
      finalizeTurf($arg,$context['sandbox']);
      
      $nl = $nlsObj->getNlById($mcid);
      
      $context['results']['mcid'] = $mcid;
      $context['results']['turfName'] = $turfName;
      $context['results']['county'] = $county;
      $context['results']['voterCount'] = $arg['voterCount'];
      $context['results']['turfCnt'] = count($votersInTurf);
      $context['results']['nickname'] = $nl['nickname'];
      $context['results']['result'] = 'success';
      $context['results']['turfIndex'] = $context['sandbox']['turfIndex'];
      $context['sandbox']['#finished'] = 1;
      return;
    }

    $context['finished'] = $currentBatch / $batchCount;
    $context['sandbox']['votersInTurf'] = $votersInTurf;
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * validateTurfPrecinct
   *
   * Verify that we have a turf with voters from just one precinct.
   *
   * @param array $voters - array of voters in the new turf.
   * @param array $legislativeDistricts array with precinct and HD number.  Precinct number will
   *          be an empty string if there is more than one precinct
   *          in the list of voters.  The count of voters is also returned.
   *
   * @return bool
   */
  function validateTurfPrecinct(array &$legislativeDistricts, array $voters): bool
  {
    $vanids = array_keys($voters);
    if(empty($legislativeDistricts)) {
      $firstVanid = reset($vanids);
      $precinct = $voters[$firstVanid]['address']['precinct'];
      $hd = $voters[$firstVanid]['address']['hd'];
      $cd = $voters[$firstVanid]['address']['cd'];
      $count = 0;
    } else {
      $precinct = $legislativeDistricts['precinct'];
      $hd = $legislativeDistricts['hd'];
      $cd = $legislativeDistricts['cd'];
      $count = $legislativeDistricts['count'];
    }
    
    $count = $count + count($voters);
    foreach ($voters as $voter) {
      if(($precinct != $voter['address']['precinct']) OR ($hd != $voter['address']['hd'])) {
        return FALSE;
      }
    }
    $legislativeDistricts = [
      'precinct' => $precinct,
      'hd' => $hd,
      'cd' => $cd,
      'count' => $count,
    ];
    return TRUE;
  }
  
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * getDuplicates
   *
   * Check if any voters in the new turf are already assigned to a turf.
   *
   * @param array $voters - list of voters proposed for the new turf.
   *
   * @return array - array of voter info for overlapped voters, if any.
   */
  function getDuplicates(array $voters): array
  {
    $votersObj = Drupal::getContainer()->get('nlpservices.voters');
    $vanids = array_keys($voters);
    // Get of voters already assigned to a turf.
    $dupVoterTurfs = $votersObj->duplicateVoters($vanids);
    // If none, exit as we don't have a problem.
    if(empty($dupVoterTurfs)) {return [];}
    // Now get the voter information for all the overlapped voters.
    $dupVanids = array_keys($dupVoterTurfs);
    $dupVoters = $votersObj->getVotersInList($dupVanids);
    foreach ($dupVanids as $vanid) {
      $dupVoters[$vanid]['mcid'] = $dupVoterTurfs[$vanid]['mcid'];
      $dupVoters[$vanid]['turfIndex'] = $dupVoterTurfs[$vanid]['turfIndex'];
      $dupVoters[$vanid]['county'] = $dupVoterTurfs[$vanid]['county'];
    }
    return $dupVoters;
  }

  /**
   * updateMovedStatus
   *
   *
   * @param int $vanid
   * @param array $voter
   * @return void
   */
  function updateMovedStatus(int $vanid, array $voter): void
  {
    $reportsObj = Drupal::getContainer()->get('nlpservices.reports');
    $votersObj = Drupal::getContainer()->get('nlpservices.voters');
    //  Get the moved report if it exists.
    $request['vanid'] = $vanid;
    $request['type'] = 'contact';
    $request['value'] = 'moved';
    $request['cycle'] = NULL;  // All cycles.
    $movedReport = $reportsObj->getReport($request);
    // If the voter was marked as moved, check if there is a new address.
    if(!empty($movedReport['text'])) {
      $existingAddressObj = json_decode($movedReport['text']);
      $existingAddress =  (array) $existingAddressObj;
      $moveStatus = 1;
      $address = $voter['address'];
      $match = $votersObj->addressCompare($address,$existingAddress);
      if($match) {
        // Voter is at the same address where marked as moved.
        $votersObj->setMovedStatus($voter['address']['turfIndex'] ,$vanid,$moveStatus);
      }
    }
    
    $votersObj = Drupal::getContainer()->get('nlpservices.voters');
    $voterStatus = $votersObj->getVoterStatus($vanid);
    if(!empty($voterStatus)) {
      $voterStatus['moved'] = $voter['address']['moved'];
      $votersObj->setVoterStatus($vanid, $voterStatus);
    }
  }

  /**
   * finalizeTurf
   *
   * Finalize the turf assignment and save the PDF.
   *
   * @param $arg
   * @param $sandbox
   * @return void
   */
  function finalizeTurf($arg,$sandbox): void
  {
    $turfsObj = Drupal::getContainer()->get('nlpservices.turfs');
    $nlsObj = Drupal::getContainer()->get('nlpservices.nls');
    $pathsObj = Drupal::getContainer()->get('nlpservices.paths');
    $fileSystem = Drupal::service('file_system');

    $county = $arg['county'];
    $folderId = $arg['folderId'];
    $folderName = $arg['folderName'];
    $showLists = $arg['showLists'];
    $mapRegionId = $arg['mapRegionId'];
    $mapRegionName = $arg['mapRegionName'];
    $turfId = $arg['turfId'];
    $turfName = $arg['turfName'];
    $mcid = $arg['mcid'];
    $walksheetInfo = $arg['walksheetInfo'];
    $turfIndex = $sandbox['turfIndex'];

    $nl = $nlsObj->getNlById($mcid);

    $nlsStatus = $nlsObj->getNlsStatus($mcid,$county);
    $nlsStatus['mcid'] = $mcid;
    $nlsStatus['signedUp'] = 'Y';
    $nlsStatus['turfCut'] = 'Y';
    $nlsStatus['asked'] = 'Yes';
    $nlsObj->setNlsStatus($nlsStatus);

    $isoDate = explode('T', date('c'));  // date/time in ISO format.

    $assignment = [
      'turfId' => $turfId,
      'mcid' => $mcid,
      'vfid' => $nl['vfid'],
      'turfName'=> $turfName,
      'savedList' => $showLists,
      'mapRegionId' => $mapRegionId,
      'mapRegionName' => $mapRegionName,
      'folderId'=> $folderId,
      'folderName'=> $folderName,
      'firstName' => $nl['firstName'],
      'lastName' => $nl['lastName'],
      'county' => $county,
      'dateAssigned' => $isoDate[0],
      ];

    // Get rid of any prior assignments of a turf of the same name.
    do {
      $priorAssignment = $turfsObj->getTurfAssignmentByTurfName( $folderId,  $turfName);
      if(empty($priorAssignment)) {break;}
      $priorTurfId = $priorAssignment['turfId'];
      $turfsObj->deleteTurfAssignment($priorTurfId);
    } while (true);


    $turfsObj->setTurfAssignment($assignment);

    $legislativeDistricts = $sandbox['legislativeDistricts'];

    $turf = [
      'nlFirstName' => $nl['firstName'],
      'nlLastName' => $nl['lastName'],
      'mapRegionName' => $arg['mapRegionName'],
      'mapRegionId' => $arg['mapRegionId'],
      'turfPdf' => '',
      'turfHd' => $legislativeDistricts['hd'],
      'turfPrecinct' => $legislativeDistricts['precinct'],
      'turfCd' => $legislativeDistricts['cd'],
      'voterCount' => $arg['voterCount'],
      'doorCount' => $arg['doorCount'],
    ];
    if(!empty($arg['minivan'])) {
      $turf['minivanNumber'] = $arg['minivanNumber'];
      $turf['minivanCreateDate'] = $arg['minivanCreateDate'];
    }
    $turfsObj->updateTurfFields($turfIndex, $turf);

    // Save the PDF where we can find it.
    if (!empty($walksheetInfo['tempName'])) {
      $uniqueWalksheetName = "MCID".$mcid."_".$walksheetInfo['fileNameBase']
        .'_TI'.$turfIndex.'.'.$walksheetInfo['fileType'];

      $uri = $pathsObj->getPath('PDF', $county) . $uniqueWalksheetName;
      copy($walksheetInfo['tempName'], $uri);
      $turfsObj->updateTurf($turfIndex, 'turfPDF', $uniqueWalksheetName);
    }

  }

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * reloadVoterStatus
 *
 * Recover the information known about this voter and rebuild the status
 * information for the data entry display pages.
 *
 * @param array $voter
 * @param int $turfIndex
 * @param int $mcid
 */
  function reloadVoterStatus(array $voter, int $turfIndex, int $mcid): void
  {
    $reportsObj = Drupal::getContainer()->get('nlpservices.reports');
    $votersObj = Drupal::getContainer()->get('nlpservices.voters');
    
    $vanid = $voter['vanid'];
    $surveyReports = $reportsObj->getNlpTypeReports($vanid,'Survey');
    $pledgedToVote = 0;
    foreach ($surveyReports as $surveyReport) {
      if($surveyReport['reportIndex'] > $pledgedToVote) {
        $pledgedToVote = $surveyReport['reportIndex'];
      }
    }
    
    $surveyReports = $reportsObj->getNlpTypeReports($vanid,'contact');
    $attemptedContact = 0;
    foreach ($surveyReports as $surveyReport) {
      if($surveyReport['reportIndex'] > $attemptedContact) {
        $attemptedContact = $surveyReport['reportIndex'];
      }
    }
    
    // Update the pledge to vote status for this voter.
    $turfVoter = [];
    $turfVoter['vanid'] = $vanid;
    $turfVoter['attemptedContact'] = $attemptedContact;
    $turfVoter['pledgedToVote'] = $pledgedToVote;
    $turfVoter['turfIndex'] =$turfIndex;
    $votersObj->updateTurfVoter($turfVoter);
    
    //  Get the moved report if it exists.
    $request['vanid'] = $vanid;
    $request['type'] = 'contact';
    $request['value'] = 'moved';
    $request['cycle'] = NULL;  // All cycles.
    $movedReport = $reportsObj->getReport($request);
    // If the voter was marked as moved, check if there is a new address.
    if(!empty($movedReport['text'])) {
      $existingAddressObj = json_decode($movedReport['text']);
      $existingAddress =  (array) $existingAddressObj;
      $moveStatus = 1;
      $address = $voter['address'];
      $match = $votersObj->addressCompare($address,$existingAddress);
      if($match) {
        // Voter is at the same address where marked as moved.
        $votersObj->setMovedStatus($turfIndex ,$vanid,$moveStatus);
      }
    }
    
    //  Get the comment for this election cycle.
    
    $req['vanid'] = $vanid;
    $req['mcid'] = $mcid;
    $comment = $reportsObj->getComment($req);
    if(empty($comment['reportIndex'])) {return;}
    $note = $comment['text'];
    $reportIndex = $comment['reportIndex'];
    $noteId = 0;
    $votersObj->updateTurfNote($turfIndex,$vanid,$note,$reportIndex,$noteId);
  }
  
  /** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
   * turfSyncBatchFinished
   *
   * The batch operation is finished.  Report the results.
   *
   * @param bool $success
   * @param array $results
   * @param array $operations
   * @noinspection PhpUnusedParameterInspection
   * @noinspection PhpUnused
   */
  function syncTurfFinished(bool $success, array $results, array $operations): void
  {
    $turfsObj = Drupal::getContainer()->get('nlpservices.turfs');
    $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');
    $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
    $messenger = Drupal::messenger();
    if($success) {
      if($results['result'] == 'error') {
        $messages = $results['message'];
        foreach ($messages as $message) {
          $messenger->addError($message);
        }
        if(!empty($results['turfIndex'])) {
          $turf['mcid'] = $results['mcid'];
          $turf['county'] = $results['county'];
          $turf['turfIndex'] = $results['turfIndex'];
          $turfsObj->deleteTurf($turf);
        }
        return;
      }
      $turfName = $results['turfName'];
      $messages[] = "$turfName has been successfully checked in.";
      
      $mcid = $results['mcid'];
      $county = $results['county'];
      $turfs = $turfsObj->turfExists($mcid,$county);
      $turfCount = $turfs['turfCnt'];
      $nickname = $results['nickname'];
      $messages[] = "$nickname has $turfCount turf(s) assigned.";
      
      foreach ($turfs['turfs'] as $turf) {
        $turfIndex = $turf['turfIndex'];
        $turfName = $turf['turfName'];
        $syncDate = $turf['syncDate'];
        $messages[] = "($turfIndex) $syncDate $turfName";
      }
      
      $drupalUser = $drupalUserObj->getUserByMcid($mcid);
      if(!empty($drupalUser)) {
        $userName = $drupalUser['userName'];
        $magicWord = $magicWordObj->getMagicWord($mcid);
        $messages[] = "User credentials:Username: $userName Password: $magicWord";
        
        $messageDisplay = implode('<br>',$messages);
        $messenger->addStatus($messageDisplay);
      }
      
    } else {
      $messenger->addError('An error occurred.');
    }
    
  }
