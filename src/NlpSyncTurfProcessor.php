<?php

namespace Drupal\nlpservices;

use Drupal;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use RuntimeException;
use Throwable;

/**
 * Syncs a VoteBuilder turf into NLP Services and assigns it to an NL.
 *
 * The VoteBuilder API is slow, so a sync spans many batch requests, and any one of
 * them can fail (a timeout, a dropped connection, an API error).  To keep a failure
 * from leaving part of a turf in the database, the sync runs in three phases:
 *
 *  1. fetch: download the turf's voter list and the voter details from the API, one
 *     chunk per request.  Nothing is written to the database.  A failure here leaves
 *     nothing behind.
 *  2. commit: one request, one database transaction.  Removes the residue of any
 *     earlier failed sync of the same turf, moves voters out of other turfs, and
 *     writes the turf, its voters and the NL's assignment.  Either all of it is
 *     written or, on any failure, none of it.
 *  3. activistCodes: set the NLP voter activist code in VoteBuilder, one chunk per
 *     request.  The turf is already complete in the database, so a failure here only
 *     leaves some activist codes unset.  The nightly turf resync method adds missing codes,
 *     and delete of the turf removes them all.
 */
class NlpSyncTurfProcessor {

  const int MAX_QUEUE_LIMIT = 17;
  const int MAX_MOVED_VOTERS = 6;

  const string PHASE_FETCH = 'fetch';
  const string PHASE_COMMIT = 'commit';
  const string PHASE_ACTIVIST_CODES = 'activistCodes';

  protected ApiExportJobs $exportJobsObj;
  protected ApiSavedLists $savedListsObj;
  protected ApiVoter $apiVoterObj;
  protected NlpNls $nlsObj;
  protected ApiActivistCodes $activistCodesObj;
  protected NlpVoters $votersObj;
  protected NlpTurfs $turfsObj;
  protected NlpConfiguration $configurationObj;
  protected NlpAwards $awardsObj;
  protected NlpReports $reportsObj;
  protected NlpPaths $pathsObj;
  protected NlpTurfAssignments $turfAssignmentsObj;
  protected MessengerInterface $messenger;
  protected NlpTurfStatus $turfStatusObj;
  protected Connection $connection;

  public function __construct(
    $exportJobsObj,
    $savedListsObj,
    $apiVoterObj,
    $nlsObj,
    $activistCodesObj,
    $votersObj,
    $turfsObj,
    $configurationObj,
    $awardsObj,
    $reportsObj,
    $pathsObj,
    $turfAssignmentsObj,
    $messenger,
    $turfStatusObj,
    $connection
  ) {
    $this->exportJobsObj      = $exportJobsObj;
    $this->savedListsObj      = $savedListsObj;
    $this->apiVoterObj        = $apiVoterObj;
    $this->nlsObj             = $nlsObj;
    $this->activistCodesObj   = $activistCodesObj;
    $this->votersObj          = $votersObj;
    $this->turfsObj           = $turfsObj;
    $this->configurationObj   = $configurationObj;
    $this->awardsObj          = $awardsObj;
    $this->reportsObj         = $reportsObj;
    $this->pathsObj           = $pathsObj;
    $this->turfAssignmentsObj = $turfAssignmentsObj;
    $this->messenger          = $messenger;
    $this->turfStatusObj      = $turfStatusObj;
    $this->connection         = $connection;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('nlpservices.export_jobs'),
      $container->get('nlpservices.saved_lists'),
      $container->get('nlpservices.api_voter'),
      $container->get('nlpservices.nls'),
      $container->get('nlpservices.activist_codes'),
      $container->get('nlpservices.voters'),
      $container->get('nlpservices.turfs'),
      $container->get('nlpservices.configuration'),
      $container->get('nlpservices.awards'),
      $container->get('nlpservices.reports'),
      $container->get('nlpservices.paths'),
      $container->get('nlpservices.turf_assignments'),
      $container->get('messenger'),
      $container->get('nlpservices.turf_status'),
      $container->get('database'),
    );
  }

  /**
   * processBatch
   *
   * Runs one step of the turf sync.  See the class comment for the phases.
   *
   * @param array $arg
   *   An associative array containing necessary parameters for the process. Expected keys include:
   *   - 'county': The county identifier.
   *   - 'folderId': The folder ID associated with the turf.
   *   - 'folderName': The name of the folder.
   *   - 'turfId': The turf ID to be processed.
   *   - 'turfName': The name of the turf.
   *   - 'mcid': The unique ID of the neighborhood leader (NL) managing the turf.
   *   - 'voterCount': The total count of voters in the turf.
   * @param array &$context
   *   A reference to the operation's context array, storing ongoing operation state. Keys include:
   *   - 'sandbox': Temporary data used for current batch processing.
   *   - 'finished': A flag or progress ratio indicating completion state.
   *   - 'results': Finalized results or error messages for the operation.
   * @return void
   *   This method does not return a value. Results are stored in the context array.
   */
  public function processBatch(array $arg, array &$context): void {
    // The Batch API sets 'finished' to 1 before every call.  Clear it so only an
    // abort or the last activist code step ends the batch.
    $context['finished'] = 0;
    if (empty($context['sandbox']['phase'])) {
      if (!$this->startSync($arg, $context)) { return; }
    }

    switch ($context['sandbox']['phase']) {
      case self::PHASE_FETCH:
        $this->fetchVoters($arg, $context);
        break;
      case self::PHASE_COMMIT:
        $this->commitTurf($arg, $context);
        break;
      case self::PHASE_ACTIVIST_CODES:
        $this->setActivistCodes($context);
        break;
    }

    if (($context['finished'] ?? 0) >= 1) { return; }
    $sandbox = $context['sandbox'];
    $chunkCount = count($sandbox['turfVoters']);
    // Until the commit, assume one activist code step per fetch step.
    $acCount = ($sandbox['phase'] == self::PHASE_ACTIVIST_CODES) ? count($sandbox['acQueue']) : $chunkCount;
    $done = $sandbox['fetchIndex'] + (($sandbox['phase'] == self::PHASE_ACTIVIST_CODES) ? 1 + $sandbox['acIndex'] : 0);
    $context['finished'] = $done / ($chunkCount + 1 + $acCount);
  }

  /**
   * startSync
   *
   * Downloads the turf's voter list from VoteBuilder and splits it into chunks
   * for the fetch phase.
   *
   * @param array $arg
   * @param array $context
   * @return bool - FALSE if the sync was aborted.
   */
  private function startSync(array $arg, array &$context): bool {
    $county   = $arg['county'];
    $database = 0;
    $exportJob = $this->exportJobsObj->getExportJobs($county, $database, $arg['turfId']);
    if (empty($exportJob['downloadUrl'])) {
      $result = $this->savedListsObj->recoverTurfId($county, $arg['folderId'], $arg['turfName']);
      if ($result['result'] == 'error') {
        $this->abortSync($arg, $context, $result['message']);
        return FALSE;
      }
      $exportJob = $this->exportJobsObj->getExportJobs($county, $database, $result['turfId']);
      if (empty($exportJob['downloadUrl'])) {
        $this->abortSync($arg, $context, t('The turf is not accessible by ID.'));
        return FALSE;
      }
    }
    $fh = @fopen($exportJob['downloadUrl'], 'r');
    if (!$fh) {
      $this->abortSync($arg, $context, t('Unable to download the turf voter list.'));
      return FALSE;
    }
    fgetcsv($fh, escape: '');
    $vanids = [];
    do {
      $listRecord = fgetcsv($fh, escape: '');
      if ($listRecord === FALSE) { break; }
      if (empty($listRecord)) { continue; }
      $vanids[] = $listRecord[1];
    } while (TRUE);
    if (!feof($fh)) {
      fclose($fh);
      $this->abortSync($arg, $context, t('The turf voter download was interrupted. Please try again.'));
      return FALSE;
    }
    fclose($fh);
    if (empty($vanids)) {
      $this->abortSync($arg, $context, t('The turf has no voters.'));
      return FALSE;
    }

    $context['sandbox'] = [
      'phase'                => self::PHASE_FETCH,
      'turfVoters'           => array_chunk($vanids, self::MAX_QUEUE_LIMIT),
      'fetchIndex'           => 0,
      'voters'               => [],
      'legislativeDistricts' => [],
      'acQueue'              => [],
      'acIndex'              => 0,
    ];
    return TRUE;
  }

  /**
   * fetchVoters
   *
   * Gets the details for the next chunk of voters from VoteBuilder.  Only the batch
   * sandbox is changed.
   *
   * @param array $arg
   * @param array $context
   * @return void
   */
  private function fetchVoters(array $arg, array &$context): void {
    $fetchIndex = $context['sandbox']['fetchIndex'];
    $voters = $this->apiVoterObj->getVoters($context['sandbox']['turfVoters'][$fetchIndex]);
    if (empty($voters)) {
      $this->abortSync($arg, $context, t('No voter data was returned for this turf. Please try the sync again.'));
      return;
    }

    $legislativeDistricts = $context['sandbox']['legislativeDistricts'];
    if (!$this->validateTurfPrecinct($legislativeDistricts, $voters)) {
      $this->abortSync($arg, $context, t('The turf must be for one precinct. This error likely means
      the saved list is old and the map region should be refreshed.'));
      return;
    }
    $context['sandbox']['legislativeDistricts'] = $legislativeDistricts;
    $context['sandbox']['voters'] += $voters;

    $fetchIndex++;
    $context['sandbox']['fetchIndex'] = $fetchIndex;
    if ($fetchIndex >= count($context['sandbox']['turfVoters'])) {
      $context['sandbox']['phase'] = self::PHASE_COMMIT;
    }
  }

  /**
   * commitTurf
   *
   * Writes the turf in a single database transaction, then queues the activist
   * code changes for VoteBuilder.
   *
   * @param array $arg
   * @param array $context
   * @return void
   */
  private function commitTurf(array $arg, array &$context): void {
    $sandbox = $context['sandbox'];
    $movedVoters = [];
    $transaction = $this->connection->startTransaction();
    try {
      $turfIndex = $this->writeTurf($arg, $sandbox, $movedVoters);
    }
    catch (NlpSyncTurfException $e) {
      $transaction->rollBack();
      $this->abortSync($arg, $context, $e->getMessage());
      return;
    }
    catch (Throwable $e) {
      $transaction->rollBack();
      Drupal::logger('nlpservices')->error('Sync of turf "@turfName" rolled back: @msg',
        ['@turfName' => $arg['turfName'], '@msg' => $e->getMessage()]);
      $this->abortSync($arg, $context, t('The turf could not be saved and nothing was changed. Please try the sync again.'));
      return;
    }
    $transaction->commitOrRelease();
    unset($transaction);

    $this->storeWalksheet($arg, $turfIndex);

    // Voters taken from another turf get their activist code removed and then
    // applied again for the new turf.
    $acQueue = [];
    foreach (array_chunk(array_keys($movedVoters), self::MAX_QUEUE_LIMIT) as $vanids) {
      $acQueue[] = ['action' => 'Remove', 'vanids' => $vanids];
    }
    foreach (array_chunk(array_keys($sandbox['voters']), self::MAX_QUEUE_LIMIT) as $vanids) {
      $acQueue[] = ['action' => 'Apply', 'vanids' => $vanids];
    }
    $context['sandbox']['acQueue'] = $acQueue;
    $context['sandbox']['acIndex'] = 0;
    $context['sandbox']['phase']   = self::PHASE_ACTIVIST_CODES;

    // The turf is saved.  Record the results now so the finished callback can
    // report the turf even if an activist code step fails.
    $nl = $this->nlsObj->getNlById($arg['mcid']);
    $context['results'] = [
      'committed'   => TRUE,
      'mcid'        => $arg['mcid'],
      'turfName'    => $arg['turfName'],
      'county'      => $arg['county'],
      'voterCount'  => $arg['voterCount'],
      'turfCnt'     => count($sandbox['voters']),
      'nickname'    => $nl['nickname'] ?? '',
      'turfIndex'   => $turfIndex,
      'movedVoters' => $movedVoters,
    ];
  }

  /**
   * writeTurf
   *
   * All the database changes for the sync.  Runs inside the transaction started by
   * commitTurf(), so any exception undoes everything written here.
   *
   * @param array $arg
   * @param array $sandbox
   * @param array $movedVoters - Set to the voters moved from other turfs, keyed by vanid.
   * @return int - The turfIndex of the new turf.
   * @throws NlpSyncTurfException
   */
  private function writeTurf(array $arg, array $sandbox, array &$movedVoters): int {
    $county    = $arg['county'];
    $folderId  = $arg['folderId'];
    $turfId    = $arg['turfId'];
    $turfName  = $arg['turfName'];
    $mcid      = $arg['mcid'];
    $voters    = $sandbox['voters'];
    $vanids    = array_keys($voters);

    // Another coordinator may have synced this turf while the voters were fetched.
    // What is left of an earlier failed sync of this turf is removed.
    $existingTurfs = $this->turfsObj->getTurfsForSync($folderId, $turfId, $turfName);
    foreach ($existingTurfs as $existingIndex => $existingTurf) {
      if (!$this->turfsObj->isIncompleteTurf($existingTurf)) {
        throw new NlpSyncTurfException((string) t('@turfName is already assigned to @nl. Delete it before syncing it again.', [
          '@turfName' => $turfName,
          '@nl'       => trim($existingTurf['nlFirstName'] . ' ' . $existingTurf['nlLastName']),
        ]));
      }
      $this->votersObj->deleteVotersInTurf($existingIndex);
      $this->votersObj->deleteAddressesInTurf($existingIndex);
      if (!$this->turfsObj->removeTurf($existingTurf)) {
        throw new RuntimeException("Unable to remove incomplete turf $existingIndex.");
      }
      Drupal::logger('nlpservices')->notice('Removed incomplete turf @turfIndex left by a failed sync of "@turfName".',
        ['@turfIndex' => $existingIndex, '@turfName' => $turfName]);
    }
    // Voter records pointing at a turf that no longer exists would look like
    // assignments to another turf.
    $this->votersObj->deleteOrphanTurfVoters($vanids);

    $nl        = $this->nlsObj->getNlById($mcid);
    $newNlName = trim(($nl['firstName'] ?? '') . ' ' . ($nl['lastName'] ?? ''));

    // A voter can be in only one turf.  Voters in another turf are moved to this one.
    $duplicates = $this->getDuplicates($voters);
    $firstConflict = [];
    foreach ($duplicates as $vanid => $duplicate) {
      $oldTurf = $this->turfsObj->getTurf($duplicate['turfIndex']);
      if (empty($firstConflict)) {
        $firstConflict = $oldTurf;
      }
      $oldNlName = ($oldTurf['nlFirstName'] ?? '') . ' ' . ($oldTurf['nlLastName'] ?? '');
      $movedVoters[$vanid] = [
        'firstName'   => $duplicate['firstName'],
        'lastName'    => $duplicate['lastName'],
        'oldTurfName' => $oldTurf['turfName'] ?? '',
        'oldMcid'     => $duplicate['mcid'],
        'oldNlName'   => $oldNlName,
      ];
    }
    if (count($movedVoters) > self::MAX_MOVED_VOTERS) {
      throw new NlpSyncTurfException((string) t('Too many voters moved from existing turfs (@count) while syncing turf "@turfName" (Folder: @folderName, Map Region: @mapRegionName, NL: @newNlName). The first conflict was with turf "@firstTurfName" (Folder: @firstFolderName, Map Region: @firstMapRegionName). Sync aborted.', [
        '@count'               => count($movedVoters),
        '@turfName'            => $turfName,
        '@folderName'          => $arg['folderName'],
        '@mapRegionName'       => $arg['mapRegionName'],
        '@newNlName'           => $newNlName,
        '@firstTurfName'       => $firstConflict['turfName'] ?? '',
        '@firstFolderName'     => $firstConflict['folderName'] ?? '',
        '@firstMapRegionName'  => $firstConflict['mapRegionName'] ?? '',
      ]));
    }
    foreach ($movedVoters as $vanid => $movedVoter) {
      $this->votersObj->deleteVoterInTurf($vanid);
      $this->votersObj->deleteAddressInTurf($vanid);
      Drupal::logger('nlpservices')->notice(
        'Moved voter @firstName @lastName (VANID: @vanid) from turf "@oldTurfName" (NL: @oldNlName) to turf "@newTurfName" (NL: @newNlName)',
        [
          '@firstName'    => $movedVoter['firstName'],
          '@lastName'     => $movedVoter['lastName'],
          '@vanid'        => $vanid,
          '@oldTurfName'  => $movedVoter['oldTurfName'],
          '@oldNlName'    => $movedVoter['oldNlName'],
          '@newTurfName'  => $turfName,
          '@newNlName'    => $newNlName,
        ]
      );
    }

    $turfIndex = $this->turfsObj->createTurf($this->buildTurfRecord($arg, $sandbox, $nl));
    if (empty($turfIndex)) {
      throw new RuntimeException('createTurf failed.');
    }

    $cycle = $this->configurationObj->getCycle();
    foreach ($voters as $vanid => $voter) {
      $voter['address']['turfIndex'] = $turfIndex;
      $voter['address']['moved']     = NULL;
      if (!$this->votersObj->createVoter($voter)) {
        throw new RuntimeException("createVoter failed for $vanid.");
      }

      $voterStatus            = $this->votersObj->getVoterStatus($vanid);
      $voterStatus['vanid']   = $vanid;
      $voterStatus['cycle']   = $cycle;
      $voterStatus['hostile'] = (!empty($voter['activistCodes']['hostile'])) ? 1 : 0;
      $this->votersObj->setVoterStatus($vanid, $voterStatus);

      $turfVoter = [
        'mcid'      => $mcid,
        'county'    => $county,
        'turfIndex' => $turfIndex,
        'vanid'     => $vanid,
      ];
      if (!$this->votersObj->createTurfVoter($turfVoter)) {
        throw new RuntimeException("createTurfVoter failed for $vanid.");
      }
      $this->reloadVoterStatus($voter, $turfIndex, $mcid);
    }

    $this->awardsObj->nlParticipation($mcid, 'Turf synced');
    $this->finalizeTurf($arg, $nl);
    return $turfIndex;
  }

  /**
   * setActivistCodes
   *
   * Sends the next queued activist code change to VoteBuilder.
   *
   * @param array $context
   * @return void
   */
  private function setActivistCodes(array &$context): void {
    $acIndex = $context['sandbox']['acIndex'];
    $acQueue = $context['sandbox']['acQueue'];
    if (!empty($acQueue[$acIndex])) {
      $this->activistCodesObj->setApiActivistCodeBatch($acQueue[$acIndex]['vanids'], 'NLPVoterAC', $acQueue[$acIndex]['action']);
    }
    $acIndex++;
    $context['sandbox']['acIndex'] = $acIndex;
    if ($acIndex >= count($acQueue)) {
      $context['results']['result'] = 'success';
      $context['finished'] = 1;
    }
  }

  /**
   * abortSync
   *
   * Ends the batch with an error.  Nothing from this sync is in the database when
   * this is called.
   *
   * @param array $arg
   * @param array $context
   * @param mixed $message
   * @return void
   */
  private function abortSync(array $arg, array &$context, mixed $message): void {
    $tempName = $arg['walksheetInfo']['tempName'] ?? '';
    if (!empty($tempName) && file_exists($tempName)) {
      unlink($tempName);
    }
    $context['finished'] = 1;
    $context['results']  = [
      'result'  => 'error',
      'message' => [0 => $message],
    ];
  }

  /**
   * validateTurfPrecinct
   *
   * Validates the consistency of voters' precinct and legislative district data
   * and updates the legislative district information.
   *
   * @param array $legislativeDistricts An associative array containing the current legislative
   *                                     district information, with keys such as 'precinct', 'hd', 'cd', and 'count'.
   *                                     This parameter is passed by reference and will be updated if validation succeeds.
   * @param array $voters An array of voters, where each voter is represented by an array that includes
   *                                     'address' containing 'precinct', 'hd', and 'cd' keys.
   * @return bool Returns TRUE if all voters belong to the same precinct and legislative districts as those specified
   *              in the current `$legislativeDistricts`, or if `$legislativeDistricts` is empty and set successfully.
   *              Returns FALSE if a mismatch is detected.
   */
  private function validateTurfPrecinct(array &$legislativeDistricts, array $voters): bool {
    $vanids = array_keys($voters);
    if (empty($legislativeDistricts)) {
      $firstVanid = reset($vanids);
      $precinct   = $voters[$firstVanid]['address']['precinct'];
      $hd         = $voters[$firstVanid]['address']['hd'];
      $cd         = $voters[$firstVanid]['address']['cd'];
      $count      = 0;
    } else {
      $precinct = $legislativeDistricts['precinct'];
      $hd       = $legislativeDistricts['hd'];
      $cd       = $legislativeDistricts['cd'];
      $count    = $legislativeDistricts['count'];
    }

    $count = $count + count($voters);
    foreach ($voters as $voter) {
      if (($precinct != $voter['address']['precinct']) || ($hd != $voter['address']['hd'])) {
        return FALSE;
      }
    }
    $legislativeDistricts = [
      'precinct' => $precinct,
      'hd'       => $hd,
      'cd'       => $cd,
      'count'    => $count,
    ];
    return TRUE;
  }

  /**
   * getDuplicates
   *
   * Identifies and retrieves duplicate voters based on the provided voter data.
   *
   * @param array $voters An associative array of voters where the keys are voter IDs.
   * @return array An array of duplicate voters with additional metadata such as 'mcid', 'turfIndex', and 'county'.
   */
  private function getDuplicates(array $voters): array {
    $vanids        = array_keys($voters);
    $dupVoterTurfs = $this->votersObj->duplicateVoters($vanids);
    if (empty($dupVoterTurfs)) { return []; }
    $dupVanids = array_keys($dupVoterTurfs);
    $dupVoters = $this->votersObj->getVotersInList($dupVanids);
    foreach ($dupVanids as $vanid) {
      $dupVoters[$vanid]['mcid']      = $dupVoterTurfs[$vanid]['mcid'];
      $dupVoters[$vanid]['turfIndex'] = $dupVoterTurfs[$vanid]['turfIndex'];
      $dupVoters[$vanid]['county']    = $dupVoterTurfs[$vanid]['county'];
    }
    return $dupVoters;
  }

  /**
   * reloadVoterStatus
   *
   * Updates the voter status, including contact attempts, pledge to vote status, and movement status,
   * and processes additional voter-related information based on the given parameters.
   *
   * @param array $voter An associative array containing voter data, including the 'vanid' and 'address' fields.
   * @param int $turfIndex The index of the turf to which the voter belongs.
   * @param int $mcid The MCID (Master Campaign ID) related to the voter for tracking purposes.
   * @return void
   */
  private function reloadVoterStatus(array $voter, int $turfIndex, int $mcid): void {
    $vanid = $voter['vanid'];

    $surveyReports = $this->reportsObj->getNlpTypeReports($vanid, 'Survey');
    $pledgedToVote = 0;
    foreach ($surveyReports as $surveyReport) {
      if ($surveyReport['reportIndex'] > $pledgedToVote) {
        $pledgedToVote = $surveyReport['reportIndex'];
      }
    }

    $surveyReports    = $this->reportsObj->getNlpTypeReports($vanid, 'contact');
    $attemptedContact = 0;
    foreach ($surveyReports as $surveyReport) {
      if ($surveyReport['reportIndex'] > $attemptedContact) {
        $attemptedContact = $surveyReport['reportIndex'];
      }
    }

    $turfVoter                     = [];
    $turfVoter['vanid']            = $vanid;
    $turfVoter['attemptedContact'] = $attemptedContact;
    $turfVoter['pledgedToVote']    = $pledgedToVote;
    $turfVoter['turfIndex']        = $turfIndex;
    $this->votersObj->updateTurfVoter($turfVoter);

    $request          = [];
    $request['vanid'] = $vanid;
    $request['type']  = 'contact';
    $request['value'] = 'moved';
    $request['cycle'] = NULL;
    $movedReport = $this->reportsObj->getReport($request);
    if (!empty($movedReport['text'])) {
      $existingAddress = (array) json_decode($movedReport['text']);
      $address         = $voter['address'];
      if ($this->votersObj->addressCompare($address, $existingAddress)) {
        $this->votersObj->setMovedStatus($turfIndex, $vanid, 1);
      }
    }

    $req          = [];
    $req['vanid'] = $vanid;
    $req['mcid']  = $mcid;
    $comment      = $this->reportsObj->getComment($req);
    if (empty($comment['reportIndex'])) { return; }
    $this->votersObj->updateTurfNote($turfIndex, $vanid, $comment['text'], $comment['reportIndex'], 0);
  }

  /**
   * buildTurfRecord
   *
   * The complete turf record.  It is written in one insert, so a turf record never
   * exists without its NL and district fields.
   *
   * @param array $arg
   * @param array $sandbox
   * @param array $nl - The NL record.
   * @return array
   */
  private function buildTurfRecord(array $arg, array $sandbox, array $nl): array {
    $legislativeDistricts = $sandbox['legislativeDistricts'];
    $turf = [
      'county'        => $arg['county'],
      'mcid'          => $arg['mcid'],
      'turfName'      => $arg['turfName'],
      'turfPDF'       => '',
      'folderId'      => $arg['folderId'],
      'folderName'    => $arg['folderName'],
      'listId'        => $arg['turfId'],
      'nlFirstName'   => $nl['firstName'] ?? '',
      'nlLastName'    => $nl['lastName'] ?? '',
      'mapRegionName' => $arg['mapRegionName'],
      'mapRegionId'   => $arg['mapRegionId'],
      'turfHd'        => $legislativeDistricts['hd'],
      'turfPrecinct'  => $legislativeDistricts['precinct'],
      'turfCd'        => $legislativeDistricts['cd'],
      'voterCount'    => $arg['voterCount'],
      'doorCount'     => $arg['doorCount'],
    ];
    if (!empty($arg['minivan'])) {
      $turf['minivanNumber']     = $arg['minivanNumber'];
      $turf['minivanCreateDate'] = $arg['minivanCreateDate'];
    }
    return $turf;
  }

  /**
   * finalizeTurf
   *
   * Records the NL's status, the turf assignment, and the turf status.  Runs inside
   * the sync transaction.
   *
   * @param array $arg An associative array containing turf-related details, including:
   *                   - 'county': The county associated with the turf.
   *                   - 'folderId': The ID of the folder to which the turf belongs.
   *                   - 'folderName': The name of the folder.
   *                   - 'showLists': Indicates whether to show the list of voters.
   *                   - 'mapRegionId': The ID of the map region.
   *                   - 'mapRegionName': The name of the map region.
   *                   - 'turfId': The unique identifier of the turf.
   *                   - 'turfName': The name of the turf.
   *                   - 'mcid': The ID of the managing coordinator.
   *                   - 'voterCount': The number of voters in the turf.
   * @param array $nl - The NL record.
   *
   * @return void
   */
  private function finalizeTurf(array $arg, array $nl): void {
    $county        = $arg['county'];
    $folderId      = $arg['folderId'];
    $folderName    = $arg['folderName'];
    $turfName      = $arg['turfName'];
    $mcid          = $arg['mcid'];

    $nlsStatus             = $this->nlsObj->getNlsStatus($mcid, $county);
    $nlsStatus['mcid']     = $mcid;
    $nlsStatus['signedUp'] = 'Y';
    $nlsStatus['turfCut']  = 'Y';
    $nlsStatus['asked']    = 'Yes';
    $this->nlsObj->setNlsStatus($nlsStatus);

    $isoDate = explode('T', date('c'));

    $assignment = [
      'turfId'        => $arg['turfId'],
      'mcid'          => $mcid,
      'vfid'          => $nl['vfid'],
      'turfName'      => $turfName,
      'savedList'     => $arg['showLists'],
      'mapRegionId'   => $arg['mapRegionId'],
      'mapRegionName' => $arg['mapRegionName'],
      'folderId'      => $folderId,
      'folderName'    => $folderName,
      'firstName'     => $nl['firstName'],
      'lastName'      => $nl['lastName'],
      'county'        => $county,
      'dateAssigned'  => $isoDate[0],
    ];

    do {
      $priorAssignment = $this->turfAssignmentsObj->getTurfAssignmentByTurfName($folderId, $turfName);
      if (empty($priorAssignment)) { break; }
      $this->turfAssignmentsObj->deleteTurfAssignment($priorAssignment['turfId']);
    } while (TRUE);

    $this->turfAssignmentsObj->setTurfAssignment($assignment);

    $voterCount = $arg['voterCount'];
    $expirySecs = ($voterCount < 60)
      ? NlpTurfStatus::CREATED_EXPIRY_SMALL_SECS
      : NlpTurfStatus::CREATED_EXPIRY_LARGE_SECS;
    $now = time();
    $this->turfStatusObj->createTurfStatus([
      'folderName'     => $folderName,
      'folderId'       => $folderId,
      'turfName'       => $turfName,
      'turfId'         => $arg['turfId'],
      'createTime'     => date('c', $now),
      'expirationTime' => date('c', $now + $expirySecs),
      'status'         => NlpTurfStatus::TURF_CREATED,
    ]);
  }

  /**
   * storeWalksheet
   *
   * Moves the uploaded walksheet, if any, to the county's PDF folder.  Done after the
   * commit since file operations cannot be rolled back.
   *
   * @param array $arg
   * @param int $turfIndex
   * @return void
   */
  private function storeWalksheet(array $arg, int $turfIndex): void {
    $walksheetInfo = $arg['walksheetInfo'] ?? [];
    if (empty($walksheetInfo['tempName'])) { return; }
    $uniqueWalksheetName = 'MCID' . $arg['mcid'] . '_' . $walksheetInfo['fileNameBase']
      . '_TI' . $turfIndex . '.' . $walksheetInfo['fileType'];
    $uri = $this->pathsObj->getPath('PDF', $arg['county']) . $uniqueWalksheetName;
    copy($walksheetInfo['tempName'], $uri);
    unlink($walksheetInfo['tempName']);
    $this->turfsObj->updateTurf($turfIndex, 'turfPDF', $uniqueWalksheetName);
  }

}
