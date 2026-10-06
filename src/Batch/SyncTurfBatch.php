<?php

namespace Drupal\nlpservices\Batch;

use Drupal;
use Drupal\Core\Render\Markup;
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
      if (($results['result'] ?? '') == 'error') {
        $messages = $results['message'];
        foreach ($messages as $message) {
          $messenger->addError($message);
        }
        // Nothing from a failed sync is left in the database, so there is nothing to delete.
        return;
      }
      // The batch reported success but the sync never recorded its results, so
      // there is no NL or turf to report on.
      if (empty($results['mcid']) || empty($results['county'])) {
        Drupal::logger('nlpservices')->error('Sync turf batch finished without results. Result keys: @keys',
          ['@keys' => implode(', ', array_keys($results)) ?: 'none']);
        $messenger->addWarning(t('The turf sync finished but did not report its results. Check the NL\'s turfs to see whether the turf was checked in, and sync it again if it was not.'));
        return;
      }

      $messages  = [];
      $turfName  = $results['turfName'];
      $messages[] = t('@turfName has been successfully checked in.', ['@turfName' => $turfName]);

      $mcid      = (int) $results['mcid'];
      $county    = $results['county'];
      $turfs     = $turfsObj->turfExists($mcid, $county);
      $turfCount = $turfs['turfCnt'] ?? 0;
      $nickname  = $results['nickname'];
      $messages[] = t('@nickname has @count turf(s) assigned.', ['@nickname' => $nickname, '@count' => $turfCount]);

      foreach ($turfs['turfs'] ?? [] as $turf) {
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
        $messenger->addStatus(Markup::create(implode('<br>', $messages)));
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
    } elseif (!empty($results['committed'])) {
      // The batch failed after the turf was saved, while setting activist codes in VoteBuilder.
      $messenger->addWarning(t('@turfName was checked in, but the NLP voter activist codes may not all be set in VoteBuilder. The nightly turf resync will add any that are missing.', ['@turfName' => $results['turfName']]));
    } else {
      $messenger->addError(t('An error occurred. The turf was not checked in and nothing was changed. Please try the sync again.'));
    }
  }

}
