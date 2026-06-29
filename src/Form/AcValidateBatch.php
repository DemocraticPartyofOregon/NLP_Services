<?php
const BATCH_LIMIT = 50;

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importReportsBatch
 *
 * Read the provided file and save the Dems.
 *
 * @param $arg
 * @param $context
 * @noinspection PhpUnused
 */
function awakeBatch($arg, &$context): void
{
  $messenger = Drupal::messenger();
  
  $counties = $arg['counties'];
  //nlp_debug_msg('$arg',$arg);
  $nlpVoterAC = $arg['nlpVoterAC'];
  $stateCommitteeKey = $arg['stateCommitteeKey'];
  
  $turfsObj = Drupal::getContainer()->get('nlpservices.turfs');
  $turfAssignmentsObj = Drupal::getContainer()->get('nlpservices.turf_assignments');
  $saveListsObj = Drupal::getContainer()->get('nlpservices.saved_lists');
  
  //nlp_debug_msg('$context',$context);
  if (empty($context['sandbox']['county'])) {
    $context['sandbox']['countyIndex'] = 0;
    $context['sandbox']['county'] = $counties[0];
    $context['sandbox']['countyCount'] = count($counties);
    $context['sandbox']['turfs'] = [];
    $context['sandbox']['turfIndex'] = 0;
    $context['sandbox']['turfSumCount'] = 0;
    
    $context['finished'] = 0;
    //nlp_debug_msg('start',$context['sandbox']);
    
  } else {
    //nlp_debug_msg('next',$context['sandbox']);
    $turfIndex = $context['sandbox']['turfIndex'];
    if($turfIndex >= $context['sandbox']['turfCount']-1) {
      $countyIndex = $context['sandbox']['countyIndex']+1;
      if($countyIndex >= $context['sandbox']['countyCount']) {
        $context['finished'] = 1;
        //nlp_debug_msg('finished',$context['finished']);
        return;
      }
      $context['sandbox']['countyIndex'] = $countyIndex;
      $context['sandbox']['county'] = $counties[$countyIndex];
      $context['finished'] = ($countyIndex-1)/$context['sandbox']['countyCount'];
      $context['sandbox']['turfs'] = [];
      $context['sandbox']['turfIndex'] = 0;
      //nlp_debug_msg('finished',$context['finished']);
    }
    
    
  }
  $county = $context['sandbox']['county'];
  //nlp_debug_msg('$county',$county);
  $error = FALSE;
  $nlpTurfVoters = $vbTurfVoters = [];
  $acHdr = 0;
  if(empty($context['sandbox']['turfs'])) {
    $turfs = $turfsObj->getCountyTurfs($county);
    //nlp_debug_msg('$turfs',$turfs);
    
    $turfs = array_values($turfs);
    //nlp_debug_msg('$turfs',$turfs);
    $context['sandbox']['turfIndex'] = 0;
    $context['sandbox']['turfs'] = $turfs;
    $context['sandbox']['turfCount'] = count($turfs);
    $turf = $turfs[0];
  } else {
    $turfIndex = $context['sandbox']['turfIndex']+1;
    $context['sandbox']['turfSumCount']++;
    $context['sandbox']['turfIndex'] = $turfIndex;
    $turf = $context['sandbox']['turfs'][$turfIndex];
  }
  //nlp_debug_msg('$turf',$turf);
  
  $folderId = $turf['folderId'];
  $listOptions = $saveListsObj->listOptions($stateCommitteeKey, $folderId);
  //nlp_debug_msg('$listOptions',$listOptions);
  
  $currentTurfId = nameSearch($turf['turfName'],$listOptions['rows']);
  if(empty($currentTurfId)) {
    //nlp_debug_msg('Missing turf name',$turf['turfName']);
    //nlp_debug_msg('listOptions',$listOptions['rows']);
    $messenger->addMessage('The original turf not found by name: '.$turf['turfName']);
  } else {
    //nlp_debug_msg('$currentTurfId',$currentTurfId);
    
    if ($currentTurfId != $turf['listId']) {  // Map region refreshed or changed.
      $previousTurfId = $turf['listId'];
      $messenger->addMessage('vv..Resync needed for '.$turf['listId'] . ' ' . $turf['turfName']);
      //nlp_debug_msg('Resync needed', $turf['listId'] . ' ' . $turf['turfName']);
      $turfsObj->updateTurf($turf['turfIndex'], 'listId', $currentTurfId);
      $turf['listId'] = $currentTurfId;
      $turfAssignmentsObj->fixTurfAssignment($previousTurfId, $currentTurfId);
    }
    
    $votersObj = Drupal::getContainer()->get('nlpservices.voters');
    $nlpTurfIndex = $turf['turfIndex'];
    $nlpTurfVoters = $votersObj->getVotersInTurf($nlpTurfIndex);
    //nlp_debug_msg('$nlpTurfVoters',$nlpTurfVoters);
    
    $exportJobsObj = Drupal::getContainer()->get('nlpservices.export_jobs');
    $database = 0;  // VoterFile.
    $listId = $turf['listId'];
    
    if (empty($listId)) {
      $error = TRUE;
      nlp_debug_msg('Missing list Id', $turf['turfName']);
    } else {
      $activistCodeObj = new stdClass();
      $activistCodeObj->activistCodeId = $nlpVoterAC;
      $activistCodes = [$activistCodeObj,];
      //nlp_debug_msg('$activistCodes',$activistCodes);
      $jobObj = new stdClass();
      $exportJob = $exportJobsObj->getExportJobs($stateCommitteeKey, $jobObj, $listId, $county, $database, $activistCodes);
      //nlp_debug_msg('$exportJob',$exportJob);
      $acHdr = "ActivistCode_" . $nlpVoterAC;
      
      $vbTurfVoters = $missingAC = [];
      if (!empty($exportJob['downloadUrl'])) {
        $fh = fopen($exportJob['downloadUrl'], "r");
        $hdr = fgetcsv($fh, escape: '');
        $exportList = [];
        do {
          $listRecord = fgetcsv($fh, escape: '');
          if (empty($listRecord)) {
            break;
          }
          $result = [];
          foreach ($hdr as $i => $v) {
            $result[$v] = $listRecord[$i];
          }
          $vbTurfVoters[$result['VanID']] = $result['VanID'];
          $exportList[$result['VanID']] = $result;
          /*
          if($result[$acHdr] != $nlpVoterAC) {
            $missingAC[] = $result['VanID'];
          }
          */
        } while (TRUE);
        fclose($fh);
        
      } else {
        $turfName = $turf['turfName'];
        $messenger->addMessage("Error in $county : $turfName - " . $exportJob['msg']);
        $error = TRUE;
      }
    }
  }
  
  if(!$error) {
    
    $inBoth = $notInNlp = $notInVb = [];
    foreach ($nlpTurfVoters as $nlpVanid) {
      if(!empty($vbTurfVoters[$nlpVanid])) {
        $inBoth[$nlpVanid] = $nlpVanid;
      } else {
        $notInVb[$nlpVanid] = $nlpVanid;
      }
    }
    foreach ($vbTurfVoters as $vbVanid) {
      if(empty($nlpTurfVoters[$vbVanid])) {
        $notInNlp[$vbVanid] = $vbVanid;
      }
    }
    //nlp_debug_msg('$inBoth',$inBoth);
    //nlp_debug_msg('$notInNlp',$notInNlp);
    //nlp_debug_msg('$notInVb',$notInVb);
    $notTheSame = $vbList = $nlpList = '';
    if(!empty($notInVb) OR !empty($notInNlp)) {
      $notTheSame = " The VoteBuilder turf does not match the NLP turf.";
      if(!empty($notInVb)) {
        $vbList = "..  VANIDs not in VoteBuilder turf: [";
        foreach ($notInVb as $vanid) {
          $vbList .= " $vanid,";
        }
        $vbList .= "]";
      }
      if(!empty($notInNlp)) {
        $nlpList = "..  VANIDs not in NLP turf: [";
        foreach ($notInNlp as $vanid) {
          $nlpList .= " $vanid,";
        }
        $nlpList .= "]";
      }
      
      
    }
    
    foreach ($inBoth as $vanid) {
      if(empty($exportList[$vanid][$acHdr])) {
        $missingAC[] = $vanid;
      }
    }
    
    
    $turfName = $turf['turfName'];
    if(empty($missingAC)) {
      $messenger->addMessage("No missing AC in $county : $turfName. ".$notTheSame);
      if(!empty($vbList)) {$messenger->addMessage($vbList);}
      if(!empty($nlpList)) {$messenger->addMessage($nlpList);}
      
    } else {
      $messenger->addMessage(">> missing AC in $county : $turfName ");
      
      nlp_debug_msg('$missingAC, ',$missingAC);
      nlp_debug_msg('$turf',$turf);
      //nlp_debug_msg('$inBoth',$inBoth);
      //nlp_debug_msg('$exportList',$exportList);
      
      if(!empty($arg['fix'])) {
        $surveyQuestionObj = Drupal::getContainer()->get('nlpservices.survey_question');
        
        foreach ($missingAC as $vanid) {
          // Set common fields for setting the AC.
          $surveyResponse['type'] = 'Activist';
          $surveyResponse['contactType'] = $surveyQuestionObj::CONTACT_TYPE_WALK;
          $surveyResponse['dateCanvassed'] = NULL;
          $surveyResponse['action'] = 1;
          $surveyResponse['rid'] = $nlpVoterAC;
          $surveyResponse['vanid'] = $vanid;
          $surveyQuestionObj->setApiSurveyResponse($surveyResponse);
        }
      }
      
    }
  }
  
  
  $context['finished'] = ($context['sandbox']['turfSumCount'])/$arg['turfCount'];
  
}

/**
 * @param $turfName
 * @param $listOptions
 * @return int
 */
function nameSearch($turfName,$listOptions): int
{
  //nlp_debug_msg('$turfName',$turfName);
  foreach ($listOptions as $list) {
    //nlp_debug_msg('$list',$list);
    if($turfName === $list['turfName']) {
      return $list['turfId'];
    }
  }
  return 0;
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * awakeBatchFinished
 *
 * The batch operation is finished.  Report the results.
 *
 * @param $success
 * @param $results
 * @param $operations
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function acValidateBatchFinished($success, $results, $operations): void
{
  $messenger = Drupal::messenger();
  $messenger->addStatus(t('Batch finished.'));
  
  if ($success) {
    $messenger->addStatus('The reports file successfully updated.');
  } else {
    $messenger->addError('An error occurred.');
  }
  
}
