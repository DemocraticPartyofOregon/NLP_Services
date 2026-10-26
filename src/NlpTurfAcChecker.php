<?php

namespace Drupal\nlpservices;

use Drupal;
use Symfony\Component\DependencyInjection\ContainerInterface;

class NlpTurfAcChecker
{
  protected NlpTurfs $turfsObj;
  protected NlpVoters $votersObj;
  protected ApiActivistCodes $apiActivistCodesObj;
  protected NlpTurfStatus $turfStatusObj;

  public function __construct($turfsObj, $votersObj, $apiActivistCodesObj, $turfStatusObj)
  {
    $this->turfsObj = $turfsObj;
    $this->votersObj = $votersObj;
    $this->apiActivistCodesObj = $apiActivistCodesObj;
    $this->turfStatusObj = $turfStatusObj;
  }

  public static function create(ContainerInterface $container): NlpTurfAcChecker
  {
    return new static(
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.turf_status'),
    );
  }

  /**
   * checkExpiredTurfAc
   *
   * Called by the queue worker for each expired turfCreated / turfDeleted
   * record.  Checks whether NLPVoterAC has been fully applied to a synced
   * turf.  On success the record is stamped with the validated status.  On
   * failure the voters missing the code are retried via the VAN API, the
   * expiration is extended, and the status is left unchanged so the next cron
   * run re-checks.
   *
   * A deleted turf is stamped validated without checking.  deleteTurf()
   * removes the turf and its voters at once, so a turf found by this name is
   * a newer turf, and the removal is already checked on cron by the
   * nlpservices_ac_verify_queue.
   *
   * @param array $record  Queue item: keys folderName, folderId, turfName,
   *                       turfId, createTime, status (turfCreated|turfDeleted).
   * @return void
   */
  public function checkExpiredTurfAc(array $record): void
  {
    $folderName = $record['folderName'];
    $turfName   = $record['turfName'];
    $folderId   = (int) $record['folderId'];
    $turfId     = (int) $record['turfId'];
    $status     = $record['status'];

    // The queue item is a copy of the record when cron queued it.  If the turf
    // was synced or deleted again since then, the copy is stale and the item
    // queued for the current record does the check.
    $current = $this->turfStatusObj->getTurfStatus($folderName, $turfName);
    if (empty($current) || $current['status'] !== $status
      || ($current['createTime'] ?? '') !== ($record['createTime'] ?? '')) {
      return;
    }

    if ($status === NlpTurfStatus::TURF_DELETED) {
      $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, NlpTurfStatus::TURF_DELETE_VALIDATED);
      return;
    }

    $turf = $this->turfsObj->getTurfByTurfName($folderId, $turfName);
    if (empty($turf)) {
      $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, NlpTurfStatus::TURF_CREATE_VALIDATED);
      return;
    }

    $turfIndex = (int) $turf['turfIndex'];
    $vanids    = array_keys($this->votersObj->getVotersInTurf($turfIndex));
    if (empty($vanids)) {
      $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, NlpTurfStatus::TURF_CREATE_VALIDATED);
      return;
    }

    $missingCode = $this->apiActivistCodesObj->hasApiActivistCodeBatch($vanids, 'NLPVoterAC', $unread);

    // All applied means no voter is missing the code.
    if (empty($missingCode)) {
      if (empty($unread)) {
        $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, NlpTurfStatus::TURF_CREATE_VALIDATED);
      } else {
        // Some voters couldn't be read, so check the turf again on a later cron run.
        $this->turfStatusObj->extendTurfStatusExpiration($folderName, $turfName, NlpTurfStatus::CREATED_EXPIRY_SMALL_SECS);
      }
      return;
    }
    Drupal::logger('nlpservices')->warning(
      'Expired turf @folder/@turf (turfId:@turfId): NLPVoterAC application incomplete. @count voter(s) still missing the code, retrying: @vanids',
      [
        '@folder' => $folderName,
        '@turf'   => $turfName,
        '@turfId' => $turfId,
        '@count'  => count($missingCode),
        '@vanids' => implode(', ', $missingCode),
      ]
    );
    $this->apiActivistCodesObj->setApiActivistCodeBatch($missingCode, 'NLPVoterAC', 'Apply',
      "expired turf check of $folderName/$turfName");
    $this->turfStatusObj->extendTurfStatusExpiration($folderName, $turfName, NlpTurfStatus::CREATED_EXPIRY_SMALL_SECS);
  }

}
