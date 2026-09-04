<?php

namespace Drupal\nlpservices\Batch;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;

class ImportUserAccountsBatch {

  const MAX_QUEUE_LIMIT = 100;

  /**
   * Read the provided file and save the Dems.
   */
  public static function process(array $arg, array &$context): void {
    $messenger = Drupal::messenger();
    $uri = $arg['uri'];

    $nlpRoles = $drupalRoles = [];
    try {
      $drupalRoles = Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple();
    } catch (InvalidPluginDefinitionException|PluginNotFoundException $e) {
      nlp_debug_msg('importUserAccountsBatch', $e->getMessage() );
    }
    foreach ($drupalRoles as $drupalRole) {
      $nlpRoles[$drupalRole->id()] = $drupalRole->label();
    }

    $fh = fopen($uri, "r");
    if (!$fh) {
      $messenger->addError(t('Failed to open user accounts file.'));
      $context['finished'] = TRUE;
      return;
    }

    $filesize = filesize($uri);
    $context['finished'] = 0;
    // Position the file at the start or where we left off for the previous batch.
    if(empty($context['sandbox']['seek'])) {
      // Read the header record.
      $context['sandbox']['recordCount'] = 0;
      $hdr = fgetcsv($fh, escape: '');
      $fieldPos = array_flip($hdr);
      $context['sandbox']['fieldPos'] = $fieldPos;
    } else {
      // Seek to where we will restart.
      $seek = $context['sandbox']['seek'];
      fseek($fh, $seek);
      $fieldPos = $context['sandbox']['fieldPos'];
    }

    $importFields = ['userName','email','created','access','login',
      'firstName','lastName','phone','county','hd','mcid'];

    $magicWordObj = Drupal::service('nlpservices.magic_word');
    $drupalUserObj = Drupal::service('nlpservices.drupal_user');

    $loopCount = 0;
    do {
      $user = fgetcsv($fh, escape: '');
      if (!$user) { break; }
      $loopCount++;
      $roles = [];
      $rolesString = $user[$fieldPos['roles']] ?? '';
      $userRoles = explode(';', $rolesString);
      foreach ($userRoles as $userRole) {
        if(empty($userRole)) {continue;}
        $userRoleParts = explode(':', $userRole);
        $userRoleId = $userRoleParts[1] ?? '';
        if ($userRoleId === 'authenticated' || $userRoleId === 'anonymous') {continue;}
        if(!empty($nlpRoles[$userRoleId])) {
          $roles[$userRoleId] = $nlpRoles[$userRoleId];
        }
      }

      $existingUser = $drupalUserObj->getUserByName($user[$fieldPos['userName']] ?? '');
      if(!empty($existingUser)) {
        // Avoid changing any admin account.
        if(in_array('administrator',$existingUser['roles'])) {
          continue;
        }
        $mcid = $user[$fieldPos['mcid']] ?? '';

        $editUpdate = [
          'uid'       => $existingUser['uid'],
          'county'    => $user[$fieldPos['county']]    ?? '',
          'hd'        => $user[$fieldPos['hd']]        ?? '',
          'firstName' => $user[$fieldPos['firstName']] ?? '',
          'lastName'  => $user[$fieldPos['lastName']]  ?? '',
          'phone'     => $user[$fieldPos['phone']]     ?? '',
          'mcid'      => $mcid,
        ];
        if(!empty($roles)) {
          $editUpdate['roles'] = $roles;
        }

        $drupalUserObj->updateUser($editUpdate);

      } else {

        $password = $user[$fieldPos['password']] ?? '';
        if(empty($password) || $password == 'unknown') {
          $password = $magicWordObj->createMagicWord();
        }

        if(empty($user[$fieldPos['login']] ?? '')) {
          $user[$fieldPos['login']] = $user[$fieldPos['access']] ?? '';
        }

        $account = [];
        foreach ($importFields as $field) {
          if(!empty($user[$fieldPos[$field]] ?? '')) {
            $account[$field] = $user[$fieldPos[$field]];
          }
        }
        $account['magicWord'] = $password;
        $account['roles']  = $roles;
        $account['county'] = $user[$fieldPos['county']] ?? '';
        $newUser = $drupalUserObj->addUser($account);
        if($newUser['status'] == 'complete') {
          if(!empty($newUser['mcid'])) {
            $magicWordObj->setMagicWord($newUser['mcid'],$password);
          }
        }

        if($newUser['status'] == 'error') {
          $messenger->addStatus(t('Account creation error: @first @last',
            ['@first' => $newUser['firstName'], '@last' => $newUser['lastName']]));
        }

        if($newUser['status'] == 'exists') {
          $editUpdate = [
            'uid'   => $newUser['uid'],
            'roles' => $roles,
          ];
          $drupalUserObj->updateUser($editUpdate);
        }
      }

      if($loopCount == self::MAX_QUEUE_LIMIT) {break;}

    } while (TRUE);  // Keep looping to read records until the break at EOF.

    $seek = ftell($fh);
    $context['sandbox']['seek'] = $seek;
    $context['finished'] = $seek/$filesize;
    $context['sandbox']['recordCount'] += $loopCount;
    $context['sandbox']['fieldPos'] = $fieldPos;

    if($loopCount != self::MAX_QUEUE_LIMIT || $context['finished'] == 1) {
      $context['finished'] = 1;
      $context['results']['recordCount'] = $context['sandbox']['recordCount'];
      $context['results']['uri'] = $uri;
    }
  }

  /**
   * The batch operation is finished.  Report the results.
   *
   * @noinspection PhpUnusedParameterInspection
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = Drupal::messenger();
    if ($success) {
      $recordCount = $results['recordCount'];
      $messenger->addStatus(t('The user accounts successfully restored.'));
      $messenger->addStatus(t('@count records processed.', ['@count' => $recordCount]));
    }
    else {
      $messenger->addError(t('An error occurred.'));
    }
  }

}
