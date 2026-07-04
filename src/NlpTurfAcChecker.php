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
   * record.  Checks whether NLPVoterAC has been fully applied or removed.
   * On success the record is stamped with the validated status.  On failure
   * the incomplete voters are retried via the VAN API, the expiration is
   * extended, and the status is left unchanged so the next cron run re-checks.
   *
   * @param array $record  Queue item: keys folderName, folderId, turfName,
   *                       turfId, status (turfCreated|turfDeleted).
   * @return void
   */
  public function checkExpiredTurfAc(array $record): void
  {
    $folderName = $record['folderName'];
    $turfName   = $record['turfName'];
    $folderId   = (int) $record['folderId'];
    $turfId     = (int) $record['turfId'];
    $status     = $record['status'];

    $isDelete = ($status === NlpTurfStatus::TURF_DELETED);
    $validatedStatus = $isDelete
      ? NlpTurfStatus::TURF_DELETE_VALIDATED
      : NlpTurfStatus::TURF_CREATE_VALIDATED;

    $turf = $this->turfsObj->getTurfByTurfName($folderId, $turfName);
    if (empty($turf)) {
      $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, $validatedStatus);
      return;
    }

    $turfIndex = (int) $turf['turfIndex'];
    $vanids    = array_keys($this->votersObj->getVotersInTurf($turfIndex));
    if (empty($vanids)) {
      $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, $validatedStatus);
      return;
    }

    $missingCode = $this->apiActivistCodesObj->hasApiActivistCodeBatch($vanids, 'NLPVoterAC');

    if ($isDelete) {
      // All removed means every voter is missing the code.
      if (count($missingCode) === count($vanids)) {
        $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, $validatedStatus);
        return;
      }
      $stillHaveCode = array_values(array_diff($vanids, $missingCode));
      Drupal::logger('nlpservices')->warning(
        'Expired turf @folder/@turf (turfId:@turfId): NLPVoterAC removal incomplete. @count voter(s) still have the code, retrying: @vanids',
        [
          '@folder' => $folderName,
          '@turf'   => $turfName,
          '@turfId' => $turfId,
          '@count'  => count($stillHaveCode),
          '@vanids' => implode(', ', $stillHaveCode),
        ]
      );
      $this->apiActivistCodesObj->setApiActivistCodeBatch($stillHaveCode, 'NLPVoterAC', 'Remove');
      $this->turfStatusObj->extendTurfStatusExpiration($folderName, $turfName, NlpTurfStatus::DELETED_EXPIRY_SMALL_SECS);
    } else {
      // All applied means no voter is missing the code.
      if (empty($missingCode)) {
        $this->turfStatusObj->setTurfStatusValidated($folderName, $turfName, $validatedStatus);
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
      $this->apiActivistCodesObj->setApiActivistCodeBatch($missingCode, 'NLPVoterAC', 'Apply');
      $this->turfStatusObj->extendTurfStatusExpiration($folderName, $turfName, NlpTurfStatus::CREATED_EXPIRY_SMALL_SECS);
    }
  }

}
