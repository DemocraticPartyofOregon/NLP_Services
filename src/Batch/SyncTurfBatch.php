<?php

namespace Drupal\nlpservices\Batch;

use Drupal;
use Drupal\nlpservices\NlpTurfs;
use Drupal\nlpservices\DrupalUser;
use Drupal\nlpservices\MagicWord;
use Drupal\nlpservices\NlpNls;
use Drupal\nlpservices\NlpSendEmail;

class SyncTurfBatch {

  public static function process(array $arg, array &$context): void {
    Drupal::service('nlpservices.sync_turf_processor')->processBatch($arg, $context);
  }

  /** @noinspection PhpUnusedParameterInspection */
  public static function finished(bool $success, array $results, array $operations): void {
    /** @var NlpTurfs $turfsObj */
    $turfsObj = Drupal::service('nlpservices.turfs');
    /** @var DrupalUser $drupalUserObj */
    $drupalUserObj = Drupal::service('nlpservices.drupal_user');
    /** @var MagicWord $magicWordObj */
    $magicWordObj = Drupal::service('nlpservices.magic_word');
    $messenger = Drupal::messenger();

    if ($success) {
      if ($results['result'] == 'error') {
        $messages = $results['message'];
        foreach ($messages as $message) {
          $messenger->addError($message);
        }
        if (!empty($results['turfIndex'])) {
          $turf              = [];
          $turf['mcid']      = $results['mcid'];
          $turf['county']    = $results['county'];
          $turf['turfIndex'] = $results['turfIndex'];
          $turfsObj->deleteTurf($turf);
        }
        return;
      }
      $messages  = [];
      $turfName  = $results['turfName'];
      $messages[] = t('@turfName has been successfully checked in.', ['@turfName' => $turfName]);

      $mcid      = $results['mcid'];
      $county    = $results['county'];
      $turfs     = $turfsObj->turfExists($mcid, $county);
      $turfCount = $turfs['turfCnt'];
      $nickname  = $results['nickname'];
      $messages[] = t('@nickname has @count turf(s) assigned.', ['@nickname' => $nickname, '@count' => $turfCount]);

      foreach ($turfs['turfs'] as $turf) {
        $turfIndex = $turf['turfIndex'];
        $turfName  = $turf['turfName'];
        $syncDate  = $turf['syncDate'];
        $messages[] = t('(@turfIndex) @syncDate @turfName', ['@turfIndex' => $turfIndex, '@syncDate' => $syncDate, '@turfName' => $turfName]);
      }

      $drupalUser = $drupalUserObj->getUserByMcid($mcid);
      if (!empty($drupalUser)) {
        $userName  = $drupalUser['userName'];
        $mcid      = $drupalUser['mcid'];
        $magicWord = $magicWordObj->getMagicWord($mcid);
        $messages[] = t('User credentials: Username: @userName Password: @magicWord', ['@userName' => $userName, '@magicWord' => $magicWord]);
        $messenger->addStatus(implode('<br>', $messages));
      }
      $movedVoters = $results['movedVoters'] ?? [];
      if (!empty($movedVoters)) {
        $names = implode(', ', array_map(
          fn($v) => $v['firstName'] . ' ' . $v['lastName'] . ' (was in turf ' . $v['oldTurfName'] . ', NL: ' . $v['oldNlName'] . ')',
          $movedVoters
        ));
        $messenger->addWarning(t('@count voter(s) were automatically moved from another turf: @names', [
          '@count' => count($movedVoters),
          '@names' => $names,
        ]));

        /** @var NlpNls $nlsObj */
        $nlsObj = Drupal::service('nlpservices.nls');
        /** @var NlpSendEmail $sendMailObj */
        $sendMailObj = Drupal::service('nlpservices.send_email');

        $byNl = [];
        foreach ($movedVoters as $voter) {
          $byNl[$voter['oldMcid']][] = $voter['firstName'] . ' ' . $voter['lastName'];
        }
        foreach ($byNl as $oldMcid => $voterNames) {
          $nl = $nlsObj->getNlById($oldMcid);
          if (empty($nl['email'])) { continue; }
          $subject = (string) t('Your turf list was shortened');
          $body    = (string) t(
            'One or more voters have been removed from your turf because they moved and registered at a new address:<br>@names<br>Your turf list has been shortened accordingly.',
            ['@names' => implode('<br>', $voterNames)]
          );
          $sendMailObj->sendEmail('deliver_turf', $nl['email'], $subject, $body);
        }
      }
    } else {
      $messenger->addError(t('An error occurred.'));
    }
  }

}
