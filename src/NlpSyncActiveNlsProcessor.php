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
  protected ApiActivistCodes $activistCodesObj;
  protected NlpMcidReassignment $mcidReassignmentObj;

  public function __construct(
    $apiNlsObj,
    $legislativeFixesObj,
    $nlpConfigurationObj,
    $magicWordObj,
    $drupalUserObj,
    $nlsObj,
    $awardsObj,
    $messenger,
    $activistCodesObj,
    $mcidReassignmentObj
  ) {
    $this->apiNlsObj           = $apiNlsObj;
    $this->legislativeFixesObj = $legislativeFixesObj;
    $this->nlpConfigurationObj = $nlpConfigurationObj;
    $this->magicWordObj        = $magicWordObj;
    $this->drupalUserObj       = $drupalUserObj;
    $this->nlsObj              = $nlsObj;
    $this->awardsObj           = $awardsObj;
    $this->messenger           = $messenger;
    $this->activistCodesObj    = $activistCodesObj;
    $this->mcidReassignmentObj = $mcidReassignmentObj;
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
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.mcid_reassignment'),
    );
  }

  /**
   * processBatch
   *
   * Processes a batch of data from a CSV file and performs specific operations
   * based on the data extracted.
   *
   * @param array $arg
   *   An associative array containing arguments needed for processing:
   *   - uri: The file path to the CSV file to process.
   *   - county: The name of the county being processed.
   * @param array &$context
   *   A reference to the context array for batch operations. This includes:
   *   - finished: A flag indicating the processing status (completed or not).
   *   - sandbox: Stores intermediate processing data, such as cursor position
   *     and field mappings.
   *   - results: Stores the final processing results, such as the record count.
   *
   * @return void
   *   This method does not return a value. All processing results are stored
   *   in the reference context array.
   */
  public function processBatch(array $arg, array &$context): void {
    $uri    = $arg['uri'];
    $county = $arg['county'];
    $context['results']['county'] = $county;
    $this->nlsObj->markActiveNlsSync($county);
    $legislativeFixes = $this->legislativeFixesObj->getLegFixes($county);

    $fh = @fopen($uri, 'r');
    if (!$fh) {
      $this->messenger->addWarning(t('Unable to open @uri', ['@uri' => $uri]));
      $context['finished'] = TRUE;
      return;
    }

    $filesize = filesize($uri);
    $context['finished'] = 0;

    if (empty($context['sandbox']['seek'])) {
      // First step: replace the old list of active NLs now that the rebuild
      // is actually running and the export file is readable.
      $this->nlsObj->deleteNls($county);
      $context['sandbox']['recordCount'] = 0;
      $hdr      = fgetcsv($fh, escape: '');
      $fieldPos = array_flip($hdr);

      $notRegisteredAC    = $this->nlpConfigurationObj->getActivistCode('NLPNotRegisteredAC');
      $dropoutAC          = $this->nlpConfigurationObj->getActivistCode('NLPDropoutAC');

      $notRegisteredTitle = 'ActivistCode_' . $notRegisteredAC;
      if (array_key_exists($notRegisteredTitle, $fieldPos)) {
        $fieldPos['notRegisteredAc'] = $fieldPos[$notRegisteredTitle];
        unset($fieldPos[$notRegisteredTitle]);
      } else {
        $fieldPos['notRegisteredAc'] = NULL;
        $this->messenger->addWarning(t('Column @title (Not Registered activist code) was not found in the uploaded file. Not-registered status will not be checked for this batch.', ['@title' => $notRegisteredTitle]));
      }

      $dropoutTitle = 'ActivistCode_' . $dropoutAC;
      if (array_key_exists($dropoutTitle, $fieldPos)) {
        $fieldPos['dropoutAC'] = $fieldPos[$dropoutTitle];
        unset($fieldPos[$dropoutTitle]);
      } else {
        $fieldPos['dropoutAC'] = NULL;
        $this->messenger->addWarning(t('Column @title (Dropout activist code) was not found in the uploaded file. Dropout status will not be checked for this batch.', ['@title' => $dropoutTitle]));
      }
      //nlp_debug_msg('$fieldPos',$fieldPos);

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
      $nlRaw = fgetcsv($fh, escape: '');
      //nlp_debug_msg('$nlRaw',$nlRaw);
      if (empty($nlRaw)) { break; }
      $seek = ftell($fh);
      $queCnt++;
      $recordCount++;

      $mcid = (int) $nlRaw[$fieldPos['VanID']];
      $notRegisteredPos = $fieldPos['notRegisteredAc'];
      $dropoutPos       = $fieldPos['dropoutAC'];
      $batchData[$mcid] = [
        'notRegistered' => $notRegisteredPos !== NULL && !empty($nlRaw[$notRegisteredPos]),
        'dropout'       => $dropoutPos !== NULL && !empty($nlRaw[$dropoutPos]),
      ];

      if ($queCnt == self::BATCH_LIMIT) { break; }
    } while (TRUE);

    //nlp_debug_msg('$batchData',$batchData);
    // Phase 2: Fetch all NL records concurrently.
    $nlRecords = $this->apiNlsObj->getNls($county, array_keys($batchData));

    //nlp_debug_msg('$nlRecords',$nlRecords);
    // Phase 3: Process each NL record.
    foreach ($batchData as $mcid => $flags) {
      if (empty($nlRecords[$mcid])) {
        $this->messenger->addError(t('NL rejected. MyCampaign did not return a record for this volunteer.<br>.. MCID:@mcid', ['@mcid' => $mcid]));
        continue;
      }
      $nlRecord = $nlRecords[$mcid];
      if ($nlRecord['mcid'] != $mcid) {
        $this->messenger->addError(t('NL rejected. MyCampaign returned MCID:@returnedMcid @firstName @lastName when asked for MCID:@mcid. The record was probably merged; update the list to use the current MCID.', ['@mcid' => $mcid, '@returnedMcid' => $nlRecord['mcid'], '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName']]));
        continue;
      }
      $this->processNlRecord($nlRecord, $flags, $county, $legislativeFixes);
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

  /**
   * processNlRecord
   *
   * Processes a National Leader (NL) record by validating its attributes, handling errors,
   * and creating the NL's record if all validations pass.
   *
   * @param array $nlRecord The NL record data to process, containing details
   *                                   such as MCID, name, email, and address.
   * @param array $flags Flags providing additional processing context, such
   *                                   as 'notRegistered' and 'dropout' status.
   * @param string $county The county information to associate with the NL record.
   * @param array $legislativeFixes An array containing legislative fixes for validation
   *                                   of districts based on MCID.
   *
   * @return void                      This method does not return a value. Errors and validation
   *                                   results are handled within the method.
   */
  private function processNlRecord(array $nlRecord, array $flags, string $county, array $legislativeFixes): void {
    $mcid          = $nlRecord['mcid'];
    $notRegistered = $flags['notRegistered'];
    $dropout       = $flags['dropout'];

    if ($dropout) {
      $this->messenger->addError(t('NL rejected. This volunteer has been marked as a Dropout.<br>.. MCID:@mcid @firstName @lastName', ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName']]));
      return;
    }

    if (!$this->checkVoterId($nlRecord, $notRegistered, $county)) { return; }

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

    $this->verifyCredentials($nlRecord, $drupalUser, $credentials, $magicWord);

    $nlRecord['notRegistered'] = $notRegistered;
    if (!$this->createNlsRecord($nlRecord)) {
      $this->messenger->addError(t('NL rejected. The NL record could not be saved to the database; a field may be too long (precinct: @precinct, HD: @hd, address: @address).<br>.. MCID:@mcid @firstName @lastName', ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName'], '@precinct' => $nlRecord['precinct'], '@hd' => $nlRecord['hd'], '@address' => $nlRecord['address']]));
    }
  }

  /**
   * checkDistricts
   *
   * Validates and updates district information in the given record using the provided legislative fix if necessary.
   *
   * @param array $nlRecord
   *   The record containing district information to be checked and potentially updated.
   *   It must include keys 'hd' (house district), 'precinct', 'mcid', 'firstName', and optionally 'nickname'.
   *   The 'hd' and 'precinct' values may be modified directly within this array.
   * @param array $legislativeFix
   *   An optional array containing fallback values for 'hd' (house district) and 'precinct'.
   *   This is used if the corresponding values in $nlRecord are missing.
   *
   * @return bool
   *   Returns TRUE if district information is successfully validated or updated.
   *   Returns FALSE if required information for validation is missing and cannot be repaired.
   */
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

  /**
   * checkVoterId
   *
   * Checks the NL's state voter ID (vfid). The state assigns and controls it,
   * so unlike the MCID it is never duplicated and identifies the NL across
   * duplicate MyCampaign records.
   *
   * 1. No vfid: rejected, unless the NL has the Not Registered activist code
   *    (not yet 18, or lives in another state), in which case accepted with a
   *    warning.
   * 2. The vfid is saved under a different MCID: the record is a potential
   *    duplicate. If MyCampaign still has the saved MCID, the record is a
   *    duplicate and is rejected. If the saved MCID was destroyed by a merge,
   *    the NLP database is moved to this MCID so the NL keeps their login and
   *    history.
   * 3. A vfid and the Not Registered activist code: the NL is registered now,
   *    so the activist code is removed in MyCampaign.
   *
   * @param array $nlRecord
   *   The NL record from MyCampaign; must include 'mcid', 'vfid', 'firstName'
   *   and 'lastName'.
   * @param bool $notRegistered
   *   The NL has the Not Registered activist code; cleared when it is removed.
   * @param string $county
   *   The county whose committee owns the MyCampaign record.
   *
   * @return bool
   *   TRUE if the NL should continue to be processed, FALSE if rejected.
   */
  public function checkVoterId(array $nlRecord, bool &$notRegistered, string $county): bool {
    $mcid = $nlRecord['mcid'];
    $vfid = (int) ($nlRecord['vfid'] ?? 0);
    $who  = ['@mcid' => $mcid, '@firstName' => $nlRecord['firstName'], '@lastName' => $nlRecord['lastName']];

    if (empty($vfid)) {
      if (!$notRegistered) {
        $this->messenger->addError(t('NL rejected. The vfid is missing, and the volunteer does not have the NLP Not Registered activist code. Check if the MyCampaign record is a duplicate.<br>.. MCID:@mcid @firstName @lastName', $who));
        return FALSE;
      }
      $this->messenger->addWarning(t('NL accepted without a vfid because the volunteer has the NLP Not Registered activist code.<br>.. MCID:@mcid @firstName @lastName', $who));
      return TRUE;
    }

    $existingCredentials = $this->magicWordObj->getCredentialsByVfid($vfid);
    $savedMcid = (int) ($existingCredentials['mcid'] ?? 0);
    if (!empty($savedMcid) && $savedMcid != $mcid) {
      $who += ['@vfid' => $vfid, '@savedMcid' => $savedMcid];
      $status = $this->apiNlsObj->getMcidStatus($county, $savedMcid);
      if ($status == ApiNls::MCID_EXISTS) {
        $this->messenger->addError(t('NL rejected. This MyCampaign record is a duplicate: vfid @vfid belongs to the NL with MCID:@savedMcid, which still exists in MyCampaign.<br>.. MCID:@mcid @firstName @lastName', $who));
        return FALSE;
      }
      if ($status == ApiNls::MCID_UNKNOWN) {
        $this->messenger->addError(t('NL rejected. vfid @vfid belongs to the NL with MCID:@savedMcid, and MyCampaign could not be reached to check whether that record still exists. Sync again later.<br>.. MCID:@mcid @firstName @lastName', $who));
        return FALSE;
      }
      $reassigned = $this->mcidReassignmentObj->reassign($savedMcid, $mcid);
      if (!$reassigned['ok']) {
        $who['@reason'] = $reassigned['message'];
        $this->messenger->addError(t('NL rejected. vfid @vfid belongs to MCID:@savedMcid, which no longer exists in MyCampaign, but the NLP database could not be moved to the new MCID: @reason Use the Reassign MCID page to finish the merge.<br>.. MCID:@mcid @firstName @lastName', $who));
        return FALSE;
      }
      $this->messenger->addWarning(t('MCID:@savedMcid was merged into MCID:@mcid in MyCampaign. The NLP database was updated to use the new MCID.<br>.. MCID:@mcid @firstName @lastName', $who));
    }

    if ($notRegistered) {
      $removed = $this->activistCodesObj->setApiActivistCode([
        'codeName'  => 'NLPNotRegisteredAC',
        'vanid'     => $mcid,
        'action'    => 'Remove',
        'committee' => $county,
      ]);
      if ($removed) {
        $this->messenger->addWarning(t('The NLP Not Registered activist code was removed because the volunteer has a vfid.<br>.. MCID:@mcid @firstName @lastName', $who));
      } else {
        $this->messenger->addWarning(t('The volunteer has a vfid but the NLP Not Registered activist code could not be removed. Remove it in MyCampaign.<br>.. MCID:@mcid @firstName @lastName', $who));
      }
      // The vfid shows the NL is registered, regardless of whether VAN has caught up.
      $notRegistered = FALSE;
    }
    return TRUE;
  }

  /**
   * roleConvert
   *
   * Converts a list of role IDs into their corresponding role labels based on predefined mappings.
   *
   * @param array $roles
   *   An array of role IDs to be converted. Each element represents a role ID to look up.
   *
   * @return array
   *   An associative array where keys are the role IDs from the input, and values are the corresponding role labels.
   *   Only role IDs that have a predefined mapping will be included.
   */
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

  /**
   * verifyUniqueEmail
   *
   * Verifies if the email address in the given record is unique across both NL credentials and Drupal users.
   * If the email is found to be in use by another record, a detailed message is returned.
   *
   * @param array $nlRecord
   *   The record containing information about the NL volunteer.
   *   The array must include the keys:
   *   - 'email': The email address of the volunteer.
   *   - 'mcid': The unique identifier for the NL participant.
   *   - 'firstName': The first name of the participant.
   *   - 'lastName': The last name of the participant.
   *
   * @return array
   *   Returns an associative array structured as follows:
   *   - 'uniqueEmail' (bool): TRUE if the email is unique or associated with the same record, FALSE otherwise.
   *   - 'message' (string, optional): A detailed error message if the email is not unique.
   *   - 'credentials' (array, optional): The credentials associated with the given MCID if available.
   */
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

  /**
   * verifyDrupalAccount
   *
   * Determines the status of a Drupal account based on the provided NL record and credentials.
   * Creates or updates the account accordingly, or returns an error if the process fails.
   *
   * @param array $nlRecord
   *   The NL record containing information about the user. Must include keys such as:
   *   - 'email': The user's email address.
   *   - 'mcid': The user's member ID.
   *   - 'firstName': The user's first name.
   *   - 'lastName': The user's last name.
   * @param array $credentials
   *   The credentials to be used for creating or updating the user's Drupal account.
   *
   * @return array
   *   An associative array containing the result of the verification process. Keys include:
   *   - 'accountOk': A boolean indicating if the account verification was successful.
   *   - 'type': A string representing the type of account operation performed (e.g., 'newNl', 'updateBoth').
   *   - 'credentials': The credentials used, included only in certain cases.
   *   - 'message': A descriptive message providing details about the operation or error encountered.
   */
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
        $result           = $this->createDrupalLogin($nlRecord, $credentials);
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

        return ['accountOk' => FALSE, 'message' => $message];
    }
  }

  /**
   * updateDrupalAccount
   *
   * Updates a Drupal user account with new information from a specified record.
   * Compares fields to identify changes and applies updates if discrepancies are found.
   *
   * @param array $nlRecord
   *   The source data containing updated user information.
   *   Must include keys such as 'county', 'firstName', 'lastName', 'phone', 'hd', and optionally 'email'.
   * @param array $drupalUser
   *   The current Drupal user data to be checked against the source data.
   *   Must include keys such as 'uid', 'county', 'firstName', 'lastName', 'phone', 'hd', and 'email'.
   *
   * @return array
   *   Returns an associative array indicating the result of the account update:
   *   - 'accountOk' (bool): TRUE if the update was successful or no changes were needed, FALSE if an error occurred.
   *   - 'message' (string, optional): A message describing the error if 'accountOk' is FALSE.
   *   - 'drupalUser' (array, optional): The updated Drupal user data if the update succeeded.
   */
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

  /**
   * createNlsRecord
   *
   * Creates a new NLS (Non-Legislative System) record and updates its status if applicable.
   *
   * @param array $nlRecord
   *   The record to be processed for creation. The array must include keys such as 'mcid' and 'county'.
   *   The 'userName' key, if present, will be removed from the array before further processing.
   *
   * @return bool
   *   Returns TRUE if the NLS record is successfully created and status is updated (if available).
   *   Returns FALSE if the record creation process fails.
   */
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

  /**
   * verifyCredentials
   *
   * Verifies and updates user credentials or creates new credentials if none exist.
   *
   * @param array $nlRecord
   *   The record containing information about the user. This array is used to generate or validate credentials.
   * @param array $drupalUser
   *   The Drupal user information associated with the user. It provides additional context during credential processing.
   * @param array $credentials
   *   The existing credentials of the user, if any. If empty, new credentials will be created.
   * @param string $magicWord
   *   A special string used in the process of creating or updating credentials.
   *
   * @return void
   */
  private function verifyCredentials(array $nlRecord, array $drupalUser, array $credentials, string $magicWord): void
  {
    if (empty($credentials)) {
      $this->createNewCredentials($nlRecord, $drupalUser, $magicWord);
      return;
    }
    $this->updateCredentials($nlRecord, $drupalUser, $credentials, $magicWord);
  }

  /**
   * createNewCredentials
   *
   * Generates a new set of user credentials based on provided user and record data
   * and associates them with a magic word if applicable.
   *
   * @param array $nlRecord
   *   The record containing user data needed to create credentials. Required keys include:
   *   - 'mcid': Unique identifier for the user.
   *   - 'email': Email address of the user.
   *   - 'vfid' (optional): Voting file ID, defaults to 0 if not provided.
   * @param array $drupalUser
   *   The Drupal user account data, containing:
   *   - 'userName': Username for the account.
   *   - 'county': User's county.
   *   - 'hd': User's house district.
   *   - 'roles': Roles assigned to the user.
   *   - 'created': Timestamp of account creation.
   *   - 'access': Timestamp of last account access.
   * @param string $magicWord
   *   An optional string to be stored as a magic word associated with the credentials.
   *
   * @return void
   */
  private function createNewCredentials(array $nlRecord, array $drupalUser, string $magicWord): void {
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
  }

  /**
   * updateCredentials
   *
   * Updates user credentials with data from the provided records and optionally sets a magic word.
   *
   * @param array $nlRecord
   *   The record containing new data for updating credentials. It must include the 'vfid' and 'mcid' keys.
   * @param array $drupalUser
   *   The current user data from the Drupal system. It must include fields such as 'email', 'county', 'hd',
   *   'created', 'access', and 'roles', which will be used to update the credentials.
   * @param array $credentials
   *   The current credentials array to be updated with values from $nlRecord and $drupalUser.
   * @param string $magicWord
   *   (Optional) A secret string to be set as a magic word if not already defined for the user in $nlRecord['mcid'].
   *
   * @return void
   */
  private function updateCredentials(array $nlRecord, array $drupalUser, array $credentials, string $magicWord = ''): void {
    $vfid = $nlRecord['vfid'] ?? 0;
    if ($vfid != $credentials['vfid']) {
      $credentials['vfid'] = $vfid;
    }
    foreach (['userName', 'email', 'county', 'hd', 'created', 'access'] as $field) {
      if (($credentials[$field] ?? NULL) != $drupalUser[$field]) {
        $credentials[$field] = $drupalUser[$field];
      }
    }
    $credentials['roles'] = $this->roleConvert($drupalUser['roles']);
    $this->magicWordObj->updateCredentials($credentials);
    if (!empty($magicWord) && empty($this->magicWordObj->getMagicWord($nlRecord['mcid']))) {
      $this->magicWordObj->setMagicWord($nlRecord['mcid'], $magicWord);
    }
  }

  /**
   * createDrupalLogin
   *
   * Creates a Drupal user account for the provided record, including generating a unique username
   * and a magic word for authentication.
   *
   * @param array $nlRecord
   *   An associative array containing the following information required to create a Drupal account:
   *   - 'mcid': Unique identifier for the user.
   *   - 'email': Email address of the user.
   *   - 'firstName': First name of the user. If 'nickname' exists, it will be used instead.
   *   - 'lastName': Last name of the user.
   *   - 'phone': Phone number of the user.
   *   - 'county': County associated with the user.
   *   - 'hd': House district of the user.
   *
   * @return array
   *   Returns an associative array with the following keys:
   *   - 'accountOk' (bool): TRUE if the account creation was successful, FALSE otherwise.
   *   - 'message' (string): An error message if account creation failed.
   *   - 'drupalUser' (optional): The newly created Drupal user object, if account creation succeeded.
   *   - 'magicWord' (optional): The generated magic word, if account creation succeeded.
   */
  private function createDrupalLogin(array $nlRecord, array $savedCredentials = []): array {
    $userInfo = [
      'mcid'      => $nlRecord['mcid'],
      'email'     => $nlRecord['email'],
      'firstName' => empty($nlRecord['nickname']) ? $nlRecord['firstName'] : $nlRecord['nickname'],
      'lastName'  => $nlRecord['lastName'],
      'phone'     => $nlRecord['phone'],
      'county'    => $nlRecord['county'],
      'hd'        => $nlRecord['hd'],
    ];
    if (!empty($savedCredentials['userName']) && !empty($savedCredentials['magicWord'])) {
      $userInfo['userName']  = $savedCredentials['userName'];
      $userInfo['magicWord'] = $savedCredentials['magicWord'];
      if (!empty($savedCredentials['created'])) { $userInfo['created'] = $savedCredentials['created']; }
      if (!empty($savedCredentials['access']))  { $userInfo['access']  = $savedCredentials['access'];  }
    } else {
      $uniqueUsernameArray = $this->getUniqueUsername($nlRecord);
      $uniqueUsername      = $uniqueUsernameArray['uniqueUsername'];
      if (empty($uniqueUsername)) {
        return ['accountOk' => FALSE, 'message' => $uniqueUsernameArray['message']];
      }
      $userInfo['userName']  = $uniqueUsername;
      // Keep a saved password, so the account and the saved copy match and the
      // NL can still use the one they know.  A new password is saved by
      // verifyCredentials(), since the saved one is blank.
      $userInfo['magicWord'] = !empty($savedCredentials['magicWord'])
        ? $savedCredentials['magicWord']
        : $this->magicWordObj->createMagicWord();
    }
    $newAccount = $this->drupalUserObj->createDrupalAccount($userInfo);
    if (empty($newAccount)) {
      nlp_debug_msg('NL rejected. Unable to create Drupal account.', $userInfo);
      return ['accountOk' => FALSE, 'message' => t('NL rejected. System rejected the account.')];
    }
    return ['accountOk' => TRUE, 'drupalUser' => $newAccount, 'magicWord' => $userInfo['magicWord']];
  }

  /**
   * getUniqueUsername
   *
   * Attempts to generate a unique username for the provided record by checking against existing usernames.
   *
   * @param array $nlRecord
   *   The record containing user information for generating a username.
   *   Must include keys 'mcid', 'email', 'firstName', and 'lastName'.
   *
   * @return array
   *   An associative array containing the following keys:
   *   - 'uniqueUsername': A string representing the unique username generated or an empty string if a username could not be created.
   *   - 'message': A descriptive message indicating the success or failure of the username generation process.
   */
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
