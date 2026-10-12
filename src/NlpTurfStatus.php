<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Exception;

class NlpTurfStatus
{
  const string TURF_STATUS_TBL = 'nlp_turf_status';

  const string TURF_CREATED          = 'turfCreated';
  const string TURF_DELETED          = 'turfDeleted';
  const string TURF_CREATE_VALIDATED = 'turfCreateValidated';
  const string TURF_DELETE_VALIDATED = 'turfDeleteValidated';

  const int CREATED_EXPIRY_SMALL_SECS = 240;  // voter count < 60 (4 minutes)
  const int CREATED_EXPIRY_LARGE_SECS = 420;  // voter count >= 60 (7 minutes)
  const int DELETED_EXPIRY_SMALL_SECS = 240;  // voter count < 60 (4 minutes)
  const int DELETED_EXPIRY_LARGE_SECS = 420;  // voter count >= 60 (7 minutes)

  protected Connection $connection;
  protected NlpTurfs $turfsObj;
  protected NlpVoters $votersObj;
  protected ApiActivistCodes $apiActivistCodesObj;

  public function __construct($connection, $turfsObj, $votersObj, $apiActivistCodesObj)
  {
    $this->connection = $connection;
    $this->turfsObj = $turfsObj;
    $this->votersObj = $votersObj;
    $this->apiActivistCodesObj = $apiActivistCodesObj;
  }

  public static function create(ContainerInterface $container): NlpTurfStatus
  {
    return new static(
      $container->get('database'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.activist_codes'),
    );
  }

  /**
   * createTurfStatus
   *
   * Creates a new turf status record or updates the existing one keyed by
   * folderName/turfName.
   *
   * @param array $turfStatus - Keys: folderName, folderId, turfName, turfId,
   *                            createTime, expirationTime, status.
   * @return void
   */
  public function createTurfStatus(array $turfStatus): void
  {
    try {
      $updateFields = [
        'folderId'       => $turfStatus['folderId'],
        'turfId'         => $turfStatus['turfId'],
        'expirationTime' => $turfStatus['expirationTime'],
        'status'         => $turfStatus['status'],
      ];
      if ($turfStatus['status'] === self::TURF_CREATED) {
        $updateFields['createTime'] = $turfStatus['createTime'];
      }

      $this->connection->merge(self::TURF_STATUS_TBL)
        ->keys([
          'folderName' => $turfStatus['folderName'],
          'turfName'   => $turfStatus['turfName'],
        ])
        ->insertFields([
          'folderName'     => $turfStatus['folderName'],
          'turfName'       => $turfStatus['turfName'],
          'folderId'       => $turfStatus['folderId'],
          'turfId'         => $turfStatus['turfId'],
          'createTime'     => $turfStatus['createTime'],
          'expirationTime' => $turfStatus['expirationTime'],
          'status'         => $turfStatus['status'],
        ])
        ->updateFields($updateFields)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('createTurfStatus', $e);
    }
  }

  /**
   * turfStatusCheck
   *
   * Returns TRUE when the turf's activist-code state has settled (or the
   * status record no longer requires verification), FALSE when codes are still
   * in flight.  When codes are confirmed, the record is stamped with the
   * appropriate validated status, so future calls return immediately.
   *
   * Decision tree:
   *   No record found → true (nothing to wait for)
   *   status = turfDeleteValidated/
   *              turfCreateValidated → true (already confirmed)
   *   status = turfDeleted, all codes removed → true (stamps turfDeleteValidated)
   *   status = turfCreated, all codes applied → true (stamps turfCreateValidated)
   *   expiration passed, codes are incomplete → true (logs error with failed vanids)
   *   otherwise → false (codes still in flight)
   *
   * @param string $folderName
   * @param string $turfName
   * @return bool
   */
  public function turfStatusCheck(string $folderName, string $turfName): bool
  {
    $record = $this->getTurfStatus($folderName, $turfName);
    if (empty($record)) {
      return TRUE;
    }

    $status = $record['status'];

    if ($status === self::TURF_DELETE_VALIDATED || $status === self::TURF_CREATE_VALIDATED) {
      return TRUE;
    }

    $expirationTime = $record['expirationTime'] ?? '';
    $expired = !empty($expirationTime) && time() >= strtotime($expirationTime);

    $folderId = (int) $record['folderId'];
    $turf = $this->turfsObj->getTurfByTurfName($folderId, $turfName);
    if (empty($turf)) {
      // Turf is gone from the NLP DB (already deleted). For TURF_DELETED,
      // enforce the expiration wait — we cannot verify codes, so we wait
      // until the window expires before declaring it safe.
      return ($status !== self::TURF_DELETED) || $expired;
    }

    $turfIndex = (int) $turf['turfIndex'];
    $vanids = array_keys($this->votersObj->getVotersInTurf($turfIndex));
    if (empty($vanids)) {
      return ($status !== self::TURF_DELETED) || $expired;
    }

    $missingCode = $this->apiActivistCodesObj->hasApiActivistCodeBatch($vanids, 'NLPVoterAC');

    if ($status === self::TURF_DELETED) {
      // All removed = every voter is missing the code.
      if (count($missingCode) === count($vanids)) {
        $this->setTurfStatusValidated($folderName, $turfName, self::TURF_DELETE_VALIDATED);
        return TRUE;
      }
      if ($expired) {
        $stillHaveCode = array_diff($vanids, $missingCode);
        Drupal::logger('nlpservices')->error(
          'Turf @folder/@turf: NLPVoterAC removal incomplete after expiration. @count voter(s) still have the code: @vanids',
          [
            '@folder' => $folderName,
            '@turf'   => $turfName,
            '@count'  => count($stillHaveCode),
            '@vanids' => implode(', ', $stillHaveCode),
          ]
        );
        $this->setTurfStatusValidated($folderName, $turfName, self::TURF_DELETE_VALIDATED);
        return TRUE;
      }
      return FALSE;
    }

    // TURF_CREATED: all applied = no voter is missing the code.
    if (empty($missingCode)) {
      $this->setTurfStatusValidated($folderName, $turfName, self::TURF_CREATE_VALIDATED);
      return TRUE;
    }
    if ($expired) {
      Drupal::logger('nlpservices')->error(
        'Turf @folder/@turf: NLPVoterAC application incomplete after expiration. @count voter(s) still missing the code: @vanids',
        [
          '@folder' => $folderName,
          '@turf'   => $turfName,
          '@count'  => count($missingCode),
          '@vanids' => implode(', ', $missingCode),
        ]
      );
      $this->setTurfStatusValidated($folderName, $turfName, self::TURF_CREATE_VALIDATED);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * setTurfStatusValidated
   *
   * Updates the status field of an existing record to the given validated value.
   *
   * @param string $folderName
   * @param string $turfName
   * @param string $validatedStatus - TURF_DELETE_VALIDATED or TURF_CREATE_VALIDATED
   * @return void
   */
  public function setTurfStatusValidated(string $folderName, string $turfName, string $validatedStatus): void
  {
    try {
      $this->connection->update(self::TURF_STATUS_TBL)
        ->fields(['status' => $validatedStatus])
        ->condition('folderName', $folderName)
        ->condition('turfName', $turfName)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('setTurfStatusValidated', $e);
    }
  }

  /**
   * extendTurfStatusExpiration
   *
   * Pushes the expirationTime forward by $seconds from now.  Called after a
   * retry so the record is not re-queued until VAN has had time to settle.
   *
   * @param string $folderName
   * @param string $turfName
   * @param int    $seconds
   * @return void
   */
  public function extendTurfStatusExpiration(string $folderName, string $turfName, int $seconds): void
  {
    try {
      $this->connection->update(self::TURF_STATUS_TBL)
        ->fields(['expirationTime' => date('Y-m-d H:i:s', time() + $seconds)])
        ->condition('folderName', $folderName)
        ->condition('turfName', $turfName)
        ->execute();
    } catch (Exception $e) {
      nlp_debug_msg('extendTurfStatusExpiration', $e);
    }
  }

  /**
   * getExpiredPendingTurfStatuses
   *
   * Returns all turfCreated and turfDeleted records whose expirationTime has
   * already passed.  Used by cron to populate the turf AC check queue.
   *
   * @return array  Flat list of status records (associative arrays).
   */
  public function getExpiredPendingTurfStatuses(): array
  {
    try {
      $query = $this->connection->select(self::TURF_STATUS_TBL, 's');
      $query->fields('s');
      $orGroup = $query->orConditionGroup()
        ->condition('status', self::TURF_CREATED)
        ->condition('status', self::TURF_DELETED);
      $query->condition($orGroup);
      $result = $query->execute();
    } catch (Exception $e) {
      nlp_debug_msg('getExpiredPendingTurfStatuses', $e);
      return [];
    }
    $now = time();
    $expired = [];
    while ($record = $result->fetchAssoc()) {
      $expirationTime = $record['expirationTime'] ?? '';
      if (!empty($expirationTime) && $now >= strtotime($expirationTime)) {
        $expired[] = $record;
      }
    }
    return $expired;
  }

  /**
   * getTurfStatus
   *
   * Returns the status record for the given folderName/turfName, or an empty
   * array if no record exists.
   *
   * @param string $folderName
   * @param string $turfName
   * @return array
   */
  private function getTurfStatus(string $folderName, string $turfName): array
  {
    try {
      $query = $this->connection->select(self::TURF_STATUS_TBL, 's');
      $query->fields('s');
      $query->condition('folderName', $folderName);
      $query->condition('turfName', $turfName);
      $result = $query->execute();
      $record = $result->fetchAssoc();
    } catch (Exception $e) {
      nlp_debug_msg('getTurfStatus', $e);
      return [];
    }
    return $record ?: [];
  }

}
