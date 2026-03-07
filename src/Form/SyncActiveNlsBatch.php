<?php

const MAX_QUEUE_LIMIT = '50';

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * syncActiveNlsBatch
 *
 * Read the provided file and save the Dems.
 *
 * @param array $arg
 * @param array $context
 * @return void
 * @noinspection PhpUnused
 */
function syncActiveNlsBatch(array $arg, array &$context): void
{
  $messenger = Drupal::messenger();
  // Get the fixes for missing legislative districts, if any.
  $uri = $arg['uri'];
  $county = $arg['county'];
  $legislativeFixesObj = Drupal::getContainer()->get('nlpservices.legislative_fixes');
  $legislativeFixes = $legislativeFixesObj->getLegFixes($county);
  // Open the temp file with the list of NLs to sync.
  $fh = fopen($uri, "r");
  if (empty($fh)) {
    $messenger->addWarning("Unable to open $uri");
    $context['finished'] = TRUE;
    return;
  }
  
  $filesize = filesize($uri);
  $context['finished'] = 0;
  // Position the file at the start or where we left off for the previous batch.
  if(empty($context['sandbox']['seek'])) {
    // For the first batch of voters, read the header record and save the position of
    // fields by column.
    $context['sandbox']['recordCount'] = 0;
    $hdr = fgetcsv($fh);
    $fieldPos = array_flip($hdr);
    
    $nlpConfigurationObj = Drupal::getContainer()->get('nlpservices.configuration');
    $notRegisteredAC = $nlpConfigurationObj->getActivistCode('NLPNotRegisteredAC');
    $dropoutAC = $nlpConfigurationObj->getActivistCode('NLPDropoutAC');
    $activistCodeTitle = 'ActivistCode_'.$notRegisteredAC;
    $fieldPos['notRegisteredAc'] = 0;
    if(!empty($fieldPos[$activistCodeTitle])) {
      $fieldPos['notRegisteredAc'] = $fieldPos[$activistCodeTitle];
      unset($fieldPos[$activistCodeTitle]);
    }
    
    $activistCodeTitle = 'ActivistCode_'.$dropoutAC;
    $fieldPos['dropoutAC'] = 0;
    if(!empty($fieldPos[$activistCodeTitle])) {
      $fieldPos['dropoutAC'] = $fieldPos[$activistCodeTitle];
      unset($fieldPos[$activistCodeTitle]);
    }

    $context['sandbox']['fieldPos'] = $fieldPos;
  } else {
    // Seek to where we will restart.
    $seek = $context['sandbox']['seek'];
    fseek($fh, $seek);
    $fieldPos = $context['sandbox']['fieldPos'];
  }
  $recordCount = $context['sandbox']['recordCount'];

  $apiNlsObj = Drupal::getContainer()->get('nlpservices.api_nls');
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  
  $queCnt = 0;
  do {
    // Get the record with the VANID.
    $nlRaw = fgetcsv($fh);
    $seek = ftell($fh);
    $queCnt++;
    
    if(empty($nlRaw)) {break;}

    $mcid = $nlRaw[$fieldPos['VanID']];
    $notRegistered = !empty($nlRaw[$fieldPos['notRegisteredAc']]);
    $dropout = !empty($nlRaw[$fieldPos['dropoutAC']]);
    
    $recordCount++;
    $nlRecord = $apiNlsObj->getApiNls($county,$mcid);
    // Get the contact info for a new NL.
    if(empty($nlRecord)) {continue;}
    
    // Reject the NL with the Dropout AC.
    if($dropout) {
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $message = "NL rejected. This volunteer has been marked as a Dropout.<br>
           .. MCID:$mcid $firstName $lastName";
      $messenger->addError($message);
      continue;
    }
    
    // The NL must have an email address.
    if(empty($nlRecord['email'])) {
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $message = "NL rejected. This volunteer does not have an email address and can't be an active NL.<br>
           .. MCID:$mcid $firstName $lastName";
      $messenger->addError($message);
      continue;
    }
    
    // Check if the legislative districts are missing and there is a substitution.
    $legislativeFix = (!empty($legislativeFixes[$mcid])) ? $legislativeFixes[$mcid] : [];
    if(!checkDistricts($nlRecord,$legislativeFix)) {continue;}
    
    // Record the address of the NL.
    $zip = (!empty($nlRecord['zip']))?', '.$nlRecord['zip']:'';
    $nlRecord['address'] = $nlRecord['address'].', '.$nlRecord['city'].$zip;
    unset($nlRecord['city']);
    unset($nlRecord['zip']);
    $nlRecord['county'] = $county;  // Use the county name without spaces.
    
    // Use the Nickname if provided.
    $firstName = (!empty($nlRecord['nickname']))?$nlRecord['nickname']:$nlRecord['firstName'];
    $lastName = $nlRecord['lastName'];
    if(empty($nlRecord['nickname'])) {
      $nlRecord['nickname'] = $nlRecord['firstName'];
    }
    
    // Recover the login credentials.
    $credentials = $magicWordObj->getCredentials($mcid);
    $seek = ftell($fh);

    // Check if the NL record is linked to the VoterFile, but only if the AC is not set.
    if(!$notRegistered) {
      $lastKnownVfId = empty($credentials['vfid'])?0:$credentials['vfid'];
      checkVoterId($nlRecord['vfid'],$lastKnownVfId,$nlRecord);
    }
    
    // Verify that the email for this NL is unique.
    $nlEmail = $nlRecord['email'];
    if(!empty($credentials)) {
      // We've seen this MCID before.
      if($nlEmail != $credentials['email']) {
        // The email for this MCID has changed.
        $otherCredentials = $magicWordObj->getCredentialsByEmail($nlEmail);
        $otherDrupal = $drupalUserObj->getUserByEmail($nlEmail);
        $credentialsOk = empty($otherCredentials) || $otherCredentials['mcid'] == $mcid;
        $drupalRecordOk = empty($otherDrupal) || $otherDrupal['mcid'] == $mcid;
        
        if(!$credentialsOk OR !$drupalRecordOk) {
          // Someone else uses the new email for this NL.
          if(!empty($otherDrupal)) {
            // New email already in use by another Drupal account.
            $otherMcid = $otherDrupal['mcid'];
            $otherUserName = $otherDrupal['userName'];
            $drupalMessage = "<br>.. In Drupal login, MCID:$otherMcid userName:$otherUserName";
          }
          if(!empty($otherCredentials)) {
            // The email is associated with another NL.
            $otherMcid = $otherCredentials['mcid'];
            $otherUserName = $otherCredentials['userName'];
            $nlMessage = "<br>.&nbsp; In active NLs, MCID:$otherMcid userName:$otherUserName";
          }
          $firstName = $nlRecord['firstName'];
          $message = "NL rejected. The volunteer's email is in use by another NL.<br>
                     .&nbsp; MCID: $mcid firstName:$firstName lastName:$lastName email:$nlEmail";
          $message .= (!empty($nlMessage))?$nlMessage:'';
          $message .= (!empty($drupalMessage))?$drupalMessage:'';
          $messenger->addError($message);
          continue;
        }
      }
      // Check if the Drupal login has been created for this NL, and either update or create the record.
      $userName = $credentials['userName'];
      $drupalUser = $drupalUserObj->getUserByName($userName);
      if(empty($drupalUser)) {
        // The Drupal account is not yet created.  Update any changed contact info.
        createDrupalAccount($nlRecord,$credentials);
      } else {
        // The Drupal account is already created.  Update any new contact info.
        updateDrupalAccount($nlRecord, $credentials, $drupalUser);
      }
      createNlsRecord($nlRecord);
      
    } else {
      // This record has a MCID that has not been seen before.
      $otherCredentials = $magicWordObj->getCredentialsByEmail($nlEmail);
      if(!empty($otherCredentials)) {
        // The email for this new NL is already in use by another NL.
        $otherMcid = $otherCredentials['mcid'];
        $otherUserName = $otherCredentials['userName'];
        $message = "NL rejected. The volunteer's email is in use by another NL.<br>
           .&nbsp; MCID:$mcid $firstName $lastName email:$nlEmail<br>
           .&nbsp; The existing NL. MCID:$otherMcid userName:$otherUserName";
        $messenger->addError($message);
        continue;
      }
      // Email is not yet associated with any NL.
      $firstName = $nlRecord['firstName'];
      $middleName = $nlRecord['middleName'];
      $nickname = $nlRecord['nickname'];
      $userName = $magicWordObj->defaultUserName($firstName,$middleName,$lastName,$nickname);
     
      $otherDrupalUser = $drupalUserObj->getUserByName($userName);
      $otherCredentials = $magicWordObj->getCredentialsByUsername($userName);
      if(!empty($otherDrupalUser) OR !empty($otherCredentials)) {
        
        // Assign a unique userName for this new NL.  Several different ones are tried before
        // the attempt is abandoned.
        $namesTried = [];
        do {
          $uniqueUsernameName = $magicWordObj->uniqueUserName($nlRecord,$namesTried);
          if(empty($uniqueUsernameName)) {break;}
          $otherDrupalUser = $drupalUserObj->getUserByName($uniqueUsernameName);
          if(empty($otherDrupalUser)) {break;}
          $namesTried[] = $uniqueUsernameName;
        } while (TRUE);

        if(empty($uniqueUsernameName)) {
          $message = "NL rejected. Unable to find a unique userName.<br>
           .. MCID:$mcid email:$nlEmail $firstName $lastName";
          $messenger->addError($message);
          continue;
        }
        $userName = $uniqueUsernameName;
      }
      createNlsRecord($nlRecord);
      $credentials = [
        'userName' => $userName,
        'mcid' => $nlRecord['mcid'],
        'email' => $nlRecord['email'],
        'county' => $nlRecord['county'],
        'hd' => $nlRecord['hd'],
        'nickname' => $nlRecord['nickname'],
        'roles' => [NLP_LEADER_ROLE_ID=>NLP_LEADER_ROLE_LABEL],
        'vfid' => empty($nlRecord['vfid'])?0:$nlRecord['vfid'],
      ];
      $credentials = createCredentials($credentials);
      createDrupalAccount($nlRecord,$credentials);
      $drupalUser = $drupalUserObj->getUserByMcid($credentials['mcid']);
      $created = $drupalUser['created'];
      $update = ['mcid' => $credentials['mcid'], 'created' => $created];
      $magicWordObj->updateCredentials($update);
    }
    
    if($queCnt == MAX_QUEUE_LIMIT) {break;}
  } while (TRUE);
  
  $context['sandbox']['seek'] = $seek;
  $context['finished'] = $seek/$filesize;
  $context['sandbox']['recordCount'] = $recordCount;
  $context['results']['recordCount'] = $recordCount;
  if($queCnt != MAX_QUEUE_LIMIT) {
    $context['finished'] = 1;
  }
  fclose($fh);
  
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * syncActiveNlsBatchFinished
 *
 * The batch operation is finished.  Report the results.
 *
 * @param $success
 * @param $results
 * @param $operations
 * @noinspection PhpUnused
 * @noinspection PhpUnusedParameterInspection
 */
function syncActiveNlsBatchFinished($success, $results, $operations): void
{
  $messenger = Drupal::messenger();
 
  if ($success) {
    $recordCount = $results['recordCount'];
    $messenger->addStatus( t('The list of active NLs is successfully updated.'));
    $messenger->addStatus( t('@count records processed.',array('@count' => $recordCount)));
    
  }
  else {
    $messenger->addError( t('An error occurred.'));
  }
}

/**
 * checkDistricts
 *
 * Check if the HD and Precinct are empty for this NL.  Typically, this is due to an
 * invalid address.   However, sometimes it is due to missing geocoding for the address.
 * In either case, it is possible to have substitute values until the actual problem is
 * resolved.
 *
 * @param array $nlRecord
 * @param array $legislativeFix
 * @return bool
 */
function checkDistricts(array &$nlRecord, array $legislativeFix): bool
{
  $messenger = Drupal::messenger();
  $nlHd = (!empty($nlRecord['hd']))?ltrim($nlRecord['hd'], "0"):0;
  $pct = (!empty($nlRecord['precinct']))?trim($nlRecord['precinct']):0;
  $firstName = (!empty($nlRecord['nickname']))?$nlRecord['nickname']:$nlRecord['firstName'];
  $lastName = $nlRecord['lastName'];
  $mcid = $nlRecord['mcid'];
  // Check if the HD or Pct is missing.
  if (empty($pct) OR empty($nlHd)) {
    // Check if we have a repair record.
    if (!empty ($legislativeFix))  {
      // Use the fixes for HD and Pct.
      $nlHd = $legislativeFix['hd'];
      $pct = trim($legislativeFix['precinct']);
      $messenger->addWarning( "MCID:$mcid $firstName $lastName, HD and Pct repaired.");
    } else {
      $messenger->addError( "NL rejected: MCID:$mcid $firstName $lastName, HD or Pct is missing.");
      return FALSE;
    }
  } else {
    $nlpConfigurationObj = Drupal::getContainer()->get('nlpservices.configuration');
    $state = $nlpConfigurationObj->getStateName();
    if($state == 'Oregon') {
      $pctParts = explode('-',$pct);
      if(isset($pctParts[1])) {
        $pct = $pctParts[1];  // Discard the county name.
      }
    }
  }
  $nlRecord['hd'] = $nlHd;
  $nlRecord['precinct'] = $pct;
  return TRUE;
}

/**
 * createDrupalAccount
 *
 * Create or recreate a user login for the NLP Services site.   If the login is
 * known from prior use, the userName and password are restored.
 *
 * @param array $nlRecord
 * @param array $credentials
 * @return array
 */
function createDrupalAccount(array $nlRecord, array $credentials=[]): array
{
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $userInfo = [
    'firstName' => $nlRecord['nickname'],
    'lastName' => $nlRecord['lastName'],
    'county' => $nlRecord['county'],
    'hd' => $nlRecord['hd'],
    'mcid' => $nlRecord['mcid'],
    'email' => $nlRecord['email'],
    'phone' => $nlRecord['phone'],
  ];
  if(!empty($nlRecord['userName'])) {
    $userInfo['userName'] = $nlRecord['userName'];
  }
  
  if(!empty($credentials)) {
    $userInfo['userName'] = $credentials['userName'];
    $userInfo['roles'] = $credentials['roles'];
    $userInfo['magicWord'] = $credentials['magicWord'];
    $userInfo['created'] = $credentials['created'];
    $userInfo['access'] = $credentials['access'];
  }
  $newAccount = $drupalUserObj->createDrupalAccount($userInfo);
  if(!empty($newAccount)) {
    $magicWordObj->setMagicWord($nlRecord['mcid'],$userInfo['magicWord']);
  }
  return $newAccount;
}

/**
 * updateDrupalAccount
 *
 * Update any fields in the user's existing login that have changed.
 *
 * @param array $nlRecord
 * @param array $credentials
 * @param array $drupalUser
 * @return bool
 */
function updateDrupalAccount(array $nlRecord, array $credentials, array $drupalUser): bool
{
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');
  
  $nlFieldsToUpdate = ['county','email','firstName','lastName','phone','hd',];
  $cFieldsToUpdate = ['userName','hd','email','roles'];
  
  $userUpdate = [];
  foreach ($nlFieldsToUpdate as $field) {
    if(!empty($nlRecord[$field]) AND $nlRecord[$field] != $drupalUser[$field]) {
      $userUpdate[$field] = $nlRecord[$field];
      $drupalUser[$field] = $nlRecord[$field];
    }
  }
  
  if(!empty($userUpdate['email'])) {
    $userUpdate['mail'] = $userUpdate['email'];
    unset($userUpdate['email']);
  }
  
  if(!empty($userUpdate)) {
    $userUpdate['uid'] = $drupalUser['uid'];
    $updatedUser = $drupalUserObj->updateUser($userUpdate);
    if($updatedUser['status'] == 'error') {
      return FALSE;
    }
  }
 
  $configUpdate = [];
  foreach ($cFieldsToUpdate as $field) {
    switch ($field) {
      case 'userName':
      case 'email':
      case 'hd':
        if($credentials[$field] != $drupalUser[$field]) {
          $configUpdate[$field] = $drupalUser[$field];
        }
        break;
      case 'roles':
        $currentRoles = $drupalUser[$field];
        $savedRoles = $credentials[$field];
        $nlpRoles = $drupalUserObj->getNlpRoles();
        $currentNlpRoles = [];
        foreach ($currentRoles as $rid) {
          if(!empty($nlpRoles[$rid])) {
            $currentNlpRoles[$rid] = $nlpRoles[$rid];
          }
        }
        if(!empty($currentNlpRoles) AND $currentNlpRoles != $savedRoles) {
          $configUpdate[$field] = $currentNlpRoles;
        }
        break;
    }
    
  }
  if(!empty($configUpdate)) {
    updateCredentials($configUpdate, $credentials);
  }
  return TRUE;
}

/**
 * createNlsRecord
 *
 * Create a new NL record in the NLS database.  If the record already exists,
 * it is updated.
 *
 * @param array $nlRecord
 * @return bool
 */
function createNlsRecord( array $nlRecord): bool
{
  unset($nlRecord['userName']);
  $nlsObj = Drupal::getContainer()->get('nlpservices.nls');
  $insertOk = $nlsObj->createNl($nlRecord);
  // INSERT this NL into the list for this group.
  if(!$insertOk) {return FALSE;}
  // Create a status record if on does not already exist.
  $nlStatus = $nlsObj->getNlsStatus($nlRecord['mcid'],$nlRecord['county']);
  if (!empty($nlStatus)) {
    $nlsObj->setNlsStatus($nlStatus);
  }
  return TRUE;
}

/**
 * updateCredentials
 *
 * Update the credentials for creating or recreating a user login.
 *
 * @param array $credentialUpdate
 * @param array $credentials
 * @return bool
 */
function updateCredentials(array $credentialUpdate, array $credentials): bool
{
  $updatable = ['email','userName','county','hd','created','access','roles'];
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  
  $update = FALSE;
  $editCredentials = ['mcid'=>$credentials['mcid']];
  
  foreach ($updatable as $field) {
    if(!empty($credentialUpdate[$field]) AND $credentialUpdate[$field] != $credentials[$field]) {
      $editCredentials[$field] = $credentialUpdate[$field];
      $update = TRUE;
    }
  }
  if($update) {
    $magicWordObj->updateCredentials($editCredentials);
  }
  return TRUE;
}

/**
 * createCredentials
 *
 * Save the credentials for a new NL login.
 *
 * @param array $credentials
 * @return array
 */
function createCredentials(array $credentials): array
{
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $mcid = $credentials['mcid'];
  $magicWord = $magicWordObj->createMagicWord();
  $magicWordObj->setMagicWord($mcid,$magicWord);
  $newCredentials = [
    'mcid' => $credentials['mcid'],
    'userName' => $credentials['userName'],
    'email' => $credentials['email'],
    'county' => $credentials['county'],
    'hd' => $credentials['hd'],
    'roles' => $credentials['roles'],
    'magicWord' => $magicWord,
    'vfid' => empty($credentials['vfid'])?0:$credentials['vfid'],
  ];
  $magicWordObj->updateCredentials($newCredentials);
  return $magicWordObj->getCredentials($mcid);
}

/**
 * checkVoterId
 *
 * NLP Services keeps track of NLs by the vanId in MyCampaign.   This id used to be called
 * the mcid, and NLP Service continues to use the older name.
 *
 * The vanId can be destroyed by the merge function in VoteBuilder.   This breaks the link between
 * VoteBuilder and NLP Services about the identity of an NL.   However, if the record for the NL
 * is linked to the state voter registration, the vfid field can help us identify the issue and suggest
 * a remedy.   Basically, the remedy is to tell NLP Services that there is a change in the vanId.
 *
 * @param int $vfid
 * @param int $savedVfid
 * @param array $nl
 * @return void
 */
function checkVoterId(int $vfid, int $savedVfid, array $nl): void
{
  // The record in MyCampaign is not linked to a register voter.  This may
  // indicate the record is a duplicate and should be fixed.
  if(empty($vfid)) {
    $messenger = Drupal::messenger();
    $mcid = $nl['mcid'];
    $firstName = $nl['firstName'];
    $lastName = $nl['lastName'];
    $messenger->addWarning( "MCID:$mcid $firstName $lastName,
    The VoterFile VANID is missing for this NL.  Check if the MyCampaign
    record is a duplicate.");
    return;
  }
  // Update the saved vfid if it was unknown.  This probably means the NLs
  // record in MyCampaign was linked to the state voter record.
  if(empty($savedVfid)) {
    $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
    $newCredentials = [
      'mcid' => $nl['mcid'],
      'vfid' => $nl['vfid'],
    ];
    $magicWordObj->updateCredentials($newCredentials);
    return;
  }
  
  // The state voter id does not now point to the same voter record as in
  // the past.  This suggests the MyCampaign record may be for the wrong person.
  if($vfid != $savedVfid) {
    $messenger = Drupal::messenger();
    $mcid = $nl['mcid'];
    $firstName = $nl['firstName'];
    $lastName = $nl['lastName'];
    $messenger->addWarning("MCID:$mcid $firstName $lastName,
    new VoterID: $vfid, Old voterId: $savedVfid,
    The VoterFile VANID does not match a previous usage.  The MyCampaign
    record may point to the wrong voter.");
  }
}
