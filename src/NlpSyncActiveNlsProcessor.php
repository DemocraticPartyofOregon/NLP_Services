<?php

namespace Drupal\nlpservices;

use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpSyncActiveNlsProcessor {

  const int BATCH_LIMIT = 50;

  protected ApiNls $apiNlsObj;
  protected NlpLegislativeFixes $legislativeFixesObj;
  protected NlpConfiguration $nlpConfigurationObj;
  protected MagicWord $magicWordObj;
  protected DrupalUser $drupalUserObj;
  protected NlpNls $nlsObj;
  protected NlpAwards $awardsObj;
  protected MessengerInterface $messenger;

  public function __construct(
    $apiNlsObj,
    $legislativeFixesObj,
    $nlpConfigurationObj,
    $magicWordObj,
    $drupalUserObj,
    $nlsObj,
    $awardsObj,
    $messenger
  ) {
    $this->apiNlsObj           = $apiNlsObj;
    $this->legislativeFixesObj = $legislativeFixesObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->magicWordObj        = $magicWordObj;
    $this->drupalUserObj       = $drupalUserObj;
    $this->nlsObj              = $nlsObj;
    $this->awardsObj           = $awardsObj;
    $this->messenger           = $messenger;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('nlpservices.api_nls'),
      $container->get('nlpservices.legislative_fixes'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.magic_word'),
      $container->get('nlpservices.drupal_user'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.awards'),
      $container->get('messenger'),
    );
  }

  public function processBatch(array $arg, array &$context): void {
    $uri    = $arg['uri'];
    $county = $arg['county'];
    $legislativeFixes = $this->legislativeFixesObj->getLegFixes($county);

    $fh = fopen($uri, 'r');
    if (!$fh) {
      $this->messenger->addWarning(t('Unable to open @uri', ['@uri' => $uri]));
      $context['finished'] = TRUE;
      return;
    }

    $filesize = filesize($uri);
    $context['finished'] = 0;

    if (empty($context['sandbox']['seek'])) {
      $context['sandbox']['recordCount'] = 0;
      $hdr      = fgetcsv($fh);
      $fieldPos = array_flip($hdr);

      $notRegisteredAC    = $this->nlpConfigurationObj->getActivistCode('NLPNotRegisteredAC');
      $dropoutAC          = $this->nlpConfigurationObj->getActivistCode('NLPDropoutAC');

      $notRegisteredTitle = 'ActivistCode_' . $notRegisteredAC;
      $fieldPos['notRegisteredAc'] = 0;
      if (!empty($fieldPos[$notRegisteredTitle])) {
        $fieldPos['notRegisteredAc'] = $fieldPos[$notRegisteredTitle];
        unset($fieldPos[$notRegisteredTitle]);
      }

      $dropoutTitle = 'ActivistCode_' . $dropoutAC;
      $fieldPos['dropoutAC'] = 0;
      if (!empty($fieldPos[$dropoutTitle])) {
        $fieldPos['dropoutAC'] = $fieldPos[$dropoutTitle];
        unset($fieldPos[$dropoutTitle]);
      }

      $context['sandbox']['fieldPos'] = $fieldPos;
    } else {
      fseek($fh, $context['sandbox']['seek']);
      $fieldPos = $context['sandbox']['fieldPos'];
    }
    $recordCount = $context['sandbox']['recordCount'];

    // Phase 1: Read up to BATCH_LIMIT records from the CSV.
    $batchData = [];
    $seek      = ftell($fh);
    $queCnt    = 0;
    do {
      $nlRaw = fgetcsv($fh);
      if (empty($nlRaw)) { break; }
      $seek = ftell($fh);
      $queCnt++;
      $recordCount++;

      $mcid = (int) $nlRaw[$fieldPos['VanID']];
      $batchData[$mcid] = [
        'notRegistered' => !empty($nlRaw[$fieldPos['notRegisteredAc']]),
        'dropout'       => !empty($nlRaw[$fieldPos['dropoutAC']]),
      ];

      if ($queCnt == self::BATCH_LIMIT) { break; }
    } while (TRUE);

    // Phase 2: Fetch all NL records concurrently.
    $nlRecords = $this->apiNlsObj->getNls($county, array_keys($batchData));

    // Phase 3: Process each NL record.
    foreach ($batchData as $mcid => $flags) {
      if (empty($nlRecords[$mcid])) { continue; }
      $this->processNlRecord($nlRecords[$mcid], $flags, $county, $legislativeFixes);
    }

    $context['sandbox']['seek']        = $seek;
    $context['finished']               = $seek / $filesize;
    $context['sandbox']['recordCount'] = $recordCount;
    $context['results']['recordCount'] = $recordCount;
    if ($queCnt != self::BATCH_LIMIT) {
      $context['finished'] = 1;
    }
    fclose($fh);
  }

  private function processNlRecord(array $nlRecord, array $flags, string $county, array $legislativeFixes): void {
    $mcid          = $nlRecord['mcid'];
    $notRegistered = $flags['notRegistered'];
    $dropout       = $flags['dropout'];

    if ($dropout) {
      $this->messenger->addError(t('NL rejected. This volunteer has been marked as a Dropout.<br>.. MCID:@mcid @firstName @lastName', ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName']]));
      return;
    }

    if (empty($nlRecord['email'])) {
      $this->messenger->addError(t("NL rejected. This volunteer does not have an email address and can't be an active NL.<br>.. MCID:@mcid @firstName @lastName", ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName']]));
      return;
    }

    $legislativeFix = $legislativeFixes[$mcid] ?? [];
    if (!$this->checkDistricts($nlRecord, $legislativeFix)) { return; }

    $zip = !empty($nlRecord['zip']) ? ', ' . $nlRecord['zip'] : '';
    $nlRecord['address'] = $nlRecord['address'] . ', ' . $nlRecord['city'] . $zip;
    unset($nlRecord['city'], $nlRecord['zip']);
    $nlRecord['county'] = $county;

    if (empty($nlRecord['nickname'])) {
      $nlRecord['nickname'] = $nlRecord['firstName'];
    }

    $emailVerification = $this->verifyUniqueEmail($nlRecord);
    if (!$emailVerification['uniqueEmail']) {
      $this->messenger->addError($emailVerification['message']);
      return;
    }
    $credentials = $emailVerification['credentials'];

    $drupalAccountVerify = $this->verifyDrupalAccount($nlRecord, $credentials);
    if (!$drupalAccountVerify['accountOk']) {
      $this->messenger->addError($drupalAccountVerify['message']);
      return;
    }

    $drupalUser = $drupalAccountVerify['drupalUser'] ?? [];
    $magicWord  = $drupalAccountVerify['magicWord'] ?? '';

    if (empty($drupalUser['userName'])) {
      $this->messenger->addError(t('NL rejected. Unable to create or verify a Drupal account for this NL.<br>.. MCID:@mcid @firstName @lastName', ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName']]));
      return;
    }

    $credentials = $this->verifyCredentials($nlRecord, $drupalUser, $credentials, $magicWord);

    if (!$notRegistered) {
      $lastKnownVfId = $credentials['vfid'] ?? 0;
      $this->checkVoterId($nlRecord['vfid'], $lastKnownVfId, $nlRecord);
    }

    $this->createNlsRecord($nlRecord);
  }

  public function checkDistricts(array &$nlRecord, array $legislativeFix): bool {
    $nlHd      = !empty($nlRecord['hd'])       ? ltrim($nlRecord['hd'], '0')      : 0;
    $pct       = !empty($nlRecord['precinct']) ? trim($nlRecord['precinct'])       : 0;
    $firstName = !empty($nlRecord['nickname']) ? $nlRecord['nickname']             : $nlRecord['firstName'];
    $lastName  = $nlRecord['lastName'];
    $mcid      = $nlRecord['mcid'];

    if (empty($pct) || empty($nlHd)) {
      if (!empty($legislativeFix)) {
        $nlHd = $legislativeFix['hd'];
        $pct  = trim($legislativeFix['precinct']);
        $this->messenger->addWarning(t('MCID:@mcid @firstName @lastName, HD and Pct repaired.', ['@mcid' => $mcid, '@firstName' => $firstName, '@lastName' => $lastName]));
      } else {
        $this->messenger->addError(t('NL rejected: MCID:@mcid @firstName @lastName, HD or Pct is missing.', ['@mcid' => $mcid, '@firstName' => $firstName, '@lastName' => $lastName]));
        return FALSE;
      }
    } else {
      $state = $this->nlpConfigurationObj->getStateName();
      if ($state == 'Oregon') {
        $pctParts = explode('-', $pct);
        if (isset($pctParts[1])) {
          $pct = $pctParts[1];
        }
      }
    }
    $nlRecord['hd']       = $nlHd;
    $nlRecord['precinct'] = $pct;
    return TRUE;
  }

  public function checkVoterId(int $vfid, int $savedVfid, array $nl): void {
    if (empty($vfid)) {
      $this->messenger->addWarning(t('MCID:@mcid @firstName @lastName, The VoterFile VANID is missing for this NL. Check if the MyCampaign record is a duplicate.', ['@mcid' => $nl['mcid'], '@firstName' => $nl['firstName'], '@lastName' => $nl['lastName']]));
      return;
    }
    if (empty($savedVfid)) {
      $this->magicWordObj->updateCredentials(['mcid' => $nl['mcid'], 'vfid' => $nl['vfid']]);
      return;
    }
    if ($vfid != $savedVfid) {
      $this->messenger->addWarning(t('MCID:@mcid @firstName @lastName, new VoterID: @vfid, Old voterId: @savedVfid, The VoterFile VANID does not match a previous usage. The MyCampaign record may point to the wrong voter.', ['@mcid' => $nl['mcid'], '@firstName' => $nl['firstName'], '@lastName' => $nl['lastName'], '@vfid' => $vfid, '@savedVfid' => $savedVfid]));
    }
  }

  public function roleConvert(array $roles): array {
    $nlpRoleIds = [
      NLP_ADMIN_ROLE_ID       => NLP_ADMIN_ROLE_LABEL,
      NLP_COORDINATOR_ROLE_ID => NLP_COORDINATOR_ROLE_LABEL,
      NLP_LEADER_ROLE_ID      => NLP_LEADER_ROLE_LABEL,
    ];
    $roleArray = [];
    foreach ($roles as $roleId) {
      if (isset($nlpRoleIds[$roleId])) {
        $roleArray[$roleId] = $nlpRoleIds[$roleId];
      }
    }
    return $roleArray;
  }

  private function verifyUniqueEmail(array $nlRecord): array {
    $nlEmail     = $nlRecord['email'];
    $mcid        = $nlRecord['mcid'];
    $credentials = $this->magicWordObj->getCredentials($mcid);

    if (!empty($credentials)) {
      if ($nlEmail != $credentials['email']) {
        $otherCredentials = $this->magicWordObj->getCredentialsByEmail($nlEmail);
        $otherDrupal      = $this->drupalUserObj->getUserByEmail($nlEmail);
        $credentialsOk    = empty($otherCredentials) || $otherCredentials['mcid'] == $mcid;
        $drupalRecordOk   = empty($otherDrupal)      || $otherDrupal['mcid'] == $mcid;

        if (!$credentialsOk || !$drupalRecordOk) {
          $drupalMessage = $nlMessage = '';
          if (!empty($otherDrupal)) {
            $drupalMessage = t('<br>.. In Drupal login, MCID:@mcid userName:@userName', ['@mcid' => $otherDrupal['mcid'], '@userName' => $otherDrupal['userName']]);
          }
          if (!empty($otherCredentials)) {
            $nlMessage = t('<br>.&nbsp; In active NLs, MCID:@mcid userName:@userName', ['@mcid' => $otherCredentials['mcid'], '@userName' => $otherCredentials['userName']]);
          }
          $message  = t("NL rejected. The volunteer's email is in use by another NL.<br>.&nbsp; MCID:@mcid firstName:@firstName lastName:@lastName email:@email", ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName'], '@email' => $nlEmail]);
          $message .= $nlMessage;
          $message .= $drupalMessage;
          return ['uniqueEmail' => FALSE, 'message' => $message];
        }
      }
      return ['uniqueEmail' => TRUE, 'credentials' => $credentials];
    }

    $otherCredentials = $this->magicWordObj->getCredentialsByEmail($nlEmail);
    if (!empty($otherCredentials)) {
      $message = t("NL rejected. The volunteer's email is in use by another NL.<br>.&nbsp; MCID:@mcid @firstName @lastName email:@email<br>.&nbsp; The existing NL. MCID:@otherMcid userName:@otherUserName", ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName'], '@email' => $nlEmail, '@otherMcid' => $otherCredentials['mcid'], '@otherUserName' => $otherCredentials['userName']]);
      return ['uniqueEmail' => FALSE, 'message' => $message];
    }

    $otherDrupal = $this->drupalUserObj->getUserByEmail($nlEmail);
    if (!empty($otherDrupal) && $otherDrupal['mcid'] != $mcid) {
      $message = t("NL rejected. The volunteer's email is in use by another NL.<br>.&nbsp; MCID:@mcid @firstName @lastName email:@email<br>.&nbsp; In Drupal login, MCID:@otherMcid userName:@otherUserName", ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName'], '@email' => $nlEmail, '@otherMcid' => $otherDrupal['mcid'], '@otherUserName' => $otherDrupal['userName']]);
      return ['uniqueEmail' => FALSE, 'message' => $message];
    }

    return ['uniqueEmail' => TRUE, 'credentials' => []];
  }

  private function verifyDrupalAccount(array $nlRecord, array $credentials): array {
    $nlEmail        = $nlRecord['email'];
    $drupalEmailUser = $this->drupalUserObj->getUserByEmail($nlEmail);
    $drupalMcidUser  = $this->drupalUserObj->getUserByMcid($nlRecord['mcid']);

    $drupalEmailUid = $drupalEmailUser['uid'] ?? 0;
    $drupalMcidUid  = $drupalMcidUser['uid']  ?? 0;

    if ($drupalEmailUid == 0 && $drupalMcidUid == 0) {
      $type = 'newNl';
    } elseif ($drupalEmailUid == $drupalMcidUid) {
      $type = 'updateBoth';
    } elseif ($drupalMcidUid > 0 && $drupalEmailUid == 0) {
      $type = 'newEmail';
    } else {
      $type = 'error';
    }

    switch ($type) {
      case 'newNl':
        $result           = $this->createDrupalLogin($nlRecord);
        $result['type']        = $type;
        $result['credentials'] = $credentials;
        return $result;

      case 'updateBoth':
      case 'newEmail':
        return $this->updateDrupalAccount($nlRecord, $drupalMcidUser);

      default:
        $mcid      = $nlRecord['mcid'];
        $firstName = $nlRecord['firstName'];
        $lastName  = $nlRecord['lastName'];
        $mcidMessage  = !empty($drupalMcidUser)  ? t(' In Drupal login by MCID, MCID:@mcid userName:@userName email:@email<br>',  ['@mcid' => $drupalMcidUser['mcid'],  '@userName' => $drupalMcidUser['userName'],  '@email' => $drupalMcidUser['email']])  : '';
        $emailMessage = !empty($drupalEmailUser) ? t(' In Drupal login by email, MCID:@mcid userName:@userName email:@email<br>', ['@mcid' => $drupalEmailUser['mcid'], '@userName' => $drupalEmailUser['userName'], '@email' => $drupalEmailUser['email']]) : '';
        $message  = t('NL rejected. Unable to determine the Drupal account for this NL &nbsp; MCID:@mcid @firstName @lastName email:@email<br>', ['@mcid' => $mcid, '@firstName' => $firstName, '@lastName' => $lastName, '@email' => $nlEmail]);
        $message .= $mcidMessage;
        $message .= $emailMessage;

        if ($this->nlpConfigurationObj->getDebug()) {
          nlp_debug_msg('$nlRecord:', $nlRecord);
          nlp_debug_msg('$credentials:', $credentials);
          nlp_debug_msg('$drupalMcidUser:', $drupalMcidUser);
          nlp_debug_msg('$drupalEmailUser:', $drupalEmailUser);
          $awards = $this->awardsObj->getAward($nlRecord['mcid']);
          nlp_debug_msg('$awards:', $awards);
          if (!empty($drupalEmailUser)) {
            $altMcid = $drupalEmailUser['mcid'];
            nlp_debug_msg('$altMcid:', $altMcid);
            if (!empty($altMcid)) {
              $county = $drupalEmailUser['county'];
              $altNls = $this->apiNlsObj->getApiNls($county, $altMcid);
              nlp_debug_msg('$altNls:', $altNls);
              if (empty($altNls)) {
                $drupalUserUpdate = ['uid' => $drupalEmailUser['uid'], 'mcid' => $nlRecord['mcid']];
                nlp_debug_msg('$drupalUserUpdate:', $drupalUserUpdate);
                $this->drupalUserObj->updateUser($drupalUserUpdate);
              }
            }
            if ($altMcid != $nlRecord['mcid']) {
              $altAwards      = $this->awardsObj->getAward($altMcid);
              nlp_debug_msg('$altAwards:', $altAwards);
              $altCredentials = $this->magicWordObj->getCredentials($altMcid);
              nlp_debug_msg('$altCredentials:', $altCredentials);
            }
          }
        }
        return ['accountOk' => FALSE, 'message' => $message];
    }
  }

  private function updateDrupalAccount(array $nlRecord, array $drupalUser): array {
    $userUpdate = [];
    foreach (['county', 'firstName', 'lastName', 'phone', 'hd'] as $field) {
      if (!empty($nlRecord[$field]) && $nlRecord[$field] != $drupalUser[$field]) {
        $userUpdate[$field] = $nlRecord[$field];
      }
    }
    if (!empty($nlRecord['email']) && !empty($drupalUser['email']) && $nlRecord['email'] != $drupalUser['email']) {
      $userUpdate['mail'] = $nlRecord['email'];
    }
    if (!empty($userUpdate)) {
      $userUpdate['uid'] = $drupalUser['uid'];
      $updatedUser = $this->drupalUserObj->updateUser($userUpdate);
      if ($updatedUser['status'] == 'error') {
        return ['accountOk' => FALSE, 'message' => $updatedUser['message']];
      }
    }
    return ['accountOk' => TRUE, 'drupalUser' => empty($updatedUser) ? $drupalUser : $updatedUser];
  }

  private function createNlsRecord(array $nlRecord): bool {
    unset($nlRecord['userName']);
    $insertOk = $this->nlsObj->createNl($nlRecord);
    if (!$insertOk) { return FALSE; }
    $nlStatus = $this->nlsObj->getNlsStatus($nlRecord['mcid'], $nlRecord['county']);
    if (!empty($nlStatus)) {
      $this->nlsObj->setNlsStatus($nlStatus);
    }
    return TRUE;
  }

  private function verifyCredentials(array $nlRecord, array $drupalUser, array $credentials, string $magicWord): array {
    if (empty($credentials)) {
      return $this->createNewCredentials($nlRecord, $drupalUser, $magicWord);
    }
    return $this->updateCredentials($nlRecord, $drupalUser, $credentials, $magicWord);
  }

  private function createNewCredentials(array $nlRecord, array $drupalUser, string $magicWord): array {
    $credentials              = $this->magicWordObj->createNewCredentials($nlRecord['mcid'], $drupalUser['userName'], $nlRecord['email']);
    $credentials['vfid']      = $nlRecord['vfid'] ?? 0;
    $credentials['county']    = $drupalUser['county'];
    $credentials['hd']        = $drupalUser['hd'];
    $credentials['roles']     = $this->roleConvert($drupalUser['roles']);
    $credentials['created']   = $drupalUser['created'];
    $credentials['access']    = $drupalUser['access'];
    if (!empty($magicWord)) {
      $credentials['magicWord'] = $magicWord;
    }
    $this->magicWordObj->createCredentials($credentials);
    if (!empty($magicWord)) {
      $this->magicWordObj->setMagicWord($nlRecord['mcid'], $magicWord);
    }
    return $credentials;
  }

  private function updateCredentials(array $nlRecord, array $drupalUser, array $credentials, string $magicWord = ''): array {
    $vfid = $nlRecord['vfid'] ?? 0;
    if ($vfid != $credentials['vfid']) {
      $credentials['vfid'] = $vfid;
    }
    foreach (['email', 'county', 'hd', 'created', 'access'] as $field) {
      if ($credentials[$field] != $drupalUser[$field]) {
        $credentials[$field] = $drupalUser[$field];
      }
    }
    $credentials['roles'] = $this->roleConvert($drupalUser['roles']);
    $this->magicWordObj->updateCredentials($credentials);
    if (!empty($magicWord) && empty($this->magicWordObj->getMagicWord($nlRecord['mcid']))) {
      $this->magicWordObj->setMagicWord($nlRecord['mcid'], $magicWord);
      $credentials['magicWord'] = $magicWord;
    }
    return $credentials;
  }

  private function createDrupalLogin(array $nlRecord): array {
    $userInfo = [
      'mcid'      => $nlRecord['mcid'],
      'email'     => $nlRecord['email'],
      'firstName' => empty($nlRecord['nickname']) ? $nlRecord['firstName'] : $nlRecord['nickname'],
      'lastName'  => $nlRecord['lastName'],
      'phone'     => $nlRecord['phone'],
      'county'    => $nlRecord['county'],
      'hd'        => $nlRecord['hd'],
    ];
    $uniqueUsernameArray = $this->getUniqueUsername($nlRecord);
    $uniqueUsername      = $uniqueUsernameArray['uniqueUsername'];
    if (empty($uniqueUsername)) {
      return ['accountOk' => FALSE, 'message' => $uniqueUsernameArray['message']];
    }
    $userInfo['userName']  = $uniqueUsername;
    $userInfo['magicWord'] = $this->magicWordObj->createMagicWord();
    $newAccount = $this->drupalUserObj->createDrupalAccount($userInfo);
    if (empty($newAccount)) {
      nlp_debug_msg('NL rejected. Unable to create Drupal account.', $userInfo);
      return ['accountOk' => FALSE, 'message' => t('NL rejected. System rejected the account.')];
    }
    return ['accountOk' => TRUE, 'drupalUser' => $newAccount, 'magicWord' => $userInfo['magicWord']];
  }

  private function getUniqueUsername(array $nlRecord): array {
    $namesTried = [];
    do {
      $uniqueUsernameName = $this->magicWordObj->uniqueUserName($nlRecord, $namesTried);
      if (empty($uniqueUsernameName)) { break; }
      $otherDrupalUser = $this->drupalUserObj->getUserByName($uniqueUsernameName);
      if (empty($otherDrupalUser)) { break; }
    } while (TRUE);

    if (empty($uniqueUsernameName)) {
      $message = t('NL rejected. Unable to find a unique userName.<br>.. MCID:@mcid email:@email @firstName @lastName', ['@mcid' => $nlRecord['mcid'], '@email' => $nlRecord['email'], '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName']]);
      return ['uniqueUsername' => '', 'message' => $message];
    }
    return ['uniqueUsername' => $uniqueUsernameName];
  }

}
