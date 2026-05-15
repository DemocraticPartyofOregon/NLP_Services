<?php

const NLP_NL_SYNC_BATCH_LIMIT = 50;

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
    if(empty($nlRecord['nickname'])) {
      $nlRecord['nickname'] = $nlRecord['firstName'];
    }
    $seek = ftell($fh);

    //nlp_debug_msg('$nlRecord:',$nlRecord);
    // Verify that the email for this NL is unique.
    $emailVerification  = verifyUniqueEmail($nlRecord);
    //nlp_debug_msg('$emailVerification:',$emailVerification);
    if(!$emailVerification['uniqueEmail']) {
      $messenger->addError($emailVerification['message']);
      continue;
    }
    $credentials = $emailVerification['credentials'];

    //nlp_debug_msg('$credentials:',$credentials);
    // Verify the Drupal account for this NL is correct and create or update the account as needed.
    $drupalAccountVerify = verifyDrupalAccount($nlRecord, $credentials);
    //nlp_debug_msg('$drupalAccountVerify:',$drupalAccountVerify);
    if(!$drupalAccountVerify['accountOk']) {
      $messenger->addError($drupalAccountVerify['message']);
      continue;
    }

    $drupalUser = $drupalAccountVerify['drupalUser']??[];
    $magicWord = $drupalAccountVerify['magicWord']??'';

    if(empty($drupalUser['userName'])) {
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $message = "NL rejected. Unable to create or verify a Drupal account for this NL.<br>
           .. MCID:$mcid $firstName $lastName";
      $messenger->addError($message);

      nlp_debug_msg('$drupalUser',$drupalUser);
      nlp_debug_msg('$magicWord',$magicWord);
      nlp_debug_msg('$nlRecord',$nlRecord);
      continue;
    }

    // Verify the saved login credentials.
    $credentials = verifyCredentials( $nlRecord, $drupalUser, $credentials, $magicWord);
    //nlp_debug_msg('$credentials:',$credentials);

    // Check if the NL record is linked to the VoterFile, but only if the AC is not set.
    if(!$notRegistered) {
      $lastKnownVfId = empty($credentials['vfid'])?0:$credentials['vfid'];
      checkVoterId($nlRecord['vfid'],$lastKnownVfId,$nlRecord);
    }


    createNlsRecord($nlRecord);


    
    if($queCnt == NLP_NL_SYNC_BATCH_LIMIT) {break;}
  } while (TRUE);
  
  $context['sandbox']['seek'] = $seek;
  $context['finished'] = $seek/$filesize;
  $context['sandbox']['recordCount'] = $recordCount;
  $context['results']['recordCount'] = $recordCount;
  if($queCnt != NLP_NL_SYNC_BATCH_LIMIT) {
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
  if (empty($pct) || empty($nlHd)) {
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
 * updateDrupalAccount
 *
 * Update any fields in the user's existing login that have changed.
 *
 * @param array $nlRecord
 * @param array $drupalUser
 * @return array
 */
function updateDrupalAccount(array $nlRecord, array $drupalUser): array
{
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');
  
  $nlFieldsToUpdate = ['county','firstName','lastName','phone','hd',];

  $userUpdate = [];
  foreach ($nlFieldsToUpdate as $field) {
    if(!empty($nlRecord[$field]) && $nlRecord[$field] != $drupalUser[$field]) {
      $userUpdate[$field] = $nlRecord[$field];
    }
  }
  //nlp_debug_msg('$nlRecord:',$nlRecord);
  //nlp_debug_msg('$drupalUser:',$drupalUser);
  if(!empty($nlRecord['email']) && !empty($drupalUser['email'])) {
    if($nlRecord['email'] != $drupalUser['email']) {
      $userUpdate['mail'] = $nlRecord['email'];
    }
  }
  //nlp_debug_msg('$userUpdate:',$userUpdate);
  if(!empty($userUpdate)) {
    $userUpdate['uid'] = $drupalUser['uid'];
    //nlp_debug_msg('$userUpdate:',$userUpdate);
    $updatedUser = $drupalUserObj->updateUser($userUpdate);
    //nlp_debug_msg('$updatedUser:',$updatedUser);
    if($updatedUser['status'] == 'error') {
      return ['accountOk' => FALSE, 'message' => $updatedUser['message']];
    }
  }

  return ['accountOk' => TRUE, 'drupalUser' => empty($updatedUser)?$drupalUser:$updatedUser];
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
  // The function get an NL status (it creates a status record if on does not already exist).
  $nlStatus = $nlsObj->getNlsStatus($nlRecord['mcid'],$nlRecord['county']);
  if (!empty($nlStatus)) {
    // Save this status just in case a new one was created by the get.
    $nlsObj->setNlsStatus($nlStatus);
  }
  return TRUE;
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

/**
 * verifyUniqueEmail
 *
 *
 * @param array $nlRecord
 * @return array
 */
function verifyUniqueEmail(array $nlRecord): array
{
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');

  $nlEmail = $nlRecord['email'];
  $mcid = $nlRecord['mcid'];
  $credentials = $magicWordObj->getCredentials($mcid);
  //nlp_debug_msg('$credentials:',$credentials);
  if(!empty($credentials)) {
    // We've seen this MCID before.
    if($nlEmail != $credentials['email']) {
      // The email for this MCID has changed.
      $otherCredentials = $magicWordObj->getCredentialsByEmail($nlEmail);
      //nlp_debug_msg('$otherCredentials:',$otherCredentials);
      $otherDrupal = $drupalUserObj->getUserByEmail($nlEmail);
      //nlp_debug_msg('$otherDrupal:',$otherDrupal);
      $credentialsOk = empty($otherCredentials) || $otherCredentials['mcid'] == $mcid;
      $drupalRecordOk = empty($otherDrupal) || $otherDrupal['mcid'] == $mcid;

      if(!$credentialsOk || !$drupalRecordOk) {
        // Someone else uses the new email for this NL.
        $drupalMessage = $nlMessage = '';
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
        $lastName = $nlRecord['lastName'];
        $message = "NL rejected. The volunteer's email is in use by another NL.<br>
                     .&nbsp; MCID: $mcid firstName:$firstName lastName:$lastName email:$nlEmail";
        $message .= (!empty($nlMessage))?$nlMessage:'';
        $message .= (!empty($drupalMessage))?$drupalMessage:'';
        return['uniqueEmail' => FALSE, 'message' => $message];
      }
    }

    return ['uniqueEmail' => TRUE, 'credentials' => $credentials];

  } else {
    // This record has a MCID that has not been seen before.
    $otherCredentials = $magicWordObj->getCredentialsByEmail($nlEmail);
    if(!empty($otherCredentials)) {
      // The email for this new NL is already in use by another NL.
      $otherMcid = $otherCredentials['mcid'];
      $otherUserName = $otherCredentials['userName'];
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $message = "NL rejected. The volunteer's email is in use by another NL.<br>
           .&nbsp; MCID:$mcid $firstName $lastName email:$nlEmail<br>
           .&nbsp; The existing NL. MCID:$otherMcid userName:$otherUserName";
      return['uniqueEmail' => FALSE, 'message' => $message];
    }
    $otherDrupal = $drupalUserObj->getUserByEmail($nlEmail);
    $drupalRecordOk = empty($otherDrupal) || $otherDrupal['mcid'] == $mcid;
    if(!$drupalRecordOk) {
      // The email for this new NL is already in use by another Drupal account.
      $otherMcid = $otherDrupal['mcid'];
      $otherUserName = $otherDrupal['userName'];
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $message = "NL rejected. The volunteer's email is in use by another NL.<br>
           .&nbsp; MCID:$mcid $firstName $lastName email:$nlEmail<br>
           .&nbsp; In Drupal login, MCID:$otherMcid userName:$otherUserName";
      return['uniqueEmail' => FALSE, 'message' => $message];
    }

  }
  return ['uniqueEmail' => TRUE, 'credentials' => []];
}

/**
 * verifyRoles
 *
 * Verify that the roles for this NL are consistent with the roles for the Drupal account.  If the roles are not consistent, a warning message is returned, but the
 * roles are not updated.
 *
 * @param array $nlRecord
 * @param array $drupalUser
 * @param array $credentials
 * @param string $magicWord
 * @return array
 */
function verifyCredentials(array $nlRecord, array $drupalUser, array $credentials, string $magicWord): array
{
  if(empty($credentials)) {
    $credentials = createNewCredentials( $nlRecord, $drupalUser, $magicWord);
  } else {
    $credentials = updateCredentials( $nlRecord, $drupalUser, $credentials, $magicWord);
  }
  //nlp_debug_msg('$credentials:',$credentials);
  /*
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $nlEmail = $nlRecord['email'];
  $existingCredentials = $magicWordObj->getCredentialsByEmail($nlEmail);

  if(empty($existingCredentials)) {
    $credentials = $magicWordObj->createNewCredentials( $nlRecord['mcid'], $drupalUser['userName'], $nlRecord['email']);
    $credentials['vfid'] = empty($nlRecord['vfid'])?0:$nlRecord['vfid'];
    $credentials['county'] = $drupalUser['county'];
    $credentials['hd'] = $drupalUser['hd'];
    $roles = roleConvert( $drupalUser['roles'] );
    $credentials['roles'] = $roles;
    $credentials['created'] = $drupalUser['created'];
    $credentials['access'] = $drupalUser['access'];

    if(!empty($magicWord)) {
      $credentials['magicWord'] = $magicWord;
    }
    $magicWordObj->createCredentials($credentials);

    // Use the magicWord created for a new Drupal account.
    if(!empty($magicWord)) {
      $magicWordObj->setMagicWord($nlRecord['mcid'],$magicWord);
    }

  } else {
    $message = verifyRoles($existingCredentials['roles'], $drupalUser['roles']);
    if(!empty($message)) {
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $message = "The roles for the $firstName $lastName don't match, please verify. $message";
      $messenger = Drupal::messenger();
      $messenger->addWarning($message);
    }
    $credentials = $existingCredentials;
  }
  */
  return $credentials;
}

function createNewCredentials(array $nlRecord, array $drupalUser, string $magicWord): array
{
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');

  $credentials = $magicWordObj->createNewCredentials( $nlRecord['mcid'], $drupalUser['userName'], $nlRecord['email']);
  $credentials['vfid'] = empty($nlRecord['vfid'])?0:$nlRecord['vfid'];
  $credentials['county'] = $drupalUser['county'];
  $credentials['hd'] = $drupalUser['hd'];
  $roles = roleConvert( $drupalUser['roles'] );
  $credentials['roles'] = $roles;
  $credentials['created'] = $drupalUser['created'];
  $credentials['access'] = $drupalUser['access'];

  if(!empty($magicWord)) {
    $credentials['magicWord'] = $magicWord;
  }
  //nlp_debug_msg('$credentials:',$credentials);
  $magicWordObj->createCredentials($credentials);

  // Use the magicWord created for a new Drupal account.
  if(!empty($magicWord)) {
    $magicWordObj->setMagicWord($nlRecord['mcid'],$magicWord);
  }
  return $credentials;
}


function updateCredentials(array $nlRecord, array $drupalUser, array $credentials, string $magicWord = ''): array
{
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $vfid = empty($nlRecord['vfid'])?0:$nlRecord['vfid'];
  if($vfid != $credentials['vfid']) {
    $credentials['vfid'] = $vfid;
  }
  $fieldsToUpdate = ['email', 'county', 'hd', 'created', 'access'];
  foreach($fieldsToUpdate as $field) {
    if($credentials[$field] != $drupalUser[$field]) {
      $credentials[$field] = $drupalUser[$field];
    }
  }
  $roles = roleConvert( $drupalUser['roles'] );
  $credentials['roles'] = $roles;

  $magicWordObj->updateCredentials($credentials);

  // If a new Drupal account was just created for a returning NL whose stored
  // password was empty, write the new password into the magicWord table now.
  if(!empty($magicWord) && empty($magicWordObj->getMagicWord($nlRecord['mcid']))) {
    $magicWordObj->setMagicWord($nlRecord['mcid'], $magicWord);
    $credentials['magicWord'] = $magicWord;
  }

  return $credentials;
}

/**
 * verifyDrupalAccount
 *
 * Verify that the Drupal account for this NL exists.  If it does not, create it.  If it does exist,
 * verify that the fields in the account are consistent with the NL record and update any fields
 * that are not consistent.
 *
 * @param array $nlRecord
 * @param array $credentials
 * @return array
 */
function verifyDrupalAccount(array $nlRecord, array $credentials): array
{
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');

  $nlEmail = $nlRecord['email'];
  $drupalEmailUser = $drupalUserObj->getUserByEmail($nlEmail);
  //nlp_debug_msg('$drupalEmailUser:',$drupalEmailUser);
  $drupalMcidUser = $drupalUserObj->getUserByMcid($nlRecord['mcid']);
  //nlp_debug_msg('$drupalMcidUser:',$drupalMcidUser);

  $drupalEmailUid = !empty($drupalEmailUser['uid']) ? $drupalEmailUser['uid'] : 0;
  $drupalMcidUid = !empty($drupalMcidUser['uid']) ? $drupalMcidUser['uid'] : 0;

  if ($drupalEmailUid == 0 && $drupalMcidUid == 0) {
    $type = 'newNl';  // typical for new NL or at the beginning of a new election.
  } else if ($drupalEmailUid == $drupalMcidUid) {
    $type = 'updateBoth';  // Typical update of active NLs.
  } elseif ($drupalMcidUid > 0 && $drupalEmailUid == 0) {
    $type = 'newEmail';  // A new email.
  } else {
    $type = 'error';
  }
  //nlp_debug_msg('$type:',$type);

  switch ($type) {
    case 'newNl':
      $createDrupalLogin = createDrupalLogin($nlRecord);
      $createDrupalLogin['type'] = $type;
      $createDrupalLogin['credentials'] = $credentials;
      return $createDrupalLogin;

    case 'updateBoth':
    case 'newEmail':
      $updateDrupalAccount = updateDrupalAccount($nlRecord, $drupalMcidUser);
      //if ( !$updateDrupalAccount['accountOk'] ) {
      //  return $updateDrupalAccount;
      //}
      return $updateDrupalAccount;

    default:
      $firstName = $nlRecord['firstName'];
      $lastName = $nlRecord['lastName'];
      $mcidMessage = (!empty($drupalMcidUser)) ? " In Drupal login by MCID, MCID:" . $drupalMcidUser['mcid'] .
        " userName:" . $drupalMcidUser['userName'] . " email:" . $drupalMcidUser['email'] . "<br>" : '';
      $emailMessage = (!empty($drupalEmailUser)) ? " In Drupal login by email, MCID:" . $drupalEmailUser['mcid'] .
        " userName:" . $drupalEmailUser['userName'] . " email:" . $drupalEmailUser['email'] . "<br>" : '';

      $mcid = $nlRecord['mcid'];
      $message = "NL rejected. Unable to determine the Drupal account for this NL
           &nbsp; MCID:$mcid $firstName $lastName email:$nlEmail<br>";
      $message .= $mcidMessage;
      $message .= $emailMessage;

      $nlpConfigurationObj = Drupal::getContainer()->get('nlpservices.configuration');
      $awardsObj = Drupal::getContainer()->get('nlpservices.awards');
      $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
      $apiNlsObj = Drupal::getContainer()->get('nlpservices.api_nls');

      $debug = $nlpConfigurationObj->getDebug();
      if($debug) {
        nlp_debug_msg('$nlRecord:',$nlRecord);
        nlp_debug_msg('$credentials:',$credentials);
        nlp_debug_msg('$drupalMcidUser:',$drupalMcidUser);
        nlp_debug_msg('$drupalEmailUser:',$drupalEmailUser);
        $awards = $awardsObj->getAward($nlRecord['mcid']);
        nlp_debug_msg('$awards:',$awards);
        if(!empty($drupalEmailUser)) {
          $altMcid = $drupalEmailUser['mcid'];
          nlp_debug_msg('$altMcid:',$altMcid);
          if(!empty($altMcid)) {
            $county = $drupalEmailUser['county'];
            $altNls = $apiNlsObj->getApiNls($county,$altMcid);
            nlp_debug_msg('$altNls:',$altNls);
            if(empty($altNls)) {
              $drupalUserUpdate = [
                'uid' => $drupalEmailUser['uid'],
                'mcid' => $nlRecord['mcid'],
              ];
              nlp_debug_msg('$drupalUserUpdate:',$drupalUserUpdate);
              $drupalUserObj->updateUser($drupalUserUpdate);
            }
          }
          if($altMcid != $nlRecord['mcid']) {
            $altAwards = $awardsObj->getAward($altMcid);
            nlp_debug_msg('$altAwards:',$altAwards);
            $altCredentials = $magicWordObj->getCredentials($altMcid);
            nlp_debug_msg('$altCredentials:',$altCredentials);
          }
        }


      }


      return ['accountOk' => FALSE, 'message' => $message];
  }
}

/**
 * createDrupalLogin
 *
 * Update the Drupal account for this NL.  If the account does not exist, it is created.
 * If the account exists, the fields in the account are updated to match the NL record.
 *
 * @param array $nlRecord
 * @return array
 */
function createDrupalLogin(array $nlRecord): array
{
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');

  $userInfo = [
    'mcid' => $nlRecord['mcid'],
    'email' => $nlRecord['email'],
    'firstName' => empty($nlRecord['nickname'])?$nlRecord['firstName'] : $nlRecord['nickname'] ,
    'lastName' => $nlRecord['lastName'],
    'phone' => $nlRecord['phone'],
    'county' => $nlRecord['county'],
    'hd' => $nlRecord['hd'],
  ];

  $uniqueUsernameArray = getUniqueUsername($nlRecord);
  $uniqueUsername = $uniqueUsernameArray['uniqueUsername'];
  if (empty($uniqueUsername)) {
    // Unable to find a unique userName for this NL.  Reject the NL.
    return ['accountOk' => FALSE, 'message' => $uniqueUsernameArray['message']];
  }
  $userInfo['userName'] = $uniqueUsername;
  $userInfo['magicWord'] = $magicWordObj->createMagicWord();
  // Roles and create date are created by the createDrupalAccount below.
  //nlp_debug_msg('$userInfo:',$userInfo);
  $newAccount = $drupalUserObj->createDrupalAccount($userInfo);
  if (empty($newAccount)) {
    nlp_debug_msg('NL rejected. Unable to create Drupal account.',$userInfo);
    return ['accountOk' => FALSE, 'message' => "NL rejected. System rejected the account."];
  }
  return ['accountOk' => TRUE, 'drupalUser' => $newAccount, 'magicWord' => $userInfo['magicWord']];
}



/**
 * getUniqueUsername
 *
 * Try a limited number of variants to find a unique userName for this NL.  If a unique
 * userName cannot be found, an empty username is returned and the NL is rejected.
 *
 * @param array $nlRecord
 * @return array
 */
function getUniqueUsername(array $nlRecord): array
{
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');
  // Assign a unique userName for this new NL.  Several different ones are tried before
  // the attempt is abandoned.
  $namesTried = [];
  do {
    $uniqueUsernameName = $magicWordObj->uniqueUserName($nlRecord, $namesTried);
    if (empty($uniqueUsernameName)) {
      break;
    }
    //nlp_debug_msg('getUniqueUsername:',$uniqueUsernameName);
    $otherDrupalUser = $drupalUserObj->getUserByName($uniqueUsernameName);
    //nlp_debug_msg('$otherDrupalUser:',$otherDrupalUser);
    if (empty($otherDrupalUser)) {
      break;
    }
  } while (TRUE);

  if (empty($uniqueUsernameName)) {
    $nlEmail = $nlRecord['email'];
    $mcid = $nlRecord['mcid'];
    $firstName = $nlRecord['firstName'];
    $lastName = $nlRecord['lastName'];
    $message = "NL rejected. Unable to find a unique userName.<br>
         .. MCID:$mcid email:$nlEmail $firstName $lastName";
    return ['uniqueUsername' => '', 'message' => $message];
  }
  return ['uniqueUsername' => $uniqueUsernameName,];
}

/**
 * roleConvert
 *
 * Convert the Drupal roles to the NLP roles.  The NLP roles are used to determine
 * the access level of the NL.
 *
 * @param array $roles
 * @return array
 */
function roleConvert(array $roles): array
{
  $nlpRoleIds = [
    NLP_ADMIN_ROLE_ID => NLP_ADMIN_ROLE_LABEL,
    NLP_COORDINATOR_ROLE_ID => NLP_COORDINATOR_ROLE_LABEL,
    NLP_LEADER_ROLE_ID => NLP_LEADER_ROLE_LABEL,
  ];
  $roleArray = [];
  foreach ($roles as $roleId) {
    if(isset($nlpRoleIds[$roleId])) {
      $roleArray[$roleId] = $nlpRoleIds[$roleId];
    }
  }
  return $roleArray;
}

/**
 * verifyRoles
 *
 * Compares the existing roles of an NL with the roles derived from the current Drupal roles.
 * If the roles are identical, no changes are reported. Otherwise, a message is returned
 * indicating the changes in roles, without updating them.
 *
 * @param array $existingRoles The existing roles associated with the NL.
 * @param array $drupalRoles The roles currently defined in the Drupal system.
 * @return string A message describing the role changes if there are discrepancies,
 *                or an empty string if the roles match.
 */
function verifyRoles(array $existingRoles, array $drupalRoles): string
{
  //nlp_debug_msg('$existingRoles:',$existingRoles);

  $newRoles = roleConvert($drupalRoles);
  //nlp_debug_msg('$newRoles:',$newRoles);

  if($existingRoles==$newRoles) {return '';}
  return "NL roles changed. The NL's roles have not been updated.<br>
           .&nbsp; Existing roles: ".implode(',',$existingRoles)."<br>
           .&nbsp; New roles: ".implode(',',$newRoles);
}