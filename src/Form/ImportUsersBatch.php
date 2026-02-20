<?php

const MAX_QUEUE_LIMIT = '100';

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * importUsersBatch
 *
 * Read the provided file and save the Dems.
 *
 * @param $arg
 * @param $context
 * @noinspection PhpUnused
 */
function importUsersBatch($arg,&$context): void
{
  
  $messenger = Drupal::messenger();
  $context['finished'] = TRUE;
  $uri = $arg['uri'];
  
  $drupalRoles = user_roles();
  foreach ($drupalRoles as $drupalRole) {
    $roleId = $drupalRole->get('id');
    $roleLabel = $drupalRole->get('label');
    $nlpRoles[$roleId] = $roleLabel;
  }

  $fh = fopen($uri, "r");
  if (!$fh) {
    $messenger->addError('Failed to open user accounts file.');
    $context['finished'] = TRUE;
    return;
  }
  
  $filesize = filesize($uri);
  $context['finished'] = 0;
  // Position the file at the start or where we left off for the previous batch.
  if(empty($context['sandbox']['seek'])) {
    // Read the header record.
    $context['sandbox']['recordCount'] = 0;
    $hdr = fgetcsv($fh);
    $fieldPos = array_flip($hdr);
    $context['sandbox']['fieldPos'] = $fieldPos;
  } else {
    // Seek to where we will restart.
    $seek = $context['sandbox']['seek'];
    fseek($fh, $seek);
    $fieldPos = $context['sandbox']['fieldPos'];
  }
  
  $importFields = ['userName','email','created','access','login',
    //'firstName','lastName','phone','county','hd','mcid','turfAccess'];
    'firstName','lastName','phone','county','hd','mcid'];
  
  $magicWordObj = Drupal::getContainer()->get('nlpservices.magic_word');
  $drupalUserObj = Drupal::getContainer()->get('nlpservices.drupal_user');
  
  $loopCount = 0;
  do {
    $user = fgetcsv($fh);
    if(empty($user)) {break;}
    $loopCount++;
    $roles = [];
    $rolesString = $user[$fieldPos['roles']];
    $userRoles = explode(';', $rolesString);
    foreach ($userRoles as $userRole) {
      if(empty($userRole)) {continue;}
      $userRoleParts = explode(':',$userRole);
      $userRoleId = $userRoleParts[1];
      if($userRoleId == 'authenticated' OR $userRoleId == 'anonymous') {continue;}
      if(!empty($nlpRoles[$userRoleId])) {
        $roles[$userRoleId] = $nlpRoles[$userRoleId];
      }
    }
    
    $existingUser = $drupalUserObj->getUserByName($user[$fieldPos['userName']]);
    if(!empty($existingUser)) {
      // Avoid changing any admin account.
      if(in_array('administrator',$existingUser['roles'])) {
        continue;
      }
      $mcid = $user[$fieldPos['mcid']];
      
      $editUpdate = [
        'uid' => $existingUser['uid'],
        //'roles' => $roles,
        //'sharedEmail' => $user[$fieldPos['sharedEmail']],
        'county' => $user[$fieldPos['county']],
        'hd' => $user[$fieldPos['hd']],
        'firstName' => $user[$fieldPos['firstName']],
        'lastName' => $user[$fieldPos['lastName']],
        'phone' => $user[$fieldPos['phone']],
        'mcid' => $mcid,
      ];
      if(!empty($roles)) {
        $editUpdate['roles'] = $roles;
      }
      
      $drupalUserObj->updateUser($editUpdate);
      
    } else {
      
      $password = $user[$fieldPos['password']];
      if(empty($password) or $password == 'unknown') {
        $password = $magicWordObj->createMagicWord();
      }
      
      if(empty($user[$fieldPos['login']])) {
        $user[$fieldPos['login']] = $user[$fieldPos['access']];
      }
      
      foreach ($importFields as $field) {
        if(!empty($user[$fieldPos[$field]])) {
          $account[$field] = $user[$fieldPos[$field]];
        }
      }
      $account['magicWord'] = $password;
      //$account['sharedEmail'] = $user[$fieldPos['sharedEmail']];
      $account['roles'] = $roles;
      $account['county'] = $user[$fieldPos['county']];
      $newUser = $drupalUserObj->addUser($account);
      if($newUser['status'] == 'complete') {
        if(!empty($newUser['mcid'])) {
          $magicWordObj->setMagicWord($newUser['mcid'],$password);
        }
      }
      
      if($newUser['status'] == 'error') {
        $messenger->addStatus( t("Account creation error: ".
          $newUser['firstName'].' '.$newUser['lastName']));
      }
      
      if($newUser['status'] == 'exists') {
        $editUpdate = array(
          'uid' => $newUser['uid'],
          'roles' => $roles,
        );
        $drupalUserObj->updateUser($editUpdate);
        
      }
    }
    
    if($loopCount == MAX_QUEUE_LIMIT) {break;}
    
  } while (TRUE);  // Keep looping to read records until the break at EOF.
  
  $seek = ftell($fh);
  $context['sandbox']['seek'] = $seek;
  $context['finished'] = $seek/$filesize;
  $context['sandbox']['recordCount'] += $loopCount;
  $context['sandbox']['fieldPos'] = $fieldPos;
  
  if($loopCount != MAX_QUEUE_LIMIT OR $context['finished'] == 1) {
    $context['finished'] = 1;
    $context['results']['recordCount'] = $context['sandbox']['recordCount'];
    $context['results']['uri'] = $uri;
  }
  
}

/** * * * * * * * * * * * * * * * * * * * * * * * * * * * * * *
 * nlp_import_minivan_finished
 *
 * The batch operation is finished.  Report the results.
 *
 * @param $success
 * @param $results
 * @param $operations
 * @noinspection PhpUnusedParameterInspection
 * @noinspection PhpUnused
 */
function importUsersBatchFinished($success, $results, $operations): void
{
  $messenger = Drupal::messenger();
  if ($success) {
    $recordCount = $results['recordCount'];
    $messenger->addStatus( t('The user accounts successfully restored.'));
    $messenger->addStatus( t('@count records processed.', array('@count' => $recordCount)));
  }
  else {
    $messenger->addError( t('An error occurred.'));
  }
}
